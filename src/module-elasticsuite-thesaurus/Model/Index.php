<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile ElasticSuite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteThesaurus
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2020 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */

namespace Smile\ElasticsuiteThesaurus\Model;

use Smile\ElasticsuiteCore\Helper\IndexSettings as IndexSettingsHelper;
use Smile\ElasticsuiteCore\Api\Client\ClientInterface;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteThesaurus\Config\ThesaurusConfigFactory;
use Smile\ElasticsuiteThesaurus\Config\ThesaurusConfig;
use Smile\ElasticsuiteThesaurus\Api\Data\ThesaurusInterface;
use Smile\ElasticsuiteCore\Helper\Cache as CacheHelper;
use Smile\ElasticsuiteThesaurus\Helper\Combinatorics;

/**
 * Thesaurus index.
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteThesaurus
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class Index
{
    /**
     * @var string
     */
    const INDEX_IDENTIER = 'thesaurus';

    /**
     * @var string
     */
    const WORD_DELIMITER = '_';

    /**
     * @var integer
     */
    const MAX_SIZE = 10;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var IndexSettingsHelper
     */
    private $indexSettingsHelper;

    /**
     * @var ThesaurusConfigFactory
     */
    private $thesaurusConfigFactory;

    /**
     * @var CacheHelper
     */
    private $cacheHelper;

    /**
     * Constructor.
     *
     * @param ClientInterface        $client                 ES client.
     * @param IndexSettingsHelper    $indexSettingsHelper    Index Settings Helper.
     * @param CacheHelper            $cacheHelper            ES caching helper.
     * @param ThesaurusConfigFactory $thesaurusConfigFactory Thesaurus configuration factory.
     * @param Combinatorics          $combinatorics          Helper to generate query combinations.
     */
    public function __construct(
        ClientInterface $client,
        IndexSettingsHelper $indexSettingsHelper,
        CacheHelper $cacheHelper,
        ThesaurusConfigFactory $thesaurusConfigFactory,
        Combinatorics $combinatorics = null
    ) {
        $this->client                 = $client;
        $this->indexSettingsHelper    = $indexSettingsHelper;
        $this->thesaurusConfigFactory = $thesaurusConfigFactory;
        $this->cacheHelper            = $cacheHelper;
        $this->combinatorics          = $combinatorics ?? \Magento\Framework\App\ObjectManager::getInstance()->get(Combinatorics::class);
    }

    /**
     * Provides weigthed rewrites for the query.
     *
     * @param ContainerConfigurationInterface $containerConfig Search request container config.
     * @param string                          $queryText       Fulltext query.
     * @param float                           $originalBoost   Original boost of the query
     *
     * @return array
     */
    public function getQueryRewrites(ContainerConfigurationInterface $containerConfig, $queryText, $originalBoost = 1)
    {
        $cacheKey  = $this->getCacheKey($containerConfig, $queryText);
        $cacheTags = $this->getCacheTags($containerConfig);

        $queryRewrites = $this->cacheHelper->loadCache($cacheKey);

        if ($queryRewrites === false) {
            $queryRewrites = $this->computeQueryRewrites($containerConfig, $queryText, $originalBoost);
            $this->cacheHelper->saveCache($cacheKey, $queryRewrites, $cacheTags);
        }

        return $queryRewrites;
    }

    /**
     * Compute weigthed rewrites for the query.
     *
     * @param ContainerConfigurationInterface $containerConfig Search request container config.
     * @param string                          $queryText       Fulltext query.
     * @param float                           $originalBoost   Original boost of the query
     *
     * @return array
     */
    private function computeQueryRewrites(ContainerConfigurationInterface $containerConfig, $queryText, $originalBoost)
    {
        $config   = $this->getConfig($containerConfig);
        $storeId  = $containerConfig->getStoreId();
        $rewrites = [];
        $maxRewrites = $config->getMaxRewrites();

        if ($config->isSynonymSearchEnabled()) {
            $thesaurusType   = ThesaurusInterface::TYPE_SYNONYM;
            $synonymRewrites = $this->getSynonymRewrites($storeId, $queryText, $thesaurusType, $maxRewrites);
            $rewrites        = $this->getWeightedRewrites($synonymRewrites, $config->getSynonymWeightDivider(), $originalBoost);
        }

        if ($config->isExpansionSearchEnabled()) {
            $synonymRewrites = array_merge([$queryText => $originalBoost], $rewrites);

            foreach ($synonymRewrites as $currentQueryText => $currentWeight) {
                $thesaurusType     = ThesaurusInterface::TYPE_EXPANSION;
                $expansions        = $this->getSynonymRewrites($storeId, $currentQueryText, $thesaurusType, $maxRewrites);
                $expansionRewrites = $this->getWeightedRewrites($expansions, $config->getExpansionWeightDivider(), $currentWeight);
                $rewrites          = array_merge($rewrites, $expansionRewrites);
            }
        }

        return $rewrites;
    }

    /**
     * Returns the cache key of the query.
     *
     * @param ContainerConfigurationInterface $containerConfig Search container configuration.
     * @param string                          $queryText       Fulltext query.
     *
     * @return string
     */
    private function getCacheKey(ContainerConfigurationInterface $containerConfig, $queryText)
    {
        $tags = $this->getCacheTags($containerConfig);

        return implode('|', array_merge($tags, [$queryText]));
    }

    /**
     * Returns cache tags associated to the request.
     *
     * @param ContainerConfigurationInterface $containerConfig Search container configuration.
     *
     * @return string[]
     */
    private function getCacheTags(ContainerConfigurationInterface $containerConfig)
    {
        $storeId = $containerConfig->getStoreId();

        return [$this->getIndexAlias($storeId)];
    }

    /**
     * Returns the index alias used by store id.
     *
     * @param integer $storeId Store id.
     *
     * @return string
     */
    private function getIndexAlias($storeId)
    {
        return $this->indexSettingsHelper->getIndexAliasFromIdentifier(self::INDEX_IDENTIER, $storeId);
    }

    /**
     * Load the thesaurus config for the current container.
     *
     * @param ContainerConfigurationInterface $containerConfig Search request container config.
     *
     * @return ThesaurusConfig
     */
    private function getConfig(ContainerConfigurationInterface $containerConfig)
    {
        $storeId       = $containerConfig->getStoreId();
        $containerName = $containerConfig->getName();

        return $this->thesaurusConfigFactory->create($storeId, $containerName);
    }

    /**
     * Generates all possible synonym rewrites for a store and text query.
     *
     * @param integer $storeId     Store id.
     * @param string  $queryText   Text query.
     * @param string  $type        Substitution type (synonym or expansion).
     * @param integer $maxRewrites Max number of allowed rewrites.
     *
     * @return array
     */
    private function getSynonymRewrites($storeId, $queryText, $type, $maxRewrites)
    {
        $indexName          = $this->getIndexAlias($storeId);
        $analyzedQueries    = $this->getQueryCombinations($queryText);
        $synonymByPositions = [];
        $synonyms           = [];

        foreach ($analyzedQueries as $query) {
            try {
                $analysis = $this->client->analyze(
                    ['index' => $indexName, 'body' => ['text' => $query, 'analyzer' => $type]]
                );
            } catch (\Exception $e) {
                $analysis = ['tokens' => []];
            }

            foreach ($analysis['tokens'] ?? [] as $token) {
                if ($token['type'] == 'SYNONYM') {
                    $positionKey                        = sprintf('%s_%s', $token['start_offset'], $token['end_offset']);
                    $token['token']                     = str_replace('_', ' ', $token['token']);
                    $synonymByPositions[$positionKey][] = $token;
                }
            }

            $synonyms = array_merge(
                $synonyms,
                $this->combineSynonyms(str_replace('_', ' ', $query), $synonymByPositions, $maxRewrites)
            );
        }

        return $synonyms;
    }

    /**
     * Explode the query text and fetch combination of words inside it.
     * Eg : "long sleeve dress" => "long_sleeve dress", "long sleeve_dress", "long_sleeve_dress".
     * This allow to find synonyms for couple of words that are "inside" the complete query.
     * Multi-words synonyms are indexed with "_" as separator.
     *
     * @param string $queryText The base query text
     *
     * @return array
     */
    private function getQueryCombinations($queryText)
    {
        // Get all the space character positions in the queryText.
        $spacesPosition = [];
        $offset         = 0;
        while (($pos = mb_strpos($queryText, ' ', $offset)) !== false) {
            $offset           = $pos + 1;
            $spacesPosition[] = $pos;
        }

        // Get all possible combinations of spaces to be replaced.
        $spacesCombinations = [];
        $spacesCount        = count($spacesPosition);
        for ($cpt = 1; $cpt <= $spacesCount; $cpt++) {
            foreach ($this->combinatorics->combinations($spacesPosition, $cpt) as $combination) {
                $spacesCombinations[] = $combination;
            }
        }

        // Create all variations of the query by replacing spaces by '_'.
        $queries[] = $queryText;
        foreach ($spacesCombinations as $combination) {
            $query = $queryText;
            foreach ($combination as $spaceOffset) {
                $query[$spaceOffset] = '_';
            }
            $queries[] = $query;
        }

        $queries = array_unique($queries);

        return $queries;
    }

    /**
     * Combine analysis result to provides all possible synonyms substitution comination.
     *
     * @param string  $queryText          Original query text
     * @param array   $synonymByPositions Synonyms array by positions.
     * @param integer $maxRewrites        Max number of allowed rewrites.
     * @param int     $substitutions      Number of substitutions in the current query.
     * @param int     $offset             Offset of previous substitutions.
     *
     * @return array
     */
    private function combineSynonyms($queryText, $synonymByPositions, $maxRewrites, $substitutions = 0, $offset = 0)
    {
        $combinations = [];

        if (!empty($synonymByPositions) && $substitutions < $maxRewrites) {
            $currentPositionSynonyms = current($synonymByPositions);
            $remainingSynonyms = array_slice($synonymByPositions, 1);

            foreach ($currentPositionSynonyms as $synonym) {
                $startOffset = $synonym['start_offset'] + $offset;
                $length      = $synonym['end_offset'] - $synonym['start_offset'];
                $rewrittenQueryText = $this->mbSubstrReplace($queryText, $synonym['token'], $startOffset, $length);
                $newOffset = mb_strlen($rewrittenQueryText) - mb_strlen($queryText) + $offset;
                $combinations[$rewrittenQueryText] = $substitutions + 1;

                if (!empty($remainingSynonyms)) {
                    $combinations = array_merge(
                        $combinations,
                        $this->combineSynonyms($rewrittenQueryText, $remainingSynonyms, $maxRewrites, $substitutions + 1, $newOffset)
                    );
                }
            }

            if (!empty($remainingSynonyms)) {
                $combinations = array_merge(
                    $combinations,
                    $this->combineSynonyms($queryText, $remainingSynonyms, $maxRewrites, $substitutions, $offset)
                );
            }
        }

        return $combinations;
    }

    /**
     * Convert number of substitution into search queries boost.
     *
     * @param array $queryRewrites Array of query rewrites.
     * @param int   $divider       Score divider.
     * @param int   $baseWeight    Original score.
     *
     * @return array
     */
    private function getWeightedRewrites($queryRewrites, $divider, $baseWeight = 1)
    {
        $mapper = function ($substitutions) use ($baseWeight, $divider) {
            return $baseWeight / ($substitutions * $divider);
        };

        return array_map($mapper, $queryRewrites);
    }

    /**
     * Partial implementation of a multi-byte aware version of substr_replace.
     * Required because the tokens offsets used as for parameters start and length
     * are expressed as a number of (UTF-8) characters, independently of the number of bytes.
     * Does not accept arrays as first and second parameters.
     * Source: https://github.com/fluxbb/utf8/blob/master/functions/substr_replace.php
     * Alternative: https://gist.github.com/bantya/563d7d070c286ba1b5a83b9036f0561a
     *
     * @param string $string      Input string
     * @param string $replacement Replacement string
     * @param mixed  $start       Start offset
     * @param mixed  $length      Length of replacement
     *
     * @return mixed
     */
    private function mbSubstrReplace($string, $replacement, $start, $length = null)
    {
        preg_match_all('/./us', $string, $stringChars);
        preg_match_all('/./us', $replacement, $replacementChars);
        $length = is_int($length) ? $length : mb_strlen($string);
        array_splice($stringChars[0], $start, $length, $replacementChars[0]);

        return implode($stringChars[0]);
    }
}

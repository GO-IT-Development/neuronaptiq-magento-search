<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model;

use NeuronaptiQ\Search\Api\RecommendationApiClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Topsellers of the search service for the topseller widget, Magento's bestsellers if the API fails or returns none
 *
 * @phpstan-type Topsellers array{ids: int[], fallback: bool}
 */
class TopsellerProvider
{
    /**
     * Maximum number of products of the API
     */
    private const MAX_LIMIT = 20;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var RecommendationApiClientInterface
     */
    private RecommendationApiClientInterface $apiClient;

    /**
     * @var Bestsellers
     */
    private Bestsellers $bestsellers;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Config $config
     * @param RecommendationApiClientInterface $apiClient
     * @param Bestsellers $bestsellers
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        RecommendationApiClientInterface $apiClient,
        Bestsellers $bestsellers,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->bestsellers = $bestsellers;
        $this->logger = $logger;
    }

    /**
     * Product ids of the topsellers in their order, parents instead of variants
     *
     * The ids of the fallback (Magento's bestsellers of the store) are not filtered by the category, the caller
     * filters them.
     *
     * @param int|null $categoryId the topsellers of this category, null: of the shop
     * @param int $limit
     * @param int $storeId
     * @return array|null null if the topseller widget is switched off
     * @phpstan-return Topsellers|null
     */
    public function getTopsellers(?int $categoryId, int $limit, int $storeId): ?array
    {
        if (!$this->config->isTopsellerEnabled($storeId)) {
            return null;
        }

        try {
            $hits = $this->apiClient->getTopsellers(
                $categoryId !== null ? (string)$categoryId : null,
                max(1, min($limit, self::MAX_LIMIT)),
                $storeId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('[NeuronaptiQ_Search] Topsellers failed, Magento bestsellers are shown: %s', $e->getMessage()),
                ['exception' => $e]
            );
            $hits = [];
        }

        $ids = [];
        foreach ($hits as $hit) {
            $id = !empty($hit['parentId']) ? (int)$hit['parentId'] : (int)$hit['id'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids !== []) {
            return ['ids' => array_values($ids), 'fallback' => false];
        }

        // without topsellers (no sales in the days of the integration) or if the API fails
        return ['ids' => $this->bestsellers->getProductIds($storeId), 'fallback' => true];
    }
}

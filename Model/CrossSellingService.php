<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use NeuronaptiQ\Search\Api\RecommendationApiClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides the NeuronaptiQ cross-sellings as product collections for Magento's blocks: "Related Products" and
 * "Upsell" of the product page, "Cross-Sell" of the cart page, each with the kind of its setting
 *
 * Variant hits are shown as their parent product, the variant is preselected via URL hash on the product page.
 *
 * @phpstan-import-type SearchHit from \NeuronaptiQ\Search\Api\SearchApiClientInterface
 */
class CrossSellingService
{
    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var RecommendationApiClientInterface
     */
    private RecommendationApiClientInterface $apiClient;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var CatalogConfig
     */
    private CatalogConfig $catalogConfig;

    /**
     * @var Visibility
     */
    private Visibility $visibility;

    /**
     * @var StockHelper
     */
    private StockHelper $stockHelper;

    /**
     * @var VariantUrlHash
     */
    private VariantUrlHash $variantUrlHash;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Loaded collections per products and kind, null if there are no cross-sellings
     *
     * @var array<string, Collection|null>
     */
    private array $collections = [];

    /**
     * URL hashes for variant preselection per displayed product id
     *
     * @var array<int, string>
     */
    private array $variantHashes = [];

    /**
     * @param Config $config
     * @param RecommendationApiClientInterface $apiClient
     * @param CollectionFactory $collectionFactory
     * @param CatalogConfig $catalogConfig
     * @param Visibility $visibility
     * @param StockHelper $stockHelper
     * @param VariantUrlHash $variantUrlHash
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        RecommendationApiClientInterface $apiClient,
        CollectionFactory $collectionFactory,
        CatalogConfig $catalogConfig,
        Visibility $visibility,
        StockHelper $stockHelper,
        VariantUrlHash $variantUrlHash,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->collectionFactory = $collectionFactory;
        $this->catalogConfig = $catalogConfig;
        $this->visibility = $visibility;
        $this->stockHelper = $stockHelper;
        $this->variantUrlHash = $variantUrlHash;
        $this->logger = $logger;
    }

    /**
     * Returns the cross-selling products of the "Related Products" block, sorted by score
     *
     * Returns null if cross-sellings are disabled, the API fails or there are no results; the caller should then
     * show the maintained related products.
     *
     * @param ProductInterface $product
     * @return Collection|null
     */
    public function getProducts(ProductInterface $product): ?Collection
    {
        $storeId = $this->getStoreId($product);
        if (!$this->config->isCrosssellingsEnabled($storeId)) {
            return null;
        }

        return $this->getProductsOfKind($product, $this->config->getCrosssellingsKind($storeId));
    }

    /**
     * Returns the cross-selling products of the "Upsell" block, sorted by score
     *
     * Returns null if the upsell block is switched off, the API fails or there are no results; the caller should
     * then show the maintained upsell products.
     *
     * @param ProductInterface $product
     * @return Collection|null
     */
    public function getUpsellProducts(ProductInterface $product): ?Collection
    {
        $kind = $this->config->getUpsellKind($this->getStoreId($product));

        return $kind !== null ? $this->getProductsOfKind($product, $kind) : null;
    }

    /**
     * Returns the cross-selling products of the cart for the "Cross-Sell" block of the cart page
     *
     * Returns null if the cross-sell block is switched off, the cart is empty, the API fails or there are no
     * results; the caller should then show the maintained cross-sell products.
     *
     * @param int[] $productIds products of the cart, the first 20 are sent
     * @param int|null $storeId
     * @return Collection|null
     */
    public function getCartProducts(array $productIds, ?int $storeId): ?Collection
    {
        $kind = $this->config->getCartCrosssellKind($storeId);
        $productIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $productIds)))), 0, 20);
        if ($kind === null || $productIds === []) {
            return null;
        }

        $key = implode(',', $productIds) . '/' . $kind;
        if (!array_key_exists($key, $this->collections)) {
            $this->collections[$key] = $this->load(
                fn (int $limit): array => $this->apiClient->getCrossSellingsOfProducts(
                    array_map('strval', $productIds),
                    $kind,
                    $limit,
                    $storeId
                ),
                $productIds,
                $storeId,
                sprintf('products %s', implode(',', $productIds))
            );
        }

        return $this->collections[$key];
    }

    /**
     * Returns the URL hash that preselects the cross-selling variant of a displayed product, or ''
     *
     * @param int $productId
     * @return string
     */
    public function getVariantHash(int $productId): string
    {
        return $this->variantHashes[$productId] ?? '';
    }

    /**
     * Cross-selling products of a kind for a product, kept for the request
     *
     * @param ProductInterface $product
     * @param string $kind
     * @return Collection|null
     */
    private function getProductsOfKind(ProductInterface $product, string $kind): ?Collection
    {
        $productId = (int)$product->getId();
        if ($productId === 0) {
            return null;
        }

        $storeId = $this->getStoreId($product);
        $key = $productId . '/' . $kind;
        if (!array_key_exists($key, $this->collections)) {
            $this->collections[$key] = $this->load(
                fn (int $limit): array => $this->apiClient->getCrossSellingsOfKind(
                    (string)$productId,
                    $kind,
                    $limit,
                    $storeId
                ),
                [$productId],
                $storeId,
                sprintf('product %d', $productId)
            );
        }

        return $this->collections[$key];
    }

    /**
     * Queries the API and loads the cross-selling products
     *
     * @param callable $request the API request with the limit, returns the hits
     * @param int[] $excludedIds the products the cross-sellings are for, they are not shown
     * @param int|null $storeId
     * @param string $subject the products in log entries
     * @return Collection|null
     * @phpstan-param callable(int): list<SearchHit> $request
     */
    private function load(callable $request, array $excludedIds, ?int $storeId, string $subject): ?Collection
    {
        try {
            $hits = $request($this->config->getCrosssellingsLimit($storeId));
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('[NeuronaptiQ_Search] Cross-sellings of %s failed: %s', $subject, $e->getMessage()),
                ['exception' => $e]
            );
            return null;
        }

        $displayIds = $this->resolveDisplayIds($hits, $excludedIds);
        if (empty($displayIds)) {
            return null;
        }

        $collection = $this->createCollection($displayIds, $storeId);

        return $collection->count() > 0 ? $collection : null;
    }

    /**
     * Store of the product
     *
     * @param ProductInterface $product
     * @return int|null
     */
    private function getStoreId(ProductInterface $product): ?int
    {
        return $product instanceof Product ? (int)$product->getStoreId() : null;
    }

    /**
     * Maps hits to the displayed product ids (parent of variants), removes duplicates and the excluded products
     *
     * Remembers the variant hash of the best scored variant per parent.
     *
     * @param array $hits
     * @param int[] $excludedIds
     * @return int[]
     * @phpstan-param list<SearchHit> $hits
     */
    private function resolveDisplayIds(array $hits, array $excludedIds): array
    {
        $displayIds = [];
        foreach ($hits as $hit) {
            $displayId = !empty($hit['parentId']) ? (int)$hit['parentId'] : (int)$hit['id'];
            if ($displayId <= 0 || in_array($displayId, $excludedIds, true) || isset($displayIds[$displayId])) {
                continue;
            }

            $displayIds[$displayId] = $displayId;
            if (!empty($hit['parentId'])) {
                $hash = $this->variantUrlHash->build($hit['options'] ?? []);
                if ($hash !== '') {
                    $this->variantHashes[$displayId] = $hash;
                }
            }
        }

        return array_values($displayIds);
    }

    /**
     * Creates the product collection like the core "Related Products" block, ordered like the given ids
     *
     * @param int[] $productIds
     * @param int|null $storeId
     * @return Collection
     */
    private function createCollection(array $productIds, ?int $storeId): Collection
    {
        $collection = $this->collectionFactory->create();
        if ($storeId !== null) {
            $collection->setStoreId($storeId);
        }

        $collection->addIdFilter($productIds)
            ->addStoreFilter($storeId)
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addAttributeToSelect('required_options')
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addUrlRewrite();
        $this->stockHelper->addIsInStockFilterToCollection($collection);

        $collection->getSelect()->order(
            new \Zend_Db_Expr(sprintf('FIELD(e.entity_id, %s)', implode(',', array_map('intval', $productIds))))
        );
        $collection->load();

        foreach ($collection as $item) {
            $item->setDoNotUseCategoryId(true);
        }

        return $collection;
    }
}

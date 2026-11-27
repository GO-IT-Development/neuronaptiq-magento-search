<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Block\Product\Widget;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogWidget\Block\Product\ProductsList;
use Magento\CatalogWidget\Model\Rule;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Rule\Model\Condition\Sql\Builder as SqlBuilder;
use Magento\Widget\Helper\Conditions;
use NeuronaptiQ\Search\Model\TopsellerProvider;

/**
 * Widget "NeuronaptiQ Topseller": the topsellers of the search service, on category pages those of the category
 *
 * Based on Magento's widget "Catalog Products List", rendered with its template (themes can override it); only the
 * products are replaced. Shows nothing if the topseller widget is switched off. If the API fails or returns no
 * topsellers, Magento's bestsellers are shown, on category pages those of the category.
 */
class Topseller extends ProductsList
{
    private const CATEGORY_PAGE = 'catalog_category_view';

    /**
     * Maximum number of products of the search service
     */
    private const MAX_PRODUCTS = 20;

    /**
     * @var TopsellerProvider
     */
    private TopsellerProvider $topsellerProvider;

    /**
     * @var CategoryRepositoryInterface
     */
    private CategoryRepositoryInterface $topsellerCategoryRepository;

    /**
     * @param Context $context
     * @param CollectionFactory $productCollectionFactory
     * @param Visibility $catalogProductVisibility
     * @param HttpContext $httpContext
     * @param SqlBuilder $sqlBuilder
     * @param Rule $rule
     * @param Conditions $conditionsHelper
     * @param TopsellerProvider $topsellerProvider
     * @param CategoryRepositoryInterface $categoryRepository
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        CollectionFactory $productCollectionFactory,
        Visibility $catalogProductVisibility,
        HttpContext $httpContext,
        SqlBuilder $sqlBuilder,
        Rule $rule,
        Conditions $conditionsHelper,
        TopsellerProvider $topsellerProvider,
        CategoryRepositoryInterface $categoryRepository,
        array $data = []
    ) {
        $this->topsellerProvider = $topsellerProvider;
        $this->topsellerCategoryRepository = $categoryRepository;
        parent::__construct(
            $context,
            $productCollectionFactory,
            $catalogProductVisibility,
            $httpContext,
            $sqlBuilder,
            $rule,
            $conditionsHelper,
            $data,
            null,
            null,
            null,
            $categoryRepository
        );
    }

    /**
     * The topsellers in their order, visible in the catalog, at most "Number of Products to Display"
     *
     * @return Collection
     */
    public function createCollection()
    {
        // the store of the widget parameter "store_id" (like Catalog Products List) or the current store, for the
        // collection as well as for the topsellers
        $storeId = $this->getData('store_id') !== null
            ? (int)$this->getData('store_id')
            : (int)$this->_storeManager->getStore()->getId();
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);

        $categoryId = $this->getCategoryId();
        $limit = $this->getLimit();
        $topsellers = $this->topsellerProvider->getTopsellers($categoryId, $limit, $storeId);
        $ids = $topsellers['ids'] ?? [];
        if ($ids === []) {
            // an empty collection, the template shows nothing
            return $collection->addIdFilter([]);
        }

        $this->_addProductAttributesAndPrices($collection)
            ->addStoreFilter()
            ->addAttributeToFilter(Product::STATUS, ['eq' => ProductStatus::STATUS_ENABLED])
            ->setVisibility($this->catalogProductVisibility->getVisibleInCatalogIds())
            ->addIdFilter($ids);

        // Magento's bestsellers are those of the shop, the search service returns those of the category
        $category = ($topsellers['fallback'] ?? false) && $categoryId !== null
            ? $this->getCategory($categoryId, $storeId)
            : null;
        if ($category !== null) {
            $collection->addCategoryFilter($category);
        }

        $collection->getSelect()
            ->order(new \Zend_Db_Expr(sprintf('FIELD(e.entity_id, %s)', implode(',', array_map('intval', $ids)))))
            ->limit($limit);

        return $collection;
    }

    /**
     * Cache key of the block: the parameters of the request contain the category of a category page
     *
     * @return array<int|string, mixed>
     */
    public function getCacheKeyInfo()
    {
        return array_merge(['NEURONAPTIQ_TOPSELLER', $this->getCategoryId()], parent::getCacheKeyInfo());
    }

    /**
     * Number of products: "Number of Products to Display" of the widget (a string), 1-20
     *
     * @return int
     */
    private function getLimit(): int
    {
        return max(1, min((int)$this->getProductsCount(), self::MAX_PRODUCTS));
    }

    /**
     * Category of a category page, null on other pages
     *
     * @return int|null
     */
    private function getCategoryId(): ?int
    {
        $request = $this->getRequest();
        if (!$request instanceof HttpRequest || $request->getFullActionName() !== self::CATEGORY_PAGE) {
            return null;
        }
        $categoryId = (int)$request->getParam('id');

        return $categoryId > 0 ? $categoryId : null;
    }

    /**
     * Category for the filter of Magento's bestsellers, null if unknown
     *
     * @param int $categoryId
     * @param int $storeId
     * @return Category|null
     */
    private function getCategory(int $categoryId, int $storeId): ?Category
    {
        try {
            $category = $this->topsellerCategoryRepository->get($categoryId, $storeId);
        } catch (\Throwable $e) {
            return null;
        }

        return $category instanceof Category ? $category : null;
    }
}

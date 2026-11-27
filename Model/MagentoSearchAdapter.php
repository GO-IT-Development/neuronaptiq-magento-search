<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use NeuronaptiQ\Search\Exception\EmptySearchResultException;
use NeuronaptiQ\Search\Exception\SearchApiException;
use NeuronaptiQ\Search\Model\Search\CriteriaFilterReader;
use NeuronaptiQ\Search\Model\Search\FacetAggregationBuilder;

/**
 * Adapter of the quick search and the category pages for the Magento search (SearchInterface): builds a Magento
 * SearchResult
 *
 * Category pages (SimpKo/project#380): the products of the category of the page, with those of its subcategories for
 * anchor categories; the sorting "Position" is sorted by topseller (the search service has no positions).
 *
 * @phpstan-import-type SortOrder from \NeuronaptiQ\Search\Api\SearchApiClientInterface
 * @phpstan-import-type ProductSearchResult from SearchService
 */
class MagentoSearchAdapter
{
    private const REQUEST_NAME_QUICK_SEARCH = 'quick_search_container';
    private const REQUEST_NAME_CATEGORY = 'catalog_view_container';
    private const CATEGORY_PAGE = 'catalog_category_view';
    private const DEFAULT_LIMIT = 20;

    /**
     * @var SearchService
     */
    private SearchService $searchService;

    /**
     * @var SearchResultFactory
     */
    private SearchResultFactory $searchResultFactory;

    /**
     * @var DocumentFactory
     */
    private DocumentFactory $documentFactory;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var ApiSearchResultRegistry
     */
    private ApiSearchResultRegistry $apiSearchResultRegistry;

    /**
     * @var CriteriaFilterReader
     */
    private CriteriaFilterReader $criteriaFilterReader;

    /**
     * @var FacetAggregationBuilder
     */
    private FacetAggregationBuilder $facetAggregationBuilder;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var CategoryRepositoryInterface
     */
    private CategoryRepositoryInterface $categoryRepository;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param SearchService $searchService
     * @param SearchResultFactory $searchResultFactory
     * @param DocumentFactory $documentFactory
     * @param RequestInterface $request
     * @param ApiSearchResultRegistry $apiSearchResultRegistry
     * @param CriteriaFilterReader $criteriaFilterReader
     * @param FacetAggregationBuilder $facetAggregationBuilder
     * @param Config $config
     * @param CategoryRepositoryInterface $categoryRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        SearchService $searchService,
        SearchResultFactory $searchResultFactory,
        DocumentFactory $documentFactory,
        RequestInterface $request,
        ApiSearchResultRegistry $apiSearchResultRegistry,
        CriteriaFilterReader $criteriaFilterReader,
        FacetAggregationBuilder $facetAggregationBuilder,
        Config $config,
        CategoryRepositoryInterface $categoryRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->searchService = $searchService;
        $this->searchResultFactory = $searchResultFactory;
        $this->documentFactory = $documentFactory;
        $this->request = $request;
        $this->apiSearchResultRegistry = $apiSearchResultRegistry;
        $this->criteriaFilterReader = $criteriaFilterReader;
        $this->facetAggregationBuilder = $facetAggregationBuilder;
        $this->config = $config;
        $this->categoryRepository = $categoryRepository;
        $this->storeManager = $storeManager;
    }

    /**
     * Executes the search or the category page via the NeuronaptiQ API
     *
     * Returns null if the request is not handled by NeuronaptiQ (switched off, other request types, empty query, no
     * category page or a page size above the API maximum), the caller should then use the Magento search.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultInterface|null
     * @throws EmptySearchResultException If the API finds nothing (also returned for bots), use the Magento search
     * @throws SearchApiException
     */
    public function search(SearchCriteriaInterface $searchCriteria): ?SearchResultInterface
    {
        $result = match ($searchCriteria->getRequestName()) {
            self::REQUEST_NAME_QUICK_SEARCH => $this->searchQuick($searchCriteria),
            self::REQUEST_NAME_CATEGORY => $this->searchCategory($searchCriteria),
            default => null,
        };
        if ($result === null) {
            return null;
        }

        $documents = [];
        foreach ($result['hits'] as $hit) {
            $documents[] = $this->documentFactory->create(['data' => ['id' => $hit['id'], 'score' => $hit['score']]]);
        }

        /** @var SearchResultInterface $searchResult */
        $searchResult = $this->searchResultFactory->create();
        $searchResult->setSearchCriteria($searchCriteria);
        $searchResult->setItems($documents);
        $searchResult->setTotalCount($result['total']);
        $searchResult->setAggregations($this->facetAggregationBuilder->build($result['facets']));
        // Results are already paginated by the API, the result applier must not slice them again
        $this->apiSearchResultRegistry->register($searchResult);

        return $searchResult;
    }

    /**
     * Quick search, null if not handled
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return array|null
     * @phpstan-return ProductSearchResult|null
     * @throws EmptySearchResultException
     * @throws SearchApiException
     */
    private function searchQuick(SearchCriteriaInterface $searchCriteria): ?array
    {
        if (!$this->config->isSearchEnabled()) {
            return null;
        }
        $queryText = $this->searchService->getQueryText();
        $limit = $this->getLimit($searchCriteria);
        if ($queryText === '' || $limit === null) {
            return null;
        }

        // Selection of the layered navigation, the facets become the aggregations of the layered navigation
        return $this->searchService->search(
            $queryText,
            $this->searchService->getPage(),
            $limit,
            $this->searchService->getSortOrder(),
            $this->criteriaFilterReader->read($searchCriteria)
        );
    }

    /**
     * Category page, null if not handled
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return array|null
     * @phpstan-return ProductSearchResult|null
     * @throws EmptySearchResultException
     * @throws SearchApiException
     */
    private function searchCategory(SearchCriteriaInterface $searchCriteria): ?array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->config->isCategoriesEnabled($storeId)) {
            return null;
        }
        $category = $this->getPageCategory($storeId);
        $limit = $this->getLimit($searchCriteria);
        if ($category === null || $limit === null) {
            return null;
        }

        return $this->searchService->searchCategory(
            $this->searchService->getPage(),
            $limit,
            $this->getCategorySortOrder($searchCriteria),
            $this->criteriaFilterReader->read($searchCriteria, (int)$category->getId()),
            !(bool)$category->getData('is_anchor')
        );
    }

    /**
     * Products per page, null if more than the API can deliver per page (e.g. "show all")
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return int|null
     */
    private function getLimit(SearchCriteriaInterface $searchCriteria): ?int
    {
        $pageSize = (int)$searchCriteria->getPageSize();
        $requestLimit = (int)$this->request->getParam('product_list_limit', 0);
        $limit = $requestLimit > 0 ? $requestLimit : ($pageSize > 0 ? $pageSize : self::DEFAULT_LIMIT);

        return $limit <= SearchService::MAX_LIMIT ? $limit : null;
    }

    /**
     * Category of the category page, null on other pages (e.g. a product list widget with a category condition)
     *
     * @param int $storeId
     * @return Category|null
     */
    private function getPageCategory(int $storeId): ?Category
    {
        if (!$this->request instanceof HttpRequest || $this->request->getFullActionName() !== self::CATEGORY_PAGE) {
            return null;
        }
        $categoryId = (int)$this->request->getParam('id');
        if ($categoryId <= 0) {
            return null;
        }

        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
        } catch (\Throwable $e) {
            return null;
        }

        return $category instanceof Category ? $category : null;
    }

    /**
     * Sorting of the category page from the search criteria
     *
     * The sorting of the toolbar, also the default sorting of the category: price and name, everything else (e.g.
     * position) by topseller.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return array
     * @phpstan-return SortOrder
     */
    private function getCategorySortOrder(SearchCriteriaInterface $searchCriteria): array
    {
        foreach ((array)$searchCriteria->getSortOrders() as $field => $direction) {
            if ($direction instanceof \Magento\Framework\Api\SortOrder) {
                $field = (string)$direction->getField();
                $direction = (string)$direction->getDirection();
            }
            $key = match ((string)$field) {
                'price' => 'price',
                'name' => 'name',
                default => null,
            };
            if ($key !== null) {
                return ['key' => $key, 'direction' => strtolower((string)$direction) === 'desc' ? 'desc' : 'asc'];
            }
        }

        return ['key' => 'score', 'direction' => 'desc'];
    }
}

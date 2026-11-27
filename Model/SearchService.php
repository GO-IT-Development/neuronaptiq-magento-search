<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model;

use Magento\Framework\App\RequestInterface;
use NeuronaptiQ\Search\Api\CategorySearchApiClientInterface;
use NeuronaptiQ\Search\Api\FacetedSearchApiClientInterface;
use NeuronaptiQ\Search\Api\SearchApiClientInterface;
use NeuronaptiQ\Search\Exception\EmptySearchResultException;
use NeuronaptiQ\Search\Exception\SearchApiException;
use NeuronaptiQ\Search\Model\Search\SearchFilters;

/**
 * Search engine neutral part of the quick search: returns the products to display for a search page
 *
 * Variant hits are mapped to their parent product and deduplicated. The adapters convert the result into the
 * format of the search engine (Magento SearchResult, Smile ElasticSuite QueryResponse).
 *
 * Results and errors are kept per request and search parameters (including the selected filters), so repeated
 * calls with the same parameters (e.g. the separate count query of ElasticSuite) do not query the API again.
 *
 * With filters, the selection of the layered navigation is sent to the API and the facets are returned for the
 * adapters (SimpKo/project#324). Category pages are requested like the search without query (SimpKo/project#380).
 *
 * @phpstan-import-type SortOrder from SearchApiClientInterface
 * @phpstan-import-type Facet from FacetedSearchApiClientInterface
 * @phpstan-import-type FacetedSearchResult from FacetedSearchApiClientInterface
 * @phpstan-type ProductHit array{id: int, score: float, childrenIds: list<int>}
 * @phpstan-type ProductSearchResult array{hits: list<ProductHit>, total: int, facets: list<Facet>}
 */
class SearchService
{
    /**
     * Maximum page size (limit) accepted by the search API
     *
     * Requests for more products per page are not pages of a product list (e.g. collections without page size that
     * request all hits for their facets, or "show all"); the adapters pass them on to the shop's search engine.
     */
    public const MAX_LIMIT = 100;

    /**
     * @var FacetedSearchApiClientInterface
     */
    private FacetedSearchApiClientInterface $searchApiClient;

    /**
     * @var CategorySearchApiClientInterface
     */
    private CategorySearchApiClientInterface $categoryApiClient;

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * Results (or the exception) per search parameters
     *
     * @var array<string, array<string, mixed>|SearchApiException>
     * @phpstan-var array<string, ProductSearchResult|SearchApiException>
     */
    private array $results = [];

    /**
     * @param FacetedSearchApiClientInterface $searchApiClient
     * @param CategorySearchApiClientInterface $categoryApiClient
     * @param RequestInterface $request
     */
    public function __construct(
        FacetedSearchApiClientInterface $searchApiClient,
        CategorySearchApiClientInterface $categoryApiClient,
        RequestInterface $request
    ) {
        $this->searchApiClient = $searchApiClient;
        $this->categoryApiClient = $categoryApiClient;
        $this->request = $request;
    }

    /**
     * Returns the search query of the current request, '' if there is none
     *
     * @return string
     */
    public function getQueryText(): string
    {
        return trim((string)$this->request->getParam('q', ''));
    }

    /**
     * Returns the page of the current request (URL parameter "p"), at least 1
     *
     * @return int
     */
    public function getPage(): int
    {
        return max(1, (int)$this->request->getParam('p', 1));
    }

    /**
     * Resolves the sort order of the current request into key and direction
     *
     * Supported keys: score, topseller, price, name; other sort orders (e.g. relevance, position) are sorted
     * by score.
     *
     * @return array{key: string, direction: string}
     */
    public function getSortOrder(): array
    {
        $order = (string)$this->request->getParam('product_list_order', '');
        $dir = strtolower((string)$this->request->getParam('product_list_dir', ''));

        $key = match (strtolower($order)) {
            'price' => 'price',
            'name' => 'name',
            'topseller', 'bestseller' => 'topseller',
            default => 'score',
        };

        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = ($key === 'score') ? 'desc' : 'asc';
        }

        return [
            'key' => $key,
            'direction' => $dir,
        ];
    }

    /**
     * Searches the products to display on a page of the search result
     *
     * @param string $queryText
     * @param int $page
     * @param int $limit
     * @param array $sortOrder
     * @param SearchFilters|null $filters Selection of the layered navigation and the facet request
     * @return array Displayed product ids (parents of variants) with score and the hit children in API order, the
     *               total and the facets
     * @phpstan-param SortOrder $sortOrder
     * @phpstan-return ProductSearchResult
     * @throws EmptySearchResultException If the first page is empty (also returned for bots), use the engine's search
     * @throws SearchApiException
     */
    public function search(
        string $queryText,
        int $page,
        int $limit,
        array $sortOrder,
        ?SearchFilters $filters = null
    ): array {
        $filters = $filters ?? new SearchFilters();

        return $this->cached(
            ['search', $queryText, $page, $limit, $sortOrder, $filters->toArray()],
            fn (): array => $this->searchApiClient->executeFacetedSearch(
                $queryText,
                $page,
                $limit,
                $sortOrder,
                $filters
            ),
            $page,
            $filters->hasSelection(),
            $filters,
            sprintf('Search API returned no results for "%s"', $queryText)
        );
    }

    /**
     * Products to display on a page of a category (SimpKo/project#380)
     *
     * @param int $page
     * @param int $limit
     * @param array $sortOrder
     * @param SearchFilters $filters Categories of the page, selection of the layered navigation and facet request
     * @param bool $directOnly only the products assigned to the categories (category without anchor)
     * @return array Like search()
     * @phpstan-param SortOrder $sortOrder
     * @phpstan-return ProductSearchResult
     * @throws EmptySearchResultException If the first page is empty, use the category page of the shop
     * @throws SearchApiException
     */
    public function searchCategory(
        int $page,
        int $limit,
        array $sortOrder,
        SearchFilters $filters,
        bool $directOnly
    ): array {
        return $this->cached(
            ['category', $page, $limit, $sortOrder, $filters->toArray(), $directOnly],
            fn (): array => $this->categoryApiClient->executeCategorySearch(
                $page,
                $limit,
                $sortOrder,
                $filters,
                $directOnly
            ),
            $page,
            // the categories of the page are no selection
            $filters->hasLayeredSelection(),
            $filters,
            sprintf('Category API returned no results for %s', implode(',', $filters->getCategoryIds()))
        );
    }

    /**
     * Result (or the exception) of a request, kept per parameters
     *
     * @param array $parameters
     * @param callable $request
     * @param int $page
     * @param bool $hasSelection whether the visitor selected filters
     * @param SearchFilters $filters
     * @param string $emptyMessage
     * @return array
     * @phpstan-param array<mixed> $parameters
     * @phpstan-param callable(): FacetedSearchResult $request
     * @phpstan-return ProductSearchResult
     * @throws EmptySearchResultException
     * @throws SearchApiException
     */
    private function cached(
        array $parameters,
        callable $request,
        int $page,
        bool $hasSelection,
        SearchFilters $filters,
        string $emptyMessage
    ): array {
        $key = json_encode($parameters) ?: '';
        if (!array_key_exists($key, $this->results)) {
            try {
                $response = $request();
                $this->results[$key] = $this->toProductResult($response, $page, $hasSelection, $filters, $emptyMessage);
            } catch (SearchApiException $e) {
                $this->results[$key] = $e;
            }
        }

        $result = $this->results[$key];
        if ($result instanceof SearchApiException) {
            throw $result;
        }

        return $result;
    }

    /**
     * The displayed products of a response of the API
     *
     * @param array $response
     * @param int $page
     * @param bool $hasSelection
     * @param SearchFilters $filters
     * @param string $emptyMessage
     * @return array
     * @phpstan-param FacetedSearchResult $response
     * @phpstan-return ProductSearchResult
     * @throws EmptySearchResultException
     */
    private function toProductResult(
        array $response,
        int $page,
        bool $hasSelection,
        SearchFilters $filters,
        string $emptyMessage
    ): array {
        // Only an empty first page means "nothing found" (also returned for bots), whatever the total says.
        // An empty later page means paged too far: no fallback, otherwise hits, total and pager would come from two
        // different result sets. Pages beyond the API's result pool return total 0.
        // A selection without hits is a real result, recognizable by its facets (bots get none): the page stays
        // empty with the facets, so the selection can be removed, instead of falling back to the shop's search.
        $selectionWithoutHits = $hasSelection && !empty($response['facets']);
        // A count request (countOnly) has no hits by design, it never means "nothing found"
        $countOnly = $filters->isCountOnly();
        if (empty($response['results']) && $page === 1 && !$selectionWithoutHits && !$countOnly) {
            throw new EmptySearchResultException($emptyMessage);
        }

        $hits = [];
        foreach ($response['results'] as $hit) {
            // Children (variants of configurable, products of grouped and bundle products) are displayed as their
            // parent product. The hit children are kept per parent, e.g. for the swatch image of the matching
            // variant in the ElasticSuite product list
            $hitId = (int)$hit['id'];
            $parentId = !empty($hit['parentId']) ? (int)$hit['parentId'] : 0;
            $displayId = $parentId > 0 ? $parentId : $hitId;
            if ($displayId <= 0) {
                continue;
            }
            if (!isset($hits[$displayId])) {
                $hits[$displayId] = ['id' => $displayId, 'score' => (float)$hit['score'], 'childrenIds' => []];
            }
            if ($parentId > 0 && $hitId > 0 && !in_array($hitId, $hits[$displayId]['childrenIds'], true)) {
                $hits[$displayId]['childrenIds'][] = $hitId;
            }
        }

        return [
            'hits' => array_values($hits),
            'total' => $response['total'],
            'facets' => $response['facets'],
        ];
    }
}

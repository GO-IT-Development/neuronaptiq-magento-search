<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Api;

use NeuronaptiQ\Search\Exception\SearchApiException;
use NeuronaptiQ\Search\Model\Search\SearchFilters;

/**
 * Products of a category page with the filters of the layered navigation and the facets (SimpKo/project#380)
 *
 * @phpstan-import-type SortOrder from SearchApiClientInterface
 * @phpstan-import-type FacetedSearchResult from FacetedSearchApiClientInterface
 */
interface CategorySearchApiClientInterface
{
    /**
     * Executes the request of a category page (POST category) like the search without query
     *
     * The categories of the page are the category ids of the filters (SearchFilters::withCategoryIds()). The sort
     * keys are price, name and topseller; without sort or with score the search service sorts by topseller.
     *
     * @param int $page
     * @param int $limit
     * @param array $sort Optional keys 'key' and 'direction'
     * @param SearchFilters $filters
     * @param bool $directOnly only the products assigned to the categories, not those of their subcategories
     * @param int|null $storeId
     * @return array
     * @phpstan-param SortOrder $sort
     * @phpstan-return FacetedSearchResult
     * @throws SearchApiException If endpoint/token are not configured, the API is unreachable or responds non-2xx
     */
    public function executeCategorySearch(
        int $page,
        int $limit,
        array $sort,
        SearchFilters $filters,
        bool $directOnly,
        ?int $storeId = null
    ): array;
}

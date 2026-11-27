<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model\Search;

use Magento\CatalogSearch\Model\Layer\Filter\Price;
use Magento\Framework\Api\Search\SearchCriteriaInterface;

/**
 * Reads the selection of the Magento layered navigation from the search criteria of the quick search
 * (SimpKo/project#324)
 *
 * The layered navigation adds its filters to the fulltext collection, which puts them into the search criteria:
 * - attribute filters as field = attribute code, value = option id (or a list of option ids)
 * - the category filter as category_ids, sent as group category: applied to the hits of the search like the other
 *   groups, so the result matches the count of the category facet (SimpKo/project#360)
 * - the price filter as price.from and price.to; Magento subtracts 0.001 (Price::PRICE_DELTA) from the exclusive
 *   end of a range, except for a single price (from = to). The reader adds it again and sends the end as exclusive
 *   maximum, so prices like 29.9995 are not lost; a single price stays an inclusive maximum
 * - price_dynamic_algorithm, the calculation of the price ranges
 *
 * search_term and visibility are handled by the search itself. category_ids_to_aggregate only limits the category
 * aggregation of OpenSearch to the children of the current category, it is not a filter; the layered navigation
 * shows only those children anyway.
 */
class CriteriaFilterReader
{
    private const IGNORED_FIELDS = ['search_term', 'visibility', 'category_ids_to_aggregate'];
    private const FIELD_CATEGORY = 'category_ids';
    private const FIELD_PRICE_FROM = 'price.from';
    private const FIELD_PRICE_TO = 'price.to';
    private const FIELD_PRICE_ALGORITHM = 'price_dynamic_algorithm';

    /**
     * @var PriceRangesResolver
     */
    private PriceRangesResolver $priceRangesResolver;

    /**
     * @param PriceRangesResolver $priceRangesResolver
     */
    public function __construct(PriceRangesResolver $priceRangesResolver)
    {
        $this->priceRangesResolver = $priceRangesResolver;
    }

    /**
     * Returns the selection and requests the facets with the price ranges of the shop
     *
     * On a category page the category of the page is the hard category filter (the categories of the request to
     * the search service), not a selection; a subcategory selected in the layered navigation stays a selection.
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @param int|null $pageCategoryId the category of a category page, null on the search result page
     * @return SearchFilters
     */
    public function read(SearchCriteriaInterface $searchCriteria, ?int $pageCategoryId = null): SearchFilters
    {
        $filters = new SearchFilters();
        if ($pageCategoryId !== null) {
            $filters = $filters->withCategoryIds([$pageCategoryId]);
        }
        $priceFrom = null;
        $priceTo = null;
        $algorithm = null;

        // Implementations of getFilterGroups() may return null (no filters set)
        foreach ((array)$searchCriteria->getFilterGroups() as $filterGroup) {
            foreach ($filterGroup->getFilters() as $filter) {
                $field = (string)$filter->getField();
                $values = $this->toList($filter->getValue());
                switch ($field) {
                    case self::FIELD_CATEGORY:
                        $filters = $filters->withCategories(array_values(array_filter(
                            $values,
                            static fn ($value): bool => $pageCategoryId === null
                                || !is_scalar($value) || (int)$value !== $pageCategoryId
                        )));
                        break;
                    case self::FIELD_PRICE_FROM:
                        $priceFrom = $this->toPrice($values);
                        break;
                    case self::FIELD_PRICE_TO:
                        $priceTo = $this->toPrice($values);
                        break;
                    case self::FIELD_PRICE_ALGORITHM:
                        $algorithm = isset($values[0]) && is_scalar($values[0]) ? (string)$values[0] : null;
                        break;
                    default:
                        if ($field !== '' && !in_array($field, self::IGNORED_FIELDS, true)) {
                            $filters = $filters->withGroup($field, $values);
                        }
                }
            }
        }

        if ($priceTo !== null && $priceTo !== $priceFrom) {
            // Restore the original end of the range and send it as exclusive maximum (SimpKo/project#348)
            $filters = $filters->withPrice($priceFrom, round($priceTo + Price::PRICE_DELTA, 6), true);
        } elseif ($priceFrom !== null) {
            // A range without end or a single price (from = to)
            $filters = $filters->withPrice($priceFrom, $priceTo);
        }

        return $filters->withFacets($this->priceRangesResolver->resolve($algorithm));
    }

    /**
     * Values of a filter as list, also from conditions like ['in' => [...]] or ['eq' => ...]
     *
     * @param mixed $value
     * @return array<mixed>
     */
    private function toList(mixed $value): array
    {
        if (!is_array($value)) {
            return [$value];
        }

        $list = [];
        foreach ($value as $item) {
            foreach (is_array($item) ? $item : [$item] as $scalar) {
                $list[] = $scalar;
            }
        }

        return $list;
    }

    /**
     * First numeric value as price, null if none
     *
     * @param array $values
     * @return float|null
     * @phpstan-param array<mixed> $values
     */
    private function toPrice(array $values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }
}

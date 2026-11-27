<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Api;

use NeuronaptiQ\Search\Exception\SearchApiException;

/**
 * Interface for the recommendations of the NeuronaptiQ REST API: cross-sellings of a kind, of several products and
 * the topsellers
 *
 * @phpstan-import-type SearchHit from SearchApiClientInterface
 */
interface RecommendationApiClientInterface
{
    /**
     * Returns the cross-selling hits of a kind for a product (POST xselling/{productId})
     *
     * Hits may be variants, in that case 'parentId' and 'options' are set.
     *
     * @param string $productId
     * @param string $kind related, complementary or upsell
     * @param int $limit
     * @param int|null $storeId
     * @return array List of hits
     * @phpstan-return list<SearchHit>
     * @throws SearchApiException If endpoint/token are not configured, the API is unreachable or responds non-2xx
     */
    public function getCrossSellingsOfKind(string $productId, string $kind, int $limit, ?int $storeId = null): array;

    /**
     * Returns the cross-selling hits of a kind for several products, e.g. the cart (POST xselling)
     *
     * A product recommended for several of the products comes first, the products themselves, their parents and
     * variants are no recommendations.
     *
     * @param string[] $productIds 1-20 product ids
     * @param string $kind related, complementary or upsell
     * @param int $limit
     * @param int|null $storeId
     * @return array List of hits
     * @phpstan-return list<SearchHit>
     * @throws SearchApiException If endpoint/token are not configured, the API is unreachable or responds non-2xx
     */
    public function getCrossSellingsOfProducts(
        array $productIds,
        string $kind,
        int $limit,
        ?int $storeId = null
    ): array;

    /**
     * Returns the topsellers of a category or of the shop (POST recommend, kind topseller)
     *
     * @param string|null $categoryId the topsellers of this category, null: of the shop
     * @param int $limit
     * @param int|null $storeId
     * @return array List of hits
     * @phpstan-return list<SearchHit>
     * @throws SearchApiException If endpoint/token are not configured, the API is unreachable or responds non-2xx
     */
    public function getTopsellers(?string $categoryId, int $limit, ?int $storeId = null): array;
}

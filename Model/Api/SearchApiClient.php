<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use NeuronaptiQ\Search\Api\CategorySearchApiClientInterface;
use NeuronaptiQ\Search\Api\CrossSellingApiClientInterface;
use NeuronaptiQ\Search\Api\FacetedSearchApiClientInterface;
use NeuronaptiQ\Search\Api\RecommendationApiClientInterface;
use NeuronaptiQ\Search\Api\SearchApiClientInterface;
use NeuronaptiQ\Search\Exception\SearchApiException;
use NeuronaptiQ\Search\Model\Config;
use NeuronaptiQ\Search\Model\CustomerDataProvider;
use NeuronaptiQ\Search\Model\OriginProvider;
use NeuronaptiQ\Search\Model\Search\SearchFilters;
use NeuronaptiQ\Search\Model\Session\SidManager;
use Psr\Log\LoggerInterface;

/**
 * REST JSON Client for the NeuronaptiQ Search Service
 *
 * @phpstan-import-type SearchHit from SearchApiClientInterface
 * @phpstan-import-type SearchPageResult from SearchApiClientInterface
 * @phpstan-import-type SortOrder from SearchApiClientInterface
 * @phpstan-import-type Facet from FacetedSearchApiClientInterface
 * @phpstan-import-type FacetedSearchResult from FacetedSearchApiClientInterface
 */
class SearchApiClient implements
    SearchApiClientInterface,
    CrossSellingApiClientInterface,
    RecommendationApiClientInterface,
    FacetedSearchApiClientInterface,
    CategorySearchApiClientInterface
{
    /**
     * Fields returned by the API: "basic" contains everything the module reads (id, score, parentId, categories,
     * options), "full" would add descriptions, prices, images etc. that are loaded from Magento anyway
     */
    private const RETURN_FIELDS = 'basic';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @var Json
     */
    private Json $jsonSerializer;

    /**
     * @var SidManager
     */
    private SidManager $sidManager;

    /**
     * @var RemoteAddress
     */
    private RemoteAddress $remoteAddress;

    /**
     * @var Header
     */
    private Header $httpHeader;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var CustomerDataProvider
     */
    private CustomerDataProvider $customerDataProvider;

    /**
     * @var OriginProvider
     */
    private OriginProvider $originProvider;

    /**
     * @param Config $config
     * @param Curl $curl
     * @param Json $jsonSerializer
     * @param SidManager $sidManager
     * @param RemoteAddress $remoteAddress
     * @param Header $httpHeader
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param CustomerDataProvider $customerDataProvider
     * @param OriginProvider $originProvider
     */
    public function __construct(
        Config $config,
        Curl $curl,
        Json $jsonSerializer,
        SidManager $sidManager,
        RemoteAddress $remoteAddress,
        Header $httpHeader,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        CustomerDataProvider $customerDataProvider,
        OriginProvider $originProvider
    ) {
        $this->config = $config;
        $this->curl = $curl;
        $this->jsonSerializer = $jsonSerializer;
        $this->sidManager = $sidManager;
        $this->remoteAddress = $remoteAddress;
        $this->httpHeader = $httpHeader;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->customerDataProvider = $customerDataProvider;
        $this->originProvider = $originProvider;
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, int $limit = 5, ?int $storeId = null): array
    {
        $endpoint = trim($this->config->getSuggestApiEndpoint($storeId));

        if (!empty($endpoint) && filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->executeSearchRequest($endpoint, $query, $limit, $storeId);
        }

        throw new SearchApiException('Suggest API endpoint is not configured or invalid.');
    }

    /**
     * Executes the REST JSON POST request against the NeuronaptiQ Search API
     *
     * @param string $endpoint
     * @param string $query
     * @param int $limit
     * @param int|null $storeId
     * @return array
     * @phpstan-return list<SearchHit>
     * @throws SearchApiException
     */
    protected function executeSearchRequest(string $endpoint, string $query, int $limit, ?int $storeId = null): array
    {
        $token = $this->config->getToken($storeId);
        if ($token === '') {
            throw new SearchApiException('API token is not configured.');
        }

        try {
            $timeout = $this->config->getTimeout($storeId);

            $payload = $this->buildRequestBody($query, $limit, $storeId);
            $jsonPayload = $this->jsonSerializer->serialize($payload);

            $this->curl->setTimeout($timeout);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', 'Bearer ' . $token);

            $this->logger->debug(
                sprintf('[NeuronaptiQ_Search] Sending request to %s: %s', $endpoint, $jsonPayload)
            );

            $this->curl->post($endpoint, $jsonPayload);

            $statusCode = $this->curl->getStatus();
            $responseBody = (string)$this->curl->getBody();

            $this->logger->debug(
                sprintf('[NeuronaptiQ_Search] Response (HTTP %s): %s', $statusCode, $responseBody)
            );

            if ($statusCode < 200 || $statusCode >= 300 || $responseBody === '') {
                throw new SearchApiException(
                    sprintf('Suggest API returned HTTP %s (see debug log for the response body)', $statusCode)
                );
            }

            $parsedResponse = $this->jsonSerializer->unserialize($responseBody);
            return $this->processResponse($parsedResponse);
        } catch (SearchApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SearchApiException(sprintf('Error querying Suggest API: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getCrossSellings(string $productId, int $limit, ?int $storeId = null): array
    {
        return $this->getCrossSellingsOfKind($productId, Config::KIND_RELATED, $limit, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getCrossSellingsOfKind(string $productId, string $kind, int $limit, ?int $storeId = null): array
    {
        return $this->postRecommendation(
            $this->config->getCrossSellingApiEndpoint($productId, $storeId),
            $this->buildCrossSellingBody($kind, $limit, $storeId),
            'Cross-selling',
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function getCrossSellingsOfProducts(
        array $productIds,
        string $kind,
        int $limit,
        ?int $storeId = null
    ): array {
        $payload = $this->buildCrossSellingBody($kind, $limit, $storeId);
        $payload['productIds'] = array_values(array_map('strval', $productIds));

        return $this->postRecommendation(
            $this->config->getCrossSellingProductsApiEndpoint($storeId),
            $payload,
            'Cross-selling',
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function getTopsellers(?string $categoryId, int $limit, ?int $storeId = null): array
    {
        $payload = $this->buildRequestBody('', $limit, $storeId);
        unset($payload['q']);
        $payload['kind'] = 'topseller';
        if ($categoryId !== null) {
            $payload['params']['categoryIds'] = [$categoryId];
        }

        return $this->postRecommendation(
            $this->config->getRecommendApiEndpoint($storeId),
            $payload,
            'Recommend',
            $storeId
        );
    }

    /**
     * Payload of a cross-selling, with the kind and the base currency of the store
     *
     * Like the suggest request (channel, remote, settings, sid, customer) without query; the currency is the one of
     * the price range of the upsell.
     *
     * @param string $kind
     * @param int $limit
     * @param int|null $storeId
     * @return array<string, mixed>
     */
    private function buildCrossSellingBody(string $kind, int $limit, ?int $storeId): array
    {
        $payload = $this->buildRequestBody('', $limit, $storeId);
        unset($payload['q']);
        $payload['kind'] = $kind;

        try {
            // the prices of the products are in the base currency like the price filters of the search, the
            // displayed currency is only converted
            $store = $this->storeManager->getStore($storeId);
            $currency = $store instanceof Store ? (string)$store->getBaseCurrencyCode() : '';
        } catch (\Throwable $e) {
            $currency = '';
        }
        if ($currency !== '') {
            $payload['params']['currency'] = $currency;
        }

        return $payload;
    }

    /**
     * Posts a recommendation request and returns its hits
     *
     * @param string $endpoint
     * @param array $payload
     * @param string $label name of the API in log entries and errors
     * @param int|null $storeId
     * @return array
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-return list<SearchHit>
     * @throws SearchApiException
     */
    private function postRecommendation(string $endpoint, array $payload, string $label, ?int $storeId): array
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new SearchApiException(sprintf('%s API endpoint is not configured or invalid.', $label));
        }

        $token = $this->config->getToken($storeId);
        if ($token === '') {
            throw new SearchApiException('API token is not configured.');
        }

        try {
            $jsonPayload = $this->jsonSerializer->serialize($payload);

            $this->curl->setTimeout($this->config->getTimeout($storeId));
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', 'Bearer ' . $token);

            $this->logger->debug(sprintf('[NeuronaptiQ_Search] %s request to %s: %s', $label, $endpoint, $jsonPayload));

            $this->curl->post($endpoint, $jsonPayload);

            $statusCode = $this->curl->getStatus();
            $responseBody = (string)$this->curl->getBody();

            $this->logger->debug(
                sprintf('[NeuronaptiQ_Search] %s response (HTTP %s): %s', $label, $statusCode, $responseBody)
            );

            if ($statusCode < 200 || $statusCode >= 300 || $responseBody === '') {
                throw new SearchApiException(
                    sprintf('%s API returned HTTP %s (see debug log for the response body)', $label, $statusCode)
                );
            }

            return $this->processResponse($this->jsonSerializer->unserialize($responseBody));
        } catch (SearchApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SearchApiException(sprintf('Error querying %s API: %s', $label, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Builds the JSON request payload
     *
     * @param string $query
     * @param int $limit
     * @param int|null $storeId
     * @return array<string, mixed>
     */
    public function buildRequestBody(string $query, int $limit, ?int $storeId = null): array
    {
        // Channel corresponds to Magento website ID
        $channel = '1';
        try {
            $store = $this->storeManager->getStore($storeId);
            $channel = (string)$store->getWebsiteId();
        } catch (\Throwable $e) {
            // Default to channel 1 on store lookup failure
            $this->logger->warning(
                sprintf(
                    '[NeuronaptiQ_Search] Store lookup failed (%s). Falling back to channel 1.',
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }

        $remoteAddr = (string)($this->remoteAddress->getRemoteAddress() ?: '127.0.0.1');
        $userAgent = (string)($this->httpHeader->getHttpUserAgent() ?: '');
        $sid = $this->sidManager->getSid();

        $body = [
            'limit' => $limit,
            'params' => [
                'channel' => $channel
            ],
            'q' => $query,
            'remote' => [
                'remoteAddr' => $remoteAddr,
                'userAgent' => $userAgent
            ],
            'origin' => $this->originProvider->getOrigin(),
            'settings' => [
                'return' => self::RETURN_FIELDS
            ],
            'sid' => $sid
        ];

        return $this->addCustomerData($body);
    }

    /**
     * Processes API response, stores SID into session, and extracts results
     *
     * @param mixed $response
     * @return array
     * @phpstan-return list<SearchHit>
     * @throws SearchApiException
     */
    public function processResponse(mixed $response): array
    {
        if (!is_array($response)) {
            throw new SearchApiException('Suggest API returned an invalid response.');
        }

        // Store returned SID in customer session for subsequent requests
        if (!empty($response['sid']) && is_string($response['sid'])) {
            $this->sidManager->setSid($response['sid']);
        }

        $rawResults = $response['results'] ?? [];
        if (!is_array($rawResults) || empty($rawResults)) {
            return [];
        }

        $results = [];
        foreach ($rawResults as $item) {
            if (is_array($item) && isset($item['id'])) {
                $results[] = [
                    'id' => (int)$item['id'],
                    'score' => isset($item['score']) ? (float)$item['score'] : 0.0,
                    'parentId' => !empty($item['parentId']) ? (int)$item['parentId'] : null,
                    'options' => is_array($item['options'] ?? null) ? $item['options'] : [],
                    'categoryIds' => $this->extractCategoryIds($item['categories'] ?? null)
                ];
            }
        }

        // Ensure results are strictly ordered by score descending
        usort($results, static fn(array $a, array $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /**
     * Returns the ids of the categories of a hit (category path from the top level to the deepest category)
     *
     * @param mixed $categories
     * @return list<string>
     */
    private function extractCategoryIds($categories): array
    {
        if (!is_array($categories)) {
            return [];
        }

        $ids = [];
        foreach ($categories as $category) {
            if (is_array($category) && isset($category['id']) && is_scalar($category['id'])) {
                $ids[] = (string)$category['id'];
            }
        }

        return $ids;
    }

    /**
     * @inheritDoc
     */
    public function executeSearch(
        string $query,
        int $page = 1,
        int $limit = 20,
        array $sort = [],
        ?int $storeId = null
    ): array {
        $result = $this->executeFacetedSearch($query, $page, $limit, $sort, new SearchFilters(), $storeId);
        unset($result['facets']);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function executeFacetedSearch(
        string $query,
        int $page,
        int $limit,
        array $sort,
        SearchFilters $filters,
        ?int $storeId = null
    ): array {
        $endpoint = trim($this->config->getSearchApiEndpoint($storeId));

        if (!empty($endpoint) && filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->executeFullSearchRequest($endpoint, $query, $page, $limit, $sort, $storeId, $filters);
        }

        throw new SearchApiException('Search API endpoint is not configured or invalid.');
    }

    /**
     * Executes the REST JSON POST request against the NeuronaptiQ Search Page API
     *
     * @param string $endpoint
     * @param string $query
     * @param int $page
     * @param int $limit
     * @param array $sort
     * @param int|null $storeId
     * @param SearchFilters|null $filters
     * @return array
     * @phpstan-param SortOrder $sort
     * @phpstan-return FacetedSearchResult
     * @throws SearchApiException
     */
    protected function executeFullSearchRequest(
        string $endpoint,
        string $query,
        int $page,
        int $limit,
        array $sort,
        ?int $storeId = null,
        ?SearchFilters $filters = null
    ): array {
        return $this->postSearchRequest(
            $endpoint,
            fn (): array => $this->buildSearchRequestBody($query, $page, $limit, $sort, $storeId, $filters),
            'Search',
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function executeCategorySearch(
        int $page,
        int $limit,
        array $sort,
        SearchFilters $filters,
        bool $directOnly,
        ?int $storeId = null
    ): array {
        $endpoint = trim($this->config->getCategoryApiEndpoint($storeId));
        if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new SearchApiException('Category API endpoint is not configured or invalid.');
        }

        return $this->postSearchRequest(
            $endpoint,
            fn (): array => $this->buildCategoryRequestBody($page, $limit, $sort, $storeId, $filters, $directOnly),
            'Category',
            $storeId
        );
    }

    /**
     * Builds the JSON request payload of a category page: like the search without query
     *
     * The search service sorts a category page by topseller, name, price or created, without relevance: the sort
     * key score (e.g. the sorting "Position" of Magento) is sent without sort, the search service then sorts by
     * topseller.
     *
     * @param int $page
     * @param int $limit
     * @param array $sort
     * @param int|null $storeId
     * @param SearchFilters $filters
     * @param bool $directOnly
     * @return array<string, mixed>
     * @phpstan-param SortOrder $sort
     */
    public function buildCategoryRequestBody(
        int $page,
        int $limit,
        array $sort,
        ?int $storeId,
        SearchFilters $filters,
        bool $directOnly
    ): array {
        $body = $this->buildSearchRequestBody('', $page, $limit, $sort, $storeId, $filters);
        unset($body['q']);
        if (($body['sort']['key'] ?? 'score') === 'score') {
            unset($body['sort']);
        }
        if ($directOnly) {
            $body['params']['directOnly'] = true;
        }

        return $body;
    }

    /**
     * Posts a request of the search or a category page and processes its response
     *
     * @param string $endpoint
     * @param callable $buildPayload
     * @param string $label name of the API in log entries and errors
     * @param int|null $storeId
     * @return array
     * @phpstan-param callable(): array<string, mixed> $buildPayload
     * @phpstan-return FacetedSearchResult
     * @throws SearchApiException
     */
    private function postSearchRequest(string $endpoint, callable $buildPayload, string $label, ?int $storeId): array
    {
        $token = $this->config->getToken($storeId);
        if ($token === '') {
            throw new SearchApiException('API token is not configured.');
        }

        try {
            $timeout = $this->config->getTimeout($storeId);

            $jsonPayload = $this->jsonSerializer->serialize($buildPayload());

            $this->curl->setTimeout($timeout);
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->addHeader('Authorization', 'Bearer ' . $token);

            $this->logger->debug(
                sprintf('[NeuronaptiQ_Search] %s request to %s: %s', $label, $endpoint, $jsonPayload)
            );

            $this->curl->post($endpoint, $jsonPayload);

            $statusCode = $this->curl->getStatus();
            $responseBody = (string)$this->curl->getBody();

            $this->logger->debug(
                sprintf('[NeuronaptiQ_Search] %s response (HTTP %s): %s', $label, $statusCode, $responseBody)
            );

            if ($statusCode < 200 || $statusCode >= 300 || $responseBody === '') {
                throw new SearchApiException(
                    sprintf('%s API returned HTTP %s (see debug log for the response body)', $label, $statusCode)
                );
            }

            $parsedResponse = $this->jsonSerializer->unserialize($responseBody);
            return $this->processSearchResponse($parsedResponse);
        } catch (SearchApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new SearchApiException(sprintf('Error querying %s API: %s', $label, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Builds the JSON request payload for search page
     *
     * @param string $query
     * @param int $page
     * @param int $limit
     * @param array $sort
     * @param int|null $storeId
     * @param SearchFilters|null $filters
     * @return array<string, mixed>
     * @phpstan-param SortOrder $sort
     */
    public function buildSearchRequestBody(
        string $query,
        int $page,
        int $limit,
        array $sort,
        ?int $storeId = null,
        ?SearchFilters $filters = null
    ): array {
        $channel = '1';
        $currency = '';
        try {
            $store = $this->storeManager->getStore($storeId);
            $channel = (string)$store->getWebsiteId();
            // Price filters and ranges of the layered navigation are in the base currency, only their labels are
            // converted into the displayed currency
            $currency = $store instanceof Store ? (string)$store->getBaseCurrencyCode() : '';
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    '[NeuronaptiQ_Search] Store lookup failed (%s). Falling back to channel 1.',
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }

        $remoteAddr = (string)($this->remoteAddress->getRemoteAddress() ?: '127.0.0.1');
        $userAgent = (string)($this->httpHeader->getHttpUserAgent() ?: '');
        $sid = $this->sidManager->getSid();

        $direction = strtolower($sort['direction'] ?? 'desc');
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $sortKey = match (strtolower((string)($sort['key'] ?? 'score'))) {
            'price' => 'price',
            'name' => 'name',
            'topseller', 'bestseller' => 'topseller',
            default => 'score',
        };

        $body = [
            'limit' => $limit,
            'page' => $page,
            'params' => [
                'channel' => $channel
            ],
            'q' => $query,
            'remote' => [
                'remoteAddr' => $remoteAddr,
                'userAgent' => $userAgent
            ],
            'origin' => $this->originProvider->getOrigin(),
            'settings' => [
                'return' => self::RETURN_FIELDS
            ],
            'sid' => $sid,
            'sort' => [
                'direction' => $direction,
                'key' => $sortKey
            ]
        ];

        if ($filters !== null) {
            $body['params'] = $this->addFilterParams($body['params'], $filters, $currency);
        }

        return $this->addCustomerData($body);
    }

    /**
     * Adds the selection of the layered navigation and the facet request to the params of a search request
     *
     * @param array $params
     * @param SearchFilters $filters
     * @param string $currency
     * @return array<string, mixed>
     * @phpstan-param array<string, mixed> $params
     */
    private function addFilterParams(array $params, SearchFilters $filters, string $currency): array
    {
        foreach ($filters->getGroups() as $group => $values) {
            $params['filters'][] = ['group' => $group, 'values' => $values];
        }

        if ($filters->getCategoryIds() !== []) {
            $params['categoryIds'] = $filters->getCategoryIds();
        }

        if ($filters->getPriceMin() !== null || $filters->getPriceMax() !== null) {
            $price = [];
            if ($filters->getPriceMin() !== null) {
                $price['min'] = $filters->getPriceMin();
            }
            if ($filters->getPriceMax() !== null) {
                $price['max'] = $filters->getPriceMax();
                if ($filters->isPriceMaxExclusive()) {
                    $price['maxExclusive'] = true;
                }
            }
            $params['price'] = $price;
        }

        if ($filters->isCountOnly()) {
            $params['countOnly'] = true;
        }

        if ($filters->hasFacets()) {
            $params['facets'] = true;
            // An empty object requests the automatic step, an empty array would be a JSON array
            $params['priceRanges'] = $filters->getPriceRanges() ?: new \stdClass();
        }

        if ($currency !== '' && ($filters->hasFacets() || isset($params['price']))) {
            $params['currency'] = $currency;
        }

        return $params;
    }

    /**
     * Adds the "customer" object to the request payload if a customer is logged in
     *
     * @param array $body
     * @return array<string, mixed>
     * @phpstan-param array<string, mixed> $body
     */
    private function addCustomerData(array $body): array
    {
        $customerData = $this->customerDataProvider->getCustomerData();
        if ($customerData !== null) {
            $body['customer'] = $customerData;
        }

        return $body;
    }

    /**
     * Processes search response, stores SID, and extracts pagination total, results and facets
     *
     * @param mixed $response
     * @return array
     * @phpstan-return FacetedSearchResult
     * @throws SearchApiException
     */
    public function processSearchResponse(mixed $response): array
    {
        if (!is_array($response)) {
            throw new SearchApiException('Search API returned an invalid response.');
        }

        if (!empty($response['sid']) && is_string($response['sid'])) {
            $this->sidManager->setSid($response['sid']);
        }

        $total = isset($response['total']) ? (int)$response['total'] : 0;
        $rawResults = $response['results'] ?? [];

        $results = [];
        if (is_array($rawResults)) {
            foreach ($rawResults as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $results[] = [
                        'id' => (int)$item['id'],
                        'score' => isset($item['score']) ? (float)$item['score'] : 0.0,
                        'parentId' => !empty($item['parentId']) ? (int)$item['parentId'] : null,
                        'options' => is_array($item['options'] ?? null) ? $item['options'] : []
                    ];
                }
            }
        }

        if ($total === 0 && !empty($results)) {
            $total = count($results);
        }

        return [
            'results' => $results,
            'total' => $total,
            'facets' => $this->extractFacets($response['facets'] ?? null),
            'sid' => $this->sidManager->getSid()
        ];
    }

    /**
     * Extracts the facets of a search response, invalid entries are skipped
     *
     * @param mixed $rawFacets
     * @return array
     * @phpstan-return list<Facet>
     */
    private function extractFacets(mixed $rawFacets): array
    {
        if (!is_array($rawFacets)) {
            return [];
        }

        $facets = [];
        foreach ($rawFacets as $rawFacet) {
            if (!is_array($rawFacet) || !isset($rawFacet['group']) || !is_scalar($rawFacet['group'])) {
                continue;
            }

            $facet = ['group' => (string)$rawFacet['group'], 'values' => []];
            foreach (is_array($rawFacet['values'] ?? null) ? $rawFacet['values'] : [] as $value) {
                if (is_array($value) && isset($value['value']) && is_scalar($value['value'])) {
                    $facet['values'][] = ['value' => (string)$value['value'], 'count' => (int)($value['count'] ?? 0)];
                }
            }
            foreach (['min', 'max', 'step'] as $key) {
                if (isset($rawFacet[$key]) && is_numeric($rawFacet[$key])) {
                    $facet[$key] = (float)$rawFacet[$key];
                }
            }
            if (is_array($rawFacet['ranges'] ?? null)) {
                $facet['ranges'] = [];
                foreach ($rawFacet['ranges'] as $range) {
                    if (is_array($range) && is_numeric($range['from'] ?? null) && is_numeric($range['to'] ?? null)) {
                        $facet['ranges'][] = [
                            'from' => (float)$range['from'],
                            'to' => (float)$range['to'],
                            'count' => (int)($range['count'] ?? 0),
                        ];
                    }
                }
            }

            $facets[] = $facet;
        }

        return $facets;
    }
}

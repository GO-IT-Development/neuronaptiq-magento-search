<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Config
{
    public const XML_PATH_SEARCH = 'neuronaptiq_search/general/search';
    public const XML_PATH_CATEGORIES = 'neuronaptiq_search/general/categories';
    public const XML_PATH_CROSSSELLINGS = 'neuronaptiq_search/general/crosssellings';
    public const XML_PATH_CROSSSELLINGS_LIMIT = 'neuronaptiq_search/general/crosssellings_limit';
    public const XML_PATH_CROSSSELLINGS_KIND = 'neuronaptiq_search/general/crosssellings_kind';
    public const XML_PATH_UPSELL_KIND = 'neuronaptiq_search/general/upsell_kind';
    public const XML_PATH_CART_CROSSSELL_KIND = 'neuronaptiq_search/general/cart_crosssell_kind';
    public const XML_PATH_TOPSELLER = 'neuronaptiq_search/general/topseller';
    public const XML_PATH_SEND_CUSTOMER_DATA = 'neuronaptiq_search/general/send_customer_data';
    public const XML_PATH_TRACKING = 'neuronaptiq_search/general/tracking';
    public const XML_PATH_ENVIRONMENT = 'neuronaptiq_search/general/environment';
    public const XML_PATH_INSTANCE_NAME = 'neuronaptiq_search/general/name';
    public const XML_PATH_TOKEN = 'neuronaptiq_search/general/token';
    public const XML_PATH_TIMEOUT = 'neuronaptiq_search/general/timeout';
    public const XML_PATH_SUGGEST_LIMIT = 'neuronaptiq_search/general/suggest_limit';
    public const XML_PATH_ENHANCED_SUGGESTIONS = 'neuronaptiq_search/general/enhanced_suggestions';
    public const XML_PATH_SUGGEST_PARENT_PRODUCTS = 'neuronaptiq_search/general/suggest_parent_products';

    public const ENV_DEV = 'dev';
    public const ENV_TEST = 'test';
    public const ENV_PROD = 'prod';

    /**
     * Kinds of the cross-sellings of the search service: similar products, accessories, more expensive alternatives
     */
    public const KIND_RELATED = 'related';
    public const KIND_COMPLEMENTARY = 'complementary';
    public const KIND_UPSELL = 'upsell';
    public const CROSSSELLING_KINDS = [self::KIND_RELATED, self::KIND_COMPLEMENTARY, self::KIND_UPSELL];

    /**
     * Value of a kind setting that is switched off
     */
    public const KIND_OFF = 'off';

    private const BASE_URL_DEV = 'http://host.docker.internal:8635';
    private const BASE_URL_PRODUCTION = 'https://%s.synapse.neuronaptiq.ai';
    private const BASE_URL_TESTING = self::BASE_URL_PRODUCTION;
    private const API_PATH = '/api/v1/search/ecomm/';

    private const ENDPOINT_SUGGEST = 'suggest';
    private const ENDPOINT_SEARCH = 'search';
    private const ENDPOINT_CATEGORY = 'category';
    private const ENDPOINT_TRACK = 'track';
    private const ENDPOINT_XSELLING = 'xselling';
    private const ENDPOINT_RECOMMEND = 'recommend';

    private const DEFAULT_CROSSSELLINGS_LIMIT = 8;
    private const MAX_CROSSSELLINGS_LIMIT = 20;

    private const DEFAULT_SUGGEST_LIMIT = 5;
    private const DEFAULT_TIMEOUT = 2;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
    }

    /**
     * Check if search is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSearchEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SEARCH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the category pages are listed by NeuronaptiQ
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCategoriesEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CATEGORIES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if cross-sellings are enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCrosssellingsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CROSSSELLINGS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the data of logged-in customers may be sent to the API
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCustomerDataEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SEND_CUSTOMER_DATA,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if tracking of cart, checkout and order events is enabled
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isTrackingEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_TRACKING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieve the number of cross-selling products (1-20)
     *
     * @param int|null $storeId
     * @return int
     */
    public function getCrosssellingsLimit(?int $storeId = null): int
    {
        $limit = (int)$this->scopeConfig->getValue(
            self::XML_PATH_CROSSSELLINGS_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($limit < 1) {
            return self::DEFAULT_CROSSSELLINGS_LIMIT;
        }

        return min($limit, self::MAX_CROSSSELLINGS_LIMIT);
    }

    /**
     * Kind of the cross-sellings in the "Related Products" block of the product page (with cross-sellings enabled)
     *
     * @param int|null $storeId
     * @return string one of CROSSSELLING_KINDS, related if not set
     */
    public function getCrosssellingsKind(?int $storeId = null): string
    {
        return $this->getKind(self::XML_PATH_CROSSSELLINGS_KIND, $storeId) ?? self::KIND_RELATED;
    }

    /**
     * Kind of the cross-sellings in the "Upsell" block of the product page
     *
     * @param int|null $storeId
     * @return string|null one of CROSSSELLING_KINDS, null if switched off (the maintained upsells of Magento)
     */
    public function getUpsellKind(?int $storeId = null): ?string
    {
        return $this->getKind(self::XML_PATH_UPSELL_KIND, $storeId);
    }

    /**
     * Kind of the cross-sellings in the "Cross-Sell" block of the cart page
     *
     * @param int|null $storeId
     * @return string|null one of CROSSSELLING_KINDS, null if switched off (the maintained cross-sells of Magento)
     */
    public function getCartCrosssellKind(?int $storeId = null): ?string
    {
        return $this->getKind(self::XML_PATH_CART_CROSSSELL_KIND, $storeId);
    }

    /**
     * Check if the topseller widget shows the topsellers of the search service
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isTopsellerEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_TOPSELLER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * A kind setting, null if switched off or unknown
     *
     * @param string $path
     * @param int|null $storeId
     * @return string|null
     */
    private function getKind(string $path, ?int $storeId): ?string
    {
        $kind = (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return in_array($kind, self::CROSSSELLING_KINDS, true) ? $kind : null;
    }

    /**
     * Check if the enhanced search suggestions are enabled (image, price, direct link to the product page)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnhancedSuggestionsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENHANCED_SUGGESTIONS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Whether variants are suggested as their parent product (name, image and "as low as" price of the parent)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSuggestParentProductsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SUGGEST_PARENT_PRODUCTS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Retrieve configured environment ('dev', 'test', 'prod'), 'prod' for missing or unknown values
     *
     * @param int|null $storeId
     * @return string
     */
    public function getEnvironment(?int $storeId = null): string
    {
        $env = (string)$this->scopeConfig->getValue(
            self::XML_PATH_ENVIRONMENT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($env, [self::ENV_DEV, self::ENV_TEST, self::ENV_PROD], true)
            ? $env
            : self::ENV_PROD;
    }

    /**
     * Retrieve NeuronaptiQ instance name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getInstanceName(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_INSTANCE_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Assembles and returns the REST API Endpoint URL for Search Suggest based on environment and instance name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSuggestApiEndpoint(?int $storeId = null): string
    {
        return $this->buildApiEndpoint(self::ENDPOINT_SUGGEST, $storeId);
    }

    /**
     * Assembles and returns the REST API Endpoint URL for Search Page based on environment and instance name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getSearchApiEndpoint(?int $storeId = null): string
    {
        return $this->buildApiEndpoint(self::ENDPOINT_SEARCH, $storeId);
    }

    /**
     * Assembles and returns the REST API Endpoint URL for Tracking based on environment and instance name
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTrackApiEndpoint(?int $storeId = null): string
    {
        return $this->buildApiEndpoint(self::ENDPOINT_TRACK, $storeId);
    }

    /**
     * Assembles and returns the REST API Endpoint URL for Cross-Sellings of a product
     *
     * @param string $productId
     * @param int|null $storeId
     * @return string
     */
    public function getCrossSellingApiEndpoint(string $productId, ?int $storeId = null): string
    {
        return $this->buildApiEndpoint(self::ENDPOINT_XSELLING . '/' . rawurlencode($productId), $storeId);
    }

    /**
     * Endpoint of the category pages
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCategoryApiEndpoint(?int $storeId = null): string
    {
        return $this->buildApiEndpoint(self::ENDPOINT_CATEGORY, $storeId);
    }

    /**
     * Endpoint of the cross-sellings of several products, e.g. the cart
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCrossSellingProductsApiEndpoint(?int $storeId = null): string
    {
        return $this->buildApiEndpoint(self::ENDPOINT_XSELLING, $storeId);
    }

    /**
     * Endpoint of the recommendations without a product, e.g. the topsellers
     *
     * @param int|null $storeId
     * @return string
     */
    public function getRecommendApiEndpoint(?int $storeId = null): string
    {
        return $this->buildApiEndpoint(self::ENDPOINT_RECOMMEND, $storeId);
    }

    /**
     * Assembles the REST API endpoint URL from the environment, the instance name and the endpoint path
     *
     * Returns an empty string for testing and production if no instance name is configured.
     *
     * @param string $endpoint Path below the API path, e.g. 'suggest' or 'xselling/123'
     * @param int|null $storeId
     * @return string
     */
    private function buildApiEndpoint(string $endpoint, ?int $storeId): string
    {
        $environment = $this->getEnvironment($storeId);
        $instanceName = $this->getInstanceName($storeId);

        $baseUrl = match ($environment) {
            self::ENV_TEST => $instanceName !== '' ? sprintf(self::BASE_URL_TESTING, $instanceName) : '',
            self::ENV_PROD => $instanceName !== '' ? sprintf(self::BASE_URL_PRODUCTION, $instanceName) : '',
            default => self::BASE_URL_DEV,
        };

        return $baseUrl !== '' ? $baseUrl . self::API_PATH . $endpoint : '';
    }

    /**
     * Alias for getSuggestApiEndpoint
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiEndpoint(?int $storeId = null): string
    {
        return $this->getSuggestApiEndpoint($storeId);
    }

    /**
     * Retrieve Integration Token (decrypted)
     *
     * Returns an empty string if the token cannot be decrypted, so the Magento search is used as fallback.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getToken(?int $storeId = null): string
    {
        $rawToken = (string)$this->scopeConfig->getValue(
            self::XML_PATH_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($rawToken === '') {
            return '';
        }

        try {
            $token = trim((string)$this->encryptor->decrypt($rawToken));
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('[NeuronaptiQ_Search] Token could not be decrypted (%s).', $e->getMessage()),
                ['exception' => $e]
            );
            return '';
        }

        if ($token === '') {
            $this->logger->warning('[NeuronaptiQ_Search] Token could not be decrypted (empty result).');
        }

        return $token;
    }

    /**
     * Retrieve timeout in seconds
     *
     * @param int|null $storeId
     * @return int
     */
    public function getTimeout(?int $storeId = null): int
    {
        $timeout = (int)$this->scopeConfig->getValue(
            self::XML_PATH_TIMEOUT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    /**
     * Retrieve maximum number of suggestions (suggest_limit)
     *
     * @param int|null $storeId
     * @return int
     */
    public function getSuggestLimit(?int $storeId = null): int
    {
        $limit = (int)$this->scopeConfig->getValue(
            self::XML_PATH_SUGGEST_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $limit > 0 ? $limit : self::DEFAULT_SUGGEST_LIMIT;
    }
}

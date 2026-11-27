<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Plugin;

use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Search\Api\SearchInterface;
use NeuronaptiQ\Search\Exception\EmptySearchResultException;
use NeuronaptiQ\Search\Model\MagentoSearchAdapter;
use NeuronaptiQ\Search\Model\PageCacheGuard;
use Psr\Log\LoggerInterface;

/**
 * Intercepts Magento Catalog Search to fetch the results of the quick search and the category pages from the
 * NeuronaptiQ REST API (the adapter decides per request and setting)
 *
 * On API errors and empty results the Magento search is used and the page is kept out of the full page cache.
 */
class SearchPlugin
{
    /**
     * @var MagentoSearchAdapter
     */
    private MagentoSearchAdapter $searchAdapter;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var PageCacheGuard
     */
    private PageCacheGuard $pageCacheGuard;

    /**
     * @param MagentoSearchAdapter $searchAdapter
     * @param LoggerInterface $logger
     * @param PageCacheGuard $pageCacheGuard
     */
    public function __construct(
        MagentoSearchAdapter $searchAdapter,
        LoggerInterface $logger,
        PageCacheGuard $pageCacheGuard
    ) {
        $this->searchAdapter = $searchAdapter;
        $this->logger = $logger;
        $this->pageCacheGuard = $pageCacheGuard;
    }

    /**
     * Around plugin on SearchInterface::search to provide NeuronaptiQ search results
     *
     * @param SearchInterface $subject
     * @param callable $proceed
     * @param SearchCriteriaInterface $searchCriteria
     * @return SearchResultInterface
     */
    public function aroundSearch(
        SearchInterface $subject,
        callable $proceed,
        SearchCriteriaInterface $searchCriteria
    ): SearchResultInterface {
        try {
            $searchResult = $this->searchAdapter->search($searchCriteria);
        } catch (EmptySearchResultException $e) {
            // Empty results (e.g. for requests classified as bots) are not shown and must not be cached
            $this->logger->debug('[NeuronaptiQ_Search] ' . $e->getMessage() . ', falling back to the Magento search');
            $this->pageCacheGuard->disableCaching();
            return $proceed($searchCriteria);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[NeuronaptiQ_Search] SearchPlugin error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            // Graceful fallback to default Magento search
            $this->pageCacheGuard->disableCaching();
            return $proceed($searchCriteria);
        }

        return $searchResult ?? $proceed($searchCriteria);
    }
}

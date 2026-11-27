<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Plugin;

use Magento\Checkout\Block\Cart\Crosssell;
use NeuronaptiQ\Search\Model\CrossSellingService;

/**
 * Fills the "Cross-Sell" block of the cart page with the NeuronaptiQ cross-sellings of the products of the cart
 *
 * Falls back to the maintained cross-sell products if switched off or there are no cross-sellings.
 */
class CartCrosssellPlugin
{
    /**
     * @var CrossSellingService
     */
    private CrossSellingService $crossSellingService;

    /**
     * @param CrossSellingService $crossSellingService
     */
    public function __construct(CrossSellingService $crossSellingService)
    {
        $this->crossSellingService = $crossSellingService;
    }

    /**
     * Replaces the cross-sell products with the cross-sellings of the cart, if available
     *
     * @param Crosssell $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetItems(Crosssell $subject, $result)
    {
        $quote = $subject->getQuote();
        $productIds = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $productIds[] = (int)$item->getData('product_id');
        }

        $collection = $this->crossSellingService->getCartProducts($productIds, (int)$quote->getStoreId());

        return $collection !== null ? array_values($collection->getItems()) : $result;
    }
}

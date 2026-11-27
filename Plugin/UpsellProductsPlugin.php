<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Plugin;

use Magento\Catalog\Block\Product\ProductList\Upsell;
use Magento\Catalog\Model\Product;
use NeuronaptiQ\Search\Model\CrossSellingService;

/**
 * Fills the "Upsell" block of the product page with NeuronaptiQ cross-sellings of the kind of the setting
 *
 * Falls back to the maintained upsell products if switched off or there are no cross-sellings. The template and
 * getItems() read the products via getItemCollection().
 */
class UpsellProductsPlugin
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
     * Replaces the upsell products with the cross-sellings, if available
     *
     * @param Upsell $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetItemCollection(Upsell $subject, $result)
    {
        $product = $subject->getProduct();
        if (!$product instanceof Product) {
            return $result;
        }

        return $this->crossSellingService->getUpsellProducts($product) ?? $result;
    }
}

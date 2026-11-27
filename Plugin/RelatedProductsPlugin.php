<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Plugin;

use Magento\Catalog\Block\Product\ProductList\Related;
use Magento\Catalog\Model\Product;
use NeuronaptiQ\Search\Model\CrossSellingService;

/**
 * Fills the "Related Products" block of the product page with NeuronaptiQ cross-sellings
 *
 * Falls back to the maintained related products if there are no cross-sellings. Links of cross-sold variants
 * get a URL hash that preselects the variant on the product page (VariantProductUrlPlugin).
 */
class RelatedProductsPlugin
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
     * Replaces the related products with the cross-sellings, if available
     *
     * @param Related $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetItems(Related $subject, $result)
    {
        $product = $subject->getProduct();
        if (!$product instanceof Product) {
            return $result;
        }

        return $this->crossSellingService->getProducts($product) ?? $result;
    }
}

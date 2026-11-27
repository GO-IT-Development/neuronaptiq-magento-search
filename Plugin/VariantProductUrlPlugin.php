<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Plugin;

use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Model\Product;
use NeuronaptiQ\Search\Model\CrossSellingService;
use NeuronaptiQ\Search\Model\VariantUrlHash;

/**
 * Adds the variant preselection hash to the URLs of cross-sold products in the "Related Products", "Upsell" and
 * "Cross-Sell" blocks
 *
 * Also applies to "Add to Cart" of configurable products, which redirects to the product page.
 */
class VariantProductUrlPlugin
{
    /**
     * @var CrossSellingService
     */
    private CrossSellingService $crossSellingService;

    /**
     * @var VariantUrlHash
     */
    private VariantUrlHash $variantUrlHash;

    /**
     * @param CrossSellingService $crossSellingService
     * @param VariantUrlHash $variantUrlHash
     */
    public function __construct(CrossSellingService $crossSellingService, VariantUrlHash $variantUrlHash)
    {
        $this->crossSellingService = $crossSellingService;
        $this->variantUrlHash = $variantUrlHash;
    }

    /**
     * Adds the variant preselection hash of a cross-sold product
     *
     * @param AbstractProduct $subject
     * @param string $result
     * @param Product $product
     * @param array $additional
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @phpstan-param array<string, mixed> $additional
     */
    public function afterGetProductUrl(AbstractProduct $subject, $result, $product, $additional = [])
    {
        if (!$product instanceof Product || $result === '#') {
            return $result;
        }

        return $this->variantUrlHash->append(
            $result,
            $this->crossSellingService->getVariantHash((int)$product->getId())
        );
    }
}

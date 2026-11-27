<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Relation;
use Magento\Sales\Model\ResourceModel\Report\Bestsellers\CollectionFactory;

/**
 * Bestsellers of Magento's reports statistics, the fallback of the topsellers
 *
 * Magento's bestsellers collection like the "Bestsellers" tab of the admin dashboard: the best selling products of
 * the store (Magento limits them to 5), filled by the refresh of the reports statistics (cron or Reports > Refresh
 * Statistics). The statistics contain the ordered simple products; variants are replaced by their parent product.
 */
class Bestsellers
{
    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var Relation
     */
    private Relation $relation;

    /**
     * @param CollectionFactory $collectionFactory
     * @param Relation $relation
     */
    public function __construct(CollectionFactory $collectionFactory, Relation $relation)
    {
        $this->collectionFactory = $collectionFactory;
        $this->relation = $relation;
    }

    /**
     * Product ids of the bestsellers of the store in their order, parents instead of their variants
     *
     * @param int $storeId
     * @return int[]
     */
    public function getProductIds(int $storeId): array
    {
        $collection = $this->collectionFactory->create()
            ->setModel(Product::class)
            ->addStoreFilter($storeId);

        $productIds = [];
        foreach ($collection as $item) {
            $productId = (int)$item->getData('product_id');
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }
        if ($productIds === []) {
            return [];
        }

        $parents = $this->relation->getRelationsByChildren($productIds);
        $ids = [];
        foreach ($productIds as $productId) {
            $id = isset($parents[$productId][0]) ? (int)$parents[$productId][0] : $productId;
            $ids[$id] = $id;
        }

        return array_values($ids);
    }
}

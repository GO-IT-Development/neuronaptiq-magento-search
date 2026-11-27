<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model\Config\Source;

/**
 * Options of the kind of the cross-sellings with "off" (the maintained products of Magento)
 */
class CrosssellingKindWithOff extends CrosssellingKind
{
    /**
     * Options with "off"
     */
    public function __construct()
    {
        parent::__construct(true);
    }
}

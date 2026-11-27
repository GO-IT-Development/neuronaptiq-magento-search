<?php
declare(strict_types=1);

namespace NeuronaptiQ\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use NeuronaptiQ\Search\Model\Config;

/**
 * Options of the kind of the cross-sellings: similar products, accessories, upsell; optionally "off"
 */
class CrosssellingKind implements OptionSourceInterface
{
    /**
     * @var bool
     */
    private bool $withOff;

    /**
     * @param bool $withOff whether the setting can be switched off (the maintained products of Magento)
     */
    public function __construct(bool $withOff = false)
    {
        $this->withOff = $withOff;
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        if ($this->withOff) {
            $options[] = ['value' => Config::KIND_OFF, 'label' => __('Off (maintained products of Magento)')];
        }
        $options[] = ['value' => Config::KIND_RELATED, 'label' => __('Similar products')];
        $options[] = ['value' => Config::KIND_COMPLEMENTARY, 'label' => __('Accessories')];
        $options[] = ['value' => Config::KIND_UPSELL, 'label' => __('Upsell (similar, more expensive products)')];

        return $options;
    }
}

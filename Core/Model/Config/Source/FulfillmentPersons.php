<?php

namespace Beckn\Core\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Beckn\Core\Model\ResourceModel\PersonDetails\CollectionFactory;

/**
 * Class FulfillmentPersons
 * @package Beckn\Core\Model\Config\Source
 */
class FulfillmentPersons extends AbstractSource
{

    /**
     * @var CollectionFactory
     */
    protected $_collectionFactory;

    public function __construct(
        CollectionFactory $collectionFactory
    )
    {
        $this->_collectionFactory = $collectionFactory;
    }

    public function getAllOptions()
    {
        /**
         * @var \Beckn\Core\Model\ResourceModel\PersonDetails\Collection $collection
         */
        $collection = $this->_collectionFactory->create();
        $options = [];
        $options[] = [
            'value' => "",
            'label' => __("Disable")
        ];
        /**
         * @var \Beckn\Core\Model\PersonDetails $item
         */
        foreach ($collection as $item) {
            $options[] =
                [
                    'value' => $item->getEntityId(),
                    'label' => $item->getName()
                ];
        }
        return $options;
    }
}
<?php

namespace Beckn\Search\Model\Repository\Search;

use Beckn\Core\Helper\Data as Helper;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Magento\Framework\Webapi\Exception;
use Magento\Framework\Search\Search as MagentoCoreSearch;
use Magento\Framework\Api\Search\SearchCriteriaInterfaceFactory as SearchCriteriaInterfaceFactory;
use Beckn\Core\Model\ResourceModel\ItemFulfillmentOptions\CollectionFactory as ItemFulfillmentCollectionFactory;
use Beckn\Core\Model\PersonDetailsFactory;

/**
 * Class SearchRepository
 * @author Indglobal
 * @package Beckn\Search\Model\Repository\Search
 */
class SearchRepository implements \Beckn\Search\Api\SearchRepositoryInterface
{

    /**
     * @var Helper
     */
    protected $_helper;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var CollectionFactory
     */
    protected $_productCollectionFactory;

    /**
     * @var CategoryFactory
     */
    protected $_categoryFactory;
    /**
     * @var MagentoCoreSearch
     */
    protected $_magentoCoreSearch;
    /**
     * @var SearchCriteriaInterfaceFactory
     */
    protected $_searchCriteriaInterfaceFactory;

    /**
     * @var DataObjectHelper
     */
    protected $_dataObjectHelper;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    protected $_categoryCollectionFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var ItemFulfillmentCollectionFactory
     */
    protected $_itemFulfillmentCollectionFactory;

    /**
     * @var PersonDetailsFactory
     */
    protected $_personDetailsFactory;

    /**
     * SearchRepository constructor.
     * @param Helper $helper
     * @param LoggerInterface $logger
     * @param CollectionFactory $productCollectionFactory
     * @param CategoryFactory $categoryFactory
     * @param MagentoCoreSearch $magentoCoreSearch
     * @param SearchCriteriaInterfaceFactory $searchCriteriaInterfaceFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collectionFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param ItemFulfillmentCollectionFactory $itemFulfillmentCollectionFactory
     * @param PersonDetailsFactory $personDetailsFactory
     */
    public function __construct(
        Helper $helper,
        LoggerInterface $logger,
        CollectionFactory $productCollectionFactory,
        CategoryFactory $categoryFactory,
        MagentoCoreSearch $magentoCoreSearch,
        SearchCriteriaInterfaceFactory $searchCriteriaInterfaceFactory,
        DataObjectHelper $dataObjectHelper,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collectionFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        ItemFulfillmentCollectionFactory $itemFulfillmentCollectionFactory,
        PersonDetailsFactory $personDetailsFactory
    )
    {
        $this->_helper = $helper;
        $this->_logger = $logger;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_categoryFactory = $categoryFactory;
        $this->_magentoCoreSearch = $magentoCoreSearch;
        $this->_searchCriteriaInterfaceFactory = $searchCriteriaInterfaceFactory;
        $this->_dataObjectHelper = $dataObjectHelper;
        $this->_dataObjectHelper = $dataObjectHelper;
        $this->_categoryCollectionFactory = $collectionFactory;
        $this->_storeManager = $storeManager;
        $this->_itemFulfillmentCollectionFactory = $itemFulfillmentCollectionFactory;
        $this->_personDetailsFactory = $personDetailsFactory;
    }

    /**
     * @param mixed $context
     * @param mixed $message
     * @return string|void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \SodiumException
     */
    public function getSearch($context, $message)
    {
        $authStatus = $this->_helper->validateAuth($context, $message);
        if (!$authStatus) {
            echo $this->_helper->unauthorizedResponse();
            exit();
        }
        $validateMessage = $this->_helper->validateApiRequest($context, $message);
        if (is_callable('fastcgi_finish_request')) {
            $acknowledge = $this->_helper->getAcknowledge($context);
            if (!empty($validateMessage['message'])) {
                $errorAcknowledge = $this->_helper->acknowledgeError($validateMessage['code'], $validateMessage['message']);
                $acknowledge["message"]["ack"]["status"] = Helper::NACK;
                $acknowledge["error"] = $errorAcknowledge;
            }
            $this->_helper->apiResponseEvent($context, $acknowledge);
            echo json_encode($acknowledge);
            session_write_close();
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        ob_start();

        //Add code here
        if (empty($validateMessage['message'])) {
            $this->processSearch($context, $message);
        }
        echo $serverProtocol = filter_input(INPUT_SERVER, 'SERVER_PROTOCOL', FILTER_SANITIZE_STRING);
        header($serverProtocol . ' 200 OK');
        // Disable compression (in case content length is compressed).
        header('Content-Encoding: none');
        header('Content-Length: ' . ob_get_length());
        // Close the connection.
        header('Connection: close');
        ob_end_flush();
        ob_flush();
        flush();
    }

    /**
     * @param $context
     * @param $message
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function processSearch($context, $message)
    {
        $apiUrl = $this->_helper->getBapUri(Helper::ON_SEARCH, $context);
        $response = [];
        /**
         * @var \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
         */
        $filterStoreId = $this->_helper->getAllStoreIds($message);
        if (!empty($filterStoreId)) {
            $collection = $this->_productCollectionFactory->create();
            $collection->addAttributeToSelect('*');
            $collection->addAttributeToFilter('product_store_bpp', ["in", $filterStoreId]);
            $collection->addAttributeToFilter('type_id', "simple");
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
//            if (isset($message["intent"]["item"]["descriptor"]["name"]) && !empty($message["intent"]["item"]["descriptor"]["name"])) {
//                $searchQuery = $message["intent"]["item"]["descriptor"]["name"];
//                $productIds = $this->getDefaultMagentoSearch($searchQuery);
//                $collection->addAttributeToFilter('entity_id', ["IN", $productIds]);
//            }
            $collection = $this->_helper->addCondition($message, $collection);
        }
        $allItems = [];
        $priceData = [];
        $availableStore = [];
        $allCategory = [];
        $productList = [];
        $fulfillmentList = [];
        if (!empty($collection)) {
            foreach ($collection as $_collection) {
                $prepareProduct = $this->prepareProduct($_collection, $context, $message);
                $allItems[] = $prepareProduct["item"];
                $productList = array_merge($productList, $prepareProduct["product_list"]);
                $priceData[$prepareProduct["id"]] = $prepareProduct["price"];
                $availableStore[] = $prepareProduct["store_id"];
                $allCategory = array_merge($allCategory, $prepareProduct["all_category"]);
                $fulfillmentList = array_merge($fulfillmentList, $prepareProduct["fulfillment_list"]);
            }
        }
//        if (isset($message["intent"]["item"]["price"]["minimum_value"])) {
//            $minValue = $message["intent"]["item"]["price"]["minimum_value"];
//            $maxValue = $message["intent"]["item"]["price"]["maximum_value"];
//            $allItems = $this->_helper->addPriceFilter($allItems, $priceData, $minValue, $maxValue);
//        }
        if (!empty($productList)) {
            $provider = $this->_helper->getProvidersDetails($productList, array_unique($availableStore));
            $provider["categories"] = $this->getCategoryData($allCategory);
            $provider["fulfillments"] = $this->filterFulfillments($fulfillmentList);
            $response["context"] = $this->_helper->getContext($context);
            $response["message"]["catalog"]["bpp/descriptor"] = $this->_helper->getDescriptorDetails();
            $response["message"]["catalog"]["bpp/providers"][0] = $provider;
            $this->_helper->sendResponse($apiUrl, $response);
        } else {
            $this->_logger->info("No match found hence not firing on_search.");
        }
    }

    /**
     * @param $searchQuery
     * @return array
     */
    protected function getDefaultMagentoSearch($searchQuery)
    {
        $searchRequest = [
            "requestName" => "quick_search_container",
            "filter_groups" => [
                [
                    "filters" => [
                        [
                            "field" => "search_term",
                            "value" => $searchQuery
                        ]
                    ]
                ]
            ]
        ];
        $object = $this->_searchCriteriaInterfaceFactory->create();
        $interface = \Magento\Framework\Api\Search\SearchCriteriaInterface::class;
        $this->_dataObjectHelper->populateWithArray(
            $object, $searchRequest, $interface
        );
        $searchCollection = $this->_magentoCoreSearch->search($object);
        $productIds = [];
        if ($searchCollection->getTotalCount()) {
            foreach ($searchCollection->getItems() as $_search) {
                $productIds[] = $_search->getId();
            }
        }
        return $productIds;
    }

    /**
     * @param Product $product
     * @param array $context
     * @param array $message
     * @return array
     * @throws NoSuchEntityException
     */
    private function prepareProduct(Product $product, array $context, array $message)
    {
        $productData = [
            "id" => $product->getSku(),
            "descriptor" => [
                "name" => $product->getName(),
                "images" => $this->_helper->getProductMediaGallery($product->getSku()),
            ],
            "price" => [
                "currency" => $this->_helper->getCurrentCurrencyCode(),
                "value" => $product->getFinalPrice()
            ],
            "matched" => true,
        ];
        $categoryIds = $product->getCategoryIds();
        if(!empty($categoryIds)){
            $productData["category_id"] = end($categoryIds);
        }
        if ($product->getShortDescription() != "") {
            $productData["descriptor"]["short_desc"] = $product->getShortDescription();
        }
        if ($product->getDescription() != "") {
            $productData["descriptor"]["long_desc"] = $product->getDescription();
        }
        if ($product->getItemCodeBpp() != "") {
            $productData["descriptor"]["code"] = $product->getItemCodeBpp();
        }
        if ($product->getData("time_range_start_date_bpp") != "") {
            $productData["time"] = [
                "range" => [
                    "start" => $this->_helper->formatDate($product->getData("time_range_start_date_bpp"), false),
                    "end" => $this->_helper->formatDate($product->getData("time_range_end_date_bpp"), false),
                ]
            ];
        }
        if ($product->getPricePolicyBpp() != "") {
            $price = $this->_helper->getPriceFromPolicy($product->getPricePolicyBpp());
            if ($price != "") {
                $productData["price"]["value"] = $this->_helper->formatPrice($price);
            }
        }
        $productStoreId = $product->getProductStoreBpp();
        $productData["location_id"] = $productStoreId;
        if ($productStoreId != "") {
            $locationId = $this->_helper->getProductLocationId($productStoreId);
            if ($locationId != "") {
                $productData["location_id"] = $locationId;
            }
        }

        $productList = [];
        $allFulfillmentList = [];
        $fulfillmentIds = $product->getData("item_fulfillment_optionsbpp");
        $allFulfillmentIds = array_filter(explode(",", $fulfillmentIds), 'strlen');
        if(!empty($allFulfillmentIds)){
            $allFulfillmentList = $this->getSearchFulfillment($allFulfillmentIds);
            foreach ($allFulfillmentList as $item){
                $fulfillmentId = $item["id"];
                $productData["fulfillment_id"] = $fulfillmentId;
                $productList[] = $productData;
            }
        }

        return [
            "item" => $productData,
            "product_list" => $productList,
            "id" => $productData["id"],
            "price" => $productData["price"]["value"],
            "store_id" => $productStoreId,
            "all_category" => $product->getCategoryIds(),
            "fulfillment_list" => $allFulfillmentList,
        ];
    }

    /**
     * @param $allCategory
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCategoryData($allCategory){
        $allCategoryData = [];
        if(!empty($allCategory)){
            /**
             * @var \Magento\Catalog\Model\ResourceModel\Category\Collection $categoryCollection
             */
            $categoryCollection = $this->_categoryCollectionFactory->create();
            $categoryCollection->addAttributeToSelect('*')
                ->addFieldToFilter("entity_id", ["IN", $allCategory]);
            /**
             * @var \Magento\Catalog\Model\Category $_category
             */
            foreach ($categoryCollection as $_category){
                $eachCategory = [];
                $eachCategory["id"] = $_category->getId();
                $eachCategory["descriptor"] = [
                    "name" => $_category->getName(),
                    "short_desc" => (string)$_category->getData("description"),
                    "images" => [$this->getCategoryImage($_category->getData("image"))]
                ];
                if($_category->getParentId()!=2){
                    $eachCategory["parent_category_id"] = $_category->getParentId();
                }
                $allCategoryData[] = $eachCategory;
            }
        }
        return $allCategoryData;
    }

    /**
     * @param $imagePath
     * @return string
     * @throws NoSuchEntityException
     */
    public function getCategoryImage($imagePath){
        if($imagePath!=""){
            $baseUrl = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
            return $baseUrl.$imagePath;
        }
        return "";
    }

    public function getSearchFulfillment($allFulfillmentIds){
        $allFulfillmentList = [];
        /**
         * @var \Beckn\Core\Model\ResourceModel\ItemFulfillmentOptions\Collection $itemFulfillmentCollection
         */
        $itemFulfillmentCollection = $this->_itemFulfillmentCollectionFactory->create();
        $itemFulfillmentCollection->addFieldToFilter("entity_id", ["IN", $allFulfillmentIds]);
        /**
         * @var \Beckn\Core\Model\ItemFulfillmentOptions $itemFulfillment
         */
        foreach ($itemFulfillmentCollection as $itemFulfillment){
            $eachItem = [];
            $itemFulfillmentTimes = $itemFulfillment->getItemFulfillmentTimes();
            /**
             * @var \Beckn\Core\Model\ItemFulfillmentTimes $fulfillmentTime
             */
            foreach ($itemFulfillmentTimes as $fulfillmentTime){
                $eachItem["id"] = $fulfillmentTime->getEntityId();
                $eachItem["type"] = $itemFulfillment->getFulfillmentType();
                $fulfillmentPerson = $itemFulfillment->getFulfillmentPerson();
                /**
                 * @var \Beckn\Core\Model\PersonDetails $personDetail
                 */
                $personDetail = $this->_personDetailsFactory->create()->load($fulfillmentPerson);
                $person = [];
                if(!empty($personDetail->getData())){
                    if(!$this->_helper->addAgentCondition($personDetail->getName())){
                        continue;
                    }
                    $person = [
                        "id" => $personDetail->getId(),
                        "name" => $personDetail->getName(),
                        "gender" => $personDetail->getGender(),
                        "image" => $personDetail->getPersonImageUrl(),
                        "cred" => $personDetail->getCred(),
                    ];
                }
                if(!empty($person)){
                    $eachItem["agent"] = $person;
                }
                $eachItem["start"]["time"]["timestamp"] = $this->_helper->formatDate($fulfillmentTime->getStartTime());
                if($itemFulfillment->getFulfillmentLocation()){
                    $eachItem["start"]["location"] = [
                        "gps" => $itemFulfillment->getGps(),
                        "address" => [
                            "name" => $itemFulfillment->getName(),
                            "building" => $itemFulfillment->getBuilding(),
                            "street" => $itemFulfillment->getStreet(),
                            "locality" => $itemFulfillment->getLocality(),
                            "ward" => $itemFulfillment->getWard(),
                            "city" => $itemFulfillment->getCity(),
                            "state" => $itemFulfillment->getStreet(),
                            "country" => $this->_helper->getCountryId($itemFulfillment->getCountry()),
                            "area_code" => $itemFulfillment->getAreaCode(),
                        ]
                    ];
                }
                $eachItem["end"]["time"]["timestamp"] = $this->_helper->formatDate($fulfillmentTime->getEndTime());
                $allFulfillmentList[] = $eachItem;
            }
        }
        return $allFulfillmentList;
    }

    /**
     * @param $fulfillmentList
     * @return array
     */
    public function filterFulfillments($fulfillmentList){
        $filterLst = [];
        $matchingId = [];
        foreach ($fulfillmentList as $list){
            if(!in_array($list["id"], $matchingId)){
                $filterLst[] = $list;
                $matchingId[] = $list["id"];
            }
        }
        return $filterLst;
    }
}
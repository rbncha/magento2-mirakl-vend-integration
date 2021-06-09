<?php

namespace Rbncha\SyncPos\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Framework\HTTP\ZendClientFactory;
use \GuzzleHttp\Client;
use \Mirakl\MMP\Common\Domain\Order\OrderState as MiraklOrderState;
use \Mirakl\Connector\Helper\Order as MiraklHelperOrder;
use \Magento\Framework\Mail\Template\TransportBuilder;
use \Magento\Framework\Translate\Inline\StateInterface;

class Vend extends AbstractHelper
{

    protected $_storeManager;
    protected $_request;
    protected $_customer;
    protected $_curl;
    protected $_oauthKey;
    protected $_resource;
    protected $_orderCollectionFactory;
    protected $_helper;
    protected $_miraklApi;
    protected $_miraklHelperOrder;
    protected $_product;
    protected $_httpClientFactory;
    protected $_transportBuilder;
    protected $_inlineTranslation;
    protected $_messageManager;
    protected $_redirect;
    protected $_orderItemRepository;
    protected $_orderRepository;
    protected $_productRepository;
    protected $_customerRepositoryInterface;
    protected $_outlets;
    protected $_consignments;

    const vendSyncedEmailTemplateId = 'rsync_vend_email_template';


    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        StoreManagerInterface $storeManager,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Customer\Model\CustomerFactory $customer,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Rbncha\SyncPos\Helper\Data $helper,
        \MiraklSeller\Api\Helper\Order $miraklApi,
        MiraklHelperOrder $miraklHelperOrder,
        TransportBuilder $transportBuilder,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\App\Response\RedirectInterface $redirect,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        \Magento\Sales\Model\Order\ItemRepository $orderItemRepository,
        \Magento\Catalog\Model\ProductRepository $productRepository,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        StateInterface $state
    ) {

        parent::__construct($context);

        $this->_storeManager = $storeManager;
        $this->_request = $request;
        $this->_customer = $customer;
        $this->_curl = $curl;
        $this->_resource = $resource;
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->_helper = $helper;
        $this->_miraklApi = $miraklApi;
        $this->_miraklHelperOrder = $miraklHelperOrder;
        $this->_httpClientFactory = $httpClientFactory;
        $this->_inlineTranslation = $state;
        $this->_transportBuilder = $transportBuilder;
        $this->_messageManager = $messageManager;
        $this->_redirect = $redirect;
        $this->_orderRepository = $orderRepository;
        $this->_productRepository = $productRepository;
        $this->_orderItemRepository = $orderItemRepository;
        $this->_customerRepositoryInterface = $customerRepositoryInterface;

    }

    public function getRequest()
    {
        return $this->_request;
    }

    public function getProductImageUrl($productImage)
    {
        return $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' . $productImage;
    }

    /**
     * Returns Oauth keys for given | stored customer
     *
     * @param integer $customerId
     * @return object
     */
    public function getCustomerOauthKey(int $customerId)
    {
        if (!isset($this->_oauthKey[$customerId])) {
           // $customer = $this->_customerRepositoryInterface->getById($customerId);
            $customer = $this->_customer->create()->load($customerId);
            //$storeId = $this->_storeManager->getStore()->getId();
            $storeId = $customer->getStoreId();

            $this->_oauthKey[$customerId] = json_decode($customer->getOauthKey());


            $clientId = $this->scopeConfig->getValue(
	            'rsyncpos/vend/client_id',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

	        $clientSecret = $this->scopeConfig->getValue(
	            'rsyncpos/vend/client_secret',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

            if(!isset($this->_oauthKey[$customerId]->access_token)){
            	//throw new \Exception(__('Looks like you have not integrated with Vend yet.'));
            	return false;
            }

            $this->_oauthKey[$customerId]->client_id = $clientId;
            $this->_oauthKey[$customerId]->client_secret = $clientSecret;
        }

        return $this->_oauthKey[$customerId];
    }

    public function getIsVendEnabled($storeId = null)
    {
    	if(is_null($storeId)) $storeId = $this->_storeManager->getStore()->getId();

    	$enabled = $this->scopeConfig->getValue(
            'rsyncpos/vend/enable',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $enabled;
    }

    /**
     * Validate whether Vend is integrated or not
     *
     * @return boolean
     */
    public function isVendIntegrated($customerId)
    {
        $return = false;
        $customer = $this->_customer->create()->load($customerId);
        $oauthKey = json_decode($customer->getOauthKey());
        //$storeId = $this->_storeManager->getStore()->getId();
        $storeId = $customer->getStoreId();
        $enabled = $this->getIsVendEnabled($storeId);

        if ($enabled && isset($oauthKey->access_token) && !empty($oauthKey->access_token)) {
            $return = true;
        }

        return $return;
    }

    /**
     * Refreshes Vend Oauth access token for given customer id
     *
     * @param int $customerId
     * @return bool
     */
    public function refreshToken($customerId)
    {
        try {

        	/**
        	 * Prevent oauth key refreshing everytime
        	 * instead use stored one for the instance
        	 */
        	if(isset($this->_oauthKey[$customerId])){
        		return $this->_oauthKey[$customerId];
        	}

            if (!$this->isVendIntegrated($customerId)) {
                throw new \Exception("Customer ID $customerId is not yet connected to vend");
            }

            $params = $this->getRequest()->getParams();
            $connection = $this->_resource->getConnection();
            $oauthKey = $this->getCustomerOauthKey($customerId);

            /** fetch stored Vend Oauth client_id and secret */
            $clientId = $oauthKey->client_id;
            $clientSecret = $oauthKey->client_secret;

            $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/1.0/token';

            $data = [
                'refresh_token' => $oauthKey->refresh_token,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token'
            ];

            /**
             * Define form post type for curl post request
             */
            $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $this->_curl->post($url, $data);

            /** decode the response into stdObject */
            $response = json_decode($this->_curl->getBody());

            /** If no new refresh token code is generated, use the old one */
            if (!isset($response->refresh_token)) {
                $response->refresh_token = $oauthKey->refresh_token;
            }

            if (!isset($response->domain_prefix)) {
                $response->domain_prefix = $oauthKey->domain_prefix;
            }

            if(isset($response->error)){
            	throw new \Exception(print_r($response,true));
            }

            /** Update the customer entity table with new oauth credentials for future use */
            $sql = "update " . $this->_resource->getTableName('customer_entity') . " set oauth_key = '".json_encode($response)."' where entity_id = $customerId";
            $connection->query($sql);

        } catch (\Exception $e) {
        	$this->_helper->debug($e->getMessage(), 'vend-sync-log.log');

            return false;
        }

        return true;
    }

    /**
     * Fetch outlet data for specific customer
     *
     * @param int $customerId
     * @return Object
     */
    public function getOutlet($customerId)
    {
    	if(!isset($this->_outlets[$customerId])){
	    	$oauthKey = $this->getCustomerOauthKey($customerId);
	        $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/outlets';

	        $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
	        $this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);
	        $this->_curl->get($url);

	        $response = json_decode($this->_curl->getBody());
	        $outlet = isset($response->outlets[0])? $response->outlets[0]:[];

	        $this->_outlets[$customerId] = $outlet;
    	}

    	return $this->_outlets[$customerId];
    }

    /**
     * Sync number of orders to Vend
     * Used for cron
     *
     * @return void
     */
    public function syncOrderItems()
    {
    	$connection = $this->_resource->getConnection();
    	$now = new \DateTime();
        $date = $now->sub(new \DateInterval('P14D'));

    	$sql = "select distinct(order_id) from " . $this->_resource->getTableName('sales_order_item') . " where vend_consignment_item_id = '' OR vend_consignment_item_id IS NULL and created_at >= ? order by created_at DESC";
        $selectedOrders = $connection->fetchCol($sql, [$date->format('Y-m-d H:i:s')]);

        $collection = $this->_orderCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addFieldToFilter('main_table.entity_id', ['in' => $selectedOrders])
            ->addFieldToFilter('main_table.customer_id', [['neq' => 'NULL']])
            //->addFieldToFilter('main_table.vend_synced', ['neq' => '1'])
            ->addFieldToFilter('main_table.mirakl_sent', 1)
            ->addAttributeToFilter('main_table.customer_is_guest', ['neq'=>1])
            ->addFieldToFilter('main_table.created_at', ['gteq' => $date->format('Y-m-d H:i:s')])
            ;

        $orderId = $this->_request->getParam('oid');

        if(!empty($orderId)){
        	$collection->addFieldToFilter('entity_id', $orderId);
        }

        $collection->getSelect()->join(
        	['customer' => $collection->getTable('customer_entity')],
        	'customer.entity_id = main_table.customer_id',[]
        );

        $collection->addFieldToFilter('customer.oauth_key', ['neq' => ''])
        ->setPageSize(25)
        ->setCurPage(1)
        ->setOrder('main_table.created_at', 'desc');

         /**
          * Fetch Mirakl details for the collection
          */
        $this->_miraklHelperOrder->addMiraklOrdersToCollection($collection);

        foreach ($collection as $order) {

            $totalItems = 0;
        	$vendSyncedItems = 0;
        	$vendSyncedItemsNow = 0;
            $orderId = $order->getId();
            $realOrderId = $order->getRealOrderId();
            $customerId = $order->getCustomerId();
            $_productList = [];

            /**
             * Check if any item of order is shipped and ready to be synced to Vend
             */
            foreach ($order->getMiraklOrders() as $miraklOrder) {
            	foreach ($miraklOrder->getOrderLines() as $miraklOrderLine) {
            		$totalItems++;

                    $result = $this->syncOrderItem($miraklOrderLine);

                    /**
                     * Confirm this item is just or previously synced to Vend
                     * sometimes $result may return error object or Mirakl did not ship
                     * the item yet, in this case $vendSyncedItemsNow will not match with
                     * $totalItems. It means all the items of order is not shipped from Mirakl.
                     */
                    if($result !== false || $result == 'synced-already'){
                    	$vendSyncedItems++;
                    }

                    if($result !== false && $result != 'synced-already'){
                    	$vendSyncedItemsNow++;
                    }

                    if($result !== false){
                    	$consignmentId = isset($result['vend_consignment_id']) ? $result['vend_consignment_id'] : count($_productList);
                    	$_productList[$consignmentId][] = $result;
                    }
	            }
            }

	        foreach($_productList as $consignmentId => $products){
	        	$consignmentOkToDispatch = true;
	        	foreach($products as $product){
        			if(isset($product['vend_consignment_product_response']->errors)){
        				$consignmentOkToDispatch = false;
        				//echo 'ERROR:: Product' . $product['item_id'] . '<pre>'.print_r($product['vend_consignment_product_response']->errors,true) . '</pre>';
        			}

	        	}

        		if($consignmentOkToDispatch) $this->dispatchConsignment($customerId, $consignmentId);
        		else $vendSyncedItems = 0; //there was something wrong, not dispached, check the error log

        	}

        	/**
	         *
	         * Let's indicate the order has been synced to vend so that it will be ignored in next sync
	         * This is specially useful for automated cron type of sync
	         *
	         * NOTE: indicates as vend synced only if all individual items have been shipped
	         */

	        if($totalItems > 0 && $totalItems == $vendSyncedItems){
		        $sql = 'update ' . $this->_resource->getTableName('sales_order') . " set vend_synced = ? where entity_id = ?";
		        $connection->query($sql, [1, $orderId]);
	        }

			/**
			 * Let's notify customers that the consignment has been shipped
			 */

			if($vendSyncedItemsNow > 0){
		        $this->notifyOrderSynced($order);
		    }
        }
    }

    public function syncOrderItem(\Mirakl\MMP\FrontOperator\Domain\Order\OrderLine $miraklOrderLine)
    {
    	$item = $this->_orderItemRepository->get($miraklOrderLine->getId());
    	$order = $this->_orderRepository->get($item->getOrderId());
        $vendConsignmentProductId = $item->getVendConsignmentItemId();

    	if($this->_request->getParam('debug') == 1){
    		//echo 'OrderId: ' . $order->getRealOrderId() . ' - OrderLineId: ' . $miraklOrderLine->getId() . ' <status>:' . $miraklOrderLine->getStatus()->getState() . "\n<br>";
        	//echo '---- This order is shipped<br>';
        	$this->_helper->debug('OrderId: ' . $order->getRealOrderId() . ' - OrderLineId: ' . $miraklOrderLine->getId() . ' <status>:' . $miraklOrderLine->getStatus()->getState(), 'vend-sync-log.log');

    	}

    	/**
    	 * If an item is not shipped, no more processing for that one
    	 *
    	 * BUG: even if Mirakl item is partially shipped, some item still left to be shipped
    	 *      sometimes it returns Mirakl item object as not shipped
    	 *      $miraklOrderLine->getStatus()->getState() == SHIPPING
    	 */

        if ($miraklOrderLine->getStatus()->getState() != 'SHIPPED') return false;

    	/**
    	 * Check if item has been synced to vend already
    	 * Do not sync again if it's been synced already
    	 */
    	if(!empty($vendConsignmentProductId)){
    		return 'synced-already';
    	}

    	$customer = $this->_customerRepositoryInterface->getById($order->getCustomerId());
    	$orderId = $order->getId();
    	$customerId = $customer->getId();
    	$storeId = $this->_storeManager->getStore()->getId();
        $product = $this->_productRepository->getById($item->getProductId());
        $miraklOrderLinePrice = $miraklOrderLine->getOffer()->getPrice() * 10 / 11;
        $gstExempt = $product->getResource()->getAttribute('gst_exempt')->getFrontend()->getValue($product);
        $brand = $product->getResource()->getAttribute('brand')->getFrontend()->getValue($product);

        if (empty($miraklOrderLinePrice)) {
            $miraklOrderLinePrice = $item->getPrice();
        }

        if ($gstExempt == 'Yes') {
            $miraklOrderLinePrice = $miraklOrderLine->getOffer()->getPrice();
        }

    	/**
         * Flush the access code before proceeding to sync process
         * Often the access code will be expired
         * In such cases we need to regenerate access code
         * using refresh_token key
         */
        $this->refreshToken($customerId);

        if (!$this->isVendIntegrated($customerId)) {
            return false;
        }

        $oauthKey = $this->getCustomerOauthKey($customerId);
        $connection = $this->_resource->getConnection();
        $outlet = $this->getOutlet($customerId);
        $supplierId = $this->getSupplierId($customerId, $item->getMiraklShopName());
        $consignment = $this->createConsignment($order, $outlet->id, $supplierId, $outlet->name .' - ' . $order->getIncrementId());
        $realOrderId = $order->getIncrementId();

        if(!isset($consignment->data->id)){
        	$this->_helper->debug("Order no. $realOrderId. Consignment could not be created.", 'vend-sync-log.log');
        	return false;
        }

        $consignmentId = $consignment->data->id;

        /** fetch stored Vend Oauth client_id and secret */
        $clientId = $oauthKey->client_id;
        $clientSecret = $oauthKey->client_secret;

        /**
         * Get previous stock of the item
         */

        $currentQty = 0;

        /**
         * Genarate product push data array
         */
        $productData = [
        	'order_id' => $orderId,
            'order_increment_id' => $order->getIncrementId(),
            'order_item_id' => $item->getId(),
            'vend_consignment_id' => $consignmentId,
            'customer_id' => $customerId,
            'name' => $item->getName(),
            'handle' => $item->getProduct()->getProductType() != 'simple' ? $item->getProduct()->getSku() : $item->getSku(),
            'sku' => $item->getSku(),
            'active' => true,
            'has_inventory' => true,
            'is_composite' => false,
            'description' => $item->getProduct()->getDescription(),
            'source' => 'USER',
            'brand_name' => $brand,
            'supplier_name' => $item->getMiraklShopName(),
            'supplier_code' => $item->getMiraklShopId(),
            'supply_price' => (float) number_format($miraklOrderLinePrice, 2, '.', ''), //$item->getBaseCost(),
            'has_variants' => true,
            'variant_options' => [],
            'is_active' => true,
            'track_inventory' => true,
            'qty' => $item->getSimpleQtyToShip() + $currentQty,
            'cost' => (float) number_format($miraklOrderLinePrice, 2, '.', ''),
            'retail_price' => 0, //(float) number_format($miraklOrderLinePrice, 2, '.', ''),
        ];


        $options = $item->getProductOptions();

        if ($item->getHasChildren()) {

            /**
             * Let's grab dynamically assigned product variants
             */

             $variations = [];

            if (isset($options['attributes_info']) && is_array($options['attributes_info'])) {
                foreach ($options['attributes_info'] as $info) {
                    $variations[] = ['name' => $info['label'], 'value' => $info['value']];
                }
            }

            /**
             * Defining variant products manually as Vend specification
             */
            if (isset($variations[0])) {
                $productData['variant_option_one_name'] = $variations[0]['name'];
                $productData['variant_option_one_value'] = $variations[0]['value'];
            }

            if (isset($variations[1])) {
                $productData['variant_option_two_name'] = $variations[1]['name'];
                $productData['variant_option_two_value'] = $variations[1]['value'];
            }

            if (isset($variations[2])) {
                $productData['variant_option_three_name'] = $variations[2]['name'];
                $productData['variant_option_three_value'] = $variations[2]['value'];
            }
        }

        /**
         * Extra product attributes
         */
        if (isset($options['additional_options']) && is_array($options['additional_options'])) {
            foreach ($options['additional_options'] as $addInfo) {
                $productData[$addInfo['label']] = $addInfo['value'];
            }
        }

        /**
         * Define form post type for curl post request
         */
        $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/products';
        $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);
        $this->_curl->post($url, json_encode($productData));

        /** decode the response into stdObject */
        $response = json_decode($this->_curl->getBody());
        $productData['vend_product_add_response'] = $response;

        /**
         * Now let's upload product images
         */

        if (isset($response->product->id)) {
            $vendProductId = $response->product->id;

            $images = [];
            $images[] = $this->_helper->getBaseBaseUrl('media') . 'catalog/product' . $item->getProduct()->getImage();
            //$images[] = $helper->getBaseBaseUrl('media') . 'catalog/product' . $item->getProduct()->getThumbnail();
            //$images[] = $helper->getBaseBaseUrl('media') . 'catalog/product' . $item->getProduct()->getSmallImage();

            $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/2.0/products/' . $vendProductId . '/actions/image_upload';

            foreach ($images as $image) {
                $imageHeaders = @get_headers($image);

                if (!stripos(@implode("\n", $imageHeaders), "200 OK")) {
                    continue;
                }

                $client = new \GuzzleHttp\Client();
                $response = $client->request('POST', $url, [
                    'multipart' => [
                        [
                            'name'     => 'image',
                            'contents' => fopen($image, 'r'),
                        ]
                    ],
                    'headers'  =>
                    [
                        'Authorization' => 'Bearer ' . $oauthKey->access_token
                    ]
                ]);
            }
        }

        $result = $this->addProductToConsignment($customerId, $consignmentId, $productData);

		if(isset($result['vend_consignment_product_response'])) $productData['vend_consignment_product_response'] = $result['vend_consignment_product_response'];

        return $productData;
    }

    /**
     * Adds product to consignment
     *
     * @param string $consignmentId
     * @param array $products
     * @return void
     */
    public function addProductToConsignment($customerId, string $consignmentId, array $product)
    {
    	try{
	        $connection = $this->_resource->getConnection();
	        $oauthKey = $this->getCustomerOauthKey($customerId);
	        $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/2.0/consignments/'. $consignmentId . '/products';

	        if (isset($product['vend_product_add_response']) && isset($product['vend_product_add_response']->product)) {
	        	$this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
	        	$this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);
	            $this->_curl->post($url, [
	            'product_id' => $product['vend_product_add_response']->product->id,
	            'product_sku' => $product['sku'],
	            //'received' => $product['qty'],
	            'count' => $product['qty'],
	            'status' => 'SUCCESS',
	            'cost' => $product['supply_price'],
	            ]);

	            $response = json_decode($this->_curl->getBody());

	            if(isset($response->errors)) throw new \Exception('Consignment dispatch error: ' . $consignmentId . '. Vend Error: ' . print_r($response,true));

	            $consignmentProductId = isset($response->data->product_id) ? $response->data->product_id : false;

	            /**
	             *
	             * Let's indicate the order item has been synced to vend
	             */

	            if($consignmentProductId !== false){
		            $sql = 'update ' . $this->_resource->getTableName('sales_order_item') . " set vend_consignment_id = ?, vend_consignment_item_id = ? where item_id = ?";
		            $connection->query($sql, [$consignmentId, $consignmentProductId, $product['order_item_id']]);
	            }else{
	            	throw new \Exception(print_r($response,true));
	            }

	            $product['vend_consignment_product_response'] = $response;
	        }
    	}
    	catch(\Exception $e){
    		$this->_helper->debug($e->getMessage(), 'vend-sync-log.log');
    		$product['vend_consignment_product_response'] = $e->getMessage();
    	}

    	return $product;
    }

    /**
     * Dispatch consignment
     *
     * @param string $consignmentId
     * @return void
     */
    public function dispatchConsignment($customerId, string $consignmentId)
    {
    	try{
	        $connection = $this->_resource->getConnection();
	        $oauthKey = $this->getCustomerOauthKey($customerId);
        	$url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/2.0/consignments/'.$consignmentId;

	        $client = $this->_httpClientFactory->create();
            $client->setUri($url);
            $client->setMethod(\Zend_Http_Client::PUT);
            $client->setHeaders(\Zend_Http_Client::CONTENT_TYPE, 'application/x-www-form-urlencoded');
            $client->setHeaders('Authorization', 'Bearer ' . $oauthKey->access_token);
            $client->setParameterPost([
                'status' => 'DISPATCHED'
            ]);

            $response = json_decode($client->request()->getBody());

       		if(isset($response->errors)) throw new \Exception('Consignment dispatch error: ' . $consignmentId . '. Vend Error: ' . print_r($response,true));

            return true;
    	}
    	catch(\Exception $e){
    		$this->_helper->debug($e->getMessage(), 'vend-sync-log.log');

    		return $e->getMessage();
    	}
    }

    /**
     * Create Consignment
     *
     * @param string $outletId
     * @param string $consignmentName
     * @param string $consignmentType
     * @param string $status
     * @return object
     */
    public function createConsignment($order, $outletId, $supplierId, $consignmentName = null, $consignmentType = 'SUPPLIER', $status = 'SENT')
    {
    	$consignmentId = $order->getId() . '__' . $supplierId;

    	if(!isset($this->_consignments[$consignmentId])){
	        $oauthKey = $this->getCustomerOauthKey($order->getCustomerId());
	        $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/2.0/consignments';
	        $customerId = $order->getCustomerId();

	        $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
	        $this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);
	        $this->_curl->post($url, [
	            'outlet_id' => $outletId,
	            'name' => $consignmentName,
	            'supplier_id' => $supplierId,
	            'type' => $consignmentType,
	            'status' => $status
	            ]);

	        $consignment = json_decode($this->_curl->getBody());

	        $this->_consignments[$consignmentId] = $consignment;
    	}

        return $this->_consignments[$consignmentId];
    }
    
    public function addSupplier($customerId, $supplierName)
    {
        $oauthKey = $this->getCustomerOauthKey($customerId);
        $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/supplier';
        $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);
        $this->_curl->post($url, json_encode(['name' => $supplierName]));
        
        $response = json_decode($this->_curl->getBody());
        
        return $response;
    }

    public function getSupplierId($customerId, $name)
    {
    	$suppliers = $this->getSuppliers($customerId);

    	if(isset($suppliers->data)){
	    	foreach($suppliers->data as $supplier){
	    		if($name == $supplier->name){
	    			return $supplier->id;
	    		}
	    	}
    	}
    	
    	$result = $this->addSupplier($customerId, $name);
    	
    	if(isset($result->id)) return $result->id;

    	return false;
    }
    
    public function getSuppliers($customerId)
    {
    	$oauthKey = $this->getCustomerOauthKey($customerId);
        $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/2.0/suppliers';
        $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);
        $this->_curl->get($url);

        /** decode the response into stdObject */
        $response = json_decode($this->_curl->getBody());

        return $response;
    }

    /**
     * @param   Connection  $connection
     * @param   string      $miraklOrderId
     * @return  ShopOrder
     * @throws  \Exception
     */
    protected function getMiraklOrder(\MiraklSeller\Api\Model\Connection $connection, $miraklOrderId)
    {
        $miraklOrder = $this->_miraklApi->getOrderById($connection, $miraklOrderId);

        if (!$miraklOrder) {
            return false;
        }

        return $miraklOrder;
    }

    public function returnOrders()
    {
        $connection = $this->_resource->getConnection();

        $sql = "select distinct(order_id) from " . $this->_resource->getTableName('sales_order_item') . " where vend_consignment_item_id != '' and vend_refunded=0 order by created_at DESC limit 25";
        $selectedOrders = $connection->fetchCol($sql);

        $collection = $this->_orderCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addFieldToFilter('entity_id', ['in' => $selectedOrders])
            ->addFieldToFilter('customer_id', [['neq' => 'NULL']])
            //->addFieldToFilter('vend_synced', ['eq' => '1'])
            ->addFieldToFilter('mirakl_sent', 1)
            ->setPageSize(25)
            ->setCurPage(1)
            ->setOrder('created_at', 'desc')
            ->addAttributeToFilter('customer_is_guest', ['neq'=>1])
            ;

        $this->_miraklHelperOrder->addMiraklOrdersToCollection($collection);

        foreach ($collection as $order) {
            $orderId = $order->getId();
            $realOrderId = $order->getRealOrderId();
            $customerId = $order->getCustomerId();
            $oauthKey = $this->getCustomerOauthKey($customerId);

            if(!isset($oauthKey->access_token)) continue;

            $this->refreshToken($customerId);

            /**
             * Fetch outlets data
             */

            $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/outlets';
            $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);
            $this->_curl->get($url);
            $response = json_decode($this->_curl->getBody());

            $outlet = isset($response->outlets[0])? $response->outlets[0]:[];

            $items = [];
            $vendItems = [];

            $consignmentId = null; //reset for every new order/consignment

            foreach ($order->getAllVisibleItems() as $item) {
                /**
                 * Skip running into child products
                 * We just count either single simple product or configurable/bundled ones
                 */
                if ($item->getParentItem()) {
                    continue;
                }

                $vendConsignmentId = $item->getVendConsignmentId();
                $vendConsignmentProductId = $item->getVendConsignmentItemId();

                /**
                 * exception conditions:
                 * 1. Do not repeat the refund
                 * 2. If no vend consignment product id
                 * 3. If no refund qty exists
                 */
                if ($item->getVendRefunded() || $item->getQtyRefunded() <= 0 || empty($vendConsignmentProductId)) {
                    continue;
                }

                foreach ($order->getMiraklOrders() as $miraklOrder) {
                    foreach ($miraklOrder->getOrderLines() as $miraklOrderLine) {
                        if ($miraklOrderLine->getOffer()->getProduct()->getSku() == $item->getSku()) {
                            $refundQty = 0;
                            $refundAmt = 0;

                            foreach ($miraklOrderLine->getRefunds() as $refund) {

                                /**
                                 * Consider only REFUNDED state as refunded ones
                                 */
                                if ($refund->getState() != 'REFUNDED') {
                                    continue;
                                }

                                $refundQty += (float) $refund->getQuantity();
                                $refundAmt += (float) $refund->getAmount();
                            }

                            if ($refundQty > 0) {
                                $consignmentName = $order->getIncrementId() . ' - ' . $outlet->name . ' - ' . date('Y m d');

                                if (is_null($consignmentId)) {
                                	$supplierId = $this->getSupplierId($customerId, $item->getMiraklShopName());
                                    $consignment = $this->createConsignment($order, $outlet->id, $supplierId, $consignmentName, 'RETURN', 'OPEN');
                                    $consignmentId = $consignment->data->id;
                                }

                                $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/2.0/consignments/'. $consignmentId . '/products';
                                $this->_curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
                                $this->_curl->addHeader('Authorization', 'Bearer ' . $oauthKey->access_token);

                                $this->_curl->post($url, [
                                'product_id' => $vendConsignmentProductId,
                                'product_sku' => $item->getSku(),
                                'count' => $refundQty,
                                'cost' => $refundAmt,
                                'status' => 'SUCCESS',
                                ]);

                                $response = json_decode($this->_curl->getBody());


                                $url = 'https://'. $oauthKey->domain_prefix . '.vendhq.com/api/2.0/consignments/'. $consignmentId;
                                $client = $this->_httpClientFactory->create();
                                $client->setUri($url);
                                $client->setMethod(\Zend_Http_Client::PUT);
                                $client->setHeaders(\Zend_Http_Client::CONTENT_TYPE, 'application/x-www-form-urlencoded');
                                //$client->setHeaders('Accept','application/json');
                                $client->setHeaders('Authorization', 'Bearer ' . $oauthKey->access_token);
                                $client->setParameterPost([
                                    'outlet_id' => $outlet->id,
                                    'name' => $consignmentName,
                                    'type' => 'RETURN',
                                    'status' => 'SENT'
                                    ]); //json
                                $response = json_decode($client->request()->getBody());

                                $sql = 'update ' . $this->_resource->getTableName('sales_order_item') . " set vend_refunded = 1 where item_id = ?";
                                $connection->query($sql, [$item->getItemId()]);
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Notify the customer that the item(s) of order has been shipped
     *
     * @param Object $order
     * @return bool
     */
    public function notifyOrderSynced(\Magento\Sales\Model\Order $order)
    {
    	try{
	        $storeId = $order->getStoreId();
	        $realOrderId = $order->getIncrementId();
	        $templateId = 'rsync_vend_email_template';
	    	$emailTo = $order->getCustomerEmail();

	        $emailFromName = $this->scopeConfig->getValue(
	            'trans_email/ident_general/name',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

	        $emailFrom = $this->scopeConfig->getValue(
	            'trans_email/ident_general/email',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

	        $emailSupport = $this->scopeConfig->getValue(
	            'trans_email/ident_support/email',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

	        // template variables pass here
	        $templateVars = [
				'order' => $order,
				'store' => $order->getStore(),
				'customer_name' => $order->getCustomerFirstName(),
				'created_at_formatted' => $order->getCreatedAtFormatted(2),
	        ];

	        $from = ['email' => $emailFrom, 'name' => $emailFromName];
	        $this->_inlineTranslation->suspend();

	        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
	        $templateOptions = [
	            'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
	            'store' => $storeId
	        ];
	        $transport = $this->_transportBuilder->setTemplateIdentifier($templateId, $storeScope)
	            ->setTemplateOptions($templateOptions)
	            ->setTemplateVars($templateVars)
	            ->setFrom($from)
	            ->setReplyTo($emailSupport)
	            ->addTo($emailTo)
	            ->getTransport();
	        $response = $transport->sendMessage();
	        $this->_inlineTranslation->resume();

	        return true;

    	}catch(\Exception $e){
    		$this->_helper->debug("Error while sending order synced email. Please check your email settings. Order no. $realOrderId", 'vend-sync-log.log');
    	}
    }

    /**
     * Notify the customer for Vend integration
     *
     * @param Object $order
     * @return bool
     */
    public function notifyVendIntegrated($customer)
    {
    	try{
	        $storeId = $this->_storeManager->getStore()->getId();
	        $templateId = 'rsync_vend_integration_email_template';
	    	$emailTo = $customer->getEmail();

	        $emailFromName = $this->scopeConfig->getValue(
	            'trans_email/ident_general/name',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

	        $emailFrom = $this->scopeConfig->getValue(
	            'trans_email/ident_general/email',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

	        $emailSupport = $this->scopeConfig->getValue(
	            'trans_email/ident_support/email',
	            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
	            $storeId
	        );

	        // template variables pass here
	        $templateVars = [
				'customer' => $customer,
				'store' => $this->_storeManager->getStore(),
				'customer_name' => $customer->getFirstname(),
	        ];

	        $from = ['email' => $emailFrom, 'name' => $emailFromName];
	        $this->_inlineTranslation->suspend();

	        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
	        $templateOptions = [
	            'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
	            'store' => $storeId
	        ];
	        $transport = $this->_transportBuilder->setTemplateIdentifier($templateId, $storeScope)
	            ->setTemplateOptions($templateOptions)
	            ->setTemplateVars($templateVars)
	            ->setFrom($from)
	            ->setReplyTo($emailSupport)
	            ->addTo($emailTo)
	            ->getTransport();
	        $response = $transport->sendMessage();
	        $this->_inlineTranslation->resume();

	        return true;

    	}catch(\Exception $e){
    		$this->_helper->debug("Error while sending Vend integration email. Please check your email settings.", 'vend-integration.log');
    	}
    }
}

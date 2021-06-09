<?php
namespace Rbncha\SyncPos\Controller\Vend;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;

class Callback extends \Magento\Customer\Controller\AbstractAccount implements HttpGetActionInterface
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $_pageFactory;

    protected $_customerSession;

    protected $storeManager;

    protected $curl;
    
    protected $_helper;
    
    protected $_scopeConfig;
    
    /**
     * @param \Magento\Framework\App\Action\Context $context
     */
    public function __construct(
       \Magento\Framework\App\Action\Context $context,
       \Magento\Framework\View\Result\PageFactory $pageFactory,
       \Magento\Customer\Model\Session $customerSession,
       \Magento\Store\Model\StoreManagerInterface $storeManager,
       \Magento\Framework\HTTP\Client\Curl $curl,
       \Rbncha\SyncPos\Helper\Vend $vendHelper,
       \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->_pageFactory = $pageFactory;
        $this->_customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->curl = $curl;
        $this->_helper = $vendHelper;
        $this->_scopeConfig = $scopeConfig;

        return parent::__construct($context);
    }
    /**
     * View page action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $params = $this->getRequest()->getParams();
        $customerId = $this->_customerSession->getCustomer()->getId();
        $customer = $this->_customerSession->getCustomer();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance(); // Instance of object manager
        $resource = $objectManager->create('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $storeId = $this->storeManager->getStore()->getId();
        
        $authorizationCode = $this->getRequest()->getParam('code');
        $error = $this->getRequest()->getParam('error', false);
        $errorDesc = $this->getRequest()->getParam('error_description');
        
        if($error){
        	$this->messageManager->addSuccessMessage(__('Vend Integration Error: ' . $errorDesc));
        	$this->_redirect('rsyncpos/index/view');
        	return;
        }
        
        $url = 'https://'.$this->getRequest()->getParam('domain_prefix') . '.vendhq.com/api/1.0/token';
        
        $clientId = $this->_scopeConfig->getValue(
            'rsyncpos/vend/client_id',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        $clientSecret = $this->_scopeConfig->getValue(
            'rsyncpos/vend/client_secret',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        $redirectUri = $this->storeManager->getStore()->getUrl('rsyncpos/vend/callback');
        
        $data = [
            'code' => $authorizationCode,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri
        ];

        $this->curl->addHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->curl->post($url, $data);

        $response = json_decode($this->curl->getBody());
        $data = $this->curl->getBody();
        
        $sql = "update " . $resource->getTableName('customer_entity') . " set oauth_key = ? where entity_id = ?";
        $connection->query($sql, [$data, $customerId]);
        
        $this->_helper->notifyVendIntegrated($customer);

        $this->messageManager->addSuccessMessage(__('Vend integration has been done and saved for future use'));

        $this->_redirect('rsyncpos/index/view');
    }
}

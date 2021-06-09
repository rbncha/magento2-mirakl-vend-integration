<?php
namespace Rbncha\SyncPos\Block\Customer;

use Magento\Framework\Exception\NoSuchEntityException;

class View extends \Magento\Framework\View\Element\Template
{
    /**
     * Cached subscription object
     *
     * @var \Magento\Newsletter\Model\Subscriber
     */
    protected $_customer;

    /**
     * @var \Magento\Customer\Helper\View
     */
    protected $_helperView;

    protected $_customerSession;

    /**
     * @var \Magento\Customer\Helper\Session\CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * @var Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepositoryInterface;

    protected $_storeManager;
    
    protected $_scopeConfig;
    
    protected $_helperVend;

    /**
     * Constructor
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Customer\Helper\Session\CurrentCustomer $currentCustomer
     * @param \Magento\Newsletter\Model\SubscriberFactory $subscriberFactory
     * @param \Magento\Customer\Helper\View $helperView
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Customer\Helper\Session\CurrentCustomer $currentCustomer,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Customer\Helper\View $helperView,
        \Magento\Customer\Model\Customer $customer,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepositoryInterface,
        \Magento\Framework\UrlInterface $urlInterface,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Rbncha\SyncPos\Helper\Vend $vendHelper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        $this->_helperView = $helperView;
        $this->currentCustomer = $currentCustomer;
        $this->customerRepositoryInterface = $customerRepositoryInterface;
        $this->_customerSession = $customerSession;
        $this->_storeManager = $storeManager;
        $this->_customer = $customer;
        $this->_helperVend = $vendHelper;

        parent::__construct($context, $data);
    }

    public function _construct()
    {
        parent::_construct();
    }

    public function isLoggedIn()
    {
        return $this->_customerSession->isLoggedIn();
    }

    /**
     * Returns the Magento Customer Model for this block
     *
     * @return \Magento\Customer\Api\Data\CustomerInterface|null
     */
    public function getCurrentCustomer()
    {
        try {
            return $this->currentCustomer->getCustomer();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    public function getCustomer()
    {
        if(!$this->_customer->getId() && $this->isLoggedIn())
            $this->_customer = $this->_customer->load($this->currentCustomer->getCustomer()->getId());
        
        return $this->_customer;
    }

    public function getApiCustomer()
    {
        if($this->isLoggedIn())
            return $this->customerRepositoryInterface->getById($this->getCurrentCustomer()->getId());

        return false;
    }

    /**
     * Get the full name of a customer
     *
     * @return string full name
     */
    public function getName()
    {
        return $this->_helperView->getCustomerName($this->getCurrentCustomer());
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        return $this->currentCustomer->getCustomerId() ? parent::_toHtml() : '';
    }

    /**
     * Validate whether Vend is integrated or not
     *
     * @return boolean
     */
    public function isVendIntegrated()
    {
    	$return = false;
    	
        if($this->isLoggedIn()){
			$return = $this->_helperVend->isVendIntegrated($this->currentCustomer->getCustomerId());
        }

        return $return;
    }

    /**
     * Generate Vend authorization url
     *
     * @return string
     */
    public function getVendAuthorizationUrl()
    {
    	$storeId = $this->_storeManager->getStore()->getId();
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

        $redirectUri = $this->escapeUrl($this->_storeManager->getStore()->getUrl('rsyncpos/vend/callback'));
        $state = 'tradesquare-vd';

        $url = "https://secure.vendhq.com/connect?response_type=code&client_id=$clientId&state=$state&redirect_uri=$redirectUri";
        
        return $url;
    }
}

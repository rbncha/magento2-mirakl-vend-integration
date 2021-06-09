<?php
namespace Rbncha\SyncPos\Controller\Vend;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;

class Remove extends \Magento\Customer\Controller\AbstractAccount implements HttpGetActionInterface
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $_pageFactory;
    protected $_customerSession;
    
    /**
     * @param \Magento\Framework\App\Action\Context $context
     */
    public function __construct(
       \Magento\Framework\App\Action\Context $context,
       \Magento\Framework\View\Result\PageFactory $pageFactory,
       \Magento\Customer\Model\Session $customerSession
    )
    {
        $this->_pageFactory = $pageFactory;
        $this->_customerSession = $customerSession;


        return parent::__construct($context);
    }
    /**
     * View page action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $customerId = $this->_customerSession->getCustomer()->getId();
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $resource = $objectManager->create('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        
        $sql = "update " . $resource->getTableName('customer_entity') . " set oauth_key = '' where entity_id = ?";
        $connection->query($sql, [$customerId]);

        $this->messageManager->addSuccessMessage(__('Vend integration has been removed.'));

        $this->_redirect('rsyncpos/index/view');
    }
}

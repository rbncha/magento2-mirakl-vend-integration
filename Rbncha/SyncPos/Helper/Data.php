<?php

namespace Rbncha\SyncPos\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\ObjectManagerInterface;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

class Data extends AbstractHelper
{

    protected $_storeManager;
    protected $_objectManager;
    protected $_request;
    protected $_appstate;
    protected $_ip;
    
    const debugIp = '172.31.30.84';

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        StoreManagerInterface $storeManager,
        ObjectManagerInterface $objectManager,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\State $appState,
        RemoteAddress $remoteAddress
    ) {

        parent::__construct($context);
        
        $this->_storeManager = $storeManager;
        $this->_objectManager = $objectManager;
        $this->_request = $request;
        $this->_appstate = $appState;
        $this->_ip = $remoteAddress;
    }

    public function getRequest()
    {
        return $this->_request;
    }

    /**
     * Returns only the website's base url without/with store code
     *
     * @param $storeCode string|bool store code
     * @return string
     */
    public function getBaseUrl($storeCode = false)
    {
        $stores = $this->getStoreCodes();
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl();

        foreach ($stores as $store) {
            $code = $store->getCode();
            $baseUrl = rtrim(str_replace($code, '', $baseUrl), '/') . '/';
        }

        if ($storeCode) {
            $baseUrl .= $storeCode . '/';
        }

        return $baseUrl;
    }

    public function getBaseBaseUrl($type = null)
    {
        $stores = $this->getStoreCodes();
        $baseUrl = $this->_storeManager->getStore()->getBaseUrl($type);

        foreach ($stores as $store) {
            $code = $store->getCode();
            $baseUrl = rtrim(str_replace($code, '', $baseUrl), '/') . '/';
        }

        return $baseUrl;
    }

    public function getStoreCodes($returnArray = false)
    {
        $stores = $this->_storeManager->getStores($withDefault = false);

        if ($returnArray) {
            $storesArray = [];
            foreach ($stores as $store) {
                $storesArray[$store->getCode()] = $store->toArray();
            }

            return $storesArray;
        }

        return $stores;
    }

    public function getStoreCode()
    {
        return $this->_storeManager->getStore()->getCode();
    }

    public function getStore()
    {
        return $this->_storeManager->getStore();
    }

    public function getInstance($class)
    {
        return $this->_objectManager->get($class);
    }

    /**
     * Create thumbnail image from image url
     *
     * @image string
     * @width integer
     * @height integer
     * @return string image path
     */
    public function resizeImage($image, $width = 200, $height = 200, $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'tiff'])
    {
        //$image = 'https://google.com/logo.jpg';

        $extension = pathinfo($image, PATHINFO_EXTENSION);

        if (!in_array($extension, $allowedExtensions)) {
            return $image;
        }

        $absolutePath = $this->_filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)->getAbsolutePath('dealerportal') . DIRECTORY_SEPARATOR . basename($image);
        $resizedURL = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA).'dealerportal' . DIRECTORY_SEPARATOR . basename($image);

        if (!file_exists($absolutePath)) {
            // auto create directory if not exist
            $this->_file->checkAndCreateFolder(dirname($absolutePath));


            $filepath = $this->_filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)->getAbsolutePath('tmp') . DIRECTORY_SEPARATOR . basename($image);
            //file_put_contents($filepath, file_get_contents($image));

            $this->_file->read($image, $filepath);

            $imageResize = $this->_imageFactory->create();
            $imageResize->open($filepath);
            $imageResize->constrainOnly(true);
            $imageResize->keepTransparency(true);
            $imageResize->keepFrame(true);
            $imageResize->keepAspectRatio(true);
            $imageResize->resize($width, $height);
            $destination = $absolutePath;
            $imageResize->save($destination);

            unlink($filepath);
        }
        
        return $resizedURL;
    }

    /**
     * Debug script with mode given
     *
     * @param string $message
     * @param string $filename
     * @param string $type
     * @param string $logDir
     * @param string $appmode
     *
     * $obj = \Magento\Framework\App\ObjectManager::getInstance();
     * $helper = $obj->create('\Rbnha\Vendhq\Helper\Data');
     * $helper->debug(__FILE__, 'saveorder.log', 'error');
     */
    public function debug($message, $filename = 'rbncha.log', $type = 'error', $logDir = BP . '/var/log/', $onlyOnDeveloperMode = true)
    {
        if ($onlyOnDeveloperMode && !$this->isDeveloperMode()) {
            return;
        }
        
        $type = $type == 'error' ? 'err' : $type;
        $writer = new \Zend\Log\Writer\Stream($logDir . $filename);
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->$type($message);
    }
    
    /**
     * check if application is in developer mode
     */
    public function isDeveloperMode()
    {
        return \Magento\Framework\App\State::MODE_DEVELOPER === $this->_appstate->getMode();
    }
    
    /**
     * check if application is in production mode
     */
    public function isProductionMode()
    {
        return \Magento\Framework\App\State::MODE_PRODUCTION === $this->_appstate->getMode();
    }
    
    public function debugIfIp($message, $echoExit = 'exit')
    {
        if ($this->_ip->getRemoteAddress() == self::debugIp) {
            if ($echoExit == 'exit') {
                exit($message.'....');
            }
            
            if ($echoExit == 'echo') {
                echo $message;
            }
        }
    }
    
    public function exits($message)
    {
        $this->debugIfIp($message, 'exit');
    }
    
    public function ifIpTrue()
    {
        return $_SERVER['REMOTE_ADDR'] == $this::debugIp;
    }
}

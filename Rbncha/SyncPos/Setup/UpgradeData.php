<?php
namespace Rbncha\SyncPos\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\InstallSchemaInterface;  
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

/**
* @codeCoverageIgnore
*/
class UpgradeData implements UpgradeDataInterface
{
    /**
     * Eav setup factory
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * Init
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(\Magento\Eav\Setup\EavSetupFactory $eavSetupFactory)
    {
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
    	$table = $setup->getTable('oauth_integration');
    	
    	if (version_compare($context->getVersion(), '1.0.1', '<')){
            
            if (!$setup->tableExists($table)) {
	            $connection = $setup->getConnection();
		        $connection->query("CREATE TABLE $table (
	              `client_id` varchar(255) NOT NULL,
	              `client_secret` varchar(255) DEFAULT NULL,
	              `authorized_code` text,
	              `code` varchar(50),
	              `date_created` datetime NOT NULL DEFAULT current_timestamp(),
	              `active` smallint(1) NOT NULL DEFAULT 1,
	              PRIMARY KEY (`client_id`)
	            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }
		}
		
		if (version_compare($context->getVersion(), '1.0.2', '<')){
			if ($setup->tableExists($table)) {
	            $setup->getConnection()->dropTable($setup->getTable($table));
	        }
		}

    }
}

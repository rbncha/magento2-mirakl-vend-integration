<?php

declare(strict_types=1);

namespace Rbncha\SyncPos\Cron;

use Rbncha\SyncPos\Helper\Data;

class Sync
{
    protected $_vendHelper;

    public function __construct(
        \Rbncha\SyncPos\Helper\Vend $vendHeler
    ) {
        $this->_vendHelper = $vendHeler;
    }

    public function execute()
    {
        $this->_vendHelper->syncOrderItems();
        //$this->_vendHelper->returnOrders();
    }
}

<?php
namespace Lof\PosSync\Api;

interface SyncOrderManagementInterface
{

    /**
     * Sync Order
     *  @return mixed
     */
    public function saveSyncOrder();

}
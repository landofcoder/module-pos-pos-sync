<?php

namespace Lof\PosSync\Model;

 use Lof\PosSync\Api\SyncOrderManagementInterface;
 use Magento\Framework\Webapi\Rest\Request;

 class SyncOrderManagement implements  SyncOrderManagementInterface
 {
     const TOPIC_NAME = 'syncorders';
     /**
      * @var Request
      */
     protected $request;

     public function __construct(Request $request)
     {
         $this->request = $request;

     }
     /**
     * {@inheritdoc}
     */
     public function saveSyncOrder()
     {
         $carts = $this->request->getBodyParams();
         return $carts;
     }
 }
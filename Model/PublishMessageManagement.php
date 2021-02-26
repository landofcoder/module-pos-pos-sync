<?php
namespace Lof\PosSync\Model;
class PublishMessageManagement implements \Lof\PosSync\Api\PublishMessageManagementInterface
{
    const TOPIC_NAME = 'syncorders';
    /**
     * @var \Magento\Framework\MessageQueue\PublisherInterface
     */
    private $publisher;
    public $message;
    public function __construct(\Magento\Framework\MessageQueue\PublisherInterface $publisher,
                                \Lof\PosSync\Api\SyncOrderManagementInterface $message)
    {
        $this->publisher = $publisher;
        $this->message = $message;
    }
    /**
     * {@inheritdoc}
     */
    public function setMessage()
    {
        $publish = $this->message->saveSyncOrder();
        $encodeValue = json_encode($publish, true);
         $this->publisher->publish(self::TOPIC_NAME, $encodeValue);

        return true;
    }
}
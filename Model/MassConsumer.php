<?php

namespace Lof\PosSync\Model;
use Magento\Catalog\Model\ProductFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\CallbackInvoker;
use Magento\Framework\MessageQueue\ConsumerConfigurationInterface;
use Magento\Framework\MessageQueue\ConsumerInterface;
use Magento\Framework\MessageQueue\EnvelopeInterface;
use Magento\Framework\MessageQueue\LockInterfaceFactory;
use Magento\Framework\MessageQueue\MessageController;
use Magento\Framework\MessageQueue\QueueInterface;
use Magento\Checkout\Model\Cart;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class MassConsumer implements ConsumerInterface
{
    /**
     * @var \Magento\Framework\MessageQueue\ConsumerConfigurationInterface
     */
    private $configuration;
    /**
     * @var \Magento\Framework\MessageQueue\CallbackInvoker
     */
    private $invoker;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resource;
    /**
     * @var \Magento\Framework\MessageQueue\MessageController
     */
    private $messageController;
    /**
     * @var ProductFactory
     */
    protected $product;
    /**
     * @var  Cart
     */
    protected $cart;
    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;
    /**
     * @var QuoteManagement
     */
    protected $quoteManagement;
    /**
     * @var StoreManagerInterface
     */
    protected  $storeManager;
    /**
     * @var CustomerFactory
     */
    protected  $customerFactory;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public  function  __construct(
        CallbackInvoker $invoker,
        Cart $cart,
        ResourceConnection $resource,
        ConsumerConfigurationInterface $configuration,
        ProductFactory $product,
        CartRepositoryInterface $quoteRepository,
        QuoteManagement $quoteManagement,
        MessageController $messageController,
        StoreManagerInterface $storeManager,
        CustomerFactory $customerFactory,
        LoggerInterface $logger = null)
    {
        $this->configuration = $configuration;
        $this->invoker = $invoker;
        $this->resource = $resource;
        $this->messageController = $messageController;
        $this->product = $product;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->cart = $cart;
        $this->storeManager = $storeManager;
        $this->customerFactory = $customerFactory;
        $this->logger = $logger ?: \Magento\Framework\App\ObjectManager::getInstance()->get(LoggerInterface::class);
    }

    /**
     * @inheritDoc
     */
    public function process($maxNumberOfMessages = null)
    {
        $queue = $this->configuration->getQueue();
        if (!isset($maxNumberOfMessages)) {
            $queue->subscribe($this->getTransactionCallback($queue));
        } else {
            $this->invoker->invoke($queue, $maxNumberOfMessages, $this->getTransactionCallback($queue));
        }
    }

    /**
     * Get transaction callback. This handles the case of async.
     *
     * @param QueueInterface $queue
     * @return \Closure
     */
    private function getTransactionCallback(QueueInterface $queue)
    {
        return function (EnvelopeInterface $message) use ($queue) {
            /** @var LockInterfaceFactory $lock */
            $lock = null;
            try {
                 $lock = $this->messageController->lock($message, $this->configuration->getConsumerName());
                $message = $message->getBody();
                $message = json_decode($message);
                $message = (array)json_decode($message, true);
                $products = $message['items']['cartCurrentResult'];
                foreach ($products as $product) {
                    $productId = $product['id'];
                    $qty = $product['pos_qty'];
                    $params = [
                        'product_id' => $productId, //product Id
                        'pos_qty' => $qty
                    ];
                    $_product = $this->product->create()->load($productId);
                    try {
                        $this->cart->addProduct($_product, $params);

                    } catch (LocalizedException $e) {
                        $this->logger->critical($e->getMessage());
                    }
                }
                $cartObj = $this->cart->save();
                $cartId = $cartObj->getQuote()->getId();
                $order = $message['items']['orderPreparingCheckoutResult'];
                $email = $order['email'];
                $firstName = $order['shipping_address']['firstname'];
                $lastName = $order['shipping_address']['lastname'];
                $shippingAddr = $order['shipping_address'];
                $shippingMethod = $order['shipping_address']['shipping_method'];
                $paymentMethod = $order['shipping_address']['method'];
                $shippingMethodCode = $order['shipping_address']['shipping_method_code'];
                $shippingCarrierCode = $order['shipping_address']['shipping_carrier_code'];
                $websiteId = $this->storeManager->getStore()->getWebsiteId();
                $customer = $this->customerFactory->create();
                $customer->setWebsiteId($websiteId);
                $customer->loadByEmail($email);
                if (!$customer->getEntityId()) {
                    //If not avilable then create this customer
                    $customer->setWebsiteId($websiteId);
                    $customer->setEmail($email);
                    $customer->setFirstname($firstName);
                    $customer->setLastname($lastName);
                    $customer->setPassword($email);
                    $customer->save();
                }
                $quote = $this->quoteRepository->get($cartId);
                $quote->getBillingAddress()->addData($shippingAddr);
                $quote->getShippingAddress()->addData($shippingAddr);
                $shippingAddress = $quote->getShippingAddress();
                $shippingAddress->setCollectShippingRates(true)
                    ->collectShippingRates()
                    ->setShippingMethod($shippingMethod)
                    ->setShippingMethodCode($shippingMethodCode)
                    ->setshippingCarrierCode($shippingCarrierCode);
                $quote->getPayment()->setMethod($paymentMethod);
                $quote->setCustomer_email($email)
                    ->setCustomerFirstname($firstName)
                    ->setCustomerLastname($lastName);
                $quote->setCustomerIsGuest(true);
                $quote->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
                $quote->save();
                $quote = $this->quoteRepository->get($quote->getId());
                try {
                    $this->quoteManagement->placeOrder($quote->getId());
                } catch (CouldNotSaveException $e) {
                    $this->logger->critical($e->getMessage());
                }
                $queue->acknowledge($message);
                return true;
            } catch (\Magento\Framework\MessageQueue\ConnectionLostException $e) {
                if ($lock) {
                    $this->resource->getConnection()
                        ->delete($this->resource->getTableName('queue_lock'), ['id = ?' => $lock->getId()]);
                }
            } catch (\Magento\Framework\Exception\NotFoundException $e) {
                $queue->acknowledge($message);
                $this->logger->warning($e->getMessage());
            } catch (\Exception $e) {
                $queue->reject($message, false, $e->getMessage());
                if ($lock) {
                    $this->resource->getConnection()
                        ->delete($this->resource->getTableName('queue_lock'), ['id = ?' => $lock->getId()]);
                }
            }
        };
    }
}

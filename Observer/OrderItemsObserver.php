<?php
/**
 * MagoArab OrderEnhancer Order Items Observer
 *
 * @category    MagoArab
 * @package     MagoArab_OrderEnhancer
 * @author      MagoArab Team
 * @copyright   Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderEnhancer\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MagoArab\OrderEnhancer\Helper\Data as HelperData;
use Psr\Log\LoggerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Magento\Framework\App\ResourceConnection;

class OrderItemsObserver implements ObserverInterface
{
    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderItemCollectionFactory
     */
    protected $orderItemCollectionFactory;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @param HelperData $helperData
     * @param LoggerInterface $logger
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderItemCollectionFactory $orderItemCollectionFactory
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        HelperData $helperData,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        OrderItemCollectionFactory $orderItemCollectionFactory,
        ResourceConnection $resourceConnection
    ) {
        $this->helperData = $helperData;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->orderItemCollectionFactory = $orderItemCollectionFactory;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Add order items data to collection after load
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->helperData->isProductColumnsEnabled()) {
            return;
        }

        try {
            $collection = $observer->getEvent()->getCollection();
            
            if (!$collection || !$collection->isLoaded()) {
                return;
            }

            // Get all order IDs from collection
            $orderIds = [];
            foreach ($collection as $order) {
                $orderId = $order->getData('entity_id');
                if ($orderId) {
                    $orderIds[] = $orderId;
                }
            }

            if (empty($orderIds)) {
                return;
            }

            // Batch load all items for performance
            $itemsData = $this->loadOrderItemsData($orderIds);

            // Apply items data to orders
            foreach ($collection as $order) {
                $orderId = $order->getData('entity_id');
                if (isset($itemsData[$orderId])) {
                    $order->setData('item_details', $itemsData[$orderId]['details']);
                    $order->setData('item_prices', $itemsData[$orderId]['prices']);
                    $order->setData('items_subtotal', $itemsData[$orderId]['subtotal']);
                }
            }

            $this->logger->info('OrderItemsObserver: Processed ' . count($orderIds) . ' orders');

        } catch (\Exception $e) {
            $this->logger->error('OrderItemsObserver Error: ' . $e->getMessage());
        }
    }

    /**
     * Load order items data for multiple orders at once
     *
     * @param array $orderIds
     * @return array
     */
    protected function loadOrderItemsData(array $orderIds)
    {
        $itemsData = [];
        
        try {
            $connection = $this->resourceConnection->getConnection();
            $itemsTable = $this->resourceConnection->getTableName('sales_order_item');
            
            // Build optimized query to get all items at once
            $select = $connection->select()
                ->from($itemsTable, [
                    'order_id',
                    'name',
                    'sku',
                    'qty_ordered',
                    'price',
                    'row_total',
                    'parent_item_id'
                ])
                ->where('order_id IN (?)', $orderIds)
                ->where('parent_item_id IS NULL'); // Only get parent items
            
            $items = $connection->fetchAll($select);
            
            // Group items by order
            $orderItems = [];
            foreach ($items as $item) {
                $orderId = $item['order_id'];
                if (!isset($orderItems[$orderId])) {
                    $orderItems[$orderId] = [];
                }
                $orderItems[$orderId][] = $item;
            }
            
            // Process each order's items
            foreach ($orderItems as $orderId => $items) {
                $details = [];
                $prices = [];
                $subtotal = 0;
                
                foreach ($items as $item) {
                    // Format item details
                    $details[] = sprintf(
                        '%s (SKU: %s, Qty: %s)',
                        $item['name'] ?: 'Unknown Product',
                        $item['sku'] ?: 'N/A',
                        number_format((float)$item['qty_ordered'], 2)
                    );
                    
                    // Format price
                    $prices[] = number_format((float)$item['price'], 2);
                    
                    // Calculate subtotal
                    $subtotal += (float)$item['row_total'];
                }
                
                $itemsData[$orderId] = [
                    'details' => implode(' | ', $details),
                    'prices' => implode(', ', $prices),
                    'subtotal' => $subtotal
                ];
            }
            
            // Set empty data for orders without items
            foreach ($orderIds as $orderId) {
                if (!isset($itemsData[$orderId])) {
                    $itemsData[$orderId] = [
                        'details' => '',
                        'prices' => '',
                        'subtotal' => 0
                    ];
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error loading order items: ' . $e->getMessage());
        }
        
        return $itemsData;
    }
}
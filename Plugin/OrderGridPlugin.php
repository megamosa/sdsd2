<?php
/**
 * MagoArab OrderEnhancer Order Grid Plugin
 *
 * @category    MagoArab
 * @package     MagoArab_OrderEnhancer
 * @author      MagoArab Team
 * @copyright   Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderEnhancer\Plugin;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection;
use MagoArab\OrderEnhancer\Helper\Data as HelperData;
use Psr\Log\LoggerInterface;
use Magento\Framework\DB\Select;

class OrderGridPlugin
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
     * @var array
     */
    protected $joinedTables = [];

    /**
     * @param HelperData $helperData
     * @param LoggerInterface $logger
     */
    public function __construct(
        HelperData $helperData,
        LoggerInterface $logger
    ) {
        $this->helperData = $helperData;
        $this->logger = $logger;
    }

    /**
     * Before load to add required columns
     *
     * @param Collection $subject
     * @param bool $printQuery
     * @param bool $logQuery
     * @return array
     */
    public function beforeLoad(Collection $subject, $printQuery = false, $logQuery = false)
    {
        if (!$subject->isLoaded() && $this->helperData->isExcelExportEnabled()) {
            $this->addCustomColumns($subject);
        }
        
        return [$printQuery, $logQuery];
    }

    /**
     * Add custom columns to order grid
     *
     * @param Collection $collection
     */
    protected function addCustomColumns(Collection $collection)
    {
        try {
            $select = $collection->getSelect();
            
            // Reset joined tables tracking
            $this->joinedTables = [];
            
            // Join with sales_order table for basic order data
            $this->joinSalesOrderTable($select, $collection);
            
            // Join billing address table once
            $this->joinBillingAddressTable($select, $collection);
            
            // Join shipping address table once
            $this->joinShippingAddressTable($select, $collection);
            
            // Add customer name with proper concatenation
            $this->addCustomerNameColumn($select);
            
            // Add phone columns
            $this->addPhoneColumns($select);
            
            // Add address columns
            $this->addAddressColumns($select);
            
            // Add item details - optimized
            $this->addItemDetailsColumns($select, $collection);
            
            $this->logger->info('OrderGridPlugin: Successfully added custom columns');

        } catch (\Exception $e) {
            $this->logger->error('OrderGridPlugin Error: ' . $e->getMessage());
            $this->logger->error('Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Join sales_order table - Enhanced with increment_id
     */
    protected function joinSalesOrderTable($select, $collection)
    {
        if (!isset($this->joinedTables['sales_order'])) {
            $select->joinLeft(
                ['so' => $collection->getTable('sales_order')],
                'so.entity_id = main_table.entity_id',
                [
                    'increment_id' => 'so.increment_id',
                    'customer_note' => 'so.customer_note',
                    'discount_amount' => 'so.discount_amount',
                    'total_qty_ordered' => 'so.total_qty_ordered',
                    'customer_email' => 'so.customer_email',
                    'customer_firstname' => 'so.customer_firstname',
                    'customer_lastname' => 'so.customer_lastname'
                ]
            );
            $this->joinedTables['sales_order'] = true;
        }
    }

    /**
     * Join billing address table
     */
    protected function joinBillingAddressTable($select, $collection)
    {
        if (!isset($this->joinedTables['billing_address'])) {
            $select->joinLeft(
                ['billing_addr' => $collection->getTable('sales_order_address')],
                'billing_addr.parent_id = main_table.entity_id AND billing_addr.address_type = "billing"',
                [
                    'billing_firstname' => 'billing_addr.firstname',
                    'billing_lastname' => 'billing_addr.lastname',
                    'billing_telephone' => 'billing_addr.telephone',
                    'billing_region' => 'billing_addr.region',
                    'billing_city' => 'billing_addr.city',
                    'billing_street' => 'billing_addr.street'
                ]
            );
            $this->joinedTables['billing_address'] = true;
        }
    }

    /**
     * Join shipping address table
     */
    protected function joinShippingAddressTable($select, $collection)
    {
        if (!isset($this->joinedTables['shipping_address'])) {
            $select->joinLeft(
                ['shipping_addr' => $collection->getTable('sales_order_address')],
                'shipping_addr.parent_id = main_table.entity_id AND shipping_addr.address_type = "shipping"',
                [
                    'shipping_firstname' => 'shipping_addr.firstname',
                    'shipping_lastname' => 'shipping_addr.lastname',
                    'shipping_telephone' => 'shipping_addr.telephone',
                    'shipping_region' => 'shipping_addr.region',
                    'shipping_city' => 'shipping_addr.city',
                    'shipping_street' => 'shipping_addr.street'
                ]
            );
            $this->joinedTables['shipping_address'] = true;
        }
    }

    /**
     * Add customer name column with proper priority - Enhanced version
     */
    protected function addCustomerNameColumn($select)
    {
        // Enhanced customer name with better priority and trimming
        $customerNameExpression = new \Zend_Db_Expr('
            TRIM(
                COALESCE(
                    NULLIF(TRIM(CONCAT(
                        IFNULL(billing_addr.firstname, ""), 
                        " ", 
                        IFNULL(billing_addr.lastname, "")
                    )), ""),
                    NULLIF(TRIM(CONCAT(
                        IFNULL(shipping_addr.firstname, ""), 
                        " ", 
                        IFNULL(shipping_addr.lastname, "")
                    )), ""),
                    NULLIF(TRIM(CONCAT(
                        IFNULL(so.customer_firstname, ""), 
                        " ", 
                        IFNULL(so.customer_lastname, "")
                    )), ""),
                    "Guest Customer"
                )
            )
        ');

        // Use 'enhanced_customer_name' to match the XML column definition
        $select->columns(['enhanced_customer_name' => $customerNameExpression]);
    }

    /**
     * Add phone columns with fallback
     */
    protected function addPhoneColumns($select)
    {
        // Phone with fallback to shipping
        $phoneExpression = new \Zend_Db_Expr('
            COALESCE(
                NULLIF(billing_addr.telephone, ""),
                NULLIF(shipping_addr.telephone, ""),
                ""
            )
        ');

        $select->columns(['phone_number' => $phoneExpression]);
        
        // Alternative phone - simplified for now
        $select->columns(['alternative_phone' => new \Zend_Db_Expr('""')]);
    }

    /**
     * Add address columns with fallback
     */
    protected function addAddressColumns($select)
    {
        // Region/Governorate with fallback
        $regionExpression = new \Zend_Db_Expr('
            COALESCE(
                NULLIF(billing_addr.region, ""),
                NULLIF(shipping_addr.region, ""),
                ""
            )
        ');

        // City with fallback
        $cityExpression = new \Zend_Db_Expr('
            COALESCE(
                NULLIF(billing_addr.city, ""),
                NULLIF(shipping_addr.city, ""),
                ""
            )
        ');

        // Street with fallback
        $streetExpression = new \Zend_Db_Expr('
            COALESCE(
                NULLIF(billing_addr.street, ""),
                NULLIF(shipping_addr.street, ""),
                ""
            )
        ');

        $select->columns([
            'governorate' => $regionExpression,
            'city' => $cityExpression,
            'street_address' => $streetExpression
        ]);
    }

    /**
     * Add item details columns - optimized version
     */
    protected function addItemDetailsColumns($select, $collection)
    {
        // Join order items table
        $itemsTable = $collection->getTable('sales_order_item');
        
        // Item details - only visible items
        $itemDetailsExpression = new \Zend_Db_Expr("
            (SELECT GROUP_CONCAT(
                CONCAT(
                    IFNULL(name, 'Unknown'), 
                    ' (SKU: ', IFNULL(sku, 'N/A'), 
                    ', Qty: ', CAST(IFNULL(qty_ordered, 0) AS CHAR), ')'
                ) SEPARATOR ' | '
            ) 
            FROM {$itemsTable}
            WHERE order_id = main_table.entity_id 
            AND parent_item_id IS NULL
            GROUP BY order_id)
        ");

        // Item prices
        $itemPricesExpression = new \Zend_Db_Expr("
            (SELECT GROUP_CONCAT(
                CAST(ROUND(IFNULL(price, 0), 2) AS CHAR) SEPARATOR ', '
            ) 
            FROM {$itemsTable}
            WHERE order_id = main_table.entity_id 
            AND parent_item_id IS NULL
            GROUP BY order_id)
        ");

        // Items subtotal
        $itemSubtotalExpression = new \Zend_Db_Expr("
            (SELECT IFNULL(SUM(row_total), 0) 
            FROM {$itemsTable}
            WHERE order_id = main_table.entity_id 
            AND parent_item_id IS NULL)
        ");

        $select->columns([
            'item_details' => $itemDetailsExpression,
            'item_prices' => $itemPricesExpression,
            'items_subtotal' => $itemSubtotalExpression
        ]);
    }
}
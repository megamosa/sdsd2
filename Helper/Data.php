<?php
/**
 * MagoArab OrderEnhancer Helper
 *
 * @category    MagoArab
 * @package     MagoArab_OrderEnhancer
 * @author      MagoArab Team
 * @copyright   Copyright (c) 2024 MagoArab
 */

namespace MagoArab\OrderEnhancer\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;

class Data extends AbstractHelper
{
    /**
     * Configuration paths
     */
    const XML_PATH_ENABLE_EXCEL_EXPORT = 'order_enhancer/general/enable_excel_export';
    const XML_PATH_ENABLE_GOVERNORATE_FILTER = 'order_enhancer/general/enable_governorate_filter';
    const XML_PATH_ENABLE_PRODUCT_COLUMNS = 'order_enhancer/general/enable_product_columns';
    const XML_PATH_ENABLE_CUSTOMER_EMAIL = 'order_enhancer/general/enable_customer_email';
    const XML_PATH_CONSOLIDATE_ORDERS = 'order_enhancer/general/consolidate_orders';
    const XML_PATH_UTF8_ENCODING = 'order_enhancer/general/utf8_encoding';
    const XML_PATH_ENABLE_LOGGING = 'order_enhancer/debug/enable_logging';
    const XML_PATH_LOG_EXPORT_DETAILS = 'order_enhancer/debug/log_export_details';

    /**
     * @var array
     */
    protected $configCache = [];

    /**
     * @var TypeListInterface
     */
    protected $cacheTypeList;

    /**
     * @param Context $context
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        Context $context,
        TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * Check if Excel export enhancement is enabled (cached)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isExcelExportEnabled($storeId = null)
    {
        return $this->getCachedConfigFlag(self::XML_PATH_ENABLE_EXCEL_EXPORT, $storeId);
    }

    /**
     * Check if governorate filter is enabled (cached)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isGovernorateFilterEnabled($storeId = null)
    {
        return $this->getCachedConfigFlag(self::XML_PATH_ENABLE_GOVERNORATE_FILTER, $storeId);
    }

    /**
     * Check if product columns are enabled (cached)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isProductColumnsEnabled($storeId = null)
    {
        return $this->getCachedConfigFlag(self::XML_PATH_ENABLE_PRODUCT_COLUMNS, $storeId);
    }

    /**
     * Check if customer email column is enabled (cached)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isCustomerEmailEnabled($storeId = null)
    {
        return $this->getCachedConfigFlag(self::XML_PATH_ENABLE_CUSTOMER_EMAIL, $storeId);
    }

    /**
     * Check if order consolidation is enabled (cached)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isOrderConsolidationEnabled($storeId = null)
    {
        return $this->getCachedConfigFlag(self::XML_PATH_CONSOLIDATE_ORDERS, $storeId);
    }

    /**
     * Check if UTF-8 encoding is enabled (cached)
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isUtf8EncodingEnabled($storeId = null)
    {
        return $this->getCachedConfigFlag(self::XML_PATH_UTF8_ENCODING, $storeId);
    }

    /**
     * Check if logging is enabled
     *
     * @return bool
     */
    public function isLoggingEnabled()
    {
        return $this->getCachedConfigFlag(self::XML_PATH_ENABLE_LOGGING);
    }

    /**
     * Get cached config flag
     *
     * @param string $path
     * @param int|null $storeId
     * @return bool
     */
    protected function getCachedConfigFlag($path, $storeId = null)
    {
        $cacheKey = $path . '_' . ($storeId ?: 'default');
        
        if (!isset($this->configCache[$cacheKey])) {
            $this->configCache[$cacheKey] = $this->scopeConfig->isSetFlag(
                $path,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        
        return $this->configCache[$cacheKey];
    }

    /**
     * Get required columns configuration
     *
     * @return array
     */
    public function getRequiredColumns()
    {
        return [
            'Order Date' => ['Order Date', 'created_at', 'Created At'],
            'Order Name' => ['Order Name', 'order_name', 'enhanced_customer_name', 'billing_firstname'],
            'Customer Email' => ['Customer Email', 'customer_email'],
            'Phone Number' => ['Phone Number', 'phone_number', 'billing_telephone'],
            'Alternative Phone' => ['Alternative Phone', 'alternative_phone'],
            'Order Comments' => ['Order Comments', 'customer_note'],
            'Order Status' => ['Order Status', 'status'],
            'Governorate' => ['Governorate', 'governorate', 'billing_region'],
            'City' => ['City', 'city', 'billing_city'],
            'Street Address' => ['Street Address', 'street_address', 'billing_street'],
            'Total Quantity Ordered' => ['Total Quantity Ordered', 'total_qty_ordered'],
            'Item Details' => ['Item Details', 'item_details'],
            'Item Price' => ['Item Price', 'item_prices'],
            'Subtotal' => ['Subtotal', 'items_subtotal'],
            'Shipping Amount' => ['Shipping Amount', 'shipping_and_handling'],
            'Discount Amount' => ['Discount Amount', 'discount_amount'],
            'Grand Total' => ['Grand Total', 'grand_total']
        ];
    }

    /**
     * Get export file configuration
     *
     * @return array
     */
    public function getExportConfig()
    {
        return [
            'encoding' => 'UTF-8',
            'delimiter' => $this->scopeConfig->getValue('order_enhancer/export_settings/delimiter') ?: ',',
            'enclosure' => $this->scopeConfig->getValue('order_enhancer/export_settings/enclosure') ?: '"',
            'escape' => '"',
            'add_bom' => true
        ];
    }

    /**
     * Log debug information if enabled
     *
     * @param string $message
     * @param array $context
     */
    public function logDebug($message, array $context = [])
    {
        if ($this->isLoggingEnabled()) {
            $this->_logger->debug('MagoArab OrderEnhancer: ' . $message, $context);
        }
    }

    /**
     * Get customer name priority settings
     *
     * @return array
     */
    public function getCustomerNamePriority()
    {
        $priority = $this->scopeConfig->getValue('order_enhancer/customer_data/name_priority');
        
        switch ($priority) {
            case 'shipping_first':
                return ['shipping_address', 'billing_address', 'customer_data', 'guest_fallback'];
            case 'customer_first':
                return ['customer_data', 'billing_address', 'shipping_address', 'guest_fallback'];
            case 'billing_only':
                return ['billing_address', 'guest_fallback'];
            case 'shipping_only':
                return ['shipping_address', 'guest_fallback'];
            default:
                return ['billing_address', 'shipping_address', 'customer_data', 'guest_fallback'];
        }
    }

    /**
     * Check if phone fallback is enabled
     *
     * @return bool
     */
    public function isPhoneFallbackEnabled()
    {
        return $this->scopeConfig->isSetFlag('order_enhancer/customer_data/phone_fallback');
    }

    /**
     * Check if address fallback is enabled
     *
     * @return bool
     */
    public function isAddressFallbackEnabled()
    {
        return $this->scopeConfig->isSetFlag('order_enhancer/customer_data/address_fallback');
    }

    /**
     * Get date format for export
     *
     * @return string
     */
    public function getDateFormat()
    {
        return $this->scopeConfig->getValue('order_enhancer/export_settings/date_format') ?: 'Y-m-d H:i:s';
    }

    /**
     * Validate required configuration
     *
     * @return array
     */
    public function validateConfiguration()
    {
        $errors = [];
        
        if (!$this->isExcelExportEnabled()) {
            $errors[] = __('Excel export enhancement is disabled');
        }
        
        // Check PHP extensions
        if (!extension_loaded('mbstring')) {
            $errors[] = __('PHP mbstring extension is required for proper UTF-8 support');
        }
        
        return $errors;
    }

    /**
     * Clear configuration cache
     *
     * @return void
     */
    public function clearConfigCache()
    {
        $this->configCache = [];
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }
}
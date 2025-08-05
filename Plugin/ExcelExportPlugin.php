<?php

namespace MagoArab\OrderEnhancer\Plugin;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Response\Http\FileFactory;
use MagoArab\OrderEnhancer\Helper\Data as HelperData;

class ExcelExportPlugin
{
    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var FileFactory
     */
    protected $fileFactory;

    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * Required columns mapping - Updated with Order Name
     */
    private const REQUIRED_COLUMNS = [
        'Order ID' => ['Order ID', 'increment_id', 'Increment Id', 'entity_id'],
        'Order Date' => ['Order Date', 'created_at', 'Created At'],
        'Order Name' => ['Order Name', 'enhanced_customer_name', 'order_name', 'full_customer_name', 'billing_firstname', 'shipping_firstname'],
        'Customer Email' => ['Customer Email', 'customer_email'],
        'Phone Number' => ['Phone Number', 'phone_number', 'billing_telephone', 'shipping_telephone'],
        'Alternative Phone' => ['Alternative Phone', 'alternative_phone'],
        'Order Comments' => ['Order Comments', 'customer_note'],
        'Order Status' => ['Order Status', 'status', 'Status'],
        'Governorate' => ['Governorate', 'governorate', 'billing_region', 'shipping_region'],
        'City' => ['City', 'city', 'billing_city', 'shipping_city'],
        'Street Address' => ['Street Address', 'street_address', 'billing_street', 'shipping_street'],
        'Total Quantity Ordered' => ['Total Quantity Ordered', 'total_qty_ordered'],
        'Item Details' => ['Item Details', 'item_details'],
        'Item Price' => ['Item Price', 'item_prices'],
        'Subtotal' => ['Subtotal', 'subtotal', 'items_subtotal'],
        'Shipping Amount' => ['Shipping Amount', 'shipping_and_handling', 'Shipping and Handling'],
        'Discount Amount' => ['Discount Amount', 'discount_amount'],
        'Grand Total' => ['Grand Total', 'grand_total']
    ];

    /**
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     * @param FileFactory $fileFactory
     * @param HelperData $helperData
     */
    public function __construct(
        Filesystem $filesystem,
        LoggerInterface $logger,
        FileFactory $fileFactory,
        HelperData $helperData
    ) {
        $this->filesystem = $filesystem;
        $this->logger = $logger;
        $this->fileFactory = $fileFactory;
        $this->helperData = $helperData;
    }

    /**
     * After get CSV file - ConvertToCsv
     */
    public function afterGetCsvFile($subject, $result)
    {
        if (!$this->helperData->isExcelExportEnabled()) {
            return $result;
        }

        try {
            $this->processExportResult($result);
        } catch (\Exception $e) {
            $this->logger->error('ExcelExportPlugin Error: ' . $e->getMessage());
        }

        return $result;
    }
    
    /**
     * After get XML file - ConvertToXml
     */
    public function afterGetXmlFile($subject, $result)
    {
        if (!$this->helperData->isExcelExportEnabled()) {
            return $result;
        }

        try {
            $this->processExportResult($result);
        } catch (\Exception $e) {
            $this->logger->error('ExcelExportPlugin Error: ' . $e->getMessage());
        }

        return $result;
    }
    
    /**
     * Process export result
     */
    protected function processExportResult($result)
    {
        $filePath = $this->extractFilePath($result);
        
        if ($filePath) {
            $this->enhanceOrderExport($filePath);
        }
    }

    /**
     * Extract file path from various result formats
     */
    protected function extractFilePath($result)
    {
        if (is_array($result)) {
            return $result['value'] ?? $result['file'] ?? null;
        }
        
        return is_string($result) ? $result : null;
    }

    /**
     * Enhance order export with proper UTF-8 encoding and required columns only
     */
    protected function enhanceOrderExport($filePath)
    {
        try {
            $this->logger->info('EnhanceOrderExport: Starting with file path: ' . $filePath);
            
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            
            $fullPath = $this->findValidFilePath($directory, $filePath);
            if (!$fullPath) {
                $this->logger->error('EnhanceOrderExport: Could not find valid file path for: ' . $filePath);
                return;
            }
            
            $this->logger->info('EnhanceOrderExport: Found file at: ' . $fullPath);

            $content = $directory->readFile($fullPath);
            if (empty($content)) {
                $this->logger->error('EnhanceOrderExport: File is empty: ' . $fullPath);
                return;
            }
            
            $this->logger->info('EnhanceOrderExport: File content length: ' . strlen($content));
            
            // Remove BOM if present
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
            
            $lines = explode("\n", $content);
            if (empty($lines) || empty(trim($lines[0]))) {
                $this->logger->error('EnhanceOrderExport: No valid lines found in file');
                return;
            }
            
            $this->logger->info('EnhanceOrderExport: Found ' . count($lines) . ' lines in file');
            $this->logger->info('EnhanceOrderExport: First line (header): ' . substr($lines[0], 0, 200));

            // Process and organize the CSV data
            $this->processOrderData($lines, $directory, $fullPath);
            
        } catch (\Exception $e) {
            $this->logger->error('Error enhancing order export: ' . $e->getMessage());
            $this->logger->error('Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Find valid file path
     */
    protected function findValidFilePath($directory, $filePath)
    {
        $paths = [
            $filePath,
            'export/' . basename($filePath),
            'tmp/' . basename($filePath)
        ];

        foreach ($paths as $path) {
            if ($directory->isExist($path)) {
                return $path;
            }
        }

        return null;
    }
    
    /**
     * Process and organize order data
     */
    protected function processOrderData($lines, $directory, $fullPath)
    {
        $this->logger->info('ProcessOrderData: Starting with ' . count($lines) . ' lines');
        
        $header = $this->parseCsvLine($lines[0]);
        $this->logger->info('ProcessOrderData: Header parsed - ' . implode('|', $header));
        $expectedColumns = count($header);
        
        // Map headers to required columns
        $columnMapping = $this->mapColumnsToRequired($header);
        $this->logger->info('ProcessOrderData: Column mapping - ' . json_encode($columnMapping));
        
        if (empty($columnMapping)) {
            $this->logger->warning('ProcessOrderData: No column mapping found, fixing encoding only');
            $this->fixEncodingOnly($lines, $directory, $fullPath);
            return;
        }
        
        // Process all data without consolidation first
        $processedData = [];
        $skippedRows = 0;
        $multilineBuffer = '';
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                $this->logger->debug('ProcessOrderData: Skipping empty line ' . $i);
                continue;
            }
            
            // Handle potential multiline records
            $fullLine = $multilineBuffer . $line;
            $row = $this->parseCsvLine($fullLine);
            
            $this->logger->debug('ProcessOrderData: Line ' . $i . ' parsed into ' . count($row) . ' columns, expected ' . $expectedColumns);
            
            if (count($row) < $expectedColumns) {
                // This might be a multiline record, buffer it
                $multilineBuffer = $fullLine . "\n";
                $this->logger->debug('ProcessOrderData: Buffering line ' . $i . ' for multiline processing');
                continue;
            } elseif (count($row) > $expectedColumns) {
                // Too many columns, try to merge excess columns
                $this->logger->warning('ProcessOrderData: Line ' . $i . ' has too many columns (' . count($row) . '), attempting to merge');
                
                // Merge excess columns into the last expected column
                $mergedRow = array_slice($row, 0, $expectedColumns - 1);
                $lastColumn = implode(' ', array_slice($row, $expectedColumns - 1));
                $mergedRow[] = $lastColumn;
                $row = $mergedRow;
            }
            
            // Reset buffer since we have a complete row
            $multilineBuffer = '';
            
            if (count($row) !== $expectedColumns) {
                $skippedRows++;
                $this->logger->warning('ProcessOrderData: Skipping line ' . $i . ' - column count mismatch. Got ' . count($row) . ', expected ' . $expectedColumns);
                $this->logger->debug('ProcessOrderData: Problematic line content: ' . substr($line, 0, 200));
                continue;
            }
            
            $processedRow = $this->processRow($row, $header, $columnMapping);
            if (!empty($processedRow)) {
                $processedData[] = $processedRow;
                $this->logger->debug('ProcessOrderData: Successfully processed row ' . $i);
            } else {
                $this->logger->warning('ProcessOrderData: Row ' . $i . ' resulted in empty processed data');
            }
        }
        
        $this->logger->info('ProcessOrderData: Processed ' . count($processedData) . ' orders, skipped ' . $skippedRows . ' rows');
        
        // Create enhanced CSV
        $this->createEnhancedCsv($processedData, array_keys($columnMapping), $directory, $fullPath);
    }

    /**
     * Process individual row
     */
    protected function processRow($row, $header, $columnMapping)
    {
        $this->logger->debug('ProcessRow: Starting with ' . count($row) . ' fields');
        
        $processedRow = [];
        
        foreach ($columnMapping as $displayName => $originalIndex) {
            $value = '';
            
            if ($originalIndex !== null && isset($row[$originalIndex])) {
                $value = $row[$originalIndex];
                $this->logger->debug('ProcessRow: ' . $displayName . ' = "' . substr($value, 0, 50) . '"');
            } else {
                $this->logger->debug('ProcessRow: ' . $displayName . ' - no data found (index: ' . $originalIndex . ')');
            }
            
            // Special handling for certain fields
            $value = $this->processFieldValue($displayName, $value, $row, $header);
            
            $processedRow[] = $value;
        }
        
        $this->logger->debug('ProcessRow: Completed with ' . count($processedRow) . ' processed fields');
        
        return $processedRow;
    }

    /**
     * Process individual field value - Enhanced for Order Comments and Alternative Phone
     */
    protected function processFieldValue($displayName, $value, $row, $header)
    {
        switch ($displayName) {
            case 'Order ID':
                // Ensure we get the increment_id (order number)
                if (empty($value)) {
                    $value = $this->getOrderIncrementId($row, $header);
                }
                break;
                
            case 'Order Name':
                // Try to construct name if empty
                if (empty($value)) {
                    $value = $this->constructCustomerName($row, $header);
                }
                break;
                
            case 'Phone Number':
                // Clean phone number
                $value = $this->cleanPhoneNumber($value);
                break;
                
            case 'Alternative Phone':
                // Extract from amasty custom fields if empty
                if (empty($value)) {
                    $value = $this->getAmastyCustomField($row, $header, 'custom_field_1');
                }
                break;
                
            case 'Order Comments':
                // Extract from amasty custom fields if empty
                if (empty($value)) {
                    $value = $this->getAmastyCustomField($row, $header, 'custom_field_2');
                }
                // Special handling for long multiline comments
                $value = $this->processOrderComments($value);
                break;
                
            case 'Item Details':
                // Ensure item details are properly formatted
                if (empty($value)) {
                    $value = $this->constructItemDetails($row, $header);
                }
                break;
        }
        
        return $this->cleanFieldValue($value);
    }
    
    /**
     * Get Amasty custom field value from database
     */
    protected function getAmastyCustomField($row, $header, $fieldName)
    {
        try {
            // Get order ID
            $orderId = $this->getOrderIncrementId($row, $header);
            if (empty($orderId)) {
                return '';
            }
            
            $this->logger->info('GetAmastyCustomField: Looking for field "' . $fieldName . '" for order: ' . $orderId);
            
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->get('\Magento\Framework\App\ResourceConnection');
            $connection = $resource->getConnection();
            
            // Get quote_id from sales_order
            $quoteIdQuery = $connection->select()
                ->from(['so' => $resource->getTableName('sales_order')], ['quote_id'])
                ->where('so.increment_id = ?', $orderId)
                ->orWhere('so.entity_id = ?', $orderId);
                
            $quoteId = $connection->fetchOne($quoteIdQuery);
            
            if (!$quoteId) {
                $this->logger->warning('GetAmastyCustomField: No quote_id found for order: ' . $orderId);
                return '';
            }
            
            $this->logger->info('GetAmastyCustomField: Found quote_id: ' . $quoteId . ' for order: ' . $orderId);
            
            // Get custom field value from amasty table
            $customFieldQuery = $connection->select()
                ->from(['acqcf' => $resource->getTableName('amasty_amcheckout_quote_custom_fields')], 
                       ['billing_value', 'shipping_value'])
                ->where('acqcf.quote_id = ?', $quoteId)
                ->where('acqcf.name = ?', $fieldName);
                
            $customFieldData = $connection->fetchRow($customFieldQuery);
            
            if ($customFieldData) {
                // Prefer shipping_value, fallback to billing_value
                $value = !empty($customFieldData['shipping_value']) ? 
                         $customFieldData['shipping_value'] : 
                         $customFieldData['billing_value'];
                         
                $this->logger->info('GetAmastyCustomField: Found value "' . $value . '" for field "' . $fieldName . '"');
                return $value;
            }
            
            $this->logger->info('GetAmastyCustomField: No data found for field "' . $fieldName . '" and quote_id: ' . $quoteId);
            return '';
            
        } catch (\Exception $e) {
            $this->logger->error('GetAmastyCustomField Error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get Order Increment ID
     */
    protected function getOrderIncrementId($row, $header)
    {
        $incrementIdFields = ['increment_id', 'Increment Id', 'Order ID'];
        
        foreach ($incrementIdFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && !empty($row[$index])) {
                return $row[$index];
            }
        }
        
        // Fallback to entity_id if increment_id not found
        $entityIdFields = ['entity_id', 'ID'];
        foreach ($entityIdFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && !empty($row[$index])) {
                return $row[$index];
            }
        }
        
        return '';
    }

    /**
     * Construct customer name from available data - Enhanced version
     */
    protected function constructCustomerName($row, $header)
    {
        // Priority: enhanced_customer_name > billing address > shipping address > customer data
        $directNameFields = ['enhanced_customer_name', 'order_name', 'full_customer_name'];
        $firstNameFields = ['billing_firstname', 'shipping_firstname', 'customer_firstname'];
        $lastNameFields = ['billing_lastname', 'shipping_lastname', 'customer_lastname'];
        
        // First, try to get direct name fields
        foreach ($directNameFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && isset($row[$index]) && !empty(trim($row[$index]))) {
                $name = trim($row[$index]);
                // Avoid Excel formula errors
                if (!str_starts_with($name, '#') && !str_starts_with($name, '=') && $name !== 'Guest Customer') {
                    return $name;
                }
            }
        }
        
        $firstName = '';
        $lastName = '';
        
        // Get first name with priority
        foreach ($firstNameFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && isset($row[$index]) && !empty(trim($row[$index]))) {
                $firstName = trim($row[$index]);
                break;
            }
        }
        
        // Get last name with priority
        foreach ($lastNameFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && isset($row[$index]) && !empty(trim($row[$index]))) {
                $lastName = trim($row[$index]);
                break;
            }
        }
        
        // Construct full name
        $fullName = trim($firstName . ' ' . $lastName);
        
        // Return proper name or fallback, avoid Excel errors
        if (!empty($fullName) && !str_starts_with($fullName, '#') && !str_starts_with($fullName, '=')) {
            return $fullName;
        }
        
        return 'Guest Customer';
    }

    /**
     * Construct item details if missing
     */
    protected function constructItemDetails($row, $header)
    {
        // Try to find product name and SKU fields
        $productFields = ['product_name', 'name', 'item_name'];
        $skuFields = ['sku', 'product_sku'];
        $qtyFields = ['qty_ordered', 'qty', 'quantity'];
        
        $productName = '';
        $sku = '';
        $qty = '';
        
        foreach ($productFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && !empty($row[$index])) {
                $productName = $row[$index];
                break;
            }
        }
        
        foreach ($skuFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && !empty($row[$index])) {
                $sku = $row[$index];
                break;
            }
        }
        
        foreach ($qtyFields as $field) {
            $index = array_search($field, $header);
            if ($index !== false && !empty($row[$index])) {
                $qty = $row[$index];
                break;
            }
        }
        
        if (!empty($productName) || !empty($sku)) {
            return sprintf(
                '%s (SKU: %s, Qty: %s)',
                $productName ?: 'Unknown Product',
                $sku ?: 'N/A',
                $qty ?: '1'
            );
        }
        
        return '';
    }

    /**
     * Clean phone number
     */
    protected function cleanPhoneNumber($phone)
    {
        if (empty($phone)) {
            return '';
        }
        
        // Remove non-numeric characters except + and spaces
        $phone = preg_replace('/[^\d+\s-]/', '', $phone);
        
        return trim($phone);
    }

    /**
     * Map original columns to required columns
     */
    protected function mapColumnsToRequired($header)
    {
        $mapping = [];
        
        foreach (self::REQUIRED_COLUMNS as $displayName => $possibleNames) {
            $found = false;
            foreach ($header as $index => $columnName) {
                $trimmedName = trim($columnName);
                if (in_array($trimmedName, $possibleNames)) {
                    $mapping[$displayName] = $index;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $mapping[$displayName] = null;
            }
        }
        
        return $mapping;
    }

    /**
     * Clean field value - Enhanced for long text and comments
     */
    protected function cleanFieldValue($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        $value = (string)$value;
        
        // Ensure UTF-8 encoding
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'auto');
        }
        
        // Handle multiline content - replace line breaks with space
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        
        // Remove control characters
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // Remove excessive whitespace
        $value = preg_replace('/\s+/', ' ', $value);
        
        // Handle problematic characters for CSV - escape properly
        $value = str_replace(['"'], ['\"'], $value);
        
        // Prevent Excel formula errors
        if (str_starts_with($value, '=') || str_starts_with($value, '#')) {
            $value = "'" . $value;
        }
        
        return trim($value);
    }

    /**
     * Create enhanced CSV with proper structure
     */
    protected function createEnhancedCsv($data, $headers, $directory, $fullPath)
    {
        $csvLines = [];
        
        // Create header
        $csvLines[] = $this->createCsvLine($headers);
        
        // Process each row
        foreach ($data as $row) {
            $csvLines[] = $this->createCsvLine($row);
        }
        
        // Write enhanced CSV
        $csvContent = implode("\n", $csvLines);
        $csvContent = "\xEF\xBB\xBF" . $csvContent; // Add BOM for UTF-8
        
        $directory->writeFile($fullPath, $csvContent);
        
        $this->logger->info('Successfully created enhanced CSV with ' . count($data) . ' orders');
    }

    /**
     * Fix encoding without column filtering
     */
    protected function fixEncodingOnly($lines, $directory, $fullPath)
    {
        $csvLines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $row = $this->parseCsvLine($line);
            $csvLines[] = $this->createCsvLine($row);
        }
        
        $csvContent = implode("\n", $csvLines);
        $csvContent = "\xEF\xBB\xBF" . $csvContent;
        
        $directory->writeFile($fullPath, $csvContent);
    }
    
    /**
     * Parse CSV line properly - Enhanced for multiline content and complex comments
     */
    private function parseCsvLine($line)
    {
        $this->logger->debug('ParseCsvLine: Processing line of length ' . strlen($line));
        
        // First, try standard parsing
        $result = str_getcsv($line, ',', '"', '\\');
        
        // If we get unexpected results, try alternative parsing
        if (count($result) == 1 && strpos($line, ',') !== false) {
            $this->logger->debug('ParseCsvLine: Standard parsing failed, trying manual parsing');
            
            // Manual CSV parsing for problematic lines
            $result = [];
            $current = '';
            $inQuotes = false;
            $length = strlen($line);
            
            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];
                
                if ($char === '"') {
                    if ($inQuotes && $i + 1 < $length && $line[$i + 1] === '"') {
                        // Escaped quote
                        $current .= '"';
                        $i++; // Skip next quote
                    } else {
                        // Toggle quote state
                        $inQuotes = !$inQuotes;
                    }
                } elseif ($char === ',' && !$inQuotes) {
                    // Field separator
                    $result[] = $current;
                    $current = '';
                } else {
                    $current .= $char;
                }
            }
            
            // Add the last field
            $result[] = $current;
        }
        
        $this->logger->debug('ParseCsvLine: Parsed into ' . count($result) . ' fields');
        
        // Clean each field to handle multiline content
        foreach ($result as $key => $value) {
            $originalLength = strlen($value);
            
            // Remove surrounding quotes if present
            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value)-1] === '"') {
                $value = substr($value, 1, -1);
            }
            
            // Replace line breaks with space to prevent CSV structure issues
            $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
            
            // Remove excessive whitespace
            $value = preg_replace('/\s+/', ' ', $value);
            
            // Handle escaped quotes
            $value = str_replace(['""'], ['"'], $value);
            
            $result[$key] = trim($value);
            
            if ($originalLength > 100) {
                $this->logger->debug('ParseCsvLine: Field ' . $key . ' was long (' . $originalLength . ' chars), cleaned to ' . strlen($result[$key]));
            }
        }
        
        return $result;
    }
    
    /**
     * Create CSV line with proper encoding - Enhanced for long text and comments
     */
    private function createCsvLine($row)
    {
        $csvRow = [];
        foreach ($row as $field) {
            $field = $this->cleanFieldValue($field);
            
            // Additional protection for very long fields
            if (strlen($field) > 2000) {
                $field = substr($field, 0, 1997) . '...';
            }
            
            // Escape quotes properly
            $field = str_replace('"', '""', $field);
            
            // Always wrap in quotes for safety with long text
            $csvRow[] = '"' . $field . '"';
        }
        return implode(',', $csvRow);
    }

    /**
     * Process Order Comments - Handle long multiline comments
     */
    protected function processOrderComments($comments)
    {
        if (empty($comments)) {
            return '';
        }
        
        // Convert to string if not already
        $comments = (string)$comments;
        
        // Handle multiline comments - replace line breaks with space
        $comments = str_replace(["\r\n", "\r", "\n"], ' ', $comments);
        
        // Remove excessive whitespace
        $comments = preg_replace('/\s+/', ' ', $comments);
        
        // Trim the result
        $comments = trim($comments);
        
        // Remove any problematic characters that might break CSV
        $comments = str_replace(['"', "'"], ['\"', "\'"], $comments);
        
        // Prevent Excel formula errors
        if (str_starts_with($comments, '=') || str_starts_with($comments, '#')) {
            $comments = "'" . $comments;
        }
        
        return $comments;
    }
}
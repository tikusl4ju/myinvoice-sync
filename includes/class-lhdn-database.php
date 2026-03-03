<?php
/**
 * LHDN Database Operations
 */

if (!defined('ABSPATH')) exit;

class LHDN_Database {
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta("
            CREATE TABLE {$wpdb->prefix}lhdn_myinvoice (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                invoice_no VARCHAR(100) UNIQUE,
                order_id BIGINT,
                uuid VARCHAR(100),
                longid VARCHAR(100),
                item_class VARCHAR(100),
                document_hash CHAR(64),
                payload LONGTEXT,
                status VARCHAR(30),
                code VARCHAR(30),
                response LONGTEXT,
                retry_count INT DEFAULT 0,
                queue_status VARCHAR(50),
                queue_date DATETIME,
                refund_complete TINYINT(1) DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME,
                KEY idx_uuid (uuid),
                KEY idx_status_code (status, code),
                KEY idx_retry (retry_count),
                KEY idx_updated (updated_at),
                KEY idx_queue_status (queue_status)
            ) {$charset};
        ");

        dbDelta("
            CREATE TABLE {$wpdb->prefix}lhdn_tokens (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                access_token TEXT,
                expires_at DATETIME,
                created_at DATETIME
            ) {$charset};
        ");

        dbDelta("
            CREATE TABLE {$wpdb->prefix}lhdn_settings (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) UNIQUE,
                setting_value LONGTEXT,
                updated_at DATETIME
            ) {$charset};
        ");

        dbDelta("
            CREATE TABLE {$wpdb->prefix}lhdn_cert (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                pem_content LONGTEXT,
                organization VARCHAR(255),
                organization_identifier VARCHAR(255),
                cn VARCHAR(255),
                serial_number VARCHAR(255),
                email_address VARCHAR(255),
                issuer VARCHAR(500),
                valid_from DATETIME,
                valid_to DATETIME,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME,
                updated_at DATETIME
            ) {$charset};
        ");

        // Ensure latest schema (columns + indexes) on existing installs
        self::check_and_update_table_structure();
        self::check_cert_table_structure();
    }

    /**
     * Check and update certificate table structure
     */
    public static function check_cert_table_structure() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'lhdn_cert';

        // Use dbDelta with a full CREATE TABLE statement so that WordPress
        // safely creates or updates the table structure without manual DDL.
        dbDelta("
            CREATE TABLE {$table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                pem_content LONGTEXT,
                organization VARCHAR(255),
                organization_identifier VARCHAR(255),
                cn VARCHAR(255),
                serial_number VARCHAR(255),
                email_address VARCHAR(255),
                issuer VARCHAR(500),
                valid_from DATETIME,
                valid_to DATETIME,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME,
                updated_at DATETIME
            ) {$charset};
        ");
    }

    /**
     * Check and update table structure to ensure all columns exist
     */
    public static function check_and_update_table_structure() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'lhdn_myinvoice';

        // Use dbDelta with a full CREATE TABLE statement so that WordPress
        // safely creates or updates the table structure (including indexes)
        // without manual DDL or string-concatenated SQL.
        dbDelta("
            CREATE TABLE {$table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                invoice_no VARCHAR(100) UNIQUE,
                order_id BIGINT,
                uuid VARCHAR(100),
                longid VARCHAR(100),
                item_class VARCHAR(100),
                document_hash CHAR(64),
                payload LONGTEXT,
                status VARCHAR(30),
                code VARCHAR(30),
                response LONGTEXT,
                retry_count INT DEFAULT 0,
                queue_status VARCHAR(50),
                queue_date DATETIME,
                refund_complete TINYINT(1) DEFAULT 0,
                created_at DATETIME,
                updated_at DATETIME,
                KEY idx_uuid (uuid),
                KEY idx_status_code (status, code),
                KEY idx_retry (retry_count),
                KEY idx_updated (updated_at),
                KEY idx_queue_status (queue_status)
            ) {$charset};
        ");
    }

    /**
     * Get invoice by invoice number
     */
    public static function get_invoice_by_number($invoice_no) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lhdn_myinvoice WHERE invoice_no = %s LIMIT 1",
                $invoice_no
            )
        );
    }

    /**
     * Get invoice by UUID
     */
    public static function get_invoice_by_uuid($uuid) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lhdn_myinvoice WHERE uuid = %s LIMIT 1",
                $uuid
            )
        );
    }

    /**
     * Save invoice
     */
    public static function save_invoice($data) {
        global $wpdb;
        
        $defaults = [
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        
        $data = array_merge($defaults, $data);
        
        return $wpdb->replace(
            "{$wpdb->prefix}lhdn_myinvoice",
            $data
        );
    }

    /**
     * Update invoice
     */
    public static function update_invoice($invoice_no, $data) {
        global $wpdb;
        
        $data['updated_at'] = current_time('mysql');
        
        return $wpdb->update(
            "{$wpdb->prefix}lhdn_myinvoice",
            $data,
            ['invoice_no' => $invoice_no]
        );
    }

    /**
     * Update invoice by UUID
     */
    public static function update_invoice_by_uuid($uuid, $data) {
        global $wpdb;
        
        $data['updated_at'] = current_time('mysql');
        
        return $wpdb->update(
            "{$wpdb->prefix}lhdn_myinvoice",
            $data,
            ['uuid' => $uuid]
        );
    }

    /**
     * Validate database structure - check all tables and columns
     * 
     * @return array Array with 'valid' boolean and 'issues' array
     */
    public static function validate_database_structure() {
        global $wpdb;
        
        $issues = [];
        $all_valid = true;
        
        // Define required tables
        $required_tables = [
            'lhdn_myinvoice' => [
                'id', 'invoice_no', 'order_id', 'uuid', 'longid', 'item_class', 
                'document_hash', 'payload', 'status', 'code', 'response', 
                'retry_count', 'queue_status', 'queue_date', 'refund_complete', 
                'created_at', 'updated_at'
            ],
            'lhdn_tokens' => [
                'id', 'access_token', 'expires_at', 'created_at'
            ],
            'lhdn_settings' => [
                'id', 'setting_key', 'setting_value', 'updated_at'
            ],
            'lhdn_cert' => [
                'id', 'pem_content', 'organization', 'organization_identifier', 
                'cn', 'serial_number', 'email_address', 'issuer', 'valid_from', 
                'valid_to', 'is_active', 'created_at', 'updated_at'
            ],
        ];
        
        // Check each table
        foreach ($required_tables as $table_name => $required_columns) {
            $full_table_name = $wpdb->prefix . $table_name;
            
            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $full_table_name
            )) === $full_table_name;
            
            if (!$table_exists) {
                $issues[] = [
                    'type' => 'table_missing',
                    'table' => $table_name,
                    'message' => "Table '{$table_name}' does not exist"
                ];
                $all_valid = false;
                continue;
            }
            
            // Get existing columns
            // Table name comes from $wpdb->prefix (WordPress core, trusted)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // DESCRIBE is a DDL statement - table name from $wpdb->prefix is safe
            $existing_columns = $wpdb->get_col("DESCRIBE {$full_table_name}");
            
            // Check each required column
            foreach ($required_columns as $column_name) {
                if (!in_array($column_name, $existing_columns)) {
                    $issues[] = [
                        'type' => 'column_missing',
                        'table' => $table_name,
                        'column' => $column_name,
                        'message' => "Column '{$column_name}' is missing in table '{$table_name}'"
                    ];
                    $all_valid = false;
                }
            }
        }
        
        return [
            'valid' => $all_valid && empty($issues),
            'issues' => $issues
        ];
    }
}


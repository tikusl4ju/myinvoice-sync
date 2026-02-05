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
                updated_at DATETIME
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

        $table = $wpdb->prefix . 'lhdn_myinvoice';
        self::add_index_if_not_exists($table, 'idx_uuid', 'uuid');
        self::add_index_if_not_exists($table, 'idx_status_code', 'status, code');
        self::add_index_if_not_exists($table, 'idx_retry', 'retry_count');
        self::add_index_if_not_exists($table, 'idx_updated', 'updated_at');
        self::add_index_if_not_exists($table, 'idx_queue_status', 'queue_status');
        
        // Check and update table structure
        self::check_and_update_table_structure();
        
        // Check certificate table structure
        self::check_cert_table_structure();
    }

    /**
     * Check and update certificate table structure
     */
    public static function check_cert_table_structure() {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_cert';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        
        if (!$table_exists) {
            return;
        }
        
        try {
            // Get existing columns
            // Table name comes from $wpdb->prefix (WordPress core, trusted)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // DESCRIBE is a DDL statement - table name from $wpdb->prefix is safe
            $existing_columns = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
            
            if (empty($existing_columns)) {
                return;
            }
            
            $existing_column_names = array_column($existing_columns, 'Field');
            
            // Define required columns
            $required_columns = [
                'pem_content' => [
                    'type' => 'LONGTEXT',
                    'after' => 'id',
                    'null' => 'YES',
                ],
                'organization' => [
                    'type' => 'VARCHAR(255)',
                    'after' => 'pem_content',
                    'null' => 'YES',
                ],
                'organization_identifier' => [
                    'type' => 'VARCHAR(255)',
                    'after' => 'organization',
                    'null' => 'YES',
                ],
                'cn' => [
                    'type' => 'VARCHAR(255)',
                    'after' => 'organization_identifier',
                    'null' => 'YES',
                ],
                'serial_number' => [
                    'type' => 'VARCHAR(255)',
                    'after' => 'cn',
                    'null' => 'YES',
                ],
                'email_address' => [
                    'type' => 'VARCHAR(255)',
                    'after' => 'serial_number',
                    'null' => 'YES',
                ],
                'issuer' => [
                    'type' => 'VARCHAR(500)',
                    'after' => 'email_address',
                    'null' => 'YES',
                ],
                'valid_from' => [
                    'type' => 'DATETIME',
                    'after' => 'issuer',
                    'null' => 'YES',
                ],
                'valid_to' => [
                    'type' => 'DATETIME',
                    'after' => 'valid_from',
                    'null' => 'YES',
                ],
                'is_active' => [
                    'type' => 'TINYINT(1)',
                    'extra' => 'DEFAULT 1',
                    'after' => 'valid_to',
                    'null' => 'YES',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'after' => 'is_active',
                    'null' => 'YES',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'after' => 'created_at',
                    'null' => 'YES',
                ],
            ];
            
            // Add missing columns
            $previous_column = null;
            foreach ($required_columns as $column_name => $column_def) {
                if (!in_array($column_name, $existing_column_names)) {
                    if ($column_def['after']) {
                        if (in_array($column_def['after'], $existing_column_names)) {
                            $after_clause = "AFTER `{$column_def['after']}`";
                        } elseif ($previous_column && in_array($previous_column, $existing_column_names)) {
                            $after_clause = "AFTER `{$previous_column}`";
                        } else {
                            $after_clause = '';
                        }
                    } else {
                        $after_clause = '';
                    }
                    
                    $null_clause = isset($column_def['null']) && $column_def['null'] === 'YES' ? 'NULL' : 'NOT NULL';
                    $extra_clause = !empty($column_def['extra']) ? $column_def['extra'] : '';
                    
                    // Whitelist: column name must be from hardcoded $required_columns (no user input)
                    if (!array_key_exists($column_name, $required_columns)) {
                        continue;
                    }
                    
                    // Validate column type contains only safe characters
                    $column_type = preg_match('/^[A-Za-z0-9_()\s,]+$/', $column_def['type']) ? $column_def['type'] : 'VARCHAR(255)';
                    
                    // Whitelist table: only our known table (no user input)
                    $allowed_tables = [ $wpdb->prefix . 'lhdn_cert' ];
                    if (!in_array($table, $allowed_tables, true)) {
                        continue;
                    }
                    
                    // Build AFTER clause from whitelist only: use value from allowed array, not from regex capture
                    $safe_after_sql = '';
                    $allowed_after_cols = array_merge($existing_column_names, array_keys($required_columns));
                    if (!empty($after_clause) && preg_match('/^AFTER\s+`([a-zA-Z0-9_]+)`$/', $after_clause, $matches)) {
                        foreach ($allowed_after_cols as $allowed_col) {
                            if ($allowed_col === $matches[1]) {
                                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $allowed_col is from whitelist (existing_column_names, required_columns)
                                $safe_after_sql = 'AFTER `' . $allowed_col . '`';
                                break;
                            }
                        }
                    }
                    
                    // Whitelist extra_clause for cert table: only known patterns
                    $safe_extra_sql = '';
                    if ($extra_clause === 'DEFAULT 1' || (preg_match('/^DEFAULT\s+[\'"0-9]+$/', $extra_clause))) {
                        $safe_extra_sql = $extra_clause;
                    }
                    
                    // Build query using whitelisted identifiers only (no esc_sql; wpdb::prepare cannot be used for DDL identifiers per WP docs)
                    $query = "ALTER TABLE `{$table}` ADD COLUMN `{$column_name}` {$column_type} {$null_clause}";
                    if (!empty($safe_extra_sql)) {
                        $query .= " {$safe_extra_sql}";
                    }
                    if (!empty($safe_after_sql)) {
                        $query .= " {$safe_after_sql}";
                    }
                    // DDL: identifiers whitelisted; wpdb::prepare() cannot be used for table/column names in ALTER TABLE (WP docs).
                    $result = $wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    
                    if ($result !== false) {
                        if (class_exists('LHDN_Logger')) {
                            LHDN_Logger::log("Added missing column {$column_name} to {$table} table");
                        }
                        $existing_column_names[] = $column_name;
                    }
                }
                $previous_column = $column_name;
            }
            
        } catch (Exception $e) {
            if (class_exists('LHDN_Logger')) {
                LHDN_Logger::log("Error checking certificate table structure: " . $e->getMessage());
            }
        }
    }

    /**
     * Check and update table structure to ensure all columns exist
     */
    public static function check_and_update_table_structure() {
        global $wpdb;
        $table = $wpdb->prefix . 'lhdn_myinvoice';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        
        if (!$table_exists) {
            // Table doesn't exist, dbDelta should have created it
            return;
        }
        
        try {
            // Get existing columns with their details
            // Table name comes from $wpdb->prefix (WordPress core, trusted)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // DESCRIBE is a DDL statement - table name from $wpdb->prefix is safe
            $existing_columns = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
            
            if (empty($existing_columns)) {
                // Error getting columns
                return;
            }
            
            // Create a simple array of existing column names
            $existing_column_names = array_column($existing_columns, 'Field');
            
            // Define ALL required columns with their definitions (in order)
            $required_columns = [
                'id' => [
                    'type' => 'BIGINT',
                    'extra' => 'AUTO_INCREMENT PRIMARY KEY',
                    'after' => null,
                    'null' => 'NO',
                ],
                'invoice_no' => [
                    'type' => 'VARCHAR(100)',
                    'extra' => 'UNIQUE',
                    'after' => 'id',
                    'null' => 'NO',
                ],
                'order_id' => [
                    'type' => 'BIGINT',
                    'extra' => '',
                    'after' => 'invoice_no',
                    'null' => 'YES',
                ],
                'uuid' => [
                    'type' => 'VARCHAR(100)',
                    'extra' => '',
                    'after' => 'order_id',
                    'null' => 'YES',
                ],
                'longid' => [
                    'type' => 'VARCHAR(100)',
                    'extra' => '',
                    'after' => 'uuid',
                    'null' => 'YES',
                ],
                'item_class' => [
                    'type' => 'VARCHAR(100)',
                    'extra' => '',
                    'after' => 'longid',
                    'null' => 'YES',
                ],
                'document_hash' => [
                    'type' => 'CHAR(64)',
                    'extra' => '',
                    'after' => 'item_class',
                    'null' => 'YES',
                ],
                'payload' => [
                    'type' => 'LONGTEXT',
                    'extra' => '',
                    'after' => 'document_hash',
                    'null' => 'YES',
                ],
                'status' => [
                    'type' => 'VARCHAR(30)',
                    'extra' => '',
                    'after' => 'payload',
                    'null' => 'YES',
                ],
                'code' => [
                    'type' => 'VARCHAR(30)',
                    'extra' => '',
                    'after' => 'status',
                    'null' => 'YES',
                ],
                'response' => [
                    'type' => 'LONGTEXT',
                    'extra' => '',
                    'after' => 'code',
                    'null' => 'YES',
                ],
                'retry_count' => [
                    'type' => 'INT',
                    'extra' => 'DEFAULT 0',
                    'after' => 'response',
                    'null' => 'YES',
                ],
                'queue_status' => [
                    'type' => 'VARCHAR(50)',
                    'extra' => '',
                    'after' => 'retry_count',
                    'null' => 'YES',
                ],
                'queue_date' => [
                    'type' => 'DATETIME',
                    'extra' => '',
                    'after' => 'queue_status',
                    'null' => 'YES',
                ],
                'refund_complete' => [
                    'type' => 'TINYINT(1)',
                    'extra' => 'DEFAULT 0',
                    'after' => 'queue_date',
                    'null' => 'NO',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'extra' => '',
                    'after' => 'refund_complete',
                    'null' => 'YES',
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'extra' => '',
                    'after' => 'created_at',
                    'null' => 'YES',
                ],
            ];
            
            // Add missing columns
            $previous_column = null;
            foreach ($required_columns as $column_name => $column_def) {
                if (!in_array($column_name, $existing_column_names)) {
                    // Skip id column if it doesn't exist - it should be created with the table
                    if ($column_name === 'id') {
                        if (class_exists('LHDN_Logger')) {
                            LHDN_Logger::log("Warning: id column missing from {$table} table. Table may need to be recreated.");
                        }
                        continue;
                    }
                    
                    // Determine the AFTER clause
                    if ($column_def['after']) {
                        // Check if the 'after' column exists
                        if (in_array($column_def['after'], $existing_column_names)) {
                            $after_clause = "AFTER `{$column_def['after']}`";
                        } elseif ($previous_column && in_array($previous_column, $existing_column_names)) {
                            $after_clause = "AFTER `{$previous_column}`";
                        } else {
                            $after_clause = '';
                        }
                    } else {
                        $after_clause = '';
                    }
                    
                    // Whitelist: column name must be from hardcoded $required_columns (no user input)
                    if (!array_key_exists($column_name, $required_columns)) {
                        continue;
                    }
                    
                    // Validate column type contains only safe characters
                    $column_type = preg_match('/^[A-Za-z0-9_()\s,]+$/', $column_def['type']) ? $column_def['type'] : 'VARCHAR(255)';
                    
                    $null_clause = isset($column_def['null']) && $column_def['null'] === 'YES' ? 'NULL' : 'NOT NULL';
                    $extra_clause = !empty($column_def['extra']) ? $column_def['extra'] : '';
                    
                    // Whitelist table: only our known table (no user input)
                    $allowed_tables_myinvoice = [ $wpdb->prefix . 'lhdn_myinvoice' ];
                    if (!in_array($table, $allowed_tables_myinvoice, true)) {
                        continue;
                    }
                    
                    // Build AFTER clause from whitelist only: use value from allowed array, not from regex capture
                    $safe_after_sql = '';
                    $allowed_after_cols = array_merge($existing_column_names, array_keys($required_columns));
                    if (!empty($after_clause) && preg_match('/^AFTER\s+`([a-zA-Z0-9_]+)`$/', $after_clause, $matches)) {
                        foreach ($allowed_after_cols as $allowed_col) {
                            if ($allowed_col === $matches[1]) {
                                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $allowed_col is from whitelist (existing_column_names, required_columns)
                                $safe_after_sql = 'AFTER `' . $allowed_col . '`';
                                break;
                            }
                        }
                    }
                    
                    // Whitelist extra_clause for myinvoice table: only known patterns
                    $safe_extra_sql = '';
                    $allowed_extra_myinvoice = [ 'AUTO_INCREMENT PRIMARY KEY', 'UNIQUE', 'DEFAULT 0', '' ];
                    if (in_array($extra_clause, $allowed_extra_myinvoice, true) || (strpos($extra_clause, 'DEFAULT') !== false && preg_match('/^DEFAULT\s+[\'"0-9]+$/', $extra_clause))) {
                        $safe_extra_sql = $extra_clause;
                    }
                    
                    // Build query using whitelisted identifiers only (no esc_sql; wpdb::prepare cannot be used for DDL identifiers per WP docs)
                    $query = "ALTER TABLE `{$table}` ADD COLUMN `{$column_name}` {$column_type} {$null_clause}";
                    if (!empty($safe_extra_sql)) {
                        $query .= " {$safe_extra_sql}";
                    }
                    if (!empty($safe_after_sql)) {
                        $query .= " {$safe_after_sql}";
                    }
                    // DDL: identifiers whitelisted; wpdb::prepare() cannot be used for table/column names in ALTER TABLE (WP docs).
                    $result = $wpdb->query($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    
                    if ($result !== false) {
                        if (class_exists('LHDN_Logger')) {
                            LHDN_Logger::log("Added missing column {$column_name} to {$table} table");
                        }
                        $existing_column_names[] = $column_name;
                        
                        if ($column_name === 'invoice_no' && strpos($extra_clause, 'UNIQUE') !== false) {
                            $unique_exists = $wpdb->get_var(
                                $wpdb->prepare(
                                    "SHOW INDEX FROM `{$table}` WHERE Column_name = %s AND Non_unique = 0",
                                    $column_name
                                )
                            );
                            if (!$unique_exists && array_key_exists($column_name, $required_columns)) {
                                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                                // DDL: $table and $column_name whitelisted above
                                $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE (`{$column_name}`)");
                            }
                        }
                    } else {
                        if (class_exists('LHDN_Logger')) {
                            LHDN_Logger::log("Failed to add column {$column_name} to {$table} table: " . $wpdb->last_error);
                        }
                    }
                }
                $previous_column = $column_name;
            }
            
            // Ensure all indexes exist
            self::add_index_if_not_exists($table, 'idx_uuid', 'uuid');
            self::add_index_if_not_exists($table, 'idx_status_code', 'status, code');
            self::add_index_if_not_exists($table, 'idx_retry', 'retry_count');
            self::add_index_if_not_exists($table, 'idx_updated', 'updated_at');
            self::add_index_if_not_exists($table, 'idx_queue_status', 'queue_status');
            
        } catch (Exception $e) {
            if (class_exists('LHDN_Logger')) {
                LHDN_Logger::log("Error checking table structure: " . $e->getMessage());
            }
        }
    }

    /**
     * Add index if not exists.
     * Uses whitelisting only (no esc_sql); table, index and column names must be from allowed lists.
     */
    public static function add_index_if_not_exists($table, $index, $columns) {
        global $wpdb;

        // Whitelist: only our known table (no user input)
        $allowed_tables = [ $wpdb->prefix . 'lhdn_myinvoice' ];
        if (!in_array($table, $allowed_tables, true)) {
            return;
        }

        // Whitelist: only known index/columns pairs (hardcoded; no user input)
        $allowed_indexes = [
            'idx_uuid'         => 'uuid',
            'idx_status_code'  => 'status, code',
            'idx_retry'        => 'retry_count',
            'idx_updated'      => 'updated_at',
            'idx_queue_status' => 'queue_status',
        ];
        if (!isset($allowed_indexes[ $index ]) || $allowed_indexes[ $index ] !== $columns) {
            return;
        }

        $allowed_column_names = [ 'uuid', 'status', 'code', 'retry_count', 'updated_at', 'queue_status' ];
        $column_list = array_map('trim', explode(',', $columns));
        $valid_columns = [];
        foreach ($column_list as $col) {
            if (in_array($col, $allowed_column_names, true)) {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $col is from whitelist $allowed_column_names
                $valid_columns[] = $col;
            }
        }
        if (empty($valid_columns)) {
            return;
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
                $index
            )
        );

        if (!$exists) {
            // Build columns list from whitelisted names only (no esc_sql)
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $valid_columns contains only whitelisted column names
            $columns_sql = implode(', ', $valid_columns);
            // DDL: table/index/columns from whitelist above; prepare() does not support identifier placeholders.
            $wpdb->query( "CREATE INDEX `{$index}` ON `{$table}` ({$columns_sql})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
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


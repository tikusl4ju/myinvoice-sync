<?php
/**
 * LHDN WooCommerce Integration
 */

if (!defined('ABSPATH')) exit;

class LHDN_WooCommerce {
    
    private $invoice;
    
    public function __construct() {
        $this->invoice = new LHDN_Invoice();
    }

    /**
     * Check if order uses wallet payment method
     */
    private function is_wallet_payment($order) {
        $payment_method = strtolower($order->get_payment_method());
        // Check if payment method contains 'wallet'
        return strpos($payment_method, 'wallet') !== false;
    }

    /**
     * Submit order when completed
     */
    public function submit_from_wc_order($order_id) {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (plugin inactive)");
            return;
        }
        
        if (!class_exists('WC_Order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order)) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (wallet payment method)");
            return;
        }

        // Check if already submitted by checking database status
        global $wpdb;
        $invoice_no = $order->get_order_number();
        $existing_invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}lhdn_myinvoice 
                 WHERE invoice_no = %s 
                 AND status IN ('submitted', 'valid', 'cancelled') 
                 LIMIT 1",
                $invoice_no
            )
        );
        
        if ($existing_invoice) {
            LHDN_Logger::log("WC Order {$order_id} already submitted to LHDN (status: {$existing_invoice->status})");
            return;
        }

        $billing_circle = LHDN_Settings::get('billing_circle', 'on_completed');

        // Handle "After X Days" - set queue status in database
        if (strpos($billing_circle, 'after_') === 0) {
            $days = (int) str_replace('after_', '', str_replace('_days', '', $billing_circle));
            if ($days > 0 && $days <= 7) {
                $dmy = wp_date('dmy'); // Day Month Year
                $queue_status = 'q' . $days . $dmy;
                $queue_date = current_time('mysql');
                
                // Check if invoice record already exists
                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}lhdn_myinvoice WHERE invoice_no = %s LIMIT 1",
                        $invoice_no
                    )
                );
                
                if ($existing) {
                    // Update existing record
                    $wpdb->update(
                        "{$wpdb->prefix}lhdn_myinvoice",
                        [
                            'order_id' => $order_id,
                            'queue_status' => $queue_status,
                            'queue_date' => $queue_date,
                            'status' => 'queued',
                            'updated_at' => current_time('mysql'),
                        ],
                        ['invoice_no' => $invoice_no]
                    );
                } else {
                    // Create new queued record
                    LHDN_Database::save_invoice([
                        'invoice_no' => $invoice_no,
                        'order_id' => $order_id,
                        'status' => 'queued',
                        'queue_status' => $queue_status,
                        'queue_date' => $queue_date,
                    ]);
                }
                
                LHDN_Logger::log("WC Order {$order_id} queued for submission after {$days} day(s) with status: {$queue_status}");
                return;
            }
        }

        // Handle "On Completed Order" - submit immediately
        if ($billing_circle === 'on_completed') {
            LHDN_Logger::log("WC Order completed > Submitting to LHDN ({$order_id})");

            $this->invoice->submit_wc_order($order);
        }
    }

    /**
     * Handle WooCommerce refunds by issuing a full credit note
     *
     * This will NOT create a second credit note if one already exists
     * (either created manually from the invoices list or from a previous refund).
     *
     * @param int $order_id
     * @param int $refund_id
     */
    public function handle_order_refunded($order_id, $refund_id) {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log("WC Order {$order_id} refund: plugin inactive, skipping credit note.");
            return;
        }

        if (!class_exists('WC_Order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        global $wpdb;
        $invoice_no = $order->get_order_number();

        // Ensure there is an original submitted/valid invoice
        $table = $wpdb->prefix . 'lhdn_myinvoice';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT status, uuid FROM {$table} WHERE invoice_no = %s LIMIT 1",
            $invoice_no
        ));

        if (!$original || !in_array($original->status, ['submitted', 'valid'], true) || empty($original->uuid)) {
            LHDN_Logger::log("WC Order {$order_id} refund: no submitted/valid invoice found for {$invoice_no}, skipping credit note.");
            return;
        }

        // Check if a credit note already exists (manual or previous auto)
        $cn_invoice_no = 'CN-' . $invoice_no;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_cn = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$table} WHERE invoice_no = %s LIMIT 1",
            $cn_invoice_no
        ));

        if ($existing_cn && in_array($existing_cn->status, ['submitted', 'valid', 'processing', 'retry'], true)) {
            LHDN_Logger::log("WC Order {$order_id} refund: credit note already exists for {$invoice_no}, skipping new credit note.");
            return;
        }

        // Create the full credit note
        $result = $this->invoice->create_credit_note_for_invoice($invoice_no);

        if (is_array($result)) {
            if ($result['success']) {
                LHDN_Logger::log("WC Order {$order_id} refund: credit note created successfully for {$invoice_no}.");
            } else {
                LHDN_Logger::log("WC Order {$order_id} refund: failed to create credit note for {$invoice_no} - {$result['message']}");
            }
        }
    }

    /**
     * Submit order when processing
     */
    public function submit_from_wc_order_processing($order_id) {
        if (!LHDN_Settings::is_plugin_active()) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (plugin inactive)");
            return;
        }
        
        if (!class_exists('WC_Order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order)) {
            LHDN_Logger::log("WC Order {$order_id} submission skipped (wallet payment method)");
            return;
        }

        // Check if already submitted by checking database status
        global $wpdb;
        $invoice_no = $order->get_order_number();
        $existing_invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}lhdn_myinvoice 
                 WHERE invoice_no = %s 
                 AND status IN ('submitted', 'valid', 'cancelled') 
                 LIMIT 1",
                $invoice_no
            )
        );
        
        if ($existing_invoice) {
            LHDN_Logger::log("WC Order {$order_id} already submitted to LHDN (status: {$existing_invoice->status})");
            return;
        }

        $billing_circle = LHDN_Settings::get('billing_circle', 'on_completed');

        // Only submit if billing circle is "On Processed Order"
        if ($billing_circle !== 'on_processed') {
            return;
        }

        LHDN_Logger::log("WC Order processing > Submitting to LHDN ({$order_id})");

        $this->invoice->submit_wc_order($order);
    }

    /**
     * Add bulk action to WooCommerce orders list
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['lhdn_submit_orders'] = __('Submit to LHDN', 'myinvoice-sync');
        return $bulk_actions;
    }

    /**
     * Handle bulk action submission
     */
    public function handle_bulk_action_submit($redirect_to, $action, $post_ids) {
        // Only process our custom action
        if ($action !== 'lhdn_submit_orders') {
            return $redirect_to;
        }

        // Check permissions
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'myinvoice-sync'));
        }
        
        // Check nonce
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'bulk-orders')) {
            wp_die(esc_html__('Security check failed.', 'myinvoice-sync'));
        }

        if (!LHDN_Settings::is_plugin_active()) {
            $redirect_to = add_query_arg('lhdn_bulk_error', urlencode(__('Plugin is inactive.', 'myinvoice-sync')), $redirect_to);
            return $redirect_to;
        }

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($post_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            
            if (!$order) {
                $skipped++;
                continue;
            }

            // Skip refunds
            if (is_a($order, 'WC_Order_Refund')) {
                $skipped++;
                continue;
            }

            // Skip wallet payment orders if setting is enabled
            if (LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order)) {
                $skipped++;
                continue;
            }

            // Check if already submitted
            global $wpdb;
            $invoice_no = $order->get_order_number();
            $existing_invoice = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}lhdn_myinvoice 
                     WHERE invoice_no = %s 
                     AND status IN ('submitted', 'valid', 'cancelled') 
                     LIMIT 1",
                    $invoice_no
                )
            );

            if ($existing_invoice) {
                $skipped++;
                continue;
            }

            // Submit the order
            try {
                $result = $this->invoice->submit_wc_order($order);
                if ($result !== false) {
                    $processed++;
                } else {
                    $errors++;
                }
            } catch (Exception $e) {
                LHDN_Logger::log("Bulk submit error for order #{$order_id}: " . $e->getMessage());
                $errors++;
            }

            // Small delay to prevent overload
            usleep(100000); // 0.1 second
        }

        // Add result message to redirect URL
        $redirect_to = add_query_arg([
            'lhdn_bulk_processed' => $processed,
            'lhdn_bulk_skipped' => $skipped,
            'lhdn_bulk_errors' => $errors,
        ], $redirect_to);

        return $redirect_to;
    }

    /**
     * Display bulk action result notices
     */
    public function display_bulk_action_notices() {
        if (!isset($_GET['lhdn_bulk_processed']) && !isset($_GET['lhdn_bulk_skipped']) && !isset($_GET['lhdn_bulk_errors']) && !isset($_GET['lhdn_bulk_error'])) {
            return;
        }

        $processed = isset($_GET['lhdn_bulk_processed']) ? (int) $_GET['lhdn_bulk_processed'] : 0;
        $skipped = isset($_GET['lhdn_bulk_skipped']) ? (int) $_GET['lhdn_bulk_skipped'] : 0;
        $errors = isset($_GET['lhdn_bulk_errors']) ? (int) $_GET['lhdn_bulk_errors'] : 0;
        $error_msg = isset($_GET['lhdn_bulk_error']) ? sanitize_text_field(wp_unslash($_GET['lhdn_bulk_error'])) : '';

        if ($error_msg) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error_msg) . '</p></div>';
            return;
        }

        $messages = [];
        if ($processed > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of orders */
                _n('%d order submitted to LHDN successfully.', '%d orders submitted to LHDN successfully.', $processed, 'myinvoice-sync'),
                $processed
            );
        }
        if ($skipped > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of orders */
                _n('%d order skipped (already submitted or invalid).', '%d orders skipped (already submitted or invalid).', $skipped, 'myinvoice-sync'),
                $skipped
            );
        }
        if ($errors > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of orders */
                _n('%d order failed to submit.', '%d orders failed to submit.', $errors, 'myinvoice-sync'),
                $errors
            );
        }

        if (!empty($messages)) {
            $notice_type = ($errors > 0) ? 'notice-warning' : 'notice-success';
            echo '<div class="notice ' . esc_attr($notice_type) . ' is-dismissible"><p>' . esc_html(implode(' ', $messages)) . '</p></div>';
        }
    }

    /**
     * Add LHDN column to orders list
     */
    public function add_order_column($columns) {
        $new = [];

        foreach ($columns as $key => $label) {
            $new[$key] = $label;

            if ($key === 'order_status') {
                $new['lhdn'] = __('LHDN MyInvois', 'myinvoice-sync');
            }
        }

        return $new;
    }

    /**
     * Display LHDN column content
     */
    public function orders_list_column_content($column, $order) {
        if ($column !== 'lhdn') {
            return;
        }

        global $wpdb;

        $invoice_no      = $order->get_order_number();
        $credit_note_no  = 'CN-' . $invoice_no;
        $refund_note_no  = 'RN-' . $invoice_no;

        // 1) If a Refund Note exists (RN-XXXX), show that first
        $rn_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uuid, longid, status, item_class 
                 FROM {$wpdb->prefix}lhdn_myinvoice
                 WHERE invoice_no = %s
                 LIMIT 1",
                $refund_note_no
            )
        );

        if ($rn_row && $rn_row->uuid && $rn_row->longid && defined('LHDN_HOST')) {
            $url = LHDN_HOST . '/' . $rn_row->uuid . '/share/' . $rn_row->longid;
            printf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($url),
                esc_html__('Refund Note', 'myinvoice-sync')
            );
            return;
        }

        // 2) If a Credit Note exists (CN-XXXX), show it and a Refund Note button
        $cn_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uuid, longid, status, item_class 
                 FROM {$wpdb->prefix}lhdn_myinvoice
                 WHERE invoice_no = %s
                 LIMIT 1",
                $credit_note_no
            )
        );

        if ($cn_row && $cn_row->uuid && $cn_row->longid && defined('LHDN_HOST')) {
            $url = LHDN_HOST . '/' . $cn_row->uuid . '/share/' . $cn_row->longid;
            printf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($url),
                esc_html__('Credit Note', 'myinvoice-sync')
            );

            // Show Refund Note button below the Credit Note link
            ?>
            <br>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=myinvoice-sync-invoices')); ?>" style="margin-top: 3px;">
                <?php wp_nonce_field('lhdn_refund_note_action', 'lhdn_refund_note_nonce'); ?>
                <input type="hidden" name="refund_note_invoice_no" value="<?php echo esc_attr($credit_note_no); ?>">
                <button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to create a refund note for this credit note? This will be submitted to LHDN.', 'myinvoice-sync')); ?>');">
                    <?php esc_html_e('Refund Note', 'myinvoice-sync'); ?>
                </button>
            </form>
            <?php
            return;
        }

        // 3) Fallback: normal invoice record
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uuid, longid, status, item_class, queue_status, queue_date
                 FROM {$wpdb->prefix}lhdn_myinvoice
                 WHERE invoice_no = %s
                 LIMIT 1",
                $invoice_no
            )
        );

        if (!$row) {
            $order_status = $order->get_status();
            
            // Get custom order statuses from settings
            $custom_statuses = LHDN_Settings::get('custom_order_statuses', '');
            $allowed_statuses = ['completed', 'processing'];
            if (!empty($custom_statuses)) {
                $custom = array_filter(array_map('trim', explode(',', strtolower($custom_statuses))));
                $allowed_statuses = array_merge($allowed_statuses, $custom);
            }
            
            // Check if wallet payment and exclude_wallet setting is enabled
            $is_wallet = LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order);
            
            if ($is_wallet) {
                // Show "Wallet Order" status instead of Process button
                echo '<small><em>Wallet Order</em></small>';
            } elseif (in_array($order_status, $allowed_statuses)) {
                // Only show Process button for allowed order statuses (non-wallet)
                $order_id = $order->get_id();
                $nonce = wp_create_nonce('lhdn_submit_order_' . $order_id);
                echo '<small><em>Not Submitted</em></small><br>';
                echo '<button type="button" class="button button-small lhdn-process-order-btn" ';
                echo 'data-order-id="' . esc_attr($order_id) . '" ';
                echo 'data-nonce="' . esc_attr($nonce) . '">';
                echo 'Process</button>';
                echo '<span class="lhdn-submit-status" style="margin-left: 5px;"></span>';
            } else {
                echo '<small><em>Not Submitted</em></small>';
            }
            return;
        }

        if($row->item_class === '004'){
          $item_class = "Consolidated";
        }else if($row->item_class === '008'){
          $item_class = "e-Commerce";
        }else{
          $item_class = $row->item_class;
        }

        if ($row->uuid && $row->longid) {
            $url = LHDN_HOST . '/' . $row->uuid . '/share/' . $row->longid;
            if ($row->status === 'cancelled') {
                $document_status = 'Cancelled';
            } elseif ($row->status === 'submitted') {
                $document_status = 'Processing';
            } elseif ($row->status === 'valid') {
                $document_status = 'Submitted';
            } else {
                // For other statuses, show the original status or a generic message
                $document_status = ucfirst($row->status);
            }
            printf(
                '<a href="%s" target="_blank">' . esc_html($document_status) . '</a> ' . '(' . esc_html($item_class) . ')',
                esc_url($url),
                esc_html($row->longid)
            );
        } elseif ($row->uuid) {
            echo '<small><em>Processing ' . '(' . esc_html($item_class) . ')</em></small>';
        } elseif ($row->status === 'retry') {
            echo '<small><em>Retrying ' . '(' . esc_html($item_class) . ')</em></small>';
        } elseif ($row->status == "queued") {
            // Check if wallet payment and exclude_wallet setting is enabled
            $is_wallet = LHDN_Settings::get('exclude_wallet', '1') === '1' && $this->is_wallet_payment($order);
            
            if ($is_wallet) {
                // Show "Wallet Order" status instead of Process button
                echo '<small><em>Wallet Order</em></small>';
            } else {
                $day = preg_match('/^q(\d+)(\d{6})$/', $row->queue_status, $matches) ? $matches[1] : '';
                $remaining_days = '';
                if ($day && $row->queue_date) {
                    $queue_date = strtotime($row->queue_date);
                    $target_date = strtotime('+' . $day . ' days', $queue_date);
                    $current_date = current_time('timestamp');
                    $remaining = ceil(($target_date - $current_date) / DAY_IN_SECONDS);
                    $remaining_days = max(0, $remaining); // Ensure non-negative
                }
                if ($remaining_days !== '') {
                    $days_text = ($remaining_days == 1) ? 'day' : 'days';
                    echo '<small><em>Queueing (' . esc_html($remaining_days) . ' ' . esc_html($days_text) . ')</em></small>';
                } else {
                    echo '<small><em>Queueing</em></small>';
                }
                
                $order_id = $order->get_id();
                $nonce = wp_create_nonce('lhdn_submit_order_' . $order_id);
                echo '<button type="button" class="button button-small lhdn-process-order-btn" ';
                echo 'data-order-id="' . esc_attr($order_id) . '" ';
                echo 'data-nonce="' . esc_attr($nonce) . '">';
                echo 'Process</button>';
                echo '<span class="lhdn-submit-status" style="margin-left: 5px;"></span>';
            }
        } else {
            echo '<small><em>Failed ' . '(' . esc_html($item_class) . ')</em></small>';
        }
    }

    /**
     * Add LHDN Receipt column to My Account orders table
     */
    public function add_my_account_order_column($columns) {
        // Check if Show Receipt setting is enabled
        if (LHDN_Settings::get('show_receipt', '1') !== '1') {
            return $columns;
        }

        $new_columns = [];
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            // Add LHDN Receipt column after order-status
            if ($key === 'order-status') {
                $new_columns['lhdn-receipt'] = __('MyInvois Receipt', 'myinvoice-sync');
            }
        }
        return $new_columns;
    }

    /**
     * Display LHDN Receipt column content in My Account orders table
     */
    public function my_account_order_column_content($order) {
        // Check if Show Receipt setting is enabled
        if (LHDN_Settings::get('show_receipt', '1') !== '1') {
            return;
        }

        if (!defined('LHDN_HOST')) {
            return;
        }

        global $wpdb;

        $invoice_no   = $order->get_order_number();
        $order_status = $order->get_status(); // e.g. 'completed', 'refunded'

        // If order is refunded, first try to show Refund Note, then Credit Note
        if ($order_status === 'refunded') {
            $rn_invoice_no = 'RN-' . $invoice_no;
            $cn_invoice_no = 'CN-' . $invoice_no;

            // Prefer Refund Note if it exists
            $rn_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT uuid, longid, status
                     FROM {$wpdb->prefix}lhdn_myinvoice
                     WHERE invoice_no = %s
                     LIMIT 1",
                    $rn_invoice_no
                )
            );

            if ($rn_row && $rn_row->uuid && $rn_row->longid) {
                $rn_url = LHDN_HOST . '/' . $rn_row->uuid . '/share/' . $rn_row->longid;
                printf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    esc_url($rn_url),
                    esc_html__('View Refund Note', 'myinvoice-sync')
                );
                return;
            }

            // Fallback to Credit Note if Refund Note not found
            $cn_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT uuid, longid, status
                     FROM {$wpdb->prefix}lhdn_myinvoice
                     WHERE invoice_no = %s
                     LIMIT 1",
                    $cn_invoice_no
                )
            );

            if ($cn_row && $cn_row->uuid && $cn_row->longid) {
                $cn_url = LHDN_HOST . '/' . $cn_row->uuid . '/share/' . $cn_row->longid;
                printf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    esc_url($cn_url),
                    esc_html__('View Credit Note', 'myinvoice-sync')
                );
                return;
            }
        }

        // Fallback: show original invoice receipt (for non-refunded orders or when no CN/RN exists)
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uuid, longid, status
                 FROM {$wpdb->prefix}lhdn_myinvoice
                 WHERE invoice_no = %s
                 LIMIT 1",
                $invoice_no
            )
        );

        if ($row && $row->uuid && $row->longid) {
            $url = LHDN_HOST . '/' . $row->uuid . '/share/' . $row->longid;
            printf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url($url),
                esc_html__('View Receipt', 'myinvoice-sync')
            );
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }

    /**
     * Validate TIN during checkout
     * Checks if TIN Enforce is enabled OR if cart total exceeds 10,000
     * and if user has valid TIN
     */
    public function validate_tin_on_checkout() {
        // Get billing country - TIN Enforce only applies to Malaysian users
        $billing_country = '';
        
        // Try to get from POST data first (checkout form submission)
        if (isset($_POST['billing_country']) && !empty($_POST['billing_country'])) {
            $billing_country = sanitize_text_field(wp_unslash($_POST['billing_country']));
        }
        
        // Fallback to WooCommerce customer object
        if (empty($billing_country) && function_exists('WC') && WC()->customer) {
            $billing_country = WC()->customer->get_billing_country();
        }
        
        // Fallback to user meta for logged-in users
        if (empty($billing_country) && is_user_logged_in()) {
            $user_id = get_current_user_id();
            if ($user_id) {
                $billing_country = get_user_meta($user_id, 'billing_country', true);
            }
        }
        
        // Only enforce TIN for Malaysian users (MY)
        // If country is set and not Malaysia, skip TIN enforcement
        if (!empty($billing_country) && $billing_country !== 'MY') {
            return;
        }
        
        // Check if TIN Enforce setting is enabled
        $tin_enforce_enabled = (LHDN_Settings::get('tin_enforce', '0') === '1');
        
        // Get cart total (subtotal + taxes + fees)
        $cart_total = 0;
        if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            // Get the numeric total value
            $cart_total = (float) WC()->cart->get_total('');
        }
        
        // Only check if TIN Enforce is enabled OR cart total exceeds 10,000
        if (!$tin_enforce_enabled && $cart_total <= 10000) {
            return;
        }

        // If user is not logged in, block checkout when enforcement applies
        if (!is_user_logged_in()) {
            if (!$tin_enforce_enabled && $cart_total > 10000) {
                // Guest, high-value purchase
                wc_add_notice(
                    __('Your purchase total exceeds 10,000 MYR. Please create an account and complete the TIN verification in your profile before checkout.', 'myinvoice-sync'),
                    'error'
                );
            } elseif ($tin_enforce_enabled) {
                // Global TIN enforcement, guest checkout
                wc_add_notice(
                    __('Please create an account and complete the TIN verification in your profile before checkout.', 'myinvoice-sync'),
                    'error'
                );
            }
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        // Check if user has valid TIN
        $tin_validation_status = get_user_meta($user_id, 'lhdn_tin_validation', true);
        
        if ($tin_validation_status !== 'valid') {
            // Choose message based on why enforcement is happening
            if (!$tin_enforce_enabled && $cart_total > 10000) {
                // Enforcement due to high cart total
                $message = __('Your purchase total exceeds 10,000 MYR. You need to complete the TIN verification in your profile before checkout.', 'myinvoice-sync');
            } else {
                // Enforcement due to global TIN Enforce setting
                $message = __('You would need to complete the TIN verification in your profile before checkout.', 'myinvoice-sync');
            }

            wc_add_notice($message, 'error');
        }
    }
}


<?php
/**
 * LHDN Invoice Handler
 */

if (!defined('ABSPATH')) exit;

class LHDN_Invoice {
    
    private $api;
    private $db;
    
    public function __construct() {
        $this->api = new LHDN_API();
        $this->db = new LHDN_Database();
    }

    /**
     * Force refresh token for cron jobs
     * This ensures a fresh token is obtained at the start of each cron run
     */
    public function force_refresh_token_for_cron() {
        return $this->api->get_token(true);
    }

    /**
     * Get buyer address with fallback: profile -> billing -> shipping
     * 
     * @param int $user_id User ID
     * @param WC_Order|null $order WooCommerce order object
     * @return array Address array with keys: line1, city, postcode, state_code, country
     */
    private function get_buyer_address($user_id, $order = null) {
        $address = [
            'line1'      => '',
            'city'       => '',
            'postcode'   => '',
            'state_code' => '',
            'country'    => '',
        ];

        // 1. Try profile address (user meta) first
        if ($user_id > 0) {
            $profile_line1 = trim(
                (get_user_meta($user_id, 'billing_address_1', true) ?: '') . ' ' .
                (get_user_meta($user_id, 'billing_address_2', true) ?: '')
            );
            $profile_city = get_user_meta($user_id, 'billing_city', true);
            $profile_postcode = get_user_meta($user_id, 'billing_postcode', true);
            $profile_state = get_user_meta($user_id, 'billing_state', true);
            $profile_country = get_user_meta($user_id, 'billing_country', true);

            if (!empty($profile_line1) || !empty($profile_city) || !empty($profile_postcode)) {
                $address['line1'] = $profile_line1;
                $address['city'] = $profile_city ?: $profile_country;
                $address['postcode'] = $profile_postcode;
                $address['state_code'] = LHDN_Helpers::wc_state_to_lhdn($profile_state);
                $address['country'] = LHDN_Helpers::country_iso2_to_iso3($profile_country);
                return $address;
            }
        }

        // 2. Fallback to billing address from order
        if ($order) {
            $billing_line1 = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
            $billing_city = $order->get_billing_city();
            $billing_postcode = $order->get_billing_postcode();
            $billing_state = $order->get_billing_state();
            $billing_country = $order->get_billing_country();

            if (!empty($billing_line1) || !empty($billing_city) || !empty($billing_postcode)) {
                $address['line1'] = $billing_line1;
                $address['city'] = $billing_city ?: $billing_country;
                $address['postcode'] = $billing_postcode;
                $address['state_code'] = LHDN_Helpers::wc_state_to_lhdn($billing_state);
                $address['country'] = LHDN_Helpers::country_iso2_to_iso3($billing_country);
                return $address;
            }
        }

        // 3. Fallback to shipping address from order
        if ($order) {
            $shipping_line1 = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());
            $shipping_city = $order->get_shipping_city();
            $shipping_postcode = $order->get_shipping_postcode();
            $shipping_state = $order->get_shipping_state();
            $shipping_country = $order->get_shipping_country();

            if (!empty($shipping_line1) || !empty($shipping_city) || !empty($shipping_postcode)) {
                $address['line1'] = $shipping_line1;
                $address['city'] = $shipping_city ?: $shipping_country;
                $address['postcode'] = $shipping_postcode;
                $address['state_code'] = LHDN_Helpers::wc_state_to_lhdn($shipping_state);
                $address['country'] = LHDN_Helpers::country_iso2_to_iso3($shipping_country);
                return $address;
            }
        }

        return $address;
    }

    /**
     * Clean and validate phone number for LHDN submission
     * Requirements: 8-20 characters, digits only (optional + at front), no spaces
     * 
     * @param string $phone Raw phone number
     * @return string Cleaned phone number or empty string if invalid
     */
    private function clean_phone_number($phone) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove all spaces, dashes, parentheses, and other non-digit characters except +
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));
        
        // Remove + if it's not at the beginning
        if (strpos($cleaned, '+') !== false && strpos($cleaned, '+') !== 0) {
            $cleaned = str_replace('+', '', $cleaned);
        }
        
        // Ensure + is only at the beginning if present
        if (strpos($cleaned, '+') !== false && strpos($cleaned, '+') !== 0) {
            $cleaned = '+' . str_replace('+', '', $cleaned);
        }
        
        // Validate length (8-20 characters)
        $length = mb_strlen($cleaned);
        if ($length < 8 || $length > 20) {
            return '';
        }
        
        // Validate format: optional + at front, then only digits
        if (!preg_match('/^\+?\d+$/', $cleaned)) {
            return '';
        }
        
        return $cleaned;
    }

    /**
     * Submit invoice
     */
    public function submit(array $args) {
        $invoiceNo = $args['invoice_no'];
        $itemClassificationCode = $args['item_classification_code'];
        $buyer     = $args['buyer'];
        $lines     = $args['lines'];
        $total     = $args['total'];
        $tax_amount = $args['tax_amount'];
        $seller_address = $args['seller_address'];
        $order_id = isset($args['order_id']) ? $args['order_id'] : null;

        // Optional: document type and reference data (used for credit notes)
        $document_type  = $args['document_type'] ?? 'invoice'; // 'invoice' or 'credit_note'
        $ref_invoice_no = $args['ref_invoice_no'] ?? null;
        $ref_uuid       = $args['ref_uuid'] ?? null;

        // Get tax category ID and industry classification code from settings
        $taxCategoryId = LHDN_Settings::get('tax_category_id', 'E');
        $industryClassificationCode = LHDN_Settings::get('industry_classification_code', '86909');

        // Build UBL
        $ubl = lhdn_invoice_doc_ubl($invoiceNo, [
            'document_type'            => $document_type,
            'ref_invoice_no'           => $ref_invoice_no,
            'ref_uuid'                 => $ref_uuid,
            'item_classification_code' => $itemClassificationCode,
            'tax_category_id' => $taxCategoryId,
            'industry_classification_code' => $industryClassificationCode,
            'buyer_tin'      => $buyer['tin'],
            'buyer_id_type'  => $buyer['id_type'],
            'buyer_id_value' => $buyer['id_value'],
            'buyer_name'     => $buyer['name'],
            'buyer_phone'    => $buyer['phone'],
            'buyer_email'    => $buyer['email'],
            'buyer_address'  => $buyer['address'],
            'seller_address' => $seller_address,
            'lines'          => $lines,
            'total'          => round($total, 2),
            'tax_amount'     => round($tax_amount, 2),
        ]);

        // Get UBL version from settings (allows dynamic switching)
        $ubl_version = LHDN_MyInvoice_Plugin::get_ubl_version();
        
        if ($ubl_version === '1.1') {
            // Get PEM from database only (no file fallback)
            $pem_content = LHDN_MyInvoice_Plugin::get_pem_from_database();
            if (!empty($pem_content)) {
                $ubl = lhdn_sign_invoice_v1_1_with_pem($ubl);
            } else {
                // No certificate found - this shouldn't happen if validation works, but log error
                LHDN_Logger::log('Error: UBL 1.1 selected but no PEM certificate found in database. Invoice will be unsigned.');
                // Force to unsigned format
                unset($ubl['Invoice'][0]['Signature']);
                unset($ubl['Invoice'][0]['UBLExtensions']);
                // Also force version back to 1.0 in settings to prevent future issues
                LHDN_Settings::set('ubl_version', '1.0');
            }
        } else {
            unset($ubl['Invoice'][0]['Signature']);
            unset($ubl['Invoice'][0]['UBLExtensions']);
        }

        $json = LHDN_Helpers::canonical_json($ubl);
        $hash = hash('sha256', $json);
        $b64  = base64_encode($json);

        LHDN_Logger::log("Submitting invoice {$invoiceNo}" . ($document_type === 'credit_note' ? ' (credit note)' : ''));

        // Preserve existing retry_count if this is a retry attempt
        global $wpdb;
        $existing_invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT retry_count FROM {$wpdb->prefix}lhdn_myinvoice WHERE invoice_no = %s LIMIT 1",
            $invoiceNo
        ));
        $preserved_retry_count = $existing_invoice ? (int) $existing_invoice->retry_count : 0;

        $save_data = [
            'invoice_no' => $invoiceNo,
            'item_class' => $itemClassificationCode,
            'document_hash' => $hash,
            'payload' => $json,
            'status' => 'processing',
            'retry_count' => $preserved_retry_count,
        ];
        
        if ($order_id !== null) {
            $save_data['order_id'] = $order_id;
        }
        
        $this->db::save_invoice($save_data);

        $payload = [
            "documents" => [[
                "format"       => "JSON",
                "documentHash" => $hash,
                "codeNumber"   => $invoiceNo,
                "document"     => $b64
            ]]
        ];

        $result = $this->api->submit_invoice($payload);
        
        if (!$result) {
            return false;
        }

        $data = $result['data'];
        $uuid = $data['acceptedDocuments'][0]['uuid'] ?? null;

        if ($uuid) {
            LHDN_Logger::log("UUID recorded: {$uuid}");

            $this->db::update_invoice($invoiceNo, [
                'uuid'        => $uuid,
                'response'    => $result['body'],
                'status'      => 'submitted',
                'code'        => $result['code'],
                'retry_count' => 0,
            ]);

            return $uuid;
        } else {
            $this->db::update_invoice($invoiceNo, [
                'uuid'       => $uuid,
                'response'   => $result['body'],
                'status'     => 'retry',
                'code'       => $result['code'],
            ]);

            LHDN_Logger::log("UUID missing - submission failed");
            return false;
        }
    }

    /**
     * Sync document status
     */
    public function sync_status($uuid) {
        $result = $this->api->get_document_status($uuid);
        
        if (!$result) {
            return;
        }

        $data = $result['data'];
        $status = strtolower($data['status'] ?? '');
        $allowed = ['valid','invalid','submitted','cancelled'];
        $status  = in_array($status, $allowed, true) ? $status : 'unknown';

        if (!$status) {
            LHDN_Logger::log("Status missing in response");
            return;
        }

        $longid = $data['longId'] ?? '';

        // Get invoice record to find invoice_no and check if order_id needs updating
        global $wpdb;
        $invoice_record = $wpdb->get_row($wpdb->prepare(
            "SELECT invoice_no, order_id FROM {$wpdb->prefix}lhdn_myinvoice WHERE uuid = %s LIMIT 1",
            $uuid
        ));

        $update_data = [
            'status'     => strtolower($status),
            'code'       => $result['code'],
            'response'   => $result['body'],
            'longid'     => $longid,
        ];

        // Update order_id if missing and invoice is not a test invoice
        // For test submissions, invoice_no may contain 'TEST-' in various positions (e.g. CN-TEST-123)
        if ($invoice_record && strpos($invoice_record->invoice_no, 'TEST-') === false) {
            // If order_id is missing or 0, try to find it from invoice_no
            if (empty($invoice_record->order_id) || $invoice_record->order_id == 0) {
                if (class_exists('WC_Order')) {
                    // Try to get order by invoice_no (order number), restricting to shop_order (exclude refunds)
                    $orders = wc_get_orders([
                        'limit'    => 1,
                        'orderby'  => 'date',
                        'order'    => 'DESC',
                        'type'     => 'shop_order',
                        'meta_query' => [
                            [
                                'key'     => '_order_number',
                                'value'   => $invoice_record->invoice_no,
                                'compare' => '=',
                            ],
                        ],
                    ]);

                    if (empty($orders)) {
                        // Try searching by get_order_number() method, again only across shop_order
                        $orders = wc_get_orders([
                            'limit'   => 100,
                            'orderby' => 'date',
                            'order'   => 'DESC',
                            'type'    => 'shop_order',
                        ]);
                        foreach ($orders as $test_order) {
                            // Skip refunds and ensure method exists
                            if (is_a($test_order, 'WC_Order_Refund')) {
                                continue;
                            }
                            if (method_exists($test_order, 'get_order_number') && $test_order->get_order_number() === $invoice_record->invoice_no) {
                                $update_data['order_id'] = $test_order->get_id();
                                break;
                            }
                        }
                    } else {
                        $order = $orders[0];
                        // Ensure it's not a refund
                        if ($order && !is_a($order, 'WC_Order_Refund')) {
                            $update_data['order_id'] = $order->get_id();
                        }
                    }
                }
            }
        }

        $this->db::update_invoice_by_uuid($uuid, $update_data);

        LHDN_Logger::log("Status updated > {$status}");
    }

    /**
     * Cancel document
     * 
     * @param string $uuid Document UUID
     * @return array|bool Returns array with 'success' and 'message' keys, or false on failure
     */
    public function cancel($uuid) {
        LHDN_Logger::log("Attempting cancel for {$uuid}");

        $result = $this->api->cancel_document($uuid);
        
        if (!$result || !$result['success']) {
            LHDN_Logger::log("Cancel rejected by LHDN");
            
            // Check for specific error: OperationPeriodOver
            $error_message = '';
            if (!empty($result['body'])) {
                $error_data = json_decode($result['body'], true);
                if (isset($error_data['error']['details']) && is_array($error_data['error']['details'])) {
                    foreach ($error_data['error']['details'] as $detail) {
                        if (isset($detail['code']) && $detail['code'] === 'OperationPeriodOver') {
                            $error_message = 'time_limit_exceeded';
                            break;
                        }
                    }
                }
            }
            
            return [
                'success' => false,
                'message' => $error_message
            ];
        }

        $this->db::update_invoice_by_uuid($uuid, [
            'status'     => 'cancelled',
            'code'       => $result['code'],
            'response'   => $result['body'],
        ]);

        LHDN_Logger::log("Document marked as cancelled locally");
        return [
            'success' => true,
            'message' => 'Document cancelled successfully'
        ];
    }

    /**
     * Submit WooCommerce order
     */
    public function submit_wc_order(WC_Order $order) {
        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1') {
            $payment_method = strtolower($order->get_payment_method());
            if (strpos($payment_method, 'wallet') !== false) {
                LHDN_Logger::log("WC Order #{$order->get_id()} submission skipped (wallet payment method)");
                return false;
            }
        }

        $user_id = $order->get_user_id();

        // Check if user marked as not Malaysian
        $not_malaysian = $user_id ? (get_user_meta($user_id, 'lhdn_not_malaysian', true) === '1') : false;
        $is_foreign = ($order->get_billing_country() !== 'MY') || $not_malaysian;
        $tin     = $is_foreign ? 'EI00000000020' : 'EI00000000010';
        $id_type = $is_foreign ? 'PASSPORT' : 'NRIC';
        $id_value = 'NA';

        $item_classification_code = $is_foreign ? '008' : '004';

        if ($user_id && !$not_malaysian) {
            $status = get_user_meta($user_id, 'lhdn_tin_validation', true);
            if($status === 'valid'){
                $tin      = get_user_meta($user_id, 'lhdn_tin', true) ?: $tin;
                $id_type  = get_user_meta($user_id, 'lhdn_id_type', true) ?: $id_type;
                $id_value = get_user_meta($user_id, 'lhdn_id_value', true) ?: $id_value;
                $item_classification_code = '008';
            }
        }

        $lines = [];
        $total = 0;
        $tax_amount = 0;
        $i = 1;

        foreach ($order->get_items() as $item) {
            $qty   = (float) $item->get_quantity();
            $price = (float) $item->get_total() / max($qty, 1);

            $lines[] = [
                'id'          => $i++,
                'qty'         => $qty,
                'unit_price'  => round($price, 2),
                'desc'        => $item->get_name(),
            ];

            $total += $item->get_total();
            $tax_amount += $item->get_total_tax();
        }

        foreach ($order->get_items('shipping') as $shipping) {
            $shippingTotal = (float) $shipping->get_total();

            if ($shippingTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($shippingTotal, 2),
                'desc'       => $shipping->get_name() ?: 'Shipping Fee',
            ];

            $total += $shippingTotal;
        }

        // Add order fees (e.g., COD fees, payment gateway fees)
        foreach ($order->get_fees() as $fee) {
            $feeTotal = (float) $fee->get_total();

            if ($feeTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($feeTotal, 2),
                'desc'       => $fee->get_name() ?: 'Fee',
            ];

            $total += $feeTotal;
            $tax_amount += $fee->get_total_tax();
        }

        // Get and clean phone number (billing first, fallback to shipping, then profile)
        $phone = $this->clean_phone_number($order->get_billing_phone());
        if (empty($phone)) {
            $phone = $this->clean_phone_number($order->get_shipping_phone());
        }
        if (empty($phone) && $user_id > 0) {
            $phone = $this->clean_phone_number(get_user_meta($user_id, 'billing_phone', true));
        }
        // If still empty, use a default placeholder (LHDN requires phone number)
        if (empty($phone)) {
            $phone = '60123456789'; // Default Malaysian phone number format
            LHDN_Logger::log("WC Order #{$order->get_id()}: Phone number missing, using default");
        }

        // Get buyer address with fallback: profile -> billing -> shipping
        $buyer_address = $this->get_buyer_address($user_id, $order);

        // Get buyer name (profile company/name, fallback to order billing)
        $buyer_name = '';
        if ($user_id > 0) {
            $buyer_name = get_user_meta($user_id, 'billing_company', true);
            if (empty($buyer_name)) {
                $first_name = get_user_meta($user_id, 'first_name', true);
                $last_name = get_user_meta($user_id, 'last_name', true);
                if ($first_name || $last_name) {
                    $buyer_name = trim($first_name . ' ' . $last_name);
                }
            }
        }
        if (empty($buyer_name)) {
            $buyer_name = $order->get_billing_company() ?: $order->get_formatted_billing_full_name();
        }

        // Get buyer email (profile first, fallback to order billing)
        $buyer_email = '';
        if ($user_id > 0) {
            $buyer_email = get_user_meta($user_id, 'billing_email', true);
            if (empty($buyer_email)) {
                $user = get_userdata($user_id);
                $buyer_email = $user ? $user->user_email : '';
            }
        }
        if (empty($buyer_email)) {
            $buyer_email = $order->get_billing_email();
        }

        return $this->submit([
            'invoice_no' => $order->get_order_number(),
            'order_id' => $order->get_id(),
            'item_classification_code' => $item_classification_code,
            'buyer' => [
                'tin'            => $tin,
                'id_type'        => $id_type,
                'id_value'       => $id_value,
                'name'           => $buyer_name,
                'phone'          => $phone,
                'email'          => $buyer_email,
                'address'        => $buyer_address,
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }

    /**
     * Submit test invoice
     */
    public function submit_test() {
        $invoiceNo = 'TEST-' . wp_date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $lines = [
            ['id'=>1,'qty'=>1,'unit_price'=>1100,'desc'=>'Consultation Service'],
            ['id'=>2,'qty'=>2,'unit_price'=>1234.50,'desc'=>'Follow-up Service'],
        ];

        $total = array_reduce($lines, fn($t,$l) => $t + ($l['qty'] * $l['unit_price']), 0);
        $tax_amount = 0;

        return $this->submit([
            'invoice_no' => $invoiceNo,
            'order_id' => 0, // Dummy order_id for test invoices
            'item_classification_code' => '004',
            'buyer' => [
                'tin'            => 'EI00000000010',
                'id_type'        => 'NRIC',
                'id_value'       => 'NA',
                'name'           => 'Test Buyer Name',
                'phone'          => '60123456789',
                'email'          => 'test_buyer@example.com',
                'address'        => [
                      'city'       => 'Kuala Lumpur',
                      'postcode'   => '50480',
                      'state_code' => LHDN_Helpers::wc_state_to_lhdn('WP'),
                      'line1'      => 'Lot 77, Jalan Test',
                      'country'    => 'MYS',
                ]
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }

    /**
     * Create a full credit note for an existing submitted invoice
     *
     * @param string $invoiceNo Original invoice number
     * @return array{success:bool,message:string}
     */
    public function create_credit_note_for_invoice($invoiceNo) {
        if (!LHDN_Settings::is_plugin_active()) {
            return [
                'success' => false,
                'message' => __('Plugin is inactive, cannot create credit note.', 'myinvoice-sync'),
            ];
        }

        global $wpdb;

        // Get original invoice record
        $table = $wpdb->prefix . 'lhdn_myinvoice';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE invoice_no = %s LIMIT 1",
            $invoiceNo
        ));

        if (!$original) {
            return [
                'success' => false,
                'message' => __('Original invoice record not found.', 'myinvoice-sync'),
            ];
        }

        if (!in_array($original->status, ['submitted', 'valid'], true) || empty($original->uuid)) {
            return [
                'success' => false,
                'message' => __('Credit note can only be issued for submitted/valid invoices with UUID.', 'myinvoice-sync'),
            ];
        }

        // Prevent duplicate credit notes: check if CN row already exists
        $cn_invoice_no = 'CN-' . $invoiceNo;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_cn = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$table} WHERE invoice_no = %s LIMIT 1",
            $cn_invoice_no
        ));

        if ($existing_cn && in_array($existing_cn->status, ['submitted', 'valid', 'processing', 'retry'], true)) {
            return [
                'success' => false,
                'message' => __('Credit note already exists or is being processed for this invoice.', 'myinvoice-sync'),
            ];
        }

        // Try to get data from WooCommerce order first (if available)
        $order_id = (int) $original->order_id;
        $order = null;
        $buyer_data = null;
        $lines = [];
        $total = 0;
        $tax_amount = 0;
        $item_classification_code = $original->item_class ?: '004';

        if ($order_id > 0 && class_exists('WC_Order')) {
            $order = wc_get_order($order_id);
        }

        if ($order) {
            // Build buyer details from WooCommerce order
            $user_id = $order->get_user_id();

            // Check if user marked as not Malaysian
            $not_malaysian = $user_id ? (get_user_meta($user_id, 'lhdn_not_malaysian', true) === '1') : false;
            $is_foreign = ($order->get_billing_country() !== 'MY') || $not_malaysian;
            $tin     = $is_foreign ? 'EI00000000020' : 'EI00000000010';
            $id_type = $is_foreign ? 'PASSPORT' : 'NRIC';
            $id_value = 'NA';

            $item_classification_code = $is_foreign ? '008' : '004';

            if ($user_id && !$not_malaysian) {
                $status = get_user_meta($user_id, 'lhdn_tin_validation', true);
                if ($status === 'valid') {
                    $tin      = get_user_meta($user_id, 'lhdn_tin', true) ?: $tin;
                    $id_type  = get_user_meta($user_id, 'lhdn_id_type', true) ?: $id_type;
                    $id_value = get_user_meta($user_id, 'lhdn_id_value', true) ?: $id_value;
                    $item_classification_code = '008';
                }
            }

            $i = 1;

            foreach ($order->get_items() as $item) {
                $qty   = (float) $item->get_quantity();
                $price = (float) $item->get_total() / max($qty, 1);

                $lines[] = [
                    'id'          => $i++,
                    'qty'         => $qty,
                    'unit_price'  => round($price, 2),
                    'desc'        => $item->get_name(),
                ];

                $total += $item->get_total();
                $tax_amount += $item->get_total_tax();
            }

            foreach ($order->get_items('shipping') as $shipping) {
                $shippingTotal = (float) $shipping->get_total();

                if ($shippingTotal <= 0) {
                    continue;
                }

                $lines[] = [
                    'id'         => $i++,
                    'qty'        => 1,
                    'unit_price' => round($shippingTotal, 2),
                    'desc'       => $shipping->get_name() ?: 'Shipping Fee',
                ];

                $total += $shippingTotal;
            }

            // Add order fees (e.g., COD fees, payment gateway fees)
            foreach ($order->get_fees() as $fee) {
                $feeTotal = (float) $fee->get_total();

                if ($feeTotal <= 0) {
                    continue;
                }

                $lines[] = [
                    'id'         => $i++,
                    'qty'        => 1,
                    'unit_price' => round($feeTotal, 2),
                    'desc'       => $fee->get_name() ?: 'Fee',
                ];

                $total += $feeTotal;
                $tax_amount += $fee->get_total_tax();
            }

            // Get and clean phone number (billing first, fallback to shipping, then profile)
            $phone = $this->clean_phone_number($order->get_billing_phone());
            if (empty($phone)) {
                $phone = $this->clean_phone_number($order->get_shipping_phone());
            }
            if (empty($phone) && $user_id > 0) {
                $phone = $this->clean_phone_number(get_user_meta($user_id, 'billing_phone', true));
            }
            if (empty($phone)) {
                $phone = '60123456789'; // Default Malaysian phone number format
                LHDN_Logger::log("WC Order #{$order->get_id()}: Phone number missing for credit note, using default");
            }

            // Get buyer address with fallback: profile -> billing -> shipping
            $buyer_address = $this->get_buyer_address($user_id, $order);

            // Get buyer name (profile company/name, fallback to order billing)
            $buyer_name = '';
            if ($user_id > 0) {
                $buyer_name = get_user_meta($user_id, 'billing_company', true);
                if (empty($buyer_name)) {
                    $first_name = get_user_meta($user_id, 'first_name', true);
                    $last_name = get_user_meta($user_id, 'last_name', true);
                    if ($first_name || $last_name) {
                        $buyer_name = trim($first_name . ' ' . $last_name);
                    }
                }
            }
            if (empty($buyer_name)) {
                $buyer_name = $order->get_billing_company() ?: $order->get_formatted_billing_full_name();
            }

            // Get buyer email (profile first, fallback to order billing)
            $buyer_email = '';
            if ($user_id > 0) {
                $buyer_email = get_user_meta($user_id, 'billing_email', true);
                if (empty($buyer_email)) {
                    $user = get_userdata($user_id);
                    $buyer_email = $user ? $user->user_email : '';
                }
            }
            if (empty($buyer_email)) {
                $buyer_email = $order->get_billing_email();
            }

            $buyer_data = [
                'tin'            => $tin,
                'id_type'        => $id_type,
                'id_value'       => $id_value,
                'name'           => $buyer_name,
                'phone'          => $phone,
                'email'          => $buyer_email,
                'address'        => $buyer_address,
            ];
        } else {
            // Fallback: Extract data from stored payload (for test invoices or invoices without WC order)
            if (empty($original->payload)) {
                return [
                    'success' => false,
                    'message' => __('Cannot create credit note: Original invoice data not available and WooCommerce order not found.', 'myinvoice-sync'),
                ];
            }

            // Decode the stored payload JSON
            $original_ubl = json_decode($original->payload, true);
            if (!$original_ubl || !isset($original_ubl['Invoice'][0])) {
                return [
                    'success' => false,
                    'message' => __('Cannot create credit note: Invalid original invoice data format.', 'myinvoice-sync'),
                ];
            }

            $inv = $original_ubl['Invoice'][0];

            // Extract buyer information from original invoice
            $customer_party = $inv['AccountingCustomerParty'][0]['Party'][0] ?? [];
            $party_id = $customer_party['PartyIdentification'] ?? [];
            $postal_addr = $customer_party['PostalAddress'][0] ?? [];
            $legal_entity = $customer_party['PartyLegalEntity'][0] ?? [];
            $contact = $customer_party['Contact'][0] ?? [];

            // Extract TIN and ID
            $tin = 'EI00000000010';
            $id_type = 'NRIC';
            $id_value = 'NA';
            foreach ($party_id as $pid) {
                $scheme = $pid['ID'][0]['schemeID'] ?? '';
                $value = $pid['ID'][0]['_'] ?? '';
                if ($scheme === 'TIN') {
                    $tin = $value;
                } elseif (in_array($scheme, ['NRIC', 'PASSPORT', 'BRN'], true)) {
                    $id_type = $scheme;
                    $id_value = $value;
                }
            }

            // Extract address
            $address_line = $postal_addr['AddressLine'][0]['Line'][0]['_'] ?? '';
            $city = $postal_addr['CityName'][0]['_'] ?? '';
            $postcode = $postal_addr['PostalZone'][0]['_'] ?? '';
            $state_code = $postal_addr['CountrySubentityCode'][0]['_'] ?? '';
            $country = $postal_addr['Country'][0]['IdentificationCode'][0]['_'] ?? 'MYS';

            // Extract contact info
            $phone = $contact['Telephone'][0]['_'] ?? '60123456789';
            $email = $contact['ElectronicMail'][0]['_'] ?? '';
            $name = $legal_entity['RegistrationName'][0]['_'] ?? '';

            $buyer_data = [
                'tin'            => $tin,
                'id_type'        => $id_type,
                'id_value'       => $id_value,
                'name'           => $name,
                'phone'          => $phone,
                'email'          => $email,
                'address'        => [
                    'city'       => $city,
                    'postcode'   => $postcode,
                    'state_code' => $state_code,
                    'line1'       => $address_line,
                    'country'    => $country,
                ]
            ];

            // Extract invoice lines from original invoice
            $invoice_lines = $inv['InvoiceLine'] ?? [];
            $i = 1;
            foreach ($invoice_lines as $line) {
                $qty = (float) ($line['InvoicedQuantity'][0]['_'] ?? 1);
                $price = (float) ($line['Price'][0]['PriceAmount'][0]['_'] ?? 0);
                $desc = $line['Item'][0]['Description'][0]['_'] ?? 'Item';

                $lines[] = [
                    'id'          => $i++,
                    'qty'         => $qty,
                    'unit_price'  => round($price, 2),
                    'desc'        => $desc,
                ];

                $total += ($qty * $price);
            }

            // Extract tax amount from TaxTotal
            $tax_total = $inv['TaxTotal'][0] ?? [];
            $tax_amount = (float) ($tax_total['TaxAmount'][0]['_'] ?? 0);

            // Extract total from LegalMonetaryTotal
            $monetary_total = $inv['LegalMonetaryTotal'][0] ?? [];
            $total = (float) ($monetary_total['PayableAmount'][0]['_'] ?? $total);
        }

        // Submit as a credit note document
        $result = $this->submit([
            'invoice_no'               => $cn_invoice_no,
            'order_id'                 => $order_id > 0 ? $order_id : null,
            'item_classification_code' => $item_classification_code,
            'document_type'            => 'credit_note',
            'ref_invoice_no'           => $invoiceNo,
            'ref_uuid'                 => $original->uuid,
            'buyer'                    => $buyer_data,
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines'      => $lines,
            'total'      => $total,
            'tax_amount' => $tax_amount,
        ]);

        if ($result === false) {
            return [
                'success' => false,
                'message' => __('Failed to submit credit note to LHDN.', 'myinvoice-sync'),
            ];
        }

        return [
            'success' => true,
            'message' => __('Credit note submitted successfully.', 'myinvoice-sync'),
        ];
    }

    /**
     * Create a refund note for an existing credit note (CN-*)
     *
     * @param string $creditNoteInvoiceNo Credit note invoice number (CN-XXXX)
     * @return array{success:bool,message:string}
     */
    public function create_refund_note_for_credit_note($creditNoteInvoiceNo) {
        if (!LHDN_Settings::is_plugin_active()) {
            return [
                'success' => false,
                'message' => __('Plugin is inactive, cannot create refund note.', 'myinvoice-sync'),
            ];
        }

        if (strpos($creditNoteInvoiceNo, 'CN-') !== 0) {
            return [
                'success' => false,
                'message' => __('Refund note can only be created from credit note records.', 'myinvoice-sync'),
            ];
        }

        $originalInvoiceNo = substr($creditNoteInvoiceNo, 3);

        global $wpdb;

        $table = $wpdb->prefix . 'lhdn_myinvoice';

        // Get original invoice record (the invoice that the credit note refers to)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE invoice_no = %s LIMIT 1",
            $originalInvoiceNo
        ));

        if (!$original) {
            return [
                'success' => false,
                'message' => __('Original invoice record not found for this credit note.', 'myinvoice-sync'),
            ];
        }

        if (!in_array($original->status, ['submitted', 'valid'], true) || empty($original->uuid)) {
            return [
                'success' => false,
                'message' => __('Refund note can only be issued for submitted/valid invoices with UUID.', 'myinvoice-sync'),
            ];
        }

        // Prevent duplicate refund notes: check if RN row already exists
        $rn_invoice_no = 'RN-' . $originalInvoiceNo;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_rn = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM {$table} WHERE invoice_no = %s LIMIT 1",
            $rn_invoice_no
        ));

        if ($existing_rn && in_array($existing_rn->status, ['submitted', 'valid', 'processing', 'retry'], true)) {
            return [
                'success' => false,
                'message' => __('Refund note already exists or is being processed for this invoice.', 'myinvoice-sync'),
            ];
        }

        // Reuse the same data building logic as credit note: create a full refund for all items
        $order_id = (int) $original->order_id;
        $order = null;
        $buyer_data = null;
        $lines = [];
        $total = 0;
        $tax_amount = 0;
        $item_classification_code = $original->item_class ?: '004';

        if ($order_id > 0 && class_exists('WC_Order')) {
            $order = wc_get_order($order_id);
        }

        if ($order) {
            // Build buyer details from WooCommerce order
            $user_id = $order->get_user_id();

            // Check if user marked as not Malaysian
            $not_malaysian = $user_id ? (get_user_meta($user_id, 'lhdn_not_malaysian', true) === '1') : false;
            $is_foreign = ($order->get_billing_country() !== 'MY') || $not_malaysian;
            $tin     = $is_foreign ? 'EI00000000020' : 'EI00000000010';
            $id_type = $is_foreign ? 'PASSPORT' : 'NRIC';
            $id_value = 'NA';

            $item_classification_code = $is_foreign ? '008' : '004';

            if ($user_id && !$not_malaysian) {
                $status = get_user_meta($user_id, 'lhdn_tin_validation', true);
                if ($status === 'valid') {
                    $tin      = get_user_meta($user_id, 'lhdn_tin', true) ?: $tin;
                    $id_type  = get_user_meta($user_id, 'lhdn_id_type', true) ?: $id_type;
                    $id_value = get_user_meta($user_id, 'lhdn_id_value', true) ?: $id_value;
                    $item_classification_code = '008';
                }
            }

            $i = 1;

            foreach ($order->get_items() as $item) {
                $qty   = (float) $item->get_quantity();
                $price = (float) $item->get_total() / max($qty, 1);

                $lines[] = [
                    'id'          => $i++,
                    'qty'         => $qty,
                    'unit_price'  => round($price, 2),
                    'desc'        => $item->get_name(),
                ];

                $total += $item->get_total();
                $tax_amount += $item->get_total_tax();
            }

            foreach ($order->get_items('shipping') as $shipping) {
                $shippingTotal = (float) $shipping->get_total();

                if ($shippingTotal <= 0) {
                    continue;
                }

                $lines[] = [
                    'id'         => $i++,
                    'qty'        => 1,
                    'unit_price' => round($shippingTotal, 2),
                    'desc'       => $shipping->get_name() ?: 'Shipping Fee',
                ];

                $total += $shippingTotal;
            }

            // Add order fees (e.g., COD fees, payment gateway fees)
            foreach ($order->get_fees() as $fee) {
                $feeTotal = (float) $fee->get_total();

                if ($feeTotal <= 0) {
                    continue;
                }

                $lines[] = [
                    'id'         => $i++,
                    'qty'        => 1,
                    'unit_price' => round($feeTotal, 2),
                    'desc'       => $fee->get_name() ?: 'Fee',
                ];

                $total += $feeTotal;
                $tax_amount += $fee->get_total_tax();
            }

            // Get and clean phone number (billing first, fallback to shipping, then profile)
            $phone = $this->clean_phone_number($order->get_billing_phone());
            if (empty($phone)) {
                $phone = $this->clean_phone_number($order->get_shipping_phone());
            }
            if (empty($phone) && $user_id > 0) {
                $phone = $this->clean_phone_number(get_user_meta($user_id, 'billing_phone', true));
            }
            if (empty($phone)) {
                $phone = '60123456789'; // Default Malaysian phone number format
                LHDN_Logger::log("WC Order #{$order->get_id()}: Phone number missing for refund note, using default");
            }

            // Get buyer address with fallback: profile -> billing -> shipping
            $buyer_address = $this->get_buyer_address($user_id, $order);

            // Get buyer name (profile company/name, fallback to order billing)
            $buyer_name = '';
            if ($user_id > 0) {
                $buyer_name = get_user_meta($user_id, 'billing_company', true);
                if (empty($buyer_name)) {
                    $first_name = get_user_meta($user_id, 'first_name', true);
                    $last_name = get_user_meta($user_id, 'last_name', true);
                    if ($first_name || $last_name) {
                        $buyer_name = trim($first_name . ' ' . $last_name);
                    }
                }
            }
            if (empty($buyer_name)) {
                $buyer_name = $order->get_billing_company() ?: $order->get_formatted_billing_full_name();
            }

            // Get buyer email (profile first, fallback to order billing)
            $buyer_email = '';
            if ($user_id > 0) {
                $buyer_email = get_user_meta($user_id, 'billing_email', true);
                if (empty($buyer_email)) {
                    $user = get_userdata($user_id);
                    $buyer_email = $user ? $user->user_email : '';
                }
            }
            if (empty($buyer_email)) {
                $buyer_email = $order->get_billing_email();
            }

            $buyer_data = [
                'tin'            => $tin,
                'id_type'        => $id_type,
                'id_value'       => $id_value,
                'name'           => $buyer_name,
                'phone'          => $phone,
                'email'          => $buyer_email,
                'address'        => $buyer_address,
            ];
        } else {
            // Fallback: Extract data from stored payload (for test invoices or invoices without WC order)
            if (empty($original->payload)) {
                return [
                    'success' => false,
                    'message' => __('Cannot create refund note: Original invoice data not available and WooCommerce order not found.', 'myinvoice-sync'),
                ];
            }

            // Decode the stored payload JSON
            $original_ubl = json_decode($original->payload, true);
            if (!$original_ubl || !isset($original_ubl['Invoice'][0])) {
                return [
                    'success' => false,
                    'message' => __('Cannot create refund note: Invalid original invoice data format.', 'myinvoice-sync'),
                ];
            }

            $inv = $original_ubl['Invoice'][0];

            // Extract buyer information from original invoice
            $customer_party = $inv['AccountingCustomerParty'][0]['Party'][0] ?? [];
            $party_id = $customer_party['PartyIdentification'] ?? [];
            $postal_addr = $customer_party['PostalAddress'][0] ?? [];
            $legal_entity = $customer_party['PartyLegalEntity'][0] ?? [];
            $contact = $customer_party['Contact'][0] ?? [];

            // Extract TIN and ID
            $tin = 'EI00000000010';
            $id_type = 'NRIC';
            $id_value = 'NA';
            foreach ($party_id as $pid) {
                $scheme = $pid['ID'][0]['schemeID'] ?? '';
                $value = $pid['ID'][0]['_'] ?? '';
                if ($scheme === 'TIN') {
                    $tin = $value;
                } elseif (in_array($scheme, ['NRIC', 'PASSPORT', 'BRN'], true)) {
                    $id_type = $scheme;
                    $id_value = $value;
                }
            }

            // Extract address
            $address_line = $postal_addr['AddressLine'][0]['Line'][0]['_'] ?? '';
            $city = $postal_addr['CityName'][0]['_'] ?? '';
            $postcode = $postal_addr['PostalZone'][0]['_'] ?? '';
            $state_code = $postal_addr['CountrySubentityCode'][0]['_'] ?? '';
            $country = $postal_addr['Country'][0]['IdentificationCode'][0]['_'] ?? 'MYS';

            // Extract contact info
            $phone = $contact['Telephone'][0]['_'] ?? '60123456789';
            $email = $contact['ElectronicMail'][0]['_'] ?? '';
            $name = $legal_entity['RegistrationName'][0]['_'] ?? '';

            $buyer_data = [
                'tin'            => $tin,
                'id_type'        => $id_type,
                'id_value'       => $id_value,
                'name'           => $name,
                'phone'          => $phone,
                'email'          => $email,
                'address'        => [
                    'city'       => $city,
                    'postcode'   => $postcode,
                    'state_code' => $state_code,
                    'line1'       => $address_line,
                    'country'    => $country,
                ]
            ];

            // Extract invoice lines from original invoice
            $invoice_lines = $inv['InvoiceLine'] ?? [];
            $i = 1;
            foreach ($invoice_lines as $line) {
                $qty = (float) ($line['InvoicedQuantity'][0]['_'] ?? 1);
                $price = (float) ($line['Price'][0]['PriceAmount'][0]['_'] ?? 0);
                $desc = $line['Item'][0]['Description'][0]['_'] ?? 'Item';

                $lines[] = [
                    'id'          => $i++,
                    'qty'         => $qty,
                    'unit_price'  => round($price, 2),
                    'desc'        => $desc,
                ];

                $total += ($qty * $price);
            }

            // Extract tax amount from TaxTotal
            $tax_total = $inv['TaxTotal'][0] ?? [];
            $tax_amount = (float) ($tax_total['TaxAmount'][0]['_'] ?? 0);

            // Extract total from LegalMonetaryTotal
            $monetary_total = $inv['LegalMonetaryTotal'][0] ?? [];
            $total = (float) ($monetary_total['PayableAmount'][0]['_'] ?? $total);
        }

        // Submit as a refund note document (InvoiceTypeCode 04)
        $result = $this->submit([
            'invoice_no'               => $rn_invoice_no,
            'order_id'                 => $order_id > 0 ? $order_id : null,
            'item_classification_code' => $item_classification_code,
            'document_type'            => 'refund_note',
            'ref_invoice_no'           => $originalInvoiceNo,
            'ref_uuid'                 => $original->uuid,
            'buyer'                    => $buyer_data,
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines'      => $lines,
            'total'      => $total,
            'tax_amount' => $tax_amount,
        ]);

        if ($result === false) {
            return [
                'success' => false,
                'message' => __('Failed to submit refund note to LHDN.', 'myinvoice-sync'),
            ];
        }

        // Update the credit note's refund_complete field to 1 since refund note was created
        // Get the credit note record
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $credit_note_record = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE invoice_no = %s LIMIT 1",
            $creditNoteInvoiceNo
        ));

        if ($credit_note_record) {
            $wpdb->update(
                $table,
                ['refund_complete' => 1],
                ['id' => $credit_note_record->id],
                ['%d'],
                ['%d']
            );
            LHDN_Logger::log("Marked credit note {$creditNoteInvoiceNo} as complete (refund note created)");
        }

        return [
            'success' => true,
            'message' => __('Refund note submitted successfully.', 'myinvoice-sync'),
        ];
    }

    /**
     * Resubmit WooCommerce order
     */
    public function resubmit_wc_order($ordernum) {
        $order = null;
        
        LHDN_Logger::log("Resubmit: Searching for order with invoice number: {$ordernum}");
        
        // Get order_id from database (most reliable)
        global $wpdb;
        $invoice_record = $wpdb->get_row($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}lhdn_myinvoice WHERE invoice_no = %s LIMIT 1",
            $ordernum
        ));
        
        if ($invoice_record && !empty($invoice_record->order_id) && $invoice_record->order_id > 0) {
            $order = wc_get_order((int) $invoice_record->order_id);
            if ($order) {
                LHDN_Logger::log("Resubmit: Found order by stored order_id: {$invoice_record->order_id} for invoice {$ordernum}");
            }
        }
        
        if (!$order) {
            LHDN_Logger::log("Resubmit failed: WC order {$ordernum} not found. Order ID not stored in database or invalid.");
            return false;
        }

        // Skip wallet payment orders if setting is enabled
        if (LHDN_Settings::get('exclude_wallet', '1') === '1') {
            $payment_method = strtolower($order->get_payment_method());
            if (strpos($payment_method, 'wallet') !== false) {
                LHDN_Logger::log("WC Order #{$order->get_id()} resubmission skipped (wallet payment method)");
                return;
            }
        }

        $user_id = $order->get_user_id();

        // Check if user marked as not Malaysian
        $not_malaysian = $user_id ? (get_user_meta($user_id, 'lhdn_not_malaysian', true) === '1') : false;
        $is_foreign = ($order->get_billing_country() !== 'MY') || $not_malaysian;
        $tin     = $is_foreign ? 'EI00000000020' : 'EI00000000010';
        $id_type = $is_foreign ? 'PASSPORT' : 'NRIC';
        $id_value = 'NA';

        $item_classification_code = $is_foreign ? '008' : '004';

        if ($user_id && !$not_malaysian) {
            $status = get_user_meta($user_id, 'lhdn_tin_validation', true);
            if($status === 'valid'){
                $tin      = get_user_meta($user_id, 'lhdn_tin', true) ?: $tin;
                $id_type  = get_user_meta($user_id, 'lhdn_id_type', true) ?: $id_type;
                $id_value = get_user_meta($user_id, 'lhdn_id_value', true) ?: $id_value;
                $item_classification_code = '008';
            }
        }

        $lines = [];
        $total = 0;
        $tax_amount = 0;
        $i = 1;

        foreach ($order->get_items() as $item) {
            $qty   = (float) $item->get_quantity();
            $price = (float) $item->get_total() / max($qty, 1);

            $lines[] = [
                'id'          => $i++,
                'qty'         => $qty,
                'unit_price'  => round($price, 2),
                'desc'        => $item->get_name(),
            ];

            $total += $item->get_total();
            $tax_amount += $item->get_total_tax();
        }

        foreach ($order->get_items('shipping') as $shipping) {
            $shippingTotal = (float) $shipping->get_total();

            if ($shippingTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($shippingTotal, 2),
                'desc'       => $shipping->get_name() ?: 'Shipping Fee',
            ];

            $total += $shippingTotal;
        }

        // Add order fees (e.g., COD fees, payment gateway fees)
        foreach ($order->get_fees() as $fee) {
            $feeTotal = (float) $fee->get_total();

            if ($feeTotal <= 0) {
                continue;
            }

            $lines[] = [
                'id'         => $i++,
                'qty'        => 1,
                'unit_price' => round($feeTotal, 2),
                'desc'       => $fee->get_name() ?: 'Fee',
            ];

            $total += $feeTotal;
            $tax_amount += $fee->get_total_tax();
        }

        LHDN_Logger::log("Re-submitting WC Order {$ordernum}");

        // Get and clean phone number (billing first, fallback to shipping, then profile)
        $phone = $this->clean_phone_number($order->get_billing_phone());
        if (empty($phone)) {
            $phone = $this->clean_phone_number($order->get_shipping_phone());
        }
        if (empty($phone) && $user_id > 0) {
            $phone = $this->clean_phone_number(get_user_meta($user_id, 'billing_phone', true));
        }
        // If still empty, use a default placeholder (LHDN requires phone number)
        if (empty($phone)) {
            $phone = '60123456789'; // Default Malaysian phone number format
            LHDN_Logger::log("WC Order #{$order->get_id()} resubmit: Phone number missing, using default");
        }

        // Get buyer address with fallback: profile -> billing -> shipping
        $buyer_address = $this->get_buyer_address($user_id, $order);

        // Get buyer name (profile company/name, fallback to order billing)
        $buyer_name = '';
        if ($user_id > 0) {
            $buyer_name = get_user_meta($user_id, 'billing_company', true);
            if (empty($buyer_name)) {
                $first_name = get_user_meta($user_id, 'first_name', true);
                $last_name = get_user_meta($user_id, 'last_name', true);
                if ($first_name || $last_name) {
                    $buyer_name = trim($first_name . ' ' . $last_name);
                }
            }
        }
        if (empty($buyer_name)) {
            $buyer_name = $order->get_billing_company() ?: $order->get_formatted_billing_full_name();
        }

        // Get buyer email (profile first, fallback to order billing)
        $buyer_email = '';
        if ($user_id > 0) {
            $buyer_email = get_user_meta($user_id, 'billing_email', true);
            if (empty($buyer_email)) {
                $user = get_userdata($user_id);
                $buyer_email = $user ? $user->user_email : '';
            }
        }
        if (empty($buyer_email)) {
            $buyer_email = $order->get_billing_email();
        }

        return $this->submit([
            'invoice_no' => $ordernum,
            'order_id' => $order->get_id(),
            'item_classification_code' => $item_classification_code,
            'buyer' => [
                'tin'   => $tin,
                'id_type' => $id_type,
                'id_value' => $id_value,
                'name'  => $buyer_name,
                'phone' => $phone,
                'email' => $buyer_email,
                'address' => $buyer_address,
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }

    /**
     * Resubmit test invoice
     */
    public function resubmit_test($ordernum) {
        $invoiceNo = $ordernum;

        $lines = [
            ['id'=>1,'qty'=>1,'unit_price'=>1100,'desc'=>'Consultation Service'],
            ['id'=>2,'qty'=>2,'unit_price'=>1234.50,'desc'=>'Follow-up Service'],
        ];

        $total = array_reduce($lines, fn($t,$l) => $t + ($l['qty'] * $l['unit_price']), 0);
        $tax_amount = 0;

        return $this->submit([
            'invoice_no' => $invoiceNo,
            'order_id' => 0, // Dummy order_id for test invoices
            'item_classification_code' => '004',
            'buyer' => [
                'tin'   => 'EI00000000010',
                'id_type' => 'NRIC',
                'id_value' => 'NA',
                'name'  => 'Test Buyer Name',
                'phone' => '60123456789',
                'email' => 'test_buyer@example.com',
                'address' => [
                    'city'       => 'Kuala Lumpur',
                    'postcode'   => '50480',
                    'state_code' => LHDN_Helpers::wc_state_to_lhdn('WP'),
                    'line1'      => 'Lot 77, Jalan Test',
                    'country'    => 'MYS',
                ]
            ],
            'seller_address' => [
                'city'       => LHDN_SELLER_ADDRESS_CITY,
                'postcode'   => LHDN_SELLER_ADDRESS_POSTCODE,
                'state_code' => LHDN_Helpers::wc_state_to_lhdn(LHDN_SELLER_ADDRESS_STATE),
                'line1'      => LHDN_SELLER_ADDRESS_LINE1,
                'country'    => LHDN_SELLER_ADDRESS_COUNTRY,
            ],
            'lines' => $lines,
            'total' => $total,
            'tax_amount' => $tax_amount,
        ]);
    }
}


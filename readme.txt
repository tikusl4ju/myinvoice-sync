=== MyInvoice Sync ===

Contributors: nadzree
Tags: lhdn, myinvoice, myinvois, einvoice, woocommerce
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 2.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated invoice submission to LHDN MyInvois system for Malaysian businesses.

== Description ==

MyInvoice Sync automatically generates and submits invoices to the LHDN (Lembaga Hasil Dalam Negeri) MyInvois system in compliance with Malaysian e‑invoicing regulations. It integrates with WooCommerce to submit invoices, validate customer TINs, and manage credit/refund notes.

Key features:

* Automatic invoice submission based on WooCommerce order status (completed, processing, or delayed “after X days”)
* UBL 1.0/1.1 invoice generation, with optional digital signatures (PEM) for UBL 1.1
* TIN capture and validation in user profile and WooCommerce “My Account”
* Background cron jobs for retries, delayed submissions, and status sync
* Credit note and refund note creation linked to original invoices

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/myinvoice-sync/`
2. Activate the plugin from **Plugins → Installed Plugins**
3. Go to **MyInvoice Sync → Settings** to configure:
   * Environment (Sandbox / Production)
   * LHDN MyInvois API client ID and secrets
   * Seller TIN, ID type, SST/TTX numbers, address and contact information
   * Billing circle (when invoices are submitted)
4. For UBL 1.1 with signatures, upload your PEM certificate in the **PEM Certificate Management** section.

Requirements:

* WordPress 5.0 or higher
* WooCommerce 3.0 or higher (for store integration)
* PHP 7.4 or higher
* Valid LHDN MyInvois API credentials

== External Services ==

This plugin connects to **third‑party external services** operated by LHDN (Lembaga Hasil Dalam Negeri / Malaysian Inland Revenue Board). Users should be aware of this before use.

=== What the service is and what it is used for ===

The plugin uses the **LHDN MyInvois API** (official Malaysian government service) to:

* Submit invoices generated from WooCommerce orders
* Validate Tax Identification Numbers (TIN) for customers
* Retrieve invoice statuses and document details
* Cancel documents and submit credit/refund notes

=== Domains (where the service is hosted) ===

API and portal domains are selected based on environment:

* **Sandbox (Pre‑Production)**  
  * API: `https://preprod-api.myinvois.hasil.gov.my`  
  * Portal: `https://preprod.myinvois.hasil.gov.my`
* **Production**  
  * API: `https://api.myinvois.hasil.gov.my`  
  * Portal: `https://myinvois.hasil.gov.my`

=== What data is sent and when ===

* **Invoice data** — When an order reaches the configured status (completed/processing or after the chosen delay), the plugin sends:
  * Invoice number, date, and monetary amounts
  * Buyer information (name, TIN, ID type, ID value, address, contact details)
  * Seller information (TIN, SST/TTX numbers, address, contact details)
  * Line items (products, quantities, prices, descriptions)
  * Tax information and industry classification codes
* **TIN validation** — When a user submits or updates their TIN in their profile/My Account, the plugin sends:
  * Tax Identification Number (TIN)
  * ID Type (e.g. NRIC, Passport, BRN)
  * ID Value
* **Status and document requests** — Cron jobs and admin actions request invoice status and document details from the API.
* **OAuth authentication** — Client ID and secret are sent to the token endpoint to obtain and refresh OAuth access tokens.

Data is sent only when:

* Orders are submitted to LHDN (automatically or manually)
* Users explicitly validate their TIN details
* The plugin syncs invoice statuses or documents (via cron or on‑demand)

All communication with LHDN endpoints uses HTTPS and OAuth 2.0.

=== Service provider and policies ===

* **Provider:** Lembaga Hasil Dalam Negeri (LHDN / Malaysian Inland Revenue Board)  
* **Terms of use:** https://myinvois.hasil.gov.my/terms  
* **Privacy policy:** https://myinvois.hasil.gov.my/privacy  

== Frequently Asked Questions ==

= Do I need my own LHDN MyInvois account? =

Yes. You must obtain API credentials from LHDN for your business. The plugin does not provide or broker credentials.

= Does this plugin send data to servers other than LHDN? =

No. API calls are made only to the LHDN MyInvois API/portal domains listed in the External Services section.

= Can I test in Sandbox first? =

Yes. You can switch between Sandbox and Production in the plugin settings. API/portal URLs are adjusted automatically.

== Screenshots ==

1. MyInvoice Sync settings page with environment, seller and API configuration.
2. WooCommerce Orders list with LHDN MyInvois status column.
3. Invoice list showing submission status and UUID.
4. User profile / My Account TIN fields and validation status.

== Changelog ==

= 2.1.0 =
* Improved nonce validation in user profile TIN save handler based on WordPress.org review feedback.
* Documented external LHDN API/portal services and domains in readme files.

= 2.0.12 =
* Maintenance and compatibility updates.

== Upgrade Notice ==

= 2.1.0 =
Security and compliance update: improved nonce checks and explicit external service documentation. It is recommended to update before submitting to the WordPress.org plugin directory.


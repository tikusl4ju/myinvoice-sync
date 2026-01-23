# MyInvoice Sync

**Contributors:** nadzree, tikusL4ju   
**Tags:** lhdn, myinvoice, myinvois, einvoice, woocommerce  
**Requires at least:** 5.0  
**Tested up to:** 6.9  
**Stable tag:** 2.0.11  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Version:** 2.0.11  
**Repository:** [GitHub](https://github.com/tikusl4ju/myinvoice-sync)  

== Description ==

Automated invoice submission to LHDN (Lembaga Hasil Dalam Negeri) MyInvois system for Malaysian businesses.

== Short Description ==

Automated invoice submission to LHDN MyInvois system for Malaysian businesses.

## Overview

The MyInvoice Sync plugin automatically generates and submits invoices to the LHDN MyInvois portal in compliance with Malaysian tax regulations. It integrates seamlessly with WooCommerce to automate invoice submission.

**Payment Gateway Compatibility**: The plugin operates independently without requiring middleware and is compatible with all WooCommerce payment gateways, ensuring flexibility for your payment processing setup. The communication is directly to LHDN API.

## Features

### 🚀 Core Functionality

- **Automatic Invoice Submission**: Automatically generates and submits invoices to LHDN MyInvois when WooCommerce orders are completed
- **WooCommerce Integration**: Full integration with WooCommerce for seamless order-to-invoice workflow
- **UBL (Universal Business Language) Support**: Generates compliant UBL 1.0/1.1 formatted invoices
- **Digital Signatures**: Supports XMLDSig-style digital signatures for UBL 1.1 invoices
- **Environment Toggle**: Switch between Sandbox (Pre-Production) and Production environments
- **Flexible Billing Circle**: Configure when invoices are submitted (immediately or after a delay)

### 📋 Configuration Options

- **Billing Circle**: Choose when invoices are submitted:
  - On Completed Order (immediate submission)
  - On Processed Order (submit when order status is "processing")
  - After 1-7 Days (delayed submission with automatic cron processing)
- **Tax Category ID**: Select appropriate tax category (Standard Rated, Zero Rated, Exempt, etc.)
- **Industry Classification Code (MSIC)**: Select your business MSIC code from a comprehensive list
- **Seller Information**: Configure seller details (TIN, SST Number, TTX Number, address, etc.)
- **API Credentials**: Manage OAuth client credentials for API authentication

### 👤 User Profile Integration 

- **TIN Validation**: Users can add and validate their Tax Identification Number (TIN)
- **ID Type Support**: Supports NRIC, Passport, and other ID types
- **Frontend Integration**: TIN fields available in WooCommerce My Account and checkout pages
- **Validation Status**: Visual indicators showing TIN validation status

### 🔄 Automated Processing

- **Cron Jobs**: Automated background processing for:
  - Syncing submitted invoice statuses
  - Retrying failed submissions (with exponential backoff)
  - Processing delayed invoice submissions
- **Queue Management**: Automatic retry mechanism for failed submissions
- **Status Synchronization**: Regular sync of invoice statuses from LHDN portal

### 📊 Admin Dashboard

- **Invoice Management**: View all submitted invoices with status, UUID, and links
- **Settings Page**: Comprehensive configuration interface
- **Debug Logging**: Enable detailed logging for troubleshooting
- **Cron Status**: Monitor cron job execution times
- **Order Integration**: View LHDN submission status directly in WooCommerce orders list
- **Bulk Actions**: Bulk submit orders to LHDN from WooCommerce orders list
- **Pending Refund View**: Dedicated "Pending Refund" table showing credit notes that do not yet have a refund note issued

### 💳 Credit & Refund Note Management

- **Manual Credit Note Creation**: Create credit notes directly from submitted invoices in the admin panel
- **Automatic Credit Note on Refund**: Automatically generates credit notes when WooCommerce refunds are processed
- **Full Credit Support**: Supports full credit notes (all items refunded)
- **Duplicate Prevention**: Prevents duplicate credit notes for the same invoice
- **Manual Refund Note Creation**: Create refund notes directly from credit notes in the admin panel
- **Refund Note Linking**: Refund notes are linked to the original invoice (via UUID) and exposed in both admin and customer views

## Installation

1. Upload the plugin files to `/wp-content/plugins/myinvoice-sync/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **MyInvoice Sync > Settings** to configure the plugin
4. Ensure you have your LHDN API credentials and PEM certificate file ready

### Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher (for WooCommerce integration)
- PHP 7.4 or higher
- MySQL 5.6 or higher
- LHDN MyInvois API credentials
- PEM certificate file (for UBL 1.1 digital signatures)


## Configuration

### Initial Setup

1. **Environment Selection**
   - Choose between **Sandbox** (for testing) or **Production** (for live submissions)
   - API and Portal URLs update automatically based on selection

2. **API Credentials**
   - Enter your **Client ID**
   - Enter **Client Secret 1** and **Client Secret 2**
   - These are provided by LHDN when you register for MyInvois API access

3. **Seller Information**
   - **Seller TIN**: Your business Tax Identification Number
   - **Seller ID Type**: Business Registration Number (BRN), NRIC, etc.
   - **Seller ID Value**: Your registration number
   - **Seller SST Number**: Your SST registration number (or "NA" if not applicable)
   - **Seller TTX Number**: Your TTX number (or "NA" if not applicable)
   - **Seller Name**: Registered business name
   - **Seller Contact**: Email, phone, and address details
   - **Seller Country**: Fixed to "MYS" (Malaysia)

4. **Tax Configuration**
   - **Tax Category ID**: Select from available tax categories
     - Service Tax
     - Not Applicable
     - Exempt (E)
   - **Industry Classification Code (MSIC)**: Select your business activity code

5. **Billing Circle**
   - **On Completed Order**: Submit immediately when order status changes to "completed"
   - **On Processed Order**: Submit when order status changes to "processing"
   - **After X Days**: Queue invoice for submission after specified number of days (1-7 days)

### PEM Certificate Setup

For UBL 1.1 invoices with digital signatures:
1. Navigate to **MyInvoice Sync > Settings** in WordPress admin
2. Scroll down to the **PEM Certificate Management** section
3. Click **Choose File** and select your PEM certificate file (`.pem` format only)
4. Click **Upload PEM Certificate** button
5. The plugin will automatically parse and extract certificate details (organization, serial number, validity dates, etc.)
6. Once uploaded, you can select UBL 1.1 in the settings above
7. Certificate details will be displayed, including validity dates and upload timestamp
8. You can reset the certificate at any time using the **Reset Certificate** button

**Note**: The certificate must be in valid PEM format and will be stored securely in the database. Only one active certificate is allowed at a time.

## Usage

### Automatic Submission

Once configured, the plugin automatically:
1. Detects when WooCommerce orders reach the configured status
2. Generates UBL-compliant invoice documents
3. Submits invoices to LHDN MyInvois API
4. Tracks submission status and updates order metadata

### Manual Submission

From the WooCommerce Orders list:
1. Navigate to **WooCommerce > Orders**
2. Find orders with "Not Submitted" status in the LHDN MyInvois column
3. Click the **Process** button to manually submit the invoice
4. Note: Process button only appears for "Completed", "Processing" or custom status orders.

### Bulk Submission

From the WooCommerce Orders list:
1. Navigate to **WooCommerce > Orders**
2. Select multiple orders using the checkboxes
3. Choose **"Submit to LHDN"** from the bulk actions dropdown
4. Click **"Apply"** to process all selected orders
5. View the result notice showing how many orders were processed, skipped, or failed

### Viewing Invoices

1. Navigate to **MyInvoice Sync > Invoices** in WordPress admin
2. View all submitted invoices with:
   - Invoice number
   - Submission status
   - UUID and Long ID
   - Direct links to LHDN portal
   - Item classification type

### Creating Credit & Refund Notes

#### Manual Credit Note Creation

1. Navigate to **MyInvoice Sync > Invoices** in WordPress admin
2. Find a submitted/valid invoice (status must be "submitted" or "valid")
3. Click the **"Credit Note"** button in the Actions column
4. The plugin will automatically:
   - Create a full credit note for all items in the original invoice
   - Submit it to LHDN with reference to the original invoice UUID
   - Store it as a new invoice record with prefix "CN-" (e.g., "CN-12345")

#### Automatic Credit Note on Refund

When processing a refund in WooCommerce:
1. Navigate to **WooCommerce > Orders**
2. Open an order that has a submitted invoice
3. Process a refund
4. The plugin automatically:
   - Detects the refund action
   - Creates a credit note for the original invoice
   - Prevents duplicate credit notes if one already exists
   - Links the credit note to the original invoice UUID

**Note**: If a credit note was already created manually, the automatic refund process will skip creating a duplicate.

#### Manual Refund Note Creation (from Admin)

1. Navigate to **MyInvoice Sync > Invoices** in WordPress admin
2. Locate a **Credit Note** record (invoice number starts with `CN-`)
3. Click the **"Refund Note"** button in the Actions column
4. The plugin will automatically:
   - Create a full refund note for all items in the original invoice
   - Submit it to LHDN with reference to the original invoice UUID
   - Store it as a new invoice record with prefix `RN-` (e.g., `RN-12345`)

#### Manual Refund Note Creation (from WooCommerce Orders)

1. Navigate to **WooCommerce > Orders**
2. For orders that already have a credit note, the **LHDN MyInvois** column will show:
   - A **Credit Note** link
   - A **"Refund Note"** button
3. Click **"Refund Note"** to create and submit a refund note for that credit note

### Status Indicators

- **Not Submitted**: Invoice has not been submitted yet
- **Processing**: Invoice is being processed by LHDN
- **Submitted**: Invoice successfully submitted (click to view on LHDN portal)
- **Valid**: Invoice validated by LHDN
- **Cancelled**: Invoice has been cancelled
- **Queueing**: Invoice is in retry queue
- **Failed**: Submission failed (will be retried automatically)

### Credit Note Indicators

- Credit notes are stored as separate invoice records with prefix "CN-" (e.g., "CN-12345")
- Credit notes appear in the invoice list with their own status and **Type** = "Credit Note"
- Credit notes reference the original invoice UUID in the LHDN submission

### Refund Note Indicators

- Refund notes are stored as separate invoice records with prefix "RN-" (e.g., "RN-12345")
- Refund notes appear in the invoice list with **Type** = "Refund Note"
- Credit notes that do not yet have a refund note appear in the **Pending Refund** table for quick follow-up
- In WooCommerce **Orders**:
  - The **LHDN MyInvois** column links to the Refund Note receipt when it exists (otherwise falls back to the Credit Note or original invoice)
- In WooCommerce **My Account → Orders**:
  - Refunded orders link to **"View Refund Note"** when a refund note exists (otherwise fall back to the Credit Note or original invoice)

## WooCommerce Integration

### Order Status Integration

The plugin adds a "LHDN MyInvois" column to the WooCommerce orders list showing:
- Submission status
- Direct links to view invoices on LHDN portal
- Manual submission button for unsubmitted orders

### Order Processing

- **Consolidated Invoices**: For Malaysian customers without validated TIN
- **E-Commerce Invoices**: For customers with validated TIN or foreign customers
- **Automatic Detection**: Plugin automatically determines invoice type based on customer data

### Bulk Order Submission

- Select multiple orders from the WooCommerce orders list
- Use the "Submit to LHDN" bulk action to process multiple orders at once
- The bulk action respects all plugin settings (wallet exclusions, billing circle, etc.)
- Orders that are already submitted or invalid are automatically skipped

### Refund Integration

- **Automatic Credit Note**: When a refund is processed in WooCommerce, the plugin automatically creates a credit note for the original invoice
- **Duplicate Prevention**: If a credit note already exists (created manually or from a previous refund), the automatic process will skip creating a duplicate
- **Full Credit Support**: Currently supports full credit notes (all items refunded)
- **Manual Refund Notes**: Admins can create refund notes from credit notes (invoices prefixed with `CN-`)
- **Refund Note Linking**: Refund notes (prefixed with `RN-`) are linked to the original invoice and surfaced in both admin and customer order views
- **Works with All Orders**: Credit and refund notes can be created for any submitted invoice, including test invoices

## Billing Circle Options

### On Completed Order
- Invoices are submitted immediately when order status changes to "completed"
- Best for: Real-time invoice generation and immediate compliance

### On Processed Order
- Invoices are submitted when order status changes to "processing"
- Best for: Early invoice generation before order completion

### After X Days (1-7 Days)
- Orders are queued when completed
- Invoices are automatically submitted after the specified number of days
- Processed by automated cron jobs
- Best for: Businesses that need delayed invoice submission

**How Delayed Submission Works:**
1. When order completes, a queue record is created in the database
2. Queue status includes the number of days and date (format: `q1dmy`, `q2dmy`, etc.)
3. Cron job runs every 10 minutes to check for ready invoices
4. Invoices are automatically submitted when the delay period expires

## Credit & Refund Notes

### Overview

Credit and refund notes are used to reverse or adjust previously submitted invoices. The plugin supports both manual and automatic creation flows.

### When to Use Credit & Refund Notes

- **Refunds**: When customers request refunds for completed orders
- **Invoice Corrections**: When an invoice needs to be reversed or adjusted
- **Cancellation After Time Limit**: When an invoice cannot be cancelled (time limit exceeded) and a credit note is required instead

### Credit Note Features

- **Full Credit Support**: Creates credit notes for all items in the original invoice
- **Automatic Linking**: Credit notes are automatically linked to the original invoice via UUID reference
- **Duplicate Prevention**: System prevents creating multiple credit notes for the same invoice
- **Works with Test Invoices**: Credit notes can be created even for test invoices without WooCommerce orders
- **Manual or Automatic**: Can be created manually from admin panel or automatically on WooCommerce refunds

### Credit Note Structure

Credit notes follow the LHDN UBL format with:
- **InvoiceTypeCode**: Set to "02" (Credit Note)
- **BillingReference**: Contains reference to original invoice number and UUID
- **Same Buyer/Seller Info**: Uses the same buyer and seller information as the original invoice
- **Full Item List**: Includes all items from the original invoice with same quantities and prices

### Creating Credit Notes

#### From Admin Panel

1. Navigate to **MyInvoice Sync > Invoices**
2. Find an invoice with status "submitted" or "valid"
3. Click the **"Credit Note"** button in the Actions column
4. The credit note will be created and submitted automatically

#### From WooCommerce Refund

1. Process a refund in WooCommerce for an order with a submitted invoice
2. The plugin automatically detects the refund
3. A credit note is created and submitted to LHDN
4. If a credit note already exists, the process is skipped (no duplicate)

### Credit Note Storage

- Credit notes are stored as separate invoice records in the database
- Invoice number format: `CN-{original_invoice_number}` (e.g., "CN-12345")
- Each credit note has its own UUID and status tracking
- Credit notes appear in the invoice list alongside regular invoices

### Refund Note Features

- **Full Refund Support**: Creates refund notes for all items in the original invoice (via the associated credit note)
- **Automatic Linking**: Refund notes are automatically linked back to the original invoice via UUID reference
- **Duplicate Prevention**: System prevents creating multiple refund notes for the same original invoice
- **Works with Test Invoices**: Refund notes can be created even for test invoices without WooCommerce orders (using stored payload data)

### Refund Note Structure

Refund notes follow the LHDN UBL format with:
- **InvoiceTypeCode**: Set to `"04"` (Refund Note)
- **BillingReference**: Contains reference to the original invoice number and UUID
- **Same Buyer/Seller Info**: Uses the same buyer and seller information as the original invoice
- **Full Item List**: Includes all items from the original invoice with the same quantities and prices

## User Profile TIN Validation

### For Customers

Customers can add their TIN information in:
- **WordPress Admin**: User profile page (for admin users)
- **WooCommerce My Account**: Account details page
- **Checkout Page**: TIN status badge displayed

### TIN Validation Process

1. Customer enters TIN, ID Type, and ID Value
2. Plugin validates TIN with LHDN API
3. Validation status stored in user meta
4. Validated customers receive E-Commerce invoices instead of Consolidated invoices

### Supported ID Types

- **NRIC**: National Registration Identity Card (Malaysian)
- **Passport**: For foreign customers
- **BRN**: Business Registration Number
- **Other**: Custom ID types as supported by LHDN

## Cron Jobs

The plugin uses WordPress cron for automated tasks:

### Sync Submitted Invoices
- **Frequency**: Every 10 minutes
- **Purpose**: Sync status of submitted invoices from LHDN portal
- **Processes**: Up to 5 invoices per run

### Retry Queue Invoices
- **Frequency**: Every 10 minutes
- **Purpose**: Retry failed submissions (HTTP 429 rate limit errors)
- **Retry Logic**: Exponential backoff (up to 3 retries)
- **Processes**: Up to 5 invoices per run

### Process Delayed Invoices
- **Frequency**: Every 10 minutes
- **Purpose**: Process invoices queued for delayed submission
- **Processes**: Up to 20 invoices per run
- **Logic**: Checks queue dates and submits when delay period expires

### Monitoring Cron Status

View last execution times in **MyInvoice Sync > Settings**:
- Last Sync Cron
- Last Retry Cron
- Last Delayed Invoice Cron

## API Integration

### OAuth Authentication

The plugin handles OAuth token management automatically:
- Tokens are cached in the database
- Automatic token refresh when expired
- Environment-specific token storage (sandbox vs production)

### API Endpoints Used

- `/connect/token` - OAuth token endpoint
- `/api/v1.0/documentsubmissions/` - Submit invoices
- `/api/v1.0/documents/` - Get document details
- `/api/v1.0/documents/state/` - Cancel documents
- `/api/v1.0/taxpayer/validate/` - Validate TIN

### Error Handling

- **429 Rate Limiting**: Automatic retry with exponential backoff
- **Token Expiry**: Automatic token refresh
- **Network Errors**: Logged for debugging
- **Validation Errors**: Displayed to users

## Database Structure

The plugin creates the following database tables:

### `wp_lhdn_myinvoice`
Stores all invoice submissions with:
- Invoice number, UUID, Long ID
- UBL payload and document hash
- Submission status and HTTP response codes
- Queue status and dates (for delayed submissions)
- Retry counts and timestamps

### `wp_lhdn_tokens`
Stores OAuth access tokens:
- Encrypted access tokens
- Expiration timestamps
- Environment-specific storage

### `wp_lhdn_settings`
Stores plugin configuration:
- All settings from admin panel
- Serialized values for complex data

## Troubleshooting

### Invoices Not Submitting

1. **Check API Credentials**: Verify Client ID and Secrets are correct
2. **Check Environment**: Ensure correct environment (Sandbox/Production) is selected
3. **Check Order Status**: Verify order status matches billing circle setting
4. **Check Debug Logs**: Enable debug logging in settings to see detailed error messages
5. **Check Cron Jobs**: Verify WordPress cron is running (use WP-Cron or real cron)

### Common Issues

**Issue**: "Order already submitted" but shows "Not Submitted"
- **Solution**: Check if invoice exists in database with different status

**Issue**: Cron jobs not running
- **Solution**: Ensure WordPress cron is enabled, or set up real cron via WP-CLI

**Issue**: TIN validation failing
- **Solution**: Verify TIN format and ensure customer details match LHDN records

**Issue**: Digital signature errors (UBL 1.1)
- **Solution**: Verify PEM certificate is uploaded through **MyInvoice Sync > Settings > PEM Certificate Management**. Check that the certificate is valid, not expired, and in correct PEM format. You can view certificate details (validity dates, organization) in the settings page.

### Debug Mode

Enable debug logging in **Settings > Enable Debug Logging** to see:
- API requests and responses
- Invoice generation details
- Cron job execution logs
- Error messages and stack traces

View logs via AJAX endpoint or check WordPress debug log.

## Security

- All API credentials stored securely in database
- OAuth tokens encrypted
- Input sanitization on all user inputs
- Nonce verification on admin actions
- Capability checks for admin functions


## Snippets

### Certificate Management

#### Convert P12 to PEM Format

Convert your LHDN certificate from P12 format to PEM format:

```bash
OPENSSL_CONF=/etc/ssl/openssl.cnf openssl pkcs12 -legacy -in key.p12 -out lhdn.pem -nodes
```

**Note**: The `-legacy` flag is required for older P12 files. Remove it if you encounter compatibility issues.

#### Verify PEM Certificate

Check if your converted PEM certificate is valid:

```bash
# Check RSA key validity
openssl rsa -in lhdn.pem -check

# View certificate subject details
openssl x509 -in lhdn.pem -noout -subject

# View full certificate information
openssl x509 -in lhdn.pem -noout -text
```

#### View Certificate Validity Dates

```bash
# Check certificate expiration
openssl x509 -in lhdn.pem -noout -dates

# Check certificate validity period
openssl x509 -in lhdn.pem -noout -startdate -enddate
```

== External Services ==

This plugin connects to the LHDN (Lembaga Hasil Dalam Negeri) MyInvois API to submit invoices, validate taxpayer information, and retrieve invoice statuses. This is required for compliance with Malaysian tax regulations.

**What the service is and what it is used for:**
- The LHDN MyInvois API is the official Malaysian government service for electronic invoice submission and management
- The plugin uses this service to automatically submit invoices generated from WooCommerce orders
- The service validates Tax Identification Numbers (TIN) for customers
- The service provides invoice status updates and document retrieval

**Where the service is hosted (domains):**
- API base URLs are configured in the plugin settings and typically point to:
  - Sandbox (Pre-Production): `https://preprod-api.myinvois.hasil.gov.my`
  - Production: `https://api.myinvois.hasil.gov.my`
- Document viewing links in the admin UI point to the official MyInvois portal, for example:
  - Sandbox portal: `https://preprod.myinvois.hasil.gov.my`
  - Production portal: `https://myinvois.hasil.gov.my`

**What data is sent and when:**
- **Invoice Data**: When a WooCommerce order reaches the configured status (completed/processing or after delay), the plugin sends invoice data including:
  - Invoice number, date, and amounts
  - Buyer information (name, TIN, ID type, ID value, address, contact details)
  - Seller information (TIN, SST/TTX numbers, address, contact details)
  - Line items (products, quantities, prices, descriptions)
  - Tax information and industry classification codes
- **TIN Validation**: When a user submits their TIN information in their profile, the plugin sends:
  - Tax Identification Number (TIN)
  - ID Type (NRIC, Passport, BRN, etc.)
  - ID Value
- **Status Queries**: The plugin periodically queries the API to retrieve invoice submission statuses
- **Token Requests**: OAuth access tokens are requested and refreshed as needed for API authentication

**Service Provider:**
- **Service Name**: LHDN MyInvois
- **Provider**: Lembaga Hasil Dalam Negeri (Malaysian Inland Revenue Board)
- **Terms of Service**: https://myinvois.hasil.gov.my/terms
- **Privacy Policy**: https://myinvois.hasil.gov.my/privacy
- **API Documentation**: https://myinvois.hasil.gov.my/api-documentation

**Data Transmission:**
- All data is transmitted over HTTPS (encrypted)
- OAuth 2.0 authentication is used for API access
- Data is only sent when:
  - An order is submitted (based on billing circle configuration)
  - A user validates their TIN in their profile
  - The plugin syncs invoice statuses (via cron jobs)
  - Manual invoice submission is triggered from the admin panel

**User Control:**
- Users can disable automatic invoice submission by deactivating the plugin
- TIN validation is optional and only performed when users voluntarily enter their TIN information
- Users can view and manage their TIN information in their WordPress/WooCommerce account profile

## Credits
Developed for Malaysian businesses requiring LHDN MyInvois compliance.

---

**Note**: This plugin requires valid LHDN MyInvois API credentials. Contact LHDN to obtain API access for your business.

## Support

No official support is provided. The plugin is free to use, but commercialization or redistribution for commercial purposes is not permitted. 

tikusL4ju@gmail.com
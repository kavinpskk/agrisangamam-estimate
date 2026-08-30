# SGAS Simple Billing

Dependency-free PHP 8.1 / MySQL application for non-GST bills, Tamil print names, customer balances, PDF printing and WhatsApp sharing.

## Hostinger installation

1. Upload all files into the document root of `estimate.agrisangamam.in`.
2. In phpMyAdmin, select `u495945422_estimate` and import `schema.sql`.
3. Copy `config.example.php` to `config.php`; enter the database password. Never commit `config.php`.
4. Open `/install.php`, create the first administrator, and immediately delete `install.php` from Hostinger.
5. Sign in and add categories, products (English + Tamil), customers and opening balances.

Use UTF-8/utf8mb4 throughout. The Print / Save PDF button opens the browser print dialog; choose **Save as PDF**. On supported Android browsers, Share uses the system share sheet. The WhatsApp fallback shares the secure bill link.

## Important behavior

- Search and entry use the English product name only.
- Printed bills use the Tamil product name only.
- No GST, tax, discount, inventory, estimate date or validity date.
- Cancelling a bill removes it from the customer's calculated balance without deleting the audit record.

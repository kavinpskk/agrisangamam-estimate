# SGAS Simple Billing

Dependency-free PHP 8.1 / MySQL application for non-GST bills, Tamil print names, customer balances, PDF printing and WhatsApp sharing.

## Hostinger installation

1. Upload all files into the document root of `estimate.agrisangamam.in`.
2. In phpMyAdmin, select `u495945422_estimate` and import `schema.sql`.
3. Copy `config.example.php` to `config.php`; enter the database password. Never commit `config.php`.
4. Create the first administrator during the initial private setup, then remove the installer before deployment.
5. Sign in and add categories, products (English + Tamil), customers and opening balances.

Use UTF-8/utf8mb4 throughout. The Print / Save PDF button opens the browser print dialog; choose **Save as PDF**. On supported Android browsers, Share uses the system share sheet. The WhatsApp fallback shares the secure bill link.

After upgrading an existing installation, import each file in `migrations/` once through phpMyAdmin. Migration files are safe to run again.

## Automatic Google Drive backup

1. Open **Settings → Backup** and connect the existing Google Apps Script backup endpoint.
2. Enable **Automatic daily backup** and save.
3. Copy the protected scheduled-backup URL shown on the page.
4. In Hostinger, create a daily cron job that opens that URL (recommended time: 11:30 PM, Asia/Kolkata).
5. Use **Test Backup Now** once, then confirm the new file appears in the `SGAS Backups` Google Drive folder.

The scheduled endpoint is protected by the existing 64-character Drive token, prevents simultaneous runs, and creates at most one scheduled backup per day. Google Drive connection and scheduling secrets are excluded from generated database backups.

## Important behavior

- Search and entry use the English product name only.
- Printed bills use the Tamil product name only.
- No GST, tax, discount, inventory, estimate date or validity date.
- Cancelling a bill removes it from the customer's calculated balance without deleting the audit record.
- Bill numbers are allocated under a database row lock to prevent duplicates.
- Product, quantity, rate and received-amount calculations are validated again on the server.

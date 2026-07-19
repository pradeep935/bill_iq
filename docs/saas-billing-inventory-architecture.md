# Bill IQ SaaS Billing, Inventory and Accounting Architecture

## Architecture Plan

Bill IQ is a multi-tenant SaaS application for retail billing, inventory, GST and accounting. The application is organized around a strict hierarchy:

Super Admin -> Business/Tenant -> Branch -> Warehouse -> Users -> Products and Transactions

The backend is Laravel with modular controllers, models, services and REST endpoints. The current UI is Vue + Inertia inside Laravel, while the business logic remains backend-owned so Blade or another frontend can reuse the same APIs later.

Every business-owned record must carry `business_id`. Warehouse stock records also carry `branch_id`, `warehouse_id` and, where applicable, `warehouse_location_id`. Tenant filtering must be applied in controller/service query boundaries before data reaches the frontend.

## ER Diagram

```text
users
  | role_id
  | tenant_id/business_id
  v
businesses/companies
  |-- branches
  |     |-- warehouses
  |           |-- warehouse_locations
  |
  |-- users
  |-- customers
  |-- suppliers
  |-- product_categories
  |-- brands
  |-- units
  |-- accounts
  |
  |-- products
        |-- product_barcodes
        |-- product_batches
        |-- product_serial_numbers
        |-- product_purchase_prices
        |-- opening_stock_items
        |-- stock_ledgers / stock_transactions

hsn_masters
  |-- hsn_tax_rates
  |-- category_hsn_mappings

opening_stock_entries
  |-- opening_stock_items
        |-- stock_ledgers

sale_invoices
  |-- sale_invoice_items with GST snapshot

purchase_bills
  |-- purchase items / purchase price history

vouchers / ledger_entries
  |-- accounts
```

## Module List

- SaaS admin: businesses, subscriptions, approvals, system users, HSN/GST master.
- Business setup: branches, warehouses, warehouse locations, users, roles, permissions, settings.
- Product master: products, HSN mapping, units, brands, categories, barcodes, batches, serials.
- Inventory: opening stock, stock ledger, current stock, stock transfers, adjustments, expiry, count workflow.
- Purchase: purchase orders, GRN, supplier invoices, returns, supplier payments.
- Sales/POS: POS billing, quotations, invoices, returns, customer payments, stock allocation.
- Accounting: chart of accounts, vouchers, ledgers, receivables, payables, trial balance, P&L, balance sheet.
- Reports: sales, purchases, GST, stock, valuation, expiry, serial, low stock, reorder, cash flow.
- Audit: login, product changes, HSN changes, approvals, stock changes, sales cancellation.

## Folder Structure

```text
app/
  Http/Controllers/
    ProductController.php
    InventoryController.php
    SalesController.php
    PurchaseController.php
    AccountingController.php
    SetupController.php
    ReportsController.php
  Models/
    Product.php
    HsnMaster.php
    HsnTaxRate.php
    StockLedger.php
    ...
  Services/
    ProductInventoryService.php
    AuditLogger.php
  Observers/
    AuditLogObserver.php
database/
  migrations/
  seeders/
resources/js/
  Pages/
  Components/
routes/
  web.php
  api.php
tests/
  Feature/
docs/
```

## Development Phases

1. SaaS foundation: login, roles, businesses, branches, warehouses, permissions, audit logs.
2. Master data: products, HSN/GST, customers, suppliers, units, categories, accounts.
3. Inventory engine: opening stock, stock ledger, balances, batches, serials, expiry, count approval.
4. Purchase cycle: PO, GRN, invoice approval, stock and accounting posting.
5. Sales/POS cycle: product search, barcode scanning, invoice tax snapshot, stock deduction, payments.
6. Accounting: double-entry posting, ledgers, vouchers, receivables, payables.
7. Reports and exports: Excel/PDF, GST, stock valuation, P&L, balance sheet.
8. Subscription and SaaS limits: plans, trial, feature limits, billing, expiry.

## Roles and Permissions

Roles:
- Super Admin
- Business Owner/Admin
- Manager
- Accountant
- Cashier
- Inventory Supervisor
- Inventory Employee

Core permissions:
- `view_products`
- `create_products`
- `edit_products`
- `manage_hsn`
- `manage_barcodes`
- `manage_batches`
- `manage_serials`
- `view_purchase_price`
- `create_purchase`
- `approve_purchase`
- `create_sale`
- `cancel_sale`
- `perform_inventory_count`
- `approve_inventory_count`
- `create_stock_adjustment`
- `approve_stock_adjustment`
- `view_accounting`
- `view_reports`

## Installation Notes

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

For an existing database, do not run destructive refresh commands. First align the `migrations` table with the existing schema or migrate on a fresh database.

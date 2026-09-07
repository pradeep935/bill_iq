# Batch & Expiry implementation

Local runtime: MySQL `billiq` on MAMP port 8889. No application database switch to SQLite.

## Changes

- `resources/js/Pages/Inventory/BatchExpiry.vue`: Overview, Batches, Batch Movements, Expiry, Quarantine and Reports; operation modal, summary/expiry navigation, filters, pagination, detail/history, permission-driven actions and download exports.
- `app/Services/BatchManagementService.php`: ledger-derived batch/location/variant/condition balances, FEFO ranking, movement presentation, reports and controlled operations through existing opening, adjustment and transfer services.
- `app/Services/BatchIdentityService.php`: product-locked deterministic lot reuse, manufacturing/expiry compatibility validation. Product edits preserve lot identities.
- `StockService.php`, `ProductBatch.php`: central sale eligibility, batch-required posting, locking and negative-balance prevention. Current Stock available totals exclude restricted lots.
- `OpeningStockService.php`, `PurchaseService.php`, `SalesService.php`, `SalesReturnService.php`, `PurchaseReturnService.php`: identity reuse, posting locks, original-batch returns, original sale cost and non-saleable return receipt.
- `InventoryControlService.php`, `Control.vue`: existing physical count/transfer batch selection uses batch numbers; transfers retain identity.
- `ProductMasterService.php`: prevents destructive lot replacement and changing tracking while stock remains.
- `Purchases.vue`: manufacturing date field.
- `InventoryController.php`, `BatchOperationRequest.php`, `OpeningStockVoucherRequest.php`: endpoints, authorization, validation and actual CSV/XLSX/PDF downloads.
- `resources/views/inventory/batch-export.blade.php`: PDF report.
- `config/inventory.php`: configurable 30-day near-expiry default.
- Obsolete split/merge routes and client methods removed.

## Migrations

Added and applied locally:
- `2026_09_06_000001_add_batch_operation_permissions`: adjust/reclassify/writeoff in existing permission system; existing role grants preserved.
- `2026_09_06_000002_add_batch_posting_audit_fields`: opening condition and unique operation retry token/hash.

Applied existing `2026_08_30_000001_add_posted_at_to_stock_ledgers` for movement posting timestamps.
Corrected an overlong index name in the historical recurring-expense migration so fresh MySQL test databases migrate.

## Routes

Under `/app/inventory/batches`:
- New GET `/movements`, GET `/export`, POST `/operations`.
- Updated `/list`, `/reports`, `/references`, `/fefo`, `/{batch}`, `/{batch}/ledger`, `/{batch}/status`, `/{batch}/transfer`.
- Removed `/{batch}/split` and `/{batch}/merge`.

## Stock rules

Physical stock is SUM(IN − OUT) across physical conditions, excluding lost. Available stock is eligible saleable stock minus reservations, clamped at zero in batch reporting. Condition changes create paired ledger entries and preserve physical quantity. Block/unblock records audit events without changing quantity.

Costs follow the existing stock service's weighted historical receipt cost per scope, rounded to two decimals; quantity × that cost provides valuation. This is the existing application method, not a newly introduced FIFO or moving-average accounting engine.

FEFO sorts eligible batches by expiry (undated last), then receipt/lot sequence. Existing explicit sales batch selection is preserved. Returns retain originating batch; non-saleable returns enter their physical condition. Posting and batch operations use transactions and locks; operation retry tokens prevent duplicate quantity postings.

## Verification

MySQL only for final verification, temporary databases created and removed by `scripts/test-batches-mysql.php`:

```sh
php scripts/test-batches-mysql.php 'BatchExpiryWorkflowTest::test_batch|InventoryConditionAdjustmentTest|InventoryMovementServiceTest'
php scripts/test-batches-mysql.php 'SalesInventoryFlowTest|InventoryTraceabilityManufacturingTest'
php scripts/test-batches-mysql.php 'BatchDocumentIntegrationTest::test_batch_documents'
npm run build:local
```

Results: 34 batch/stock/unit tests (212 assertions), 15 sales/manufacturing tests, and one full document/export test (25 assertions) passed. Full document test covers opening, purchase, FEFO, sale, partial return, duplicate-return protection, purchase return, physical count, reconciliation, CSV/XLSX/PDF and pagination. PHP syntax checked on 26 changed/new PHP files; diff whitespace check passed. New PHP files formatted with Pint. No separate frontend lint/type script configured.

Local authenticated browser smoke check: six tabs, operation modal, desktop/mobile screenshots, no page JavaScript exceptions. Local database currently has no posted batch stock; acceptance fixtures were isolated rather than added to business inventory.

## Limits

- Sales retain the existing manual single-batch-per-line model. Multiple lots require separate invoice lines; automatic split allocation is not enabled.
- Batch block/unblock applies to the lot across all locations, matching the existing lot master model. Quarantine/reclassification is quantity/location/condition-specific.
- Product options initially load 300 products; batch-operation search returns up to 100 matching location/condition rows. Narrow search for larger catalogs.
- Detail shows the latest 80 movements/events; the full ledger is paginated separately. Running balances are omitted rather than inferred across mixed locations/conditions.
- Export generation is synchronous; very large exports may require a background export job.
- Accounting entries follow existing configured opening/adjustment/purchase/sale services; this work does not introduce another accounting engine.

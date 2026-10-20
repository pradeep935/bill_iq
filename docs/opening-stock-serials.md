# Opening Stock serial integration

Opening Stock accepts multiple serial assignments per item. Drafts store intent in JSON; they do not create serial records, make serials saleable, or post ledger/history entries. Posting validates again inside the voucher transaction and creates one quantity-IN ledger entry per serial using the existing `serial_id` link. Serial history records the voucher, user, location and effective opening date.

Existing serials are reusable only when they match business/product/variant/batch/branch/warehouse, both statuses are `in_stock`, condition is saleable, and there is no previous ledger entry, purchase/sale reference, customer/sold timestamp, or movement history beyond creation/metadata edits. New numbers must be unique across the business, including deleted records. Normalization follows the serial module: uppercase, ignoring whitespace and hyphens.

## Files and responsibilities

- `app/Services/VoucherSerialService.php`: assignment/count validation, eligible candidates, opening posting, POS sale and opening reversal checks; reuses `SerialNumberService`, `StockService`, `ProductSerialNumber` and `SerialNumberHistory`.
- `app/Services/OpeningStockService.php`: saves draft assignments, invokes serial posting/reversal within transactions, locks draft edits and reversal.
- `app/Services/SerialNumberService.php`: serial creation lock, business-wide duplicate check, history effective date presentation.
- `app/Services/StockService.php`: retains serial identity on reversal entries.
- `app/Services/SalesService.php`: persists assignments, validates/consumes selected serials, updates sale history; rejects unsupported serial invoice reversal.
- `app/Services/SalesReturnService.php`: rejects unsupported serial returns before stock changes.
- `app/Models/OpeningStockItem.php`, `SalesItem.php`, `SerialNumberHistory.php`: JSON/date casts.
- `app/Http/Requests/OpeningStockVoucherRequest.php`, `SalesVoucherRequest.php`: nested serial payload rules.
- `app/Http/Controllers/OpeningStockController.php`, `SalesController.php`, `PosController.php`, `routes/web.php`: candidate endpoints, serialized assignments and POS tracking flag.
- `resources/js/Components/Common/SerialAssignment.vue`: shared inline editor with eligible existing serial search, new/bulk entry for Opening Stock, counts, status/location and remove controls.
- `resources/js/Pages/Inventory/OpeningStock.vue`: multi-serial payload and validation, editor, location clearing, read-only posted assignments. Retains the earlier table-overlap fix.
- `resources/js/Pages/Pos.vue`, `resources/js/Components/Billing/ProductTable.vue`: POS serial selection and count validation.
- `tests/Feature/OpeningStockSerialTest.php`: API integration coverage.

## Migration

`2026_09_09_000001_add_voucher_serial_assignments.php` adds nullable `serials` JSON on `opening_stock_items` and `sales_items`, plus nullable `occurred_at` on `serial_number_histories`. JSON preserves editable draft serial numbers before they have register IDs. Existing ledger/history tables hold posted links; no competing pivot or serial register is created.

## API

`POST /app/inventory/opening-stock` (same item format for draft updates):

```json
{
  "branch_id": 1,
  "warehouse_id": 1,
  "opening_date": "2026-09-09",
  "status": "posted",
  "items": [{
    "product_id": 123,
    "quantity": 1,
    "purchase_cost": 2500,
    "serials": [{
      "serial_number_id": 456,
      "serial_number": "NSWX1-2026-0001356789012345671"
    }]
  }]
}
```

IDs above are examples. New serials use `serial_number_id: null`. POS sales accept the same array but require existing available serial IDs. Candidates are read from `/app/inventory/opening-stock/serials` and `/app/sales/invoices/serials` with product, branch, warehouse, optional variant/batch and search parameters.

## Manual Qty 1 test

1. Refresh Opening Stock. Select the actual Default Branch and Default warehouse.
2. Add SKU `NOISE-X1-BLK`; enter Qty **1** and the correct cost.
3. Open **Manage Serials**. Search for `NSWX1-2026-0001356789012345671` and select the existing result. Confirm **Serials 1/1**.
4. If no result appears, inspect the serial register's location/status and previous transactions. An existing ineligible serial must not be re-entered as new. If the number genuinely does not exist in this business, enter it in **New serial numbers**, then **Add new serials**.
5. Save Draft first if desired: stock and serial register counts must not increase. Reopen the draft and confirm the assignment persists.
6. Click **Confirm & Post**. Confirm the voucher is posted/read-only; current stock increases by one; the register has one in-stock serial, the same location and an opening voucher purchase reference.
7. Open serial details. Verify one `opening_stock` history event and a quantity-IN ledger row linked to that serial, with the opening date and user.
8. In POS choose the same branch/warehouse and product. Attempt sale without a serial: it must fail. Select the serial through **Manage Serials**, then complete the sale: the serial becomes sold, quantity decreases by one and sale history is recorded.
9. Try reversing the original opening voucher after sale: it must fail. For an untouched posted serial, reversal instead posts an OUT entry, preserves history and marks the serial blocked/unavailable.

The supplied exact serial was not found by the read-only check against this checkout's configured database. No application product or serial records were changed to make the test pass.

## Limits

Serial opening currently accepts saleable condition only. Damaged/blocked/sold/reserved/prior-linked serials are rejected. Quantity changes retain assignments and visibly expose shortages/excess; location/variant/batch changes clear selections. Posted Opening Stock cannot be edited. Serial invoice reversal and serial sales returns are explicitly rejected until their controlled correction workflows are implemented.

## Validation

Use a disposable MySQL database with the repository test suite: historical migrations contain MySQL-specific SQL and cannot all run on SQLite. Set `APP_URL=http://localhost` to avoid the local `/billiq` subdirectory affecting HTTP test routes. Never point RefreshDatabase tests at the application database.

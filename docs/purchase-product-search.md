# Purchase product search

## Findings and scope

The frontend already called GET `/app/purchase/bills/products/search?q=...` and rendered selectable results. The local `honey 500gm` record (ID 1, business 1, SKU `H`, barcode `890000000072`) is active stock goods and was returned by the service before the change. The exact cause of the reported browser failure is therefore not established by local reproduction.

Confirmed gaps fixed: overlapping requests could replace newer results, rejected/non-JSON responses had no visible feedback, short names and the legacy barcode field were not searched, and the two-character client minimum prevented the real single-character SKU `H`. Header-only drafts were restored without reopening the purchase drawer. Add Product with blank search did not automatically open its creation form.

Purchase entry fields, calculations, validation, posting, accounting and stock writes are unchanged. Services, non-stock products, inactive and deleted products remain excluded. Batch/serial tracking does not exclude active stock goods.

## API and frontend

- Existing endpoint and response array retained; adds `current_stock` from the product record (no stock aggregation).
- Two-character substring search for name, short name, SKU and primary/legacy/extra barcodes. One-character input resolves exact SKU or primary/legacy barcode only. Empty input returns no products.
- Maximum 20 results, stable name/ID ordering. Variants and active batches are eager loaded. Legacy `company_id` ownership is used only if `business_id` is null; an explicit different business cannot be overridden by company ID.
- 250 ms debounce, stale-response invalidation, loading/error/no-match feedback. Selection uses the existing add-row function and clears pending search results.
- Existing session-storage draft and `added_product_id` return mechanism retained. Header-only drafts reopen; a failed returned-product lookup leaves the restored form usable.

## Verification

- `npm run build`: passed; generated public/build assets and manifest refreshed.
- `node --test tests/frontend/purchase-search.test.mjs`: three tests passed (debounce/race/selection, error recovery, header restoration).
- Added `tests/Feature/PurchaseProductSearchTest.php` covering field matching, ID lookup, tenant isolation, stock/status/tracking rules and result cap.
- `php artisan test --filter='PurchaseProductSearchTest|PurchaseTaxSnapshotTest'`: blocked before assertions by existing migration `2026_07_21_000023_repair_legacy_account_type_column.php`, whose `ALTER TABLE ... MODIFY` is incompatible with SQLite. No purchase logic or migration was changed to bypass it.
- Read-only local service checks passed for honey, exact name, H, 890000000072, id:1 and an unknown name. Matching searches executed three queries; no-match executed one.
- No interactive browser tools were available; full browser/manual and new-product persistence checks remain to be performed.

## Manual retest

1. Refresh the application. Open Purchase Management → Purchases → New Purchase under the business containing honey.
2. Search `honey`, then `honey 500gm`, `H`, and `890000000072` separately. Each should show honey 500gm. Select a result; verify its normal purchase row and all existing editable fields, then remove the row before the next lookup.
3. Search an existing short name and alternate barcode. Verify the correct product.
4. Type rapidly and clear the field while a request is pending. Old results must not reappear. Simulate a failed request and confirm the visible error and recovery on the next search.
5. Search a unique unknown name. Confirm no results, then use Add Product. Set supplier, branch, warehouse and header fields first; retain an existing item too for a second pass.
6. Save a new active stock-goods product. Confirm return to the open purchase drawer, preserved header/items, and automatic addition of the newly saved product. Repeat starting with blank search to check Add Product opens the form.
7. Check active batch/serial products remain selectable, inactive/non-stock products stay excluded, and another business's products never appear.

## Dropdown layering follow-up

The supplied browser screenshot revealed the missing visual cause: global sticky table headers use z-index 20/31, overlapping the dropdown at z-index 20. The purchase drawer table now has an isolated stacking context at level 0, with the product search at level 1. The dropdown is positioned below the actual search row height for both desktop and stacked mobile layouts. This is scoped to the purchase drawer.

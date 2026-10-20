# New Purchase entry UX

The New/Edit Purchase drawer now uses item cards instead of the wide input table. Product identity and line total sit above labelled quantity, free quantity, rate, discount type/value, GST, sell price and MRP inputs. The existing header, product search, Add Product and save actions remain.

Inventory details open automatically for batch/expiry tracking, variants, or existing inventory values. Variant is shown only when available/selected. Batch, manufacturing date, expiry and optional location remain accessible in the secondary section, including for simple products; no location-required configuration was available in the search response, so none is invented. Tracking metadata already returned by search is now retained on new rows. Existing drafts without metadata retain access to all optional fields.

Totals bind to a new read-only POST `/app/purchase/bills/preview-totals` endpoint, guarded by existing purchase access checks. It validates calculation inputs and calls the unchanged PurchaseCalculationService used when saving. No database records are read or written by the calculation. It allows empty items and zero quantity during entry; save validation remains authoritative. Debounced previews discard stale responses and hide outdated totals while updating. The summary displays subtotal, item discount, voucher discount, taxable amount, GST, cess, rounding, grand total, paid and balance. JavaScript only sums returned discount/tax amounts for display; it does not implement tax/discount formulas.

Desktop uses a seven-field grid and a wider drawer (maximum 1360px). At 1150px it wraps to four columns; at 700px to two columns, with stacked identity and summary. Inventory fields wrap independently. Save actions are sticky. Routine item entry has no horizontal table scrolling.

Changed source files: Purchases.vue, PurchaseApi.js, PurchaseController.php and routes/web.php. Tests updated: purchase-search.test.mjs and PurchaseProductSearchTest.php. Build assets regenerated. No calculation, GST, posting, schema or stock business logic changed.

Validation: production build passed; four frontend regression tests passed; PHP syntax and diff checks passed. Existing calculator smoke check for 2 × 250, 5% GST and 100 paid returned total 525 and balance 425. The new endpoint feature test is provided but the PHP feature suite remains blocked by the previously identified SQLite-incompatible legacy migration. No browser automation is installed, so actual responsive rendering remains a manual check.

Manual checks:
1. Hard refresh; open New Purchase and add honey 500gm. Check product/SKU, all primary fields and line total.
2. Enter quantity 2, rate 250, GST 5, no discount and paid 100: total 525, balance 425. Change discount type/value and verify summary updates after the debounce. Check empty/invalid values show unavailable totals rather than stale ones.
3. Expand simple-product inventory details. Add a batch/expiry/variant product and confirm automatic expansion and editable fields. Remove items and check summary refresh.
4. At 1440px, 1024px and 768px (and 390px mobile), verify readable inputs, no horizontal clipping, search suggestions above items, and reachable sticky actions with multiple items.
5. Check supplier/header fields and Add Product return preserve the draft. Do not post the honey voucher as part of this UI task; end-to-end posting is a separate user-directed test.

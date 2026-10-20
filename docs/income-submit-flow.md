# Income Approve and Post investigation

## Reproduction and findings

The exact requested browser submission (Default Branch, 13 September 2026, Shipping Income category/account, Cash in Hand, party `test customer`, taxable 150, total 150) returned HTTP 201 and created `OIN-2026-00001`. The original complete no-row symptom did not reproduce in this session. No browser JavaScript exception occurred during the successful click; the route, request validation, database insert and posting engine accepted the payload.

The following frontend defects were confirmed:

- The Income tab did not render field-keyed errors. `capture()` stored Laravel's `errors` map, while the page displayed only `errors.form`; errors such as `total_amount`, `status` or `voucher_date` therefore disappeared from view.
- `saveIncome()` ignored the returned voucher and had no success message. It waited for the full `loadAll()` chain, including expense references/list and unrelated modules, to update the table. A failure before the income-list request could hide a successful insert. Income listing also inherited invisible filters from the Expenses tab.
- The handler never retained the saved ID and had no immediate in-flight guard. A later click created a new voucher rather than updating the saved draft; posted inputs remained editable.
- Existing-row Post had no error catch and depended on the same broad reload.

## Fix

`resources/js/Pages/Accounting/Expenses.vue` now submits through a real form, displays all validation and server/network/session errors in an Income alert, and shows a persistent success message with the voucher number. The returned voucher is immediately inserted/replaced in the table. Income lists no longer inherit Expense filters. Draft saves retain their ID; posted forms are disabled until New Income is selected. Both submit and row-post handlers guard in-flight requests and catch failures. No calculation changes were made.

`app/Services/ExpenseIncomeService.php` locks an existing income voucher during edit/post transactions, serializing repeat posting of the same voucher before the existing already-posted check. Journal calculations and account selection remain unchanged.

Tests changed/added: `tests/Feature/ExpenseIncomeCategoriesTest.php`, `tests/frontend/income-submit.test.mjs`. Vite build assets/manifest were regenerated.

## Verification

- Real local browser request created one posted voucher: ID 2, `OIN-2026-00001`, journal ID 84. The earlier API diagnostic ran inside a rolled-back transaction.
- Read-only database check found exactly two journal entries: **Dr Cash in Hand (CASH) 150.00; Cr Shipping Income (SHIP-I) 150.00**.
- Browser validation with total 0 returned 422 and visibly displayed `Total amount is below the allowed minimum.`
- Browser success/locking/table verification replayed the already-created voucher response to avoid another live financial transaction: success message visible, one matching row, posted form disabled.
- Simulated HTTP 500 visibly displayed the server error. No uncaught browser JavaScript exceptions were observed. Desktop and 390px mobile screenshots were inspected.
- PHP focused suite: **4 tests, 77 assertions passed**, including the exact request payload, database insert, list endpoint, exact debit/credit and repeated posting without more entries.
- Frontend suite: **4 tests passed**, covering in-flight/repeated submits, draft ID reuse, success/table update, error handling and existing-row posting.
- `npm run build`, PHP syntax and diff whitespace checks passed. Existing bundle-size/Browserslist warnings remain.

The live reproduction voucher remains in the local database as requested. The earlier reported no-row failure cannot be attributed to a specific backend error without the failing response; the confirmed feedback and refresh defects above have been fixed and tested.

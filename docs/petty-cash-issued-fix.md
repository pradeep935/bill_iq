# Petty Cash Issued ₹100 backend fix

## Confirmed root cause

The 13 September 2026 17:38:13 Laravel log records `AccountingPostingService::addDebitEntry(): Argument #2 ($accountId) must be of type int, null given`, called from postPettyCashAdvance. An exact request reproduction returned HTTP 500 with `{ "message": "Server Error" }`.

The business had both petty_cash_advance_account_id and supplier_advance_account_id null, and no PETTY-ADV account. The original expense foundation migration defines PETTY-ADV, but the later lazy default-settings path omitted it. The employee/branch validation succeeded. The failure occurred during journal construction after the advance insert, before commit/response serialization; the surrounding transaction correctly rolled the advance back.

## Repair

- `database/seeders/PettyCashAccountSeeder.php`: initializes only missing petty-cash accounting setup. Uses the already-defined PETTY-ADV code/name. New account is an asset with debit nature, which works without groups (this business has none). Existing valid legacy ledger accounts with a debit-nature group are also reusable. Existing mappings (including the configured supplier-advance fallback), existing account balances, inactive/deleted accounts and user choices are preserved. No guessed expense/cash mapping.
- `app/Models/BusinessAccountSetting.php`: invokes setup when new business accounting settings are initialized.
- `database/seeders/DatabaseSeeder.php`: includes the repair in normal seeding.
- `database/migrations/2026_09_13_000002_initialize_missing_petty_cash_accounts.php`: data-only repair for existing settings; no schema changes. Rollback preserves mappings that may have posted entries.
- `app/Services/AccountingPostingService.php`: rejects a missing, inactive, deleted or foreign-business debit account with a clear status validation error before calling the typed entry method. Reads refreshed settings because initialization may fill the mapping. Existing debit/credit rules and transaction boundaries remain unchanged.
- `resources/js/Pages/Accounting/Expenses.vue`: safe actionable fallback for unexpected 5xx responses (never raw SQL/stack details), preserved field-specific 422 messages, session-expiry feedback, red required markers and required attributes for Cash Account, Advance Date, Advance Amount, Purpose and Status. Employee retains its required marker; Branch remains optional.
- `tests/Feature/ExpenseIncomeCategoriesTest.php`, `tests/frontend/petty-cash.test.mjs`: setup idempotency, preservation, failed-posting rollback/cash stability and safe error-message regression coverage.
- Generated Vite assets/manifest and this report.

## Exact live browser verification

Default Branch; Dummy Employee — DUMMY-001; Cash in Hand; 13/09/2026; amount 100; purpose Testing petty cash advance; Issued. The unmocked request returned HTTP 201, success text and one visible row. No browser JavaScript exception was observed. Repeat save was disabled after success.

Created advance **PC-2026-00001**, ID 3, journal ID 85. Database contains one petty-cash advance for the business, with settled_amount 0 and balance_amount 100.

| Account | Debit | Credit |
|---|---:|---:|
| Employee Petty Cash Advance — PETTY-ADV (ID 22) | 100.00 | 0.00 |
| Cash in Hand — CASH (ID 1) | 0.00 | 100.00 |

Cash current_balance changed **11270.00 → 11170.00**. Advance account balance changed **0.00 → 100.00**. The test advance remains in the local database.

## Checks

- 7 backend tests, 157 assertions passed. Employee/business/branch/active validation retained. Failed posting rolls back the inserted advance, journal entries and cash change. Existing income/category/recurring/posting regressions passed.
- 12 frontend tests passed, including validation, duplicate-click protection, employee filtering and 5xx sanitization.
- Production build, PHP syntax, diff whitespace checks passed. Existing Browserslist/bundle size warnings remain.
- Scoped data repair ran successfully; rerunning its seeder added no duplicate account or mapping.
- No schema, voucher numbering or settlement changes.

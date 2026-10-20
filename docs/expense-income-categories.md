# Expenses > Categories implementation report

## Changes

- `resources/js/Pages/Accounting/CategoryManager.vue`: separate labeled expense/income cards, helper text, independent save/error/success states, edit/cancel, activate/deactivate, mapped account/status lists and pagination. Two columns above 1000px; stacked below, with single-column fields below 480px and horizontally scrollable tables.
- `resources/js/Pages/Accounting/Expenses.vue`: mounts category manager and refreshes voucher category references after saves.
- `app/Http/Requests/ExpenseCategoryRequest.php`, `IncomeCategoryRequest.php`: business-scoped code/name uniqueness, required fields and valid account validation.
- `app/Models/Account.php`: shared category-specific eligible-account query.
- `app/Services/ExpenseIncomeService.php`: additional category account reference lists. Existing voucher reference lists and posting logic retained.
- `app/Models/BusinessAccountSetting.php`: initializes valid category defaults after accounting settings creation.
- `database/seeders/ExpenseIncomeCategorySeeder.php`, `DatabaseSeeder.php`: repeatable per-business initialization.
- `database/migrations/2026_09_13_000001_seed_valid_expense_income_categories.php`: data-only backfill for existing businesses; rollback preserves potentially used categories.
- `database/migrations/2026_07_21_000009_create_expense_income_cash_bank_tables.php`: removes unsafe historical bulk category mappings from fresh installation flow, retaining ledger/settings initialization. Already-applied migration records are not rerun.
- `tests/Feature/ExpenseIncomeCategoriesTest.php`: focused API, seeding and posting regression coverage.
- Vite generated build assets and manifest updated.

## Existing architecture

Existing GET list, POST create, PUT edit/status and DELETE routes already existed for both category types. UI uses list/create/update only; no new endpoints. Lists return 50 rows per page, including inactive categories; voucher references continue returning active categories only.

`expense_categories.expense_account_id` references `accounts.id`; existing legacy `name` and `account_id` fields are kept synchronized on save. `income_categories.income_account_id` references `accounts.id`. Both tables already contain business_id, code, name, status and soft deletion fields. No schema columns/tables added.

Category account choices require same business, active, not deleted, matching expense/income type, and not a control account. Configured sales, purchase, returns, COGS and round-off account roles and their standard account codes are excluded. Account names are not used to classify ledgers. The same query validates category API submissions. Existing voucher account dropdowns/posting rules remain unchanged.

## Applied defaults (local business 1)

| Category code | Category | Ledger code | Ledger ID |
|---|---|---|---|
| SHIP-EXP | Shipping Expense | SHIP-E | 21 |
| SHIP-INC | Shipping Income | SHIP-I | 20 |

Both category tables were empty before the change. The scoped migration inserted these two records; rerunning the seeder left exactly one of each.

Skipped: Office Expense, Electricity Expense, Internet / Telephone Expense, Travel Expense, Repair & Maintenance, Miscellaneous Expense, Commission Income, Other / Miscellaneous Income. No corresponding ledgers exist in the inspected chart. No fake ledgers or guessed mappings were created. The seeder intentionally knows only the two relevant ledgers defined by the standard chart; custom ledgers can be mapped explicitly through the UI.

The seeder locks the company row in a transaction and checks code, name or existing use of the mapped ledger, including inactive and soft-deleted records. It never updates existing categories. API uniqueness is scoped per business and excludes the edited record; deleted codes/names remain reserved. New companies receive valid defaults when accounting settings are created, which the existing posting setup currently does lazily, rather than immediately at company registration.

## Findings

The historical migration assigned rent, electricity, commission and other unrelated categories to shipping ledgers (or the first account of the type), using updateOrInsert. That fresh-install behavior has been removed. Existing business mappings are preserved. No posting engine changes were made.

A pre-existing generic ExampleTest expects `/` to return 200, but it returns 404. The normal SQLite migration chain also contains MySQL-only SQL. The focused test fixture excludes unrelated MySQL-only repair/index migrations; this does not alter production migrations or claim coverage of those repairs.

A confirmed expense-save bug was also found: `saveExpense()` wrote `updated_by`, which does not exist in either the migration or the inspected live `expense_vouchers` table. Removed that invalid attribute assignment, preserving the existing created/approved audit fields and timestamps. No new schema or journal logic was required.

## Validation results

- `php artisan test --filter=ExpenseIncomeCategoriesTest`: **3 passed, 59 assertions**. Covers expense/income creation, edit/status, required fields, duplicate codes/names, correct account relationships, active voucher references, invalid/foreign ledger rejection, tenant-safe defaults, repeated seeding, unchanged user data, deleted category preservation, automatic defaults on settings creation, balanced cash expense/income posting, exact debit/credit account selection and repeated posting without extra journal entries.
- `npm run build`: passed. Existing Browserslist freshness and large bundle warnings remain.
- PHP syntax checks and `git diff --check`: passed.
- Scoped local data migration and subsequent seeder rerun: passed; one category per type remains for business 1.
- Responsive breakpoints and table overflow are implemented and reviewed in source; no interactive desktop/mobile browser validation was performed. No browser automation package/tool is installed in this workspace.
- Broader voucher/reversal/GST suites were not run; their implementation was not changed. The focused posting test uses non-taxable paid cash transactions.
- The generic ExampleTest fails under this environment's forced base URL configuration. The focused API fixture explicitly uses localhost to avoid the application subdirectory being prepended to test requests.

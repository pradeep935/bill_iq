# Petty Cash UI review

## Field mapping

All visible fields bind to `pettyForm.<key>`, use the same request payload and `PettyCashAdvanceRequest` validation key, and persist to `petty_cash_advances.<key>`.

| Label | Key | Meaning and validation |
|---|---|---|
| Branch | branch_id | Optional branch assignment; nullable integer, database foreign key to branches. |
| Employee ID | employee_id | Numeric employee reference stored on the advance; required integer. No employee lookup, model relation or foreign key. |
| Cash Account | cash_account_id | Account credited on issue; required integer, service enforces same-business active cash account. |
| Advance Date | advance_date | Advance date and journal date when issued; required date. |
| Advance Amount | amount | Amount advanced; required numeric, minimum 0.01, stored decimal(15,2). |
| Purpose | purpose | Reason for the advance; required text up to 255 characters. |
| Status | status | Required draft or issued. |

## Workflow

Save Advance POSTs the existing create endpoint. Draft persists a record with balance_amount equal to amount and settled_amount zero, without a journal. Issued persists and immediately posts in a database transaction: debit configured petty_cash_advance_account_id (fallback supplier_advance_account_id), credit selected cash_account_id. It records journal_voucher_id and approved_by. There is no separate approval stage exposed.

Only GET `/app/accounting/petty-cash/list` and POST `/app/accounting/petty-cash` exist. The service has an optional ID argument for draft updates, but no HTTP update route exposes it. No later issue, approve, settle, edit or delete route is present. No actions column was added. The UI explicitly explains this limitation, particularly for drafts. Settlement fields exist but no petty-cash settlement implementation was found.

## Employee and account findings

A payroll `employees` master and Employee model exist, but petty cash has no `employee()` relation, employee foreign key, or existence/business validation. Employee::advances points to the separate payroll EmployeeAdvance model. Therefore the manual ID is retained and labeled honestly; employee names are not inferred. This existing integrity limitation is reported, not silently changed.

Cash choices already come from same-business active cash/bank references and are filtered by `account_type === 'cash'` in this form. Account model soft-deletion scope excludes deleted accounts. The service explicitly accepts only cash; bank choices would violate current behavior, so they were not added. Posting requires the existing configured advance debit account; no replacement mappings or ledgers were introduced.

## UI changes and files

- `resources/js/Pages/Accounting/Expenses.vue`: visible associated labels for all seven fields, useful placeholders/helpers, draft/issued consequence text, responsive grid, primary save button, field errors plus alert summary, success with voucher number, immediate returned-row update, repeated-click guard and explicit New Advance reset. Added Existing Petty Cash Advances heading, table headers for number/date/employee ID/purpose/cash account/amount/status, empty state and existing pagination.
- `tests/frontend/petty-cash.test.mjs`: draft/issued feedback, row update, duplicate click guard, validation/network error feedback and reset coverage.
- `tests/Feature/ExpenseIncomeCategoriesTest.php`: real API draft/issue validation, list account relationship and exact debit/credit behavior with configured advance ledger.
- Vite assets/manifest and this report.

No production backend code, accounting, numbering, settlement or schema changes were made. Previously field-keyed validation errors were captured but hidden on this tab and success had no feedback; these UI defects are fixed.

## Validation

Production build passed with existing Browserslist/bundle-size warnings. Backend suite: 6 tests, 120 assertions passed. Frontend income/recurring/petty-cash suites: 10 tests passed. Diff whitespace check passed.

Browser exercised real required-field validation, simulated a successful save response to avoid a live advance, checked immediate list/success/disabled repeat save and status-specific help, and captured desktop/mobile layouts. Actual draft and issued database/posting behavior was tested in the isolated backend test database.

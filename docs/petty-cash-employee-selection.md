# Petty cash employee selection

Reuses `App\Models\Employee` and the existing `employees` payroll/master table. The shared SearchSelect component searches `display_name — employee_code` and submits the selected `employees.id` as `employee_id`. No employee master or schema was added.

The existing payroll employee-list endpoint includes salary assignments and is restricted to payroll/master permissions. Instead of exposing those fields or broadening permissions, the existing accounting reference response now supplies only id, display_name, employee_code and branch_id for eligible employees.

Eligible employees belong to the current `AppController::businessId()`, have both status=active and employment_status=active, and are not soft-deleted. Employees are reliably branch-linked through employees.branch_id. With a branch selected, the UI and save validation require an exact branch match; without one, all eligible employees in the current business are available. Changing branch/reference data clears a selection that no longer qualifies.

`PettyCashAdvanceRequest` now validates branch ownership and rejects nonexistent, foreign-business, inactive, non-active-employment, soft-deleted or wrong-branch employees. The employee check runs on every save, so a selection that became inactive since loading produces a clear employee-specific error. The frontend displays it both at the select and in the error summary.

The new PettyCashAdvance employee relationship resolves existing employee_id values. List/save responses load only employee identity columns and constrain the relationship to the current business. Existing inactive/soft-deleted same-business employees remain name/code-readable; missing or foreign-business references fall back to “Employee unavailable (legacy record)”. Existing records/IDs are not rewritten. Posting logic is untouched.

## Files

- resources/js/Pages/Accounting/Expenses.vue
- app/Http/Requests/PettyCashAdvanceRequest.php
- app/Models/PettyCashAdvance.php
- app/Services/ExpenseIncomeService.php
- tests/Feature/ExpenseIncomeCategoriesTest.php
- tests/frontend/petty-cash.test.mjs
- tests/frontend/income-submit.test.mjs and recurring-template.test.mjs (inject watch into existing harness)
- Generated Vite assets/manifest and this report.

## Verification

- Production build passed (existing Browserslist/bundle-size warnings).
- Six backend tests, 145 assertions passed, including invalid/missing/foreign/inactive/resigned/deleted/wrong-branch employee rejection, historical employee display, business data isolation, and existing draft/issued accounting behavior.
- Eleven frontend tests passed, including branch filtering, name/code display and clearing invalid selections, plus prior submission regressions.
- Browser test with simulated reference identities and save error verified search by employee code, readable selection, numeric primary-key payload and visible stale-employee feedback without writing a live advance.
- PHP syntax and diff whitespace checks passed.

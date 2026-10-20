# Petty cash draft lifecycle

## Endpoints

Existing POST `/app/accounting/petty-cash` creation is unchanged.

- PUT `/app/accounting/petty-cash/{advance}`: update an unposted draft. Validates branch, employee, cash account, date, amount and purpose with the existing request. Status must remain draft; issuing through update is rejected.
- POST `/app/accounting/petty-cash/{advance}/issue`: locks the business-scoped stored draft, rejects issued/journal-linked records, revalidates current employee/branch eligibility and active cash account, calls existing postPettyCashAdvance, then sets issued/journal/approval fields in the same transaction.
- DELETE `/app/accounting/petty-cash/{advance}`: locks and deletes only an unposted draft. Never deletes a journal or ledger entry.

All three use the existing authenticated accounting route group and current business scope. Row locks serialize concurrent operations on the same advance. A journal-link/reference check also rejects inconsistent draft records already associated with accounting. Repeated Issue returns a validation error after the first successful issue; it does not repost.

## UI

Existing table now has Actions. Drafts show Edit, Issue and Delete; issued records show none. Edit preloads the six editable fields and correctly converts serialized dates to local calendar dates. It offers Cancel Edit and saves through PUT, with no accounting effect. Existing status/required indicators remain consistent, and edit exposes only Draft status. Issue and Delete require confirmation. Actions have busy guards, visible business/validation errors, safe unexpected-error messages, and update the list immediately.

## Accounting and compatibility

The existing issued-on-create behavior, voucher numbering and postPettyCashAdvance calculations are unchanged. Issuing a saved draft produces Dr configured advance ledger / Cr selected cash account exactly once. Edit/Delete never post. Save/post remains transactional.

PC-2026-00002 (ID 4) was confirmed as an unposted draft for 100. Its actual stored data successfully passed the new Issue service inside an explicitly rolled-back transaction, producing Dr PETTY-ADV 100 and Cr CASH 100, then rejecting repeated Issue. Cash moved 11170 to 11070 only inside that transaction. After rollback the record remains Draft and live cash remains 11170. No permanent issue/delete was performed on this user draft.

## Files

- app/Services/ExpenseIncomeService.php
- app/Http/Controllers/AccountingController.php
- routes/web.php
- resources/js/Pages/Accounting/AccountingApi.js
- resources/js/Pages/Accounting/Expenses.vue
- tests/Feature/ExpenseIncomeCategoriesTest.php
- tests/frontend/petty-cash.test.mjs
- Generated build assets/manifest and this report.

## Tests

- Backend regression suite: 7 tests, 182 assertions passed. Includes Create Draft without posting; Edit Draft without posting; explicit Issue; repeated Issue rejection; inactive-employee rejection at issue; delete another Draft without accounting impact; issued edit/delete rejection; Cash/Bank Book endpoint returning exactly one credit for the issued draft.
- Frontend suites: 13 tests passed, including confirmation cancellation, actions/row updates, issued-record guard, visible business errors and existing income/recurring/petty-cash tests.
- Production build, PHP syntax and diff whitespace checks passed. Existing Browserslist and bundle-size warnings remain.
- Existing draft browser checks: Edit prefill/date, Cancel Edit, Issue/Delete confirmations (dismissed), and no draft-only actions on the issued row.

No database schema changes.

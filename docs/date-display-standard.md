# Indian date display standard

Use resources/js/Support/dateFormat.js for frontend read-only output:
- formatDate(value): DD/MM/YYYY
- formatDateTime(value): DD/MM/YYYY, hh:mm AM/PM
- formatDateField(key, value): date-aware table/export cell formatting.

Timezone is explicitly Asia/Kolkata. Date-only values are calendar dates. Offset/Z timestamps represent instants and are converted to Kolkata. Untagged application timestamps are interpreted as Kolkata wall time. Null/invalid values display an em dash. Sorting, filters, input models and backend storage remain raw and unchanged.

Corrected pages: Accounting/Vouchers (Recent Journals, receipt invoices, payment bills), Accounting/Ledgers (account/customer/supplier/cash-bank ledgers and both outstanding tables, CSV export), Accounting/Expenses; Purchase/Purchases, PurchaseReturns, InventoryOrders, ReorderSuggestions, Workflow (including CSV date cells); Sales/SalesInvoices, SalesReturns, Customers (detail/history/outstanding displays).

Shared components updated: Components/Common/DateShow.vue, Components/Billing/ProductTable.vue, Pages/Inventory/Shared/InventoryTable.vue and formatters.js. Inventory date-time formatting now delegates to the shared helper. Custom table slots continue to control their own output.

No backend timezone defect was established. Frontend inconsistencies included raw ISO strings, hyphen-splitting DateShow, month-name billing dates and browser-dependent date conversion. Backend timezone defaults to Asia/Kolkata and was not modified. Database, API, accounting, reversal and allocation logic unchanged.

Validation: npm run build passed; all 18 frontend tests passed. Date tests also passed with TZ=America/Los_Angeles, covering UTC midnight rollover, calendar-only dates, naive Kolkata timestamps, 12-hour AM/PM, missing values and export field selection. Browser/manual verification remains pending.

Manual check: hard refresh, verify Recent Journals, ledger/date/due columns, open invoice/bill allocations, purchase list, sales invoice detail and customer history. Confirm 2026-09-12T18:30:00.000000Z displays 13/09/2026. Check ledger/PO CSV date cells, native input editing and unchanged date filtering. This review covers these frontend surfaces and shared consumers; separately rendered server PDF/print templates and unrelated legacy admin pages were not audited as part of this frontend change.

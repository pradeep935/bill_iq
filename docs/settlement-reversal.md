# Receipt and supplier payment reversal

Root cause: reverseJournalVoucher created opposite journal entries and marked only the original journal reversed. Receipt/payment source status, ledger_allocations and sales/purchase settlement fields were untouched. Ledger queries excluded reversed originals but included approved reversals, causing a double economic effect in displayed running balances.

Fix: lock and reload the source journal before reversal; reject duplicate reversal and reversing reversal journals. Preserve reversed original entries in ledger/cash-book queries. For receipt/payment journals, add negative compensating allocation records against the existing reversal's party journal line. Keep original allocations intact. Each compensation is identified by reversal entry plus original allocation ID, making reconciliation idempotent. Lock source documents, subtract reversed allocations from paid fields, update balance/payment status, mark receipt/payment reversed and link reversal_of_id. All changes occur transactionally. No migration.

Receipt entries: original Dr Cash 160 / Cr Receivable 160; reversal Dr Receivable 160 / Cr Cash 160. Supplier payment reversals use Dr Cash / Cr Payable. Both originals and reversals remain in audit history.

Verified local case: journal REC-2026-00002 (65), source receipt RCPT-2026-00002 (5), reversal REV-2026-00001 (66), sales invoice INV-2026-00025 (27). Applied allocation-only reconciliation to this confirmed existing reversal after rollback tests. No new journal/cash posting was created. Persisted invoice now paid 200, balance 160, status partial; customer ledger runs 360,160,0,160. Source receipt now reversed. Other historical settlements were not repaired in bulk.

Tests: tests/Manual/settlement-reversal.php exercises known receipt reopening, idempotence, existing cash preservation, new settlement/reversal/re-settlement, linked headers, duplicate rejection, cash and party balances; equivalent supplier payment 450 path passes. All regression writes rolled back. Ten frontend tests and PHP syntax/diff checks passed. Browser retest still required. The requested cash/book and outstanding screens should now show the restored 160 after refresh.

Files: app/Services/AccountingPostingService.php; app/Services/AccountingService.php; tests/Manual/settlement-reversal.php; this document.

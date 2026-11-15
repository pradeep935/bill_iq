# BillIQ Tally Data Migration Tool — client guide

1. Open TallyPrime and select the company you want to migrate. Keep it running.
2. Ask your BillIQ Super Admin to open **Accounting → Tally Integration** and authorize a new importer for the correct BillIQ company.
3. Download and open `BillIQ-Tally-Importer.exe`. Windows may show a publisher warning until BillIQ distributes a code-signed release.
4. Select **Detect TallyPrime**. If it is not found, open the company in TallyPrime and enable its local XML/HTTP interface. Do not make that interface public.
5. Paste the one-time pairing code provided by the Super Admin and choose **Pair with BillIQ**.
6. Select the detected company and choose **Full Migration** for the initial move, or **Incremental Sync** for a later run.
7. Select **Start Import**. You can close and retry after a network error; BillIQ de-duplicates staged uploads.
8. Wait for the completion summary. The tool only stages data. It does not change Tally or post BillIQ accounting records.
9. Tell the Super Admin the batch is ready. They must review mappings and reconciliation before any explicit posting.

## What may need manual review

Receipts and payments need BillIQ invoice allocations. Stock groups, godowns, cost centres, payroll, manufacturing detail, TDS/TCS detail, voucher allocations, and reliable stock valuation are not automatically posted by BillIQ.

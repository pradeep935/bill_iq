# Bill IQ Tally Recovery Status - 2026-10-14

## Scope

- Project: `G:\iq`
- Database: `bl` on `127.0.0.1:3306`
- Client: HARI SIVANANDA JI ENTERPRISES
- User-confirmed GSTIN: `05AABCP5818M1Z7`
- Target Bill IQ company: ID `2`
- Preserve Bill IQ company ID `1` ABC Retail unchanged

## Backup And Source Preservation

- Database backup created: `storage/app/tally-recovery/backups/bl_backup_20261014_1124.sql`
- Backup size: `7,700,855` bytes
- Original Tally ZIP left unchanged at: `C:\Users\Dell\Downloads\TALLY DATA 13.10.26.zip`
- Original ZIP SHA-256: `39B802D9D18AE2C3329FB16FCEE3F54A0BE74270FB433C46259778A38200A15B`
- Working extraction path: `storage/app/tally-recovery/source`

## Tally Folder Inventory

The ZIP contains native Tally data folders. These were inventoried only; native binary contents were not reverse-engineered.

| Folder | Files | Bytes |
| --- | ---: | ---: |
| `010000` | 17 | 275,456 |
| `010001` | 17 | 6,151,680 |
| `010002` | 19 | 6,248,448 |
| `010003` | 19 | 71,027,712 |
| `10000-migrated-to-5.1.0` | 13 | 136,704 |
| `10001-migrated-to-5.1.0` | 12 | 2,186,752 |
| `10002-migrated-to-3.0` | 13 | 2,353,152 |
| `10003-migrated-to-3.0` | 13 | 1,082,368 |
| `update data tally 13.10.2022/10000 new` | 13 | 131,584 |
| `update data tally 13.10.2022/10002 old` | 13 | 1,069,056 |

Important: folder size is not sufficient identity evidence. No company was selected based on size.

## Identity Findings

- Bill IQ company ID `2` currently exists as `PHOOLTAS TRANSRAIL LIMITED (provisional)`.
- Company ID `2` GSTIN is already set to `05AABCP5818M1Z7`.
- Branch ID `3`: `Main Branch`, business ID `2`.
- Warehouse ID `3`: `Main Warehouse`, business ID `2`, branch ID `3`.
- Existing GST evidence batch ID `1`: business/company ID `2`, `gstr1_json`, status `committed_as_evidence`, source GSTIN `05AABCP5818M1Z7`, period `082026`.
- Company ID `1` ABC Retail has existing masters/vouchers and was not modified.

## Extraction Status

Migration is blocked before any import writes.

Reason: the source files are native TallyPrime `.1800` and older `.900` data files. A plain-text scan did not expose usable company, GSTIN, master, voucher, or financial-year data. TallyPrime is not installed or running locally:

- No `tally.exe` found on PATH.
- No TallyPrime install found in standard `Program Files` paths checked.
- No Tally process was running.
- No local Tally ODBC listener was found on port `9000`.
- No Tally/TallyPrime installer was found in `C:\Users\Dell\Downloads`; only the original ZIP was present.

Per the migration rule, accounting transactions must not be fabricated or reverse-engineered from incomplete binary data.

## Official Tally Recovery Route

Official Tally documentation indicates that TallyPrime must be installed and opened locally to restore, repair, load, and export company data. Current official download page: `https://tallysolutions.com/download/`.

Observed official limitations and requirements:

- TallyPrime installation is a Windows desktop install from Tally Solutions.
- A paid license activation requires Serial Number, Activation Key, Administrator Email ID, and unlock key.
- Without activation, TallyPrime runs in Educational Mode for demonstration use with limitations.
- TallyVault-enabled data requires the TallyVault password.
- Damaged data can be repaired only through TallyPrime repair/zero-command repair workflows, and repair can alter the working copy. It must not be run on the original ZIP or only copy.
- ODBC extraction requires TallyPrime to be open and ODBC enabled.
- Tally documentation notes ODBC is suitable for masters such as ledgers and stock items, while trial balance and ledger transactions are not fully available through ODBC and should be exported through TallyPrime reports/XML/Excel where supported.

Approval required before continuing with installation:

1. Download the official TallyPrime installer from `https://tallysolutions.com/download/`.
2. Install TallyPrime locally.
3. Open TallyPrime in activated or educational mode, without accepting license/terms on the user's behalf unless approved.
4. Configure the company data path to a fresh copy of `storage/app/tally-recovery/source/TALLY DATA 13.10.26/TALLY PRIME DATA`.
5. Load each visible company folder; if prompted for migration/repair, back up the working copy first and record all prompts.
6. Export company info, masters, vouchers, registers, stock summary, trial balance, receivables, payables, and GST reports to a new local export folder.

## TallyPrime Local Verification Attempt

TallyPrime was installed locally after the initial recovery pass.

- Executable found: `C:\Program Files\TallyPrime\tally.exe`
- Migration utility found: `C:\Program Files\TallyPrime\tallymigrationtool.exe`
- Existing source working copy preserved: `G:\iq\storage\app\tally-recovery\source`
- Disposable verification copy created: `G:\iq\storage\app\tally-recovery\disposable\TALLY PRIME DATA`
- Tally local config backup created: `G:\iq\storage\app\tally-recovery\disposable\tally.ini.before-disposable-data`
- Tally config was temporarily pointed at the disposable data path and later restored to its original settings.

The Tally XML service on port `9000` responded successfully, but returned zero loaded companies while pointed at the disposable data path:

- Company count: `0`
- Ledger count: `0`
- Stock item count: `0`
- Voucher count: `0`

Likely load IDs were tested through Tally's startup `Load=` setting against the disposable copy:

- `010000`, `010001`, `010002`, `010003`
- `10000`, `10001`, `10002`, `10003`
- `100000`, `100001`, `100002`, `100003`

None loaded a company through the XML interface. No disposable Tally data files were modified during this pass after the copy was created.

Important limitation: the Tally desktop window launched from the tool environment was not visible/interactive, so manual UI selection from TallyPrime's "Select Company" / "Specify Path" screen could not be completed by the agent. No repair, migration, conversion, or export was performed.

Current verification result: HARI SIVANANDA JI ENTERPRISES was not yet located inside TallyPrime. Company GSTIN, financial years, voucher availability, and export availability remain unverified until the company is manually selected/opened in the visible TallyPrime UI, or until TallyPrime exposes the selected company through XML after manual loading.

## Required To Continue

Provide one of the following supported extraction paths:

1. Install TallyPrime locally and point it at the working copy under `storage/app/tally-recovery/source/TALLY DATA 13.10.26/TALLY PRIME DATA`, then export XML/Excel reports for each real company and financial year.
2. Provide TallyPrime exports directly: company info, ledger masters, stock masters, vouchers, inventory movements, trial balance, receivables/payables, and GST reports.
3. Provide a Tally ODBC/XML gateway connection that can query/export the loaded companies.

At minimum, the exported package must identify company names, GSTINs, financial years, voucher types, voucher numbers, dates, ledgers, item lines, GST/tax values, opening balances, and stock quantities.

With the current machine state and no client-supplied exports, the practical next step is local TallyPrime installation and opening the existing working copy.

## Existing Genuine Structured Evidence

Bill IQ already contains one GST evidence import for company ID `2`:

| Batch | Period | Rows | Taxable | CGST | SGST | IGST | Cess | Total |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | `082026` | 7 | 176,257,540.36 | 0.00 | 0.00 | 31,726,357.27 | 0.00 | 207,983,897.63 |

This batch is evidence-only and must not create duplicate invoices, products, ledgers, or stock entries.

## Bill IQ Mapping Plan

- Tally ledger groups -> `account_groups`
- Tally ledgers -> `accounts`, with customer/supplier creation only when ledger group/GST behavior proves the party role
- Sundry Debtors -> `customers`
- Sundry Creditors -> `suppliers`
- Tally stock groups/units/items -> `product_categories`, `units`, `products`
- Sales vouchers -> `sales_vouchers`, `sales_items`, `sales_payments`, `journal_vouchers`, `journal_entries`, `stock_ledgers`
- Purchase vouchers -> `purchase_vouchers`, `purchase_items`, `journal_vouchers`, `journal_entries`, `stock_ledgers`
- Credit/debit notes -> sales/purchase return modules or journal vouchers depending on source voucher type and inventory effect
- Receipts/payments/journals -> `receipt_vouchers`, `payment_vouchers`, `journal_vouchers`, `journal_entries`
- Opening stock -> `opening_stock_entries`, `opening_stock_items`, `stock_ledgers`
- GST evidence -> `gst_import_batches`, `gst_import_rows`, `gst_reconciliation_items`

All rows must preserve Tally source company, source folder, source voucher GUID/key if exported, voucher number, date, ledger names, item names, GSTINs, tax values, and import batch reference.

## Validation Gates Before Import

- Verify company identity and GSTIN from exported Tally company data.
- Detect duplicate migrated folders and overlapping financial years.
- Compare source and target counts by module.
- Check duplicate voucher keys per company.
- Check debit equals credit for accounting vouchers.
- Check opening balance totals and prevent double-counting with transaction-ledger balances.
- Reconcile stock opening, inward, outward, and closing quantities.
- Reconcile GST taxable/tax totals and ensure existing GSTR-1 batch ID `1` remains evidence-only.
- Abort import on missing masters, unsupported voucher types, unresolved tax mismatch, or imbalance.

## Testing Status

- PHPUnit/Artisan could not run because `php` on PATH is `7.4.29`; this Laravel app requires PHP `>=8.2.0`.
- Playwright end-to-end tests were not run because the Laravel app cannot start with the available PHP CLI.

## Import Status

- No migration import writes were performed.
- Company ID `2` was not renamed yet because extraction and reconciliation have not passed.
- No ABC Retail records were changed.

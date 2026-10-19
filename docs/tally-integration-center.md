# Tally Integration Center

BillIQ now includes a tenant-scoped Tally connection and staging pipeline at **Accounting → Tally Integration**.

## Current verified behavior

- Local and cloud connection metadata can be created without storing credentials in plaintext.
- Pairing codes are single-use setup codes in the UI and are accepted only for the selected company.
- Connector ingestion requires an `X-BillIQ-Connector-Token` and uses the shared normalized payload shape.
- Repeated `(company, source type, source key)` records are updated rather than duplicated.
- Exact-name matches are suggested automatically for BillIQ accounts, customers, suppliers and products.
- Native TallyPrime XML fragments inside an `ENVELOPE` are normalized for companies, groups, ledgers, units, stock items and vouchers; namespaced XML, missing optional fields, cancellations, alterations, GST ledger allocations, inventory allocations and preserved raw XML are handled.
- Approved master import creates or reuses ledger groups, accounts, customers, suppliers, units and products within the current business; source keys are retained for repeat-sync safety.
- Unmatched source records remain staged and block approval.
- Reconciliation compares available sales, purchases, journal totals, party balances, opening balances and GST totals, and reports `unavailable` when stock valuation, warehouse quantities or another comparable posted basis does not exist.
- Approved normalized `sales`, `purchase`, `receipt`, `payment`, `journal`, `contra`, `sales_return`, and `purchase_return` records are posted through BillIQ services in a transaction; a failed record rolls back the batch.
- Tally is read-only; no public port forwarding is required.

## Connector setup

1. Open Accounting → Tally Integration and create a Local connection.
2. Run TallyPrime with the XML service available on its local interface (normally `127.0.0.1:9000`).
3. Pair the connector using the displayed code. Keep the code private; it becomes the connector bearer token until the connection is revoked.
4. On the Windows machine, run `scripts/tally-connector.ps1` with the BillIQ HTTPS URL and token.

Example:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\tally-connector.ps1 `
  -BillIqUrl https://your-billiq-host.example `
  -ConnectorToken ABCD-EFGH
```

The script is a reference connector, not a signed MSI installer. Packaging, code signing, auto-update, Windows service installation, and provider-specific cloud APIs remain external deployment work. Hosted Windows Tally should run the connector on that Windows host; its XML port must not be exposed to the internet.

The connector supports `-TallyUrl`, company selection, date variables, full/incremental mode, upload batching, exponential retry, a local resume state file, and clear HTTP/Tally error reporting. Tally report XML varies by version and configuration; the connector preserves the raw voucher XML in each staged record. A provider HTTPS API is only tested when a provider name, endpoint, and access token are configured; no provider is claimed as supported by default.

## Deliberate remaining gates

The import endpoint requires an administrator-approved batch. Unsupported voucher types, missing dates, unbalanced journal/contra data, or voucher rows without resolvable party/item/ledger mappings remain staged and are not posted. Opening balances are imported onto newly-created masters but are not silently rewritten on existing masters; posting them as accounting opening-balance journals still requires the existing BillIQ opening-balance workflow. GST/HSN values are parsed from source fields and tax ledgers, while a full HSN/GST master synchronization still requires target-version fixtures and explicit mapping rules. A real TallyPrime instance or provider API credentials are required to verify report names and field availability.

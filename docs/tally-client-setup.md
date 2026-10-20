# BillIQ Tally client setup

This is a client launcher, not a signed Windows installer. It does not install a Windows service, change Tally data, or store the BillIQ token.

## Before starting

1. Use a Windows PC that can open the hosted BillIQ site over HTTPS.
2. Open the required company in TallyPrime and keep TallyPrime running.
3. In BillIQ, open **Accounting → Tally Integration**, choose **Local TallyPrime** or **Hosted Windows / RDP connector**, and create a pairing code.
4. Download the complete `scripts` folder supplied by BillIQ. Do not email the token in plain text.

## Start the connector

Double-click `Start-TallyConnector.cmd`. Enter:

- BillIQ HTTPS address
- The **one-time pairing code** displayed by BillIQ (not a connector token)
- Tally XML address, normally `http://127.0.0.1:9000` unless the Tally host is configured differently
- Company name, or leave blank to select from the detected companies
- Optional start and end dates in `YYYY-MM-DD`

The launcher first exchanges the one-time pairing code over HTTPS for its own connector token. That token is never shown or saved by the launcher; it exists only while the connector is running. The launcher then checks the date range before connecting. It reports unavailable Tally, invalid XML, invalid or used pairing codes, expired pairing, and HTTPS errors in plain language.

## Complete the BillIQ workflow

After the connector reports success:

1. Return to **Tally Integration**.
2. Open the fetched batch and review unmatched or unsupported records.
3. Resolve all required mappings. Approval is blocked while unresolved records remain.
4. Click **Approve review** as an administrator.
5. Click **Import approved** only after previewing the totals.
6. Click **Reconcile** and review every mismatch or unavailable comparison.

BillIQ does not write back to Tally. Re-running a batch uses stable source identifiers and connector request IDs to avoid duplicate staging and duplicate financial posting.

## Supported connection choices

- **Local TallyPrime:** supported through this Windows connector.
- **Hosted TallyPrime:** supported when this connector runs on the Windows/RDP host where Tally is available.
- **Provider API:** no provider API is currently enabled by default. Do not select or claim this option unless BillIQ has supplied a provider-specific adapter and credentials.

## Client testing checklist

Use a non-production Tally company and a non-production BillIQ business.

- Confirm company name and GUID.
- Test a one-day fetch containing one ledger, one customer, one supplier, one stock item, one sales voucher and one receipt.
- Confirm raw XML, source identifiers and normalized dates are visible in the batch.
- Confirm existing same-name masters are matched rather than duplicated.
- Confirm an unresolved mapping blocks approval.
- Confirm approval is administrator-only.
- Confirm importing the same source again does not create a second voucher.
- Confirm a deliberately invalid token returns an authentication error.
- Confirm Tally closed/unavailable returns a connection error.
- Confirm reversed dates are rejected before fetch.
- Stop the connector after one uploaded batch, restart it, and confirm resume does not duplicate records.
- Compare sales, purchases, party balances, GST totals and journal totals.
- Record stock valuation and opening-balance journal checks as unavailable/not implemented unless separately verified.

Do not use live client financial data until a BillIQ administrator signs off this checklist against a running TallyPrime instance.

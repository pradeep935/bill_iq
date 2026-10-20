# BillIQ Tally Data Migration Tool

This is the Windows GUI source for `BillIQ-Tally-Importer.exe`. It is a read-only client for local TallyPrime XML and connects to BillIQ only over HTTPS.

## What it does

1. Scans local loopback ports `9000`–`9005` for TallyPrime XML.
2. Lists open Tally companies.
3. Exchanges a BillIQ Super Admin's one-time code for a separate connector credential.
4. Protects that credential with Windows DPAPI for the current Windows user.
5. Exports only the currently supported reports: groups, ledgers, units, stock items, and Day Book vouchers.
6. Transfers batches of 100 records with three retries and deterministic request IDs.
7. Saves local resume metadata; BillIQ applies request and source-record idempotency.

It never writes to Tally, opens an internet listener, posts accounting entries, or exposes a BillIQ credential in the UI.

## Build a release EXE

Use the `Build BillIQ Tally Importer` GitHub Actions workflow. It publishes a self-contained `win-x64` single-file executable artifact. On a Windows build machine the equivalent command is:

```powershell
dotnet publish BillIQ.TallyImporter.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o ..\..\dist\BillIQ-Tally-Importer
```

## Acceptance testing required

Before release, test with a visible TallyPrime installation and non-production BillIQ business: XML disabled, XML enabled on a non-default port, closed/no selected company, expired and used code, interrupted upload, a large company, duplicate rerun, and each supported voucher type. Review every batch in BillIQ before approving it.

param(
    [string]$ConnectorScript = "$PSScriptRoot\tally-connector.ps1"
)

$ErrorActionPreference = 'Stop'
$host.UI.RawUI.WindowTitle = 'BillIQ Tally Connector'

function Read-Required([string]$Prompt) {
    do { $value = Read-Host $Prompt } while ([string]::IsNullOrWhiteSpace($value))
    return $value.Trim()
}

Write-Host 'BillIQ Tally Connector' -ForegroundColor Cyan
Write-Host 'This launcher does not install software, modify Tally, or store your token.'
Write-Host 'Keep TallyPrime open and do not expose its XML port to the internet.' -ForegroundColor Yellow
Write-Host ''

if (-not (Test-Path -LiteralPath $ConnectorScript)) { throw "Connector file not found: $ConnectorScript" }
$billIqUrl = Read-Required 'BillIQ HTTPS address (example: https://app.example.com)'
$token = Read-Required 'BillIQ connector token'
$tallyUrl = Read-Host 'Tally XML address [http://127.0.0.1:9000]'
if ([string]::IsNullOrWhiteSpace($tallyUrl)) { $tallyUrl = 'http://127.0.0.1:9000' }
$company = Read-Host 'Company name (optional; leave blank to choose from the list)'
$fromText = Read-Host 'Start date YYYY-MM-DD (optional; press Enter for all available dates)'
$toText = Read-Host 'End date YYYY-MM-DD (optional; press Enter for today/all available dates)'

$from = $null; $to = $null
if ($fromText) { if (-not [datetime]::TryParseExact($fromText, 'yyyy-MM-dd', $null, [Globalization.DateTimeStyles]::None, [ref]$from)) { throw 'Start date must use YYYY-MM-DD.' } }
if ($toText) { if (-not [datetime]::TryParseExact($toText, 'yyyy-MM-dd', $null, [Globalization.DateTimeStyles]::None, [ref]$to)) { throw 'End date must use YYYY-MM-DD.' } }
if ($from -and $to -and $from.Date -gt $to.Date) { throw 'Start date cannot be later than end date.' }

$arguments = @{ BillIqUrl=$billIqUrl; ConnectorToken=$token; TallyUrl=$tallyUrl }
if ($company) { $arguments.CompanyName = $company }
if ($from) { $arguments.DateFrom = $from }
if ($to) { $arguments.DateTo = $to }
try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ConnectorScript @arguments
    if ($LASTEXITCODE -ne 0) { throw "Connector stopped with exit code $LASTEXITCODE." }
    Write-Host 'Finished. Sign in to BillIQ and review the staged batch before approval.' -ForegroundColor Green
} catch {
    Write-Host "Connector could not complete: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Check: TallyPrime is open, the XML address is correct, the BillIQ address uses HTTPS, and the token has not expired or been revoked.' -ForegroundColor Yellow
    Read-Host 'Press Enter to close'
    exit 1
}
Read-Host 'Press Enter to close'

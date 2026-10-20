param(
    [Parameter(Mandatory=$true)][string]$BillIqUrl,
    [Parameter(Mandatory=$true)][string]$PairingCode,
    [string]$TallyUrl = 'http://127.0.0.1:9000',
    [string]$CompanyName,
    [string]$CompanyGuid,
    [datetime]$DateFrom,
    [datetime]$DateTo,
    [switch]$Full,
    [string]$StatePath = "$PSScriptRoot\tally-connector-state.json",
    [int]$BatchSize = 100,
    [int]$MaxRetries = 3
)

$ErrorActionPreference = 'Stop'
if (-not $BillIqUrl.StartsWith('https://')) { throw 'BillIQ URL must use HTTPS.' }
$TallyUrl = $TallyUrl.TrimEnd('/')
if ($DateFrom -and $DateTo -and $DateFrom.Date -gt $DateTo.Date) { throw 'DateFrom cannot be later than DateTo. Choose a valid date range.' }
if ($BatchSize -lt 1) { throw 'BatchSize must be at least 1.' }
if ($MaxRetries -lt 1) { throw 'MaxRetries must be at least 1.' }

function Get-NodeText($Node, [string]$Name) {
    $value = $Node.SelectSingleNode(".//*[local-name()='$Name']")
    if ($null -eq $value -and $Node.Attributes[$Name]) { return $Node.Attributes[$Name].Value.Trim() }
    if ($null -eq $value) { return $null }
    return $value.InnerText.Trim()
}

function Exchange-PairingCode {
    try {
        $response = Invoke-RestMethod -Method Post -Uri ("{0}/api/tally/connector/pair" -f $BillIqUrl.TrimEnd('/')) -ContentType 'application/json' -Body (@{ pairing_code=$PairingCode } | ConvertTo-Json) -TimeoutSec 30
        if ([string]::IsNullOrWhiteSpace([string]$response.connector_token)) { throw 'BillIQ did not return a connector token.' }
        return [string]$response.connector_token
    } catch {
        $detail = $_.ErrorDetails.Message
        if ($detail) { throw "Pairing failed: $detail" }
        throw "Pairing failed. Confirm the code is entered exactly once within 15 minutes: $($_.Exception.Message)"
    }
}

function Invoke-Tally([string]$Report, [hashtable]$Variables = @{}) {
    $static = '<SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>'
    foreach ($key in $Variables.Keys) { $static += "<$key>$($Variables[$key])</$key>" }
    $xml = "<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Data</TYPE><ID>$Report</ID></HEADER><BODY><DESC><STATICVARIABLES>$static</STATICVARIABLES></DESC></BODY></ENVELOPE>"
    $attempt = 0
    while ($true) {
        try {
            $response = [xml](Invoke-RestMethod -Method Post -Uri $TallyUrl -ContentType 'application/xml' -Body $xml -TimeoutSec 20)
            $lineError = $response.SelectSingleNode('//*[local-name()="LINEERROR"]')
            if ($lineError -and $lineError.InnerText.Trim()) { throw "Tally rejected '$Report': $($lineError.InnerText.Trim())" }
            return $response
        }
        catch {
            $attempt++
            if ($attempt -ge $MaxRetries) { throw "Tally request '$Report' failed after $MaxRetries attempts: $($_.Exception.Message)" }
            Start-Sleep -Seconds ([math]::Min(8, [math]::Pow(2, $attempt)))
        }
    }
}

function Get-Companies {
    $response = Invoke-Tally 'List of Companies'
    $companies = @()
    foreach ($node in @($response.SelectNodes('//*[local-name()="COMPANY"]'))) {
        $name = Get-NodeText $node 'NAME'; $guid = Get-NodeText $node 'GUID'
        if ($name) { $companies += [pscustomobject]@{ Name=$name; Guid=$guid } }
    }
    if ($companies.Count -eq 0) { throw 'Tally responded but no company was detected. Open a company in TallyPrime.' }
    return $companies
}

function Select-Company($Companies) {
    $selected = $null
    if ($CompanyGuid) { $selected = $Companies | Where-Object { $_.Guid -eq $CompanyGuid } }
    elseif ($CompanyName) { $selected = $Companies | Where-Object { $_.Name -eq $CompanyName } }
    elseif ($Companies.Count -eq 1) { $selected = $Companies[0] }
    else {
        Write-Host 'Companies detected in TallyPrime:'
        for ($i = 0; $i -lt $Companies.Count; $i++) { Write-Host ("[{0}] {1} ({2})" -f ($i + 1), $Companies[$i].Name, $Companies[$i].Guid) }
        $choice = Read-Host 'Select company number'; $selected = $Companies[[int]$choice - 1]
    }
    if ($null -eq $selected) { throw 'The requested Tally company was not found.' }
    return $selected
}

function Convert-Report($Response, [string]$Type) {
    $rows = @()
    foreach ($node in @($Response.SelectNodes('//*[local-name()="COMPANY" or local-name()="GROUP" or local-name()="LEDGER" or local-name()="UNIT" or local-name()="STOCKITEM" or local-name()="VOUCHER"]'))) {
        $guid = Get-NodeText $node 'GUID'; $number = Get-NodeText $node 'VOUCHERNUMBER'; $name = Get-NodeText $node 'NAME'
        $key = $guid; if (-not $key) { $key = $number }; if (-not $key) { $key = $name }; if (-not $key) { continue }
        $rowType = $Type
        if ($node.LocalName -eq 'COMPANY') { $rowType = 'company' } elseif ($node.LocalName -eq 'GROUP') { $rowType = 'group' } elseif ($node.LocalName -eq 'LEDGER') { $rowType = 'ledger' } elseif ($node.LocalName -eq 'UNIT') { $rowType = 'unit' } elseif ($node.LocalName -eq 'STOCKITEM') { $rowType = 'stock_item' } elseif ($node.LocalName -eq 'VOUCHER') { $rowType = 'voucher' }
        $rows += @{ type=$rowType; key=$key; guid=$guid; name=$name; date=(Get-NodeText $node 'DATE'); number=$number; debit=(Get-NodeText $node 'DEBIT'); credit=(Get-NodeText $node 'CREDIT'); quantity=(Get-NodeText $node 'QTY'); payload=@{ tally_type=$Type; xml=$node.OuterXml } }
    }
    return $rows
}

try {
    Write-Host 'Exchanging one-time BillIQ pairing code...'
    $connectorToken = Exchange-PairingCode
    Write-Host 'Paired. The connector token is kept only in this running process.' -ForegroundColor Green
    Write-Host "Testing TallyPrime at $TallyUrl ..."
    $companies = Get-Companies; $company = Select-Company $companies
    Write-Host "Selected: $($company.Name)"
    $state = @{ completed = @{} }
    if ((-not $Full) -and (Test-Path -LiteralPath $StatePath)) {
        $saved = Get-Content -Raw -LiteralPath $StatePath | ConvertFrom-Json
        foreach ($property in $saved.completed.PSObject.Properties) { $state.completed[$property.Name] = $true }
    }
    $variables = @{}
    if ($DateFrom) { $variables.SVFROMDATE = $DateFrom.ToString('yyyyMMdd') }
    if ($DateTo) { $variables.SVTODATE = $DateTo.ToString('yyyyMMdd') }
    $all = @()
    $reports = @(@{ name='Group'; type='group' }, @{ name='Ledger'; type='ledger' }, @{ name='Unit'; type='unit' }, @{ name='Stock Item'; type='stock_item' }, @{ name='Day Book'; type='voucher' })
    foreach ($report in $reports) { Write-Host "Fetching $($report.type) data..."; $all += Convert-Report (Invoke-Tally $report.name $variables) $report.type }
    $pending = @($all | Where-Object { -not $state.completed.ContainsKey([string]$_.key) })
    Write-Host ("Fetched {0} records; {1} remain after resume filtering." -f $all.Count, $pending.Count)
    $headers = @{ 'X-BillIQ-Connector-Token'=$connectorToken }
    for ($offset = 0; $offset -lt $pending.Count; $offset += $BatchSize) {
        $end = [math]::Min($offset + $BatchSize - 1, $pending.Count - 1); $chunk = @($pending[$offset..$end])
        $body = @{ mode=if($Full){'full'}else{'incremental'}; company_name=$company.Name; company_guid=$company.Guid; date_from=if($DateFrom){$DateFrom.ToString('yyyy-MM-dd')}else{$null}; date_to=if($DateTo){$DateTo.ToString('yyyy-MM-dd')}else{$null}; records=$chunk } | ConvertTo-Json -Depth 12
        $uploaded = $false
        $requestId = [guid]::NewGuid().ToString()
        for ($attempt = 1; $attempt -le $MaxRetries -and -not $uploaded; $attempt++) {
            try { $headers['X-Connector-Request-Id'] = $requestId; Invoke-RestMethod -Method Post -Uri ("{0}/api/tally/connector/ingest" -f $BillIqUrl.TrimEnd('/')) -Headers $headers -ContentType 'application/json' -Body $body -TimeoutSec 90 | Out-Null; $uploaded = $true }
            catch { if ($attempt -eq $MaxRetries) { throw "BillIQ upload failed for batch starting at record ${offset}: $($_.Exception.Message)" }; Start-Sleep -Seconds ([math]::Min(15, [math]::Pow(2, $attempt))) }
        }
        foreach ($row in $chunk) { $state.completed[[string]$row.key] = $true }
        $state | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $StatePath -Encoding UTF8
        Write-Host ("Uploaded {0}/{1}" -f ($end + 1), $pending.Count)
    }
    Write-Host 'Sync complete. Review mappings and approve the batch in BillIQ before posting.'
} catch { Write-Error $_.Exception.Message; exit 1 }

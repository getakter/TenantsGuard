<#
    TenantsGuard script runner (trusted wrapper).

    Security model
    - The context (token, parameters, demo data) arrives on STDIN as one JSON document, never
      on the command line or in environment variables, so it is not visible in process listings.
    - The access token lives only inside a private dynamic module. The approved script can use
      it only through Invoke-TGGraph, which accepts relative Microsoft Graph paths, enforces
      read-only scripts, and caps the number of calls.
    - After setup the session is switched to ConstrainedLanguage. The approved script runs in
      that mode: no Add-Type, no arbitrary .NET, no COM. The switch cannot be undone.
    - Results are written between markers and parsed by TenantsGuard; everything else is log.
#>
param([Parameter(Mandatory = $true)][string]$ScriptPath)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$InformationPreference = 'Continue'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

function Write-TGMarker {
    param([string]$Kind, $Data)
    $json = ConvertTo-Json -InputObject $Data -Depth 6 -Compress
    [Console]::Out.WriteLine("<<<TG:$Kind>>>$json<<<TG:END>>>")
}

try {
    $ctx = [Console]::In.ReadToEnd() | ConvertFrom-Json
} catch {
    Write-TGMarker 'ERROR' @{ message = 'The runner did not receive a valid context.'; line = 0 }
    exit 2
}

# ------------------------------------------------------------------ private helper module
$null = New-Module -Name TenantsGuard -ArgumentList $ctx -ScriptBlock {
    param($ctx)
    $script:Token = [string]$ctx.graphToken
    $script:Demo = [bool]$ctx.demo
    $script:ReadOnly = [bool]$ctx.readOnly
    $script:DemoData = $ctx.demoData
    $script:Tenant = $ctx.tenant
    $script:Calls = 0
    $script:MaxCalls = [int]$ctx.maxGraphCalls

    function Test-TGBudget {
        $script:Calls++
        if ($script:Calls -gt $script:MaxCalls) { throw "The Microsoft Graph call limit for one tenant ($($script:MaxCalls)) was reached." }
    }

    function Get-TGDemoValue([string]$Path) {
        $prop = $script:DemoData.PSObject.Properties[$Path]
        if ($prop) { return , $prop.Value }
        # /users/{id or upn} -> item from the /users collection
        $parent = $Path.Substring(0, $Path.LastIndexOf('/'))
        $key = [uri]::UnescapeDataString($Path.Substring($Path.LastIndexOf('/') + 1))
        $coll = $script:DemoData.PSObject.Properties[$parent]
        if ($coll) {
            foreach ($item in $coll.Value) {
                if ($item.id -eq $key -or $item.userPrincipalName -eq $key) { return , $item }
            }
            throw "Resource '$key' does not exist."
        }
        throw "The demo tenant has no data for $Path. Available: " + (($script:DemoData.PSObject.Properties.Name) -join ', ')
    }

    <#
        .SYNOPSIS Calls Microsoft Graph for the current tenant.
        .EXAMPLE Invoke-TGGraph -Uri '/users?$select=displayName,userPrincipalName' -All
        .EXAMPLE Invoke-TGGraph -Method PATCH -Uri "/users/$id" -Body @{ accountEnabled = $false }
    #>
    function Invoke-TGGraph {
        [CmdletBinding()]
        param(
            [Parameter(Mandatory = $true)][string]$Uri,
            [ValidateSet('GET', 'POST', 'PATCH', 'PUT', 'DELETE')][string]$Method = 'GET',
            $Body,
            [switch]$All,
            [switch]$Beta
        )
        if ($Uri -notmatch '^/[A-Za-z]') { throw "Use a relative Microsoft Graph path such as '/users'." }
        if ($script:ReadOnly -and $Method -ne 'GET') { throw "This script is approved as read-only, so $Method requests are blocked." }
        Test-TGBudget

        if ($script:Demo) {
            $path = ($Uri -split '\?')[0].TrimEnd('/')
            if ($Method -ne 'GET') {
                Write-Information "[demo] $Method $path was simulated. Demo tenants are not changed by scripts."
                return [ordered]@{ demo = $true; method = $Method; path = $path }
            }
            $value = Get-TGDemoValue $path
            if ($value -is [array]) { return $value } else { return $value }
        }

        $base = if ($Beta) { 'https://graph.microsoft.com/beta' } else { 'https://graph.microsoft.com/v1.0' }
        $headers = @{ Authorization = "Bearer $($script:Token)"; ConsistencyLevel = 'eventual' }
        $req = @{ Uri = $base + $Uri; Method = $Method; Headers = $headers; ContentType = 'application/json'; TimeoutSec = 90 }
        if ($null -ne $Body) { $req.Body = ConvertTo-Json -InputObject $Body -Depth 10 }
        $res = Invoke-RestMethod @req
        if ($Method -eq 'GET' -and $null -ne $res -and $res.PSObject.Properties['value']) {
            $res.value
            while ($All -and $res.'@odata.nextLink') {
                if (-not ([string]$res.'@odata.nextLink').StartsWith('https://graph.microsoft.com/')) { throw 'Unexpected paging host.' }
                Test-TGBudget
                $res = Invoke-RestMethod -Uri $res.'@odata.nextLink' -Headers $headers -Method GET -TimeoutSec 90
                $res.value
            }
        } else {
            $res
        }
    }

    function Get-TGTenant {
        [ordered]@{ Name = $script:Tenant.name; TenantId = $script:Tenant.id; Domain = $script:Tenant.domain; Demo = $script:Demo; ReadOnly = $script:ReadOnly }
    }

    Export-ModuleMember -Function Invoke-TGGraph, Get-TGTenant
}

# ------------------------------------------------------------------ optional connections (trusted setup)
try {
    if ($ctx.useGraphModule) {
        if ($ctx.demo) { throw 'The Microsoft Graph PowerShell module is not available for demo tenants. Use Invoke-TGGraph instead.' }
        Import-Module Microsoft.Graph.Authentication -ErrorAction Stop
        Connect-MgGraph -AccessToken (ConvertTo-SecureString $ctx.graphToken -AsPlainText -Force) -NoWelcome -ErrorAction Stop
    }
    if ($ctx.useExchange) {
        if ($ctx.demo) { throw 'Exchange Online is not available for demo tenants.' }
        if (-not $ctx.exchangeToken) { throw 'No Exchange Online token. Grant the app Exchange.ManageAsApp and the Exchange Administrator role in this tenant.' }
        Import-Module ExchangeOnlineManagement -ErrorAction Stop
        $exo = @{ AccessToken = $ctx.exchangeToken; Organization = $ctx.tenant.initialDomain; ShowBanner = $false; ErrorAction = 'Stop' }
        if ($ctx.readOnly) { $exo.CommandName = @($ctx.exchangeReadCommands) }
        Connect-ExchangeOnline @exo
    }
} catch {
    Write-TGMarker 'ERROR' @{ message = 'Connection failed: ' + $_.Exception.Message; line = 0 }
    exit 3
}

$splat = @{}
if ($ctx.parameters) { foreach ($p in $ctx.parameters.PSObject.Properties) { $splat[$p.Name] = $p.Value } }

# Drop references to secrets from this scope before untrusted code runs.
$ctx = $null
[GC]::Collect()

# ------------------------------------------------------------------ run the approved script, constrained
$ExecutionContext.SessionState.LanguageMode = 'ConstrainedLanguage'
try {
    $results = @(& $ScriptPath @splat)
    Write-TGMarker 'RESULTS' $results
    exit 0
} catch {
    Write-TGMarker 'ERROR' @{ message = $_.Exception.Message; line = $_.InvocationInfo.ScriptLineNumber }
    exit 1
}

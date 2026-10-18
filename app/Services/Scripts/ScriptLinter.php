<?php

namespace App\Services\Scripts;

/**
 * Static checks on a script before it can be submitted for approval. This is defence in depth:
 * the hard boundary is ConstrainedLanguage mode plus the private token module in runner.ps1.
 * "Blocked" findings stop submission; "warnings" are shown to the approver.
 */
final class ScriptLinter
{
    /** command or token (case-insensitive, whole word) => why it is blocked */
    private const BLOCKED = [
        'Invoke-Expression' => 'Runs arbitrary strings as code.', 'iex' => 'Alias of Invoke-Expression.',
        'Add-Type' => 'Compiles .NET code.', 'Start-Process' => 'Starts programs on the TenantsGuard server.', 'saps' => 'Alias of Start-Process.',
        'Invoke-WebRequest' => 'Direct web requests can send data outside Microsoft 365. Use Invoke-TGGraph.', 'iwr' => 'Alias of Invoke-WebRequest.',
        'Invoke-RestMethod' => 'Direct web requests can send data outside Microsoft 365. Use Invoke-TGGraph.', 'irm' => 'Alias of Invoke-RestMethod.',
        'curl' => 'Web request.', 'wget' => 'Web request.', 'Start-BitsTransfer' => 'File transfer.', 'Send-MailMessage' => 'Sends data by email.',
        'Resolve-DnsName' => 'Network lookups can leak data.', 'Test-NetConnection' => 'Network access from the server.', 'Test-Connection' => 'Network access from the server.',
        'Invoke-Command' => 'Remote execution.', 'icm' => 'Alias of Invoke-Command.', 'Enter-PSSession' => 'Remote session.', 'New-PSSession' => 'Remote session.',
        'Start-Job' => 'Background jobs escape the time limit.', 'Start-ThreadJob' => 'Background jobs escape the time limit.',
        'Register-ScheduledTask' => 'Persistence on the server.', 'schtasks' => 'Persistence on the server.', 'New-Service' => 'Persistence on the server.',
        'Set-ExecutionPolicy' => 'Changes server security settings.', 'Set-Service' => 'Changes server services.', 'Stop-Computer' => 'Server shutdown.', 'Restart-Computer' => 'Server restart.',
        'Import-Module' => 'Modules are loaded by the runner. Choose the connection options instead.', 'ipmo' => 'Alias of Import-Module.',
        'Install-Module' => 'Installs code on the server.', 'Install-Package' => 'Installs code on the server.',
        'Connect-MgGraph' => 'The runner connects for you.', 'Connect-ExchangeOnline' => 'The runner connects for you.', 'Connect-AzAccount' => 'Not supported.',
        'Get-Module' => 'Inspects the trusted runner.', 'Get-Variable' => 'Inspects runner variables.', 'gv' => 'Alias of Get-Variable.', 'Get-MgContext' => 'Exposes connection details.',
        'Get-Credential' => 'Interactive credential prompt.', 'ConvertFrom-SecureString' => 'Unwraps secrets.',
        'Get-Content' => 'Reads files on the server.', 'gc' => 'Alias of Get-Content.', 'Set-Content' => 'Writes files on the server.', 'Add-Content' => 'Writes files on the server.',
        'Out-File' => 'Writes files on the server. Return objects instead; TenantsGuard exports them.', 'Export-Csv' => 'Writes files on the server. Return objects instead.',
        'New-Item' => 'Creates files on the server.', 'Remove-Item' => 'Deletes files on the server.', 'Copy-Item' => 'Copies files on the server.', 'Move-Item' => 'Moves files on the server.',
        'Get-ChildItem' => 'Lists files on the server.', 'gci' => 'Alias of Get-ChildItem.', 'dir' => 'Lists files on the server.', 'ls' => 'Lists files on the server.',
        'New-PSDrive' => 'Mounts drives.', 'Get-WmiObject' => 'Server inspection.', 'Get-CimInstance' => 'Server inspection.', 'Invoke-CimMethod' => 'Server changes.',
        'cmd' => 'Runs the Windows command shell.', 'powershell' => 'Starts another PowerShell outside the sandbox.', 'pwsh' => 'Starts another PowerShell outside the sandbox.',
    ];

    /** Regular expressions for constructs that are not simple command names. */
    private const BLOCKED_PATTERNS = [
        '/\$env:/i' => 'Reads server environment variables.',
        '/\benv:\\\\?/i' => 'Reads server environment variables.',
        '/\[\s*(System\.)?(Reflection|Runtime\.InteropServices|Diagnostics\.Process|IO\.File|IO\.Directory|Net\.|Environment\b|Management\.Automation\.)/i' => 'Uses .NET types that reach outside the sandbox.',
        '/\bDownload(String|File|Data)\b/i' => 'Downloads content from the internet.',
        '/-ComObject\b/i' => 'Creates COM objects.',
        '/\bNew-Object\b[^\n]*\bNet\./i' => 'Creates network clients.',
        '/\.Module\b/i' => 'Inspects the trusted runner module.',
        '/\bSessionState\b/i' => 'Touches the session state (language mode, variables).',
        '/\bLanguageMode\b/i' => 'Touches the language mode.',
        '/\$ExecutionContext\b/i' => 'Touches the execution context.',
        '/\b[A-Z]:\\\\/i' => 'Uses a local file path.',
        '/\\\\\\\\[A-Za-z0-9.-]+\\\\/' => 'Uses a network share path.',
        '/\[\s*pscustomobject\s*\]/i' => 'Not available in Constrained Language Mode. Use [ordered]@{ Name = ... } instead; it is shown and exported the same way.',
        '/\bNew-Object\b(?![^\n]*\bPSObject\b)/i' => 'Only New-Object PSObject is allowed. Use [ordered]@{ } for results.',
    ];

    private const WARN = [
        '/Invoke-TGGraph[^\n]*-Method\s+[\'"]?DELETE/i' => 'Deletes objects in Microsoft 365.',
        '/Invoke-TGGraph[^\n]*-Method\s+[\'"]?(PATCH|PUT|POST)/i' => 'Changes Microsoft 365 objects.',
        '/\b(Remove|Disable|Revoke|Reset|Set|New|Update|Add)-(Mg|EXO|Mailbox|Distribution|Transport|Inbox|Unified|CAS|Organization)/i' => 'Uses a cmdlet that changes Microsoft 365.',
        '/-Confirm:\s*\$false/i' => 'Suppresses confirmation prompts.',
        '/ForEach-Object\s*-Parallel/i' => 'Parallel execution increases load on Microsoft Graph.',
    ];

    /**
     * @param  array  $parameters  declared parameter definitions (name, ...)
     * @return array{blocked: array<int, array{line:int, text:string, why:string}>, warnings: array<int, array{line:int, text:string, why:string}>}
     */
    public static function lint(string $body, array $parameters = [], string $risk = 'read'): array
    {
        $blocked = [];
        $warnings = [];
        $lines = preg_split('/\r\n|\r|\n/', self::stripComments($body));
        foreach ($lines as $i => $line) {
            $code = self::stripStrings($line);
            foreach (self::BLOCKED as $cmd => $why) {
                if (preg_match('/(?<![\w$.-])'.preg_quote($cmd, '/').'(?![\w-])/i', $code)) {
                    $blocked[] = ['line' => $i + 1, 'text' => $cmd, 'why' => $why];
                }
            }
            foreach (self::BLOCKED_PATTERNS as $re => $why) {
                // Patterns check the raw line too: paths and env access are just as dangerous inside strings.
                if (preg_match($re, $line)) {
                    $blocked[] = ['line' => $i + 1, 'text' => trim(mb_substr($line, 0, 80)), 'why' => $why];
                }
            }
            foreach (self::WARN as $re => $why) {
                if (preg_match($re, $line)) {
                    $warnings[] = ['line' => $i + 1, 'text' => trim(mb_substr($line, 0, 80)), 'why' => $why];
                }
            }
        }

        if ($risk === 'read') {
            foreach ($warnings as $w) {
                if (str_contains($w['why'], 'Changes') || str_contains($w['why'], 'Deletes') || str_contains($w['why'], 'changes')) {
                    $blocked[] = ['line' => $w['line'], 'text' => $w['text'], 'why' => 'A read-only script cannot change Microsoft 365. Mark the script as "Makes changes".'];
                }
            }
        }

        foreach ($parameters as $p) {
            if (! preg_match('/\$'.preg_quote($p['name'], '/').'\b/i', $body)) {
                $warnings[] = ['line' => 1, 'text' => '$'.$p['name'], 'why' => 'Declared parameter is not used. Add it to the param() block.'];
            }
        }
        if ($parameters && ! preg_match('/^\s*(\[[^\]]*\]\s*)*param\s*\(/im', $body)) {
            $blocked[] = ['line' => 1, 'text' => 'param()', 'why' => 'The script declares parameters but has no param() block.'];
        }

        return ['blocked' => self::unique($blocked), 'warnings' => self::unique($warnings)];
    }

    private static function unique(array $rows): array
    {
        return array_values(collect($rows)->unique(fn ($r) => $r['line'].'|'.$r['why'])->all());
    }

    private static function stripComments(string $body): string
    {
        // Block comments keep their line breaks so line numbers stay right.
        $body = preg_replace_callback('/<#.*?#>/s', fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $body);

        return preg_replace('/(^|\s)#(?![>]).*$/m', '$1', $body);
    }

    /** Replace string literal contents with blanks so command names inside messages do not trigger. */
    private static function stripStrings(string $line): string
    {
        // Double-quoted strings with $( ) sub-expressions run code, so they are kept for checking.
        $line = preg_replace('/\'[^\']*\'/', "''", $line);

        return preg_replace_callback('/"[^"]*"/', fn ($m) => str_contains($m[0], '$(') ? $m[0] : '""', $line);
    }
}

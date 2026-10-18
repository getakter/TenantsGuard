<?php

namespace App\Services\Scripts;

use App\Models\ScriptRunTarget;
use App\Models\ScriptVersion;
use App\Models\Tenant;
use App\Services\Access\TenantAccess;
use App\Services\Demo\MockGraphClient;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphException;
use App\Support\Access;
use App\Support\MicrosoftApp;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs one approved script version against one tenant in an isolated pwsh process:
 * no profile, scrubbed environment, private temp folder, context over STDIN only,
 * hard timeout, output cap, cancellation, and secret redaction of everything stored.
 */
class ScriptExecutor
{
    /** Read-only Exchange cmdlets imported for read-only scripts (Connect-ExchangeOnline -CommandName). */
    public const EXO_READ_COMMANDS = ['Get-EXOMailbox', 'Get-EXOMailboxStatistics', 'Get-EXOMailboxPermission', 'Get-EXORecipient', 'Get-EXOCasMailbox',
        'Get-EXOMailboxFolderStatistics', 'Get-Mailbox', 'Get-MailboxPermission', 'Get-RecipientPermission', 'Get-InboxRule', 'Get-TransportRule',
        'Get-DistributionGroup', 'Get-DistributionGroupMember', 'Get-UnifiedGroup', 'Get-AcceptedDomain', 'Get-OrganizationConfig', 'Get-MailboxStatistics',
        'Get-HostedOutboundSpamFilterPolicy', 'Get-AntiPhishPolicy', 'Get-SafeLinksPolicy', 'Get-SafeAttachmentPolicy', 'Get-DkimSigningConfig', 'Get-RemoteDomain'];

    /** Collections offered to scripts in demo tenants (Invoke-TGGraph GET). */
    public const DEMO_PATHS = ['/users', '/groups', '/subscribedSkus', '/organization', '/domains', '/directoryRoles', '/applications',
        '/identity/conditionalAccess/policies', '/reports/authenticationMethods/userRegistrationDetails', '/deviceManagement/managedDevices',
        '/deviceManagement/deviceCompliancePolicies', '/policies/authorizationPolicy', '/policies/identitySecurityDefaultsEnforcementPolicy'];

    private array $secrets = [];

    public static function pwsh(): ?string
    {
        if ($p = config('tenantsguard.scripts.pwsh')) {
            return is_file($p) ? $p : null;
        }
        foreach (['C:\\Program Files\\PowerShell\\7\\pwsh.exe', '/usr/bin/pwsh', '/usr/local/bin/pwsh', '/opt/microsoft/powershell/7/pwsh'] as $p) {
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Only what PowerShell needs. Every inherited variable (APP_KEY, DB_PASSWORD, MS_CLIENT_SECRET, ...)
     * is explicitly unset, because Symfony Process otherwise passes $_ENV and $_SERVER to the child.
     */
    public static function environment(string $workDir): array
    {
        $env = [];
        foreach (array_merge(array_keys(getenv() ?: []), array_keys($_ENV), array_keys(array_filter($_SERVER, 'is_string'))) as $k) {
            if (is_string($k) && $k !== '') {
                $env[$k] = false;
            }
        }
        $keep = ['SystemRoot', 'SYSTEMROOT', 'windir', 'WINDIR', 'SystemDrive', 'ComSpec', 'PATHEXT', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432',
            'ProgramData', 'CommonProgramFiles', 'PROCESSOR_ARCHITECTURE', 'NUMBER_OF_PROCESSORS', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'HOME',
            'APPDATA', 'LOCALAPPDATA', 'USERNAME', 'USER', 'LANG', 'PSModulePath'];
        foreach ($keep as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                $env[$k] = $v;
            }
        }
        $pathDirs = DIRECTORY_SEPARATOR === '\\'
            ? array_filter([getenv('SystemRoot') ? getenv('SystemRoot').'\\System32' : null, getenv('SystemRoot') ? getenv('SystemRoot').'\\System32\\WindowsPowerShell\\v1.0' : null, dirname((string) self::pwsh())])
            : ['/usr/local/bin', '/usr/bin', '/bin', dirname((string) self::pwsh())];
        $env[DIRECTORY_SEPARATOR === '\\' ? 'Path' : 'PATH'] = implode(PATH_SEPARATOR, array_unique($pathDirs));
        $env['TEMP'] = $env['TMP'] = $env['TMPDIR'] = $workDir;
        $env['POWERSHELL_TELEMETRY_OPTOUT'] = '1';
        $env['POWERSHELL_UPDATECHECK'] = 'Off';

        return $env;
    }

    /** Execute and record the outcome on the target. Never throws. */
    public function execute(ScriptRunTarget $target, ScriptVersion $version, array $parameters, callable $cancelled): void
    {
        $tenant = $target->tenant;
        $started = microtime(true);
        $target->update(['status' => 'running', 'started_at' => now()]);
        $work = storage_path('app/script-runs/'.$target->script_run_id.'-'.$target->id.'-'.bin2hex(random_bytes(6)));

        try {
            if (! $version->intact()) {
                throw new RuntimeException('Integrity check failed: this script version was changed after approval. Nothing was run.');
            }
            $pwsh = self::pwsh() ?? throw new RuntimeException('PowerShell 7 (pwsh) was not found on the TenantsGuard server. Install it or set TG_PWSH.');
            File::ensureDirectoryExists($work, 0700);
            File::put($work.'/script.ps1', "\u{FEFF}".$version->body);
            File::copy(resource_path('powershell/runner.ps1'), $work.'/runner.ps1');

            $ctx = $this->context($tenant, $version, $parameters);
            $process = new Process([$pwsh, '-NoProfile', '-NonInteractive', '-NoLogo', '-ExecutionPolicy', 'Bypass', '-File', $work.'/runner.ps1', '-ScriptPath', $work.'/script.ps1'],
                $work, self::environment($work), json_encode($ctx, JSON_UNESCAPED_SLASHES), null);
            unset($ctx);

            $cap = config('tenantsguard.scripts.output_max_kb') * 1024;
            $out = '';
            $truncated = false;
            $status = null;
            $process->start();
            $lastCancelCheck = 0;
            while ($process->isRunning()) {
                usleep(150_000);
                $out .= $process->getIncrementalOutput().$process->getIncrementalErrorOutput();
                if (strlen($out) > $cap) {
                    $truncated = true;
                    $process->stop(2);
                    break;
                }
                if (microtime(true) - $started > $version->timeout) {
                    $status = 'timeout';
                    $process->stop(2);
                    break;
                }
                if (microtime(true) - $lastCancelCheck > 2) {
                    $lastCancelCheck = microtime(true);
                    if ($cancelled()) {
                        $status = 'cancelled';
                        $process->stop(2);
                        break;
                    }
                }
            }
            $out .= $process->getIncrementalOutput().$process->getIncrementalErrorOutput();
            $exit = $process->getExitCode();

            [$log, $results, $error] = self::parse($this->redact($out));
            if ($truncated) {
                $error ??= 'Output exceeded '.config('tenantsguard.scripts.output_max_kb').' KB, so the script was stopped. Return fewer or smaller objects.';
            }
            $status ??= ($truncated || $exit !== 0 || $error) ? 'failed' : 'succeeded';
            if ($status === 'timeout') {
                $error = 'Stopped after the '.$version->timeout.' second time limit.';
            } elseif ($status === 'cancelled') {
                $error = 'Cancelled while running.';
            }

            $target->update([
                'status' => $status, 'exit_code' => $exit, 'output' => mb_substr($log, 0, $cap), 'results' => $results,
                'error' => $error ? mb_substr($error, 0, 2000) : null, 'truncated' => $truncated,
            ]);
        } catch (Throwable $e) {
            $msg = $e instanceof GraphException ? $e->friendly() : $e->getMessage();
            $target->update(['status' => 'failed', 'error' => mb_substr($this->redact($msg), 0, 2000)]);
        } finally {
            $this->secrets = [];
            File::deleteDirectory($work);
            $target->update(['duration_ms' => (int) ((microtime(true) - $started) * 1000), 'finished_at' => now()]);
        }
    }

    private function context(Tenant $tenant, ScriptVersion $version, array $parameters): array
    {
        // The token carries every role the client granted, so the access profile is checked up front.
        if ($version->risk === 'change' && ! array_filter(TenantAccess::enabledCapabilities($tenant), fn ($c) => Access::tier($c) !== 'read')) {
            throw new GraphException("The access profile for {$tenant->name} is read-only, so change scripts cannot run there. Enable a write capability under the client's Access and permissions.", 403, 'AccessPolicy');
        }
        if ($version->use_exchange && ! TenantAccess::enabled($tenant, 'exchange')) {
            throw new GraphException("The access profile for {$tenant->name} does not allow Exchange Online. Enable it under the client's Access and permissions.", 403, 'AccessPolicy');
        }
        $ctx = [
            'demo' => $tenant->isDemo(), 'readOnly' => $version->risk === 'read', 'maxGraphCalls' => (int) config('tenantsguard.scripts.max_graph_calls'),
            'useGraphModule' => $version->use_graph_module && $version->risk === 'change', 'useExchange' => $version->use_exchange,
            'exchangeReadCommands' => self::EXO_READ_COMMANDS, 'parameters' => (object) $parameters,
            'tenant' => ['name' => $tenant->name, 'id' => $tenant->ms_tenant_id, 'domain' => $tenant->domain, 'initialDomain' => null],
            'graphToken' => null, 'exchangeToken' => null, 'demoData' => (object) [],
        ];
        if ($tenant->isDemo()) {
            $mock = new MockGraphClient($tenant);
            $data = [];
            foreach (self::DEMO_PATHS as $path) {
                try {
                    $res = $mock->get($path);
                    $data[$path] = array_key_exists('value', $res) ? $res['value'] : $res;
                } catch (Throwable) {
                }
            }
            $ctx['demoData'] = (object) $data;

            return $ctx;
        }

        // Fresh tokens so the full lifetime is available to the run. They are never written to disk or logs.
        $ctx['graphToken'] = GraphClient::token($tenant->ms_tenant_id, true);
        $this->secrets[] = $ctx['graphToken'];
        if ($version->use_exchange) {
            $org = (new GraphClient($tenant->ms_tenant_id))->get('/organization');
            $ctx['tenant']['initialDomain'] = collect($org['value'][0]['verifiedDomains'] ?? [])->firstWhere('isInitial', true)['name'] ?? $tenant->domain;
            try {
                $ctx['exchangeToken'] = GraphClient::tokenFor($tenant->ms_tenant_id, 'https://outlook.office365.com/.default', true);
                $this->secrets[] = $ctx['exchangeToken'];
            } catch (GraphException) {
                $ctx['exchangeToken'] = null;
            }
        }

        return $ctx;
    }

    /** Remove tokens, bearer headers, JWTs and the app secret from anything that is stored or shown. */
    private function redact(string $text): string
    {
        foreach (array_filter(array_merge($this->secrets, [MicrosoftApp::clientSecret()])) as $s) {
            if (strlen($s) >= 8) {
                $text = str_replace($s, '[REDACTED]', $text);
            }
        }

        return preg_replace(['/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]*/', '/(Bearer\s+)[A-Za-z0-9._~+\/=-]{16,}/i'], ['[REDACTED-TOKEN]', '$1[REDACTED]'], $text);
    }

    /** @return array{0: string, 1: ?array, 2: ?string} log, results, error */
    public static function parse(string $out): array
    {
        $results = null;
        $error = null;
        $log = preg_replace_callback('/<<<TG:(RESULTS|ERROR)>>>(.*?)<<<TG:END>>>\R?/s', function ($m) use (&$results, &$error) {
            $data = json_decode($m[2], true);
            if ($m[1] === 'RESULTS') {
                $results = is_array($data) ? (array_is_list($data) ? $data : [$data]) : ($data === null ? [] : [$data]);
            } else {
                $error = trim(($data['message'] ?? 'Script failed.').(! empty($data['line']) ? ' (line '.$data['line'].')' : ''));
            }

            return '';
        }, $out);

        return [trim((string) $log), $results, $error];
    }
}

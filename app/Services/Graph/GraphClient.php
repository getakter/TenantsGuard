<?php

namespace App\Services\Graph;

use App\Models\Tenant;
use App\Services\Access\TenantAccess;
use App\Support\MicrosoftApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Live Microsoft Graph client using the OAuth 2.0 client-credentials flow
 * against a customer tenant that has granted admin consent to our multi-tenant app.
 */
class GraphClient implements GraphClientInterface
{
    private const MAX_PAGES = 100;

    public function __construct(private readonly string $tenantId) {}

    public static function token(string $tenantId, bool $fresh = false): string
    {
        return self::tokenFor($tenantId, 'https://graph.microsoft.com/.default', $fresh);
    }

    /** App-only token for any resource the app was consented for (Graph, Exchange Online). */
    public static function tokenFor(string $tenantId, string $scope, bool $fresh = false): string
    {
        $cfg = config('tenantsguard.microsoft');
        $cfg['client_id'] = MicrosoftApp::clientId();
        $cfg['client_secret'] = MicrosoftApp::clientSecret();
        if (empty($cfg['client_id']) || empty($cfg['client_secret'])) {
            throw new GraphException('The Microsoft app is not set up yet. Open Settings and use automatic setup or enter your app registration.', 0, 'NotConfigured');
        }

        $key = $scope === 'https://graph.microsoft.com/.default' ? 'graph_token_'.$tenantId : 'app_token_'.md5($scope).'_'.$tenantId;
        if ($fresh) {
            Cache::forget($key);
        }
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        try {
            $res = Http::asForm()->timeout(20)->post($cfg['login_base']."/{$tenantId}/oauth2/v2.0/token", [
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'scope' => $scope,
                'grant_type' => 'client_credentials',
            ]);
        } catch (ConnectionException $e) {
            throw new GraphException('Could not reach login.microsoftonline.com: '.$e->getMessage());
        }

        if (! $res->successful()) {
            $err = $res->json('error_description') ?? $res->json('error') ?? $res->body();
            throw new GraphException('Token request failed: '.strtok((string) $err, "\r\n"), $res->status(), $res->json('error'));
        }

        $token = $res->json('access_token');
        Cache::put($key, $token, max(60, (int) $res->json('expires_in', 3600) - 120));

        return $token;
    }

    /** Application permissions granted in the tenant: the roles claim of an app-only token. */
    public static function grantedRoles(string $tenantId, bool $fresh = false, string $scope = 'https://graph.microsoft.com/.default'): array
    {
        $jwt = self::tokenFor($tenantId, $scope, $fresh);
        $payload = json_decode(base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);

        return array_values($payload['roles'] ?? []);
    }

    private ?Tenant $tenantModel = null;

    /** Every write passes the TenantsGuard access policy of the client first. */
    private function guard(string $method, string $path): void
    {
        $this->tenantModel ??= Tenant::where('ms_tenant_id', $this->tenantId)->first();
        if ($this->tenantModel) {
            TenantAccess::assertWrite($this->tenantModel, $method, $path);
        }
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function getAll(string $path): array
    {
        $items = [];
        $url = $path;
        for ($page = 0; $url && $page < self::MAX_PAGES; $page++) {
            $data = $this->send('GET', $url);
            array_push($items, ...($data['value'] ?? []));
            $url = $data['@odata.nextLink'] ?? null;
        }

        return $items;
    }

    public function post(string $path, array $body): array
    {
        $this->guard('POST', $path);

        return $this->send('POST', $path, $body);
    }

    public function patch(string $path, array $body): array
    {
        $this->guard('PATCH', $path);

        return $this->send('PATCH', $path, $body);
    }

    public function put(string $path, array $body): array
    {
        $this->guard('PUT', $path);

        return $this->send('PUT', $path, $body);
    }

    public function delete(string $path): array
    {
        $this->guard('DELETE', $path);

        return $this->send('DELETE', $path);
    }

    private function url(string $path): string
    {
        if (str_starts_with($path, 'https://')) {
            return $path;
        }
        $base = config('tenantsguard.microsoft.graph_base');

        return str_starts_with($path, '/beta/') ? $base.$path : $base.'/v1.0'.$path;
    }

    private function send(string $method, string $path, ?array $body = null): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $req = Http::withToken(self::token($this->tenantId))
                    ->acceptJson()
                    ->timeout(45)
                    ->withHeaders(['ConsistencyLevel' => 'eventual']);
                /** @var Response $res */
                $res = $body === null ? $req->send($method, $this->url($path)) : $req->send($method, $this->url($path), ['json' => $body]);
            } catch (ConnectionException $e) {
                throw new GraphException('Could not reach Microsoft Graph: '.$e->getMessage());
            }

            if ($res->status() === 429 || $res->status() === 503) {
                if ($attempt >= 4) {
                    throw new GraphException('Throttled by Microsoft Graph', $res->status(), 'TooManyRequests');
                }
                sleep(min(10, (int) ($res->header('Retry-After') ?: 2 * $attempt)));

                continue;
            }

            if ($res->status() === 401 && $attempt === 1) {
                self::token($this->tenantId, true);

                continue;
            }

            if (! $res->successful()) {
                throw new GraphException(
                    (string) ($res->json('error.message') ?? $res->reason()),
                    $res->status(),
                    $res->json('error.code')
                );
            }

            return $res->json() ?? [];
        }
    }
}

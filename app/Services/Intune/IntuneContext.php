<?php

namespace App\Services\Intune;

use App\Models\Tenant;
use App\Services\Checks\CheckContext;
use App\Services\Graph\GraphClientInterface;
use App\Services\Graph\GraphException;
use App\Services\Scanner;

/** Graph access for the Intune module with tolerant reads, permission tracking and cached licence detection. */
class IntuneContext
{
    public readonly GraphClientInterface $graph;

    /** Paths Microsoft Graph refused with 401/403 (missing permission or consent). */
    public array $denied = [];

    private CheckContext $check;

    private ?array $licenses = null;

    public function __construct(public readonly Tenant $tenant)
    {
        $this->check = Scanner::context($tenant);
        $this->graph = $this->check->graph;
    }

    public function tryGet(string $path): ?array
    {
        try {
            return $this->graph->get($path);
        } catch (GraphException $e) {
            $this->noteDenied($path, $e);

            return null;
        }
    }

    public function tryGetAll(string $path): array
    {
        try {
            return $this->graph->getAll($path);
        } catch (GraphException $e) {
            $this->noteDenied($path, $e);

            return [];
        }
    }

    private function noteDenied(string $path, GraphException $e): void
    {
        if (in_array($e->status, [401, 403], true)) {
            $this->denied[] = strtok($path, '?');
        }
    }

    /** Run a prerequisite check, reporting "could not check" instead of a false negative when access was denied. */
    public function verify(array $template): array
    {
        if (empty($template['verify'])) {
            return ['ok' => null, 'detail' => 'Confirm manually in the portal.'];
        }
        $before = count($this->denied);
        $result = ($template['verify'])($this);
        if (count($this->denied) > $before) {
            return ['ok' => null, 'detail' => 'Could not check: the TenantsGuard app is missing Intune read permissions in this tenant. Update app permissions in Settings, then re-grant consent.'];
        }

        return $result;
    }

    /** @return array{intune: bool, p1: bool, p2: bool, mde: bool, known: bool, skus: array} */
    public function licenses(): array
    {
        return $this->licenses ??= $this->check->licenses();
    }
}

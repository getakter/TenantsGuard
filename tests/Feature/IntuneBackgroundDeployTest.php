<?php

namespace Tests\Feature;

use App\Models\IntuneBundle;
use App\Models\IntuneDeployment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Intune\IntuneDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Intune deployments run in a background worker, not in the web request. */
class IntuneBackgroundDeployTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123', 'tenantsguard.intune.dispatch' => 'none']);
        $this->seed();
        $this->tenant = DemoTenant::create('Northwind Dental (Demo)');
        $this->actingAs(User::first());
    }

    public function test_submit_queues_and_the_worker_completes_it(): void
    {
        $bundle = IntuneBundle::where('name', 'like', 'SMB%')->firstOrFail();
        $this->post(route('intune.bundles.deploy.run', $bundle), ['tenant_ids' => [$this->tenant->id], 'mode' => 'deploy'])->assertRedirect();

        $d = IntuneDeployment::firstOrFail();
        $this->assertSame('queued', $d->status, 'the request only queues the work');
        $this->assertCount(0, $d->items);
        $this->get(route('intune.run', $d->batch))->assertOk()->assertSee('Deployment in progress')->assertSee('Waiting to start')->assertSee('0 of '.count($bundle->items));

        $this->assertSame(1, app(IntuneDeployer::class)->processQueued(60));
        $d->refresh();
        $this->assertContains($d->status, ['success', 'partial']);
        $this->assertNotNull($d->finished_at);
        $this->assertGreaterThan(5, $d->items()->count());
        $this->get(route('intune.run', $d->batch))->assertOk()->assertDontSee('Deployment in progress');

        // Idempotent re-run: nothing is created twice.
        $this->post(route('intune.bundles.deploy.run', $bundle), ['tenant_ids' => [$this->tenant->id], 'mode' => 'deploy']);
        app(IntuneDeployer::class)->processQueued(60);
        $again = IntuneDeployment::latest('id')->first();
        $this->assertSame(0, $again->items()->where('status', 'created')->count());
    }

    public function test_stale_running_deployment_is_closed_with_a_clear_message(): void
    {
        $d = IntuneDeployment::create(['batch' => 'b1', 'tenant_id' => $this->tenant->id, 'bundle_name' => 'X', 'status' => 'running',
            'started_at' => now()->subHour(), 'request' => ['items' => []]]);
        app(IntuneDeployer::class)->processQueued(5);
        $d->refresh();
        $this->assertSame('failed', $d->status);
        $this->assertStringContainsString('stopped before it finished', $d->items()->first()->message);
    }

    public function test_busy_client_stays_queued(): void
    {
        $d = app(IntuneDeployer::class)->queue($this->tenant, [['template' => 'WIN-CMP-01', 'assignment' => 'pilot']], true, false, null, 'X');
        $lock = cache()->lock('tenant-op-'.$this->tenant->id, 60);
        $lock->get();
        $this->assertSame(0, app(IntuneDeployer::class)->processQueued(5));
        $this->assertSame('queued', $d->fresh()->status);
        $lock->release();
        $this->assertSame(1, app(IntuneDeployer::class)->processQueued(5));
        $this->assertSame('preview', $d->fresh()->status);
    }
}

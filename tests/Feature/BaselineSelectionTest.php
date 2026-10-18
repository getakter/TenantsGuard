<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Baseline;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BaselineMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaselineSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->user = User::first();
        $this->actingAs($this->user);
    }

    private function demo(): Tenant
    {
        $this->post(route('tenants.demo'));

        return Tenant::where('mode', 'demo')->latest('id')->first();
    }

    private function state(Tenant $t, string $id): string
    {
        return BaselineMatrix::build(Tenant::whereKey($t->id)->get())[$t->id][$id]['state'];
    }

    public function test_only_selected_items_are_applied_per_tenant(): void
    {
        $a = $this->demo();
        $b = $this->demo();
        $baseline = Baseline::where('name', 'App consent lockdown')->first();

        $this->post(route('baselines.apply', $baseline), ['selection' => [
            $a->id => ['APP-01', 'APP-04'],
            $b->id => ['APP-02'],
        ]])->assertRedirect()->assertSessionHas('baseline_report');

        $this->assertSame('applied', $this->state($a, 'APP-01'));
        $this->assertSame('applied', $this->state($a, 'APP-04'));
        $this->assertSame('open', $this->state($a, 'APP-02'), 'Unselected item must stay untouched');
        $this->assertSame('open', $this->state($a, 'APP-03'));
        $this->assertSame('applied', $this->state($b, 'APP-02'));
        $this->assertSame('open', $this->state($b, 'APP-01'));
        $this->assertSame(3, ActivityLog::where('action', 'baseline:App consent lockdown')->where('outcome', 'success')->count());
    }

    public function test_grid_shows_applied_greyed_and_open_toggles(): void
    {
        $t = $this->demo();
        $this->post(route('fix.store', [$t, 'APP-01']));

        $html = $this->get(route('baselines.index'))->assertOk()->getContent();
        // Applied: disabled, checked, grey switch. Not applied: active toggle named for submission.
        $this->assertStringContainsString('switch is-applied', $html);
        $this->assertStringNotContainsString('name="selection['.$t->id.'][]" value="APP-01"', $html);
        $this->assertStringContainsString('name="selection['.$t->id.'][]" value="APP-02"', $html);
        $this->assertStringContainsString('Applied '.now()->format('M j, Y').' via quick fix', $html);
    }

    public function test_risk_accepted_item_stays_selectable_and_report_only_is_pending(): void
    {
        $t = $this->demo();
        $this->post(route('annotations.store', [$t, 'COL-03']), ['type' => 'accepted', 'note' => 'Client shares price lists publicly']);
        $this->post(route('fix.store', [$t, 'ID-02'])); // step 1: report-only policy

        $this->assertSame('accepted', $this->state($t, 'COL-03'));
        $this->assertSame('pending', $this->state($t, 'ID-02'));
        $this->assertSame('manual', $this->state($t, 'ID-01'), 'Tenant has P1: security defaults fix is not offered');
        $this->assertSame('na', $this->state($t, 'ID-06'));
    }

    public function test_items_outside_the_baseline_are_rejected_and_empty_selection_errors(): void
    {
        $t = $this->demo();
        $baseline = Baseline::where('name', 'App consent lockdown')->first();

        $this->post(route('baselines.apply', $baseline), ['selection' => [$t->id => ['COL-03']]])->assertSessionHasErrors();
        $this->assertSame('open', $this->state($t, 'COL-03'));
        $this->post(route('baselines.apply', $baseline), [])->assertSessionHasErrors();
    }
}

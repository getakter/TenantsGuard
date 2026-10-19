<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('rule', 32)->index();
            $t->string('severity', 12);                 // critical | high | medium | low
            $t->string('title', 255);
            $t->text('detail')->nullable();
            $t->json('evidence')->nullable();
            $t->string('dedupe_key', 191);              // rule-specific: audit id, user id, alert id …
            $t->string('status', 16)->default('new');   // new | acknowledged | resolved
            $t->timestamp('occurred_at')->nullable();   // when it happened in the tenant
            $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution', 32)->nullable();   // true_positive | benign | false_positive | cleared_at_source
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'rule', 'dedupe_key']);
            $t->index(['status', 'severity']);
        });

        Schema::table('tenants', function (Blueprint $t) {
            $t->json('monitoring')->nullable();          // break_glass: [upn], rules: [enabled rule keys] (null = all)
            $t->timestamp('monitored_at')->nullable();   // last successful monitoring pass
            $t->json('monitor_status')->nullable();      // rule => ok | unavailable: reason
        });

        $add = [
            'administrator' => ['alerts.view', 'alerts.manage', 'licensing.view'],
            'technician' => ['alerts.view', 'alerts.manage', 'licensing.view'],
            'read_only' => ['alerts.view', 'licensing.view'],
        ];
        foreach ($add as $key => $perms) {
            $row = DB::table('roles')->where('key', $key)->first();
            if ($row) {
                $current = json_decode($row->permissions, true) ?: [];
                DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode(array_values(array_unique(array_merge($current, $perms))))]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['monitoring', 'monitored_at', 'monitor_status']);
        });
    }
};

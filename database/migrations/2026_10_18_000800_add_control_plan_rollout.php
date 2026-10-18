<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance profiles become "control plans": frameworks + in-scope controls + phased rollout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_profiles', function (Blueprint $t) {
            $t->text('phases')->nullable()->after('frameworks');              // [{n, name, description}]
            $t->longText('control_settings')->nullable()->after('excluded_controls'); // {controlId: {phase, assignment}}
        });

        Schema::create('control_plan_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_id')->nullable()->constrained('compliance_profiles')->nullOnDelete();
            $t->string('plan_name', 150);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('dry_run')->default(false);
            $t->string('scope', 120);          // "Phase 2", "All phases", "3 selected controls"
            $t->text('summary')->nullable();   // counts by result
            $t->longText('results');           // [{control, result, message}]
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_plan_runs');
        Schema::table('compliance_profiles', function (Blueprint $t) {
            $t->dropColumn(['phases', 'control_settings']);
        });
    }
};

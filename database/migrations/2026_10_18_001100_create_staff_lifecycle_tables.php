<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Day-to-day operations: staff onboarding / offboarding in client tenants. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->longText('data');               // department, job title, licences, groups, password mode…
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });

        Schema::create('lifecycle_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12);             // onboard | offboard
            $t->string('target_upn', 190);
            $t->string('target_name', 190)->nullable();
            $t->string('target_id', 64)->nullable();
            $t->string('ticket', 80)->nullable();
            $t->string('status', 16);           // completed | in_progress | partial | failed
            $t->longText('steps');              // [{key, label, kind, status, message, at, by}]
            $t->longText('snapshot')->nullable(); // offboarding: memberships/licences/devices before removal
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('lifecycle_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('lifecycle_runs')->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('action', 32);           // enable_account | remove_licenses | delete_user
            $t->timestamp('execute_at');
            $t->string('status', 16)->default('pending'); // pending | done | failed | cancelled
            $t->text('message')->nullable();
            $t->timestamps();
            $t->index(['status', 'execute_at']);
        });

        foreach (['administrator' => ['staff.onboard', 'staff.offboard'], 'technician' => ['staff.onboard', 'staff.offboard']] as $key => $perms) {
            $row = DB::table('roles')->where('key', $key)->first();
            if ($row) {
                $current = json_decode($row->permissions, true) ?: [];
                DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode(array_values(array_unique(array_merge($current, $perms))))]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_tasks');
        Schema::dropIfExists('lifecycle_runs');
        Schema::dropIfExists('lifecycle_templates');
    }
};

<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('description', 1000)->nullable();
            $table->string('category', 60)->default('General');
            $table->boolean('archived')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every edit is a new immutable version. Security-relevant settings live on the version, so they are part of what is approved.
        Schema::create('script_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('body');
            $table->char('sha256', 64);
            $table->string('risk', 10);                    // read | change
            $table->boolean('use_graph_module')->default(false);
            $table->boolean('use_exchange')->default(false);
            $table->unsignedInteger('timeout');
            $table->json('parameters');
            $table->json('lint');
            $table->string('change_note', 500)->nullable();
            $table->string('status', 12)->default('draft'); // draft | pending | approved | rejected | superseded
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->char('signature', 64)->nullable();     // HMAC over the approved content and settings
            $table->timestamps();
            $table->unique(['script_id', 'version']);
        });

        Schema::create('script_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('queued'); // queued | running | completed | partial | failed | cancelling | cancelled
            $table->json('parameters');
            $table->string('reason', 500);
            $table->string('ticket', 80)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->boolean('mfa_verified')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('script_run_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('queued'); // queued | running | succeeded | failed | timeout | cancelled
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->longText('results')->nullable();
            $table->string('error', 2000)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('truncated')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'script_run_id']);
        });

        // Least privilege for the built-in roles: Administrators run scripts, Technicians run read-only scripts.
        // Authoring and approval stay with Global Administrators unless a role is edited.
        foreach (['administrator' => ['scripts.view', 'scripts.run', 'scripts.run_change'], 'technician' => ['scripts.view', 'scripts.run']] as $key => $add) {
            if ($role = Role::where('key', $key)->first()) {
                $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], $add)))]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('script_run_targets');
        Schema::dropIfExists('script_runs');
        Schema::dropIfExists('script_versions');
        Schema::dropIfExists('scripts');
    }
};

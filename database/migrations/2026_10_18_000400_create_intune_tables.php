<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intune_bundles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->text('context')->nullable();   // plan, ownership, platforms, industry
            $t->longText('items');             // [{template, params, assignment, group}]
            $t->boolean('is_system')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('intune_deployments', function (Blueprint $t) {
            $t->id();
            $t->uuid('batch')->index();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bundle_id')->nullable()->constrained('intune_bundles')->nullOnDelete();
            $t->string('bundle_name', 150);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('dry_run')->default(false);
            $t->boolean('update_existing')->default(false);
            $t->string('status', 16);          // success | partial | failed | preview
            $t->text('summary')->nullable();   // counts by status
            $t->timestamps();
        });

        Schema::create('intune_deployment_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deployment_id')->constrained('intune_deployments')->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('template', 32)->index();
            $t->string('display_name', 255);
            $t->string('status', 20);          // created | updated | exists | failed | manual | ready | would_create | would_update | skipped
            $t->string('object_id', 64)->nullable();
            $t->string('assignment', 120)->nullable();
            $t->text('message')->nullable();
            $t->timestamps();
        });

        // New permissions for existing built-in roles.
        $add = [
            'administrator' => ['intune.view', 'intune.manage', 'intune.deploy'],
            'technician' => ['intune.view', 'intune.deploy'],
            'read_only' => ['intune.view'],
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
        Schema::dropIfExists('intune_deployment_items');
        Schema::dropIfExists('intune_deployments');
        Schema::dropIfExists('intune_bundles');
    }
};

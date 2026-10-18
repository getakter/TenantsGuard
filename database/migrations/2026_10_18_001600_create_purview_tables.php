<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purview_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('stage', 20)->default('requirements');
            $table->json('requirements');
            $table->json('capabilities')->nullable();
            $table->json('design')->nullable();
            $table->char('design_hash', 64)->nullable();
            $table->timestamp('design_approved_at')->nullable();
            $table->foreignId('design_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('signoffs')->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('purview_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purview_project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('phase', 20);
            $table->unsignedSmallInteger('position');
            $table->string('title', 255);
            $table->string('method', 10);
            $table->text('why');
            $table->json('steps');
            $table->string('link', 255)->nullable();
            $table->string('verify', 40)->nullable();
            $table->string('who', 80);
            $table->boolean('required')->default(true);
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('todo'); // todo | in_progress | done | skipped | blocked
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('verify_status', 10)->nullable(); // ok | partial | missing
            $table->string('verify_detail', 1000)->nullable();
            $table->boolean('stale')->default(false);   // no longer in the current design
            $table->timestamps();
            $table->unique(['purview_project_id', 'key']);
        });

        foreach (['administrator' => ['purview.view', 'purview.manage'], 'technician' => ['purview.view'], 'read_only' => ['purview.view']] as $key => $add) {
            if ($role = Role::where('key', $key)->first()) {
                $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], $add)))]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purview_tasks');
        Schema::dropIfExists('purview_projects');
    }
};

<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('stage', 20)->default('requirements');
            $table->json('requirements');
            $table->json('assessment')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->json('usage')->nullable();
            $table->timestamp('usage_at')->nullable();
            $table->json('signoffs')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('copilot_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('copilot_project_id')->constrained()->cascadeOnDelete();
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
            $table->string('status', 12)->default('todo');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('verify_status', 10)->nullable();
            $table->string('verify_detail', 1000)->nullable();
            $table->boolean('stale')->default(false);
            $table->timestamps();
            $table->unique(['copilot_project_id', 'key']);
        });

        foreach (['administrator' => ['copilot.view', 'copilot.manage'], 'technician' => ['copilot.view'], 'read_only' => ['copilot.view']] as $key => $add) {
            if ($role = Role::where('key', $key)->first()) {
                $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], $add)))]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_tasks');
        Schema::dropIfExists('copilot_projects');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('domain')->nullable();
            $t->string('ms_tenant_id', 64)->nullable()->index();
            $t->string('mode', 8)->default('live');
            $t->string('consent_status', 16)->default('pending');
            $t->text('consent_error')->nullable();
            $t->longText('demo_state')->nullable();
            $t->unsignedTinyInteger('last_score')->nullable();
            $t->timestamp('last_scanned_at')->nullable();
            $t->timestamps();
        });

        Schema::create('scans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('trigger', 32)->default('manual');
            $t->unsignedTinyInteger('score')->default(0);
            $t->longText('counts')->nullable();
            $t->longText('drifted')->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('scan_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $t->string('check_id', 32);
            $t->string('status', 16);
            $t->text('summary')->nullable();
            $t->longText('evidence')->nullable();
            $t->longText('affected')->nullable();
            $t->longText('fix')->nullable();
            $t->index(['scan_id', 'check_id']);
        });

        Schema::create('annotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('check_id', 32);
            $t->string('type', 16);
            $t->text('note');
            $t->date('review_by')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'check_id']);
        });

        Schema::create('baselines', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->longText('check_ids');
            $t->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('action', 48);
            $t->string('check_id', 32)->nullable();
            $t->string('outcome', 16)->default('info');
            $t->text('message')->nullable();
            $t->longText('detail')->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'baselines', 'annotations', 'scan_results', 'scans', 'tenants', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

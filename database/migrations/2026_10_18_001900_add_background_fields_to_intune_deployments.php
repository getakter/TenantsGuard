<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intune_deployments', function (Blueprint $table) {
            // Deployments run in a background worker: queued -> running -> success | partial | failed | preview
            $table->json('request')->nullable();          // the items to deploy
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('intune_deployments', function (Blueprint $table) {
            $table->dropColumn(['request', 'started_at', 'finished_at']);
        });
    }
};

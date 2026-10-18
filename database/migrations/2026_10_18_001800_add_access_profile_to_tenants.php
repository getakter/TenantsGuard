<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // null = every capability in the app plan (behaviour before this feature)
            $table->json('access_profile')->nullable();
            $table->json('access_expiry')->nullable();      // capability => ISO timestamp (time-limited privileged access)
            $table->json('granted_roles')->nullable();      // roles claim of the last app-only token
            $table->timestamp('granted_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['access_profile', 'access_expiry', 'granted_roles', 'granted_checked_at']);
        });
    }
};

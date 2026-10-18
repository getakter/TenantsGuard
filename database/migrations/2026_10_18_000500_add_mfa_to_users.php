<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('mfa_secret')->nullable()->after('password');          // encrypted TOTP secret
            $t->timestamp('mfa_enabled_at')->nullable()->after('mfa_secret');
            $t->text('mfa_recovery_codes')->nullable()->after('mfa_enabled_at'); // JSON list of bcrypt hashes
            $t->unsignedBigInteger('mfa_last_step')->nullable()->after('mfa_recovery_codes'); // replay protection
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['mfa_secret', 'mfa_enabled_at', 'mfa_recovery_codes', 'mfa_last_step']);
        });
    }
};

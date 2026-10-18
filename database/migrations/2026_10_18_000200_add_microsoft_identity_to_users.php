<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('microsoft_oid', 64)->nullable()->unique()->after('email');
            $t->string('microsoft_tid', 64)->nullable()->after('microsoft_oid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['microsoft_oid']);
            $t->dropColumn(['microsoft_oid', 'microsoft_tid']);
        });
    }
};

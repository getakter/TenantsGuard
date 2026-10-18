<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Identity management: default user templates, scheduled offboarding payloads. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lifecycle_templates', function (Blueprint $t) {
            $t->boolean('is_default')->default(false)->after('name');
        });
        Schema::table('lifecycle_tasks', function (Blueprint $t) {
            $t->longText('payload')->nullable()->after('action'); // scheduled offboarding options
        });
    }

    public function down(): void
    {
        Schema::table('lifecycle_templates', fn (Blueprint $t) => $t->dropColumn('is_default'));
        Schema::table('lifecycle_tasks', fn (Blueprint $t) => $t->dropColumn('payload'));
    }
};

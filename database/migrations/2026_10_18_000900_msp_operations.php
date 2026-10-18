<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MSP operations: client metadata and plan-level Intune settings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->text('tags')->nullable()->after('domain');            // JSON list, e.g. ["Healthcare","Tier 1"]
            $t->string('contact_name', 120)->nullable()->after('tags');
            $t->string('contact_email', 190)->nullable()->after('contact_name');
            $t->text('notes')->nullable()->after('contact_email');
        });

        Schema::table('compliance_profiles', function (Blueprint $t) {
            $t->foreignId('intune_bundle_id')->nullable()->after('control_settings')->constrained('intune_bundles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compliance_profiles', fn (Blueprint $t) => $t->dropConstrainedForeignId('intune_bundle_id'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['tags', 'contact_name', 'contact_email', 'notes']));
    }
};

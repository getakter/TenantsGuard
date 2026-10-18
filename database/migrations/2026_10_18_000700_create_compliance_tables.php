<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->unique();
            $t->text('description')->nullable();
            $t->text('frameworks');            // [{key, target_level}]
            $t->longText('excluded_controls')->nullable(); // {controlId: reason}
            $t->boolean('exclude_from_score')->default(true);
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });

        Schema::table('tenants', function (Blueprint $t) {
            $t->foreignId('compliance_profile_id')->nullable()->after('mode')->constrained('compliance_profiles')->nullOnDelete();
        });

        Schema::create('framework_attestations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('framework', 32);
            $t->string('requirement', 64);
            $t->string('status', 20);          // implemented | partial | not_implemented | not_applicable
            $t->text('note');
            $t->string('evidence_url', 500)->nullable();
            $t->date('review_by')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['tenant_id', 'framework', 'requirement']);
        });

        $add = [
            'administrator' => ['compliance.manage', 'compliance.attest'],
            'technician' => ['compliance.attest'],
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
        Schema::dropIfExists('framework_attestations');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropConstrainedForeignId('compliance_profile_id'));
        Schema::dropIfExists('compliance_profiles');
    }
};

<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('key', 64)->unique();
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->text('permissions');
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->after('password')->constrained('roles')->nullOnDelete();
            $t->boolean('all_tenants')->default(true)->after('role_id');
            $t->boolean('is_active')->default(true)->after('all_tenants');
            $t->timestamp('last_login_at')->nullable()->after('is_active');
        });

        Schema::create('tenant_user', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['tenant_id', 'user_id']);
        });

        $now = now();
        foreach (Permissions::SYSTEM_ROLES as $key => $role) {
            DB::table('roles')->insert([
                'key' => $key,
                'name' => $role['name'],
                'description' => $role['description'],
                'permissions' => json_encode($role['permissions']),
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Everyone who could sign in before keeps full access.
        $global = DB::table('roles')->where('key', 'global_admin')->value('id');
        DB::table('users')->update(['role_id' => $global, 'all_tenants' => true, 'is_active' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn(['all_tenants', 'is_active', 'last_login_at']);
        });
        Schema::dropIfExists('roles');
    }
};

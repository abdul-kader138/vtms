<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * - A `customer` role for portal accounts (no permissions: it never opens
 *   the admin panel). Existing customer accounts get it: users linked to a
 *   customer record, and accounts with no role or permission at all (people
 *   who registered on the customer sign-up and wait to be linked).
 * - users.device_lock_exempt: switch the one-device lock off for a single
 *   customer while it stays on for everyone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('device_lock_exempt')->default(false)->after('customer_id');
        });

        $tables = config('permission.table_names');
        $now = now();

        $roleId = DB::table($tables['roles'])->where(['name' => 'customer', 'guard_name' => 'web'])->value('id')
            ?? DB::table($tables['roles'])->insertGetId(['name' => 'customer', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $modelType = (new User)->getMorphClass();
        $withRoles = DB::table($tables['model_has_roles'])->where('model_type', $modelType)->pluck('model_id');
        $withPermissions = DB::table($tables['model_has_permissions'])->where('model_type', $modelType)->pluck('model_id');

        DB::table('users')
            ->where(fn ($q) => $q->whereNotNull('customer_id')
                ->orWhere(fn ($q) => $q->whereNotIn('id', $withRoles)->whereNotIn('id', $withPermissions)))
            ->whereNotIn('id', DB::table($tables['model_has_roles'])->where('model_type', $modelType)->where('role_id', $roleId)->pluck('model_id'))
            ->pluck('id')
            ->each(fn ($id) => DB::table($tables['model_has_roles'])->insert(['role_id' => $roleId, 'model_type' => $modelType, 'model_id' => $id]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('device_lock_exempt');
        });

        $tables = config('permission.table_names');
        $roleId = DB::table($tables['roles'])->where(['name' => 'customer', 'guard_name' => 'web'])->value('id');
        if ($roleId) {
            DB::table($tables['model_has_roles'])->where('role_id', $roleId)->delete();
            DB::table($tables['roles'])->where('id', $roleId)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

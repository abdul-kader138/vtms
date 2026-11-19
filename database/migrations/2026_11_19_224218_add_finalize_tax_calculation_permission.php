<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * finalize_tax_calculation was only ever created by ShieldSeeder, which
 * deploys don't run — so an existing install had no such permission:
 * nobody (super_admin included) could finalize a manually-adjusted tax
 * calculation, and queueing one as a TaxAdjustmentRequest crashed looking
 * up approvers. Creates it and grants it to super_admin, same as
 * ShieldSeeder does on a fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $now = now();

        $permissionId = DB::table($tables['permissions'])->where(['name' => 'finalize_tax_calculation', 'guard_name' => 'web'])->value('id')
            ?? DB::table($tables['permissions'])->insertGetId(['name' => 'finalize_tax_calculation', 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);

        $superAdminId = DB::table($tables['roles'])->where(['name' => 'super_admin', 'guard_name' => 'web'])->value('id');

        if ($superAdminId !== null) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $superAdminId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: ShieldSeeder creates it too, so a fresh install
        // has it regardless — dropping it here would break the dashboard.
    }
};

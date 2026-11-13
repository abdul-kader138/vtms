<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ShieldSeeder extends Seeder
{
    public function run(): void
    {
        // Permission rows aren't created by a migration — they only exist once
        // `shield:generate` has run against whatever database is active. Doing
        // that here (rather than assuming it was already run by hand) is what
        // makes this seeder work on a fresh install AND in the test suite's
        // own database, not just on whichever machine happened to run the CLI
        // command first.
        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--no-interaction' => true,
            // Without this, re-running the seeder (e.g. on every deploy)
            // overwrites hand-extended policies with the bare generated version.
            '--ignore-existing-policies' => true,
        ]);

        // Admin panel roles.
        // super_admin: full access to everything.
        // operator / panel_user: scaffolded for future feature-specific
        // permissions — no permissions synced yet beyond what super_admin holds.
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator',    'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'panel_user',  'guard_name' => 'web']);

        // filament-shield's config ships with super_admin.define_via_gate = false,
        // meaning there is NO automatic Gate::before bypass for this role — it
        // only gets full access because every permission is explicitly synced to
        // it here (exactly what `php artisan shield:super-admin` would otherwise
        // do interactively). Must run after shield:generate has created the
        // permission rows, or this silently syncs zero permissions.
        $superAdmin->syncPermissions(Permission::all());

        // Default super admin user (credentials via .env: ADMIN_EMAIL, ADMIN_PASSWORD)
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@admin.com')],
            [
                'first_name' => env('ADMIN_FIRST_NAME', 'Super'),
                'last_name' => env('ADMIN_LAST_NAME', 'Admin'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole($superAdmin);

        $this->command->info('Admin user ready.');
        $this->command->table(
            ['Field', 'Value'],
            [
                ['Email',    $admin->email],
                ['Password', env('ADMIN_PASSWORD', 'password')],
                ['Role',     'super_admin'],
                ['URL',      url('/login')],
            ]
        );
    }
}

<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Rules\SudanPhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Role 'admin' has all permissions (see EnsureDashboardPermission).
     * Other roles use the permissions array (see config/dashboard.php).
     */
    public function run(): void
    {
        $allPermissions = config('dashboard.permissions', []);

        AdminUser::firstOrCreate(
            [
                'phone' => SudanPhoneNumber::normalize('0912345678'),
            ],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'permissions' => null, // admin role bypasses permission check
            ]
        );

        $this->command->info('Admin user created successfully!');
        $this->command->info('Phone: 0912345678');
        $this->command->info('Password: password');
        $this->command->warn('Please change the default password after first login!');
        $this->command->info('To create a manager with limited permissions, set role to "manager" and permissions to e.g. ' . json_encode($allPermissions));
    }
}


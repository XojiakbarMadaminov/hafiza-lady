<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        // Super admin user
        $superAdmin = User::updateOrCreate(
            ['email' => 'super@gmail.com'],
            [
                'name'     => 'Super Admin User',
                'password' => bcrypt('hafiza_ledy2026.'),
            ]
        );

        $adminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->roles()->syncWithoutDetaching([$adminRole->id]);
    }
}

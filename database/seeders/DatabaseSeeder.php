<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            // Must stay last: it syncs every permission created above onto the
            // Super Admin role.
            SuperAdminPermissionsSeeder::class,
        ]);
    }
}

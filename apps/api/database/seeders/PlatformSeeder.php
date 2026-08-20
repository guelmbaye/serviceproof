<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Role;
use Illuminate\Database\Seeder;

class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        User::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'root@serviceproof.ai'],
            [
                'organization_id' => null,
                'name' => 'Platform Super Admin',
                'password' => env('SP_SUPER_ADMIN_PASSWORD', 'password'),
                'role' => Role::SUPER_ADMIN->value,
                'status' => 'ACTIVE',
            ]
        );
    }
}

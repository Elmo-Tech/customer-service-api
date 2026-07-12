<?php

namespace Database\Seeders\User;

use App\Enums\User\AccountType;
use App\Enums\User\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = config('initial_admin');
        if (in_array(null, $admin, true) || in_array('', $admin, true)) {
            $this->command?->warn('Initial admin was not seeded. Configure SEED_ADMIN_* values first.');

            return;
        }

        $user = User::query()->firstOrCreate([
            'username' => $admin['username'],
        ], [
            'name' => $admin['name'],
            'email' => $admin['email'],
            'status' => UserStatus::ACTIVE->value,
            'password' => $admin['password'],
            'account_type' => AccountType::INTERNAL->value,
        ]);

        if ($user->account_type === null) {
            $user->update(['account_type' => AccountType::INTERNAL->value]);
        }

        $user->syncRoles([Role::findByName('مدير', 'api')]);

    }
}

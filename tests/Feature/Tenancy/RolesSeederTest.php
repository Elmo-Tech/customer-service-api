<?php

namespace Tests\Feature\Tenancy;

use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;
use App\Models\User;
use Database\Seeders\Roles\RolesAndPermissionsSeeder;
use Database\Seeders\User\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_and_permissions_can_be_seeded_repeatedly(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $expectedRoles = ['مدير', ...array_column(TenantRole::cases(), 'value')];

        $this->assertEqualsCanonicalizing(
            $expectedRoles,
            Role::query()->where('guard_name', 'api')->pluck('name')->all(),
        );
        $this->assertTrue(
            Role::findByName('مدير', 'api')->hasAllPermissions(Permission::query()->get()),
        );
        $this->assertTrue(Role::findByName(TenantRole::EMPLOYEE->value, 'api')->hasAllPermissions([
            'all_tickets',
            'create_ticket',
        ]));
        $this->assertFalse(Role::findByName(TenantRole::EMPLOYEE->value, 'api')->hasPermissionTo('all_users'));

        Role::findByName(TenantRole::EMPLOYEE->value, 'api')->givePermissionTo('all_users');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertTrue(Role::findByName(TenantRole::EMPLOYEE->value, 'api')->hasPermissionTo('all_users'));
    }

    public function test_initial_admin_uses_explicit_configuration_and_is_idempotent(): void
    {
        config()->set('initial_admin', [
            'name' => 'System Admin',
            'username' => 'system-admin',
            'email' => 'system-admin@example.com',
            'password' => 'InitialPassword1!',
        ]);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        $admin = User::query()->firstOrFail();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(AccountType::INTERNAL, $admin->account_type);
        $this->assertTrue($admin->hasRole('مدير'));
        $this->assertTrue(Hash::check('InitialPassword1!', $admin->password));
    }
}

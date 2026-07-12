<?php

namespace Database\Seeders\Roles;

use App\Enums\User\TenantRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    private const TENANT_PERMISSIONS = [
        TenantRole::COMPANY_OWNER->value => [
            'all_companies', 'edit_company', 'update_company', 'export_companies',
            'create_branch', 'edit_branch', 'update_branch', 'delete_branch',
            'all_users', 'create_user', 'edit_user', 'update_user', 'delete_user', 'change_user_status', 'export_users',
            'all_customers', 'create_customer', 'edit_customer', 'update_customer', 'delete_customer', 'export_customers',
            'all_tickets', 'create_ticket', 'export_tickets', 'view_ticket_dashboard',
        ],
        TenantRole::COMPANY_MANAGER->value => [
            'all_companies', 'all_users', 'create_user', 'edit_user', 'update_user', 'change_user_status', 'export_users',
            'create_branch', 'edit_branch', 'update_branch',
            'all_customers', 'create_customer', 'edit_customer', 'update_customer', 'export_customers',
            'all_tickets', 'create_ticket', 'export_tickets', 'view_ticket_dashboard',
        ],
        TenantRole::BRANCH_MANAGER->value => [
            'all_users', 'create_user', 'edit_user', 'update_user', 'change_user_status', 'export_users',
            'all_customers', 'create_customer', 'edit_customer', 'update_customer', 'export_customers',
            'all_tickets', 'create_ticket', 'export_tickets', 'view_ticket_dashboard',
        ],
        TenantRole::EMPLOYEE->value => ['all_tickets', 'create_ticket'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $permissions = [
            'all_users',
            'create_user',
            'edit_user',
            'update_user',
            'delete_user',
            'change_user_status',
            'export_users',

            'all_roles',
            'create_role',
            'edit_role',
            'update_role',
            'delete_role',

            'all_companies',
            'create_company',
            'edit_company',
            'update_company',
            'delete_company',
            'onboard_company',
            'export_companies',

            'create_branch',
            'edit_branch',
            'update_branch',
            'delete_branch',

            'all_customers',
            'create_customer',
            'edit_customer',
            'update_customer',
            'delete_customer',
            'export_customers',

            'all_tickets',
            'create_ticket',
            'edit_ticket',
            'update_ticket',
            'delete_ticket',
            'export_tickets',
            'view_ticket_dashboard',
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['name' => $permission], [
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        $superAdmin = Role::updateOrCreate(
            ['name' => 'مدير', 'guard_name' => 'api'],
            ['name' => 'مدير', 'guard_name' => 'api'],
        );
        $superAdmin->syncPermissions(Permission::all());

        foreach (TenantRole::cases() as $tenantRole) {
            $role = Role::updateOrCreate(
                ['name' => $tenantRole->value, 'guard_name' => 'api'],
                ['name' => $tenantRole->value, 'guard_name' => 'api'],
            );
            $role->givePermissionTo(self::TENANT_PERMISSIONS[$tenantRole->value]);
        }

    }
}

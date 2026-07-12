<?php

namespace Database\Seeders\Roles;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        // premissions
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

        // roles
        $superAdmin = Role::create(['name' => 'مدير']);
        $superAdmin->givePermissionTo(Permission::all());

    }
}

<?php

namespace Database\Seeders\Roles;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CompanyContractPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = collect([
            'all_company_contracts',
            'create_company_contract',
            'update_company_contract',
            'delete_company_contract',
        ])->map(fn ($name) => Permission::findOrCreate($name, 'api'));

        // Match the existing manager role without changing any other role's grants.
        Role::where('name', 'مدير')->where('guard_name', 'api')->get()
            ->each(fn ($role) => $role->givePermissionTo($permissions));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

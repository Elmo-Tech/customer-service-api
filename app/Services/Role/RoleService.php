<?php

namespace App\Services\Role;

use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function __construct(private readonly RoleTemplateCatalog $templates) {}

    public function allRoles()
    {
        return Role::query()->with('permissions')->get();
    }

    public function createRole(array $roleData): Role
    {

        $role = Role::create([
            'name' => $roleData['name'],
            'guard_name' => 'api',
        ]);

        $role->syncPermissions($roleData['permissions']);

        return $role;
    }

    public function editRole(int $roleId): Role
    {
        return Role::with('permissions')->findOrFail($roleId);
    }

    public function updateRole(array $roleData): Role
    {

        $role = Role::findOrFail($roleData['roleId']);
        $this->assertEditable($role);

        $role->update([
            'name' => $roleData['name'],
        ]);

        $role->syncPermissions($roleData['permissions']);

        return $role;

    }

    public function deleteRole(int $roleId): void
    {
        $role = Role::findOrFail($roleId);
        $this->assertEditable($role);
        $role->delete();
    }

    public function matrix(): array
    {
        $permissions = Permission::query()->where('guard_name', 'api')->orderBy('name')->pluck('name')->all();

        return [
            'templates' => $this->templates->templates($permissions),
        ];
    }

    private function assertEditable(Role $role): void
    {
        if ($this->templates->isSystemRole($role->name)) {
            throw ValidationException::withMessages([
                'roleId' => 'System role templates cannot be changed or deleted.',
            ]);
        }
    }
}

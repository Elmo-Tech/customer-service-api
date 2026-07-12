<?php

namespace App\Services\Role;

use App\Enums\User\AccountType;
use App\Enums\User\TenantRole;

class RoleTemplateCatalog
{
    private const INTERNAL_ADMIN = 'مدير';

    private const TENANT_PERMISSIONS = [
        'company_owner' => [
            'all_companies', 'edit_company', 'update_company', 'export_companies',
            'create_branch', 'edit_branch', 'update_branch', 'delete_branch',
            'all_users', 'create_user', 'edit_user', 'update_user', 'delete_user', 'change_user_status', 'export_users',
            'all_customers', 'create_customer', 'edit_customer', 'update_customer', 'delete_customer', 'export_customers',
            'all_tickets', 'create_ticket', 'export_tickets', 'view_ticket_dashboard',
        ],
        'company_manager' => [
            'all_companies', 'all_users', 'create_user', 'edit_user', 'update_user', 'change_user_status', 'export_users',
            'create_branch', 'edit_branch', 'update_branch',
            'all_customers', 'create_customer', 'edit_customer', 'update_customer', 'export_customers',
            'all_tickets', 'create_ticket', 'export_tickets', 'view_ticket_dashboard',
        ],
        'branch_manager' => [
            'all_users', 'create_user', 'edit_user', 'update_user', 'change_user_status', 'export_users',
            'all_customers', 'create_customer', 'edit_customer', 'update_customer', 'export_customers',
            'all_tickets', 'create_ticket', 'export_tickets', 'view_ticket_dashboard',
        ],
        'employee' => ['all_tickets', 'create_ticket'],
    ];

    public function templates(array $allPermissions): array
    {
        $templates = [[
            'name' => self::INTERNAL_ADMIN,
            'accountType' => AccountType::INTERNAL->value,
            'permissions' => $allPermissions,
        ]];

        foreach (TenantRole::cases() as $role) {
            $templates[] = [
                'name' => $role->value,
                'accountType' => AccountType::TENANT->value,
                'permissions' => self::TENANT_PERMISSIONS[$role->value],
            ];
        }

        return $templates;
    }

    public function isSystemRole(string $roleName): bool
    {
        return $roleName === self::INTERNAL_ADMIN || array_key_exists($roleName, self::TENANT_PERMISSIONS);
    }

    public function permissions(string $roleName): array
    {
        return self::TENANT_PERMISSIONS[$roleName] ?? [];
    }
}

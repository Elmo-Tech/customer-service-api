<?php

namespace App\Enums\User;

enum TenantRole: string
{
    case COMPANY_OWNER = 'company_owner';
    case COMPANY_MANAGER = 'company_manager';
    case BRANCH_MANAGER = 'branch_manager';
    case EMPLOYEE = 'employee';
}

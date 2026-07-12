<?php

namespace App\Enums\User;

enum AccountType: string
{
    case INTERNAL = 'internal';
    case TENANT = 'tenant';
}

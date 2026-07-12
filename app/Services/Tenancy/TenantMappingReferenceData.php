<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Collection;

class TenantMappingReferenceData
{
    public function __construct(
        public readonly Collection $users,
        public readonly Collection $companies,
        public readonly Collection $branches,
        public readonly array $roleNames,
    ) {}
}

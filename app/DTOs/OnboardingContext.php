<?php

namespace App\DTOs;

use App\Models\Company\Company;
use App\Models\User;

class OnboardingContext
{
    public function __construct(
        public readonly User $actor,
        public readonly Company $company,
        public readonly array $branches,
    ) {}
}

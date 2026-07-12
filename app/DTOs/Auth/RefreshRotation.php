<?php

namespace App\DTOs\Auth;

use App\Models\User;

readonly class RefreshRotation
{
    public function __construct(public User $user, public string $secret) {}
}

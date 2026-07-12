<?php

namespace App\DTOs;

class InvitationSecret
{
    public function __construct(
        public readonly int $invitationId,
        public readonly string $token,
        public readonly string $email,
    ) {}
}

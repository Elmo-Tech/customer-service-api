<?php

namespace App\Services\User;

use App\Models\User;
use App\Services\Auth\AccountInvitationDelivery;
use App\Services\Auth\AccountInvitationService;
use App\Services\Tenancy\TenantAuditLogger;
use Illuminate\Support\Facades\DB;

class TeamMemberCreationService
{
    public function __construct(
        private readonly UserService $users,
        private readonly AccountInvitationService $invitations,
        private readonly AccountInvitationDelivery $delivery,
        private readonly TenantAuditLogger $audit,
    ) {}

    public function create(User $actor, array $fields): bool
    {
        $invitation = DB::transaction(function () use ($actor, $fields) {
            $user = $this->users->createUser($actor, $fields);
            if (! ($fields['invite'] ?? false)) {
                return null;
            }

            $secret = $this->invitations->issue($user, $actor);
            $this->audit->record($actor, 'account.invited', $user, ['invitationId' => $secret->invitationId]);

            return $secret;
        });

        if ($invitation) {
            $this->delivery->queue($invitation);
        }

        return $invitation !== null;
    }
}

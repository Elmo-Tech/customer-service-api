<?php

namespace App\Http\Controllers\Api\Public\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupInvitedAccountRequest;
use App\Models\Auth\AccountInvitation;
use App\Models\User;
use App\Services\Auth\AccountInvitationDelivery;
use App\Services\Auth\AccountInvitationService;
use App\Services\Tenancy\TenantAuditLogger;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountInvitationController extends Controller
{
    public function __construct(
        private readonly AccountInvitationService $invitations,
        private readonly AccountInvitationDelivery $delivery,
        private readonly TenantAuditLogger $audit,
    ) {
        $this->middleware('auth:api')->except('setup');
        $this->middleware('permission:create_user|onboard_company')->only('resend');
        $this->middleware('permission:update_user|onboard_company')->only('revoke');
    }

    public function setup(SetupInvitedAccountRequest $request): JsonResponse
    {
        $this->invitations->consume((string) $request->string('token'), (string) $request->string('password'));

        return response()->json(['message' => 'Account password created.']);
    }

    public function resend(Request $request, int $invitationId): JsonResponse
    {
        $invitation = $this->managedInvitation($request->user(), $invitationId);
        $secret = $this->invitations->issue($invitation->user, $request->user());
        $this->audit->record($request->user(), 'account.invitation_resent', $invitation->user, [
            'invitationId' => $secret->invitationId,
        ]);
        $this->delivery->queue($secret);

        return response()->json(['message' => 'Invitation queued.']);
    }

    public function revoke(Request $request, int $invitationId): JsonResponse
    {
        $invitation = $this->managedInvitation($request->user(), $invitationId);
        $this->invitations->revoke($invitation);
        $this->audit->record($request->user(), 'account.invitation_revoked', $invitation->user, [
            'invitationId' => $invitation->id,
        ]);

        return response()->json(['message' => 'Invitation revoked.']);
    }

    private function managedInvitation(User $actor, int $invitationId): AccountInvitation
    {
        $context = new TenantContext($actor);
        if (! $context->canManageBranchAccounts()) {
            throw new AuthorizationException;
        }

        $userIds = $context->scopeUsers(User::query())->select('id');

        return AccountInvitation::query()->with('user')
            ->whereIn('user_id', $userIds)->findOrFail($invitationId);
    }
}

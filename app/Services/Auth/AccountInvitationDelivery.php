<?php

namespace App\Services\Auth;

use App\DTOs\InvitationSecret;
use App\Mail\AccountInvitationMail;
use App\Models\Auth\AccountInvitation;
use Illuminate\Support\Facades\Mail;

class AccountInvitationDelivery
{
    public function queue(InvitationSecret $invitationSecret): void
    {
        $setupUrl = config('account_invitations.frontend_url').'?token='.urlencode($invitationSecret->token);
        AccountInvitation::query()->whereKey($invitationSecret->invitationId)
            ->update(['delivery_attempted_at' => now()]);
        Mail::to($invitationSecret->email)->queue(new AccountInvitationMail($setupUrl));
    }
}

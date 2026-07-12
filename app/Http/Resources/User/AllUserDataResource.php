<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AllUserDataResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'userId' => $this->id,
            'name' => $this->name ?? '',
            'username' => $this->username ?? '',
            'email' => $this->email ?? '',
            'phone' => $this->phone ?? '',
            'address' => $this->address ?? '',
            'status' => $this->status,
            'avatar' => $this->avatar ? Storage::disk('public')->url($this->avatar) : '',
            'roleId' => $this->roles->first()?->id,
            'roleName' => $this->roles->first()?->name,
            'accountType' => $this->account_type?->value,
            'companyId' => $this->company_id,
            'companyName' => $this->company?->name,
            'branchId' => $this->branch_id,
            'branchName' => $this->branch?->name,
            'pendingInvitationId' => $this->pendingInvitation?->id,
            'invitationExpiresAt' => $this->pendingInvitation?->expires_at?->toIso8601String(),
        ];
    }
}

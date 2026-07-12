<?php

namespace App\Services\Tenancy;

use App\Models\Tenancy\TenantAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TenantAuditLogger
{
    public function record(User $actor, string $event, Model $subject, array $metadata = []): void
    {
        TenantAuditEvent::create([
            'actor_user_id' => $actor->id,
            'event' => $event,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata,
        ]);
    }
}

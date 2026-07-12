<?php

namespace App\Models\Tenancy;

use Illuminate\Database\Eloquent\Model;

class TenantAuditEvent extends Model
{
    protected $fillable = ['actor_user_id', 'event', 'subject_type', 'subject_id', 'metadata'];

    protected $casts = ['metadata' => 'array'];
}

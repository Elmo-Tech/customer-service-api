<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshSession extends Model
{
    protected $fillable = [
        'user_id', 'selector', 'secret_hash', 'expires_at', 'last_used_at', 'revoked_at',
        'replaced_by_id', 'ip_hash', 'user_agent_hash',
    ];

    protected $hidden = ['secret_hash', 'selector', 'ip_hash', 'user_agent_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

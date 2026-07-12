<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountInvitation extends Model
{
    protected $fillable = [
        'user_id', 'selector', 'secret_hash', 'purpose', 'expires_at', 'consumed_at',
        'revoked_at', 'delivery_attempted_at', 'created_by_user_id',
    ];

    protected $hidden = ['selector', 'secret_hash'];

    protected $casts = [
        'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime',
        'delivery_attempted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

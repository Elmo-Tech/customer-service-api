<?php

namespace App\Models\Tiket;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReviewCapability extends Model
{
    protected $fillable = [
        'ticket_id', 'secret_hash', 'purpose', 'expires_at', 'consumed_at', 'revoked_at', 'created_ip_hash',
    ];

    protected $hidden = ['secret_hash', 'created_ip_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}

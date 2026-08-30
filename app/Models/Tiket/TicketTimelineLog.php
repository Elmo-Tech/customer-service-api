<?php

namespace App\Models\Tiket;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketTimelineLog extends Model
{
    use HasFactory;

    public const TYPE_MESSAGE = 1;

    public const TYPE_STATUS_CHANGE = 2;

    public const ACTOR_ADMIN = 1;

    public const ACTOR_CUSTOMER = 2;

    protected $fillable = [
        'ticket_id',
        'type',
        'actor_type',
        'user_id',
        'user_name',
        'message',
        'old_status',
        'new_status',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketTimelineLogAttachment::class, 'ticket_timeline_log_id');
    }
}

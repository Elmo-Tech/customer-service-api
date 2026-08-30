<?php

namespace App\Models\Tiket;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTimelineLogAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_timeline_log_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
    ];

    public function log(): BelongsTo
    {
        return $this->belongsTo(TicketTimelineLog::class, 'ticket_timeline_log_id');
    }
}

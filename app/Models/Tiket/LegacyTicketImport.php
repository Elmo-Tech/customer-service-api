<?php

namespace App\Models\Tiket;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyTicketImport extends Model
{
    protected $fillable = ['source_key', 'source_ticket_id', 'ticket_id', 'source_hash'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}

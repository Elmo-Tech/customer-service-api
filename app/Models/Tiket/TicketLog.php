<?php

namespace App\Models\Tiket;

use App\Traits\CreatedUpdatedBy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketLog extends Model
{
    use HasFactory, SoftDeletes, CreatedUpdatedBy;

    protected $fillable = [
        'status',
        'text',
        'ticket_id',
        'token'
    ];
    
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}

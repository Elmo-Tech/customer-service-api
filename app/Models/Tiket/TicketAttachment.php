<?php

namespace App\Models\Tiket;

use App\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketAttachment extends Model
{
    use CreatedUpdatedBy, HasFactory, SoftDeletes;

    protected $fillable = [
        'path',
        'storage_disk',
        'original_name',
        'file_size',
        'checksum',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function filesystemDisk(): string
    {
        return match ($this->storage_disk) {
            'private' => 'ticket_attachments',
            'public' => 'public',
        };
    }
}

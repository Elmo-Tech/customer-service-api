<?php

namespace App\Models\Tiket;

use App\Enums\Ticket\TicketImportanceStatus;
use App\Enums\Ticket\TicketStatus;
use App\Models\Company\Branch;
use App\Models\Company\Company;
use App\Models\Company\Customer;
use App\Models\User;
use App\Traits\CreatedUpdatedBy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use CreatedUpdatedBy, HasFactory, SoftDeletes;

    protected $fillable = [
        'status',
        'importance',
        'description',
        'customer_id',
        'company_id',
        'branch_id',
        'closed_at',
        'real_closed_at',
        'tag_id',
        'opened_by_user_id',
        'assigned_to_user_id',
        'due_at',
        'escalated_at',
    ];

    protected $cast = [
        'status' => TicketStatus::class,
        'importance' => TicketImportanceStatus::class,
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $requesterId = $model->customer_id ?? $model->opened_by_user_id;
            $model->ticket_number = 'T'.generateUniqNumber(4).$requesterId.'_'.Carbon::now()->format('m/Y');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function logs()
    {
        return $this->hasMany(TicketLog::class);
    }

    public function requesterName(): string
    {
        return $this->customer?->getFullName() ?? $this->openedBy?->name ?? '';
    }

    public function requesterEmail(): ?string
    {
        return $this->customer?->email ?? $this->openedBy?->email;
    }
}

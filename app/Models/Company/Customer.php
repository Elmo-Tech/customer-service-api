<?php

namespace App\Models\Company;

use App\Enums\Company\CustomerStatus;
use App\Models\User;
use App\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use CreatedUpdatedBy, HasFactory, SoftDeletes;

    protected $fillable = [
        'firstname',
        'lastname',
        'pin',
        'status',
        'company_id',
        'branch_id',
        'email',
        'user_id',
    ];

    protected $cast = [
        'status' => CustomerStatus::class,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function getFullName()
    {
        return $this->firstname.' '.$this->lastname;
    }
}

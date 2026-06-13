<?php

namespace App\Models\Company;

use App\Enums\Company\CustomerStatus;
use App\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes, CreatedUpdatedBy;

    protected $fillable = [
        'firstname',
        'lastname',
        'pin',
        'status',
        'company_id',
        'email'
    ];

    protected $cast = [
        'status' => CustomerStatus::class
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getFullName(){

        return $this->firstname . " " . $this->lastname;
    }


}

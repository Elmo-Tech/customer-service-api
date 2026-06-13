<?php

namespace App\Models\Company;

use App\Enums\Company\BranchStatus;
use App\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, SoftDeletes, CreatedUpdatedBy;

    protected $fillable = [
        'name',
        'status',
        'company_id'
    ];

    protected $cast = [
        'status' => BranchStatus::class
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

}

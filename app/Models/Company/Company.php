<?php

namespace App\Models\Company;

use App\Enums\Company\CompanyStatus;
use App\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes, CreatedUpdatedBy;

    protected $fillable = [
        'name',
        'status'
    ];

    protected $cast = [
        'status' => CompanyStatus::class
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}

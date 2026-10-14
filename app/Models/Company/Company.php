<?php

namespace App\Models\Company;

use App\Enums\Company\CompanyStatus;
use App\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use CreatedUpdatedBy, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'status',
        'legacy_ticket_enabled',
    ];

    protected $cast = [
        'status' => CompanyStatus::class,
        'legacy_ticket_enabled' => 'boolean',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(CompanyContract::class);
    }

    public function activeContract(): HasOne
    {
        return $this->hasOne(CompanyContract::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->active(),
        );
    }

    public function latestContract(): HasOne
    {
        return $this->hasOne(CompanyContract::class)->ofMany(['created_at' => 'max', 'id' => 'max']);
    }
}

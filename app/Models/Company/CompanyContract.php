<?php

namespace App\Models\Company;

use App\Traits\CreatedUpdatedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyContract extends Model
{
    use CreatedUpdatedBy, SoftDeletes;

    protected $fillable = ['company_id', 'start_date', 'end_date'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereDate('start_date', '<=', today()->toDateString())
            ->whereDate('end_date', '>=', today()->toDateString());
    }

    public function isActive(): bool
    {
        return today()->betweenIncluded($this->start_date, $this->end_date);
    }
}

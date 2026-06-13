<?php

namespace App\Filters\Ticket;

use Spatie\QueryBuilder\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class FilterTicket implements Filter
{
    public function __invoke(Builder $query, $value, string $property): Builder
    {
        return $query->where(function ($query) use ($value) {
            $query->where('ticket_number', 'like', '%' . $value . '%');
        });
    }
}

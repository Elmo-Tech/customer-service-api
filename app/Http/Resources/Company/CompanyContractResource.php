<?php

namespace App\Http\Resources\Company;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'contractId' => $this->id,
            'companyId' => $this->company_id,
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date->toDateString(),
            'status' => $this->isActive() ? 1 : 0,
        ];
    }
}

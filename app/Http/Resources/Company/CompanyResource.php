<?php

namespace App\Http\Resources\Company;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'companyId' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'usesBranches' => $this->uses_branches,
        ];
    }
}

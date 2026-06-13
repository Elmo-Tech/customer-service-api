<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customerId' => $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'pin' => $this->pin,
            'companyId' => $this->company_id,
            'status' => $this->status,
            'email' => $this->email??""
        ];
    }
}

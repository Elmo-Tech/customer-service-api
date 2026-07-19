<?php

namespace App\Http\Resources\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AllCustomerResource extends JsonResource
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
            'customerName' => $this->firstname.' '.$this->lastname,
            'username' => $this->username,
            // 'lastname' => $this->lastname,
            // 'pin' => $this->pin,
            'companyName' => $this->company->name,
            'status' => $this->status,
        ];
    }
}

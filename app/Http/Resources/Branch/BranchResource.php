<?php

namespace App\Http\Resources\Branch;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'branchId' => $this->id,
            'name' => $this->name??"",
            'status' => $this->status,
        ];
    }
}

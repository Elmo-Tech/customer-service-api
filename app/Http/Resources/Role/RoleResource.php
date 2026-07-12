<?php

namespace App\Http\Resources\Role;

use App\Services\Role\RoleTemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'permissions' => $this->permissions ? $this->permissions->pluck('name')->toArray() : [],
            'isSystem' => app(RoleTemplateCatalog::class)->isSystemRole($this->name),
        ];
    }
}

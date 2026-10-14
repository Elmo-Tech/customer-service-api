<?php

namespace App\Http\Resources\Company;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class CompanyContractCollection extends ResourceCollection
{
    private array $pagination;

    public function __construct($resource)
    {
        $this->pagination = [
            'total' => $resource->total(),
            'count' => $resource->count(),
            'perPage' => $resource->perPage(),
            'currentPage' => $resource->currentPage(),
            'totalPages' => $resource->lastPage(),
        ];
        parent::__construct($resource->getCollection());
    }

    public function toArray(Request $request): array
    {
        return [
            'result' => ['contracts' => CompanyContractResource::collection($this->collection)],
            'pagination' => $this->pagination,
        ];
    }
}

<?php

namespace App\Http\Resources\Branch;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AllBranchCollection extends ResourceCollection
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */

     private $pagination;

     public function __construct($resource)
     {
         $this->pagination = [
             'total' => $resource->total(),
             'count' => $resource->count(),
             'per_page' => $resource->perPage(),
             'current_page' => $resource->currentPage(),
             'total_pages' => $resource->lastPage()
         ];
 
         $resource = $resource->getCollection();
 
         parent::__construct($resource);
     }

     
    public function toArray(Request $request): array
    {

        return [
            "result" => [
                'branches' => BranchResource::collection(($this->collection)->values()->all()),
            ],
            'pagination' => $this->pagination
        ];

    }
}

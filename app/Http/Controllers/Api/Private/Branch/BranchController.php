<?php

namespace App\Http\Controllers\Api\Private\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\CreateBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\Branch\BranchResource;
use App\Services\Company\BranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function __construct(private readonly BranchService $branchService)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:create_branch')->only('create');
        $this->middleware('permission:edit_branch')->only('edit');
        $this->middleware('permission:update_branch')->only('update');
        $this->middleware('permission:delete_branch')->only('delete');
    }

    public function create(CreateBranchRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->branchService->createBranch($request->user(), $request->validated()));

        return response()->json(['message' => 'تم اضافة فرع جديد بنجاح']);
    }

    public function edit(Request $request): JsonResponse
    {
        $branch = $this->branchService->editBranch($request->user(), $request->integer('branchId'));

        return response()->json(new BranchResource($branch));
    }

    public function update(UpdateBranchRequest $request): JsonResponse
    {
        DB::transaction(fn () => $this->branchService->updateBranch($request->user(), $request->validated()));

        return response()->json(['message' => 'تم تحديث بيانات الفرع!']);
    }

    public function delete(Request $request): JsonResponse
    {
        $branchId = $request->integer('branchId') ?: $request->integer('BranchId');
        DB::transaction(fn () => $this->branchService->deleteBranch($request->user(), $branchId));

        return response()->json(['message' => 'تم حذف الفرع بنجاح!']);
    }
}

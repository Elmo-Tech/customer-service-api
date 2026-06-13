<?php

namespace App\Http\Controllers\Api\Private\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\CreateBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\Branch\BranchResource;
use App\Services\Company\BranchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class BranchController extends Controller
{
    protected $BranchService;
    protected $branchService;

    public function __construct(BranchService $branchService)
    {
        $this->middleware('auth:api');
        $this->middleware('permission:create_branch', ['only' => ['create']]);
        $this->middleware('permission:edit_branch', ['only' => ['edit']]);
        $this->middleware('permission:update_branch', ['only' => ['update']]);
        $this->middleware('permission:delete_branch', ['only' => ['delete']]);
        $this->branchService = $branchService;
    }

    public function create(CreateBranchRequest $createBranchRequest)
    {

        try {
            DB::beginTransaction();

            $branch = $this->branchService->createBranch($createBranchRequest->validated());

            DB::commit();

            return response()->json([
                'message' => 'تم اضافة فرع جديد بنجاح'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    /**
     * Show the form for editing the specified resource.
     */

    public function edit(Request $request)
    {
        //dd($request->branchId);
        $Branch  =  $this->branchService->editBranch($request->branchId);

        return response()->json(
            new BranchResource($Branch)//new UserResource($user)
        ,200);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBranchRequest $updateBranchRequest)
    {

        try {
            DB::beginTransaction();
            $this->branchService->updateBranch($updateBranchRequest->validated());

            DB::commit();
            return response()->json([
                 'message' => 'تم تحديث بيانات الفرع!'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

    /**
     * Remove the specified resource from storage.
     */
    public function delete(Request $request)
    {

        try {
            DB::beginTransaction();
            $this->branchService->deleteBranch($request->BranchId);
            DB::commit();
            return response()->json([
                'message' => 'تم حذف الفرع بنجاح!'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }


    }

}

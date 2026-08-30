<?php

use App\Http\Controllers\Api\Private\Branch\BranchController;
use App\Http\Controllers\Api\Private\Company\CompanyController;
use App\Http\Controllers\Api\Private\Customer\CustomerController;
use App\Http\Controllers\Api\Private\Role\RoleController;
use App\Http\Controllers\Api\Private\Select\SelectController;
use App\Http\Controllers\Api\Private\Ticket\AdminTicketController;
use App\Http\Controllers\Api\Private\Ticket\CustomerTicketController;
use App\Http\Controllers\Api\Private\Ticket\TicketLogController;
use App\Http\Controllers\Api\Private\Ticket\TicketPublicController;
use App\Http\Controllers\Api\Private\Ticket\TicketTimelineController;
use App\Http\Controllers\Api\Private\User\UserController;
use App\Http\Controllers\Api\Public\Auth\AuthController;
use App\Http\Controllers\Api\Public\Ticket\LegacyTicketOptionsController;
use App\Http\Controllers\Api\Public\Ticket\PublicTicketSubmissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1/admin/auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
});

Route::prefix('v1/admin/users')->group(function () {
    Route::get('', [UserController::class, 'allUsers']);
    Route::post('create', [UserController::class, 'create']);
    Route::get('edit', [UserController::class, 'edit']);
    Route::put('update', [UserController::class, 'update']);
    Route::delete('delete', [UserController::class, 'delete']);
    Route::put('changestatus', [UserController::class, 'changeStatus']);
});

Route::prefix('v1/admin/companies')->group(function () {
    Route::get('', [CompanyController::class, 'allCompanies']);
    Route::post('create', [CompanyController::class, 'create']);
    Route::get('edit', [CompanyController::class, 'edit']);
    Route::put('update', [CompanyController::class, 'update']);
    Route::delete('delete', [CompanyController::class, 'delete']);

});

Route::prefix('v1/admin/branches')->group(function () {
    Route::post('create', [BranchController::class, 'create']);
    Route::get('edit', [BranchController::class, 'edit']);
    Route::put('update', [BranchController::class, 'update']);
    Route::delete('delete', [BranchController::class, 'delete']);
});

Route::prefix('v1/admin/customers')->group(function () {
    Route::get('', [CustomerController::class, 'allCustomers']);
    Route::post('create', [CustomerController::class, 'create']);
    Route::get('edit', [CustomerController::class, 'edit']);
    Route::put('update', [CustomerController::class, 'update']);
    Route::delete('delete', [CustomerController::class, 'delete']);
});

Route::prefix('v1/admin/tickets')->group(function () {
    Route::get('', [AdminTicketController::class, 'allTickets']);
    Route::get('edit', [AdminTicketController::class, 'edit']);
    Route::get('timeline', [TicketTimelineController::class, 'timeline']);
    Route::post('messages', [TicketTimelineController::class, 'storeAdminMessage']);
    Route::put('update', [AdminTicketController::class, 'update']);
    Route::delete('delete', [AdminTicketController::class, 'delete']);
});

Route::prefix('v1/tickets')->group(function () {
    Route::post('create', [CustomerTicketController::class, 'create']);
    Route::get('timeline', [TicketTimelineController::class, 'customerTimeline']);
    Route::post('messages', [TicketTimelineController::class, 'storeCustomerMessage']);
    Route::put('status', [TicketTimelineController::class, 'updateCustomerStatus']);
});

Route::prefix('v1/admin/roles')->group(function () {
    Route::get('', [RoleController::class, 'allRoles']);
    Route::post('create', [RoleController::class, 'create']);
    Route::get('edit', [RoleController::class, 'edit']);
    Route::put('update', [RoleController::class, 'update']);
    Route::delete('delete', [RoleController::class, 'delete']);
});

Route::prefix('v1/selects')->group(function () {
    Route::get('', [SelectController::class, 'getSelects']);
});

Route::get('v1/public/ticket', [TicketPublicController::class, 'show']);
Route::post('v1/public/tickets/identify', [PublicTicketSubmissionController::class, 'identify'])
    ->middleware('throttle:public-ticket-identify');
Route::post('v1/public/tickets/create', [PublicTicketSubmissionController::class, 'create'])
    ->middleware('throttle:public-ticket-create');
Route::get('v1/public/legacy-ticket-options', [LegacyTicketOptionsController::class, 'index'])
    ->middleware('throttle:public-ticket-options');
Route::get('v1/public/legacy-ticket-options/branches', [LegacyTicketOptionsController::class, 'branches'])
    ->middleware('throttle:public-ticket-options');

Route::prefix('v1/ticket-logs')->group(function () {
    Route::get('/', [TicketLogController::class, 'index']); // auth:api
    Route::post('/', [TicketLogController::class, 'store']); // public
});

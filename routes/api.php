<?php

use App\Http\Controllers\Api\Private\Branch\BranchController;
use App\Http\Controllers\Api\Private\Company\CompanyController;
use App\Http\Controllers\Api\Private\Company\CompanyOnboardingController;
use App\Http\Controllers\Api\Private\Customer\CustomerController;
use App\Http\Controllers\Api\Private\Role\RoleController;
use App\Http\Controllers\Api\Private\Select\SelectController;
use App\Http\Controllers\Api\Private\Ticket\AdminTicketController;
use App\Http\Controllers\Api\Private\Ticket\TicketAttachmentController;
use App\Http\Controllers\Api\Private\Ticket\TicketLogController;
use App\Http\Controllers\Api\Private\Ticket\TicketPublicController;
use App\Http\Controllers\Api\Private\Ticket\TicketReportController;
use App\Http\Controllers\Api\Private\User\UserController;
use App\Http\Controllers\Api\Public\Auth\AccountInvitationController;
use App\Http\Controllers\Api\Public\Auth\AuthController;
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
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('throttle:logout');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware(['throttle:refresh', 'trustedOrigin']);
    Route::get('me', [AuthController::class, 'me']);
});

Route::post('v1/account-invitations/setup', [AccountInvitationController::class, 'setup'])
    ->middleware('throttle:invitation-setup');
Route::post('v1/admin/account-invitations/{invitationId}/resend', [AccountInvitationController::class, 'resend'])
    ->middleware('throttle:invitation-resend');
Route::post('v1/admin/account-invitations/{invitationId}/revoke', [AccountInvitationController::class, 'revoke']);

Route::prefix('v1/admin/users')->group(function () {
    Route::get('export', [UserController::class, 'export']);
    Route::get('', [UserController::class, 'allUsers']);
    Route::post('create', [UserController::class, 'create']);
    Route::get('edit', [UserController::class, 'edit']);
    Route::put('update', [UserController::class, 'update']);
    Route::delete('delete', [UserController::class, 'delete']);
    Route::put('changestatus', [UserController::class, 'changeStatus']);
});

Route::prefix('v1/admin/companies')->group(function () {
    Route::get('onboarding-options', [CompanyOnboardingController::class, 'options']);
    Route::get('export', [CompanyController::class, 'export']);
    Route::post('onboard', [CompanyOnboardingController::class, 'store']);
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
    Route::get('export', [CustomerController::class, 'export']);
    Route::get('', [CustomerController::class, 'allCustomers']);
    Route::post('create', [CustomerController::class, 'create']);
    Route::get('edit', [CustomerController::class, 'edit']);
    Route::put('update', [CustomerController::class, 'update']);
    Route::delete('delete', [CustomerController::class, 'delete']);
});

Route::prefix('v1/admin/tickets')->group(function () {
    Route::post('create', [AdminTicketController::class, 'create']);
    Route::get('', [AdminTicketController::class, 'allTickets']);
    Route::get('edit', [AdminTicketController::class, 'edit']);
    Route::put('update', [AdminTicketController::class, 'update']);
    Route::delete('delete', [AdminTicketController::class, 'delete']);
    Route::get('export', [TicketReportController::class, 'export'])->name('tickets.export');
    Route::get('dashboard', [TicketReportController::class, 'dashboard'])->name('tickets.dashboard');
});

Route::prefix('v1/admin/roles')->group(function () {
    Route::get('matrix', [RoleController::class, 'matrix']);
    Route::get('', [RoleController::class, 'allRoles']);
    Route::post('create', [RoleController::class, 'create']);
    Route::get('edit', [RoleController::class, 'edit']);
    Route::put('update', [RoleController::class, 'update']);
    Route::delete('delete', [RoleController::class, 'delete']);
});

Route::prefix('v1/selects')->group(function () {
    Route::get('', [SelectController::class, 'getSelects']);
});

Route::get('v1/public/ticket', [TicketPublicController::class, 'show'])->middleware('throttle:ticket-review');
Route::get('v1/admin/tickets/{ticketId}/attachments/{attachmentId}', [TicketAttachmentController::class, 'download'])
    ->name('tickets.attachments.download');
Route::get('v1/public/tickets/{ticketId}/attachments/{attachmentId}', [TicketAttachmentController::class, 'reviewDownload'])
    ->middleware('throttle:ticket-review')
    ->name('tickets.attachments.review-download');

Route::prefix('v1/ticket-logs')->group(function () {
    Route::get('/', [TicketLogController::class, 'index']);
    Route::post('/', [TicketLogController::class, 'store'])->middleware('throttle:ticket-review');
});

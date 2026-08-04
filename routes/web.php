<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CheckinSettingController;
use App\Http\Controllers\Admin\ClubSettingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MailSettingController;
use App\Http\Controllers\Admin\MeetingSessionController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\TitleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttendanceFormController;
use App\Http\Controllers\PayPlusCallbackController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\SuperAdmin\AuthController as SuperAdminAuthController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Http\Controllers\SuperAdmin\PasswordController as SuperAdminPasswordController;
use App\Http\Controllers\SuperAdmin\PlanController;
use App\Http\Controllers\SuperAdmin\PlatformSettingController;
use App\Http\Controllers\SuperAdmin\TenantController;
use App\Http\Controllers\SuperAdmin\WelcomeController;
use App\Http\Middleware\CheckTenantSubscription;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.super_admin_host'))->group(function () {
    Route::get('/', [WelcomeController::class, 'show'])->name('super-admin.welcome');

    Route::get('inscription', [SignupController::class, 'show'])->name('signup.show');
    Route::post('inscription', [SignupController::class, 'store'])->name('signup.store');
    Route::get('inscription/pending', [SignupController::class, 'pending'])->name('signup.pending');
    Route::get('inscription/status', [SignupController::class, 'checkPaymentStatus'])->name('signup.status');

    Route::prefix('superadmin')->name('super-admin.')->group(function () {
        Route::middleware('guest:super_admin')->group(function () {
            Route::get('login', [SuperAdminAuthController::class, 'create'])->name('login');
            Route::post('login', [SuperAdminAuthController::class, 'store'])->name('login.store');
        });

        Route::middleware(['auth:super_admin', 'auth.session.guard:super_admin'])->group(function () {
            Route::post('logout', [SuperAdminAuthController::class, 'destroy'])->name('logout');
            Route::get('dashboard', [SuperAdminDashboardController::class, 'index'])->name('dashboard');
            Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
            Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
            Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
            Route::patch('tenants/grace-period', [TenantController::class, 'updateGracePeriod'])->name('tenants.grace-period');
            Route::post('tenants/{tenant}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate.start');
            Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
            Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
            Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
            Route::patch('plans/{plan}/toggle-active', [PlanController::class, 'toggleActive'])->name('plans.toggle-active');
            Route::get('settings', [PlatformSettingController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [PlatformSettingController::class, 'update'])->name('settings.update');
            Route::get('password', [SuperAdminPasswordController::class, 'edit'])->name('password.edit');
            Route::put('password', [SuperAdminPasswordController::class, 'update'])->name('password.update');
        });
    });
});

Route::middleware(ResolveTenant::class)->group(function () {
    Route::middleware(CheckTenantSubscription::class)->group(function () {
        Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
        Route::post('/check-in', [AttendanceFormController::class, 'lookup'])->name('attendance.lookup');
        Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('guest')->group(function () {
            Route::get('login', [AuthController::class, 'create'])->name('login');
            Route::post('login', [AuthController::class, 'store'])->name('login.store');
        });

        Route::middleware(['auth:web,super_admin', 'auth.session.guard:web'])->group(function () {
            Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

            Route::get('subscription', [SubscriptionController::class, 'index'])->name('subscription.index');
            Route::post('subscription/checkout', [SubscriptionController::class, 'checkout'])->name('subscription.checkout');
            Route::get('subscription/pending', [SubscriptionController::class, 'pending'])->name('subscription.pending');
            Route::get('subscription/status', [SubscriptionController::class, 'checkPaymentStatus'])->name('subscription.status');

            Route::middleware(CheckTenantSubscription::class)->group(function () {
                Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
                Route::get('sessions', [MeetingSessionController::class, 'index'])->name('sessions.index');
                Route::post('sessions', [MeetingSessionController::class, 'store'])->name('sessions.store');
                Route::post('sessions/{meetingSession}/toggle-open', [MeetingSessionController::class, 'toggleOpen'])->name('sessions.toggle-open');
                Route::get('sessions/{meetingSession}', [MeetingSessionController::class, 'show'])->name('sessions.show');
                Route::get('sessions/{meetingSession}/export-pdf', [MeetingSessionController::class, 'exportPdf'])->name('sessions.export-pdf');
                Route::patch('attendances/{attendance}/toggle-present', [AttendanceController::class, 'togglePresent'])->name('attendances.toggle-present');
                Route::get('users', [UserController::class, 'index'])->name('users.index');
                Route::get('users/create', [UserController::class, 'create'])->name('users.create');
                Route::post('users', [UserController::class, 'store'])->name('users.store');
                Route::get('members', [MemberController::class, 'index'])->name('members.index');
                Route::get('members/create', [MemberController::class, 'create'])->name('members.create');
                Route::post('members', [MemberController::class, 'store'])->name('members.store');
                Route::get('members/import-template', [MemberController::class, 'importTemplate'])->name('members.import-template');
                Route::post('members/import', [MemberController::class, 'import'])->name('members.import');
                Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
                Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
                Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
                Route::get('titles', [TitleController::class, 'index'])->name('titles.index');
                Route::get('titles/create', [TitleController::class, 'create'])->name('titles.create');
                Route::post('titles', [TitleController::class, 'store'])->name('titles.store');
                Route::get('titles/{title}/edit', [TitleController::class, 'edit'])->name('titles.edit');
                Route::put('titles/{title}', [TitleController::class, 'update'])->name('titles.update');
                Route::patch('titles/{title}/toggle-active', [TitleController::class, 'toggleActive'])->name('titles.toggle-active');
                Route::patch('titles/{title}/move-order/{direction}', [TitleController::class, 'moveOrder'])->name('titles.move-order');
                Route::delete('titles/{title}', [TitleController::class, 'destroy'])->name('titles.destroy');
                Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
                Route::get('positions/create', [PositionController::class, 'create'])->name('positions.create');
                Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
                Route::get('positions/{position}/edit', [PositionController::class, 'edit'])->name('positions.edit');
                Route::put('positions/{position}', [PositionController::class, 'update'])->name('positions.update');
                Route::patch('positions/{position}/toggle-active', [PositionController::class, 'toggleActive'])->name('positions.toggle-active');
                Route::patch('positions/{position}/move-order/{direction}', [PositionController::class, 'moveOrder'])->name('positions.move-order');
                Route::delete('positions/{position}', [PositionController::class, 'destroy'])->name('positions.destroy');
                Route::get('mail-settings', [MailSettingController::class, 'edit'])->name('mail-settings.edit');
                Route::put('mail-settings', [MailSettingController::class, 'update'])->name('mail-settings.update');
                Route::post('mail-settings/test', [MailSettingController::class, 'sendTest'])->name('mail-settings.test');
                Route::get('checkin-settings', [CheckinSettingController::class, 'edit'])->name('checkin-settings.edit');
                Route::put('checkin-settings', [CheckinSettingController::class, 'update'])->name('checkin-settings.update');
                Route::get('club-settings', [ClubSettingController::class, 'edit'])->name('club-settings.edit');
                Route::put('club-settings', [ClubSettingController::class, 'update'])->name('club-settings.update');
            });
        });

        Route::middleware(['auth:web', 'auth.session.guard:web'])->group(function () {
            Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
            Route::put('password', [PasswordController::class, 'update'])->name('password.update');
        });
    });
});

Route::post('/payplus/callback', [PayPlusCallbackController::class, 'handle'])->name('payplus.callback');

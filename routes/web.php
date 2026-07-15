<?php

use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\MailSettingController;
use App\Http\Controllers\Admin\MeetingSessionController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\TitleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttendanceFormController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
Route::post('/check-in', [AttendanceFormController::class, 'lookup'])->name('attendance.lookup');
Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'create'])->name('login');
        Route::post('login', [AuthController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'destroy'])->name('logout');
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
        Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
        Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
        Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::get('titles', [TitleController::class, 'index'])->name('titles.index');
        Route::get('titles/create', [TitleController::class, 'create'])->name('titles.create');
        Route::post('titles', [TitleController::class, 'store'])->name('titles.store');
        Route::get('titles/{title}/edit', [TitleController::class, 'edit'])->name('titles.edit');
        Route::put('titles/{title}', [TitleController::class, 'update'])->name('titles.update');
        Route::get('positions', [PositionController::class, 'index'])->name('positions.index');
        Route::get('positions/create', [PositionController::class, 'create'])->name('positions.create');
        Route::post('positions', [PositionController::class, 'store'])->name('positions.store');
        Route::get('positions/{position}/edit', [PositionController::class, 'edit'])->name('positions.edit');
        Route::put('positions/{position}', [PositionController::class, 'update'])->name('positions.update');
        Route::get('mail-settings', [MailSettingController::class, 'edit'])->name('mail-settings.edit');
        Route::put('mail-settings', [MailSettingController::class, 'update'])->name('mail-settings.update');
        Route::post('mail-settings/test', [MailSettingController::class, 'sendTest'])->name('mail-settings.test');
    });
});

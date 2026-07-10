<?php

use App\Http\Controllers\AttendanceFormController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AttendanceFormController::class, 'show'])->name('attendance.show');
Route::post('/attendances', [AttendanceFormController::class, 'store'])->name('attendance.store');

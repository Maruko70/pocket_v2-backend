<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ComplexController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\MajorController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\DeviceTokenController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/login-unified', [AuthController::class, 'loginUnified']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

// Public browse (no auth required)
Route::get('/doctors/search', [DoctorController::class, 'search']);
Route::get('/doctors/featured', [DoctorController::class, 'featured']);
Route::get('/complexes/search', [ComplexController::class, 'search']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/doctors/{id}', [DoctorController::class, 'show']);
    Route::get('/doctors/{id}/complexes', [DoctorController::class, 'complexes']);
    Route::get('/doctors/{id}/available-days', [DoctorController::class, 'availableDays']);
    Route::get('/doctors/{id}/availability', [DoctorController::class, 'availability']);
    Route::get('/doctors/{id}/ticket-availability', [DoctorController::class, 'ticketAvailability']);

    Route::get('/complexes/{id}', [ComplexController::class, 'show']);

    Route::get('/majors', [MajorController::class, 'index']);
    Route::get('/majors/{id}/doctors', [MajorController::class, 'doctors']);

    Route::get('/reservations', [ReservationController::class, 'index']);
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::get('/reservations/{id}/can-review', [ReservationController::class, 'canReview']);
    Route::get('/reservations/{id}', [ReservationController::class, 'show']);
    Route::delete('/reservations/{id}', [ReservationController::class, 'destroy']);

    Route::get('/doctors/{id}/reviews', [ReviewController::class, 'index']);
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{id}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);

    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{id}', [FavoriteController::class, 'destroy']);
    Route::post('/favorites/toggle/{doctorId}', [FavoriteController::class, 'toggle']);

    Route::post('/user/device-tokens', [DeviceTokenController::class, 'store']);
});

// Dashboard routes (admin panel)
require __DIR__.'/dashboard.php';

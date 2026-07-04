<?php

use App\Http\Controllers\Dashboard\ActivityLogController;
use App\Http\Controllers\Dashboard\AdminUserManagementController;
use App\Http\Controllers\Dashboard\AnalyticsController;
use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\AvailabilityController;
use App\Http\Controllers\Dashboard\ComplexManagementController;
use App\Http\Controllers\Dashboard\DoctorTicketConfigController;
use App\Http\Controllers\Dashboard\DoctorAuthController;
use App\Http\Controllers\Dashboard\DoctorManagementController;
use App\Http\Controllers\Dashboard\DoctorPauseController;
use App\Http\Controllers\Dashboard\DoctorProfileController;
use App\Http\Controllers\Dashboard\DoctorStatsController;
use App\Http\Controllers\Dashboard\DoctorReservationController;
use App\Http\Controllers\Dashboard\DoctorDeviceTokenController;
use App\Http\Controllers\Dashboard\DoctorUnavailableController;
use App\Http\Controllers\Dashboard\DoctorUnavailabilityRequestController;
use App\Http\Controllers\Dashboard\MajorManagementController;
use App\Http\Controllers\Dashboard\ReservationManagementController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\UserManagementController;
use App\Http\Controllers\Dashboard\WeeklyScheduleController;
use Illuminate\Support\Facades\Route;

// Dashboard auth routes (public)
Route::prefix('dashboard')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::middleware([\App\Http\Middleware\EnsureAdminUser::class])->group(function () {
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
    });
});

// Doctor portal auth (public login; protected user/logout)
Route::prefix('dashboard')->group(function () {
    Route::post('/doctor-auth/login', [DoctorAuthController::class, 'login']);
    Route::middleware([\App\Http\Middleware\EnsureDoctorUser::class])->group(function () {
        Route::get('/doctor-auth/user', [DoctorAuthController::class, 'user']);
        Route::post('/doctor-auth/logout', [DoctorAuthController::class, 'logout']);
    });
});

// Doctor portal API (scoped to authenticated doctor)
Route::prefix('dashboard')->middleware(\App\Http\Middleware\EnsureDoctorUser::class)->group(function () {
    Route::get('/doctor/profile', [DoctorProfileController::class, 'show']);
    Route::get('/doctor/stats', [DoctorStatsController::class, 'index']);
    Route::get('/doctor/reservations', [DoctorReservationController::class, 'index']);
    Route::get('/doctor/reservations/{id}', [DoctorReservationController::class, 'show']);
    Route::post('/doctor/unavailable', [DoctorUnavailableController::class, 'store']);
    Route::post('/doctor/device-tokens', [DoctorDeviceTokenController::class, 'store']);
    Route::get('/doctor/pause-dates', [DoctorPauseController::class, 'index']);
    Route::post('/doctor/pause-dates', [DoctorPauseController::class, 'store']);
    Route::delete('/doctor/pause-dates/{date}', [DoctorPauseController::class, 'destroy']);
});

// Dashboard routes (protected by admin + permission + activity log)
$dashboardMiddleware = [
    \App\Http\Middleware\EnsureAdminUser::class,
    'log.activity',
];

Route::prefix('dashboard')->middleware($dashboardMiddleware)->group(function () {
    // Doctors
    Route::middleware('dashboard.permission:doctors.view,doctors.manage')->group(function () {
        Route::get('doctors', [DoctorManagementController::class, 'index']);
        Route::get('doctors/featured', [DoctorManagementController::class, 'featured']);
        Route::get('doctors/{doctor}', [DoctorManagementController::class, 'show']);
    });
    Route::middleware('dashboard.permission:doctors.manage')->group(function () {
        Route::put('doctors/featured', [DoctorManagementController::class, 'updateFeatured']);
        Route::post('doctors/{id}/account', [DoctorManagementController::class, 'storeAccount']);
        Route::post('doctors/{id}/status', [DoctorManagementController::class, 'updateStatus']);
        Route::post('doctors/bulk-status', [DoctorManagementController::class, 'bulkUpdateStatus']);
        Route::post('doctors', [DoctorManagementController::class, 'store']);
        Route::put('doctors/{doctor}', [DoctorManagementController::class, 'update']);
        Route::delete('doctors/{doctor}', [DoctorManagementController::class, 'destroy']);
    });

    // Unavailability requests (admin approval)
    Route::middleware('dashboard.permission:doctors.manage')->group(function () {
        Route::get('unavailability-requests', [DoctorUnavailabilityRequestController::class, 'index']);
        Route::get('unavailability-requests/{id}', [DoctorUnavailabilityRequestController::class, 'show']);
        Route::post('unavailability-requests/{id}/approve', [DoctorUnavailabilityRequestController::class, 'approve']);
        Route::post('unavailability-requests/{id}/reject', [DoctorUnavailabilityRequestController::class, 'reject']);
    });

    // Complexes
    Route::middleware('dashboard.permission:complexes.view,complexes.manage')->group(function () {
        Route::get('complexes', [ComplexManagementController::class, 'index']);
        Route::get('complexes/{complex}', [ComplexManagementController::class, 'show']);
        Route::get('complexes/{complexId}/ticket-configs', [DoctorTicketConfigController::class, 'index']);
    });
    Route::middleware('dashboard.permission:complexes.manage')->group(function () {
        Route::post('complexes', [ComplexManagementController::class, 'store']);
        Route::put('complexes/{complex}', [ComplexManagementController::class, 'update']);
        Route::delete('complexes/{complex}', [ComplexManagementController::class, 'destroy']);
        Route::post('complexes/{complexId}/ticket-configs', [DoctorTicketConfigController::class, 'store']);
        Route::put('ticket-configs/{id}', [DoctorTicketConfigController::class, 'update']);
        Route::get('complexes/{complexId}/doctors/{doctorId}/specialties', [ComplexManagementController::class, 'getDoctorSpecialties']);
        Route::put('complexes/{complexId}/doctors/{doctorId}/specialties', [ComplexManagementController::class, 'updateDoctorSpecialties']);
        Route::post('complexes/{complexId}/chips', [ComplexManagementController::class, 'storeChip']);
        Route::delete('complexes/{complexId}/chips/{chipId}', [ComplexManagementController::class, 'destroyChip']);
        Route::get('complexes/{complexId}/doctors/{doctorId}/chips', [ComplexManagementController::class, 'getDoctorChips']);
        Route::put('complexes/{complexId}/doctors/{doctorId}/chips', [ComplexManagementController::class, 'updateDoctorChips']);
    });

    // Majors
    Route::middleware('dashboard.permission:majors.manage')->group(function () {
        Route::apiResource('majors', MajorManagementController::class);
    });

    // Availability & Weekly Schedule
    Route::middleware('dashboard.permission:availability.manage')->group(function () {
        Route::get('availability', [AvailabilityController::class, 'index']);
        Route::post('availability', [AvailabilityController::class, 'store']);
        Route::post('availability/bulk', [AvailabilityController::class, 'bulkCreate']);
        Route::post('availability/bulk-range', [AvailabilityController::class, 'bulkCreateRange']);
        Route::put('availability/{id}', [AvailabilityController::class, 'update']);
        Route::delete('availability/{id}', [AvailabilityController::class, 'destroy']);

        Route::get('weekly-schedule', [WeeklyScheduleController::class, 'index']);
        Route::post('weekly-schedule', [WeeklyScheduleController::class, 'store']);
        Route::post('weekly-schedule/bulk', [WeeklyScheduleController::class, 'bulkStore']);
        Route::put('weekly-schedule/{id}', [WeeklyScheduleController::class, 'update']);
        Route::delete('weekly-schedule/{id}', [WeeklyScheduleController::class, 'destroy']);
        Route::post('weekly-schedule/{doctorId}/toggle-day', [WeeklyScheduleController::class, 'toggleDay']);
    });

    // Reservations: export must be defined before reservations/{id}
    Route::get('reservations/export', [ReservationManagementController::class, 'export'])
        ->middleware('dashboard.permission:reservations.export,reservations.manage');

    Route::get('reservations/available-days', [ReservationManagementController::class, 'availableDays'])
        ->middleware('dashboard.permission:reservations.create');
    Route::get('reservations/paused-dates', [ReservationManagementController::class, 'pausedDates'])
        ->middleware('dashboard.permission:reservations.create');
    Route::get('reservations/slot-availability', [ReservationManagementController::class, 'slotAvailability'])
        ->middleware('dashboard.permission:reservations.create');
    Route::get('reservations/ticket-availability', [ReservationManagementController::class, 'ticketAvailability'])
        ->middleware('dashboard.permission:reservations.create');

    Route::post('reservations', [ReservationManagementController::class, 'store'])
        ->middleware('dashboard.permission:reservations.create');

    Route::middleware('dashboard.permission:reservations.view,reservations.manage')->group(function () {
        Route::get('reservations', [ReservationManagementController::class, 'index']);
        Route::get('reservations/{id}', [ReservationManagementController::class, 'show']);
    });

    Route::middleware('dashboard.permission:reservations.manage')->group(function () {
        Route::put('reservations/{id}/status', [ReservationManagementController::class, 'updateStatus']);
    });

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index'])
        ->middleware('dashboard.permission:analytics.view');
    Route::get('analytics/period-counts', [AnalyticsController::class, 'periodCounts'])
        ->middleware('dashboard.permission:analytics.view,reservations.manage');

    // Activity Logs
    Route::middleware('dashboard.permission:activity_logs.view')->group(function () {
        Route::get('activity-logs', [ActivityLogController::class, 'index']);
        Route::get('activity-logs/{id}', [ActivityLogController::class, 'show']);
    });

    // User Control (app users)
    Route::middleware('dashboard.permission:users.view')->group(function () {
        Route::get('users', [UserManagementController::class, 'index']);
        Route::get('users/{id}', [UserManagementController::class, 'show']);
    });

    // Admin Control (dashboard admins)
    Route::middleware('dashboard.permission:admins.manage')->group(function () {
        Route::get('admins', [AdminUserManagementController::class, 'index']);
        Route::post('admins', [AdminUserManagementController::class, 'store']);
        Route::get('admins/{id}', [AdminUserManagementController::class, 'show']);
        Route::put('admins/{id}', [AdminUserManagementController::class, 'update']);
        Route::delete('admins/{id}', [AdminUserManagementController::class, 'destroy']);
    });

    // Notifications (admin broadcasts)
    Route::middleware('dashboard.permission:notifications.send')->group(function () {
        Route::post('notifications/send', [NotificationController::class, 'send']);
        Route::post('notifications/upload-image', [NotificationController::class, 'uploadImage']);
        Route::get('notifications/history', [NotificationController::class, 'history']);
        Route::post('notifications/{id}/resend', [NotificationController::class, 'resend']);
    });
});

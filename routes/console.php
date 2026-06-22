<?php

use App\Jobs\CheckDoctorStatusJob;
use App\Jobs\MarkNoShowReservationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    CheckDoctorStatusJob::dispatch();
})->dailyAt('00:00')->timezone('Africa/Khartoum');

Schedule::call(function () {
    MarkNoShowReservationsJob::dispatch();
})->dailyAt('01:00')->timezone('Africa/Khartoum');

Schedule::call(function () {
    \App\Jobs\SendReservationRemindersJob::dispatch();
})->hourly()->timezone('Africa/Khartoum');

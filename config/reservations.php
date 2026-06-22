<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Auto no-show hours
    |--------------------------------------------------------------------------
    | After this many hours past the appointment date/time, confirmed
    | reservations are automatically marked as no_show by MarkNoShowReservationsJob.
    | Set to 0 to disable auto-no-show.
    */
    'auto_no_show_hours' => (int) env('RESERVATION_AUTO_NO_SHOW_HOURS', 24),
];

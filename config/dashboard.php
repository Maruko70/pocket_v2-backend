<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard permission keys
    |--------------------------------------------------------------------------
    | Use these when assigning permissions to admin users (JSON array in
    | admin_users.permissions). Role 'admin' is treated as having all permissions.
    |
    | Example seeder: admin gets all; manager gets [
    |   'doctors.manage', 'complexes.manage', 'majors.manage', 'availability.manage',
    |   'reservations.manage', 'reservations.export', 'analytics.view', 'activity_logs.view'
    | ]
    */

    'permissions' => [
        'doctors.view',
        'doctors.manage',
        'complexes.view',
        'complexes.manage',
        'majors.manage',
        'availability.manage',
        'reservations.create',
        'reservations.view',
        'reservations.manage',
        'reservations.export',
        'analytics.view',
        'activity_logs.view',
        'users.view',
        'admins.manage',
        'notifications.send',
    ],

];

<?php

return [

    /*
    | Minutes before a location's cutoff when a reminder is sent if nothing was ordered (0 disables).
    */
    'reminder_minutes' => (int) env('CRM_REMINDER_MINUTES', 30),

    'support_phone' => env('CRM_SUPPORT_PHONE', '+998 95 144 94 00'),

    /*
    |--------------------------------------------------------------------------
    | Demo login helper
    |--------------------------------------------------------------------------
    |
    | When enabled, the CRM login page lists the seeded demo accounts so they
    | can be used with one click. Keep this disabled outside local development.
    |
    */

    'demo_login' => (bool) env('CRM_DEMO_LOGIN', false),

    /*
    |--------------------------------------------------------------------------
    | Seeded account password
    |--------------------------------------------------------------------------
    */

    'seed_password' => env('CRM_SEED_PASSWORD', 'password'),

    'demo_users' => [
        ['name' => 'Admin', 'email' => 'admin@thenon.uz', 'role' => 'admin'],
        ['name' => 'Operator', 'email' => 'operator@thenon.uz', 'role' => 'operator'],
    ],

];

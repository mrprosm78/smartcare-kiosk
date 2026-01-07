<?php

return [
    'carehome' => [
        'name' => 'Care Home',
        'timezone' => 'Europe/London',
    ],
    'kiosk' => [
        'code' => 'KIOSK-1',
        'name' => 'Front Desk',
    ],

    // Security + behaviour
    'pin_length' => 4,
    'max_shift_minutes' => 960,
    'min_seconds_between_punches' => 10,

    // DEV only — set false before going live
    'allow_plain_pin' => true,
];

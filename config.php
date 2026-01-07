<?php
return [
  'carehome' => [
    'name' => 'My Care Home',
    'timezone' => 'Europe/London',
  ],
  'kiosk' => [
    'code' => 'KIOSK-1',      // allowed kiosk code
    'name' => 'Front Desk',
  ],
  'pin_length' => 4,
  'max_shift_minutes' => 960,          // 16h
  'min_seconds_between_punches' => 20, // anti double tap
  'allow_plain_pin' => true,           // dev only
];

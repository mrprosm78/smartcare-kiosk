-- Remove legacy care-home payroll rule settings (Jan 2026 cleanup)
-- Safe to run multiple times.

DELETE FROM kiosk_settings
WHERE `key` IN (
  'default_break_minutes',
  'night_shift_threshold_percent',
  'night_premium_enabled',
  'night_premium_start',
  'night_premium_end',
  'overtime_default_multiplier',
  'weekend_premium_enabled',
  'weekend_days',
  'weekend_rate_multiplier',
  'bank_holiday_enabled',
  'bank_holiday_paid',
  'bank_holiday_paid_cap_hours',
  'bank_holiday_rate_multiplier',
  'payroll_overtime_priority',
  'payroll_overtime_threshold_hours',
  'payroll_stacking_mode',
  'payroll_night_start',
  'payroll_night_end',
  'payroll_bank_holiday_cap_hours',
  'payroll_callout_min_paid_hours',
  'default_night_multiplier',
  'default_night_premium_per_hour',
  'default_weekend_multiplier',
  'default_weekend_premium_per_hour',
  'default_bank_holiday_multiplier',
  'default_bank_holiday_premium_per_hour',
  'default_overtime_multiplier',
  'default_overtime_premium_per_hour',
  'default_callout_multiplier',
  'default_callout_premium_per_hour'
);

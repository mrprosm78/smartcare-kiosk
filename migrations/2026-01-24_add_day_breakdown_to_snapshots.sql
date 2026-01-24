-- Adds per-day breakdown JSON for payroll shift snapshots (used for employee week/day audit view)
ALTER TABLE payroll_shift_snapshots
  ADD COLUMN day_breakdown_json MEDIUMTEXT NULL;

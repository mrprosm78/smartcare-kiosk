# SmartCare Kiosk — Payroll Rules & Engineering Notes (Jan 2026)

This README is the **source of truth** for payroll rules and key system decisions agreed in **Jan 2026**.

## 1) Locked system rules

### UTC storage + payroll timezone boundaries
- All database timestamps are stored in **UTC**.
- Payroll day/week/month boundaries are computed in **payroll timezone** (`kiosk_settings.payroll_timezone`, usually `Europe/London`) and then converted to UTC for querying.

### Month boundary mode (care-home setting; superadmin only)
`kiosk_settings.payroll_month_boundary_mode` controls how cross-month shifts are assigned:
- `midnight` (default, recommended): split at local midnight; each minute belongs to the month/day it was worked.
- `end_of_shift` (advanced): assign the whole shift to the month of its start date.
This should not be changed retroactively after payroll has been run.

### Week start is global and locked
- Week boundaries for payroll/overtime/rota views use `kiosk_settings.payroll_week_starts_on`.
- This is set at initial setup and becomes **read-only** after `app_initialized = 1`.

### Shift open/closed truth (do not trust is_closed)
- **Open shift:** `kiosk_shifts.clock_out_at IS NULL`
- **Closed shift:** `kiosk_shifts.clock_out_at IS NOT NULL`

### Approval rule (payroll only uses approved shifts)
Payroll runs include only shifts that are:
- **closed**, and
- **approved** (`approved_at IS NOT NULL`).

### Rounding (applied only at payroll/export)
- Rounding is configurable (e.g., 5/10/15 minutes).
- Rounding is applied at **payroll/export time**, never at punch time.

## 2) Break policy (locked)

### Breaks are per-shift and tier-based
- Breaks are calculated **per shift**, not per day.
- Tiers are stored in `kiosk_break_tiers` as:
  - `min_worked_minutes` → `break_minutes`
- Rule: pick the tier with the **highest** `min_worked_minutes` where `min_worked_minutes <= worked_minutes`.
- If no tier matches, break is **0**.

### Breaks are unpaid by default; contract can make them paid
- Employee contract field: `kiosk_employee_pay_profiles.break_is_paid` (0/1).
- Per shift:
  - `worked_minutes = clock_out_at - clock_in_at`
  - `break_minutes = tier(worked_minutes)`
  - If unpaid breaks: `paid_minutes = max(0, worked_minutes - break_minutes)`
  - If paid breaks: `paid_minutes = worked_minutes`

All internal calculations use **minutes**; UI may display **HH:MM**.

## 3) Uplifts (weekend / bank holiday / overtime) (locked)

### Contract-first
All uplift rules come from **employee contracts** (`kiosk_employee_pay_profiles.rules_json`).
There are **no care-home/global uplift rates**.

### Weekend
- Weekend = Saturday + Sunday (based on payroll timezone date).

### Bank holiday
- Bank holiday is based on **local calendar date**.
- If a shift crosses midnight, bank holiday minutes apply **only on the BH date** (does not carry past midnight).

### No stacking (exclusive, multiplier-first)
For any minute, apply **only one** uplift:
- If multiple multipliers qualify: choose the **highest multiplier**.
- Otherwise choose the **highest premium**.

## 4) Overtime + monthly payroll runs (locked)

### Weekly overtime
- Overtime is calculated **weekly** (using `payroll_week_starts_on`).
- Threshold comes from employee contract: `contract_hours_per_week`.
  - If 0 → overtime disabled.
- Overtime uses **weekly paid_minutes** (paid breaks count).

### Monthly payroll run with month-end deferral
- Payroll is run **monthly**.
- If the month ends mid-week, overtime for that incomplete week is **not finalized** in that month.
- It is finalized in the **next month payroll** when that week completes.

## 5) Current implementation status

### Implemented
- Punch audit trail: `kiosk_punch_events`
- Derived shifts: `kiosk_shifts`
- Auto-close stale shifts at `clock_in_at + max_shift_minutes` with `is_autoclosed=1`, `close_reason='autoclose_max'`
- Break tiers (`kiosk_break_tiers`) + per-shift paid_minutes
- Bank holiday list: `payroll_bank_holidays`
- Payroll batching:
  - `payroll_batches`
  - `payroll_shift_snapshots`
- `admin/payroll-runner.php` creates per-shift snapshots and calculates raw minute buckets split by local midnight:
  - `normal_minutes`, `weekend_minutes`, `bank_holiday_minutes`

- Weekly overtime allocation (no stacking):
  - OT is computed weekly from total **paid minutes** in the payroll week (payroll timezone).
  - OT minutes are allocated from the **end of the week backwards**.
  - OT minutes override other buckets (weekend/BH) so each paid minute belongs to exactly one bucket.
  - If a payroll month ends mid-week, OT for that incomplete week is **deferred** to the next month's payroll.

- Payroll month boundary mode (`payroll_month_boundary_mode`):
  - `midnight` (default): split at local midnight and allocate minutes to the month worked.
  - `end_of_shift`: assign whole shift to the month of its start date.

- Payroll UI audit view (Option C): employee → week → day breakdown
  - `admin/payroll-view.php` includes an Employee breakdown section that groups by payroll week and shows daily totals.
  - Backed by `payroll_shift_snapshots.day_breakdown_json` (stored during payroll run).

### Not implemented yet (next steps)
1) Contract rule "winner selection" (exclusive uplift, multiplier-first) across BH/weekend/OT *rates* (we already ensure minutes do not stack)
2) Rounding stored into snapshots/export (store raw + rounded)

## 6) Settings cleanup (Jan 2026 change)

Legacy global/care-home payroll uplift settings were removed from the UI and should not exist in DB.

`setup.php` actively deletes any legacy keys to prevent drift/confusion.
If you are upgrading an existing database, run the cleanup migration:
- `migrations/2026-01-legacy-payroll-settings-cleanup.sql`

## 7) Developer reminders
- Always determine closed shifts by `clock_out_at IS NOT NULL`.
- Always compute day/week cutoffs in `payroll_timezone` then convert to UTC.
- Keep README updated when rules/implementation changes.

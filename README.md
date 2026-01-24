# SmartCare Kiosk — Payroll Rules & Engineering Notes (Jan 2026)

This document is the **source of truth** for payroll rules and key system decisions agreed in Jan 2026.

## 1) Non-negotiable system rules

### UTC storage + payroll timezone boundaries
- Database timestamps are stored in **UTC**.
- Payroll boundaries (day/week/month, bank holiday midnight cutoff) are computed in **payroll timezone** (`kiosk_settings.payroll_timezone`), then converted to UTC for querying.

### Week start setting is global
- Week boundaries for overtime, rota/week views, and payroll are controlled by: `kiosk_settings.payroll_week_starts_on`.
- Value stored uppercase (e.g., `MONDAY`).
- This is set at setup and then **locked** (read-only after `app_initialized=1`).

### Shift open/closed definition (LOCKED)
Do **not** rely on `is_closed` alone.
- **Open shift:** `clock_out_at IS NULL`
- **Closed shift:** `clock_out_at IS NOT NULL`

`is_closed`, `is_autoclosed`, and `close_reason` are informational/audit fields only.

### Auto-close stale shifts (LOCKED)
- If an employee forgets to clock out, the system auto-closes stale open shifts.
- Trigger: during punch flow.
- Close time must be: `clock_in_at + max_shift_minutes` (NOT “now”).
- Mark: `is_autoclosed=1`, `close_reason='autoclose_max'`.

## 2) Break rules (LOCKED)

### Breaks are per-shift, tiered by worked minutes
- Break rules are configured as tiers such as:
  - 0–3 hours → 0 minutes
  - up to 6 hours → 30 minutes
  - up to 10 hours → 45 minutes
  - etc.

### Breaks are unpaid by default
- Each employee contract controls only one flag:
  - `break_is_paid` (0/1)

### Minutes-first calculations
- Internal calculation/storage uses **minutes**.
- UI can show hours:minutes.

### Implementation notes
- Tiers are stored in `kiosk_break_tiers` as **min_worked_minutes → break_minutes**.
- Admin UI: `/admin/break-tiers.php`
- On shift close (punch OUT or auto-close), we store:
  - `kiosk_shifts.duration_minutes`
  - `kiosk_shifts.break_minutes`
  - `kiosk_shifts.paid_minutes` (duration minus break unless contract says paid breaks)

## 3) Premium / multiplier rules (LOCKED)

### Contract-first rates
- Weekend, bank holiday, and overtime rules come from **individual employee contracts** (not care-home defaults).

### Weekend
- Weekend is **Saturday + Sunday**.

### Bank holiday
- Bank holiday is based on **local calendar date** only.
- Bank holiday uplift **does not carry past midnight**.

### No stacking
For any minute, apply **only one uplift**:
- If multiple **multipliers** apply: choose the **highest multiplier**.
- Otherwise choose the **highest premium**.

### Overtime
- Overtime is calculated **weekly** using `payroll_week_starts_on`.
- Overtime uses **paid minutes** (includes paid breaks).
- Overtime threshold:
  - If contract hours per week is `0`, employee has **no overtime**.
  - If contract hours is `30`, overtime applies above **30 hours/week**.

## 4) Monthly payroll run with weekly overtime (LOCKED)
- Payroll is run **monthly**.
- Overtime is calculated **weekly**.
- If a month ends mid-week, overtime for that incomplete week is **not finalized** in that month; it is calculated and included in the **next month** payroll when the week completes.

## 5) Rounding (LOCKED)
- Rounding increment (e.g., 5 or 10 minutes) is configurable in settings.
- Rounding is applied at **payroll/export** (not at punch time).

## 6) Payroll reruns + audit (LOCKED)
- Payroll can be run/rerun by: **payroll, admin, superadmin**.
- Payroll runs must be auditable:
  - Keep a log of reruns (who/when/why)
  - Preserve previous run outputs (do not silently overwrite history)

Recommended persistence:
- `payroll_batches` (one row per run)
- `payroll_shift_snapshots` (frozen per-shift results per batch)
- `payroll_run_logs` (audit trail of run/rerun/finalize)

## 7) Setup / install safety
- `setup.php?action=install` should be safe to re-run for repair.
- It must **not overwrite existing setting values** in `kiosk_settings`.
- It may update setting metadata (labels/descriptions/types/sort).


### Week start is global and locked
- Week start day is stored in `kiosk_settings.payroll_week_starts_on` (uppercase values like `MONDAY`, `SUNDAY`).
- The chosen week start applies everywhere (payroll, overtime, week views) and must be set once at initial setup and **not changed later**.

### Shift open/closed truth
- **Open shift**: `kiosk_shifts.clock_out_at IS NULL`
- **Closed shift**: `kiosk_shifts.clock_out_at IS NOT NULL`
- Fields like `is_closed`, `is_autoclosed`, `close_reason` are **informational only** and must not be the sole determinant of open/closed state.

### Auto-close stale open shifts
- Setting: `kiosk_settings.max_shift_minutes` (default 960 in setup; can be changed).
- If a shift is still open beyond max minutes, the system auto-closes it at:
  - `clock_out_at = clock_in_at + max_shift_minutes`
  - `is_autoclosed = 1`, `close_reason = 'autoclose_max'`

## 2) Break policy (shift-wise)

### Break tiers (configurable)
Break is calculated **per shift** using tier rules:
- Example tiers:
  - 0 to 3 hours -> 0 minutes break
  - up to 6 hours -> 30 minutes break
  - up to 10 hours -> 45 minutes break
- Break minutes are deducted from worked minutes to produce **paid minutes**.

### Paid breaks are per employee contract
Each employee contract only decides whether breaks are paid:
- If `break_is_paid = 1`: break minutes are **added back** (i.e. paid minutes include break).
- If `break_is_paid = 0`: break minutes are **unpaid** and reduce paid minutes.

### Minutes-first calculations
- All payroll calculations are performed in **minutes**.
- UI may display hours:minutes; conversion is at the UI layer.

## 3) Enhancements (contract-first)

Enhancement rates come from **individual contracts** (not care-home defaults):
- Weekend (Sat/Sun): usually premium (multiplier optional)
- Bank holiday: usually multiplier (premium optional)
- Overtime: usually multiplier (premium optional)

## 4) Non-stacking rule
For any minute, apply **at most ONE uplift**:
- **Multiplier-first**: choose the highest multiplier that applies.
- If no multiplier applies, choose the highest premium that applies.
- Never stack multiple multipliers or multiple premiums.

## 5) Bank holiday boundary
- Bank holiday applies by **local calendar date only**.
- It **does not carry past midnight** into the next day.

## 6) Overtime (weekly) + monthly payroll runs

### Weekly overtime threshold
- Weekly overtime is based on the employee contract:
  - `contract_hours_per_week` (aka contracted hours)
- If contracted hours is **0** => **no overtime** for that employee.
- If contracted hours > 0 => overtime applies above that threshold.

### Overtime uses paid minutes
- Weekly overtime is calculated using **weekly paid minutes** (paid breaks count toward overtime).

### Monthly payroll run, weekly overtime finalization
- Payroll is run **monthly**, but overtime is calculated **weekly**.
- If a month ends mid-week, overtime for that incomplete week is **not finalized** in that month.
- That week's overtime is calculated and included in the **next month's** payroll when the week completes.

## 7) Rounding
- Rounding increment (e.g., 5 or 10 minutes) is configurable in settings.
- Rounding is applied at **payroll/export stage** (not to raw stored punches or raw shift times).

## 8) Payroll locking + reruns (audit-first)

### Batches and snapshots
- Each payroll run creates a **batch** (`payroll_batches`).
- The system stores **snapshots** of calculated results (`payroll_shift_snapshots`) as the source of truth for that run.
- Payroll can be **re-run** by roles: payroll, admin, superadmin.
- Reruns must not delete history; keep old batch and create a new batch + new snapshots.

### Logs
- Actions like run/rerun/finalize should be recorded to `payroll_run_logs`.

---

## Developer notes
- Do not rely on `is_closed` for any open/closed filtering; always prefer `clock_out_at`.
- Keep all boundary calculations consistent with payroll timezone.

## 2) Break rules (shift-based tiers)
- Breaks are calculated **per shift**.
- Break rules are configured as **tiers by worked duration** (not by time-of-day):
  - Example: 0–3h => 0m break; up to 6h => 30m; up to 10h => 45m; etc.
- Breaks are **unpaid by default**.
- The **employee contract** contains a single flag:
  - `break_is_paid` (paid breaks on/off)
- Calculation:
  - `worked_minutes = clock_out_at - clock_in_at` (minus training handling if used)
  - `break_minutes = tier(worked_minutes)`
  - If `break_is_paid = 1`: `paid_minutes = worked_minutes`
  - If `break_is_paid = 0`: `paid_minutes = max(0, worked_minutes - break_minutes)`
- Internally calculate/store in **minutes**; UI displays **HH:MM**.

## 3) Weekend, bank holiday, overtime — contract-first
- All uplifts (weekend, bank holiday, overtime) come from **employee contract** rules (no care-home-level rates).
- Weekend is Saturday and Sunday.
- Bank holiday:
  - Applies by **local calendar date only**.
  - Does **not** carry past midnight into the next day.

## 4) Non-stacking uplift rule (exclusive)
For any minute, apply **only one** enhancement:
- If multiple multipliers apply: choose the **highest multiplier** (**multiplier-first**).
- Otherwise choose the **highest premium**.
- Never stack two multipliers or premium+multiplier.

## 5) Overtime (weekly) and monthly payroll
- Overtime is calculated **weekly** using the configured week start.
- Overtime threshold comes from employee contract:
  - `contract_hours_per_week`
  - If contract hours = **0** (or NULL treated as 0): **no overtime**.
  - If contract hours > 0: overtime applies to minutes **above contract hours**.
- Overtime uses **paid minutes** (meaning **paid breaks count** toward overtime).
- Monthly payroll run:
  - Payroll is run monthly.
  - If the month ends mid-week, overtime for that incomplete week is **not finalized** in that month.
  - That week's overtime is calculated and included in the **next month** once the week completes.

## 6) Rounding
- Rounding increment is configured in settings (e.g., 5 or 10 minutes).
- Rounding is applied at **payroll/export** stage (not to raw punches or raw shifts).

## 7) Auto-close stale open shifts
- `kiosk_settings.max_shift_minutes` defines max shift duration (default target ~14h).
- If a shift remains open past max duration, the system auto-closes it:
  - `clock_out_at = clock_in_at + max_shift_minutes` (not "now")
  - `is_autoclosed = 1`
  - `close_reason = 'autoclose_max'`

## 8) Approvals and payroll readiness
- Payroll is run only when shifts are **reviewed/approved** by managers.
- Closed shifts are detected by `clock_out_at IS NOT NULL`.

## 9) Payroll locking & reruns (audit-first)
- Payroll is run by: **payroll**, **admin**, or **superadmin**.
- Payroll can be **re-run**; history is preserved.
- Each run should produce frozen snapshots (source of truth) and an audit log.


## 3) Weekend, bank holiday, overtime (contract-first)
- Weekend, bank holiday, and overtime rates come from **individual employee contracts** (no care-home-level rates).
- Weekend = **Saturday + Sunday**.

### Bank holiday cutoff (midnight rule)
- Bank holiday applies by **local calendar date only**.
- It does **not carry past midnight** into the next day.

### Overtime is weekly; payroll is monthly
- Overtime is calculated **weekly** based on the global week start (`payroll_week_starts_on`).
- Monthly payroll runs monthly, but overtime for a week is finalized only when the week completes.
- If a month ends mid-week, overtime for that partial week is **not included** in that month’s payroll; it is included in the **next month** when the week completes.

### Overtime threshold
- Overtime threshold comes from the employee contract’s `contract_hours_per_week`.
- If `contract_hours_per_week = 0`, there is **no overtime** for that employee.
- Overtime is based on **weekly paid minutes** (paid breaks count toward overtime).

## 4) Non-stacking / priority
- No stacking: for any minute, apply **only one uplift**.
- Priority rule:
  1) If any multipliers apply, choose the **highest multiplier**.
  2) Otherwise choose the **highest premium**.

## 5) Rounding
- Internal storage and calculations use **minutes**.
- Rounding is configurable in settings (e.g., 5 or 10 minutes) and is applied at **payroll/export** time.

## 6) Shift autoclose
- `kiosk_settings.max_shift_minutes` controls stale shift autoclose.
- Autoclose is triggered during punch processing (no cron required):
  - Any open shift older than `max_shift_minutes` is closed.
  - The autoclose time is **clock_in_at + max_shift_minutes** (not "now").
  - Mark with:
    - `is_autoclosed=1`
    - `close_reason='autoclose_max'`

## 7) Manager approval gating payroll
- Shifts must be reviewed/approved by a manager before payroll runs.
- Payroll runs should include only shifts where:
  - `clock_out_at IS NOT NULL` and
  - `approved_at IS NOT NULL`

## 8) Payroll audit + reruns (planned)
- Payroll can be run by roles: **payroll, admin, superadmin**.
- Payroll may be re-run; prior runs must remain for audit.
- Each run creates a payroll batch and frozen snapshot rows:
  - `payroll_batches`
  - `payroll_shift_snapshots`
  - `payroll_run_logs`


#### Overtime threshold
- Each contract has **contracted hours per week** (`contract_hours_per_week`).
- If contracted hours is **0**, then **no overtime** applies for that employee.
- If contracted hours is >0 (e.g., 30h), overtime applies only to minutes above that threshold.
- Overtime is computed using **weekly paid minutes** (paid breaks count, unpaid breaks do not).

## 4) No stacking — multiplier-first
- For any minute, apply **only one uplift**.
- If multiple multipliers apply to the same minute, choose the **highest multiplier**.
- If no multipliers apply, choose the **highest premium**.
- This includes overlaps such as Bank Holiday vs Overtime: **highest multiplier wins**.

## 5) Rounding
- Rounding is configurable in settings (e.g., 5 or 10 minutes).
- All internal calculations use **exact minutes**.
- Rounding is applied at **payroll/export time** (not to raw punches/shifts).

## 6) Auto-close stale shifts
- If an employee forgets to clock out, the system auto-closes open shifts older than `kiosk_settings.max_shift_minutes`.
- Auto-close sets:
  - `clock_out_at = clock_in_at + max_shift_minutes`
  - `is_autoclosed = 1`
  - `close_reason = 'autoclose_max'`

## 7) Payroll approval gate
- Payroll runs only include shifts that are:
  - Closed (`clock_out_at IS NOT NULL`)
  - Approved/reviewed (`approved_at IS NOT NULL`)

## 8) Payroll runs, snapshots, and reruns
- Payroll is run monthly.
- Each run creates a **payroll batch** record.
- Results are frozen as **snapshots** (per shift) so future setting/contract edits do not change past results.
- Reruns create a new batch; old batches remain for audit.


## Settings cleanup (Jan 2026)

Legacy care-home payroll rule settings (night/weekend/BH/overtime defaults and the break fallback) are removed from the Settings UI and deleted from `kiosk_settings`.

Payroll rule rates now live only in employee contracts: `kiosk_employee_pay_profiles.rules_json`.

The only payroll-wide settings kept are:

- `payroll_week_starts_on` (setup-only lock)
- `payroll_timezone`
- rounding settings (`rounding_enabled`, `round_increment_minutes`, `round_grace_minutes`)

One-time SQL cleanup is provided in:

- `migrations/2026-01-legacy-payroll-settings-cleanup.sql`

# SmartCare Kiosk

**Status:** Stable core · Active development (automation & UX)

SmartCare Kiosk is an **offline‑first time & attendance system with a payroll‑hours engine** designed specifically for care homes. The system focuses on **accurate, auditable hours**, not pay amounts, so payroll teams can export clean data directly into external payroll software (e.g. Sage).

This README is **authoritative and up‑to‑date** as of **Jan 2026**.

---

## 1. Core Principles

- **Hours only** – no money calculations in the app
- **Auditability first** – every punch and shift change is traceable
- **Offline‑first kiosk** – works without internet, syncs later
- **Simple rules** – explicit behaviour, no hidden stacking
- **Incremental automation** – managers stay in control

---

## 2. High‑Level Architecture

### Main concepts

- **Punch** → raw event (clock‑in / clock‑out)
- **Shift** → derived working period built from punches
- **Payroll snapshot** → frozen result used for export

### Key tables

- `kiosk_punch_events` – immutable audit log
- `kiosk_shifts` – editable shifts
- `kiosk_shift_changes` – history of edits / approvals
- `kiosk_employee_pay_profiles` – contracts
- `kiosk_break_tiers` – care‑home break rules
- `payroll_batches`, `payroll_shift_snapshots` – payroll engine

---

## 3. Roles & Permissions

### Kiosk (Employees)
- Clock IN / OUT using PIN
- Photo captured on punch
- Works offline (IndexedDB)

### Manager
- Weekly shifts grid
- Fix shifts
- Approve shifts
- Cannot edit contracts

### Payroll
- Review approved hours
- Export monthly CSV

### Admin / Superadmin
- Full access
- System configuration

---

## 4. Timekeeping Model

### Shift truth rules

- **Open shift:** `clock_out_at IS NULL`
- **Closed shift:** `clock_out_at IS NOT NULL`
- `is_closed` is informational only

### Auto‑close

- If an employee forgets to clock out:
  - Shift auto‑closes at `clock_in + max_shift_minutes`
  - `is_autoclosed = 1`
  - Auto‑closed shifts are **not auto‑approved**

---

## 5. Offline Behaviour (Confirmed)

- Kiosk works fully offline once loaded
- Punches and photos queue locally
- Sync order: punches → photos

⚠️ Do **not** refresh kiosk page while offline (Android WebView limitation).

---

## 6. Break Rules (Care‑Home Level)

### Break tiers

Stored in `kiosk_break_tiers`:

- `min_worked_minutes`
- `break_minutes`

Rule:
> Pick the tier with the highest `min_worked_minutes ≤ worked_minutes`

### Paid vs unpaid

- Controlled per employee contract (`break_is_paid`)
- Breaks deducted first
- Paid breaks added back later

All calculations use **minutes internally**.

---

## 7. Departments (Final Model)

Employees belong to **Departments**.

Canonical department list:

- Management
- Care
- Nursing
- Kitchen
- Housekeeping
- Laundry
- Maintenance
- Activities
- Admin
- Agency

Field used:
- `kiosk_employees.department_id`

⚠️ Any legacy references to *category* are obsolete.

---

## 8. Employee Contracts

### Table
`kiosk_employee_pay_profiles`

Used fields:

- `employee_id`
- `contract_hours_per_week`
- `break_is_paid`
- `rules_json`

### Contract hours rule

- **0 or blank = 0‑hour contract**
- 0‑hour contracts are **not eligible for overtime**

### rules_json (locked format)

```json
{
  "bank_holiday_multiplier": 1.5,
  "bank_holiday_premium_per_hour": null,
  "weekend_multiplier": null,
  "weekend_premium_per_hour": null,
  "night_multiplier": null,
  "night_premium_per_hour": null,
  "overtime_multiplier": null,
  "overtime_premium_per_hour": null,
  "callout_multiplier": null,
  "callout_premium_per_hour": null
}
```

Rules:
- `null` = rule disabled
- Never store `0` in JSON
- Highest multiplier wins
- **No stacking**

---

## 9. Shifts UI (Source of Truth)

### File
`admin/shifts.php`

This weekly grid is **authoritative**.

Features:
- Employees × 7‑day week
- Approved + unapproved shifts
- **Only closed shifts shown**
- Bank holidays highlighted
- Fix button shown when:
  - auto‑closed
  - edited
  - close_reason present
- Department totals shown below

⚠️ Do not replace this page with compact or legacy versions.

---

## 10. Payroll Engine

### Design

- Snapshot‑based
- Results frozen at run time

### Behaviour

- Weekly overtime calculation
- Monthly payroll runs
- Partial weeks defer overtime to next month
- Multiplier‑first, no stacking

---

## 11. setup.php Behaviour

### What setup.php does

- Creates all required tables
- Seeds **default** data
- Locks configuration after install (`app_initialized`)

### Important notes

- Department seeding in setup.php is **DEV‑default only**
- Live installs may override departments manually
- Legacy tables (e.g. `kiosk_break_rules`) still exist but are **not used**

---

## 12. Known Issues

- Kiosk may show “rejected” when photo upload fails (UX only)
- Payroll week start bug (case mismatch, some hardcoded Monday logic)

---

## 13. Planned (Not Implemented Yet)

1. Auto‑approve clean shifts
2. Clock‑in cooldown after clock‑out (configurable, default 240 mins)

Settings already exist, logic pending.

---

## 14. MariaDB Compatibility

- Server uses **MariaDB 10.6**
- Use `VALUES(col)` in `ON DUPLICATE KEY UPDATE`
- Avoid MySQL‑only alias syntax

---

## 15. Current Status

- Core system stable
- Payroll logic correct and auditable
- Offline mode proven
- Remaining work is automation, not redesign

---

**This README is the definitive reference for SmartCare Kiosk.**


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

# SmartCare Kiosk

**Care-home focused kiosk & shift management system**  
**Baseline / Source of truth:** `smartcare-kiosk2.zip`  
**Last updated:** Jan 2026

---

## 1. Overview

SmartCare Kiosk is a **simple, auditable time-and-attendance system** designed specifically for care homes.

Core principles:
- Employees **clock in / clock out** using a kiosk device
- Managers **review and correct shifts**
- The system deals in **minutes and hours only**
- **No pay or money calculations** are performed in the app
- Auditability and clarity come before automation

---

## 2. Source of Truth

- `smartcare-kiosk2.zip` is the **official baseline**
- Any other zip files are **historical or experimental**
- Decisions in this README reflect the **real, working codebase**

---

## 3. Core Rules (Locked)

### 3.1 Hours Only
- The system works with **minutes and hours**
- No rates, pay amounts, or payroll calculations exist
- Any future payroll export is **hours-only**

### 3.2 Auditability First
- **Punch events are immutable**
  - Stored in `kiosk_punch_events`
  - Never edited, deleted, or rounded
- **Shifts may be edited**
  - Every edit/add/close is logged in `kiosk_shift_changes`

### 3.3 Shift Truth
- **Open shift:** `clock_out_at IS NULL`
- **Closed shift:** `clock_out_at IS NOT NULL`
- `is_closed` is informational only and must not be relied on

### 3.4 No Rule Stacking (Future Payroll Rule)
If payroll logic is reintroduced:
- Apply **only one uplift per minute**
- Highest **multiplier** wins
- If no multiplier applies, highest **premium** wins
- Never stack multiple uplifts

---

## 4. Terminology

### Departments (Canonical)
- **Department** is the correct and final term
- “Category” is obsolete and must not be used
- Employees belong to departments via:

# SmartCare Kiosk

Care-home focused kiosk, shift, and attendance management system  
**Baseline / Source of Truth:** `smartcare-kiosk2.zip`  
**Status:** Stable, operational, auditable  
**Last updated:** Jan 2026

---

## 1. Purpose & Design Philosophy

SmartCare Kiosk is designed to solve **one problem well**:

> Accurately record staff attendance in care homes in a way that is  
> **simple**, **auditable**, and **operationally safe**.

This system intentionally avoids:
- complex payroll calculations
- hidden automation
- irreversible actions
- silent data mutation

Every design decision prioritises:
1. **Auditability**
2. **Clarity**
3. **Operational safety**
4. **Care-home reality (not theoretical payroll systems)**

---

## 2. Source of Truth & Project Status

### 2.1 Source of Truth
- `smartcare-kiosk2.zip` is the **single authoritative baseline**
- Any other zip files are:
  - experimental
  - historical
  - reference only

No assumptions should be made based on other builds.

### 2.2 Stability
- The system is **stable and usable**
- Admin UI is intentionally minimal
- Several features are intentionally deferred (documented below)

---

## 3. Core System Principles (Locked Decisions)

These rules are **non-negotiable** unless explicitly revisited.

### 3.1 Hours Only (No Money)

- The system works only with:
  - minutes
  - hours
- No money, rates, payslips, or totals are calculated
- Any future payroll export must remain **hours-only**

This avoids:
- rounding disputes
- tax/regional complexity
- trust issues with staff

---

### 3.2 Auditability First

#### Punch Events (Immutable)
- Stored in `kiosk_punch_events`
- Punches are:
  - never edited
  - never deleted
  - never rounded
- Punches represent **what the device received**, not what payroll wants

#### Shifts (Editable, Audited)
- Shifts are derived from punches
- Shifts may be:
  - edited
  - added
  - closed
  - approved
- **Every change must be logged** in:

There must never be a situation where:
> “We don’t know who changed this shift or why”

---

### 3.3 Shift Truth (Critical)

The only reliable indicator of a closed shift is:


- Open shift: `clock_out_at IS NULL`
- Closed shift: `clock_out_at IS NOT NULL`
- `is_closed` is informational only and must not be relied upon

This rule must be followed **everywhere**.

---

### 3.4 No Pay Rule Stacking (Future Payroll Rule)

If payroll logic is ever reintroduced:

- Apply **only one uplift per minute**
- Priority order:
  1. Highest multiplier
  2. If none, highest premium
- Never stack multiple rules

This rule is agreed and locked even though payroll is currently removed.

---

## 4. Terminology & Data Model

### 4.1 Departments (Canonical Term)

- **Department** is the correct and final term
- “Category” is obsolete and must not be used
- Employees belong to departments via:


Any remaining references to “category” are legacy documentation only and should be removed over time.

---

## 5. Break Rules

### 5.1 Care-Home Level Break Configuration

Breaks are defined using:


Break selection logic:
- Choose the tier with the **highest `min_worked_minutes` ≤ worked minutes**

Examples:
- 0–4 hours → 0 minutes break
- 4–8 hours → 30 minutes break
- 8+ hours → 45 minutes break

### 5.2 Paid vs Unpaid Breaks

- Breaks are **unpaid by default**
- Whether a break is paid is decided per employee contract:


### 5.3 Legacy Cleanup

- The table **`kiosk_break_rules` is not used**
- It must be:
  - removed from the database
  - removed from `setup.php`
- All break logic relies only on `kiosk_break_tiers`

---

## 6. Employees Page (`admin/employees.php`)

### 6.1 Purpose
The Employees page is an **operational overview**, not a payroll configuration screen.

### 6.2 Visible Columns (Final)

- Name
- Emp ID
- Type
- Department
- Contract
- Break (Paid / Unpaid)
- Status
- Action

### 6.3 Multipliers (Intentional Decision)

- The **Multipliers column is hidden**
- This includes:
  - Bank holiday
  - Weekend
  - Night
  - Overtime
  - Callout

Important:
- This is **UI-only**
- Contract data remains unchanged
- Multipliers may still exist in contract configuration

Reason:
> To keep the Employees page simple, readable, and operational.

---

## 7. Punch Workflow (Critical for Debugging)

### 7.1 Two Independent Processes

#### 1) Punch Processing
- Endpoint: `api/kiosk/punch.php`
- Writes to `kiosk_punch_events`
- Records:
  - `result_status` (`received`, `processed`, `rejected`)
  - `error_code`
  - `shift_id` (when applicable)

Common rejection reasons:
- `too_soon`
- `already_clocked_in`
- `no_open_shift`
- `cooldown_active`
- `invalid_action`
- `server_error`

#### 2) Photo Upload
- Endpoint: `api/kiosk/photo_upload.php`
- Photo upload is **separate**
- Can fail independently
- Photo failure does **not** mean punch failure

---

### 7.2 Known Limitation (Current UX Issue)

- Admin UI may show:
- This can be misleading when:
- punch succeeded
- photo upload failed

### 7.3 Planned Improvement (Deferred)

Future update will:
- Split statuses clearly:
- Punch status + reason + shift_id
- Photo status + reason
- Log step-level errors
- Document error codes clearly

This will be documented in this README when implemented.

---

## 8. Punch Details Page (`admin/punch-details.php`)

- Filters are intentional and must remain
- Original query logic is correct
- Layout refactors previously caused regressions and were reverted

Future work:
- Improve layout without changing query logic
- Display split Punch vs Photo status

---

## 9. Shifts Weekly Grid (`admin/shifts.php`) — Source of Truth

### 9.1 Behaviour
- Weekly grid: employees × 7 days
- Only **closed shifts** shown
- Approved / unapproved visible
- Indicators show:
- autoclosed
- edited
- close_reason
- Department totals included

### 9.2 Critical Global Rule (Locked)

**Shifts are anchored to their START date (`clock_in_at`, local time)**

Examples:
- Monday → Tuesday shift counts on Monday
- Jan 31 → Feb 1 shift counts in January

This rule applies everywhere:
- shifts grid
- dashboard
- totals
- future exports

### 9.3 Hide Empty Employees

- A toggle exists to hide employees with no shifts
- Attempts to force default ON caused regressions
- Decision: **leave current behaviour unchanged**

---

## 10. Shift Editor

- Separate page from weekly grid
- Lists open and closed shifts
- Supports:
- add
- edit
- close
- approve
- Training minutes editable
- Training does **not** count toward worked or paid time
- All changes logged in `kiosk_shift_changes`

Planned:
- AJAX conversion for speed
- Possible role-based restrictions

---

## 11. Dashboard

Includes:
- Open shifts now
- Shifts needing approval
- Weekly department totals
- Device online/offline status (heartbeat-based)

---

## 12. Auto-Close Stale Shifts

- Setting: `max_shift_minutes` (default 960 = 16 hours)
- Triggered during punch processing (not cron)
- Auto-closes at:


Marks:
- `is_autoclosed = 1`
- `close_reason = 'autoclose_max'`

---

## 13. Rounding (Agreed, Deferred)

- Grace-minute rounding is agreed
- Punches remain raw
- Rounding applies only to shifts
- Planned setting:


---

## 14. Payroll Status

- Payroll UI is removed
- Payroll tables may still exist
- Decision pending:
  - remove payroll DB tables entirely
  - or keep dormant

---

## 15. Shift Deletion Policy

- Shifts must **not** be deleted
- Planned approach:
  - add `archived_at` to `kiosk_shifts`
  - exclude archived shifts by default
  - allow restore
  - log archive actions

---

## 16. Admin Improvements (Backlog)

- Departments should be editable / renameable
- Currently not possible via UI
- Safe to add later (no data impact)

---

## Final Note

This README documents:
- what is implemented
- what is intentionally deferred
- what is planned

Anything not listed here should be treated as **out of scope** unless explicitly agreed and documented.


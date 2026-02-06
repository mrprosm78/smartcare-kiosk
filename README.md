# SmartCare Kiosk – Complete Technical & Operational Guide

**Status:** Production‑ready core  
**Last updated:** Jan 2026

This document is the **single source of truth** for how the SmartCare Kiosk application works — both **frontend and backend** — including architecture, rules, workflows, permissions, data model, and operational behaviour.

If something in the code appears unclear, **this README takes priority**.

---

## 1. Purpose & Philosophy

SmartCare Kiosk is an **offline‑first time & attendance system** designed for care homes.

Key principles:

- The system tracks **time only** (minutes / hours)
- It **does not calculate pay amounts**
- Payroll teams export hours into external payroll software (e.g. Sage)
- Every action is **auditable**
- Simplicity > clever automation

---

## 2. Terminology (IMPORTANT)

- **Department** is the correct term (not category/categories)
- Employees belong to **Departments**
- Any legacy DB field or variable using `category` maps conceptually to **Department**

This terminology should be used consistently in:
- UI
- Code
- Documentation
- Reports

---

## 3. High‑Level Architecture

### Components

1. **Kiosk (tablet / browser)**
   - Used by employees
   - Handles PIN entry, punch actions, photos
   - Offline‑first with local queue

2. **Admin Web App**
   - Used by managers, payroll, directors, super admin
   - Review, correction, approval, export

3. **Backend (PHP + MySQL)**
   - Stores punches, shifts, audit logs
   - Applies rules for breaks, overtime, rounding
   - Never deletes punch history

---

## 4. Frontend – Kiosk

### 4.1 Punch Flow

1. Employee enters PIN
2. Chooses action:
   - Clock In
   - Clock Out
3. System records punch event immediately
4. Photo capture is triggered (if enabled)
5. Punch and photo are uploaded **independently**

### 4.2 Offline‑First Behaviour

- Punches are stored in IndexedDB
- If offline:
  - Punch is accepted locally
  - Marked as pending sync
- When online:
  - Punches sync automatically
  - Photos upload after punch confirmation

### 4.3 Important Design Rule

**Punch ≠ Photo**

- A punch can succeed even if:
  - Photo upload fails
  - Logging step fails
- UI must distinguish:
  - Punch status
  - Photo status

This avoids false “rejected” messages.

---

## 5. Backend – Punch & Shift Logic

### 5.1 Punch Events

Stored in `kiosk_punch_events`.

Each punch:
- Is immutable
- Has a unique `event_uuid`
- Stores:
  - action (in/out)
  - result_status (received / processed / rejected)
  - error_code (cooldown, already_clocked_in, etc.)

### 5.2 Shift Creation & Closure

- Clock‑in:
  - Creates a new shift if no open shift exists
- Clock‑out:
  - Closes the open shift
- A shift is **closed when `clock_out_at IS NOT NULL`**
- `is_closed` alone must never be trusted

### 5.3 Auto‑Close

- Shifts exceeding `max_shift_minutes` are auto‑closed
- Auto‑closed shifts:
  - Are flagged
  - Must be reviewed by a manager

---

## 6. Shifts – Core Rules

### 6.1 Shift Anchoring (CRITICAL)

All shifts are anchored to **clock_in_at date**.

Example:
- Clock in: Jan 31 22:00
- Clock out: Feb 1 06:00

Result:
- Entire shift counts toward **Jan 31**
- Applies everywhere:
  - Weekly grids
  - Monthly totals
  - Payroll exports

### 6.2 Time Storage

- All timestamps stored in **UTC**
- UI converts for display
- Payroll boundaries calculated using configured payroll timezone

---

## 7. Breaks

### 7.1 Break Model

Breaks use **tier‑based rules** (`kiosk_break_tiers`):

Worked minutes → Break minutes

Example:
- 0–240 → 0
- 241–480 → 30
- 481+ → 45

### 7.2 Break Behaviour

- Breaks are **unpaid by default**
- Employee contract can mark breaks as **paid**
- Breaks are calculated **per shift**
- Internally stored in minutes
- Displayed as HH:MM

---

## 8. Overtime & Premium Rules

### 8.1 Rule Source

- All pay‑related rules live in **employee contracts**
- Care‑home‑level rules only define what is available

### 8.2 No Stacking Rule (LOCKED)

For any given minute:
- Apply **only one uplift**
- Priority:
  1. Highest multiplier
  2. Otherwise highest premium

No stacking ever.

### 8.3 Overtime Timing

- Overtime is calculated **weekly**
- Payroll may be monthly, but overtime waits for week completion
- Partial weeks at month end roll into next month

---

## 9. Payroll (Hours‑Only)

### 9.1 Payroll Monthly Report

- Shows hours only
- No money
- Includes:
  - Actual time
  - Rounded time
  - Breaks
  - Overtime minutes
  - Flags

### 9.2 Rounding

- Configurable (e.g. 5 or 10 minutes)
- Applied:
  - At payroll/export time
- Not applied:
  - To raw punches
  - To audit history

---

## 10. Admin Frontend – Pages & Flow

### 10.1 Employees Page (UPDATED)

- Compact employee list (left)
- **Persistent side panel** (right)
- Clicking an employee:
  - Loads details into side panel
- Save:
  - AJAX
  - No page reload

#### Manager can:
- Add employee
- Edit employee profile
- Reset PIN
- Toggle Active / Inactive

#### Manager cannot:
- Delete employee
- Edit contract

#### Super Admin:
- Can edit contracts (via contract page)

---

### 10.2 Shifts Page

- Weekly grid (source of truth)
- Managers:
  - Edit shifts
  - Add missing shifts
  - Approve shifts
- Audit trail always recorded

---

### 10.3 Punch Details Page

- Immutable punch log
- Shows:
  - Punch status
  - Photo status
  - Error codes
- Used for diagnostics and audit

---

### 10.4 Dashboard

- Operational overview
- Focus on:
  - Open shifts
  - Exceptions
  - Missing photos

---

## 11. Roles & Permissions

### Manager
- Operational control
- Employee profiles (no contract)
- Shift approval

### Payroll
- View only
- Payroll exports

### Director
- Read‑only insight
- Trends and totals

### Super Admin
- Full system access

Permissions are enforced **server‑side**, never trusted to UI alone.

---

## 12. Data Model (Key Tables)

### Core
- `kiosk_employees`
- `kiosk_shifts`
- `kiosk_punch_events`
- `kiosk_punch_photos`
- `kiosk_shift_changes`
- `kiosk_break_tiers`

### Payroll
- `payroll_batches`
- `payroll_shift_snapshots`
- `payroll_bank_holidays`
- `payroll_run_logs`

---

## 13. Setup & Deployment

### Private Config

Each care home has its own config:

- `store_dev/config.php`
- Uploads, logs, exports stored outside public root

### Setup Script

- `setup.php?action=install` (**Install / Repair**)  
  Safe to run on live systems. It only creates missing tables/columns and applies small safe repairs.
  It does **not** wipe live data.
- `setup.php?action=reset&pin=XXXX` (**Hard Reset**)  
  **Destructive.** Drops and recreates tables. Use only on dev/test or during a controlled re‑install.

**Deprecated table cleanup (Jan 2026):** `kiosk_break_rules` is no longer used.  
`setup.php?action=install` will automatically drop it if it exists (safe because the app does not read it).

---

## 14. HR Careers Module

### Public Careers (no login)
- Entry point: `/careers/` (jobs list) and `/careers/apply.php`
- 8‑step wizard (steps 1–8) with `job=...` support
- Token‑based persistence: if no `token` is present, the system creates a draft `hr_applications` row and redirects with `token=...`
- Each step saves into `hr_applications.payload_json`
- Step 8 marks the application as **submitted** (`status='submitted'`, sets `submitted_at`)

### Admin HR Review (login required)
- Admin list + detail pages show the submitted answers (read‑only)
- Managers are allowed to update application status for now
- Later: add a permission table so superadmin can control who can do what

### Applicants vs Staff (LOCKED)
- Applicants (`hr_applications`) and Staff (`kiosk_employees`) remain separate lifecycles
- When hired, the manager will create a staff profile from the application (planned feature)
- Staff later gains contracts and rota/HR attributes without mixing into applicant records

---

## 15. Known Issues & Planned Improvements

- Normalize payroll week‑start casing everywhere
- Auto‑approve clean shifts
- Cooldown enforcement between shifts
- Delete kiosk photos after successful upload
- Improved punch/photo status UX

---

## 16. Final Notes

- Always preserve raw punch data
- Corrections must be auditable
- Keep UI simple and fast
- Prefer clarity over automation

**If behaviour is unclear, follow this README.**

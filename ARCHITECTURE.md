# SmartCare Kiosk – Architecture, Decisions & Roadmap

**Status:** Authoritative
**Last updated:** Feb 2026

This document captures *everything agreed and implemented so far*, plus the **locked architectural decisions**, **current state**, and **next steps**. It exists so nothing is lost and so future work can continue without re‑explaining context.

This file should be treated as the **primary architectural reference**. README.md should remain a high‑level overview; this document explains *how and why* the system is structured.

---

## 1. Core Goals (Care‑Home Focused)

SmartCare Kiosk is a **complete care‑home management system**, not just a clock‑in app.

Primary goals:
- Compliance‑ready (CQC / audits)
- Clear separation of concerns
- Secure by default
- Scalable without rewrites
- Laravel‑friendly long term

The kiosk is a **side application**. The **main system** is HR, Staff, Rota, Timesheets, Payroll, and Operations.

---

## 2. Locked Separation of Concepts (DO NOT MERGE)

These are intentional and must not be violated.

### 2.1 Applicants (HR Applications)
- Public users
- Come from Careers page
- Stored in `hr_applications`
- Immutable audit records
- Never deleted or merged

### 2.2 Staff (HR Profiles)
- Authoritative employee record
- Stored in `hr_staff`
- Contains *all* HR data
- One staff profile = one real person

### 2.3 Kiosk IDs (Clocking Credentials)
- Stored in `kiosk_employees`
- PIN + active flag only
- No HR data
- Optional (staff may exist without kiosk access)

### 2.4 Users (System Logins)
- Stored in `users`
- Authentication + permissions only
- Not all staff are users
- Not all users are staff

**Portal rule (LOCKED):**
- Admin operator roles (**superadmin**, **manager**, **payroll**) use **`/dashboard`** only.
- Staff user accounts use a separate portal: **`/staff-portal`**.
- Staff must never be "squeezed" into the admin dashboard via UI hiding; the separation is intentional to reduce permission complexity.

Routing behaviour (authoritative):

```
IF user.role ∈ {superadmin, manager, payroll}
  → /dashboard
ELSE IF user.role == staff
  → /staff-portal
ELSE
  → access denied
```

### Dashboard sidebar organisation (current)

The `/dashboard` sidebar is organised as **modules first** (clean + scalable). Current order:

1. **Dashboard**
2. **HR** → Applicants, Staff
3. **Rota** (placeholder)
4. **Timesheets** → Approvals
5. **Payroll** → Shift Grid, Payroll Monthly Report
6. **Kiosk** → Kiosk IDs, Punch Details
7. Other operational links (temporary) and **Settings** at the bottom

---

## 3. HR / Careers Flow (Implemented)

### 3.1 Careers Application
- Public, no login
- Generic application (not job‑specific)
- Optional dropdown: position applied for
- Token‑based draft system
- Safe refresh / resume

### 3.2 HR Applications Admin
- Managers/Admins can view all applications
- Status lifecycle: draft → submitted → reviewing → hired → rejected → archived
- Completeness review before hire

### 3.3 Convert Applicant → Staff (LOCKED FLOW)
When application is marked **Hired**:
1. Create `hr_staff`
2. Link application → staff
3. Optionally create kiosk ID
4. Lock application permanently

---

## 4. Staff Architecture (Implemented)

### 4.1 Staff Profiles (`hr_staff`)

The **single source of truth** for employees.

Contains:
- Personal details
- Contact & address
- Emergency contact
- Work experience
- Education
- References
- Compliance info (RTW, DBS, training)
- Staff status: active / inactive / archived
- Staff photo path
- `profile_json` for extensibility

All staff details are visible in **one place**.

---

### 4.2 Staff Contracts (`staff_contracts`)

- Contracts belong to staff, not kiosk IDs
- Supports effective date history
- Used by timesheets and payroll later

---

### 4.3 Staff Documents (`staff_documents`)

Implemented and working.

Used for:
- Photo ID / RTW
- DBS
- CV
- Training certificates
- References

Rules:
- Stored in **private `store_*` path** (outside web root)
- Permission‑checked downloads
- Audit‑safe

Staff photo is stored as a **path** in private storage (same model as punch photos).

---

## 5. Kiosk System (Implemented)

### 5.1 Purpose
- Clock in / clock out only
- Minimal attack surface
- High reliability

### 5.2 Kiosk Architecture
- Public UI under `/kiosk`
- Uses API endpoints only
- Punch + photo are separate operations
- Photo failures do not invalidate punches

### 5.3 Kiosk Configuration (Important)
- JS does **not** hardcode API paths
- Runtime config injected via PHP:

```js
window.SMARTCARE = {
  basePath,
  apiBase
}
```

This allows:
- Moving kiosk under `/kiosk`
- Changing domains without editing JS
- Laravel‑style config injection later

---

## 6. Folder & URL Structure (In Progress – Design Locked)

### 6.1 Public URLs

- `/name_kiosk/` → Main care‑home system
- `/name_kiosk/kiosk/` → Kiosk app
- `/name_kiosk/dashboard/` → Admin / backoffice
- `/name_kiosk/staff-portal/` → Staff self‑service portal (separate from admin)
- `/name_kiosk/admin/` → Legacy stub (redirects → `/dashboard`)

### 6.2 Dashboard navigation (current)

The admin dashboard sidebar is intentionally structured to match delivery phases:

1. **HR**
   - Applicants
   - Staff
2. **Rota** (placeholder)
3. **Timesheets** (placeholder)
4. **Payroll**
5. Existing operational links below (kept minimal; reorganised later)

### 6.3 Config‑Driven Paths

Defined in private config:

```php
define('APP_BASE_PATH', '/name_kiosk');
define('APP_ADMIN_PATH', '/dashboard');
define('APP_KIOSK_PATH', '/kiosk');
```

All URLs must be generated from these constants.

---

## 7. Private Storage & Security (Implemented)

- `store_*` folder lives **outside public web root**
- Loaded via `db.php` using `dirname(__DIR__,2)`
- Used for:
  - Punch photos
  - Staff photos
  - Staff documents
  - Payroll exports
  - Logs

This is locked and correct.

---

## 8. Future Modules (Design Locked)

### 8.1 Rota
- Planned shifts only
- Never edits kiosk shifts

### 8.2 Timesheets
- Approved actuals
- Weekly approval & locking

### 8.3 Payroll
- Minutes‑based
- Weekly overtime
- No stacking
- Export only
- Consumes approved timesheets

### 8.4 Tickets / Messaging
- Staff communication
- Linked to staff_id
- Attachments stored privately

### 8.5 Notifications
- Email / SMS
- Centralised service

### 8.6 Reports & PDFs
- Staff audit PDF
- Selectable sections
- Generated on demand
- Uses Dompdf

---

## 9. What Was Just Completed

- Staff documents upload
- Staff photo handling
- Full staff profile visibility
- Kiosk JS config injection
- Private storage usage verified
- Folder/URL restructuring design agreed

---

## 10. Next Steps (After Document Upload)

**In recommended order:**

1. Finish `/dashboard` → `/dashboard` rename (config + redirects)
2. Centralise URL helpers (`admin_url`, `kiosk_url`)
3. Staff audit PDF generation
4. Document expiry tracking (DBS, RTW)
5. Staff onboarding (one‑time code)
6. Rota module
7. Timesheets
8. Payroll export
9. Ticketing system

---

## 11. Laravel Alignment (Long‑Term)

Current structure already mirrors Laravel concepts:
- Domain separation
- Thin controllers (pages)
- Shared core services

Future migration path:
- Move logic into services
- Replace pages with controllers
- Keep URLs unchanged

No rewrite required if boundaries are respected.

---
11A. Database & Install Strategy (Deferred but Locked)

The database structure and install/update mechanism will be formally refactored later, after the three core modules are fully complete and stable:

HR / Applications

Staff (profiles, documents, compliance)

Kiosk (clock-in / clock-out)

This sequencing is intentional and correct.

Locked rules

We will not redesign database migrations while schemas are still evolving

setup.php remains intentionally conservative and non-destructive for now

No destructive resets on live systems

Early schema churn is acceptable until core modules are finalized

Planned future change

Once the three core modules are finalized, the project will introduce:

A dedicated /install folder

Incremental migrations (Laravel-style but framework-agnostic)

Seeders for default, non-destructive data

A migration tracking table (e.g. app_migrations)

This approach allows:

Safe upgrades on live care-home systems

Predictable installs on new environments

Clean future Laravel migration without rewrite

Until this point, migration work is intentionally postponed.

This decision is deliberate and locked.

## 12. Documentation Strategy (Important)

- **README.md** → High‑level overview for new developers
- **This file** → Architecture + decisions + roadmap

Do **not** put everything in README.md.

This document prevents knowledge loss and re‑explanation.

---

## 13. Final Rule

If a future change violates this document, it is **almost certainly wrong**.

Respect boundaries. Build forward. Keep it boring and reliable.


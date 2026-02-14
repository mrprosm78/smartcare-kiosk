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

**Locked linking model:**
- `kiosk_employees.hr_staff_id` is the *only* link between kiosk identities and HR staff.
- We do **not** store the reverse link on `hr_staff` (no duplication).

### 2.4 Admin Users (Dashboard Logins)
- Stored in `admin_users`
- Session tracking stored in `admin_sessions`
- Authentication + permissions only
- Not all staff are admin users

> Note: A future **staff portal** may introduce its own user model, but dashboard auth is intentionally isolated.

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

### Dashboard UI reference (LOCKED)

The dashboard UI must follow the styling and layout patterns from `smartcare-ui.zip`.

**Design tokens (reference):**
- `sc-primary`: **#2563EB**
- `sc-accent`: **#7C3AED**
- `sc-bg`: **#F3F4F6**
- `sc-panel`: **#FFFFFF**
- `sc-border`: **#E5E7EB**
- `sc-sidebar`: **#0F172A**
- `muted`: **#6B7280**

**Layout contract:** fixed sidebar + top header; sidebar nav area and main content area must be scrollable (viewport-height layout).

**Sidebar UX contract:**
- Two-level navigation with **+ / −** expanders
- Submenus open/close only when the user clicks **+ / −** (no auto open/close)
- Clear active state on the current page (highlight + pointer/arrow indicator)

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
2. Link application → staff via `hr_applications.hr_staff_id`
3. Optionally create/link a kiosk ID via `kiosk_employees.hr_staff_id`
4. Lock application permanently


#### Applicant immutability & status updates (LOCKED)

- `hr_applications` is the **immutable submission record** (what the person submitted).
- Dashboard users may update **status** while reviewing (draft → submitted → reviewing → ...).
- Once an application is **converted to staff** (`hr_applications.hr_staff_id` set), the application is considered **locked**:
  - The submitted payload must not be edited.
  - Status changes should be avoided (or disabled in UI) to preserve audit integrity.
  - The correct place for ongoing updates is `hr_staff` and HR-owned modules.

#### HR Staff editing (current policy)

For now (pre‑rota), staff identity fields (name/email/phone copied from the application) are treated as **read‑only**.
HR operators extend staff records through modules (documents, contracts, training, etc.) rather than editing the original applicant submission.

#### Kiosk ↔ Staff linking UI (LOCKED)

There must be **only one authoritative UI** for linking kiosk identities to staff:

- Linking is written only on `kiosk_employees.hr_staff_id`
- The only page that may write this link is: **`/dashboard/kiosk-ids.php`**
- HR staff pages are read‑only for this relationship and may only provide a **“Manage kiosk identity”** navigation button.


---

## 4. Staff Architecture (Implemented)

### 4.1 Staff Profiles (`hr_staff`)


### Staff code / reference (implemented, locked intent)

For audits and exports, staff has a stable **staff code** generated at conversion time.

- Stored on `hr_staff.staff_code` (unique, read‑only)
- **Numeric only** (no prefix)
- Starts from `1` and increments with staff creation
- Current implementation sets `staff_code = SC0001` style (`SC` + LPAD(id,4,'0'))
- Displayed across dashboard and exports; it must not be editable


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

### 4.2 Staff Contracts (`hr_staff_payroll_contracts`)

- Contracts belong to staff, not kiosk IDs
- Supports effective date history
- Used by timesheets and payroll later

---

### 4.3 Staff Documents (`hr_staff_documents`)

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

The admin dashboard sidebar is organised **modules first** (locked order):

1. **Dashboard**
2. **HR** → Applicants, Staff
3. **Rota** (placeholder)
4. **Timesheets** → Approvals
5. **Payroll** → Shift Grid, Payroll Monthly Report
6. **Kiosk** → Kiosk IDs, Punch Details
7. Other operational links (temporary) and **Settings** at the bottom

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

---

## 9. UI Polish – Current State & Next UI Work

**Current:** the dashboard sidebar is in the correct module order and uses **+ / −** expand/collapse.

**Keep:** the current sidebar structure and behaviour (no auto-collapse). The remaining UI polish work is:
- Make the active page state more obvious: stronger highlight and a clear pointer/arrow on the active link.
- Keep the fixed-sidebar + fixed-header layout with scrollable nav and scrollable main content.
- Apply the same "SmartCare UI" visual language to the **main content area** (headers, tables, filters) so pages look consistent.

---

## 10. Phased Delivery Plan (Feb 2026)

### Phase 0 — Foundations (DONE)
- Private storage model (`store_*` outside public web root)
- Clear boundaries: HR Applications ≠ Staff ≠ Users ≠ Kiosk IDs
- HR apply wizard + applications admin flow
- Convert Applicant → Staff flow (with optional kiosk ID)
- Staff profiles + private documents + photo
- Kiosk punch system with split punch/photo behaviour

### Phase 1 — Structure & Paths (DONE)
- Kiosk under `/.../kiosk/`
- Admin under `/.../dashboard/`
- Legacy `/.../admin/` redirects to `/dashboard`
- JS runtime config via `window.SMARTCARE` (no hardcoded paths)
- CSS split: `kiosk.css` vs `app.css`

### Phase UI — UX Polish (IN PROGRESS)
- Improve dashboard login UI
- Sidebar grouping + consistent headers/spacing
- Careers pages + wizard UI consistency using `app.css`

### Phase 2 — HR & Staff Hardening (NEXT, after UI)
- Document metadata: expiry/issue dates, verified by/at, notes, badges
- Application completeness checklist + optional gating before "Convert to staff"
- Staff status rules (inactive can't clock in; archived read-only)

### Later (Planned)
- Phase 3 Staff onboarding portal (`/staff-portal`)
- Phase 4 Staff audit PDF packs
- Phase 5 Rota (planned ≠ actual)
- Phase 6 Timesheets (weekly approvals/locking)
- Phase 7 Payroll exports (approved timesheets only)
- Phase 8 Tickets/messaging + notifications
- Phase 9 DB review + installer/migrations (deferred until core modules stable)
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



---

## HR / Kiosk Linking & Naming Rules (LOCKED – Migration‑Safe)

This section records **critical architectural decisions** that must not be accidentally reversed during ongoing development.

### HR tables naming (MANDATORY)
All HR‑owned tables **must use the `hr_` prefix** to ensure a clean future migration to Laravel.

Authoritative HR tables:
- `hr_applications`
- `hr_staff`
- `hr_staff_payroll_contracts`
- `hr_staff_documents`

No new HR tables should be created without this prefix.

---

### Kiosk vs HR responsibility (STRICT SEPARATION)
- `kiosk_employees` exists **only** for kiosk identity and punch operations.
- HR data **must not** be merged into `kiosk_employees`.

A **single linking column** connects kiosk identities to HR staff:
- `kiosk_employees.hr_staff_id` (nullable, indexed)

This keeps kiosk punching fast and stable while HR evolves independently.

---

### Contracts belong to HR staff (NOT kiosk IDs)
Employment contracts are HR‑owned data.

Canonical model:
- Contracts live in `hr_staff_payroll_contracts`
- Each contract supports effective dates (`effective_from`, `effective_to`)

Payroll and timesheets resolve contracts via:
1. `kiosk_shifts.employee_id`
2. `kiosk_employees.hr_staff_id`
3. Active `hr_staff_payroll_contracts` row

Legacy kiosk‑linked contracts may exist temporarily but are transitional only.

---

### Legacy data & cleanup strategy
`kiosk_employees` currently contains columns that may become irrelevant later.

Rule:
- Do **not** delete legacy columns early.
- First migrate reads to HR tables.
- Remove legacy data only when unused.

This avoids breaking punching, reports, and history.

---

### Staff ↔ Kiosk mapping assumption
Default assumption:
- **1 HR staff ↔ 1 kiosk identity**

We may later enforce:
- `UNIQUE(kiosk_employees.hr_staff_id)`

If multi‑kiosk identities are ever required, this constraint will not be enforced.

---

### Canonical staff model (IMPORTANT)
The authoritative staff record is:
- `hr_staff`

We must **not** maintain multiple staff profile systems.
Any legacy tables (e.g. `hr_staff_profiles`) are transitional and must be deprecated and removed over time.

---

### Migration roadmap (LOCKED ORDER)
1. Lock `hr_staff` as the single staff source of truth
2. Add and backfill `kiosk_employees.hr_staff_id`
3. Introduce and migrate to `hr_staff_payroll_contracts`
4. Update payroll/timesheets to read HR contracts
5. Remove legacy kiosk‑based contract logic
6. Remove deprecated tables only when fully unused

---
### Kiosk PIN verification strategy (LOCKED)

The system intentionally keeps bcrypt for PIN security, but avoids brute-force row scanning.

Approach:
- Each kiosk employee stores:
  - `pin_hash` (bcrypt, authoritative)
  - `pin_fingerprint` (SHA-256, indexed)
- On punch:
  1. The entered PIN is SHA-256 hashed.
  2. The indexed fingerprint is used to locate the candidate row.
  3. bcrypt verification is run **once** on the matched row.

Benefits:
- Preserves bcrypt security guarantees.
- Avoids looping through all employees.
- Scales safely with large staff counts.
- Keeps kiosk punching fast on low-power devices.

The fingerprint is used for lookup only.
bcrypt remains the sole authority for PIN validation.


---

## 10. Legacy cleanup strategy (LOCKED)

We do not delete legacy tables/columns/pages early.

Approach:
- Migrate reads first
- Migrate writes second
- Only remove legacy when nothing references it and data is safe

**File naming convention:** any page renamed to `*-legacy.php` is considered removable once no links reference it.

## 11. Current Focus & Phased Plan (Feb 2026)

### Phase A — Applicants + Staff + Kiosk link (now)
Goal: make Applicants/Staff/Kiosk linkage rock‑solid before starting Rota.

- Applicants: immutable submissions; status workflow; convert‑once.
- Staff: read‑only identity + HR modules (documents, contracts, training later).
- Kiosk link: `kiosk_employees.hr_staff_id` is mandatory for future rota/payroll alignment.
- Punching performance: bcrypt + indexed SHA‑256 fingerprint lookup (`pin_fingerprint`).

### Phase B — Contracts & Training modules
- Implement `hr_staff_payroll_contracts` screens (effective dates/history).
- Implement training records (`hr_staff_training`) and reporting.

### Phase C — Rota module (next major)
- Planned shifts (rota) owned by HR/staff domain.
- Actual shifts remain kiosk operational.
- Rota requires kiosk ↔ staff linkage to be stable.

### Phase D — Timesheets & Payroll
- Weekly approvals, monthly payroll export, audit trails.
- Continue “reads first, writes second” legacy migration approach.


# SmartCare Kiosk

**Last updated:** Feb 2026

SmartCare Kiosk is a **care‑home management system**, not just a clock‑in app.

It is designed to support the full operational lifecycle of a care home, including:
- HR & recruitment
- Staff records & compliance
- Kiosk clock‑in / clock‑out
- Rota planning (future)
- Timesheets & approvals (future)
- Payroll exports (future)
- Staff communications & tickets (future)

The system is built to be:
- **audit‑ready** (CQC / inspections)
- **secure by default**
- **clearly separated by responsibility**
- **scalable without rewrites**
- **Laravel‑friendly long‑term**

---

## Architecture & Design (IMPORTANT)

This project follows a **strict architecture** designed specifically for care‑home compliance and long‑term maintainability.

👉 **Before making any changes, read `ARCHITECTURE.md`.**

`ARCHITECTURE.md` is the **authoritative reference** and covers:
- HR / Staff / Kiosk separation rules
- Data ownership and boundaries
- Security and private storage rules
- Folder & URL structure (`/kiosk`, `/dashboard`, etc.)
- Conversion flows (Applicant → Staff → Kiosk ID)
- Future roadmap (Rota, Timesheets, Payroll, Tickets)

Any change that violates `ARCHITECTURE.md` is **almost certainly wrong**.

---

## High‑Level System Areas

### Portals (Locked)

SmartCare is intentionally split into separate portals to keep permissions simple and reduce security risk:

- **Kiosk** (`/kiosk`) — shared device clock‑in/clock‑out only
- **Dashboard** (`/dashboard`) — operator roles only (**superadmin**, **manager**, **payroll**)
- **Staff Portal** (`/staff-portal`) — staff self‑service (separate UI; staff users never use `/dashboard`)

### Careers & HR Applications
- Public careers page
- Generic job application flow
- Admin review and hiring workflow

### Staff Management
- Authoritative staff profiles
- Compliance data (RTW, DBS, training)
- Staff documents and photo
- Active / inactive / archived status

### Kiosk (Clock‑in / Clock‑out)
- Separate public kiosk app
- Minimal attack surface
- PIN‑based clocking only

---

## Dashboard Navigation (Current)

The dashboard sidebar is organised as **modules first** (clean + scalable):

1. **Dashboard**
2. **HR** → Applicants, Staff
3. **Rota** (placeholder)
4. **Timesheets** → Approvals
5. **Payroll** → Shift Grid, Payroll Monthly Report
6. **Kiosk** → Kiosk IDs, Punch Details
7. Other operational links (temporary) and **Settings** at the bottom

### Future Modules
- Rota (planned shifts)
- Timesheets (approved actuals)
- Payroll export (minutes‑based)
- Staff tickets & messaging

---

## Technical Overview (High Level)

- PHP + MySQL
- No framework dependency (Laravel‑ready structure)
- Private `store_*` directory **outside public web root**
- Secure uploads for photos and documents
- Config‑driven paths and URLs

Detailed technical decisions live in `ARCHITECTURE.md`.

---

## Project Status

This project is **actively developed**.

Recent completed work includes:
- Full HR staff profiles
- Staff documents & photo handling
- Secure private storage model
- Kiosk path & configuration refactor

See `ARCHITECTURE.md` for:
- what is complete
- what is locked
- what comes next

---

## Final Note

This README is intentionally **high‑level**.

For real understanding of how the system works — and how it must evolve —
**`ARCHITECTURE.md` is required reading.**


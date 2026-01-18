INSERT INTO kiosk_shifts
(
  employee_id,
  clock_in_at,
  clock_out_at,
  training_minutes,
  training_note,
  is_callout,
  duration_minutes,
  is_closed,
  close_reason,
  is_autoclosed,
  approved_at,
  approved_by,
  approval_note,
  created_source,
  updated_source,
  created_at,
  updated_at
)
VALUES
/* =========================
   DEC 2025 (Last month)
   ========================= */

/* Day shifts 09:00–17:00 (8h = 480) */
(1,'2025-12-02 09:00:00','2025-12-02 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-02 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(1,'2025-12-03 09:00:00','2025-12-03 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-03 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(1,'2025-12-04 09:00:00','2025-12-04 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-04 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

(2,'2025-12-02 09:00:00','2025-12-02 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-02 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2025-12-03 09:00:00','2025-12-03 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-03 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2025-12-04 09:00:00','2025-12-04 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-04 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

(3,'2025-12-02 09:00:00','2025-12-02 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-02 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2025-12-03 09:00:00','2025-12-03 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-03 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2025-12-04 09:00:00','2025-12-04 17:00:00',NULL,NULL,0,480,1,'seed',0,'2025-12-04 17:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

/* Night shift 20:00–06:00 next day (10h = 600) */
(1,'2025-12-06 20:00:00','2025-12-07 06:00:00',NULL,NULL,0,600,1,'seed',0,'2025-12-07 06:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2025-12-06 20:00:00','2025-12-07 06:00:00',NULL,NULL,0,600,1,'seed',0,'2025-12-07 06:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2025-12-06 20:00:00','2025-12-07 06:00:00',NULL,NULL,0,600,1,'seed',0,'2025-12-07 06:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

/* Weekend day 10:00–14:00 (4h = 240) */
(1,'2025-12-07 10:00:00','2025-12-07 14:00:00',NULL,NULL,0,240,1,'seed',0,'2025-12-07 14:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2025-12-07 10:00:00','2025-12-07 14:00:00',NULL,NULL,0,240,1,'seed',0,'2025-12-07 14:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2025-12-07 10:00:00','2025-12-07 14:00:00',NULL,NULL,0,240,1,'seed',0,'2025-12-07 14:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

/* =========================
   JAN 2026 (This month)
   ========================= */

/* OVERTIME WEEK: Mon–Fri 08:00–18:00 (10h/day = 600, total 50h) */
(1,'2026-01-05 08:00:00','2026-01-05 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-05 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(1,'2026-01-06 08:00:00','2026-01-06 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-06 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(1,'2026-01-07 08:00:00','2026-01-07 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-07 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(1,'2026-01-08 08:00:00','2026-01-08 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-08 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(1,'2026-01-09 08:00:00','2026-01-09 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-09 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

(2,'2026-01-05 08:00:00','2026-01-05 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-05 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2026-01-06 08:00:00','2026-01-06 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-06 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2026-01-07 08:00:00','2026-01-07 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-07 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2026-01-08 08:00:00','2026-01-08 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-08 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(2,'2026-01-09 08:00:00','2026-01-09 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-09 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

(3,'2026-01-05 08:00:00','2026-01-05 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-05 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2026-01-06 08:00:00','2026-01-06 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-06 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2026-01-07 08:00:00','2026-01-07 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-07 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2026-01-08 08:00:00','2026-01-08 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-08 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),
(3,'2026-01-09 08:00:00','2026-01-09 18:00:00',NULL,NULL,0,600,1,'seed',0,'2026-01-09 18:05:00','seed','seed approved','seed','seed',NOW(),NOW()),

/* Call-out test: 1 hour shift marked callout (22:00–23:00 = 60 mins)
   Payroll should uplift paid hours to your setting (default 4h) BEFORE OT calc */
(1,'2026-01-10 22:00:00','2026-01-10 23:00:00',NULL,NULL,1,60,1,'seed',0,'2026-01-10 23:05:00','seed','callout seed','seed','seed',NOW(),NOW()),
(2,'2026-01-10 22:00:00','2026-01-10 23:00:00',NULL,NULL,1,60,1,'seed',0,'2026-01-10 23:05:00','seed','callout seed','seed','seed',NOW(),NOW()),
(3,'2026-01-10 22:00:00','2026-01-10 23:00:00',NULL,NULL,1,60,1,'seed',0,'2026-01-10 23:05:00','seed','callout seed','seed','seed',NOW(),NOW()),

/* Long night: 19:00–07:00 next day (12h = 720) */
(1,'2026-01-15 19:00:00','2026-01-16 07:00:00',NULL,NULL,0,720,1,'seed',0,'2026-01-16 07:05:00','seed','night seed','seed','seed',NOW(),NOW()),
(2,'2026-01-15 19:00:00','2026-01-16 07:00:00',NULL,NULL,0,720,1,'seed',0,'2026-01-16 07:05:00','seed','night seed','seed','seed',NOW(),NOW()),
(3,'2026-01-15 19:00:00','2026-01-16 07:00:00',NULL,NULL,0,720,1,'seed',0,'2026-01-16 07:05:00','seed','night seed','seed','seed',NOW(),NOW()),

/* Training example: 09:00–17:00 with training_minutes filled */
(1,'2026-01-20 09:00:00','2026-01-20 17:00:00',60,'Manual Handling',0,480,1,'seed',0,'2026-01-20 17:05:00','seed','training seed','seed','seed',NOW(),NOW()),
(2,'2026-01-20 09:00:00','2026-01-20 17:00:00',60,'Manual Handling',0,480,1,'seed',0,'2026-01-20 17:05:00','seed','training seed','seed','seed',NOW(),NOW()),
(3,'2026-01-20 09:00:00','2026-01-20 17:00:00',60,'Manual Handling',0,480,1,'seed',0,'2026-01-20 17:05:00','seed','training seed','seed','seed',NOW(),NOW());

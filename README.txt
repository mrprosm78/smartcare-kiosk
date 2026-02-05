
PATCH ZIP CONTENTS
==================
This patch contains:
- admin/hr-application.php (HR application detail view)
- setup-hr.sql (SQL to create hr_applications table)

HOW TO APPLY
------------
1) Copy admin/hr-application.php into your SmartCare Kiosk admin/ directory
2) Add the SQL in setup-hr.sql to setup.php (create_tables + drop_all)
3) Ensure permissions 'view_hr_applications' exist and sidebar link is present

This patch complements the Careers wizard already added under /careers.

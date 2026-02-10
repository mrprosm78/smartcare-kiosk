<?php
// includes/helpers.php

// -------------------------------------------------
// Base-path / subfolder support (local + production)
// - Works when installed in a subfolder like /smartcare-kiosk/
// - Also works at domain root (app_base becomes '')
// -------------------------------------------------
$__sc_script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$__sc_dir    = rtrim(str_replace('\\', '/', dirname($__sc_script)), '/');

// If we're inside /careers, app base is the parent directory.
$__sc_app_base = $__sc_dir;
if (preg_match('~/careers$~', $__sc_dir)) {
    $__sc_app_base = rtrim(substr($__sc_dir, 0, -strlen('/careers')), '/');
}

if ($__sc_app_base === '/') $__sc_app_base = '';
$sc_app_base = $__sc_app_base;

if (!function_exists('sc_app_base')) {
    function sc_app_base(): string {
        global $sc_app_base;
        return (string)$sc_app_base;
    }
}

if (!function_exists('sc_app_url')) {
    function sc_app_url(string $path = ''): string {
        $base = sc_app_base();
        $path = ltrim($path, '/');
        return $base . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('sc_asset_url')) {
    function sc_asset_url(string $path = ''): string {
        $path = ltrim($path, '/');
        return sc_app_url('assets' . ($path !== '' ? '/' . $path : ''));
    }
}

if (!function_exists('sc_careers_url')) {
    function sc_careers_url(string $path = ''): string {
        $path = ltrim($path, '/');
        return sc_app_url('careers' . ($path !== '' ? '/' . $path : ''));
    }
}
/**
 * Build internal app URLs in a clean, Laravel-ready way
 * Usage:
 *  sc_url('tasks')
 *  sc_url('hr-staff-profile', ['tab' => 'compliance'])
 */
if (!function_exists('sc_url')) {
    function sc_url(string $page, array $query = []): string
    {
        $params = array_merge(['page' => $page], $query);
        return 'index.php?' . http_build_query($params);
    }
}
// ------------------------------
// Safe redirect (Laravel-friendly pattern)
// - Supports PRG pattern
// - Prevents header injection
// - Works even if headers already sent (fallback JS)
// ------------------------------
if (!function_exists('sc_safe_redirect')) {
    function sc_safe_redirect(string $to, int $status = 302): void {
        // Basic hardening: strip CR/LF to prevent header injection
        $to = str_replace(["\r", "\n"], '', $to);

        // If headers already sent, fallback safely
        if (headers_sent()) {
            $safe = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
            echo '<script>window.location.href="' . $safe . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe . '"></noscript>';
            exit;
        }

        header('Location: ' . $to, true, $status);
        exit;
    }
}

// ------------------------------
// Page meta (module + title)
// Single source of truth for header + consistency with sidebar
// ------------------------------
if (!function_exists('sc_page_meta')) {
    function sc_page_meta(): array {
        return [
            // Dashboards
            'dashboard-manager' => ['module' => 'Dashboard', 'title' => 'Manager dashboard'],
            'dashboard-staff'   => ['module' => 'Dashboard', 'title' => 'Staff dashboard'],

            // HR & Recruitment
            'hr-jobs'           => ['module' => 'HR & Recruitment', 'title' => 'Job Listing'],
            'hr-recruitment'    => ['module' => 'HR & Recruitment', 'title' => 'Recruitment'],
            'hr-applicant'      => ['module' => 'HR & Recruitment', 'title' => 'Applicants'],
            'hr-applicant-add'  => ['module' => 'HR & Recruitment', 'title' => 'Add applicant'],
            'hr-applicant-profile' => ['module' => 'HR & Recruitment', 'title' => 'Applicant profile'],
            'hr-convert-to-staff'  => ['module' => 'HR & Recruitment', 'title' => 'Convert to staff'],
            'hr-staff'          => ['module' => 'HR & Recruitment', 'title' => 'Staff'],
            'hr-staff-add'      => ['module' => 'HR & Recruitment', 'title' => 'Add staff member'],
            'hr-staff-profile'  => ['module' => 'HR & Recruitment', 'title' => 'Staff profile'],

            // Teams
            'teams'             => ['module' => 'Teams', 'title' => 'Teams & membership'],
            'team-profile'      => ['module' => 'Teams', 'title' => 'Team profile'],
            'team-assign-staff' => ['module' => 'Teams', 'title' => 'Assign staff'],

            // Rota & Scheduling
            'rota-list'         => ['module' => 'Rota & Scheduling', 'title' => 'Manage rotas'],
            'rota-create'       => ['module' => 'Rota & Scheduling', 'title' => 'Create rota'],
            'rota-week'         => ['module' => 'Rota & Scheduling', 'title' => 'Rota – Week view'],
            'rota-my'           => ['module' => 'Rota & Scheduling', 'title' => 'My rota'],
            'rota-requests'     => ['module' => 'Rota & Scheduling', 'title' => 'Requests inbox'],
            'rota-swap-request' => ['module' => 'Rota & Scheduling', 'title' => 'Swap request'],
            'rota-timeoff-request' => ['module' => 'Rota & Scheduling', 'title' => 'Time-off request'],
            'rota-audit'        => ['module' => 'Rota & Scheduling', 'title' => 'Audit log'],
            'rota-publish-review' => ['module' => 'Rota & Scheduling', 'title' => 'Publish & review'],

            // Timesheets (still under Rota & Scheduling in your sidebar)
            'timesheets'        => ['module' => 'Rota & Scheduling', 'title' => 'Timesheets'],
            'timesheet-week'    => ['module' => 'Rota & Scheduling', 'title' => 'Timesheet – Week view'],
            'timesheet-approve' => ['module' => 'Rota & Scheduling', 'title' => 'Approve timesheets'],

            // Tasks
            'tasks'             => ['module' => 'Tasks', 'title' => 'Tasks inbox'],
            'task-view'         => ['module' => 'Tasks', 'title' => 'Task details'],
            'task-create'       => ['module' => 'Tasks', 'title' => 'Create task'],

            // Settings
            'settings-org'         => ['module' => 'Settings', 'title' => 'Organisation'],
            'settings-roles'       => ['module' => 'Settings', 'title' => 'Roles'],
            'settings-permissions' => ['module' => 'Settings', 'title' => 'Permissions'],
            'settings-contracts'   => ['module' => 'Settings', 'title' => 'Contracts'],
            'settings-compliance'  => ['module' => 'Settings', 'title' => 'Compliance'],
        ];
    }
}

if (!function_exists('sc_page_title')) {
    function sc_page_title(string $page, string $fallback = 'SmartCare'): string {
        $m = sc_page_meta();
        return $m[$page]['title'] ?? $fallback;
    }
}

if (!function_exists('sc_page_module')) {
    function sc_page_module(string $page, string $fallback = 'Dashboard'): string {
        $m = sc_page_meta();
        return $m[$page]['module'] ?? $fallback;
    }
}

// -------------------------------------------------
// Optional helper modules (kept separate for clarity)
// -------------------------------------------------
$scTeamsFile = __DIR__ . '/helpers-teams.php';
if (is_file($scTeamsFile)) {
    require_once $scTeamsFile;
}

// Back-compat: some pages might call sc_get_teams() instead of sc_teams()
if (!function_exists('sc_get_teams') && function_exists('sc_teams')) {
    function sc_get_teams(): array {
        return sc_teams();
    }
}

// ------------------------------
// Session + Login + Role + Permissions (UI prototype)
// ------------------------------
if (!function_exists('sc_boot_session')) {
    function sc_boot_session(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('sc_is_logged_in')) {
    /**
     * Prototype login state.
     * Later: Laravel auth()->check()
     */
    function sc_is_logged_in(): bool {
        sc_boot_session();
        return !empty($_SESSION['sc_demo_logged_in']);
    }
}

if (!function_exists('sc_current_role')) {
    /**
     * Returns current role key (owner/admin/manager/supervisor/staff).
     *
     * Option A behaviour:
     * - Role is chosen at login and stored in session.
     * - ?as_role=... is allowed ONLY when logged in (demo switching).
     */
    function sc_current_role(): string {
        sc_boot_session();

        // Option A: allow demo role switching only after login
        if (sc_is_logged_in() && isset($_GET['as_role'])) {
            $raw = strtolower((string) $_GET['as_role']);
            $raw = preg_replace('/[^a-z]/', '', $raw);
            $allowed = ['owner','admin','manager','supervisor','staff'];
            if (in_array($raw, $allowed, true)) {
                $_SESSION['sc_role'] = $raw;
            }
        }

        $role = $_SESSION['sc_role'] ?? 'manager';
        $role = strtolower((string) $role);
        return preg_replace('/[^a-z]/', '', $role) ?: 'manager';
    }
}

if (!function_exists('sc_permissions_map')) {
    function sc_permissions_map(): array {
        return [
            'owner' => [
                'dashboard'   => ['view'],

                'hr'          => ['view','create','edit','approve','export','admin'],
                'teams'       => ['view','manage','admin'],
                'rota'        => ['view','create','edit','approve','publish','admin'],
                'timesheets'  => ['view','generate','edit','approve','export','admin'],
                'tasks'       => ['view','create','edit','approve'],

                'payroll'     => ['view','create','edit','approve','export','admin'],
                'training'    => ['view','create','edit','approve','export','admin'],
                'audits'      => ['view','create','edit','approve','export','admin'],
                'maintenance' => ['view','create','edit','approve','export','admin'],

                'settings'    => ['view','admin'],
            ],
            'admin' => [
                'dashboard'   => ['view'],

                'hr'          => ['view','create','edit','approve','export','admin'],
                'teams'       => ['view','manage'],
                'rota'        => ['view','create','edit','approve','publish','export'],
                'timesheets'  => ['view','generate','edit','approve','export'],
                'tasks'       => ['view','create','edit','approve'],

                'payroll'     => ['view','export'],
                'training'    => ['view','create','edit','approve','export'],
                'audits'      => ['view','create','edit','approve','export'],
                'maintenance' => ['view','create','edit','approve'],

                'settings'    => ['view','admin'],
            ],
            'manager' => [
                'dashboard'   => ['view'],

                'hr'          => ['view','create','edit','approve'],
                'teams'       => ['view','manage'],
                'rota'        => ['view','create','edit','approve','publish'],
                'timesheets'  => ['view','generate','edit','approve','export'],
                'tasks'       => ['view','create','edit','approve'],

                'payroll'     => ['view'],
                'training'    => ['view','create','edit','approve'],
                'audits'      => ['view','create','edit','approve'],
                'maintenance' => ['view','create','edit','approve'],

                'settings'    => ['view'],
            ],
            'supervisor' => [
                'dashboard'   => ['view'],

                'hr'          => ['view'],
                'teams'       => ['view','manage'],
                'rota'        => ['view','create','edit','approve'],
                'timesheets'  => ['view'],
                'tasks'       => ['view','create'],

                'payroll'     => [],
                'training'    => ['view'],
                'audits'      => ['view'],
                'maintenance' => ['view','create'],

                'settings'    => [],
            ],
            'staff' => [
                'dashboard'   => ['view'],

                'hr'          => [],
                'teams'       => [],
                'rota'        => ['view','request'],
                'timesheets'  => ['view'],
                'tasks'       => ['view'],

                'payroll'     => ['view'],
                'training'    => ['view'],
                'audits'      => [],
                'maintenance' => [],

                'settings'    => [],
            ],
        ];
    }
}

if (!function_exists('sc_can')) {
    function sc_can(string $module, string $action = 'view'): bool {
        $role = sc_current_role();
        $map = sc_permissions_map();
        $actions = $map[$role][$module] ?? [];
        return in_array($action, $actions, true);
    }
}

if (!function_exists('sc_nav_link_classes')) {
    function sc_nav_link_classes(string $currentPage, string $match): string
    {
        $base = 'group flex flex-col gap-0.5 px-3 py-2 rounded-lg text-sm text-sc-sidebar-muted hover:bg-slate-800/60 hover:text-sc-sidebar-active transition';

        if ($currentPage === $match) {
            return $base . ' bg-slate-800 text-sc-sidebar-active border-l-4 border-sc-primary pl-2.5';
        }

        return $base;
    }
}

if (!function_exists('sc_e')) {
    function sc_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// ------------------------------
// Staff lifecycle helpers (prototype)
// ------------------------------
if (!function_exists('sc_staff_status_normalize')) {
    function sc_staff_status_normalize($status): string {
        $s = strtolower(trim((string) $status));
        $s = str_replace([' ', '-'], '_', $s);

        if ($s === 'onboarding') return 'probation';
        if ($s === 'on_leave' || $s === 'leave') return 'on_leave';
        if ($s === 'leaver' || $s === 'left') return 'leaver';

        $allowed = ['active','probation','on_leave','suspended','inactive','leaver'];
        return in_array($s, $allowed, true) ? $s : 'active';
    }
}

if (!function_exists('sc_staff_is_active')) {
    function sc_staff_is_active(array $staff): bool {
        $status = sc_staff_status_normalize($staff['status'] ?? 'active');
        return !in_array($status, ['inactive','leaver'], true);
    }
}

if (!function_exists('sc_staff_is_operational')) {
    function sc_staff_is_operational(array $staff): bool {
        $status = sc_staff_status_normalize($staff['status'] ?? 'active');
        return in_array($status, ['active','probation'], true);
    }
}

if (!function_exists('sc_staff_status_badge')) {
    function sc_staff_status_badge(array $staff): array {
        $status = sc_staff_status_normalize($staff['status'] ?? 'active');
        switch ($status) {
            case 'active':
                return ['bg-emerald-50 text-emerald-700', 'Active'];
            case 'probation':
                return ['bg-sc-primary-soft text-sc-primary', 'Probation'];
            case 'on_leave':
                return ['bg-amber-50 text-amber-800', 'On leave'];
            case 'suspended':
                return ['bg-rose-50 text-rose-700', 'Suspended'];
            case 'leaver':
                return ['bg-slate-100 text-slate-700', 'Leaver'];
            case 'inactive':
                return ['bg-slate-100 text-slate-700', 'Inactive'];
            default:
                return ['bg-slate-100 text-slate-700', 'Active'];
        }
    }
}
// ------------------------------
// Employment terms helpers (prototype, Laravel-ready)
// ------------------------------
if (!function_exists('sc_employment_terms_defaults')) {
    /** Default structure for employment terms. */
    function sc_employment_terms_defaults(): array {
        return [
            'contract_template_key'   => null,
            'contract_template_name'  => null,

            'effective_from'          => null, // YYYY-MM-DD
            'pay_type'                => 'hourly', // hourly|salary
            'hourly_rate'             => null,
            'annual_salary'           => null,
            'contracted_hours_week'   => null,

            'holiday_method'          => null, // accrued|rolled_up|included|not_included
            'holiday_rate_percent'    => null,
            'holiday_entitlement_days'=> null,

            'worker_type'             => 'standard', // standard|student|apprentice|sponsored
            'enforce_hour_caps'       => false,
            'max_hours_week_term'     => null,
            'max_hours_week_holiday'  => null,

            // Enhancements / overtime (copied from template for now)
            'overtime_eligible'       => null,
            'overtime_threshold_hours'=> null,
            'overtime_multiplier'     => null,
            'night_multiplier'        => null,
            'weekend_multiplier'      => null,
            'bank_holiday_multiplier' => null,
            'paid_training'           => null,
        ];
    }
}

if (!function_exists('sc_employment_terms_from_template')) {
    /**
     * Build employment terms from a contract template + optional overrides.
     * $overrides values are trusted after validation in sc_employment_terms_validate().
     */
    function sc_employment_terms_from_template(?string $templateKey, array $overrides = []): array {
        $terms = sc_employment_terms_defaults();

        $tpl = null;
        if ($templateKey && function_exists('sc_contract_template')) {
            $tpl = sc_contract_template($templateKey);
        }

        if ($tpl) {
            $terms['contract_template_key']  = (string)($tpl['key'] ?? $templateKey);
            $terms['contract_template_name'] = (string)($tpl['name'] ?? $terms['contract_template_key']);

            $f = $tpl['fields'] ?? [];

            $terms['pay_type'] = (string)($f['pay_type'] ?? $terms['pay_type']);
            $terms['hourly_rate'] = isset($f['base_rate']) ? (float)$f['base_rate'] : null;
            $terms['annual_salary'] = isset($f['annual_salary']) ? (float)$f['annual_salary'] : (isset($f['annual_salary']) ? (float)$f['annual_salary'] : null);
            // NOTE: data-contracts.php uses annual_salary, not salary_amount
            if (isset($f['annual_salary'])) $terms['annual_salary'] = (float)$f['annual_salary'];

            if (isset($f['contracted_hours_week'])) $terms['contracted_hours_week'] = (float)$f['contracted_hours_week'];

            $terms['holiday_method'] = isset($f['holiday_method']) ? (string)$f['holiday_method'] : null;
            if (isset($f['holiday_rate_percent'])) $terms['holiday_rate_percent'] = $f['holiday_rate_percent'] !== null ? (float)$f['holiday_rate_percent'] : null;
            if (isset($f['holiday_entitlement_days'])) $terms['holiday_entitlement_days'] = $f['holiday_entitlement_days'] !== null ? (float)$f['holiday_entitlement_days'] : null;

            $terms['worker_type'] = isset($f['worker_type']) ? (string)$f['worker_type'] : 'standard';
            $terms['enforce_hour_caps'] = !empty($f['enforce_hour_caps']);
            if (isset($f['max_hours_week_term'])) $terms['max_hours_week_term'] = $f['max_hours_week_term'] !== null ? (float)$f['max_hours_week_term'] : null;
            if (isset($f['max_hours_week_holiday'])) $terms['max_hours_week_holiday'] = $f['max_hours_week_holiday'] !== null ? (float)$f['max_hours_week_holiday'] : null;

            // Enhancements / overtime / training (copy through)
            foreach (['overtime_eligible','overtime_threshold_hours','overtime_multiplier','night_multiplier','weekend_multiplier','bank_holiday_multiplier','paid_training'] as $k) {
                if (array_key_exists($k, $f)) $terms[$k] = $f[$k];
            }
        }

        // Apply overrides last
        foreach ($overrides as $k => $v) {
            if (!array_key_exists($k, $terms)) continue;
            $terms[$k] = $v;
        }

        // Ensure template name fallback
        if (!$terms['contract_template_name']) {
            $terms['contract_template_name'] = $terms['contract_template_key'] ?: 'Contract';
        }

        return $terms;
    }
}

if (!function_exists('sc_employment_terms_validate')) {
    /** Validate/normalise employment terms POST payload. Returns normalised array; fills $errors. */
    function sc_employment_terms_validate(array $raw, array &$errors): array {
        $errors = [];

        $templateKey = preg_replace('/[^a-z0-9\\-]/', '', (string)($raw['contract_template_key'] ?? ''));
        $effectiveFrom = trim((string)($raw['effective_from'] ?? ''));
        $payType = strtolower(trim((string)($raw['pay_type'] ?? 'hourly')));

        if ($effectiveFrom === '') {
            $errors[] = 'Effective from date is required.';
        } else {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $effectiveFrom);
            if (!$dt) $errors[] = 'Effective from must be a valid date (YYYY-MM-DD).';
        }

        if (!in_array($payType, ['hourly','salary'], true)) {
            $errors[] = 'Pay type must be hourly or salary.';
            $payType = 'hourly';
        }

        $hourlyRate = trim((string)($raw['hourly_rate'] ?? ''));
        $annualSalary = trim((string)($raw['annual_salary'] ?? ''));
        $contractedHours = trim((string)($raw['contracted_hours_week'] ?? ''));

        if ($payType === 'hourly') {
            if ($hourlyRate === '' || !is_numeric($hourlyRate)) $errors[] = 'Hourly rate must be a valid number.';
        } else {
            if ($annualSalary === '' || !is_numeric($annualSalary)) $errors[] = 'Annual salary must be a valid number.';
        }

        if ($contractedHours !== '' && !is_numeric($contractedHours)) $errors[] = 'Contracted hours per week must be a number.';

        $holidayMethod = trim((string)($raw['holiday_method'] ?? ''));
        $holidayRate = trim((string)($raw['holiday_rate_percent'] ?? ''));
        $holidayDays = trim((string)($raw['holiday_entitlement_days'] ?? ''));

        if ($holidayRate !== '' && !is_numeric($holidayRate)) $errors[] = 'Holiday rate percent must be a number.';
        if ($holidayDays !== '' && !is_numeric($holidayDays)) $errors[] = 'Holiday entitlement days must be a number.';

        $workerType = trim((string)($raw['worker_type'] ?? 'standard'));
        $workerType = $workerType ?: 'standard';

        $enforceCaps = !empty($raw['enforce_hour_caps']);
        $maxTerm = trim((string)($raw['max_hours_week_term'] ?? ''));
        $maxHoliday = trim((string)($raw['max_hours_week_holiday'] ?? ''));

        if ($enforceCaps) {
            if ($maxTerm === '' || !is_numeric($maxTerm)) $errors[] = 'Max hours (term-time) must be a number.';
            if ($maxHoliday === '' || !is_numeric($maxHoliday)) $errors[] = 'Max hours (holiday) must be a number.';
        }

        $overrides = [
            'contract_template_key' => $templateKey ?: null,
            'effective_from' => $effectiveFrom ?: null,
            'pay_type' => $payType,
            'hourly_rate' => ($payType === 'hourly' && $hourlyRate !== '' && is_numeric($hourlyRate)) ? (float)$hourlyRate : null,
            'annual_salary' => ($payType === 'salary' && $annualSalary !== '' && is_numeric($annualSalary)) ? (float)$annualSalary : null,
            'contracted_hours_week' => ($contractedHours !== '' && is_numeric($contractedHours)) ? (float)$contractedHours : null,
            'holiday_method' => $holidayMethod ?: null,
            'holiday_rate_percent' => ($holidayRate !== '' && is_numeric($holidayRate)) ? (float)$holidayRate : null,
            'holiday_entitlement_days' => ($holidayDays !== '' && is_numeric($holidayDays)) ? (float)$holidayDays : null,
            'worker_type' => $workerType,
            'enforce_hour_caps' => $enforceCaps,
            'max_hours_week_term' => ($enforceCaps && $maxTerm !== '' && is_numeric($maxTerm)) ? (float)$maxTerm : null,
            'max_hours_week_holiday' => ($enforceCaps && $maxHoliday !== '' && is_numeric($maxHoliday)) ? (float)$maxHoliday : null,
        ];

        return $overrides;
    }
}

if (!function_exists('sc_staff_set_employment_terms')) {
    /**
     * Set current employment terms on a session staff record and maintain history.
     * Stores:
     *  - employment_terms_current (array)
     *  - employment_terms_history (array of arrays)
     */
    function sc_staff_set_employment_terms(int $staffId, array $newTerms): void {
        sc_boot_session();
        if (!isset($_SESSION['sc_staff']) || !is_array($_SESSION['sc_staff'])) $_SESSION['sc_staff'] = [];
        if (!isset($_SESSION['sc_staff'][$staffId]) || !is_array($_SESSION['sc_staff'][$staffId])) {
            $_SESSION['sc_staff'][$staffId] = ['id' => $staffId, 'status' => 'Active'];
        }

        $record = &$_SESSION['sc_staff'][$staffId];

        if (!isset($record['employment_terms_history']) || !is_array($record['employment_terms_history'])) {
            $record['employment_terms_history'] = [];
        }

        // Move existing current terms into history (if present)
        if (isset($record['employment_terms_current']) && is_array($record['employment_terms_current'])) {
            $prev = $record['employment_terms_current'];

            // Only push if there is meaningful content
            $hasSomething = !empty(array_filter($prev, fn($v) => $v !== null && $v !== '' && $v !== false));
            if ($hasSomething) {
                $record['employment_terms_history'][] = $prev;
            }
        }

        $record['employment_terms_current'] = $newTerms;

        // Back-compat: some pages already read $staff['employment_terms']
        $record['employment_terms'] = $newTerms;
    }
}



// Quick-create a task in the session store (same structure as task-create.php)
function sc_task_quick_create(array $t): int {
    sc_tasks_bootstrap();

    $newId = sc_task_next_id();

    $_SESSION['sc_tasks'][$newId] = [
        'id' => $newId,
        'title' => (string)($t['title'] ?? 'Task'),
        'description' => (string)($t['description'] ?? ''),
        'status' => 'open',
        'priority' => in_array(($t['priority'] ?? 'medium'), ['low','medium','high'], true) ? $t['priority'] : 'medium',
        'due_date' => !empty($t['due_date']) ? (string)$t['due_date'] : null,
        'tags' => is_array($t['tags'] ?? null) ? array_values($t['tags']) : [],
        'assignee' => !empty($t['assignee']) ? (string)$t['assignee'] : 'Unassigned',
        'entity_type' => $t['entity_type'] ?? null,
        'entity_id' => $t['entity_id'] ?? null,
        'entity_label' => $t['entity_label'] ?? null,
        'template_key' => $t['template_key'] ?? null,
        'created_at' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'updated_at' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    ];

    sc_task_log_activity($newId, 'Task created', 'system');
    return $newId;
}

// includes/helpers.php

if (!function_exists('sc_brand')) {
    function sc_brand(): array {
        static $brand = null;

        if ($brand !== null) {
            return $brand;
        }

        // Branding override order:
        // 1) brand.local.php (per-install / per-care-home override; not meant to be committed)
        // 2) brand.php (default branding shipped with the app)
        $fileLocal = __DIR__ . '/brand.local.php';
        $fileDefault = __DIR__ . '/brand.php';

        if (is_file($fileLocal)) {
            $brand = require $fileLocal;
        } else {
            $brand = is_file($fileDefault) ? require $fileDefault : [];
        }

        return is_array($brand) ? $brand : [];
    }
}


// --- SmartCare Kiosk Careers CSRF helpers ---
if (!function_exists('sc_csrf_token')) {
  function sc_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['careers_csrf'])) {
      $_SESSION['careers_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['careers_csrf'];
  }
}
if (!function_exists('sc_csrf_field')) {
  function sc_csrf_field(): void {
    $t = sc_csrf_token();
    echo '<input type="hidden" name="csrf" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
  }
}
if (!function_exists('sc_csrf_verify')) {
  function sc_csrf_verify(?string $token): void {
    $ok = is_string($token) && $token !== '' && hash_equals((string)($_SESSION['careers_csrf'] ?? ''), $token);
    if (!$ok) {
      http_response_code(419);
      echo 'Invalid CSRF token';
      exit;
    }
  }
}

// -------------------------------------------------
// Careers application validation (client + server parity)
// -------------------------------------------------
if (!function_exists('sc_trim')) {
  function sc_trim($v): string {
    return trim(is_string($v) ? $v : (string)$v);
  }
}

if (!function_exists('sc_normalize_spaces')) {
  function sc_normalize_spaces(string $s): string {
    return preg_replace('/\s+/', ' ', trim($s)) ?? trim($s);
  }
}

if (!function_exists('sc_normalize_postcode')) {
  function sc_normalize_postcode(string $pc): string {
    $pc = strtoupper(trim($pc));
    $pc = preg_replace('/\s+/', '', $pc) ?? $pc;
    // Add a space before the last 3 chars if plausible
    if (strlen($pc) > 3) {
      $pc = substr($pc, 0, -3) . ' ' . substr($pc, -3);
    }
    return trim($pc);
  }
}

if (!function_exists('sc_is_uk_postcode')) {
  function sc_is_uk_postcode(string $postcode): bool {
    $pc = sc_normalize_postcode($postcode);
    // UK postcode (includes GIR 0AA)
    return (bool)preg_match('/^(GIR 0AA|(?:[A-Z]{1,2}\d{1,2}[A-Z]?)\s*\d[A-Z]{2})$/i', $pc);
  }
}

if (!function_exists('sc_normalize_phone')) {
  function sc_normalize_phone(string $phone): string {
    // Keep digits only (we deliberately do NOT add +44)
    return preg_replace('/[^0-9]/', '', $phone) ?? '';
  }
}

if (!function_exists('sc_is_uk_mobile')) {
  function sc_is_uk_mobile(string $phone): bool {
    $p = sc_normalize_phone($phone);
    return (bool)preg_match('/^07\d{9}$/', $p);
  }
}

if (!function_exists('sc_is_uk_phone')) {
  function sc_is_uk_phone(string $phone): bool {
    $p = sc_normalize_phone($phone);
    // General UK numbers (mobile or landline) without +44
    return (bool)preg_match('/^0\d{9,10}$/', $p);
  }
}

if (!function_exists('sc_is_valid_email')) {
  function sc_is_valid_email(string $email): bool {
    $email = trim($email);
    if ($email === '' || strlen($email) > 254) return false;
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
  }
}

if (!function_exists('sc_validate_dob')) {
  /**
   * @return array{ok:bool, message:string, age:int|null}
   */
  function sc_validate_dob(string $dob, int $minAge = 16): array {
    $dob = trim($dob);
    if ($dob === '') return ['ok'=>false, 'message'=>'Date of birth is required.', 'age'=>null];

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
    $errs = DateTimeImmutable::getLastErrors();
    if (!$dt || ($errs['warning_count'] ?? 0) > 0 || ($errs['error_count'] ?? 0) > 0) {
      return ['ok'=>false, 'message'=>'Please enter a valid date of birth.', 'age'=>null];
    }

    $today = new DateTimeImmutable('today');
    if ($dt > $today) {
      return ['ok'=>false, 'message'=>'Date of birth cannot be in the future.', 'age'=>null];
    }

    $age = (int)$today->diff($dt)->y;
    if ($age < $minAge) {
      return ['ok'=>false, 'message'=>'You must be at least ' . $minAge . ' years old to apply.', 'age'=>$age];
    }

    // sanity lower bound
    if ((int)$dt->format('Y') < 1900) {
      return ['ok'=>false, 'message'=>'Please double-check your date of birth.', 'age'=>$age];
    }

    return ['ok'=>true, 'message'=>'', 'age'=>$age];
  }
}

if (!function_exists('sc_careers_set_errors')) {
  function sc_careers_set_errors(int $step, array $errors): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['careers_errors']) || !is_array($_SESSION['careers_errors'])) {
      $_SESSION['careers_errors'] = [];
    }
    $_SESSION['careers_errors'][(string)$step] = $errors;
  }
}

if (!function_exists('sc_careers_get_errors')) {
  function sc_careers_get_errors(int $step): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $all = $_SESSION['careers_errors'] ?? [];
    $errs = is_array($all) ? ($all[(string)$step] ?? []) : [];
    return is_array($errs) ? $errs : [];
  }
}

if (!function_exists('sc_careers_clear_errors')) {
  function sc_careers_clear_errors(int $step): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['careers_errors'][(string)$step])) {
      unset($_SESSION['careers_errors'][(string)$step]);
    }
  }
}

if (!function_exists('sc_careers_validate_step')) {
  /**
   * Validates + normalizes the application data for a given step.
   * Returns: [fieldKey => message]
   */
  function sc_careers_validate_step(int $step, array &$app): array {
    $errors = [];

    $personal = $app['personal'] ?? [];
    $role     = $app['role'] ?? [];
    $checks   = $app['checks'] ?? [];
    $work     = $app['work_history'] ?? [];
    $edu      = $app['education'] ?? [];
    $refs     = $app['references'] ?? [];
    $decl     = $app['declaration'] ?? [];

    if ($step === 1) {
      // Required: name/email/dob/mobile + address + role + right-to-work + DBS
      if (sc_trim($personal['first_name'] ?? '') === '') $errors['first_name'] = 'First name is required.';
      if (sc_trim($personal['last_name'] ?? '') === '') $errors['last_name'] = 'Last name is required.';

      $dobRes = sc_validate_dob((string)($personal['dob'] ?? ''), 16);
      if (!$dobRes['ok']) $errors['dob'] = $dobRes['message'];

      $email = sc_trim($personal['email'] ?? '');
      if ($email === '') {
        $errors['email'] = 'Email address is required.';
      } elseif (!sc_is_valid_email($email)) {
        $errors['email'] = 'Please enter a valid email address.';
      } else {
        $app['personal']['email'] = strtolower($email);
      }

      $mobile = sc_trim($personal['phone'] ?? '');
      if ($mobile === '') {
        $errors['phone'] = 'Mobile number is required.';
      } elseif (!sc_is_uk_mobile($mobile)) {
        $errors['phone'] = 'Please enter a UK mobile number in the format 07XXXXXXXXX.';
      } else {
        $app['personal']['phone'] = sc_normalize_phone($mobile);
      }

      $home = sc_trim($personal['phone_home'] ?? '');
      if ($home !== '' && !sc_is_uk_phone($home)) {
        $errors['phone_home'] = 'Please enter a valid UK phone number (starting with 0).' ;
      } elseif ($home !== '') {
        $app['personal']['phone_home'] = sc_normalize_phone($home);
      }

      if (sc_trim($personal['address_line1'] ?? '') === '') $errors['address_line1'] = 'Address line 1 is required.';
      if (sc_trim($personal['address_town'] ?? '') === '') $errors['address_town'] = 'Town/City is required.';

      $pc = sc_trim($personal['address_postcode'] ?? '');
      if ($pc === '') {
        $errors['address_postcode'] = 'Postcode is required.';
      } elseif (!sc_is_uk_postcode($pc)) {
        $errors['address_postcode'] = 'Please enter a valid UK postcode.';
      } else {
        $app['personal']['address_postcode'] = sc_normalize_postcode($pc);
      }

      if (sc_trim($role['position_applied_for'] ?? '') === '') $errors['position_applied_for'] = 'Please select the position you are applying for.';
      if (sc_trim($role['work_type'] ?? '') === '') $errors['work_type'] = 'Please select a work type.';

      if (sc_trim($checks['has_right_to_work'] ?? '') === '') $errors['has_right_to_work'] = 'Please tell us your right to work status.';
      if (sc_trim($checks['has_current_dbs'] ?? '') === '') $errors['has_current_dbs'] = 'Please tell us your DBS status.';

      // If requires sponsorship is yes, visa type becomes required
      if (sc_trim($checks['requires_sponsorship'] ?? '') === 'yes' && sc_trim($checks['visa_type'] ?? '') === '') {
        $errors['visa_type'] = 'Please provide your visa type.';
      }
    }

    if ($step === 2) {
      $jobs = is_array($work['jobs'] ?? null) ? $work['jobs'] : [];
      // Require at least 1 job with basics
      $firstNonEmpty = null;
      foreach ($jobs as $j) {
        if (!is_array($j)) continue;
        if (sc_trim($j['employer_name'] ?? '') !== '' || sc_trim($j['job_title'] ?? '') !== '') {
          $firstNonEmpty = $j;
          break;
        }
      }
      if ($firstNonEmpty === null) {
        $errors['jobs'] = 'Please add at least one job in your work history.';
      } else {
        if (sc_trim($firstNonEmpty['employer_name'] ?? '') === '') $errors['jobs_employer_name'] = 'Please enter an employer name for your first job.';
        if (sc_trim($firstNonEmpty['job_title'] ?? '') === '') $errors['jobs_job_title'] = 'Please enter a job title for your first job.';
        if (empty($firstNonEmpty['start_month']) || empty($firstNonEmpty['start_year'])) $errors['jobs_start'] = 'Please enter a start month and year for your first job.';
      }
    }

    if ($step === 3) {
      if (sc_trim($edu['highest_education_level'] ?? '') === '') {
        $errors['highest_education_level'] = 'Please select your highest education level.';
      }
    }

    if ($step === 4) {
      $list = is_array($refs['references'] ?? null) ? $refs['references'] : [];
      $nonEmpty = [];
      foreach ($list as $r) {
        if (!is_array($r)) continue;
        if (sc_trim($r['name'] ?? '') !== '' || sc_trim($r['email'] ?? '') !== '' || sc_trim($r['phone'] ?? '') !== '') {
          $nonEmpty[] = $r;
        }
      }
      if (count($nonEmpty) < 2) {
        $errors['references'] = 'Please add at least 2 references.';
      } else {
        // Validate first two refs
        for ($i=0; $i<2; $i++) {
          $r = $nonEmpty[$i];
          if (sc_trim($r['name'] ?? '') === '') $errors['ref_' . ($i+1) . '_name'] = 'Reference ' . ($i+1) . ': name is required.';
          $re = sc_trim($r['email'] ?? '');
          $rp = sc_trim($r['phone'] ?? '');
          if ($re === '' && $rp === '') {
            $errors['ref_' . ($i+1) . '_contact'] = 'Reference ' . ($i+1) . ': please provide an email or phone number.';
          }
          if ($re !== '' && !sc_is_valid_email($re)) {
            $errors['ref_' . ($i+1) . '_email'] = 'Reference ' . ($i+1) . ': please enter a valid email.';
          }
          if ($rp !== '' && !sc_is_uk_phone($rp)) {
            $errors['ref_' . ($i+1) . '_phone'] = 'Reference ' . ($i+1) . ': please enter a valid UK phone number (starting with 0).';
          }
        }
      }
    }

    if ($step === 6) {
      // Declarations must all be ticked
      $requiredChecks = ['confirm_true_information','aware_of_false_info_consequences','consent_to_processing'];
      foreach ($requiredChecks as $k) {
        if (empty($decl[$k])) {
          $errors[$k] = 'Please tick this declaration to continue.';
        }
      }
      if (sc_trim($decl['typed_signature'] ?? '') === '') {
        $errors['typed_signature'] = 'Please type your full name as your signature.';
      }
      $sd = sc_trim($decl['signature_date'] ?? '');
      if ($sd === '') {
        $errors['signature_date'] = 'Please provide the signature date.';
      } else {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $sd);
        $errs = DateTimeImmutable::getLastErrors();
        if (!$dt || ($errs['warning_count'] ?? 0) > 0 || ($errs['error_count'] ?? 0) > 0) {
          $errors['signature_date'] = 'Please provide a valid signature date.';
        } else {
          $today = new DateTimeImmutable('today');
          if ($dt > $today) $errors['signature_date'] = 'Signature date cannot be in the future.';
        }
      }
    }

    return $errors;
  }
}

if (!function_exists('sc_careers_validate_all')) {
  function sc_careers_validate_all(array &$app): array {
    $all = [];
    foreach ([1,2,3,4,6] as $step) {
      $errs = sc_careers_validate_step($step, $app);
      if (!empty($errs)) {
        $all[(string)$step] = $errs;
      }
    }
    return $all;
  }
}

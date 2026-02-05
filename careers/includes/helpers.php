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

        $file = __DIR__ . '/brand.php';
        $brand = is_file($file) ? require $file : [];

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

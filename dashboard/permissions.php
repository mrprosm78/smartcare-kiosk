<?php
declare(strict_types=1);

/**
 * Simple permission map for admin roles.
 *
 * Roles (flat, explicit):
 *  - manager
 *  - payroll
 *  - admin
 *  - superadmin
 *
 * LOCKED RULES:
 * - Manager: shifts + employees/teams/departments (no payroll hours, no punch details, no contracts/settings)
 * - Payroll: view punch details + shifts + payroll hours (view-only; run/export paused)
 * - Admin: view all
 * - Superadmin: same as admin + super-only unlocks
 */

function admin_permissions_for_role(string $role): array {
  $role = strtolower(trim($role));

  // Base permissions (non-super-only)
  $all = [
    // general
    'view_dashboard',

    // shifts
    'view_shifts',
    'edit_shifts',
    'approve_shifts',

    // punches (audit)
    'view_punches',

    // HR
    'manage_staff',

    // HR
    'manage_hr_applications',

    // HR
    'view_hr_applications',

    // org
    'view_employees',
    'manage_employees',
    'view_teams',
    'manage_teams',
    'view_departments',
    'manage_departments',

    // contracts
    'view_contract',
    'edit_contract',

    // payroll hours (hours-only view)
    'view_payroll',

      'run_payroll',

    // settings / admin / devices
    'manage_settings_basic',
    'manage_settings_high',
    'manage_devices',
    'manage_admin_users',

    // Payroll (hours calculation + locking)
    // export_payroll not implemented yet
    'run_payroll',
  ];

  // Superadmin gets everything admin gets + super-only unlock permission
  if ($role === 'superadmin') {
    return array_values(array_unique(array_merge($all, [
      'unlock_payroll_locked_shifts',
    ])));
  }

  // Admin: same as superadmin EXCEPT super-only unlock
  if ($role === 'admin') {
    return $all;
  }

  // Manager (LOCKED): shifts + org views/limited manage only
  if ($role === 'manager') {
    return [
      'view_dashboard',
      'view_shifts',
      'edit_shifts',
      'approve_shifts',

      // employees
      'view_employees',
      'manage_employees',
      'view_departments',

      // punches (audit)
      'view_punches',
    
      // HR
      'view_hr_applications',
      'manage_hr_applications',
      'manage_staff',
];
  }

  // Payroll (LOCKED): view punches + shifts + payroll hours (NO contracts, NO run/export)
  if ($role === 'payroll') {
    return [
      'view_dashboard',

      'view_shifts',
      'view_punches',
      'view_payroll',

      'run_payroll',
    ];
  }

  return [];
}

function admin_can(array $user, string $permission): bool {
  $perms = admin_permissions_for_role((string)($user['role'] ?? ''));
  return in_array($permission, $perms, true);
}

function admin_require_perm(array $user, string $permission): void {
  if (!admin_can($user, $permission)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
}
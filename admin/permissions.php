<?php
declare(strict_types=1);

/**
 * Simple permission map for admin roles.
 *
 * Roles:
 *  - manager
 *  - payroll
 *  - admin
 *  - superadmin
 */

function admin_permissions_for_role(string $role): array {
  $role = strtolower(trim($role));

  $all = [
    // general
    'view_dashboard',
    'view_shifts',
    'edit_shifts',
    'approve_shifts',
    'view_employees',
    'manage_employees',
    'view_contract',
    'edit_contract',
    'view_payroll',
    'export_payroll',
    'run_payroll',
    'manage_settings_basic',
    'manage_settings_high',
    'manage_devices',
    'manage_admin_users',
  ];

  if ($role === 'superadmin') {
    return $all;
  }

  if ($role === 'manager') {
    return [
      'view_dashboard',
      'view_shifts',
      'edit_shifts',
      'approve_shifts',
      'view_employees',
      'manage_employees',
      // Managers cannot change settings or contracts (LOCKED)
    ];
  }

  if ($role === 'payroll') {
    return [
      'view_dashboard',
      'view_shifts',
      'view_employees',
      'view_contract',
      'view_payroll',
      'export_payroll',
      'run_payroll',
    ];
  }

  if ($role === 'admin') {
    // Operational admin: everything except high-level kiosk/system settings.
    return [
      'view_dashboard',
      'view_shifts',
      'edit_shifts',
      'approve_shifts',
      'view_employees',
      'manage_employees',
      'view_contract',
      'edit_contract',
      'view_payroll',
      'export_payroll',
      'manage_settings_basic',
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

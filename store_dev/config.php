<?php
declare(strict_types=1);

/**
 * SmartCare private config (DEV)
 * Location: outside public_html (sibling of public_html), e.g. /home/.../store_dev/config.php
 *
 * Copy for each care home and change ONLY this file (paths + DB).
 */

// ===== Private paths =====
define('APP_PRIVATE_ROOT', __DIR__);
define('APP_UPLOADS_PATH', APP_PRIVATE_ROOT . '/upload_photos');
define('APP_PAYROLL_EXPORTS_PATH', APP_PRIVATE_ROOT . '/payroll_exports');
define('APP_LOGS_PATH', APP_PRIVATE_ROOT . '/logs');

// ===== Database =====
$host = 'sdb-51.hosting.stackcp.net';
$db   = 'kiosk-dev-35303033d91d';
$user = 'kiosk-dev-35303033d91d';
$pass = 'j-SwK!m<^osU'; // rotate later
$charset = 'utf8mb4';

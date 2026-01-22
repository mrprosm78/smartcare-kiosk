<?php
// db.php
declare(strict_types=1);

/**
 * This file bootstraps the database connection.
 *
 * Recommended: put per-environment settings (DB creds + private paths) in a PRIVATE config file
 * outside public_html, e.g.:
 *   /home/.../store_dev/config.php
 *
 * This code will attempt to load it automatically.
 */

// Try to load private config (outside public web root).
$privateCandidates = [
  // If this code is deployed to /public_html/kiosk-dev/db.php, then:
  // dirname(__DIR__, 2) => /home/... (parent of public_html)
  dirname(__DIR__, 2) . '/store_dev/config.php',
  // Fallback if store_dev is inside public_html (not recommended, but handy for local/dev)
  dirname(__DIR__, 1) . '/store_dev/config.php',
  // As last resort, allow env var override
  getenv('SMARTCARE_PRIVATE_CONFIG') ?: '',
];

foreach ($privateCandidates as $cfg) {
  if (is_string($cfg) && $cfg !== '' && file_exists($cfg)) {
    require_once $cfg;
    break;
  }
}

// Defaults (used only if private config did not define constants)
$host = 'sdb-51.hosting.stackcp.net';
$db   = 'kiosk-dev-35303033d91d';
$user = 'kiosk-dev-35303033d91d';
$pass = 'j-SwK!m<^osU'; // rotate later
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed1');
}

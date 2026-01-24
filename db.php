<?php
// db.php
declare(strict_types=1);

/**
 * Bootstrap DB connection.
 * Secrets live ONLY in private config outside public_html.
 *
 * Expected private config defines:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 * Optionally:
 *   DB_CHARSET (default utf8mb4)
 *   SMARTCARE_ENV ("dev" or "prod")
 */

// Locate private config (outside public web root).
$privateCandidates = [
  // If deployed as: /home/.../public_html/kiosk-dev/db.php
  // dirname(__DIR__, 2) => /home/... (parent of public_html)
  dirname(__DIR__, 2) . '/store_dev/config.php',

  // Optional fallback (not recommended, but useful for local/dev if you keep store_dev inside public_html)
  dirname(__DIR__, 1) . '/store_dev/config.php',

  // Environment override
  (string)(getenv('SMARTCARE_PRIVATE_CONFIG') ?: ''),
];

$privateConfigLoaded = false;
foreach ($privateCandidates as $cfg) {
  if ($cfg !== '' && is_file($cfg)) {
    require_once $cfg;
    $privateConfigLoaded = true;
    break;
  }
}

if (!$privateConfigLoaded) {
  http_response_code(500);
  exit('Private config missing. Expected store_dev/config.php outside public_html.');
}

// Validate required DB constants exist (defined in private config).
$required = ['DB_HOST','DB_NAME','DB_USER','DB_PASS'];
$missing = [];
foreach ($required as $c) {
  if (!defined($c) || (string)constant($c) === '') {
    $missing[] = $c;
  }
}
if ($missing) {
  http_response_code(500);
  exit('Private config incomplete. Missing: ' . implode(', ', $missing));
}

$charset = defined('DB_CHARSET') && (string)DB_CHARSET !== '' ? (string)DB_CHARSET : 'utf8mb4';
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . $charset;

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Throwable $e) {
  http_response_code(500);

  // DEV-only helpful message (optional)
  $env = defined('SMARTCARE_ENV') ? (string)SMARTCARE_ENV : (string)(getenv('SMARTCARE_ENV') ?: 'prod');
  if ($env === 'dev') {
    exit("Database connection failed:\n" . $e->getMessage());
  }

  exit('Database connection failed');
}

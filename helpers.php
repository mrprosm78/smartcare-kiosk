<?php
declare(strict_types=1);

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function now_utc(): string {
    return gmdate('Y-m-d H:i:s');
}

function valid_pin(string $pin, int $length): bool {
    return ctype_digit($pin) && strlen($pin) === $length;
}

/* ===== SETTINGS HELPERS ===== */
function setting(PDO $pdo, string $key, $default = null) {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];

    $stmt = $pdo->prepare("SELECT value FROM kiosk_settings WHERE `key`=? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();

    $cache[$key] = ($val === false) ? $default : $val;
    return $cache[$key];
}

function setting_int(PDO $pdo, string $key, int $default): int {
    return (int) setting($pdo, $key, (string)$default);
}

function setting_bool(PDO $pdo, string $key, bool $default): bool {
    return setting($pdo, $key, $default ? '1' : '0') === '1';
}


function setting_set(PDO $pdo, string $key, string $value): void {
    // Update only the value (preserve metadata columns like group/description/editable_by)
    $sql = "INSERT INTO kiosk_settings (`key`,`value`) VALUES (?,?)
            ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), `updated_at`=UTC_TIMESTAMP()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$key, $value]);
}

/**
 * Resolve the uploads base directory to an absolute filesystem path.
 *
 * Goal: portable across duplicated installs (e.g. /yyy/) where uploads live in
 * <project_root>/uploads. Store uploads_base_path as a relative value like "uploads".
 *
 * Supports:
 *  - empty / "auto" => <project_root>/uploads
 *  - absolute paths ("/home/.../uploads")
 *  - relative paths ("uploads", "../uploads") resolved from project root
 *  - existing relative-to-CWD directories (some shared hosts) via realpath()
 */
function resolve_uploads_base_path(string $configured): string {
    $projectRoot = realpath(__DIR__) ?: __DIR__;

    $v = trim($configured);
    if ($v === '' || strtolower($v) === 'auto') {
        return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'uploads';
    }

    // If it already resolves as a directory (relative-to-CWD on some hosts), accept it.
    $rp = @realpath($v);
    if ($rp !== false && is_dir($rp)) {
        return rtrim($rp, '/\\');
    }

    // Absolute filesystem path.
    if ($v[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $v)) {
        return rtrim($v, '/\\');
    }

    // Relative to project root.
    $candidate = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($v, '/\\');
    $candidateRp = @realpath($candidate);
    if ($candidateRp !== false && is_dir($candidateRp)) {
        return rtrim($candidateRp, '/\\');
    }
    return rtrim($candidate, '/\\');
}

function is_bcrypt(string $s): bool {
    return str_starts_with($s,'$2y$') || str_starts_with($s,'$2a$') || str_starts_with($s,'$2b$');
}

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

    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
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

function is_bcrypt(string $s): bool {
    return str_starts_with($s,'$2y$') || str_starts_with($s,'$2a$') || str_starts_with($s,'$2b$');
}

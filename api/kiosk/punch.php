<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// payload
$eventUuid  = (string)($input['event_uuid'] ?? '');
$action     = strtoupper((string)($input['action'] ?? ''));
$pin        = (string)($input['pin'] ?? '');
$deviceTime = (string)($input['device_time'] ?? '');

// headers
$kioskCode  = (string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? '');
$deviceTok  = (string)($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
$versionHdr = (int)($_SERVER['HTTP_X_PAIRING_VERSION'] ?? 0);

// useful request meta
$ipAddress  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$tokHash    = $deviceTok !== '' ? hash('sha256', $deviceTok) : null;

$now = now_utc(); // server truth time (Y-m-d H:i:s)

/**
 * Helpers local to this file (keeps changes isolated)
 */
function s(PDO $pdo, string $key, string $default = ''): string {
    return (string) setting($pdo, $key, $default);
}
function s_int(PDO $pdo, string $key, int $default): int {
    $v = trim(s($pdo, $key, (string)$default));
    return is_numeric($v) ? (int)$v : $default;
}
function s_bool(PDO $pdo, string $key, bool $default): bool {
    $v = strtolower(trim(s($pdo, $key, $default ? '1' : '0')));
    return in_array($v, ['1','true','yes','on'], true);
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        return ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return false;
    }
}

function employee_label(array $emp, bool $hasNickname): string {
    $first = trim((string)($emp['first_name'] ?? ''));
    $last  = trim((string)($emp['last_name'] ?? ''));
    $nick  = $hasNickname ? trim((string)($emp['nickname'] ?? '')) : '';

    $primary = $nick !== '' ? $nick : ($first !== '' ? $first : 'Staff');
    $initial = $last !== '' ? (mb_substr($last, 0, 1) . '.') : '';
    return trim($primary . ' ' . $initial);
}

function fetch_open_shifts(PDO $pdo, int $limit): array {
    $limit = max(1, min($limit, 50));
    $hasNickname = column_exists($pdo, 'kiosk_employees', 'nickname');

    if ($hasNickname) {
        $sql = "
            SELECT
                s.id            AS shift_id,
                s.employee_id   AS employee_id,
                s.clock_in_at   AS clock_in_at,
                e.first_name    AS first_name,
                e.last_name     AS last_name,
                e.nickname      AS nickname
            FROM kiosk_shifts s
            INNER JOIN kiosk_employees e ON e.id = s.employee_id
            WHERE s.is_closed = 0
              AND s.clock_out_at IS NULL
              AND e.is_active = 1
            ORDER BY s.clock_in_at DESC
            LIMIT {$limit}
        ";
    } else {
        $sql = "
            SELECT
                s.id            AS shift_id,
                s.employee_id   AS employee_id,
                s.clock_in_at   AS clock_in_at,
                e.first_name    AS first_name,
                e.last_name     AS last_name
            FROM kiosk_shifts s
            INNER JOIN kiosk_employees e ON e.id = s.employee_id
            WHERE s.is_closed = 0
              AND s.clock_out_at IS NULL
              AND e.is_active = 1
            ORDER BY s.clock_in_at DESC
            LIMIT {$limit}
        ";
    }

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];

    foreach ($rows as $r) {
        $out[] = [
            'shift_id'    => (int)$r['shift_id'],
            'employee_id' => (int)$r['employee_id'],
            'clock_in_at' => (string)$r['clock_in_at'],
            'label'       => employee_label($r, $hasNickname),
        ];
    }
    return $out;
}

function log_kiosk_event(
    PDO $pdo,
    ?string $kioskCode,
    ?int $pairingVersion,
    ?string $deviceTokenHash,
    ?string $ip,
    ?string $ua,
    ?int $employeeId,
    string $eventType,
    string $result,
    ?string $errorCode = null,
    ?string $message = null,
    ?array $meta = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO kiosk_event_log
            (occurred_at, kiosk_code, pairing_version, device_token_hash, ip_address, user_agent,
             employee_id, event_type, result, error_code, message, meta_json)
            VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null;

        $stmt->execute([
            $kioskCode ?: null,
            $pairingVersion ?: null,
            $deviceTokenHash ?: null,
            $ip ?: null,
            $ua ?: null,
            $employeeId ?: null,
            $eventType,
            $result,
            $errorCode,
            $message,
            $metaJson
        ]);
    } catch (Throwable $e) {
        // fail-safe: never break punches because logging failed
    }
}

// ---- helper: parse device_time (ISO) -> SQL datetime ----
function parse_device_time(?string $deviceTimeIso): ?string {
    if (!$deviceTimeIso) return null;
    try {
        $dt = new DateTimeImmutable($deviceTimeIso);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

// ---- helper: log punch attempt result (update row) ----
function punch_mark(PDO $pdo, string $eventUuid, string $status, ?string $errorCode = null, ?int $shiftId = null): void {
    $stmt = $pdo->prepare("
        UPDATE kiosk_punch_events
        SET result_status = ?, error_code = ?, shift_id = ?
        WHERE event_uuid = ?
        LIMIT 1
    ");
    $stmt->execute([$status, $errorCode, $shiftId, $eventUuid]);
}

try {
    // kiosk auth
    if ($kioskCode !== setting($pdo,'kiosk_code','')) {
        log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'kiosk_not_authorized');
        json_response(['ok'=>false,'error'=>'kiosk_not_authorized'], 403);
    }

    if (!setting_bool($pdo,'is_paired', false)) {
        log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'kiosk_not_paired');
        json_response(['ok'=>false,'error'=>'kiosk_not_paired'], 403);
    }

    // Device token verification (hash-first, legacy fallback)
    if ($deviceTok === '') {
        log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'device_not_authorized');
        json_response(['ok'=>false,'error'=>'device_not_authorized'], 403);
    }

    $expectedHash = (string)setting($pdo,'paired_device_token_hash','');
    if ($expectedHash !== '') {
        $calc = hash('sha256', $deviceTok);
        if (!hash_equals($expectedHash, $calc)) {
            log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'device_not_authorized');
            json_response(['ok'=>false,'error'=>'device_not_authorized'], 403);
        }
    } else {
        // Legacy: plaintext token (migrate to hash on first successful use)
        $legacy = (string)setting($pdo,'paired_device_token','');
        if ($legacy === '' || !hash_equals($legacy, $deviceTok)) {
            log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'device_not_authorized');
            json_response(['ok'=>false,'error'=>'device_not_authorized'], 403);
        }

        // migrate
        try {
            setting_set($pdo, 'paired_device_token_hash', (string)(hash('sha256', $deviceTok)));
            $pdo->prepare("DELETE FROM kiosk_settings WHERE `key`='paired_device_token'")->execute();
        } catch (Throwable $e) {
            // ignore
        }
    }

    $currentVersion = (int)setting($pdo,'pairing_version','1');
    if ($versionHdr !== $currentVersion) {
        log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'device_revoked');
        json_response(['ok'=>false,'error'=>'device_revoked'], 403);
    }

    // basic payload validation
    if ($eventUuid === '' || strlen($eventUuid) !== 36) {
        json_response(['ok'=>false,'error'=>'invalid_event_uuid'], 400);
    }

    if ($action !== 'IN' && $action !== 'OUT') {
        json_response(['ok'=>false,'error'=>'invalid_action'], 400);
    }

    // PIN format
    $pinLength = setting_int($pdo,'pin_length', 4);
    if (!valid_pin($pin, $pinLength)) {
        json_response(['ok'=>false,'error'=>'invalid_pin_format'], 400);
    }

    // idempotency
    $chk = $pdo->prepare("SELECT id FROM kiosk_punch_events WHERE event_uuid=? LIMIT 1");
    $chk->execute([$eventUuid]);
    if ($chk->fetchColumn()) {
        // keep your existing behaviour
        json_response(['ok'=>true,'status'=>'duplicate']);
    }

    // ---- Rate limiting / lockout (based on failed auth logs) ----
    $failWindowSec = s_int($pdo, 'auth_fail_window_sec', 300); // 5 min
    $failMax       = s_int($pdo, 'auth_fail_max', 5);
    $lockoutSec    = s_int($pdo, 'auth_lockout_sec', 300); // 5 min

    if ($failWindowSec > 0 && $failMax > 0 && $tokHash) {
        $failStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM kiosk_event_log
            WHERE device_token_hash = ?
              AND event_type = 'punch_auth'
              AND result = 'fail'
              AND occurred_at >= (NOW() - INTERVAL ? SECOND)
        ");
        $failStmt->execute([$tokHash, $failWindowSec]);
        $fails = (int)$failStmt->fetchColumn();

        if ($fails >= $failMax) {
            log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'locked_out', 'Too many failed attempts', [
                'fails' => $fails,
                'window_sec' => $failWindowSec
            ]);
            json_response(['ok'=>false,'error'=>'too_many_attempts'], 429);
        }
    }

    // parse device time (optional)
    $deviceTimeSql = parse_device_time($deviceTime) ?? $now;

    // employee lookup (current method; we’ll improve this later as part of your “performance fix”)
    $allowPlain = setting_bool($pdo,'allow_plain_pin', false);

    $stmt = $pdo->query("SELECT id, first_name, last_name, pin_hash FROM kiosk_employees WHERE is_active=1");
    $employee = null;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stored = (string)($r['pin_hash'] ?? '');
        if ($stored === '') continue;

        $ok = false;
        if (is_bcrypt($stored)) {
            $ok = password_verify($pin, $stored);
        } elseif ($allowPlain) {
            $ok = hash_equals($stored, $pin);
        }

        if ($ok) {
            $employee = $r;
            break;
        }
    }

    if (!$employee) {
        // Log invalid PIN attempt (since punch_events requires employee_id NOT NULL)
        log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'invalid_pin');
        json_response(['ok'=>false,'error'=>'invalid_pin'], 401);
    }

    $employeeId   = (int)$employee['id'];

    // Build display names (nickname support is future-ready)
    $hasNickname  = column_exists($pdo, 'kiosk_employees', 'nickname');
    if ($hasNickname) {
        // refetch with nickname for label
        $es = $pdo->prepare("SELECT id, first_name, last_name, nickname FROM kiosk_employees WHERE id=? LIMIT 1");
        $es->execute([$employeeId]);
        $employeeFull = $es->fetch(PDO::FETCH_ASSOC) ?: $employee;
    } else {
        $employeeFull = $employee;
    }

    $employeeName  = trim(((string)($employeeFull['first_name'] ?? '')) . ' ' . ((string)($employeeFull['last_name'] ?? '')));
    $employeeLabel = employee_label($employeeFull, $hasNickname);

    // anti-spam: min seconds between punches
    $minSeconds = setting_int($pdo,'min_seconds_between_punches', 20);
    if ($minSeconds > 0) {
        $lastStmt = $pdo->prepare("
            SELECT effective_time
            FROM kiosk_punch_events
            WHERE employee_id=?
            ORDER BY id DESC
            LIMIT 1
        ");
        $lastStmt->execute([$employeeId]);
        $last = $lastStmt->fetchColumn();

        if ($last) {
            $diffStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, ?) AS diff_sec");
            $diffStmt->execute([$last, $now]);
            $diff = (int)$diffStmt->fetchColumn();

            if ($diff >= 0 && $diff < $minSeconds) {
                // Insert first, then mark rejected (keeps your audit trail consistent)
                $pdo->prepare("
                    INSERT INTO kiosk_punch_events
                    (event_uuid, employee_id, action, device_time, received_at, effective_time,
                     result_status, error_code, kiosk_code, device_token_hash, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, 'received', NULL, ?, ?, ?, ?)
                ")->execute([$eventUuid,$employeeId,$action,$deviceTimeSql,$now,$now,$kioskCode,$tokHash,$ipAddress,$userAgent]);

                punch_mark($pdo, $eventUuid, 'rejected', 'too_soon', null);
                json_response(['ok'=>false,'error'=>'too_soon'], 429);
            }
        }
    }

    // ---- INSERT the punch attempt FIRST (always) ----
    $pdo->prepare("
        INSERT INTO kiosk_punch_events
        (event_uuid, employee_id, action, device_time, received_at, effective_time,
         result_status, error_code, kiosk_code, device_token_hash, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, 'received', NULL, ?, ?, ?, ?)
    ")->execute([
        $eventUuid,
        $employeeId,
        $action,
        $deviceTimeSql,
        $now,
        $now,
        $kioskCode,
        $tokHash,
        $ipAddress,
        $userAgent
    ]);

    // ---- PROCESS (shift logic remains same) ----
    if ($action === 'IN') {

        $open = $pdo->prepare("SELECT id FROM kiosk_shifts WHERE employee_id=? AND is_closed=0 LIMIT 1");
        $open->execute([$employeeId]);

        if ($open->fetchColumn()) {
            punch_mark($pdo, $eventUuid, 'rejected', 'already_clocked_in', null);
            json_response(['ok'=>false,'error'=>'already_clocked_in'], 409);
        }

        $pdo->prepare("INSERT INTO kiosk_shifts (employee_id, clock_in_at, is_closed) VALUES (?, ?, 0)")
            ->execute([$employeeId, $now]);

        $shiftId = (int)$pdo->lastInsertId();

        punch_mark($pdo, $eventUuid, 'processed', null, $shiftId);

        // Optional open shifts list for UI
        $uiShowOpen = s_bool($pdo, 'ui_show_open_shifts', false);
        $openCount  = s_int($pdo, 'ui_open_shifts_count', 6);
        $openList   = $uiShowOpen ? fetch_open_shifts($pdo, $openCount) : [];

        json_response([
            'ok'            => true,
            'status'        => 'processed',
            'action'        => 'IN',
            'employee_name' => $employeeName,   // backwards compatible
            'employee_label'=> $employeeLabel,  // NEW (nickname-friendly)
            'open_shifts'   => $openList        // NEW
        ]);
    }

    if ($action === 'OUT') {

        $shift = $pdo->prepare("
            SELECT id, clock_in_at
            FROM kiosk_shifts
            WHERE employee_id=? AND is_closed=0
            ORDER BY clock_in_at DESC
            LIMIT 1
        ");
        $shift->execute([$employeeId]);
        $s = $shift->fetch(PDO::FETCH_ASSOC);

        if (!$s) {
            punch_mark($pdo, $eventUuid, 'rejected', 'no_open_shift', null);
            json_response(['ok'=>false,'error'=>'no_open_shift'], 409);
        }

        $minsStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, ?) AS mins");
        $minsStmt->execute([$s['clock_in_at'], $now]);
        $mins = (int)$minsStmt->fetchColumn();

        if ($mins < 0) {
            punch_mark($pdo, $eventUuid, 'rejected', 'invalid_time_order', null);
            json_response(['ok'=>false,'error'=>'invalid_time_order'], 409);
        }

        $maxMins = setting_int($pdo,'max_shift_minutes', 960);
        if ($maxMins > 0 && $mins > $maxMins) {
            punch_mark($pdo, $eventUuid, 'rejected', 'shift_too_long_needs_review', null);
            json_response(['ok'=>false,'error'=>'shift_too_long_needs_review'], 409);
        }

        $upd = $pdo->prepare("
            UPDATE kiosk_shifts
            SET clock_out_at=?, is_closed=1, duration_minutes=?
            WHERE id=?
        ");
        $upd->execute([$now, $mins, (int)$s['id']]);

        punch_mark($pdo, $eventUuid, 'processed', null, (int)$s['id']);

        // Optional open shifts list for UI (after clock out)
        $uiShowOpen = s_bool($pdo, 'ui_show_open_shifts', false);
        $openCount  = s_int($pdo, 'ui_open_shifts_count', 6);
        $openList   = $uiShowOpen ? fetch_open_shifts($pdo, $openCount) : [];

        json_response([
            'ok'            => true,
            'status'        => 'processed',
            'action'        => 'OUT',
            'employee_name' => $employeeName,   // backwards compatible
            'employee_label'=> $employeeLabel,  // NEW
            'open_shifts'   => $openList        // NEW
        ]);
    }

    // should never reach here
    punch_mark($pdo, $eventUuid, 'rejected', 'invalid_action', null);
    json_response(['ok'=>false,'error'=>'invalid_action'], 400);

} catch (Throwable $e) {
    // if something unexpected happens, mark it as rejected server_error
    try { punch_mark($pdo, $eventUuid, 'rejected', 'server_error', null); } catch (Throwable $ignored) {}
    json_response(['ok'=>false,'error'=>'server_error'], 500);
}

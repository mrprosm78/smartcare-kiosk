<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../helpers.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// payload
$eventUuid    = (string)($input['event_uuid'] ?? '');
$action       = strtoupper((string)($input['action'] ?? ''));
$pin          = (string)($input['pin'] ?? '');
$deviceTime   = (string)($input['device_time'] ?? '');
$wasOfflineIn = (bool)($input['was_offline'] ?? false);
$sourceIn     = (string)($input['source'] ?? '');

// headers
$kioskCode  = (string)($_SERVER['HTTP_X_KIOSK_CODE'] ?? '');
$deviceTok  = (string)($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
$versionHdr = (int)($_SERVER['HTTP_X_PAIRING_VERSION'] ?? 0);

// useful request meta
$ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$tokHash   = $deviceTok !== '' ? hash('sha256', $deviceTok) : null;

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
            VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        // fail-safe
    }
}

// ---- helper: parse device_time (ISO) -> DateTimeImmutable (UTC) ----
function parse_device_time_dt(?string $deviceTimeIso): ?DateTimeImmutable {
    if (!$deviceTimeIso) return null;
    try {
        $dt = new DateTimeImmutable($deviceTimeIso);
        return $dt->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

function dt_sql(DateTimeImmutable $dt): string {
    return $dt->format('Y-m-d H:i:s');
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

/**
 * Auto-close forgotten open shifts for this employee (if older than max_shift_minutes).
 * - Silent (does not block punch)
 * - Closes at clock_in_at + max_shift_minutes (not at "now") to avoid insane durations
 */
function autoclose_stale_open_shifts(
    PDO $pdo,
    int $employeeId,
    string $effectiveTimeSql,
    int $maxShiftMinutes,
    ?string $kioskCode,
    ?int $pairingVersion,
    ?string $deviceTokenHash,
    ?string $ip,
    ?string $ua
): int {
    if ($employeeId <= 0) return 0;
    if ($maxShiftMinutes <= 0) return 0;

    $closedCount = 0;

    // lock any open shifts for this employee
    $st = $pdo->prepare("
        SELECT id, clock_in_at
        FROM kiosk_shifts
        WHERE employee_id = ?
          AND is_closed = 0
          AND clock_out_at IS NULL
        ORDER BY clock_in_at ASC
        FOR UPDATE
    ");
    $st->execute([$employeeId]);
    $open = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$open) return 0;

    foreach ($open as $s) {
        $shiftId = (int)$s['id'];
        $clockIn = (string)$s['clock_in_at'];

        // how long since clock-in?
        $minsStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, ?, ?) AS mins");
        $minsStmt->execute([$clockIn, $effectiveTimeSql]);
        $minsOpen = (int)$minsStmt->fetchColumn();

        if ($minsOpen <= $maxShiftMinutes) continue;

        // close at clock_in + maxShiftMinutes
        $closeAtStmt = $pdo->prepare("SELECT DATE_ADD(?, INTERVAL ? MINUTE) AS close_at");
        $closeAtStmt->execute([$clockIn, $maxShiftMinutes]);
        $closeAt = (string)$closeAtStmt->fetchColumn();

        $upd = $pdo->prepare("
            UPDATE kiosk_shifts
            SET clock_out_at = ?,
                is_closed = 1,
                duration_minutes = ?,
                is_autoclosed = 1,
                close_reason = 'autoclose_max',
                updated_source = 'system'
            WHERE id = ?
              AND is_closed = 0
              AND clock_out_at IS NULL
        ");
        $upd->execute([$closeAt, $maxShiftMinutes, $shiftId]);

        if ($upd->rowCount() > 0) {
            $closedCount++;

            // best-effort event log
            try {
                log_kiosk_event(
                    $pdo,
                    $kioskCode,
                    $pairingVersion,
                    $deviceTokenHash,
                    $ip,
                    $ua,
                    $employeeId,
                    'shift_autoclose',
                    'ok',
                    'autoclose_max',
                    'Auto-closed stale open shift',
                    [
                        'shift_id' => $shiftId,
                        'clock_in_at' => $clockIn,
                        'closed_at' => $closeAt,
                        'max_shift_minutes' => $maxShiftMinutes,
                        'mins_open_at_punch' => $minsOpen,
                    ]
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    return $closedCount;
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
        json_response(['ok'=>true,'status'=>'duplicate']);
    }

    // ---- Rate limiting / lockout (based on failed auth logs) ----
    $failWindowSec = s_int($pdo, 'auth_fail_window_sec', 300);
    $failMax       = s_int($pdo, 'auth_fail_max', 5);
    $lockoutSec    = s_int($pdo, 'auth_lockout_sec', 300); // not used yet, keep

    if ($failWindowSec > 0 && $failMax > 0 && $tokHash) {
        $failStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM kiosk_event_log
            WHERE device_token_hash = ?
              AND event_type = 'punch_auth'
              AND result = 'fail'
              AND occurred_at >= (UTC_TIMESTAMP() - INTERVAL ? SECOND)
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
    $serverNowDt = new DateTimeImmutable($now, new DateTimeZone('UTC'));
    $deviceDt    = parse_device_time_dt($deviceTime);

    // Normalise source
    $source = strtolower(trim($sourceIn));
    if ($source !== '' && strlen($source) > 20) $source = substr($source, 0, 20);
    if ($source === '') $source = $wasOfflineIn ? 'offline_sync' : 'online';

    $wasOffline = $wasOfflineIn || ($source === 'offline_sync');

    // Device time is optional for online punches, required for offline sync punches
    if ($wasOffline && !$deviceDt) {
        json_response(['ok'=>false,'error'=>'invalid_device_time'], 400);
    }

    // Store raw device time if present
    $deviceTimeSql = $deviceDt ? dt_sql($deviceDt) : null;

    // Effective time: server time for online; validated device time for offline sync
    $effectiveDt = $serverNowDt;
    if ($wasOffline && $deviceDt) {
        $maxBackMins   = s_int($pdo, 'offline_max_backdate_minutes', 2880);
        $maxFutureSecs = s_int($pdo, 'offline_max_future_seconds', 120);

        $minAllowed = $serverNowDt->sub(new DateInterval('PT' . max(0, $maxBackMins) . 'M'));
        $maxAllowed = $serverNowDt->add(new DateInterval('PT' . max(0, $maxFutureSecs) . 'S'));

        if ($deviceDt < $minAllowed) {
            $effectiveDt = $minAllowed;
        } elseif ($deviceDt > $maxAllowed) {
            $effectiveDt = $maxAllowed;
        } else {
            $effectiveDt = $deviceDt;
        }
    }

    $effectiveTimeSql = dt_sql($effectiveDt);

    // employee lookup
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
        log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, null, 'punch_auth', 'fail', 'invalid_pin');
        json_response(['ok'=>false,'error'=>'invalid_pin'], 401);
    }

    $employeeId = (int)$employee['id'];

    // Build display names (nickname support is future-ready)
    $hasNickname  = column_exists($pdo, 'kiosk_employees', 'nickname');
    if ($hasNickname) {
        $es = $pdo->prepare("SELECT id, first_name, last_name, nickname FROM kiosk_employees WHERE id=? LIMIT 1");
        $es->execute([$employeeId]);
        $employeeFull = $es->fetch(PDO::FETCH_ASSOC) ?: $employee;
    } else {
        $employeeFull = $employee;
    }

    $employeeName  = trim(((string)($employeeFull['first_name'] ?? '')) . ' ' . ((string)($employeeFull['last_name'] ?? '')));
    $employeeLabel = employee_label($employeeFull, $hasNickname);

    // Log device/server time mismatch (audit)
    if ($deviceDt) {
        $threshold = s_int($pdo, 'offline_time_mismatch_log_sec', 300);
        $delta = abs($deviceDt->getTimestamp() - $serverNowDt->getTimestamp());
        if ($threshold > 0 && $delta > $threshold) {
            log_kiosk_event($pdo, $kioskCode, $versionHdr, $tokHash, $ipAddress, $userAgent, $employeeId, 'time_mismatch', 'info', 'device_time_skew', 'Device time differs from server', [
                'delta_seconds'   => $delta,
                'device_time_iso' => (string)$deviceTime,
                'device_time_utc' => $deviceTimeSql,
                'server_time_utc' => $now,
                'effective_time'  => $effectiveTimeSql,
                'was_offline'     => $wasOffline ? 1 : 0,
                'source'          => $source,
            ]);
        }
    }

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
            $diffStmt->execute([$last, $effectiveTimeSql]);
            $diff = (int)$diffStmt->fetchColumn();

            if ($diff >= 0 && $diff < $minSeconds) {
                $pdo->prepare("
                    INSERT INTO kiosk_punch_events
                    (event_uuid, employee_id, action, device_time, received_at, effective_time,
                     result_status, error_code, source, was_offline, kiosk_code, device_token_hash, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, 'received', NULL, ?, ?, ?, ?, ?, ?)
                ")->execute([$eventUuid,$employeeId,$action,$deviceTimeSql,$now,$effectiveTimeSql,$source,(int)$wasOffline,$kioskCode,$tokHash,$ipAddress,$userAgent]);

                punch_mark($pdo, $eventUuid, 'rejected', 'too_soon', null);
                json_response(['ok'=>false,'error'=>'too_soon'], 429);
            }
        }
    }

    // ------------------------------------------------------------------
    // ✅ SILENT HOUSEKEEPING: auto-close forgotten clock-outs (per setting)
    // Run AFTER employee is known. IMPORTANT: only run for IN punches,
    // otherwise an OUT punch could incorrectly become 'no_open_shift'.
    // ------------------------------------------------------------------
    if ($action === 'IN') {
        $maxShiftMins = setting_int($pdo, 'max_shift_minutes', 960);
        try {
            $pdo->beginTransaction();
            $closed = autoclose_stale_open_shifts(
                $pdo,
                $employeeId,
                $effectiveTimeSql,
                $maxShiftMins,
                $kioskCode,
                $versionHdr,
                $tokHash,
                $ipAddress,
                $userAgent
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // ignore housekeeping errors
        }
    }

    // ---- INSERT the punch attempt FIRST (always) ----
    $pdo->prepare("
        INSERT INTO kiosk_punch_events
        (event_uuid, employee_id, action, device_time, received_at, effective_time,
         result_status, error_code, source, was_offline, kiosk_code, device_token_hash, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, 'received', NULL, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $eventUuid,
        $employeeId,
        $action,
        $deviceTimeSql,
        $now,
        $effectiveTimeSql,
        $source,
        (int)$wasOffline,
        $kioskCode,
        $tokHash,
        $ipAddress,
        $userAgent
    ]);

    // ---- PROCESS ----
    if ($action === 'IN') {

        $open = $pdo->prepare("SELECT id FROM kiosk_shifts WHERE employee_id=? AND is_closed=0 LIMIT 1");
        $open->execute([$employeeId]);

        if ($open->fetchColumn()) {
            punch_mark($pdo, $eventUuid, 'rejected', 'already_clocked_in', null);
            json_response(['ok'=>false,'error'=>'already_clocked_in'], 409);
        }

        $pdo->prepare("INSERT INTO kiosk_shifts (employee_id, clock_in_at, is_closed) VALUES (?, ?, 0)")
            ->execute([$employeeId, $effectiveTimeSql]);

        $shiftId = (int)$pdo->lastInsertId();

        punch_mark($pdo, $eventUuid, 'processed', null, $shiftId);

        // Optional open shifts list for UI
        $uiShowOpen = s_bool($pdo, 'ui_show_open_shifts', false);
        $openCount  = s_int($pdo, 'ui_open_shifts_count', 6);
        $openList   = $uiShowOpen ? fetch_open_shifts($pdo, $openCount) : [];

        json_response([
            'ok'             => true,
            'status'         => 'processed',
            'action'         => 'IN',
            'employee_name'  => $employeeName,
            'employee_label' => $employeeLabel,
            'open_shifts'    => $openList
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
        $minsStmt->execute([$s['clock_in_at'], $effectiveTimeSql]);
        $mins = (int)$minsStmt->fetchColumn();

        if ($mins < 0) {
            punch_mark($pdo, $eventUuid, 'rejected', 'invalid_time_order', null);
            json_response(['ok'=>false,'error'=>'invalid_time_order'], 409);
        }

        $maxMins = setting_int($pdo,'max_shift_minutes', 960);
        $tooLong = ($maxMins > 0 && $mins > $maxMins);

        $hasCloseReason = column_exists($pdo, 'kiosk_shifts', 'close_reason');

        if ($tooLong && $hasCloseReason) {
            $upd = $pdo->prepare("
                UPDATE kiosk_shifts
                SET clock_out_at=?, is_closed=1, duration_minutes=?, close_reason='too_long'
                WHERE id=?
            ");
            $upd->execute([$effectiveTimeSql, $mins, (int)$s['id']]);

            punch_mark($pdo, $eventUuid, 'processed', 'shift_too_long_flagged', (int)$s['id']);
        } else {
            $upd = $pdo->prepare("
                UPDATE kiosk_shifts
                SET clock_out_at=?, is_closed=1, duration_minutes=?
                WHERE id=?
            ");
            $upd->execute([$effectiveTimeSql, $mins, (int)$s['id']]);

            punch_mark($pdo, $eventUuid, 'processed', null, (int)$s['id']);
        }

        // Optional open shifts list for UI (after clock out)
        $uiShowOpen = s_bool($pdo, 'ui_show_open_shifts', false);
        $openCount  = s_int($pdo, 'ui_open_shifts_count', 6);
        $openList   = $uiShowOpen ? fetch_open_shifts($pdo, $openCount) : [];

        $resp = [
            'ok'             => true,
            'status'         => 'processed',
            'action'         => 'OUT',
            'employee_name'  => $employeeName,
            'employee_label' => $employeeLabel,
            'open_shifts'    => $openList
        ];

        if ($tooLong) {
            $resp['warning'] = 'shift_too_long_flagged';
        }

        json_response($resp);
    }

    punch_mark($pdo, $eventUuid, 'rejected', 'invalid_action', null);
    json_response(['ok'=>false,'error'=>'invalid_action'], 400);

} catch (Throwable $e) {
    try { punch_mark($pdo, $eventUuid, 'rejected', 'server_error', null); } catch (Throwable $ignored) {}
    json_response(['ok'=>false,'error'=>'server_error'], 500);
}
<?php
// seedpunch.php
// Seeds ONE month of kiosk_punch_events for testing (2 employees).
// Matches your schema: kiosk_punch_events(action, device_time, received_at, effective_time, ...)
//
// Usage:
//   - Put this file in project root (same folder as db.php)
//   - Run in browser: /seedpunch.php
//   - Or CLI: php seedpunch.php
//
// It will seed January 2026 by default.

require_once __DIR__ . '/db.php';

$year  = 2026;
$month = 1; // January

// Fetch first 2 employees
$stmt = $pdo->query("SELECT id, first_name, last_name FROM kiosk_employees ORDER BY id ASC LIMIT 2");
$emps = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$emps || count($emps) < 2) {
    die("Need at least 2 kiosk employees. Run seedemp.php first.\n");
}

// Basic insert helper (minimal required fields)
$ins = $pdo->prepare("
  INSERT INTO kiosk_punch_events
    (event_uuid, employee_id, action, device_time, received_at, effective_time, result_status, source, kiosk_code, device_token_hash, ip_address, user_agent, was_offline)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

function addPunch($ins, $empId, $action, $dt, $kioskCode, $deviceHash, $offline=0) {
    // event_uuid can be NULL, but we generate a UUID for clarity.
    $eventUuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $deviceTime = $dt;
    $receivedAt = $dt;       // for seed, treat received_at == device_time
    $effective  = $dt;       // source of truth for shift builder
    $result     = 'OK';
    $source     = 'seed';
    $ip         = '127.0.0.1';
    $ua         = 'seedpunch.php';

    $ins->execute([
        $eventUuid,
        $empId,
        $action,
        $deviceTime,
        $receivedAt,
        $effective,
        $result,
        $source,
        $kioskCode,
        $deviceHash,
        $ip,
        $ua,
        (int)$offline
    ]);
}

function dt($y,$m,$d,$h,$min=0,$s=0) {
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y,$m,$d,$h,$min,$s);
}

// Use deterministic kiosk identity
$kioskCode   = 'KIOSK-SEED-01';
$deviceHash  = hash('sha256', 'seed-device-token');

echo "Seeding punch events for {$year}-{$month} for 2 employees...\n";

// Seed patterns per employee (slightly different so you can compare)
$patterns = [
  // Employee 1: heavier hours + weekend crossing midnight + missing OUT
  [
    // Week 1
    [5,  9, 0, 17, 0],  // Mon day shift
    [6,  9, 0, 17, 0],  // Tue day shift
    [7, 22, 0,  6, 0, true], // Wed night -> Thu morning
    [9, 20, 0,  4, 0, true], // Fri -> Sat weekend split at midnight
    [11,22, 0,  6, 0, true], // Sun -> Mon (BH test if configured)
    // Week 2
    [13, 9, 0, 17, 0],
    [14, 9, 0, 17, 0],
    // Week 3: missing OUT (manager fixes)
    [19, 9, 0, null, null],
    // Week 4: weekend day
    [24, 8, 0, 16, 0],
    // End month day shift
    [30, 9, 0, 17, 0],
  ],
  // Employee 2: lighter hours + clean punches + one weekend day
  [
    [5, 10, 0, 16, 0], // Mon
    [8,  9, 0, 15, 0], // Thu
    [15, 9, 0, 17, 0], // Thu
    [17, 8, 0, 14, 0], // Sat (weekend)
    [22,22, 0,  6, 0, true], // Night shift
    [28, 9, 0, 17, 0], // Wed
  ]
];

foreach ($emps as $idx => $emp) {
    $empId = (int)$emp['id'];
    echo "- Employee {$empId} ({$emp['first_name']} {$emp['last_name']})\n";

    foreach ($patterns[$idx] as $p) {
        // p = [day, inH, inM, outH, outM, crossesDay?]
        $day = $p[0]; $inH = $p[1]; $inM = $p[2]; $outH = $p[3]; $outM = $p[4];
        $cross = isset($p[5]) ? (bool)$p[5] : false;

        // IN punch
        addPunch($ins, $empId, 'IN', dt($year,$month,$day,$inH,$inM,0), $kioskCode, $deviceHash, 0);

        // OUT punch (if provided)
        if ($outH !== null) {
            // If crossing to next day
            if ($cross && $outH < $inH) {
                // next day
                $outDay = $day + 1;
                addPunch($ins, $empId, 'OUT', dt($year,$month,$outDay,$outH,$outM,0), $kioskCode, $deviceHash, 0);
            } else {
                addPunch($ins, $empId, 'OUT', dt($year,$month,$day,$outH,$outM,0), $kioskCode, $deviceHash, 0);
            }
        }
    }
}

echo "Done. Now go to Manager > Shifts to review, fix missing OUT on Jan 19 for employee 1, add training minutes, and approve.\n";

<?php
declare(strict_types=1);

/**
 * admin/puchdetais.php
 *
 * Features:
 * - Seed test punches for last month + this month for EMP-001/002/003 (employee_id 1..3)
 * - View punches for an employee within a date range
 *
 * Assumptions:
 * - db.php defines $pdo (PDO)
 * - Punches table: kiosk_punches(employee_id INT, punched_at DATETIME, punch_type VARCHAR)
 *   where punch_type is 'in' or 'out'
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "DB not available. Ensure db.php defines \$pdo (PDO).";
    exit;
}

date_default_timezone_set('Europe/London');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function firstDayOfMonth(DateTimeImmutable $d): DateTimeImmutable {
    return $d->modify('first day of this month')->setTime(0, 0, 0);
}

function lastDayOfMonth(DateTimeImmutable $d): DateTimeImmutable {
    return $d->modify('last day of this month')->setTime(23, 59, 59);
}

function addPunch(PDO $pdo, int $empId, DateTimeImmutable $dt, string $type): void {
    $sql = "INSERT INTO kiosk_punches (employee_id, punched_at, punch_type)
            VALUES (:eid, :ts, :type)";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':eid'  => $empId,
        ':ts'   => $dt->format('Y-m-d H:i:s'),
        ':type' => $type,
    ]);
}

function addShift(PDO $pdo, int $empId, DateTimeImmutable $start, DateTimeImmutable $end): void {
    addPunch($pdo, $empId, $start, 'in');
    addPunch($pdo, $empId, $end, 'out');
}

function seedForEmployee(PDO $pdo, int $empId, DateTimeImmutable $lastMonthStart, DateTimeImmutable $thisMonthStart): array {
    $inserted = 0;

    // Last month: 10 day-shifts from month start (09:00–17:00)
    $d = $lastMonthStart;
    for ($i = 0; $i < 10; $i++) {
        addShift($pdo, $empId, $d->setTime(9,0,0), $d->setTime(17,0,0));
        $inserted += 2;
        $d = $d->modify('+1 day');
    }

    // Last month night shift (20:00 → 06:00 next day)
    $nightStart = $lastMonthStart->modify('+11 days')->setTime(20,0,0);
    $nightEnd   = $nightStart->modify('+10 hours'); // to 06:00 next day
    addShift($pdo, $empId, $nightStart, $nightEnd);
    $inserted += 2;

    // Last month weekend shift (10:00–14:00 on the first Sunday on/after day 13)
    $w = $lastMonthStart->modify('+13 days');
    while ((int)$w->format('w') !== 0) { // Sunday = 0
        $w = $w->modify('+1 day');
    }
    addShift($pdo, $empId, $w->setTime(10,0,0), $w->setTime(14,0,0));
    $inserted += 2;

    // This month: 8 day-shifts from month start (09:00–17:00)
    $d = $thisMonthStart;
    for ($i = 0; $i < 8; $i++) {
        addShift($pdo, $empId, $d->setTime(9,0,0), $d->setTime(17,0,0));
        $inserted += 2;
        $d = $d->modify('+1 day');
    }

    // This month call-out (22:00–23:00 on the 10th)
    $calloutDay = $thisMonthStart->setDate(
        (int)$thisMonthStart->format('Y'),
        (int)$thisMonthStart->format('m'),
        10
    );
    addShift($pdo, $empId, $calloutDay->setTime(22,0,0), $calloutDay->setTime(23,0,0));
    $inserted += 2;

    // This month long night (19:00 → 07:00 next day on the 15th)
    $night2Day = $thisMonthStart->setDate(
        (int)$thisMonthStart->format('Y'),
        (int)$thisMonthStart->format('m'),
        15
    );
    $n2s = $night2Day->setTime(19,0,0);
    $n2e = $n2s->modify('+12 hours'); // 07:00 next day
    addShift($pdo, $empId, $n2s, $n2e);
    $inserted += 2;

    return ['employee_id' => $empId, 'punches_inserted' => $inserted];
}

function deleteSeedRange(PDO $pdo, DateTimeImmutable $from, DateTimeImmutable $to, array $empIds): int {
    // NOTE: This deletes ALL punches in the date range for these employee_ids
    $in = implode(',', array_fill(0, count($empIds), '?'));
    $sql = "DELETE FROM kiosk_punches
            WHERE employee_id IN ($in)
              AND punched_at >= ?
              AND punched_at <= ?";
    $st = $pdo->prepare($sql);
    $params = array_merge($empIds, [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
    $st->execute($params);
    return $st->rowCount();
}

$action = (string)($_GET['action'] ?? '');
$now = new DateTimeImmutable('now');

$thisMonthStart = firstDayOfMonth($now);
$lastMonthStart = firstDayOfMonth($now->modify('-1 month'));

$fromDefault = $lastMonthStart;
$toDefault   = lastDayOfMonth($now);

$notice = '';
$error = '';

try {
    if ($action === 'seed') {
        // Basic safety: require confirm=YES
        if (($_GET['confirm'] ?? '') !== 'YES') {
            $error = "To seed punches, add &confirm=YES to the URL.";
        } else {
            $pdo->beginTransaction();
            $results = [];
            foreach ([1,2,3] as $empId) {
                $results[] = seedForEmployee($pdo, $empId, $lastMonthStart, $thisMonthStart);
            }
            $pdo->commit();
            $notice = "Seeded punches OK: " . json_encode($results);
        }
    }

    if ($action === 'clear_seed') {
        if (($_GET['confirm'] ?? '') !== 'YES') {
            $error = "To clear punches, add &confirm=YES to the URL.";
        } else {
            $deleted = deleteSeedRange($pdo, $fromDefault, $toDefault, [1,2,3]);
            $notice = "Deleted $deleted punches for employees 1,2,3 between "
                . $fromDefault->format('Y-m-d') . " and " . $toDefault->format('Y-m-d') . ".";
        }
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
}

// View punches
$employeeId = (int)($_GET['employee_id'] ?? 1);
$from = new DateTimeImmutable((string)($_GET['from'] ?? $fromDefault->format('Y-m-d')));
$to   = new DateTimeImmutable((string)($_GET['to']   ?? $toDefault->format('Y-m-d')));
$to = $to->setTime(23,59,59);

$rows = [];
try {
    $st = $pdo->prepare("
        SELECT id, employee_id, punched_at, punch_type
        FROM kiosk_punches
        WHERE employee_id = :eid
          AND punched_at >= :from
          AND punched_at <= :to
        ORDER BY punched_at ASC
        LIMIT 2000
    ");
    $st->execute([
        ':eid'  => $employeeId,
        ':from' => $from->format('Y-m-d H:i:s'),
        ':to'   => $to->format('Y-m-d H:i:s'),
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Punch Details (Testing)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 20px; }
    .box { padding: 12px 14px; border: 1px solid #ddd; border-radius: 10px; margin-bottom: 14px; }
    .ok { background: #f0fff4; border-color: #b7ebc6; }
    .err { background: #fff5f5; border-color: #f5c2c2; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border-bottom: 1px solid #eee; padding: 8px; text-align: left; }
    code { background:#f6f8fa; padding:2px 4px; border-radius: 6px; }
    .btn { display:inline-block; padding:8px 10px; border:1px solid #ccc; border-radius:8px; text-decoration:none; color:#111; }
    .btn.danger { border-color:#e0a3a3; }
  </style>
</head>
<body>

<h2>Punch Details (Testing)</h2>

<?php if ($notice): ?>
  <div class="box ok"><?=h($notice)?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="box err"><strong>Error:</strong> <?=h($error)?></div>
<?php endif; ?>

<div class="box">
  <h3>Seed test punches</h3>
  <p>This inserts punches for <code>employee_id 1,2,3</code> for:</p>
  <ul>
    <li>Last month (from <?=h($lastMonthStart->format('Y-m-d'))?>)</li>
    <li>This month (from <?=h($thisMonthStart->format('Y-m-d'))?>)</li>
  </ul>
  <p>
    <a class="btn" href="?action=seed&confirm=YES">Seed punches now</a>
    <a class="btn danger" href="?action=clear_seed&confirm=YES">Clear punches (range)</a>
  </p>
  <p style="opacity:.8">Clear will delete all punches for employees 1,2,3 between <?=h($fromDefault->format('Y-m-d'))?> and <?=h($toDefault->format('Y-m-d'))?>.</p>
</div>

<div class="box">
  <h3>View punches</h3>
  <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
    <div>
      <label>employee_id<br>
        <input type="number" name="employee_id" value="<?=h((string)$employeeId)?>" min="1" style="padding:6px;">
      </label>
    </div>
    <div>
      <label>from (YYYY-MM-DD)<br>
        <input type="text" name="from" value="<?=h($from->format('Y-m-d'))?>" style="padding:6px;">
      </label>
    </div>
    <div>
      <label>to (YYYY-MM-DD)<br>
        <input type="text" name="to" value="<?=h($to->format('Y-m-d'))?>" style="padding:6px;">
      </label>
    </div>
    <div>
      <button type="submit" style="padding:8px 10px;">Load</button>
    </div>
  </form>
</div>

<div class="box">
  <h3>Results (<?=count($rows)?>)</h3>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>employee_id</th>
        <th>punched_at</th>
        <th>type</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?=h((string)$r['id'])?></td>
        <td><?=h((string)$r['employee_id'])?></td>
        <td><?=h((string)$r['punched_at'])?></td>
        <td><?=h((string)$r['punch_type'])?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

</body>
</html>

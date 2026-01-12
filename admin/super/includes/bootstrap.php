<?php
declare(strict_types=1);

// Super Admin bootstrap (wraps the main admin bootstrap)

require_once dirname(__DIR__) . '/includes/bootstrap.php'; // ../includes/bootstrap.php

function super_require_login(PDO $pdo): array {
  $u = admin_current_user($pdo);
  if (!$u) {
    header('Location: ../login.php');
    exit;
  }
  if ((string)$u['role'] !== 'superadmin') {
    http_response_code(403);
    exit('Forbidden');
  }
  return $u;
}

function super_nav_item(string $href, string $label, string $current): string {
  $active = ($href === $current);
  $base = 'rounded-2xl px-3 py-2 text-sm font-semibold transition-colors';
  if ($active) {
    return '<a href="' . h($href) . '" class="' . $base . ' bg-white text-slate-900">' . h($label) . '</a>';
  }
  return '<a href="' . h($href) . '" class="' . $base . ' bg-white/5 border border-white/10 text-white/80 hover:bg-white/10">' . h($label) . '</a>';
}

function super_page_start(string $title, array $user, string $current): void {
  global $pdo;
  $v = h(admin_ui_version($pdo));
  echo '<!doctype html><html lang="en"><head>';
  echo '<meta charset="utf-8" />';
  echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover" />';
  echo '<meta name="theme-color" content="#0f172a" />';
  echo '<title>' . h($title) . '</title>';
  echo '<link rel="stylesheet" href="../../assets/kiosk.css?v=' . $v . '">';
  echo '<style>html,body{height:100%} .min-h-dvh{min-height:100dvh}</style>';
  echo '</head><body class="bg-slate-950 text-white min-h-dvh">';

  echo '<div class="min-h-dvh flex flex-col">';
  echo '<header class="px-4 sm:px-6 pt-6 pb-4">';
  echo '<div class="mx-auto max-w-7xl flex items-center justify-between gap-4">';
  echo '<div class="flex items-center gap-3">';
  echo '<div class="h-11 w-11 rounded-2xl bg-white/10 flex items-center justify-center">';
  echo '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" class="opacity-90">';
  echo '<path d="M12 8v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
  echo '<path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="2"/>';
  echo '</svg></div>';
  echo '<div><div class="text-sm sm:text-base font-semibold leading-tight">Care Home Digital Time Clock</div>';
  echo '<div class="text-xs sm:text-sm text-white/60 leading-tight">Super Admin</div></div>';
  echo '</div>';

  echo '<div class="flex items-center gap-3">';
  echo '<span class="hidden sm:inline text-sm text-white/60">' . h((string)($user['name'] ?: $user['email'])) . '</span>';
  echo '<span class="rounded-full bg-amber-400/15 px-3 py-1 text-xs font-semibold text-amber-100 border border-amber-300/20">SUPERADMIN</span>';
  echo '<a href="../dashboard.php" class="rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-sm font-semibold text-white/80 hover:bg-white/10">Admin</a>';
  echo '<a href="../logout.php" class="rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-sm font-semibold text-white/80 hover:bg-white/10">Logout</a>';
  echo '</div>';

  echo '</div>';

  echo '<div class="mx-auto max-w-7xl mt-4 flex flex-wrap items-center gap-2">';
  echo super_nav_item('./dashboard.php', 'Overview', $current);
  echo super_nav_item('./calendar.php', '60‑Day Calendar', $current);
  echo super_nav_item('./targets.php', 'Targets', $current);
  echo super_nav_item('./settings.php', 'Settings', $current);
  echo '</div>';
  echo '</header>';

  $ok = admin_flash_get('ok');
  $err = admin_flash_get('err');
  if ($ok || $err) {
    echo '<div class="px-4 sm:px-6"><div class="mx-auto max-w-7xl">';
    if ($ok) {
      echo '<div class="mb-4 rounded-2xl bg-emerald-500/10 border border-emerald-400/20 p-4 text-sm text-emerald-100">' . h($ok) . '</div>';
    }
    if ($err) {
      echo '<div class="mb-4 rounded-2xl bg-rose-500/10 border border-rose-400/20 p-4 text-sm text-rose-200">' . h($err) . '</div>';
    }
    echo '</div></div>';
  }

  echo '<main class="flex-1 px-4 sm:px-6 pb-10"><div class="mx-auto max-w-7xl">';
}

function super_page_end(): void {
  echo '</div></main>';
  echo '<footer class="px-4 pb-6 text-center text-xs text-white/40">SmartCare • Super Admin</footer>';
  echo '</div></body></html>';
}

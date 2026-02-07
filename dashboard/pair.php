<?php
declare(strict_types=1);

// Device pairing flow is de-scoped for this build.
require_once __DIR__ . '/layout.php';
admin_require_perm($user, 'manage_settings_basic');

header('Location: ' . admin_url('settings.php'));
exit;

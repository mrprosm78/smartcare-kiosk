<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

admin_logout($pdo);
admin_redirect(admin_url('login.php'));

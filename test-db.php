<?php
require __DIR__ . '/db.php';

echo "DB OK";
echo password_hash('1234', PASSWORD_BCRYPT);
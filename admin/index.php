<?php
// Legacy /admin entrypoint.
// The admin portal has moved to /dashboard.
header('Location: ../dashboard/', true, 302);
exit;

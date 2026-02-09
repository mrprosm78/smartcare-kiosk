<?php
// Legacy /admin router. Redirect everything to /dashboard.
header('Location: ../dashboard/', true, 302);
exit;

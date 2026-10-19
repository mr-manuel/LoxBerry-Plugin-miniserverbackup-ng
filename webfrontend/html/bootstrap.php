<?php
// CLI jobs may start without the LBHOMEDIR environment variable.
$msbackup_base = getenv('LBHOMEDIR');
if (!$msbackup_base) {
    $msbackup_account = posix_getpwnam('loxberry');
    $msbackup_base = $msbackup_account['dir'] ?? '/opt/loxberry';
}
set_include_path($msbackup_base . '/libs/phplib' . PATH_SEPARATOR . get_include_path());
require_once 'loxberry_system.php';
require_once 'loxberry_log.php';

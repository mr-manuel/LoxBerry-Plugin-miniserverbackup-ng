<?php
// Return free work-directory space in KiB, matching the settings page.
header('Content-Type: text/plain; charset=utf-8');
$path = trim((string) @file_get_contents('/tmp/msb_free_space'));
$free = false;
if ($path !== '') {
    $candidate = is_dir($path) ? $path : dirname($path);
    if (is_dir($candidate)) {
        $free = @disk_free_space($candidate);
    }
}
echo $free === false ? '?' : (string) floor($free / 1024);

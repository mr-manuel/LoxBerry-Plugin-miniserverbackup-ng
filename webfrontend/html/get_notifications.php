<?php
# Get notifications in html format 
# Quick and dirty
require_once __DIR__ . '/bootstrap.php';
require_once LBHOMEDIR . '/libs/phplib/loxberry_web.php';
$cmd = "/usr/bin/perl  -I ".$lbshtmlauthdir." ".$lbshtmlauthdir."/tools/get_notifications.cgi ".$lbpplugindir." 2>&1";
passthru($cmd);
exit;

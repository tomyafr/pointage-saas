<?php
require_once __DIR__ . '/../includes/config.php';
startSecureSession();
session_unset();
session_destroy();

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}
setcookie('APP_SESSION_BACKUP', '', time() - 3600, '/', '', true, true);

header('Location: index.php');
exit;

<?php
require_once __DIR__ . '/../includes/config.php';
startSecureSession();
session_destroy();
setcookie('APP_SESSION_BACKUP', '', time() - 3600, '/');
header('Location: index.php');
exit;

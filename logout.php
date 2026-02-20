<?php
require_once __DIR__ . '/includes/config.php';
startSecureSession();
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;

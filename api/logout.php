<?php
require_once __DIR__ . '/../includes/config.php';
startSecureSession();

// Nettoyage complet
session_unset();
session_destroy();

// Suppression des cookies
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}
setcookie('APP_SESSION_BACKUP', '', time() - 3600, '/', '', true, true);

// Fermeture de session pour forcer l'écriture
session_write_close();

// Redirection forcée avec paramètre clear
header('Location: index.php?logout=1');
exit;

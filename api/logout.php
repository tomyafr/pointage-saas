<?php
require_once __DIR__ . '/../includes/config.php';
startSecureSession();

// Log de la déconnexion avant de détruire la session
if (isset($_SESSION['user_id'])) {
    logAudit('LOGOUT', "User: " . ($_SESSION['user_nom'] ?? 'unknown'));
}

// Nettoyage complet de la session
$_SESSION = array();

// Supprimer le cookie de session côté navigateur
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Détruire la session côté serveur
session_destroy();

// Supprimer le cookie de secours (APP_SESSION_BACKUP)
setcookie('APP_SESSION_BACKUP', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);

// Empêcher le cache de la page
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Redirection vers la page de connexion
header('Location: index.php');
exit;

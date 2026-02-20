<?php
// ============================================
// CONFIGURATION - À adapter selon votre hébergement Hostinger
// ============================================

// Base de données
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'pointage_saas');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Microsoft Business Central - Configuration API
define('BC_TENANT_ID', getenv('BC_TENANT_ID') ?: 'votre-tenant-id');
define('BC_CLIENT_ID', getenv('BC_CLIENT_ID') ?: 'votre-client-id');
define('BC_CLIENT_SECRET', getenv('BC_CLIENT_SECRET') ?: 'votre-client-secret');
define('BC_COMPANY_ID', getenv('BC_COMPANY_ID') ?: 'votre-company-id');
define('BC_ENV', getenv('BC_ENV') ?: 'production');

define('BC_BASE_URL', "https://api.businesscentral.dynamics.com/v2.0/" . BC_TENANT_ID . "/" . BC_ENV . "/api/v2.0");
define('BC_TOKEN_URL', 'https://login.microsoftonline.com/' . BC_TENANT_ID . '/oauth2/v2.0/token');
define('BC_SCOPE', 'https://api.businesscentral.dynamics.com/.default');

// Application
define('APP_NAME', 'Pointage Atelier');
define('APP_VERSION', '1.0.0');
define('SESSION_TIMEOUT', 28800); // 8 heures

// Timezone
date_default_timezone_set('Europe/Paris');

// Connexion PDO
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            die('Erreur de connexion à la base de données.');
        }
    }
    return $pdo;
}

// Démarrer la session sécurisée
function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.use_strict_mode', 1);
        session_start();
    }
}

// Vérifier l'authentification
function requireAuth($role = null) {
    startSecureSession();
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
    if ($role && $_SESSION['role'] !== $role) {
        header('Location: index.php');
        exit;
    }
}

// Obtenir la semaine courante (lundi à dimanche)
function getCurrentWeekDates() {
    $today = new DateTime();
    $dayOfWeek = (int)$today->format('N'); // 1=lundi, 7=dimanche
    $monday = clone $today;
    $monday->modify('-' . ($dayOfWeek - 1) . ' days');
    $sunday = clone $monday;
    $sunday->modify('+6 days');
    
    $dates = [];
    $current = clone $monday;
    while ($current <= $sunday) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }
    
    return [
        'monday' => $monday->format('Y-m-d'),
        'sunday' => $sunday->format('Y-m-d'),
        'dates' => $dates,
        'labels' => ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'],
    ];
}

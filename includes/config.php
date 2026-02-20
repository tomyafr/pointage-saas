<?php
// Debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Connexion PDO (PostgreSQL pour Vercel)
function getDB()
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            // 1. Tenter de récupérer les variables individuelles (Vercel ou Neon)
            $host = getenv('POSTGRES_HOST') ?: getenv('PGHOST');
            $db = getenv('POSTGRES_DATABASE') ?: getenv('PGDATABASE');
            $user = getenv('POSTGRES_USER') ?: getenv('PGUSER');
            $pass = getenv('POSTGRES_PASSWORD') ?: getenv('PGPASSWORD');

            if ($host) {
                $dsn = "pgsql:host=$host;port=5432;dbname=$db;sslmode=require";
            } else {
                // 2. Repli sur DATABASE_URL si les variables individuelles sont absentes
                $dbUrl = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL');
                if ($dbUrl) {
                    $dsn = str_replace('postgres://', 'pgsql:', $dbUrl);
                    // Nettoyage si format URL
                    if (strpos($dsn, 'pgsql:') === 0) {
                        $parts = parse_url($dbUrl);
                        $host = $parts['host'];
                        $db = ltrim($parts['path'], '/');
                        $user = $parts['user'];
                        $pass = $parts['pass'];
                        $dsn = "pgsql:host=$host;port=5432;dbname=$db;sslmode=require";
                    }
                } else {
                    die('Erreur : Variables de base de données (POSTGRES_HOST ou DATABASE_URL) non trouvées.');
                }
            }

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            die('Erreur de connexion à la base de données PostgreSQL : ' . $e->getMessage());
        }
    }
    return $pdo;
}

// Démarrer la session sécurisée
function startSecureSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Restaurer depuis le cookie si la session PHP est vide (important pour Vercel)
    if (!isset($_SESSION['user_id']) && isset($_COOKIE['APP_SESSION_BACKUP'])) {
        $data = json_decode(base64_decode($_COOKIE['APP_SESSION_BACKUP']), true);
        if ($data && isset($data['user_id'])) {
            $_SESSION['user_id'] = $data['user_id'];
            $_SESSION['user_nom'] = $data['user_nom'];
            $_SESSION['user_prenom'] = $data['user_prenom'];
            $_SESSION['role'] = $data['role'];
        }
    }
}

// Enregistrer les infos en cookie de secours
function setSessionBackup()
{
    $data = base64_encode(json_encode([
        'user_id' => $_SESSION['user_id'] ?? '',
        'user_nom' => $_SESSION['user_nom'] ?? '',
        'user_prenom' => $_SESSION['user_prenom'] ?? '',
        'role' => $_SESSION['role'] ?? ''
    ]));
    setcookie('APP_SESSION_BACKUP', $data, time() + 3600 * 24, '/', '', false, true);
}

// Vérifier l'authentification
function requireAuth($role = null)
{
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
function getCurrentWeekDates()
{
    $today = new DateTime();
    $dayOfWeek = (int) $today->format('N'); // 1=lundi, 7=dimanche
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

<?php
require_once __DIR__ . '/../includes/config.php';

// Traitement de la déconnexion (avant tout démarrage de session)
if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    $_SESSION = array();
    session_destroy();

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    setcookie('APP_SESSION_BACKUP', '', time() - 3600, '/', '', true, true);
    header('Location: index.php');
    exit;
}

startSecureSession();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'];

    // 1. Protection Brute Force
    $stmt = $db->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?');
    $stmt->execute([$ip]);
    $throttle = $stmt->fetch();

    if ($throttle && $throttle['attempts'] >= 5) {
        $last = strtotime($throttle['last_attempt']);
        if (time() - $last < 900) {
            $error = "Trop de tentatives. Bloqué pendant 15 min.";
        } else {
            $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
            $throttle = null;
        }
    }

    if (!$error) {
        $nom = strtoupper(trim($_POST['nom'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($nom) || empty($password)) {
            $error = 'Veuillez remplir tous les champs.';
        } else {
            $stmt = $db->prepare('SELECT id, nom, prenom, password_hash, role FROM users WHERE nom = ? AND actif IS TRUE');
            $stmt->execute([$nom]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nom'] = $user['nom'];
                $_SESSION['user_prenom'] = $user['prenom'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                setSessionBackup();

                logAudit('LOGIN_SUCCESS', "User: $nom");
                session_write_close();

                header('Location: ' . ($user['role'] === 'chef' ? 'chef.php' : 'operator.php'));
                exit;
            } else {
                if ($throttle) {
                    $db->prepare('UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE ip_address = ?')->execute([$ip]);
                } else {
                    $db->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)')->execute([$ip]);
                }

                logAudit('LOGIN_FAILED', "IP: $ip, Identifiant: $nom");
                $error = "Accès refusé. Vérifiez vos identifiants.";
            }
        }
    }
}

// Est-on déjà connecté ?
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Connexion | Raoul Lenoir — Pointage Industriel</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>

<body class="bg-main">
    <div class="login-page">
        <!-- Logo -->
        <div class="login-header animate-in">
            <div class="brand-icon" style="width: 220px; height: auto; margin: 0 auto 1.5rem auto;">
                <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir">
            </div>
            <h1 class="login-title" style="color: #ffb300;">Raoul Lenoir</h1>
            <p class="login-subtitle">Système de Pointage Industriel</p>
        </div>

        <?php if ($isLoggedIn): ?>
            <!-- Welcome Screen -->
            <div class="welcome-screen animate-in">
                <h2 class="welcome-title">Bienvenue, <?= htmlspecialchars($_SESSION['user_prenom']) ?></h2>
                <p class="welcome-text">Session active ·
                    <?= $_SESSION['role'] === 'chef' ? 'Administrateur' : 'Opérateur' ?>
                </p>

                <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="btn btn-primary"
                    style="margin-top: 1.5rem; width: 100%; text-decoration: none; justify-content: center;">
                    ACCÉDER AU DASHBOARD →
                </a>

                <a href="?logout=1" class="btn btn-ghost"
                    style="margin-top: 1rem; width: 100%; opacity: 0.7; text-decoration: none; justify-content: center; font-size: 0.8rem;">
                    Changer de compte
                </a>
            </div>
        <?php else: ?>
            <!-- Login Form -->
            <form method="POST" class="login-card glass animate-in">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <span>⚠</span>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="nom" class="label">Identifiant (NOM)</label>
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" name="nom" id="nom" class="input" placeholder="EX: LOTITO" required
                            autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password" class="label">Mot de passe</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" name="password" id="password" class="input" placeholder="••••••••" required
                            autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword">👁</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary login-btn">
                    Connexion Sécurisée →
                </button>

                <!-- Mode Démo désactivé pour la production -->
            </form>
        <?php endif; ?>

        <div class="login-features animate-in-delay-2">
            <div class="feature-item">
                <span class="feature-icon">🛡️</span>
                <span>Accès Sécurisé</span>
            </div>
            <div class="feature-item">
                <span class="feature-icon">📱</span>
                <span>Mobile First</span>
            </div>
            <div class="feature-item">
                <span class="feature-icon">⚡</span>
                <span>Sync BC</span>
            </div>
        </div>

        <div class="login-footer">
            V<?= APP_VERSION ?> · RAOUL LENOIR SAS · <?= date('Y') ?>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        if (togglePassword && password) {
            togglePassword.addEventListener('click', function (e) {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.textContent = type === 'password' ? '👁' : '🔒';
            });
        }

        // Auto-uppercase username
        const nomInput = document.getElementById('nom');
        if (nomInput) {
            nomInput.addEventListener('input', function () {
                this.value = this.value.toUpperCase();
            });
        }
    </script>
    <script src="/assets/notifications.js"></script>
</body>

</html>
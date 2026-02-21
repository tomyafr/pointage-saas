<?php
require_once __DIR__ . '/../includes/config.php';

// Traitement de la déconnexion
if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
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

// REDIRECTION AUTOMATIQUE SI DÉJÀ CONNECTÉ
if (isset($_SESSION['user_id'])) {
    $target = ($_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php');
    header('Location: ' . $target);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'];

    // Protection Brute Force
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Connexion | Raoul Lenoir</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="bg-main">
    <!-- Fond animé Premium -->
    <div class="bg-particles">
        <div class="particle" style="left: 10%; width: 2px; height: 2px; animation-duration: 15s; animation-delay: 0s;"></div>
        <div class="particle" style="left: 30%; width: 3px; height: 3px; animation-duration: 20s; animation-delay: 2s;"></div>
        <div class="particle" style="left: 50%; width: 2px; height: 2px; animation-duration: 18s; animation-delay: 5s;"></div>
        <div class="particle" style="left: 70%; width: 4px; height: 4px; animation-duration: 25s; animation-delay: 1s;"></div>
        <div class="particle" style="left: 90%; width: 2px; height: 2px; animation-duration: 22s; animation-delay: 8s;"></div>
    </div>

    <div class="login-page">
        <!-- Logo -->
        <div class="login-header animate-in">
            <div class="brand-icon" style="width: 280px; height: auto; margin: 0 auto 1.5rem auto;">
                <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir" style="filter: brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
            </div>
            <h1 class="login-title" style="color: #ffb300;">Raoul Lenoir</h1>
            <p class="login-subtitle">Système de Pointage Industriel</p>
        </div>

        <form method="POST" class="login-card glass animate-in" autocomplete="off">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span>⚠</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="nom" class="label">Identifiant</label>
                <div class="input-wrapper">
                    <span class="input-icon">👤</span>
                    <input type="text" name="nom" id="nom" class="input" placeholder="Votre identifiant" required autocomplete="off" spellcheck="false">
                    <button type="button" class="input-clear" id="resetNom">✕</button>
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="label">Mot de passe</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password" id="password" class="input" placeholder="••••••••" required autocomplete="new-password">
                    <button type="button" class="password-toggle" id="togglePassword">👁</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary login-btn" style="width: 100%;">
                Connexion Sécurisée →
            </button>
        </form>

        <div class="login-footer animate-in-delay-2">
            V<?= APP_VERSION ?> · RAOUL LENOIR SAS · <?= date('Y') ?>
        </div>
    </div>

    <script>
        // Toggle password
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        if (togglePassword && password) {
            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                this.textContent = type === 'password' ? '👁' : '🔒';
            });
        }

        // Reset username field
        const resetNom = document.getElementById('resetNom');
        const nomInput = document.getElementById('nom');
        if (resetNom && nomInput) {
            resetNom.addEventListener('click', () => {
                nomInput.value = '';
                nomInput.focus();
            });
            nomInput.addEventListener('input', function () {
                this.value = this.value.toUpperCase();
            });
        }
    </script>
    <script src="/assets/notifications.js"></script>
</body>
</html>
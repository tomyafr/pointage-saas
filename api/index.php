<?php
require_once __DIR__ . '/../includes/config.php';

// Traitement de la déconnexion
if (isset($_GET['logout'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
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
    // Vérification CSRF
    verifyCsrfToken();

    $db = getDB();
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    // Ne garder que la première IP si plusieurs (proxy)
    $ip = trim(explode(',', $ip)[0]);

    // Protection Brute Force
    $stmt = $db->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?');
    $stmt->execute([$ip]);
    $throttle = $stmt->fetch();

    if ($throttle && $throttle['attempts'] >= 5) {
        $last = strtotime($throttle['last_attempt']);
        if (time() - $last < 900) { // 15 minutes
            $minutesLeft = ceil((900 - (time() - $last)) / 60);
            $error = "Trop de tentatives échouées. Réessayez dans {$minutesLeft} minute(s).";
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
            // Pas d'énumération : message identique que le compte existe ou non
            $stmt = $db->prepare('SELECT id, nom, prenom, password_hash, role, must_change_password FROM users WHERE nom = ? AND actif IS TRUE');
            $stmt->execute([$nom]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Connexion réussie : supprimer les tentatives et régénérer l'ID de session
                $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);

                // Régénérer l'ID de session pour prévenir la fixation de session
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nom'] = $user['nom'];
                $_SESSION['user_prenom'] = $user['prenom'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                $_SESSION['must_change_password'] = !empty($user['must_change_password']);
                setSessionBackup();

                logAudit('LOGIN_SUCCESS', "User: $nom, IP: $ip");
                session_write_close();

                // Redirection selon rôle (ou vers profil si changement de MDP obligatoire)
                if (!empty($user['must_change_password'])) {
                    header('Location: profile.php?force=1');
                } else {
                    header('Location: ' . ($user['role'] === 'chef' ? 'chef.php' : 'operator.php'));
                }
                exit;
            } else {
                // Échec : incrémenter les tentatives
                if ($throttle) {
                    $db->prepare('UPDATE login_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE ip_address = ?')->execute([$ip]);
                } else {
                    $db->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)')->execute([$ip]);
                }
                logAudit('LOGIN_FAILED', "IP: $ip, Identifiant: $nom");
                // Délai artificiel pour ralentir les attaques bruteforce (0.5s)
                usleep(500000);
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
    <!-- Vidéo Background Premium -->
    <div class="video-background">
        <div class="video-overlay"></div>
        <video autoplay muted loop playsinline id="bgVideo">
            <source src="/assets/video-magnet.mp4" type="video/mp4">
        </video>
    </div>

    <div class="login-page">
        <!-- Logo -->
        <div class="login-header animate-in">
            <div class="brand-icon" style="width: 280px; height: auto; margin: 0 auto 1.5rem auto;">
                <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir"
                    style="filter: brightness(0) saturate(100%) invert(73%) sepia(86%) saturate(1063%) hue-rotate(358deg) brightness(101%) contrast(106%);">
            </div>
            <h1 class="login-title" style="color: #ffb300;">Raoul Lenoir</h1>
            <p class="login-subtitle">Système de Pointage Industriel</p>
        </div>

        <form method="POST" class="login-card glass animate-in" autocomplete="off">
            <?= csrfField() ?>

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
                    <input type="text" name="nom" id="nom" class="input" placeholder="Votre identifiant" required
                        autocomplete="off" spellcheck="false" maxlength="100">
                    <button type="button" class="input-clear" id="resetNom">✕</button>
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="label">Mot de passe</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password" id="password" class="input" placeholder="••••••••" required
                        autocomplete="new-password" maxlength="128">
                    <button type="button" class="password-toggle" id="togglePassword">👁</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary login-btn" style="width: 100%;">
                Connexion Sécurisée →
            </button>
        </form>

        <div class="login-footer animate-in-delay-2">
            RAOUL LENOIR SAS · <?= date('Y') ?>
        </div>
    </div>

    <script>
        // Toggle password visibility
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
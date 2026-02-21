<?php
require_once __DIR__ . '/../includes/config.php';
startSecureSession();

$error = '';

// Traitement de la déconnexion
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    setcookie('APP_SESSION_BACKUP', '', time() - 3600, '/', '', true, true);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();

    // Suppression du mode démo pour la production

    $nom = strtoupper(trim($_POST['nom'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($nom) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $stmt = $db->prepare('SELECT id, nom, prenom, password_hash, role FROM users WHERE nom = ? AND actif IS TRUE');
        $stmt->execute([$nom]);
        $user = $stmt->fetch();

        if ($user) {
            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nom'] = $user['nom'];
                $_SESSION['user_prenom'] = $user['prenom'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['login_time'] = time();
                setSessionBackup();
                session_write_close();

                header('Location: ' . ($user['role'] === 'chef' ? 'chef.php' : 'operator.php'));
                exit;
            } else {
                $error = "Accès refusé. Vérifiez vos identifiants.";
            }
        } else {
            $error = "Utilisateur inconnu.";
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
    <meta name="description"
        content="Système de pointage intelligent pour Raoul Lenoir — Saisie des heures par Ordre de Fabrication">
    <title>Connexion | Raoul Lenoir — Pointage Industriel</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    <meta name="theme-color" content="#020617">
    <style>
        .demo-access {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--glass-border);
        }

        .demo-label {
            text-align: center;
            font-size: 0.7rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin-bottom: 1rem;
        }

        .demo-chips {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }

        .login-features {
            display: flex;
            gap: 2rem;
            justify-content: center;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .login-feature {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-dim);
        }

        .login-feature-icon {
            width: 28px;
            height: 28px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }

        .input-icon-wrapper {
            position: relative;
        }

        .input-icon-wrapper .input {
            padding-left: 3rem;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.1rem;
            opacity: 0.4;
            pointer-events: none;
        }
    </style>
</head>

<body class="login-page">
    <div class="login-card animate-in">
        <div class="login-header">
            <div class="brand-icon"><img src="assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir"></div>
            <h1 class="login-title"><span class="text-gradient">Raoul Lenoir</span></h1>
            <p class="login-subtitle">Système de Pointage Industriel</p>
        </div>

        <?php if ($isLoggedIn): ?>
            <div class="card glass text-center animate-in-delay-1">
                <p style="color: var(--primary); margin-bottom: 0.5rem; font-size: 1.2rem; font-weight: 700;">
                    Bienvenue, <?= htmlspecialchars($_SESSION['user_prenom']) ?>
                </p>
                <p style="color: var(--text-dim); margin-bottom: 2rem; font-size: 0.85rem;">
                    Session active · <?= ucfirst($_SESSION['role']) ?>
                </p>
                <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="btn btn-primary"
                    style="width: 100%; margin-bottom: 1rem; height: 3.5rem; font-size: 1rem;">
                    Accéder au Dashboard →
                </a>
                <a href="index.php?logout=1"
                    style="color: var(--text-dim); font-size: 0.8rem; text-decoration: none; transition: var(--transition-fast);">
                    Changer de compte
                </a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-error animate-in">
                    <span>⚠</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" class="card glass animate-in-delay-1">
                <div class="form-group">
                    <label class="label" for="nom">Identifiant</label>
                    <div class="input-icon-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" id="nom" name="nom" class="input" placeholder="NOM DE FAMILLE"
                            autocapitalize="characters" autocomplete="username" required
                            value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="label" for="password">Mot de passe</label>
                    <div class="input-icon-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="password" name="password" class="input"
                            style="padding-left: 3rem; padding-right: 3.5rem;" placeholder="••••••••"
                            autocomplete="current-password" required>
                        <button type="button" id="togglePassword"
                            style="position:absolute; right:1rem; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:1.1rem; transition: var(--transition-fast);"
                            aria-label="Afficher/masquer le mot de passe">
                            👁
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; height: 3.5rem; font-size: 0.95rem;">
                    Connexion Sécurisée →
                </button>

                <!-- Mode Démo désactivé pour la production -->
            </form>

            <div class="login-features animate-in-delay-2">
                <div class="login-feature">
                    <div class="login-feature-icon">🔐</div>
                    <span>Chiffrement BCrypt</span>
                </div>
                <div class="login-feature">
                    <div class="login-feature-icon">☁️</div>
                    <span>Cloud Sécurisé</span>
                </div>
                <div class="login-feature">
                    <div class="login-feature-icon">📱</div>
                    <span>PWA Mobile</span>
                </div>
            </div>
        <?php endif; ?>

        <p class="animate-in-delay-3"
            style="text-align:center; color: var(--text-dim); font-size: 0.7rem; margin-top: 2.5rem; font-family: var(--font-mono); letter-spacing: 1px;">
            V<?= APP_VERSION ?> · <a href="https://www.118712.fr/professionnels/X0dXWVBRGgI" target="_blank"
                rel="noopener" style="color: var(--text-dim); text-decoration: none;">RAOUL LENOIR SAS</a> ·
            <?= date('Y') ?>
        </p>
    </div>

    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        if (togglePassword) {
            togglePassword.addEventListener('click', function () {
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
    <script src="assets/notifications.js"></script>
</body>

</html>
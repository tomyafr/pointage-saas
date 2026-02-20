<?php
require_once __DIR__ . '/../includes/config.php';
startSecureSession();

$error = '';

// Traitement de la déconnexion
if (isset($_GET['logout'])) {
    session_destroy();
    setcookie('APP_SESSION_BACKUP', '', time() - 3600, '/');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();

    // Mode Démo / Accès Rapide
    if (isset($_POST['demo_user'])) {
        $nom = strtoupper(trim($_POST['demo_user']));
        $stmt = $db->prepare('SELECT id, nom, prenom, role FROM users WHERE nom = ? AND actif IS TRUE');
        $stmt->execute([$nom]);
        $user = $stmt->fetch();

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_prenom'] = $user['prenom'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            setSessionBackup();
            session_write_close();

            header('Location: ' . ($user['role'] === 'chef' ? 'chef.php' : 'operator.php'));
            exit;
        }
    }

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
    <title>Connexion | Raoul Lenoir Pointage</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .demo-access {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--glass-border);
        }

        .demo-label {
            text-align: center;
            font-size: 0.75rem;
            color: var(--text-dim);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 1rem;
        }

        .demo-chips {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }
    </style>
</head>

<body class="login-page">
    <div class="login-card animate-in">
        <div class="login-header">
            <div class="brand-icon">🧲</div>
            <h1 class="login-title"><span class="text-gradient">Raoul Lenoir</span></h1>
            <p class="login-subtitle">Système de Pointage Intelligent</p>
        </div>

        <?php if ($isLoggedIn): ?>
            <div class="card glass text-center">
                <p style="color: var(--primary); margin-bottom: 1.5rem; font-size: 1.1rem;">
                    Bienvenue, <b><?= htmlspecialchars($_SESSION['user_prenom']) ?></b>
                </p>
                <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="btn btn-primary"
                    style="width: 100%; margin-bottom: 1rem;">
                    Accéder au Dashboard
                </a>
                <a href="index.php?logout=1" class="text-dim" style="font-size: 0.85rem; text-decoration: none;">Changer de
                    compte</a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-error animate-in">
                    <span>⚠</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" class="card glass">
                <div class="form-group">
                    <label class="label" for="nom">Identifiant</label>
                    <input type="text" id="nom" name="nom" class="input" placeholder="NOM DE FAMILLE"
                        autocapitalize="characters" autocomplete="username" required
                        value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="label" for="password">Mot de passe</label>
                    <div style="position:relative;">
                        <input type="password" id="password" name="password" class="input" placeholder="••••••••"
                            autocomplete="current-password" required>
                        <button type="button" id="togglePassword"
                            style="position:absolute; right:1rem; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:1.2rem;">
                            👁
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    Connexion Sécurisée
                </button>

                <div class="demo-access">
                    <p class="demo-label">Accès Rapide Industriel</p>
                    <div class="demo-chips">
                        <button type="submit" name="demo_user" value="DUPONT" class="btn btn-ghost"
                            style="padding: 0.75rem; font-size: 0.7rem;" formnovalidate>Opérateur</button>
                        <button type="submit" name="demo_user" value="MARTIN" class="btn btn-ghost"
                            style="padding: 0.75rem; font-size: 0.7rem;" formnovalidate>Test</button>
                        <button type="submit" name="demo_user" value="ADMIN" class="btn btn-ghost"
                            style="padding: 0.75rem; font-size: 0.7rem;" formnovalidate>Admin</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <p
            style="text-align:center; color: var(--text-dim); font-size: 0.8rem; margin-top: 2rem; font-family: var(--font-mono); letter-spacing: 1px;">
            VER. <?= APP_VERSION ?> · RAOUL LENOIR SAS · <?= date('Y') ?>
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
    </script>
</body>

</html>
<?php
require_once __DIR__ . '/../includes/config.php';
startSecureSession();

$error = '';

// Traitement de la déconnexion
if (isset($_GET['logout'])) {
    session_destroy();
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
                session_write_close();

                header('Location: ' . ($user['role'] === 'chef' ? 'chef.php' : 'operator.php'));
                exit;
            } else {
                $error = "Le mot de passe saisi est incorrect.";
            }
        } else {
            $error = "L'identifiant '" . htmlspecialchars($nom) . "' n'existe pas.";
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
    <title>Connexion - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .demo-section {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .demo-title {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 16px;
        }

        .demo-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .demo-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-muted);
            padding: 10px 4px;
            border-radius: 8px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            text-transform: uppercase;
            font-weight: 600;
        }

        .demo-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: #00f2ff;
            color: white;
        }

        .logged-in-card {
            background: rgba(0, 242, 255, 0.1);
            border: 1px solid #00f2ff;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-icon">⏱</div>
            <h1 class="login-title"><?= APP_NAME ?></h1>
            <p class="login-subtitle">Saisie des heures par OF</p>
        </div>

        <?php if ($isLoggedIn): ?>
            <div class="logged-in-card">
                <p style="color: #00f2ff; margin-bottom: 15px;">Session active :
                    <b><?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?></b></p>
                <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="btn btn-primary"
                    style="background: #00f2ff; color: #000; text-decoration: none; display: block; margin-bottom: 10px;">
                    ACCÉDER AU TABLEAU DE BORD
                </a>
                <a href="index.php?logout=1" style="color: var(--text-muted); font-size: 0.8rem;">Se déconnecter</a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="card">
                    <div class="form-group">
                        <label class="form-label" for="nom">Nom de famille</label>
                        <input type="text" id="nom" name="nom" class="form-input" placeholder="Ex: DUPONT"
                            autocapitalize="characters" autocomplete="username" required
                            value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Mot de passe</label>
                        <div style="position:relative;">
                            <input type="password" id="password" name="password" class="form-input" placeholder="••••••••"
                                autocomplete="current-password" required>
                            <button type="button" id="togglePassword"
                                style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:1.2rem; padding:4px;">
                                👁
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary"
                        style="background: #00f2ff; color: #000; font-weight: 700;">
                        → SE CONNECTER
                    </button>

                    <div class="demo-section">
                        <p class="demo-title">Accès Rapide Démo</p>
                        <div class="demo-grid">
                            <button type="submit" name="demo_user" value="DUPONT" class="demo-btn"
                                formnovalidate>Opérateur</button>
                            <button type="submit" name="demo_user" value="MARTIN" class="demo-btn"
                                formnovalidate>Test</button>
                            <button type="submit" name="demo_user" value="ADMIN" class="demo-btn"
                                formnovalidate>Admin</button>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <script>
            const togglePassword = document.querySelector('#togglePassword');
            const password = document.querySelector('#password');
            if (togglePassword) {
                togglePassword.addEventListener('click', function (e) {
                    const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                    password.setAttribute('type', type);
                    this.textContent = type === 'password' ? '👁' : '🔒';
                });
            }
        </script>

        <p
            style="text-align:center; color: var(--text-muted); font-size: 0.75rem; margin-top: 24px; font-family: var(--font-mono);">
            v<?= APP_VERSION ?> · <?= date('Y') ?>
        </p>
    </div>
</body>

</html>
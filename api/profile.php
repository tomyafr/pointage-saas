<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(); // Force la connexion

$message = '';
$messageType = '';
$userId = $_SESSION['user_id'];
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && password_verify($oldPass, $user['password_hash'])) {
            if (strlen($newPass) < 6) {
                $message = "Le nouveau mot de passe est trop court (min 6 caractères).";
                $messageType = "error";
            } elseif ($newPass !== $confirmPass) {
                $message = "Les nouveaux mots de passe ne correspondent pas.";
                $messageType = "error";
            } else {
                $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                $update = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $update->execute([$newHash, $userId]);

                logAudit('PASSWORD_CHANGE', "User ID: $userId");
                $message = "Mot de passe mis à jour avec succès.";
                $messageType = "success";
            }
        } else {
            $message = "L'ancien mot de passe est incorrect.";
            $messageType = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mon Profil | Raoul Lenoir</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .password-toggle {
            background: none;
            border: none;
            color: var(--text-dim);
            padding: 0.5rem;
            cursor: pointer;
            font-size: 1rem;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }
    </style>
</head>

<body>
    <!-- Vidéo Background Ambiance (Fumée) -->
    <div class="video-smoke-background">
        <video autoplay muted loop playsinline id="bgVideoSmoke">
            <source src="/assets/video-fumee-blanche-faible.mp4" type="video/mp4">
        </video>
    </div>

    <button class="mobile-menu-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <div class="dashboard-layout">
        <aside class="sidebar" id="sidebar">
            <div style="margin-bottom: 2.5rem;">
                <!-- Logo cliquable vers le dashboard -->
                <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="brand-icon"
                    style="display: block; width: 180px; height: auto; margin: 0 0 1rem 0;">
                    <img src="/assets/logo-raoul-lenoir.svg" alt="Raoul Lenoir">
                </a>
                <h2 style="font-size: 1.15rem;"><span class="text-gradient">Raoul Lenoir</span></h2>
                <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Mon Profil</p>
            </div>

            <nav style="display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 2rem;">
                <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="btn btn-ghost"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <span>🏠</span> Tableau de Bord
                </a>
                <a href="profile.php" class="btn btn-primary"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <span>👤</span> Mon Profil
                </a>
            </nav>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p
                    style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; margin-bottom: 0.4rem;">
                    Connecté</p>
                <p style="font-weight: 600; font-size: 0.85rem;">
                    <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                </p>
                <a href="logout.php" class="btn btn-ghost"
                    style="width: 100%; margin-top: 1rem; color: var(--error); border-color: rgba(244, 63, 94, 0.15); font-size: 0.75rem; padding: 0.6rem;">
                    Se déconnecter
                </a>
            </div>
        </aside>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> animate-in">
                    <span><?= $messageType === 'success' ? '✓' : '⚠' ?></span>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <div class="card glass animate-in">
                <div style="margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--glass-border);">
                    <h3 style="font-size: 1.3rem; margin-bottom: 0.5rem;">Informations personnelles</h3>
                    <p style="color: var(--text-dim); font-size: 0.85rem;">Gérez vos accès et vos paramètres de
                        sécurité.</p>
                </div>

                <div
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;">
                    <div>
                        <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Nom Complet</p>
                        <p style="font-size: 1.1rem; font-weight: 700; color: var(--text-main);">
                            <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                        </p>
                    </div>
                    <div>
                        <p style="font-size: 0.7rem; color: var(--text-dim); text-transform: uppercase;">Rôle</p>
                        <p style="font-size: 1.1rem; font-weight: 700; color: var(--primary);">
                            <?= $_SESSION['role'] === 'chef' ? 'Administrateur' : 'Opérateur' ?>
                        </p>
                    </div>
                </div>

                <form method="POST" class="glass"
                    style="padding: 2rem; border-radius: var(--radius-md); background: rgba(255,255,255,0.02);">
                    <input type="hidden" name="action" value="change_password">

                    <h4 style="margin-bottom: 1.5rem; color: var(--primary);">🔒 Changer le mot de passe</h4>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label class="label">Ancien mot de passe</label>
                        <div class="input-wrapper">
                            <input type="password" name="old_password" class="input p-password" required
                                placeholder="Votre mot de passe actuel" style="flex: 1;">
                            <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="form-group">
                            <label class="label">Nouveau mot de passe</label>
                            <div class="input-wrapper">
                                <input type="password" name="new_password" class="input p-password" required
                                    placeholder="Min. 6 car." style="flex: 1;">
                                <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="label">Confirmation</label>
                            <div class="input-wrapper">
                                <input type="password" name="confirm_password" class="input p-password" required
                                    placeholder="Répéter" style="flex: 1;">
                                <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        Mettre à jour mon mot de passe
                    </button>
                </form>
            </div>

            <div class="app-footer">
                Raoul Lenoir SAS · <a href="privacy.php" style="color: inherit; text-decoration: underline;">RGPD &
                    Confidentialité</a> · V<?= APP_VERSION ?>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
        }
        function togglePass(btn) {
            const input = btn.parentElement.querySelector('input');
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            btn.textContent = type === 'password' ? '👁' : '🔒';
        }
    </script>
</body>

</html>
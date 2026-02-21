<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth();

$message = '';
$messageType = '';
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'change_password') {
        $old_pass = $_POST['old_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';

        $db = getDB();
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if ($user && password_verify($old_pass, $user['password_hash'])) {
            if (strlen($new_pass) < 6) {
                $message = "Le mot de passe doit faire au moins 6 caractères.";
                $messageType = "error";
            } elseif ($new_pass !== $confirm_pass) {
                $message = "Les nouveaux mots de passe ne correspondent pas.";
                $messageType = "error";
            } else {
                $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $stmt->execute([$new_hash, $user_id]);

                logAudit('PASSWORD_CHANGE', "User ID: $user_id");
                $message = "Mot de passe mis à jour avec succès.";
                $messageType = "success";
            }
        } else {
            $message = "L'ancien mot de passe est incorrect.";
            $messageType = "error";
        }
    }
}

$pageTitle = "Mon Profil | Raoul Lenoir";
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= $pageTitle ?>
    </title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body class="bg-main">
    <div class="layout">
        <aside class="sidebar glass" id="sidebar">
            <div class="sidebar-header">
                <div class="logo-container">
                    <img src="https://www.lenoir-mec.com/wp-content/uploads/2023/12/logo-lenoir-mec-blanc.svg"
                        alt="Raoul Lenoir">
                </div>
            </div>

            <nav class="nav-menu">
                <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="nav-item">
                    <span>🏠</span> Tableau de bord
                </a>
                <a href="profile.php" class="nav-item active">
                    <span>👤</span> Mon Profil
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="logout.php" class="btn btn-ghost" style="width: 100%; color: var(--error);">
                    Se déconnecter
                </a>
            </div>
        </aside>

        <main class="main-content">
            <div class="card glass animate-in">
                <h2 style="margin-bottom: 2rem;">👤 Mon Profil</h2>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?>" style="margin-bottom: 2rem;">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <div style="margin-bottom: 2rem; padding-bottom: 2rem; border-bottom: 1px solid var(--glass-border);">
                    <p style="color: var(--text-dim); font-size: 0.9rem;">Utilisateur :</p>
                    <p style="font-size: 1.2rem; font-weight: 700;">
                        <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                    </p>
                    <p style="font-size: 0.8rem; color: var(--primary); text-transform: uppercase; margin-top: 0.2rem;">
                        Rôle :
                        <?= $_SESSION['role'] === 'chef' ? 'Administrateur' : 'Opérateur' ?>
                    </p>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="change_password">

                    <h3 style="font-size: 1rem; margin-bottom: 1.5rem;">🔒 Modifier le mot de passe</h3>

                    <div class="form-group" style="margin-bottom: 1.5rem;">
                        <label class="label">Ancien mot de passe</label>
                        <input type="password" name="old_password" class="input" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                        <div class="form-group">
                            <label class="label">Nouveau mot de passe</label>
                            <input type="password" name="new_password" class="input" required>
                        </div>
                        <div class="form-group">
                            <label class="label">Confirmer</label>
                            <input type="password" name="confirm_password" class="input" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        Mettre à jour le mot de passe
                    </button>
                </form>
            </div>
        </main>
    </div>
</body>

</html>
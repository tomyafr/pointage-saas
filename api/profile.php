<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth(); // Force la connexion

$message = '';
$messageType = '';
$userId = $_SESSION['user_id'];
$db = getDB();

// Forcer le changement si flag actif
$forceChange = !empty($_GET['force']) || !empty($_SESSION['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Vérification CSRF
    verifyCsrfToken();

    if ($_POST['action'] === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user && password_verify($oldPass, $user['password_hash'])) {
            // Vérifier la politique de mot de passe forte
            $policyErrors = validatePassword($newPass);
            if (!empty($policyErrors)) {
                $message = implode('<br>', $policyErrors);
                $messageType = "error";
            } elseif ($newPass !== $confirmPass) {
                $message = "Les nouveaux mots de passe ne correspondent pas.";
                $messageType = "error";
            } elseif ($newPass === $oldPass) {
                $message = "Le nouveau mot de passe doit être différent de l'ancien.";
                $messageType = "error";
            } else {
                $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $update = $db->prepare('UPDATE users SET password_hash = ?, must_change_password = FALSE WHERE id = ?');
                $update->execute([$newHash, $userId]);

                // Supprimer le flag de changement obligatoire
                $_SESSION['must_change_password'] = false;

                logAudit('PASSWORD_CHANGE', "User ID: $userId");
                $message = "✓ Mot de passe mis à jour avec succès.";
                $messageType = "success";
                $forceChange = false;
            }
        } else {
            $message = "L'ancien mot de passe est incorrect.";
            $messageType = "error";
        }
    } elseif ($_POST['action'] === 'update_avatar') {
        $base64 = $_POST['avatar_base64'] ?? '';
        if (!empty($base64)) {
            $stmt = $db->prepare('UPDATE users SET avatar_base64 = ? WHERE id = ?');
            $stmt->execute([$base64, $userId]);
            $_SESSION['avatar'] = $base64;
            $message = "✓ Photo de profil mise à jour avec succès.";
            $messageType = "success";
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

        /* Indicateur de force du mot de passe */
        .strength-bar-container {
            margin-top: 0.5rem;
            height: 4px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 4px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease, background 0.3s ease;
            width: 0%;
        }

        .strength-label {
            font-size: 0.65rem;
            margin-top: 0.3rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .strength-0 {
            background: transparent;
        }

        .strength-1 {
            background: #ef4444;
            width: 25%;
        }

        .strength-2 {
            background: #f97316;
            width: 50%;
        }

        .strength-3 {
            background: #eab308;
            width: 75%;
        }

        .strength-4 {
            background: #22c55e;
            width: 100%;
        }

        .policy-list {
            margin: 0.75rem 0 0 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .policy-list li {
            font-size: 0.7rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: color 0.2s;
        }

        .policy-list li .check {
            font-size: 0.75rem;
        }

        .policy-list li.ok {
            color: #22c55e;
        }

        .policy-list li.fail {
            color: var(--error);
        }

        .force-banner {
            margin-bottom: 2rem;
            padding: 1.25rem 1.5rem;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: var(--radius-md);
            color: #fca5a5;
            font-size: 0.9rem;
        }

        .force-banner strong {
            color: #ef4444;
        }
    </style>
</head>

<body>
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
                <?php if (!$forceChange): ?>
                    <a href="<?= $_SESSION['role'] === 'chef' ? 'chef.php' : 'operator.php' ?>" class="btn btn-ghost"
                        style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                        <span>🏠</span> Tableau de Bord
                    </a>
                <?php endif; ?>
                <a href="profile.php" class="btn btn-primary"
                    style="justify-content: flex-start; padding: 0.7rem 1.1rem; font-size: 0.8rem; text-decoration: none; color: inherit;">
                    <span>👤</span> Mon Profil
                </a>
            </nav>

            <div style="margin-top: auto; padding-top: 1.5rem; border-top: 1px solid var(--glass-border);">
                <p
                    style="font-size: 0.65rem; color: var(--text-dim); text-transform: uppercase; margin-bottom: 0.75rem;">
                    Connecté</p>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <?php if (!empty($_SESSION['avatar'])): ?>
                        <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                            style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);">
                    <?php else: ?>
                        <div
                            style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #000; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.8rem;">
                            <?= strtoupper(substr($_SESSION['user_prenom'], 0, 1) . substr($_SESSION['user_nom'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <p
                        style="font-weight: 600; font-size: 0.85rem; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px;">
                        <?= htmlspecialchars($_SESSION['user_prenom'] . ' ' . $_SESSION['user_nom']) ?>
                    </p>
                </div>
                <?php if (!$forceChange): ?>
                    <a href="logout.php" class="btn btn-ghost"
                        style="width: 100%; margin-top: 1rem; color: var(--error); border-color: rgba(244, 63, 94, 0.15); font-size: 0.75rem; padding: 0.6rem;">
                        Se déconnecter
                    </a>
                <?php endif; ?>
            </div>
        </aside>

        <main class="main-content">
            <?php if ($forceChange && $messageType !== 'success'): ?>
                <div class="force-banner">
                    <strong>⚠ Changement de mot de passe obligatoire</strong><br>
                    Pour des raisons de sécurité, vous devez définir un nouveau mot de passe avant de continuer.
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?> animate-in">
                    <span><?= $messageType === 'success' ? '✓' : '⚠' ?></span>
                    <span><?= $message /* Contient potentiellement des <br> de la politique */ ?></span>
                </div>
            <?php endif; ?>

            <div class="card glass animate-in">
                <div style="margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--glass-border);">
                    <h3 style="font-size: 1.3rem; margin-bottom: 0.5rem;">Informations personnelles</h3>
                    <p style="color: var(--text-dim); font-size: 0.85rem;">Gérez vos accès et vos paramètres de
                        sécurité.</p>
                </div>

                <div
                    style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
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

                <!-- FORM PHOTO DE PROFIL -->
                <form method="POST" id="avatarForm"
                    style="margin-bottom: 2rem; background: rgba(255,255,255,0.02); padding: 1.5rem; border-radius: var(--radius-md); border: 1px solid var(--glass-border);">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_avatar">
                    <input type="hidden" name="avatar_base64" id="avatarBase64Input">

                    <h4 style="margin-bottom: 0.5rem; color: var(--primary);">📷 Photo de Profil</h4>
                    <p style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 1.5rem;">Celle-ci apparaîtra
                        sur votre tableau de bord. Prenez une photo ou choisissez-en une dans la galerie.</p>

                    <div style="display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;">
                        <label for="avatarInput"
                            style="cursor: pointer; flex-shrink: 0; position: relative; width: 80px; height: 80px; border-radius: 50%; border: 2px dashed rgba(14, 165, 233, 0.4); display: flex; align-items: center; justify-content: center; overflow: hidden; background: rgba(0,0,0,0.3); transition: border-color 0.2s;">
                            <?php if (!empty($_SESSION['avatar'])): ?>
                                <img id="avatarPreview" src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                                    style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <img id="avatarPreview" src=""
                                    style="width: 100%; height: 100%; object-fit: cover; display: none;">
                                <span id="avatarPlaceholder"
                                    style="font-size: 2rem; color: var(--accent-cyan); font-weight: 300;">+</span>
                            <?php endif; ?>
                            <input type="file" id="avatarInput" accept="image/*" style="display: none;">
                        </label>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <button type="button" onclick="document.getElementById('avatarInput').click()"
                                class="btn btn-ghost"
                                style="padding: 0.5rem 1rem; font-size: 0.8rem; border-color: var(--glass-border);">
                                📁 Choisir une image...
                            </button>
                            <button type="submit" id="saveAvatarBtn" class="btn btn-primary"
                                style="display: none; padding: 0.5rem 1rem; font-size: 0.8rem; background: var(--success); color: white;">
                                ↓ Sauvegarder la photo
                            </button>
                        </div>
                    </div>
                </form>

                <script>
                    document.getElementById('avatarInput').addEventListener('change', function (e) {
                        const file = e.target.files[0];
                        if (!file) return;

                        const reader = new FileReader();
                        reader.onload = function (event) {
                            const img = new Image();
                            img.onload = function () {
                                // Redimensionner l'image du tel en 300x300 pour pas surcharger la base de données PostgreSQL
                                const canvas = document.createElement('canvas');
                                const ctx = canvas.getContext('2d');

                                const size = Math.min(img.width, img.height);
                                const sx = (img.width - size) / 2;
                                const sy = (img.height - size) / 2;

                                canvas.width = 300;
                                canvas.height = 300;
                                ctx.drawImage(img, sx, sy, size, size, 0, 0, 300, 300);

                                const base64 = canvas.toDataURL('image/jpeg', 0.8);
                                document.getElementById('avatarBase64Input').value = base64;

                                document.getElementById('avatarPreview').src = base64;
                                document.getElementById('avatarPreview').style.display = 'block';
                                const placeholder = document.getElementById('avatarPlaceholder');
                                if (placeholder) placeholder.style.display = 'none';

                                document.getElementById('saveAvatarBtn').style.display = 'inline-block';
                            };
                            img.src = event.target.result;
                        };
                        reader.readAsDataURL(file);
                    });
                </script>

                <!-- FORM MOT DE PASSE -->
                <form method="POST" class="glass" id="passwordForm"
                    style="padding: 2rem; border-radius: var(--radius-md); background: rgba(255,255,255,0.02);">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <h4 style="margin-bottom: 0.5rem; color: var(--primary);">🔒 Changer le mot de passe</h4>
                    <p style="font-size: 0.75rem; color: var(--text-dim); margin-bottom: 1.5rem;">
                        Le mot de passe doit comporter au moins 12 caractères, avec majuscule, minuscule, chiffre et
                        caractère spécial.
                    </p>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label class="label">Ancien mot de passe</label>
                        <div class="input-wrapper">
                            <input type="password" name="old_password" class="input p-password" required
                                placeholder="Votre mot de passe actuel" style="flex: 1; text-align: left;"
                                maxlength="128">
                            <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        <div class="form-group">
                            <label class="label">Nouveau mot de passe</label>
                            <div class="input-wrapper">
                                <input type="password" name="new_password" id="newPassword" class="input p-password"
                                    required placeholder="Min. 12 car." style="flex: 1; text-align: left;"
                                    maxlength="128" oninput="updateStrength(this.value)">
                                <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                            </div>
                            <!-- Indicateur de force -->
                            <div class="strength-bar-container">
                                <div class="strength-bar" id="strengthBar"></div>
                            </div>
                            <p class="strength-label" id="strengthLabel" style="color: var(--text-dim);">—</p>
                            <!-- Liste des règles -->
                            <ul class="policy-list" id="policyList">
                                <li id="rule-len"><span class="check">○</span> 12 caractères minimum</li>
                                <li id="rule-upper"><span class="check">○</span> Une lettre majuscule</li>
                                <li id="rule-lower"><span class="check">○</span> Une lettre minuscule</li>
                                <li id="rule-num"><span class="check">○</span> Un chiffre</li>
                                <li id="rule-spec"><span class="check">○</span> Un caractère spécial</li>
                            </ul>
                        </div>
                        <div class="form-group">
                            <label class="label">Confirmation</label>
                            <div class="input-wrapper">
                                <input type="password" name="confirm_password" id="confirmPassword"
                                    class="input p-password" required placeholder="Répéter"
                                    style="flex: 1; text-align: left;" maxlength="128" oninput="checkMatch()">
                                <button type="button" class="password-toggle" onclick="togglePass(this)">👁</button>
                            </div>
                            <p id="matchLabel" style="font-size: 0.65rem; margin-top: 0.4rem; font-weight: 600;"></p>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        Mettre à jour mon mot de passe
                    </button>
                </form>
            </div>

            <div class="app-footer">
                Raoul Lenoir SAS · <a href="privacy.php" style="color: inherit; text-decoration: underline;">RGPD &amp;
                    Confidentialité</a>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            document.body.classList.toggle('sidebar-is-open');
            // Fermer sidebar automatiquement si clic lien
            document.querySelectorAll('.sidebar-link').forEach(link => {
                link.addEventListener('click', () => {
                    document.getElementById('sidebar').classList.remove('open');
                    document.getElementById('sidebarOverlay').classList.remove('open');
                    document.body.classList.remove('sidebar-is-open');
                });
            });
        }
        function togglePass(btn) {
            const input = btn.parentElement.querySelector('input');
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            btn.textContent = type === 'password' ? '👁' : '🔒';
        }

        // Indicateur de force du mot de passe
        function updateStrength(val) {
            const bar = document.getElementById('strengthBar');
            const label = document.getElementById('strengthLabel');
            let score = 0;
            const checks = {
                'rule-len': val.length >= 12,
                'rule-upper': /[A-Z]/.test(val),
                'rule-lower': /[a-z]/.test(val),
                'rule-num': /[0-9]/.test(val),
                'rule-spec': /[\W_]/.test(val),
            };
            for (const [id, ok] of Object.entries(checks)) {
                const li = document.getElementById(id);
                if (ok) {
                    li.classList.add('ok');
                    li.classList.remove('fail');
                    li.querySelector('.check').textContent = '✓';
                    score++;
                } else {
                    li.classList.remove('ok');
                    if (val.length > 0) {
                        li.classList.add('fail');
                        li.querySelector('.check').textContent = '✕';
                    } else {
                        li.classList.remove('fail');
                        li.querySelector('.check').textContent = '○';
                    }
                }
            }
            bar.className = 'strength-bar strength-' + score;
            const labels = ['', 'Très faible', 'Faible', 'Moyen', 'Fort'];
            const colors = ['var(--text-dim)', '#ef4444', '#f97316', '#eab308', '#22c55e'];
            label.textContent = val.length > 0 ? (labels[score] || 'Fort') : '—';
            label.style.color = val.length > 0 ? (colors[score] || '#22c55e') : 'var(--text-dim)';
            checkMatch();
        }

        function checkMatch() {
            const newPass = document.getElementById('newPassword').value;
            const confirm = document.getElementById('confirmPassword').value;
            const ml = document.getElementById('matchLabel');
            if (!confirm) { ml.textContent = ''; return; }
            if (newPass === confirm) {
                ml.textContent = '✓ Les mots de passe correspondent';
                ml.style.color = '#22c55e';
            } else {
                ml.textContent = '✕ Ne correspond pas';
                ml.style.color = '#ef4444';
            }
        }
    </script>
</body>

</html>
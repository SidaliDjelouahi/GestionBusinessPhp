<?php
declare(strict_types=1);

session_start();
require_once 'db_connect.php';

// Sécurité : Uniquement Admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['rank'] ?? '') !== 'admin') {
    header('Location: dashboard.php'); // redirection vers dashboard au lieu de login si pas admin
    exit;
}

$username = $_SESSION['username'] ?? 'Utilisateur';
$rank     = $_SESSION['rank']     ?? 'user';
$initials = strtoupper(mb_substr($username, 0, 1));

// Connexion BDD
$pdo = getDBConnection();
if ($pdo === null) {
    die("Connexion impossible aux bases de données.");
}

/**
 * Classe Utilisateur pour opérations CRUD sur la table utilisateurs
 */
class Utilisateur
{
    private int $id;
    private string $nom;
    private string $password;
    private ?string $rank;

    public function __construct(private PDO $pdo) {}

    public function create(string $nom, string $password, string $rank): int
    {
        try {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare("INSERT INTO utilisateurs (nom, password, rank) VALUES (:nom, :password, :rank)");
            $stmt->execute([
                ':nom'      => $nom,
                ':password' => $hashedPassword,
                ':rank'     => $rank
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) { return 0; }
    }

    public function findById(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, nom, rank FROM utilisateurs WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result !== false ? $result : null;
        } catch (PDOException $e) { return null; }
    }

    public function findAll(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT id, nom, rank FROM utilisateurs ORDER BY id DESC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { return []; }
    }

    public function update(int $id, string $nom, string $rank): bool
    {
        try {
            $stmt = $this->pdo->prepare("UPDATE utilisateurs SET nom = :nom, rank = :rank WHERE id = :id");
            return $stmt->execute([':nom' => $nom, ':rank' => $rank, ':id' => $id]);
        } catch (PDOException $e) { return false; }
    }

    public function updatePassword(int $id, string $newPassword): bool
    {
        try {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare("UPDATE utilisateurs SET password = :password WHERE id = :id");
            return $stmt->execute([':password' => $hashedPassword, ':id' => $id]);
        } catch (PDOException $e) { return false; }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM utilisateurs WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (PDOException $e) { return false; }
    }
}

$userObj = new Utilisateur($pdo);
$message = '';
$message_type = '';

// Handle Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_to_delete = (int)$_GET['delete'];
    if ($userObj->delete($id_to_delete)) {
        $message = "Utilisateur supprimé avec succès.";
        $message_type = "success";
    } else {
        $message = "Erreur lors de la suppression.";
        $message_type = "danger";
    }
}

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p_nom      = trim($_POST['nom'] ?? '');
    $p_pass     = $_POST['password'] ?? '';
    $p_rank     = trim($_POST['rank'] ?? 'user');
    $edit_id    = isset($_POST['edit_id']) && is_numeric($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    if ($p_nom === '') {
        $message = "Le nom d'utilisateur est obligatoire.";
        $message_type = "danger";
    } else {
        if ($edit_id) {
            // Edit mode
            if ($userObj->update($edit_id, $p_nom, $p_rank)) {
                 $message = "Utilisateur modifié avec succès.";
                 $message_type = "success";
                 if ($p_pass !== '') {
                     $userObj->updatePassword($edit_id, $p_pass);
                 }
            } else {
                 $message = "Erreur lors de la modification.";
                 $message_type = "danger";
            }
        } else {
            // Create mode
            if ($p_pass === '') {
                $message = "Le mot de passe est obligatoire pour un nouvel utilisateur.";
                $message_type = "danger";
            } else {
                if ($userObj->create($p_nom, $p_pass, $p_rank) > 0) {
                     $message = "Utilisateur ajouté avec succès.";
                     $message_type = "success";
                } else {
                     $message = "Erreur lors de la création de l'utilisateur.";
                     $message_type = "danger";
                }
            }
        }
    }
}

// Fetch all users (+ Search)
$search = trim($_GET['search'] ?? '');
$allUsers = $userObj->findAll();
if ($search !== '') {
    $allUsers = array_filter($allUsers, function($u) use ($search) {
        return stripos($u['nom'], $search) !== false;
    });
}

// Check Edit Mode
$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_user = $userObj->findById((int)$_GET['edit']);
}

$current_page = 'utilisateurs';
$stat_total = count($allUsers);
$stat_admin = count(array_filter($allUsers, fn($u) => $u['rank'] === 'admin'));
$stat_user  = count(array_filter($allUsers, fn($u) => $u['rank'] === 'user'));
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utilisateurs — G-Business</title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">

    <style>
        /* ══════════════════════════════════════════
           CSS CUSTOM PROPERTIES — LIGHT / DARK
        ══════════════════════════════════════════ */
        :root {
            --sidebar-bg:    #0f172a;
            --sidebar-w:     260px;
            --sidebar-w-sm:  70px;
            --topbar-h:      64px;
            --content-bg:    #f1f5f9;
            --card-bg:       #ffffff;
            --card-border:   rgba(0,0,0,0.06);
            --text-primary:  #0f172a;
            --text-secondary:#64748b;
            --text-muted:    #94a3b8;
            --border-color:  #e2e8f0;
            --shadow-sm:     0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-lg:     0 10px 25px rgba(0,0,0,0.10);
            --radius:        14px;
            --radius-sm:     8px;
            --transition:    0.25s ease;
            --accent:        #6366f1;
            --grad-blue:     linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --grad-purple:   linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            --grad-green:    linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        [data-theme="dark"] {
            --content-bg:    #0f172a;
            --card-bg:       #1e293b;
            --card-border:   rgba(255,255,255,0.06);
            --text-primary:  #f1f5f9;
            --text-secondary:#94a3b8;
            --text-muted:    #64748b;
            --border-color:  #334155;
            --shadow-sm:     0 1px 3px rgba(0,0,0,0.30);
            --shadow-lg:     0 10px 25px rgba(0,0,0,0.50);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--content-bg);
            color: var(--text-primary);
            transition: background var(--transition), color var(--transition);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ══════════════════════════════════════════
           SIDEBAR
        ══════════════════════════════════════════ */
        #sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-w); height: 100vh;
            background: var(--sidebar-bg);
            display: flex; flex-direction: column;
            z-index: 1040; transition: width var(--transition), transform var(--transition);
        }
        #sidebar .sidebar-brand {
            display: flex; align-items: center; gap: 12px;
            padding: 20px 22px; border-bottom: 1px solid rgba(255,255,255,0.08);
            text-decoration: none;
        }
        .brand-icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff;
        }
        .brand-text { font-size: 18px; font-weight: 700; color: #fff; }
        .brand-text span { color: #6366f1; }
        .sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; }
        .nav-section-label {
            font-size: 10px; font-weight: 600; text-transform: uppercase; color: rgba(255,255,255,0.3);
            padding: 12px 22px 6px;
        }
        .nav-item { margin: 2px 10px; }
        .nav-link-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; border-radius: 10px;
            color: rgba(255,255,255,0.65); text-decoration: none;
            font-size: 14px; font-weight: 500; transition: all var(--transition);
        }
        .nav-link-item .nav-icon { font-size: 16px; width: 20px; text-align: center; }
        .nav-link-item:hover, .nav-link-item.active { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-link-item.active { background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(139,92,246,0.2)); color: #fff; }
        .nav-link-item.active .nav-icon { color: #818cf8; }
        .nav-badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; background: #6366f1; color: #fff; }
        .sidebar-user { padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 38px; height: 38px; border-radius: 50%; background: #6366f1; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 13px; font-weight: 600; color: #fff; }
        .user-rank { font-size: 11px; color: rgba(255,255,255,0.45); }
        .btn-logout { width: 32px; height: 32px; border-radius: 8px; background: rgba(239,68,68,0.12); color: #f87171; display: flex; justify-content: center; align-items: center; text-decoration: none; }

        body.sidebar-collapsed #sidebar { width: var(--sidebar-w-sm); }
        body.sidebar-collapsed .brand-text, body.sidebar-collapsed .nav-text, body.sidebar-collapsed .nav-badge, body.sidebar-collapsed .nav-section-label, body.sidebar-collapsed .user-info { display: none; }
        
        #main-wrapper { margin-left: var(--sidebar-w); transition: margin-left var(--transition); min-height: 100vh; display: flex; flex-direction: column; }
        body.sidebar-collapsed #main-wrapper { margin-left: var(--sidebar-w-sm); }

        /* ══════════════════════════════════════════
           TOPBAR & CONTENT
        ══════════════════════════════════════════ */
        #topbar {
            height: var(--topbar-h); background: var(--card-bg); border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; padding: 0 24px; gap: 16px; top: 0; z-index: 1030; position: sticky;
        }
        .topbar-toggle { width: 36px; height: 36px; border-radius: 8px; border: 1px solid var(--border-color); background: transparent; color: var(--text-secondary); }
        .topbar-title { font-size: 17px; font-weight: 700; color: var(--text-primary); flex: 1; }
        .topbar-actions { display: flex; gap: 10px; }
        .topbar-btn { width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--border-color); background: transparent; color: var(--text-secondary); cursor: pointer; }

        #page-content { flex: 1; padding: 28px; }

        /* CARDS & STATS */
        .section-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-bottom: 24px; padding: 22px; }
        .section-card-header { border-bottom: 1px solid var(--border-color); padding-bottom: 14px; margin-bottom: 18px; display: flex; justify-content: space-between; align-items: center; }
        .section-card-title { font-size: 15px; font-weight: 700; color: var(--text-primary); display: flex; gap: 8px; align-items: center; }
        .section-card-title i { color: var(--accent); }

        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius); padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: var(--shadow-sm); }
        .stat-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; }
        .stat-value { font-size: 20px; font-weight: 800; color: var(--text-primary); }
        .stat-label { font-size: 12px; font-weight: 500; color: var(--text-muted); }

        /* FORM & TABLE */
        .form-control-custom, .form-select-custom {
            background: var(--content-bg); border: 1px solid var(--border-color); border-radius: var(--radius-sm);
            padding: 10px 14px; font-size: 13px; color: var(--text-primary); width: 100%; transition: all var(--transition);
        }
        .form-control-custom:focus, .form-select-custom:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
        .form-label-custom { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; display: block; }

        .btn-primary-custom {
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border: none; border-radius: 10px;
            padding: 10px 20px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-secondary-custom { background: transparent; color: var(--text-secondary); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 20px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }

        .table-custom { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-custom th { font-size: 11px; text-transform: uppercase; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border-color); text-align: left; }
        .table-custom td { padding: 13px 14px; color: var(--text-secondary); vertical-align: middle; border-bottom: 1px solid var(--border-color); }
        .table-custom tr:last-child td { border-bottom: none; }
        .btn-action { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-color); color: var(--text-secondary); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }

        .badge-admin { background: rgba(99,102,241,0.1); color: #6366f1; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-user { background: rgba(16,185,129,0.1); color: #10b981; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }

        .alert-custom { padding: 12px 18px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
        .alert-success { background: rgba(16,185,129,0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.2); }
        .alert-danger { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
        
        .search-bar { display: flex; align-items: center; gap: 10px; background: var(--content-bg); border: 1px solid var(--border-color); border-radius: 10px; padding: 0 14px; width: 250px; }
        .search-bar input { border: none; background: transparent; padding: 10px 0; font-size: 13px; color: var(--text-primary); width: 100%; outline: none; }
        .search-bar i { color: var(--text-muted); }

        @media(max-width: 900px) {
           body.sidebar-mobile-open #sidebar { transform: translateX(0); width: var(--sidebar-w) !important; }
           body:not(.sidebar-mobile-open) #sidebar { transform: translateX(-100%); }
           #main-wrapper { margin-left: 0 !important; }
        }
    </style>
</head>
<body>

<nav id="sidebar">
    <a href="dashboard.php" class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-chart-line"></i></div>
        <span class="brand-text">G-<span>Business</span></span>
    </a>

    <div class="sidebar-nav">
        <div class="nav-section-label">Navigation</div>
        <div class="nav-item">
            <a href="dashboard.php" class="nav-link-item <?= $current_page==='dashboard' ? 'active' : '' ?>" data-label="Dashboard">
                <i class="fa-solid fa-house nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>
        <?php if ($rank === 'admin'): ?>
        <div class="nav-item">
            <a href="utilisateurs.php" class="nav-link-item <?= $current_page==='utilisateurs' ? 'active' : '' ?>" data-label="Utilisateurs">
                <i class="fa-solid fa-users nav-icon"></i>
                <span class="nav-text">Utilisateurs</span>
                <span class="nav-badge">Admin</span>
            </a>
        </div>
        <?php endif; ?>
        <div class="nav-item">
            <a href="products.php" class="nav-link-item <?= $current_page==='products' ? 'active' : '' ?>" data-label="Produits">
                <i class="fa-solid fa-boxes-stacked nav-icon"></i>
                <span class="nav-text">Produits</span>
            </a>
        </div>

        <div class="nav-section-label">Transactions</div>
        <div class="nav-item"><a href="achats/achats.php" class="nav-link-item"><i class="fa-solid fa-cart-shopping nav-icon"></i> <span class="nav-text">Achats</span></a></div>
        <div class="nav-item"><a href="ventes/ventes.php" class="nav-link-item"><i class="fa-solid fa-money-bill-wave nav-icon"></i> <span class="nav-text">Ventes</span></a></div>

        <div class="nav-section-label">Répertoire</div>
        <div class="nav-item"><a href="clients.php" class="nav-link-item"><i class="fa-solid fa-user-tie nav-icon"></i> <span class="nav-text">Clients</span></a></div>
        <div class="nav-item"><a href="fournisseurs.php" class="nav-link-item"><i class="fa-solid fa-industry nav-icon"></i> <span class="nav-text">Fournisseurs</span></a></div>
    </div>
    
    <div class="sidebar-user">
        <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($username) ?></div>
            <div class="user-rank"><?= htmlspecialchars($rank) ?></div>
        </div>
        <a href="logout.php" class="btn-logout" title="Déconnexion"><i class="fa-solid fa-arrow-right-from-bracket"></i></a>
    </div>
</nav>

<div id="main-wrapper">
    <header id="topbar">
        <button class="topbar-toggle" id="sidebarToggle"><i class="fa-solid fa-bars"></i></button>
        <div class="topbar-title">Gestion des Utilisateurs <small>Administration</small></div>
        <div class="topbar-actions">
            <button class="topbar-btn" id="themeToggle"><i class="fa-solid fa-moon"></i></button>
        </div>
    </header>

    <main id="page-content">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 style="font-size:22px;font-weight:800;color:var(--text-primary);margin:0;"><i class="fa-solid fa-users me-2" style="color:#6366f1;"></i> Utilisateurs</h1>
                <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;">Gérez les accès à la plateforme</p>
            </div>
            <a href="#formSection" class="btn-primary-custom" onclick="document.getElementById('formSection').scrollIntoView({behavior:'smooth'})">
                <i class="fa-solid fa-plus"></i> Nouvel Utilisateur
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert-custom alert-<?= $message_type ?>">
                <i class="fa-solid fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--grad-blue);"><i class="fa-solid fa-users"></i></div>
                <div><div class="stat-value"><?= $stat_total ?></div><div class="stat-label">Total Comptes</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--grad-purple);"><i class="fa-solid fa-user-shield"></i></div>
                <div><div class="stat-value"><?= $stat_admin ?></div><div class="stat-label">Administrateurs</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--grad-green);"><i class="fa-solid fa-user"></i></div>
                <div><div class="stat-value"><?= $stat_user ?></div><div class="stat-label">Utilisateurs standards</div></div>
            </div>
        </div>

        <div class="section-card" id="formSection" style="padding:0;">
            <div class="section-card-header" style="padding:18px 22px; margin:0;">
                <div class="section-card-title"><i class="fa-solid fa-<?= $edit_user ? 'user-pen' : 'user-plus' ?>"></i> <?= $edit_user ? 'Modifier l\'Utilisateur' : 'Créer un Utilisateur' ?></div>
                <?php if ($edit_user): ?><a href="utilisateurs.php" class="btn-secondary-custom" style="padding:6px 14px;"><i class="fa-solid fa-xmark"></i> Annuler</a><?php endif; ?>
            </div>
            <form method="POST" action="utilisateurs.php" style="padding: 22px;">
                <?php if ($edit_user): ?><input type="hidden" name="edit_id" value="<?= (int)$edit_user['id'] ?>"><?php endif; ?>
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label class="form-label-custom">Nom complet *</label>
                        <input type="text" name="nom" class="form-control-custom" placeholder="Ex: Ali Benali" value="<?= htmlspecialchars($edit_user['nom'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-custom">Mot de passe <?= $edit_user ? '(Laisser vide pour ne pas changer)' : '*' ?></label>
                        <input type="password" name="password" class="form-control-custom" placeholder="••••••••" <?= $edit_user ? '' : 'required' ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-custom">Niveau d'accès (Rank) *</label>
                        <select name="rank" class="form-select-custom" required>
                            <option value="user" <?= ($edit_user['rank'] ?? '') === 'user' ? 'selected' : '' ?>>Standard (User)</option>
                            <option value="admin" <?= ($edit_user['rank'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrateur</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-primary-custom"><i class="fa-solid fa-<?= $edit_user ? 'save' : 'plus' ?>"></i> <?= $edit_user ? 'Enregistrer les modifications' : 'Créer le compte' ?></button>
            </form>
        </div>

        <div class="section-card" style="padding:0;">
            <div class="section-card-header" style="padding:18px 22px; margin:0;">
                <div class="section-card-title"><i class="fa-solid fa-table-list"></i> Liste des Comptes</div>
                <form method="GET" action="utilisateurs.php" style="margin:0;">
                    <div class="search-bar">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </form>
            </div>
            
            <div style="overflow-x:auto;">
                <table class="table-custom">
                    <thead><tr><th>#</th><th>Nom d'utilisateur</th><th>Rôle (Rank)</th><th style="text-align:center;">Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($allUsers)): ?>
                        <tr><td colspan="4" style="text-align:center; padding: 40px; color: var(--text-muted);">Aucun utilisateur trouvé.</td></tr>
                        <?php else: ?>
                        <?php foreach($allUsers as $u): ?>
                        <tr>
                            <td><?= (int)$u['id'] ?></td>
                            <td style="font-weight:600; color:var(--text-primary);"><?= htmlspecialchars($u['nom']) ?></td>
                            <td>
                                <?php if ($u['rank'] === 'admin'): ?>
                                    <span class="badge-admin"><i class="fa-solid fa-shield-halved"></i> Admin</span>
                                <?php else: ?>
                                    <span class="badge-user"><i class="fa-solid fa-user"></i> User</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <div style="display:flex; justify-content:center; gap:6px;">
                                    <a href="utilisateurs.php?edit=<?= (int)$u['id'] ?>#formSection" class="btn-action"><i class="fa-solid fa-pen"></i></a>
                                    <?php if ((int)$u['id'] !== 1 && (int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                    <a href="utilisateurs.php?delete=<?= (int)$u['id'] ?>" class="btn-action" style="color:#ef4444;" onclick="return confirm('Supprimer cet utilisateur ?');"><i class="fa-solid fa-trash"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
// Theme
const themeBtn = document.getElementById('themeToggle');
themeBtn.addEventListener('click', () => {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.documentElement.setAttribute('data-theme', isDark ? 'light' : 'dark');
    themeBtn.querySelector('i').className = isDark ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
});
// Sidebar collapse
document.getElementById('sidebarToggle').addEventListener('click', () => {
    if(window.innerWidth < 900) document.body.classList.toggle('sidebar-mobile-open');
    else document.body.classList.toggle('sidebar-collapsed');
});
</script>
</body>
</html>

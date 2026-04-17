<?php
// ─── SESSION & AUTH ────────────────────────────────────────────────────────
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
$username = $_SESSION['username'] ?? 'Utilisateur';
$rank     = $_SESSION['rank']     ?? 'user';
$initials = strtoupper(mb_substr($username, 0, 1));

// ─── DATABASE CONNECTION ───────────────────────────────────────────────────
$pdo = getDBConnection();
if ($pdo === null) {
    die("Connexion impossible aux bases de données.");
}

// ─── HANDLE ACTIONS (Add / Edit / Delete) ──────────────────────────────────
$message = '';
$message_type = '';

// Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM produits WHERE id = ?");
        $stmt->execute([$id]);
        $message = "Produit supprimé avec succès.";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "Erreur lors de la suppression : ce produit est peut-être lié à des achats ou ventes.";
        $message_type = "danger";
    }
}

// Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom        = trim($_POST['nom'] ?? '');
    $qte        = floatval($_POST['qte'] ?? 0);
    $prix_achat = floatval($_POST['prix_achat'] ?? 0);
    $prix_vente = floatval($_POST['prix_vente'] ?? 0);
    $edit_id    = isset($_POST['edit_id']) && is_numeric($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

    if ($nom === '') {
        $message = "Le nom du produit est obligatoire.";
        $message_type = "danger";
    } else {
        try {
            if ($edit_id) {
                $stmt = $pdo->prepare("UPDATE produits SET nom = ?, qte = ?, prix_achat = ?, prix_vente = ? WHERE id = ?");
                $stmt->execute([$nom, $qte, $prix_achat, $prix_vente, $edit_id]);
                $message = "Produit modifié avec succès.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO produits (nom, qte, prix_achat, prix_vente) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nom, $qte, $prix_achat, $prix_vente]);
                $message = "Produit ajouté avec succès.";
            }
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Erreur : " . htmlspecialchars($e->getMessage());
            $message_type = "danger";
        }
    }
}

// ─── FETCH PRODUCTS ────────────────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$produits = [];
try {
    if ($search !== '') {
        $stmt = $pdo->prepare("SELECT * FROM produits WHERE nom LIKE ? ORDER BY id DESC");
        $stmt->execute(['%' . $search . '%']);
    } else {
        $stmt = $pdo->query("SELECT * FROM produits ORDER BY id DESC");
    }
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $produits = [];
}

// ─── EDIT MODE ─────────────────────────────────────────────────────────────
$edit_produit = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM produits WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $edit_produit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produits — G-Business</title>

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
            --shadow-md:     0 4px 12px rgba(0,0,0,0.08), 0 2px 4px rgba(0,0,0,0.04);
            --shadow-lg:     0 10px 25px rgba(0,0,0,0.10), 0 4px 8px rgba(0,0,0,0.06);
            --radius:        14px;
            --radius-sm:     8px;
            --transition:    0.25s ease;
            --accent:        #6366f1;
            --grad-blue:     linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --grad-purple:   linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            --grad-green:    linear-gradient(135deg, #10b981 0%, #059669 100%);
            --grad-orange:   linear-gradient(135deg, #f97316 0%, #ea580c 100%);
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
            --shadow-md:     0 4px 12px rgba(0,0,0,0.40);
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
            z-index: 1040;
            transition: width var(--transition), transform var(--transition);
            overflow: hidden;
        }
        #sidebar .sidebar-brand {
            display: flex; align-items: center; gap: 12px;
            padding: 20px 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            text-decoration: none; white-space: nowrap;
        }
        .brand-icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff; flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(99,102,241,0.4);
        }
        .brand-text { font-size: 18px; font-weight: 700; color: #fff; letter-spacing: -0.3px; }
        .brand-text span { color: #6366f1; }
        .sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
        .nav-section-label {
            font-size: 10px; font-weight: 600; letter-spacing: 1.2px;
            text-transform: uppercase; color: rgba(255,255,255,0.3);
            padding: 12px 22px 6px; white-space: nowrap; overflow: hidden;
        }
        .nav-item { margin: 2px 10px; }
        .nav-link-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; border-radius: 10px;
            color: rgba(255,255,255,0.65); text-decoration: none;
            font-size: 14px; font-weight: 500;
            transition: all var(--transition); white-space: nowrap; position: relative;
        }
        .nav-link-item .nav-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; transition: transform var(--transition); }
        .nav-link-item .nav-text { flex: 1; }
        .nav-link-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-link-item:hover .nav-icon { transform: scale(1.1); }
        .nav-link-item.active {
            background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(139,92,246,0.2));
            color: #fff; box-shadow: inset 0 0 0 1px rgba(99,102,241,0.3);
        }
        .nav-link-item.active .nav-icon { color: #818cf8; }
        .nav-badge {
            font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff;
        }
        .sidebar-user {
            padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.08);
            display: flex; align-items: center; gap: 12px; white-space: nowrap;
        }
        .user-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a78bfa);
            color: #fff; font-weight: 700; font-size: 14px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(99,102,241,0.3);
        }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 13px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-rank { font-size: 11px; color: rgba(255,255,255,0.45); text-transform: capitalize; }
        .btn-logout {
            display: flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 8px;
            background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.2);
            color: #f87171; font-size: 14px; cursor: pointer;
            transition: all var(--transition); text-decoration: none; flex-shrink: 0;
        }
        .btn-logout:hover { background: rgba(239,68,68,0.25); color: #fca5a5; }

        /* COLLAPSED */
        body.sidebar-collapsed #sidebar { width: var(--sidebar-w-sm); }
        body.sidebar-collapsed .brand-text,
        body.sidebar-collapsed .nav-text,
        body.sidebar-collapsed .nav-badge,
        body.sidebar-collapsed .nav-section-label,
        body.sidebar-collapsed .user-info,
        body.sidebar-collapsed .btn-logout { display: none; }
        body.sidebar-collapsed .sidebar-brand { justify-content: center; padding: 20px; }
        body.sidebar-collapsed .nav-link-item { justify-content: center; padding: 12px; }
        body.sidebar-collapsed .nav-item { margin: 2px 8px; }
        body.sidebar-collapsed .sidebar-user { justify-content: center; padding: 14px 8px; }
        body.sidebar-collapsed .nav-link-item { position: relative; }
        body.sidebar-collapsed .nav-link-item::after {
            content: attr(data-label); position: absolute;
            left: calc(100% + 12px); top: 50%; transform: translateY(-50%);
            background: #1e293b; color: #fff; padding: 5px 10px; border-radius: 6px;
            font-size: 12px; white-space: nowrap; pointer-events: none; opacity: 0;
            transition: opacity 0.15s; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        body.sidebar-collapsed .nav-link-item:hover::after { opacity: 1; }

        /* OVERLAY */
        #sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 1035;
        }

        /* ══════════════════════════════════════════
           MAIN WRAPPER
        ══════════════════════════════════════════ */
        #main-wrapper {
            margin-left: var(--sidebar-w); min-height: 100vh;
            display: flex; flex-direction: column;
            transition: margin-left var(--transition);
        }
        body.sidebar-collapsed #main-wrapper { margin-left: var(--sidebar-w-sm); }

        /* ══════════════════════════════════════════
           TOP NAVBAR
        ══════════════════════════════════════════ */
        #topbar {
            height: var(--topbar-h); background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; padding: 0 24px; gap: 16px;
            position: sticky; top: 0; z-index: 1030;
            transition: background var(--transition), border-color var(--transition);
            box-shadow: var(--shadow-sm);
        }
        .topbar-toggle {
            width: 36px; height: 36px; border-radius: 8px;
            border: 1px solid var(--border-color); background: transparent;
            color: var(--text-secondary); display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 16px; transition: all var(--transition);
        }
        .topbar-toggle:hover { background: var(--border-color); color: var(--text-primary); }
        .topbar-title { font-size: 17px; font-weight: 700; color: var(--text-primary); flex: 1; }
        .topbar-title small { display: block; font-size: 11px; font-weight: 400; color: var(--text-muted); margin-top: -2px; }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .topbar-btn {
            width: 38px; height: 38px; border-radius: 10px;
            border: 1px solid var(--border-color); background: transparent;
            color: var(--text-secondary); display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 15px; position: relative; transition: all var(--transition);
        }
        .topbar-btn:hover { background: var(--border-color); color: var(--text-primary); }
        .avatar-dropdown { position: relative; }
        .avatar-btn {
            width: 38px; height: 38px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a78bfa);
            color: #fff; font-weight: 700; font-size: 14px;
            border: 2px solid rgba(99,102,241,0.3);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all var(--transition);
        }
        .avatar-btn:hover { transform: scale(1.05); box-shadow: 0 4px 14px rgba(99,102,241,0.4); }
        .dropdown-menu-custom {
            position: absolute; right: 0; top: calc(100% + 10px);
            min-width: 200px; background: var(--card-bg);
            border: 1px solid var(--border-color); border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg); padding: 6px; display: none; z-index: 9999;
        }
        .dropdown-menu-custom.show { display: block; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
        .dropdown-menu-custom a {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 6px; color: var(--text-secondary);
            text-decoration: none; font-size: 13px; font-weight: 500; transition: all var(--transition);
        }
        .dropdown-menu-custom a:hover { background: var(--border-color); color: var(--text-primary); }
        .dropdown-menu-custom .divider { height: 1px; background: var(--border-color); margin: 4px 0; }
        .dropdown-menu-custom a.danger { color: #ef4444; }
        .dropdown-menu-custom a.danger:hover { background: rgba(239,68,68,0.08); }

        /* ══════════════════════════════════════════
           PAGE CONTENT
        ══════════════════════════════════════════ */
        #page-content { flex: 1; padding: 28px; }

        /* ══════════════════════════════════════════
           SECTION CARD
        ══════════════════════════════════════════ */
        .section-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: var(--radius); box-shadow: var(--shadow-sm);
            transition: background var(--transition), border-color var(--transition);
            overflow: hidden;
        }
        .section-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px 14px; border-bottom: 1px solid var(--border-color);
        }
        .section-card-title {
            font-size: 15px; font-weight: 700; color: var(--text-primary);
            display: flex; align-items: center; gap: 10px;
        }
        .section-card-title i { color: var(--accent); font-size: 14px; }
        .section-card-body { padding: 20px 22px; }

        /* ══════════════════════════════════════════
           TABLE
        ══════════════════════════════════════════ */
        .table-custom { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-custom thead th {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--text-muted); padding: 10px 14px;
            background: transparent; border-bottom: 1px solid var(--border-color); white-space: nowrap;
        }
        .table-custom tbody tr {
            border-bottom: 1px solid var(--border-color); transition: background var(--transition);
        }
        .table-custom tbody tr:last-child { border-bottom: none; }
        .table-custom tbody tr:hover { background: rgba(99,102,241,0.03); }
        .table-custom td {
            padding: 13px 14px; color: var(--text-secondary); vertical-align: middle;
        }
        .table-custom td.td-main { font-weight: 600; color: var(--text-primary); }

        /* ══════════════════════════════════════════
           STOCK BADGES
        ══════════════════════════════════════════ */
        .badge-stock {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 20px;
        }
        .badge-stock::before { content: ''; width: 6px; height: 6px; border-radius: 50%; }
        .badge-ok     { background: rgba(16,185,129,0.1); color: #10b981; }
        .badge-ok::before     { background: #10b981; }
        .badge-low    { background: rgba(245,158,11,0.1); color: #f59e0b; }
        .badge-low::before    { background: #f59e0b; }
        .badge-empty  { background: rgba(239,68,68,0.1);  color: #ef4444; }
        .badge-empty::before  { background: #ef4444; }

        /* ══════════════════════════════════════════
           ACTION BUTTONS
        ══════════════════════════════════════════ */
        .btn-action {
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-color);
            background: transparent; color: var(--text-secondary);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 13px; transition: all var(--transition); text-decoration: none;
        }
        .btn-action:hover { background: var(--border-color); color: var(--text-primary); }
        .btn-action.edit:hover { background: rgba(59,130,246,0.1); color: #3b82f6; border-color: rgba(59,130,246,0.3); }
        .btn-action.delete:hover { background: rgba(239,68,68,0.1); color: #ef4444; border-color: rgba(239,68,68,0.3); }

        /* ══════════════════════════════════════════
           FORM STYLES
        ══════════════════════════════════════════ */
        .form-control-custom {
            background: var(--content-bg); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 10px 14px; font-size: 13px;
            color: var(--text-primary); font-family: 'Inter', sans-serif;
            transition: all var(--transition); width: 100%;
        }
        .form-control-custom:focus {
            outline: none; border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
        }
        .form-control-custom::placeholder { color: var(--text-muted); }

        .form-label-custom {
            font-size: 12px; font-weight: 600; color: var(--text-secondary);
            margin-bottom: 6px; display: block;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff; border: none; border-radius: 10px; padding: 10px 20px;
            font-weight: 600; font-size: 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 7px;
            box-shadow: 0 4px 14px rgba(99,102,241,0.35);
            transition: all var(--transition); text-decoration: none;
        }
        .btn-primary-custom:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99,102,241,0.45); color: #fff; }

        .btn-secondary-custom {
            background: transparent; color: var(--text-secondary);
            border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 20px;
            font-weight: 600; font-size: 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 7px;
            transition: all var(--transition); text-decoration: none;
        }
        .btn-secondary-custom:hover { background: var(--border-color); color: var(--text-primary); }

        /* ══════════════════════════════════════════
           ALERT
        ══════════════════════════════════════════ */
        .alert-custom {
            padding: 12px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: rgba(16,185,129,0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.2); }
        .alert-danger  { background: rgba(239,68,68,0.1);  color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }

        /* ══════════════════════════════════════════
           SEARCH BAR
        ══════════════════════════════════════════ */
        .search-bar {
            display: flex; align-items: center; gap: 10px;
            background: var(--content-bg); border: 1px solid var(--border-color);
            border-radius: 10px; padding: 0 14px; transition: all var(--transition);
            max-width: 300px;
        }
        .search-bar:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
        .search-bar i { color: var(--text-muted); font-size: 14px; }
        .search-bar input {
            border: none; background: transparent; padding: 10px 0;
            font-size: 13px; color: var(--text-primary); font-family: 'Inter', sans-serif;
            outline: none; width: 100%;
        }
        .search-bar input::placeholder { color: var(--text-muted); }

        /* ══════════════════════════════════════════
           EMPTY STATE
        ══════════════════════════════════════════ */
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; margin-bottom: 12px; opacity: 0.4; }
        .empty-state p { font-size: 13px; }

        /* ══════════════════════════════════════════
           RESPONSIVE
        ══════════════════════════════════════════ */
        @media (max-width: 991px) {
            body:not(.sidebar-mobile-open) #sidebar { transform: translateX(-100%); }
            body.sidebar-mobile-open #sidebar { transform: translateX(0); width: var(--sidebar-w) !important; }
            body.sidebar-mobile-open #sidebar .brand-text,
            body.sidebar-mobile-open #sidebar .nav-text,
            body.sidebar-mobile-open #sidebar .nav-badge,
            body.sidebar-mobile-open #sidebar .nav-section-label,
            body.sidebar-mobile-open #sidebar .user-info,
            body.sidebar-mobile-open #sidebar .btn-logout { display: flex !important; }
            body.sidebar-mobile-open #sidebar .sidebar-brand { justify-content: flex-start; padding: 20px 22px; }
            body.sidebar-mobile-open #sidebar .nav-link-item { justify-content: flex-start; padding: 11px 14px; }
            body.sidebar-mobile-open #sidebar .nav-item { margin: 2px 10px; }
            body.sidebar-mobile-open #sidebar .sidebar-user { justify-content: flex-start; padding: 14px 16px; }
            body.sidebar-mobile-open #sidebar-overlay { display: block; }
            #main-wrapper { margin-left: 0 !important; }
            body.sidebar-collapsed #main-wrapper { margin-left: 0 !important; }
        }
        @media (max-width: 767px) {
            #page-content { padding: 16px; }
            .topbar-title small { display: none; }
            .form-grid { grid-template-columns: 1fr !important; }
        }

        /* ══════════════════════════════════════════
           FORM GRID
        ══════════════════════════════════════════ */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        /* ══════════════════════════════════════════
           SCROLLBAR (dark theme)
        ══════════════════════════════════════════ */
        [data-theme="dark"] ::-webkit-scrollbar { width: 6px; height: 6px; }
        [data-theme="dark"] ::-webkit-scrollbar-track { background: #0f172a; }
        [data-theme="dark"] ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
    </style>
</head>
<body>

<!-- SIDEBAR OVERLAY (mobile) -->
<div id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<!-- ════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════ -->
<aside id="sidebar" aria-label="Navigation principale">
    <a href="dashboard.php" class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-chart-pie"></i></div>
        <span class="brand-text">G-<span>Business</span></span>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Menu Principal</div>

        <div class="nav-item">
            <a href="dashboard.php" class="nav-link-item" data-label="Dashboard" id="nav-dashboard">
                <i class="fa-solid fa-house nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <?php if ($rank === 'admin'): ?>
        <div class="nav-item">
            <a href="users.php" class="nav-link-item" data-label="Utilisateurs" id="nav-users">
                <i class="fa-solid fa-users-gear nav-icon"></i>
                <span class="nav-text">Utilisateurs</span>
                <span class="nav-badge">Admin</span>
            </a>
        </div>
        <?php endif; ?>

        <div class="nav-item">
            <a href="products.php" class="nav-link-item active" data-label="Produits" id="nav-products">
                <i class="fa-solid fa-boxes-stacked nav-icon"></i>
                <span class="nav-text">Produits</span>
            </a>
        </div>

        <div class="nav-section-label">Transactions</div>

        <div class="nav-item">
            <a href="purchases.php" class="nav-link-item" data-label="Achats" id="nav-purchases">
                <i class="fa-solid fa-cart-shopping nav-icon"></i>
                <span class="nav-text">Achats</span>
            </a>
        </div>

        <div class="nav-item">
            <a href="sales.php" class="nav-link-item" data-label="Ventes" id="nav-sales">
                <i class="fa-solid fa-money-bill-wave nav-icon"></i>
                <span class="nav-text">Ventes</span>
            </a>
        </div>

        <div class="nav-section-label">Répertoire</div>

        <div class="nav-item">
            <a href="clients.php" class="nav-link-item" data-label="Clients" id="nav-clients">
                <i class="fa-solid fa-user-tie nav-icon"></i>
                <span class="nav-text">Clients</span>
            </a>
        </div>

        <div class="nav-item">
            <a href="suppliers.php" class="nav-link-item" data-label="Fournisseurs" id="nav-suppliers">
                <i class="fa-solid fa-industry nav-icon"></i>
                <span class="nav-text">Fournisseurs</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-user">
        <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($username) ?></div>
            <div class="user-rank"><?= htmlspecialchars($rank) ?></div>
        </div>
        <a href="logout.php" class="btn-logout" title="Déconnexion">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>
</aside>

<!-- ════════════════════════════════════════════
     MAIN WRAPPER
════════════════════════════════════════════ -->
<div id="main-wrapper">

    <!-- TOP NAVBAR -->
    <header id="topbar">
        <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="topbar-title">
            Gestion des Produits
            <small><?= count($produits) ?> produit<?= count($produits) > 1 ? 's' : '' ?> en catalogue</small>
        </div>

        <div class="topbar-actions">
            <button class="topbar-btn" id="themeToggle" aria-label="Basculer thème">
                <i class="fa-solid fa-moon theme-toggle-icon" id="themeIcon"></i>
            </button>

            <div class="avatar-dropdown">
                <button class="avatar-btn" id="avatarBtn" aria-label="Menu utilisateur">
                    <?= htmlspecialchars($initials) ?>
                </button>
                <div class="dropdown-menu-custom" id="avatarDropdown">
                    <a href="#"><i class="fa-solid fa-user"></i> Mon Profil</a>
                    <a href="#"><i class="fa-solid fa-gear"></i> Paramètres</a>
                    <div class="divider"></div>
                    <a href="logout.php" class="danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Déconnexion</a>
                </div>
            </div>
        </div>
    </header>

    <!-- PAGE CONTENT -->
    <main id="page-content">

        <!-- PAGE HEADER -->
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 style="font-size:22px;font-weight:800;color:var(--text-primary);margin:0;">
                    <i class="fa-solid fa-boxes-stacked me-2" style="color:#6366f1;"></i>
                    Produits
                </h1>
                <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;">
                    Gérez votre catalogue de produits
                </p>
            </div>
            <a href="#formSection" class="btn-primary-custom" onclick="document.getElementById('formSection').scrollIntoView({behavior:'smooth'})">
                <i class="fa-solid fa-plus"></i> Nouveau Produit
            </a>
        </div>

        <!-- ALERT MESSAGE -->
        <?php if ($message): ?>
            <div class="alert-custom alert-<?= $message_type ?>">
                <i class="fa-solid fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- ADD / EDIT FORM -->
        <div class="section-card" style="margin-bottom:24px;" id="formSection">
            <div class="section-card-header">
                <div class="section-card-title">
                    <i class="fa-solid fa-<?= $edit_produit ? 'pen-to-square' : 'circle-plus' ?>"></i>
                    <?= $edit_produit ? 'Modifier le Produit' : 'Ajouter un Produit' ?>
                </div>
                <?php if ($edit_produit): ?>
                    <a href="products.php" class="btn-secondary-custom" style="padding:6px 14px;">
                        <i class="fa-solid fa-xmark"></i> Annuler
                    </a>
                <?php endif; ?>
            </div>
            <div class="section-card-body">
                <form method="POST" action="products.php">
                    <?php if ($edit_produit): ?>
                        <input type="hidden" name="edit_id" value="<?= (int)$edit_produit['id'] ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div>
                            <label class="form-label-custom">Nom du produit *</label>
                            <input type="text" name="nom" class="form-control-custom" placeholder="Ex: Ciment CPJ 42.5"
                                   value="<?= htmlspecialchars($edit_produit['nom'] ?? '') ?>" required>
                        </div>
                        <div>
                            <label class="form-label-custom">Quantité</label>
                            <input type="number" name="qte" class="form-control-custom" placeholder="0" step="0.001" min="0"
                                   value="<?= htmlspecialchars($edit_produit['qte'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="form-label-custom">Prix d'achat (DA)</label>
                            <input type="number" name="prix_achat" class="form-control-custom" placeholder="0.00" step="0.01" min="0"
                                   value="<?= htmlspecialchars($edit_produit['prix_achat'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="form-label-custom">Prix de vente (DA)</label>
                            <input type="number" name="prix_vente" class="form-control-custom" placeholder="0.00" step="0.01" min="0"
                                   value="<?= htmlspecialchars($edit_produit['prix_vente'] ?? '') ?>">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="btn-primary-custom">
                            <i class="fa-solid fa-<?= $edit_produit ? 'save' : 'plus' ?>"></i>
                            <?= $edit_produit ? 'Enregistrer' : 'Ajouter' ?>
                        </button>
                        <?php if ($edit_produit): ?>
                            <a href="products.php" class="btn-secondary-custom">Annuler</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- PRODUCTS TABLE -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title">
                    <i class="fa-solid fa-table-list"></i>
                    Liste des Produits
                    <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:4px;">
                        — <?= count($produits) ?> résultat<?= count($produits) > 1 ? 's' : '' ?>
                    </span>
                </div>
                <form method="GET" action="products.php" style="margin:0;">
                    <div class="search-bar">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" placeholder="Rechercher un produit..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </form>
            </div>

            <?php if (empty($produits)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-box-open d-block"></i>
                    <p>Aucun produit trouvé<?= $search ? ' pour "' . htmlspecialchars($search) . '"' : '' ?></p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nom</th>
                                <th>Quantité</th>
                                <th>Prix Achat</th>
                                <th>Prix Vente</th>
                                <th>Marge</th>
                                <th>Valeur Stock</th>
                                <th>Statut</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($produits as $p):
                                $marge = ($p['prix_vente'] ?? 0) - ($p['prix_achat'] ?? 0);
                                $valeur = ($p['qte'] ?? 0) * ($p['prix_vente'] ?? 0);
                                $qte = floatval($p['qte'] ?? 0);
                                $badge_class = $qte > 10 ? 'badge-ok' : ($qte >= 1 ? 'badge-low' : 'badge-empty');
                                $badge_text = $qte > 10 ? 'En stock' : ($qte >= 1 ? 'Stock faible' : 'Rupture');
                            ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= (int)$p['id'] ?></td>
                                <td class="td-main">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <span style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#6366f1,#a78bfa);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                            <?= strtoupper(mb_substr($p['nom'], 0, 2)) ?>
                                        </span>
                                        <?= htmlspecialchars($p['nom']) ?>
                                    </div>
                                </td>
                                <td><?= number_format($qte, $qte == intval($qte) ? 0 : 3, ',', ' ') ?></td>
                                <td><?= number_format($p['prix_achat'] ?? 0, 2, ',', ' ') ?> DA</td>
                                <td style="font-weight:600;color:var(--text-primary);"><?= number_format($p['prix_vente'] ?? 0, 2, ',', ' ') ?> DA</td>
                                <td style="font-weight:600;color:<?= $marge >= 0 ? '#10b981' : '#ef4444' ?>;">
                                    <?= $marge >= 0 ? '+' : '' ?><?= number_format($marge, 2, ',', ' ') ?> DA
                                </td>
                                <td><?= number_format($valeur, 2, ',', ' ') ?> DA</td>
                                <td><span class="badge-stock <?= $badge_class ?>"><?= $badge_text ?></span></td>
                                <td style="text-align:center;">
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <a href="products.php?edit=<?= (int)$p['id'] ?>#formSection" class="btn-action edit" title="Modifier">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <a href="products.php?delete=<?= (int)$p['id'] ?>" class="btn-action delete" title="Supprimer"
                                           onclick="return confirm('Êtes-vous sûr de vouloir supprimer ce produit ?');">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════ -->
<script>
/* THEME SYSTEM */
const htmlEl   = document.documentElement;
const themeBtn = document.getElementById('themeToggle');
const themeIco = document.getElementById('themeIcon');

function applyTheme(theme) {
    htmlEl.setAttribute('data-theme', theme);
    themeIco.className = theme === 'dark'
        ? 'fa-solid fa-sun theme-toggle-icon'
        : 'fa-solid fa-moon theme-toggle-icon';
    localStorage.setItem('gbiz-theme', theme);
}

(function() {
    const saved = localStorage.getItem('gbiz-theme') || 'light';
    applyTheme(saved);
})();

themeBtn.addEventListener('click', () => {
    const current = htmlEl.getAttribute('data-theme');
    applyTheme(current === 'dark' ? 'light' : 'dark');
});

/* SIDEBAR TOGGLE */
const sidebarToggle = document.getElementById('sidebarToggle');
const body = document.body;

function isMobile() { return window.innerWidth < 992; }

sidebarToggle.addEventListener('click', () => {
    if (isMobile()) {
        body.classList.toggle('sidebar-mobile-open');
    } else {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('gbiz-sidebar', body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open');
    }
});

function closeMobileSidebar() { body.classList.remove('sidebar-mobile-open'); }

(function() {
    if (!isMobile() && localStorage.getItem('gbiz-sidebar') === 'collapsed') {
        body.classList.add('sidebar-collapsed');
    }
})();

window.addEventListener('resize', () => {
    if (!isMobile()) body.classList.remove('sidebar-mobile-open');
});

/* AVATAR DROPDOWN */
const avatarBtn      = document.getElementById('avatarBtn');
const avatarDropdown = document.getElementById('avatarDropdown');

avatarBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    avatarDropdown.classList.toggle('show');
});

document.addEventListener('click', () => avatarDropdown.classList.remove('show'));
</script>

</body>
</html>

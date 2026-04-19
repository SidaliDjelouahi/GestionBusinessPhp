<?php
// ─── SESSION & AUTH ────────────────────────────────────────────────────────
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php'); exit;
}
$username = $_SESSION['username'] ?? 'Utilisateur';
$rank     = $_SESSION['rank']     ?? 'user';
$initials = strtoupper(mb_substr($username, 0, 1));

// ─── DATABASE CONNECTION ────────────────────────────────────────────────────
$pdo = getDBConnection();
if ($pdo === null) { die("Connexion impossible aux bases de données."); }

// ─── EDIT MODE ──────────────────────────────────────────────────────────────
$edit_id      = null;
$edit_achat   = null;
$edit_details = [];
$page_title   = 'Nouvel Achat';

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $edit_id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM achats WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_achat = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($edit_achat) {
            $page_title = "Modifier l'Achat";
            $stmt2 = $pdo->prepare("
                SELECT ad.*, p.nom AS produit_nom
                FROM achat_details ad
                LEFT JOIN produits p ON p.id = ad.id_produit
                WHERE ad.id_achat = ?
            ");
            $stmt2->execute([$edit_id]);
            $edit_details = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $edit_id = null;
        }
    } catch (Exception $e) {
        $edit_id = null;
    }
}

// ─── AUTO-GENERATE NUM ──────────────────────────────────────────────────────
try {
    $today = date('Y-m-d');
    $stmt  = $pdo->prepare("SELECT COUNT(*) FROM achats WHERE DATE(date) = ?");
    $stmt->execute([$today]);
    $n = (int)$stmt->fetchColumn();
} catch (Exception $e) { $n = 0; }
$auto_num = 'ACH-' . date('Ymd') . '-' . str_pad($n + 1, 3, '0', STR_PAD_LEFT);

// ─── FETCH FOURNISSEURS ─────────────────────────────────────────────────────
$all_fournisseurs = [];
try {
    $stmt = $pdo->query("SELECT id, nom FROM fournisseurs ORDER BY nom ASC");
    $all_fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $all_fournisseurs = []; }

// ─── ERROR FROM SAVE ────────────────────────────────────────────────────────
$save_error = '';
if (isset($_GET['error'])) {
    $save_error = trim(urldecode($_GET['error']));
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> — G-Business</title>
    <meta name="description" content="Créez ou modifiez un bon d'achat fournisseur.">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
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
            font-family: 'Inter', sans-serif; background: var(--content-bg);
            color: var(--text-primary);
            transition: background var(--transition), color var(--transition);
            min-height: 100vh; overflow-x: hidden;
        }

        /* SIDEBAR */
        #sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-w); height: 100vh;
            background: var(--sidebar-bg);
            display: flex; flex-direction: column;
            z-index: 1040; transition: width var(--transition), transform var(--transition);
            overflow: hidden;
        }
        #sidebar .sidebar-brand {
            display: flex; align-items: center; gap: 12px;
            padding: 20px 22px; border-bottom: 1px solid rgba(255,255,255,0.08);
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
        body.sidebar-collapsed .nav-link-item::after {
            content: attr(data-label); position: absolute;
            left: calc(100% + 12px); top: 50%; transform: translateY(-50%);
            background: #1e293b; color: #fff; padding: 5px 10px; border-radius: 6px;
            font-size: 12px; white-space: nowrap; pointer-events: none; opacity: 0;
            transition: opacity 0.15s; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        body.sidebar-collapsed .nav-link-item:hover::after { opacity: 1; }

        #sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 1035;
        }

        /* MAIN WRAPPER */
        #main-wrapper {
            margin-left: var(--sidebar-w); min-height: 100vh;
            display: flex; flex-direction: column;
            transition: margin-left var(--transition);
        }
        body.sidebar-collapsed #main-wrapper { margin-left: var(--sidebar-w-sm); }

        /* TOPBAR */
        #topbar {
            height: var(--topbar-h); background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; padding: 0 24px; gap: 16px;
            position: sticky; top: 0; z-index: 1030;
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
            cursor: pointer; font-size: 15px; transition: all var(--transition);
        }
        .topbar-btn:hover { background: var(--border-color); color: var(--text-primary); }
        .avatar-btn {
            width: 38px; height: 38px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a78bfa);
            color: #fff; font-weight: 700; font-size: 14px;
            border: 2px solid rgba(99,102,241,0.3);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; transition: all var(--transition);
        }
        .avatar-btn:hover { transform: scale(1.05); box-shadow: 0 4px 14px rgba(99,102,241,0.4); }
        .avatar-dropdown { position: relative; }
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

        /* PAGE CONTENT */
        #page-content { flex: 1; padding: 28px; }

        /* SECTION CARD */
        .section-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: var(--radius); box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .section-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; gap: 8px;
        }
        .section-card-title {
            font-size: 14px; font-weight: 700; color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .section-card-title i { color: var(--accent); }

        /* ALERT */
        .alert-custom {
            padding: 12px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-danger { background: rgba(239,68,68,0.1); color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }

        /* FORM CONTROLS */
        .form-control-custom {
            background: var(--content-bg); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 9px 13px; font-size: 13px;
            color: var(--text-primary); font-family: 'Inter', sans-serif;
            transition: all var(--transition); width: 100%;
        }
        .form-control-custom:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
        .form-control-custom::placeholder { color: var(--text-muted); }
        .form-label-custom { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 5px; display: block; }

        /* BUTTONS */
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

        /* TABLE */
        .table-custom { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-custom thead th {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--text-muted); padding: 10px 12px;
            border-bottom: 1px solid var(--border-color); white-space: nowrap; background: transparent;
        }
        .table-custom tbody tr { border-bottom: 1px solid var(--border-color); }
        .table-custom tbody tr:last-child { border-bottom: none; }
        .table-custom td { padding: 10px 12px; color: var(--text-secondary); vertical-align: middle; }

        /* EMPTY STATE */
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; margin-bottom: 12px; opacity: 0.35; display: block; }
        .empty-state p { font-size: 13px; }

        /* ════════ POS LAYOUT ════════ */
        .pos-layout { display: flex; gap: 24px; align-items: flex-start; }
        .pos-left   { flex: 1; min-width: 0; }
        .pos-right  { width: 340px; flex-shrink: 0; }

        /* PRODUCT SEARCH */
        .prod-search-wrap { position: relative; }
        .prod-search-input {
            width: 100%; padding: 10px 14px;
            border: 2px solid var(--border-color); border-radius: var(--radius-sm);
            background: var(--card-bg); color: var(--text-primary);
            font-size: 14px; font-family: 'Inter', sans-serif;
            transition: border-color var(--transition);
        }
        .prod-search-input:focus { outline: none; border-color: #6366f1; }
        .prod-search-input::placeholder { color: var(--text-muted); }

        /* PRODUCT DROPDOWN */
        .prod-dropdown {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            background: var(--card-bg); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); box-shadow: var(--shadow-lg);
            z-index: 9999; max-height: 280px; overflow-y: auto; display: none;
        }
        .prod-dropdown.open { display: block; }
        .prod-drop-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: filter 0.15s; font-size: 13px;
        }
        .prod-drop-item:last-child { border-bottom: none; }
        .prod-drop-item.stock-ok    { background: #e8f5e9; }
        .prod-drop-item.stock-low   { background: #fff3e0; }
        .prod-drop-item.stock-empty { background: #ffebee; }
        .prod-drop-item.highlighted { filter: brightness(0.93); }
        [data-theme="dark"] .prod-drop-item.stock-ok    { background: rgba(16,185,129,0.12); }
        [data-theme="dark"] .prod-drop-item.stock-low   { background: rgba(245,158,11,0.12); }
        [data-theme="dark"] .prod-drop-item.stock-empty { background: rgba(239,68,68,0.12); }
        .prod-drop-name { flex: 1; font-weight: 600; color: var(--text-primary); }
        .prod-drop-meta { font-size: 11px; color: var(--text-secondary); white-space: nowrap; }
        .prod-drop-badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; white-space: nowrap; }
        .badge-en-stock  { background: #dcfce7; color: #166534; }
        .badge-faible    { background: #fef9c3; color: #854d0e; }
        .badge-rupture   { background: #fee2e2; color: #991b1b; }
        .btn-entrer {
            padding: 4px 12px; border-radius: 6px; border: none;
            background: #3b82f6; color: white; font-size: 12px;
            font-weight: 600; cursor: pointer; flex-shrink: 0;
            transition: background 0.2s;
        }
        .btn-entrer:hover { background: #1d4ed8; }

        /* SEARCH CONTROLS ROW */
        .search-controls { display: flex; gap: 8px; align-items: flex-end; margin-bottom: 16px; flex-wrap: wrap; }
        .search-controls .prod-search-wrap { flex: 1; min-width: 200px; }

        /* QTY CONTROL */
        .qty-control { display: flex; align-items: center; gap: 4px; }
        .qty-control input { width: 70px; text-align: center; }
        .btn-qty {
            width: 28px; height: 28px; border-radius: 6px;
            border: 1px solid var(--border-color); background: var(--card-bg);
            color: var(--text-primary); font-size: 16px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s;
        }
        .btn-qty:hover { background: #6366f1; color: white; border-color: #6366f1; }

        /* TICKER PANEL */
        .ticket-panel {
            background: var(--card-bg); border: 2px solid var(--border-color);
            border-radius: 16px; box-shadow: var(--shadow-lg);
            padding: 20px; position: sticky; top: 80px;
        }
        .ticket-header {
            text-align: center; padding-bottom: 14px;
            border-bottom: 2px dashed var(--border-color); margin-bottom: 14px;
        }
        .ticket-brand { font-size: 18px; font-weight: 800; color: var(--text-primary); }
        .ticket-sub { font-size: 11px; color: var(--text-muted); letter-spacing: 2px; text-transform: uppercase; }
        .ticket-num { font-size: 12px; color: var(--text-secondary); margin-top: 4px; }
        .ticket-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 6px 0; font-size: 13px;
        }
        .ticket-row.separator { border-top: 1px dashed var(--border-color); margin-top: 8px; padding-top: 12px; }
        .ticket-total-label { font-size: 13px; font-weight: 700; color: var(--text-secondary); }
        .ticket-total-value {
            font-size: 28px; font-weight: 900;
            font-family: 'Courier New', monospace; transition: color 0.3s;
        }
        .ticket-total-value.paid   { color: #10b981; }
        .ticket-total-value.unpaid { color: #ef4444; }
        .badge-solde-t   { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .badge-partiel-t { background: #fef9c3; color: #854d0e; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .badge-nonpaye-t { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .btn-valider {
            width: 100%; padding: 14px; border: none; border-radius: 12px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white; font-size: 16px; font-weight: 800;
            cursor: pointer; margin-top: 16px;
            transition: all 0.3s; box-shadow: 0 4px 14px rgba(16,185,129,0.4);
        }
        .btn-valider:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,185,129,0.5); }
        .live-clock {
            font-size: 13px; font-weight: 600; color: var(--text-secondary);
            background: var(--content-bg); border: 1px solid var(--border-color);
            border-radius: 8px; padding: 6px 12px; text-align: center;
            font-family: 'Courier New', monospace; margin-bottom: 12px;
        }

        /* INLINE TABLE INPUTS */
        .table-input {
            background: var(--content-bg); border: 1px solid var(--border-color);
            border-radius: 6px; padding: 6px 10px; font-size: 13px;
            color: var(--text-primary); font-family: 'Inter', sans-serif;
            transition: all var(--transition);
        }
        .table-input:focus { outline: none; border-color: var(--accent); }
        .line-total { font-weight: 700; color: var(--text-primary); white-space: nowrap; }
        .btn-remove-line {
            width: 28px; height: 28px; border-radius: 6px; border: none;
            background: rgba(239,68,68,0.1); color: #ef4444; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; transition: all 0.15s;
        }
        .btn-remove-line:hover { background: rgba(239,68,68,0.2); }

        /* VALIDATION ERROR */
        .form-error-msg {
            background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2);
            color: #ef4444; border-radius: 8px; padding: 10px 14px;
            font-size: 13px; margin-top: 10px; display: none;
        }

        /* RESPONSIVE */
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
        @media (max-width: 768px) {
            .pos-layout { flex-direction: column; }
            .pos-right { width: 100%; position: static; }
            .ticket-panel { position: static; }
            #page-content { padding: 16px; }
            .search-controls { flex-wrap: wrap; }
        }
        [data-theme="dark"] ::-webkit-scrollbar { width: 6px; }
        [data-theme="dark"] ::-webkit-scrollbar-track { background: #0f172a; }
        [data-theme="dark"] ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<!-- ════════ SIDEBAR ════════ -->
<aside id="sidebar" aria-label="Navigation principale">
    <a href="../dashboard.php" class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-chart-pie"></i></div>
        <span class="brand-text">G-<span>Business</span></span>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Menu Principal</div>
        <div class="nav-item">
            <a href="../dashboard.php" class="nav-link-item" data-label="Dashboard" id="nav-dashboard">
                <i class="fa-solid fa-house nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>
        <?php if ($rank === 'admin'): ?>
        <div class="nav-item">
            <a href="../utilisateurs.php" class="nav-link-item" data-label="Utilisateurs" id="nav-users">
                <i class="fa-solid fa-users-gear nav-icon"></i>
                <span class="nav-text">Utilisateurs</span>
                <span class="nav-badge">Admin</span>
            </a>
        </div>
        <?php endif; ?>
        <div class="nav-item">
            <a href="../products.php" class="nav-link-item" data-label="Produits" id="nav-products">
                <i class="fa-solid fa-boxes-stacked nav-icon"></i>
                <span class="nav-text">Produits</span>
            </a>
        </div>
        <div class="nav-section-label">Transactions</div>
        <div class="nav-item">
            <a href="achats.php" class="nav-link-item active" data-label="Achats" id="nav-achats">
                <i class="fa-solid fa-cart-shopping nav-icon"></i>
                <span class="nav-text">Achats</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="../ventes/ventes.php" class="nav-link-item" data-label="Ventes" id="nav-ventes">
                <i class="fa-solid fa-money-bill-wave nav-icon"></i>
                <span class="nav-text">Ventes</span>
            </a>
        </div>
        <div class="nav-section-label">Répertoire</div>
        <div class="nav-item">
            <a href="../clients.php" class="nav-link-item" data-label="Clients" id="nav-clients">
                <i class="fa-solid fa-user-tie nav-icon"></i>
                <span class="nav-text">Clients</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="../fournisseurs.php" class="nav-link-item" data-label="Fournisseurs" id="nav-fournisseurs">
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
        <a href="../logout.php" class="btn-logout" title="Déconnexion">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>
</aside>

<!-- ════════ MAIN ════════ -->
<div id="main-wrapper">
    <header id="topbar">
        <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="topbar-title">
            <?= htmlspecialchars($page_title) ?>
            <small><?= $edit_achat ? 'N° ' . htmlspecialchars($edit_achat['num']) : 'N° ' . htmlspecialchars($auto_num) ?></small>
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
                    <a href="../logout.php" class="danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Déconnexion</a>
                </div>
            </div>
        </div>
    </header>

    <main id="page-content">

        <!-- PAGE HEADER -->
        <div class="d-flex align-items-center justify-content-between mb-4" style="flex-wrap:wrap;gap:12px;">
            <div>
                <h1 style="font-size:22px;font-weight:800;color:var(--text-primary);margin:0;">
                    <i class="fa-solid fa-<?= $edit_achat ? 'pen-to-square' : 'cart-plus' ?> me-2" style="color:#6366f1;"></i>
                    <?= htmlspecialchars($page_title) ?>
                </h1>
                <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;">
                    <?= $edit_achat ? 'Modifiez les détails de cet achat' : 'Créez un nouveau bon d\'achat fournisseur' ?>
                </p>
            </div>
            <a href="achats.php" class="btn-secondary-custom" id="btn-back-list">
                <i class="fa-solid fa-arrow-left"></i> Retour à la liste
            </a>
        </div>

        <!-- SAVE ERROR -->
        <?php if ($save_error): ?>
            <div class="alert-custom alert-danger" id="saveError">
                <i class="fa-solid fa-exclamation-triangle"></i>
                <?= htmlspecialchars($save_error) ?>
            </div>
        <?php endif; ?>

        <!-- MAIN FORM -->
        <form method="POST" action="_save.php" id="bonAchatForm" novalidate>
            <?php if ($edit_id): ?>
                <input type="hidden" name="edit_id" value="<?= (int)$edit_id ?>">
            <?php endif; ?>
            <input type="hidden" name="num"        id="hiddenNum"  value="<?= htmlspecialchars($edit_achat['num'] ?? $auto_num) ?>">
            <input type="hidden" name="date_achat" id="hiddenDate" value="<?= date('Y-m-d H:i:s') ?>">

            <div class="pos-layout">

                <!-- ══ LEFT: SEARCH + TABLE ══ -->
                <div class="pos-left">

                    <!-- SEARCH CONTROLS -->
                    <div class="section-card" style="margin-bottom:20px;">
                        <div class="section-card-header">
                            <div class="section-card-title">
                                <i class="fa-solid fa-magnifying-glass"></i> Rechercher un produit
                            </div>
                        </div>
                        <div style="padding:16px 20px;">
                            <div class="search-controls">
                                <div class="prod-search-wrap" style="flex:1;min-width:200px;">
                                    <input type="text" id="prodSearch" class="prod-search-input"
                                           placeholder="Rechercher un produit..."
                                           autocomplete="off" spellcheck="false">
                                    <div class="prod-dropdown" id="prodDropdown"></div>
                                </div>
                                <div>
                                    <label class="form-label-custom">Quantité</label>
                                    <input type="number" id="qtyInput" class="form-control-custom"
                                           value="1" min="0.001" step="0.001"
                                           style="width:100px;">
                                </div>
                                <div>
                                    <label class="form-label-custom">P.Achat (DA)</label>
                                    <input type="number" id="prixInput" class="form-control-custom"
                                           value="" min="0" step="0.01" placeholder="Auto"
                                           style="width:120px;">
                                </div>
                                <div style="align-self:flex-end;">
                                    <button type="button" id="btnValiderProduit" class="btn-primary-custom" style="padding:9px 16px;">
                                        <i class="fa-solid fa-plus"></i> Valider
                                    </button>
                                </div>
                            </div>
                            <div id="formErrorSearch" class="form-error-msg"></div>
                        </div>
                    </div>

                    <!-- PRODUCT TABLE -->
                    <div class="section-card">
                        <div class="section-card-header">
                            <div class="section-card-title">
                                <i class="fa-solid fa-list"></i> Produits ajoutés
                                <span id="countBadge" style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:4px;">— 0 article</span>
                            </div>
                        </div>
                        <div style="overflow-x:auto;min-height:120px;">
                            <table class="table-custom" id="detailTable">
                                <thead>
                                    <tr>
                                        <th>Réf</th>
                                        <th>Nom du produit</th>
                                        <th>Prix Unitaire (DA)</th>
                                        <th>Qté</th>
                                        <th>PU × Qté</th>
                                        <th style="text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="detailLines">
                                    <!-- JS generated rows -->
                                </tbody>
                            </table>
                            <div id="emptyState" class="empty-state">
                                <i class="fa-solid fa-box-open"></i>
                                <p>Aucun produit ajouté. Utilisez la recherche ci-dessus.</p>
                            </div>
                        </div>
                    </div>
                </div><!-- /pos-left -->

                <!-- ══ RIGHT: TICKET PANEL ══ -->
                <div class="pos-right">
                    <!-- FOURNISSEUR SELECT -->
                    <div class="section-card" style="margin-bottom:16px;">
                        <div style="padding:16px;">
                            <label class="form-label-custom" for="selectFournisseur">Fournisseur *</label>
                            <select name="id_fournisseur" id="selectFournisseur" class="form-control-custom" required>
                                <option value="">-- Sélectionner un fournisseur --</option>
                                <?php foreach ($all_fournisseurs as $f): ?>
                                    <option value="<?= (int)$f['id'] ?>"
                                        <?= ($edit_achat && $edit_achat['id_fournisseur'] == $f['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($f['nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="margin-top:8px;">
                                <button type="button"
                                        class="btn-secondary-custom"
                                        style="padding:7px 14px;font-size:12px;width:100%;justify-content:center;"
                                        id="btn-add-fournisseur"
                                        onclick="openAddFournisseurModal()">
                                    <i class="fa-solid fa-plus"></i> Ajouter un fournisseur
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- LIVE CLOCK -->
                    <div class="live-clock" id="liveClock">— : — : —</div>

                    <!-- TICKET -->
                    <div class="ticket-panel">
                        <div class="ticket-header">
                            <div class="ticket-brand">🏪 G-Business</div>
                            <div class="ticket-sub">Bon d'Achat</div>
                            <div class="ticket-num">N° <?= htmlspecialchars($edit_achat['num'] ?? $auto_num) ?></div>
                        </div>

                        <div class="ticket-row">
                            <span style="color:var(--text-secondary);">Articles</span>
                            <span id="ticketArticles" style="font-weight:700;">0 produit</span>
                        </div>
                        <div class="ticket-row">
                            <span style="color:var(--text-secondary);">Sous-total</span>
                            <span id="ticketSousTotal" style="font-weight:700;">0,00 DA</span>
                        </div>

                        <div class="ticket-row separator">
                            <span class="ticket-total-label">TOTAL</span>
                        </div>
                        <div style="text-align:center;padding:8px 0 12px;">
                            <span class="ticket-total-value unpaid" id="grandTotal">0,00 DA</span>
                        </div>

                        <div style="border-top: 1px dashed var(--border-color); padding-top:12px; margin-top:4px;">
                            <label class="form-label-custom" for="versementInput">Montant versé (DA)</label>
                            <input type="number" name="versement" id="versementInput" class="form-control-custom"
                                   value="<?= htmlspecialchars($edit_achat['versement'] ?? '0') ?>"
                                   min="0" step="0.01" placeholder="0.00">
                        </div>

                        <div class="ticket-row" style="margin-top:10px;">
                            <span style="color:var(--text-secondary);font-size:13px;">Reste</span>
                            <span id="ticketReste" style="font-weight:700;font-size:14px;color:#ef4444;">0,00 DA</span>
                        </div>
                        <div class="ticket-row">
                            <span style="color:var(--text-secondary);font-size:13px;">Statut</span>
                            <span id="ticketStatut" class="badge-nonpaye-t">❌ Non payé</span>
                        </div>

                        <div id="formError" class="form-error-msg"></div>

                        <button type="submit" class="btn-valider" id="btnValiderAchat">
                            <i class="fa-solid fa-check-circle"></i>
                            <?= $edit_achat ? 'Mettre à jour' : "Valider l'Achat" ?>
                        </button>
                        <a href="achats.php" class="btn-secondary-custom" style="width:100%;justify-content:center;margin-top:10px;" id="btn-annuler">
                            <i class="fa-solid fa-xmark"></i> Annuler
                        </a>
                    </div>
                </div><!-- /pos-right -->

            </div><!-- /pos-layout -->
        </form>

    </main>
</div><!-- /main-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── THEME ── */
const htmlEl = document.documentElement;
const themeBtn = document.getElementById('themeToggle');
const themeIco = document.getElementById('themeIcon');
function applyTheme(t) {
    htmlEl.setAttribute('data-theme', t);
    themeIco.className = t === 'dark' ? 'fa-solid fa-sun theme-toggle-icon' : 'fa-solid fa-moon theme-toggle-icon';
    localStorage.setItem('gbiz-theme', t);
}
(function(){ applyTheme(localStorage.getItem('gbiz-theme') || 'light'); })();
themeBtn.addEventListener('click', () => applyTheme(htmlEl.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));

/* ── SIDEBAR ── */
const sidebarToggle = document.getElementById('sidebarToggle');
const body = document.body;
function isMobile() { return window.innerWidth < 992; }
sidebarToggle.addEventListener('click', () => {
    if (isMobile()) { body.classList.toggle('sidebar-mobile-open'); }
    else {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('gbiz-sidebar', body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open');
    }
});
function closeMobileSidebar() { body.classList.remove('sidebar-mobile-open'); }
(function(){ if (!isMobile() && localStorage.getItem('gbiz-sidebar') === 'collapsed') body.classList.add('sidebar-collapsed'); })();
window.addEventListener('resize', () => { if (!isMobile()) body.classList.remove('sidebar-mobile-open'); });

/* ── AVATAR DROPDOWN ── */
const avatarBtn = document.getElementById('avatarBtn');
const avatarDropdown = document.getElementById('avatarDropdown');
avatarBtn.addEventListener('click', e => { e.stopPropagation(); avatarDropdown.classList.toggle('show'); });
document.addEventListener('click', () => avatarDropdown.classList.remove('show'));

/* ── LIVE CLOCK ── */
function updateClock() {
    const now = new Date();
    document.getElementById('liveClock').textContent =
        now.toISOString().slice(0,10) + ' ' + now.toTimeString().slice(0,8);
    document.getElementById('hiddenDate').value =
        now.toISOString().slice(0,10) + ' ' + now.toTimeString().slice(0,8);
}
updateClock();
setInterval(updateClock, 1000);

/* ── PRODUCT SEARCH ── */
const prodSearch  = document.getElementById('prodSearch');
const prodDropdown = document.getElementById('prodDropdown');
const qtyInput    = document.getElementById('qtyInput');
const prixInput   = document.getElementById('prixInput');
let   searchDebounce = null;
let   highlightedIdx = -1;
let   dropdownItems  = [];

prodSearch.addEventListener('keyup', (e) => {
    if (e.key === 'ArrowDown') { navigateDrop(1); return; }
    if (e.key === 'ArrowUp')   { navigateDrop(-1); return; }
    if (e.key === 'Enter')     { enterHighlighted(); return; }
    if (e.key === 'Escape')    { closeDrop(); return; }
    clearTimeout(searchDebounce);
    const q = prodSearch.value.trim();
    if (q.length < 1) { closeDrop(); return; }
    searchDebounce = setTimeout(() => doSearch(q), 250);
});

function doSearch(q) {
    fetch('_get_produits.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => renderDrop(data))
        .catch(() => closeDrop());
}

function renderDrop(items) {
    dropdownItems = items;
    highlightedIdx = -1;
    if (!items || items.length === 0) { prodDropdown.innerHTML = '<div style="padding:12px 14px;font-size:13px;color:var(--text-muted);">Aucun résultat</div>'; prodDropdown.classList.add('open'); return; }
    prodDropdown.innerHTML = items.map((p, i) => {
        const qte = parseFloat(p.qte || 0);
        let cls = 'stock-ok', badgeCls = 'badge-en-stock', badgeLabel = 'En stock';
        if (qte <= 0) { cls = 'stock-empty'; badgeCls = 'badge-rupture'; badgeLabel = 'Rupture'; }
        else if (qte <= 10) { cls = 'stock-low'; badgeCls = 'badge-faible'; badgeLabel = 'Faible'; }
        return `<div class="prod-drop-item ${cls}" data-idx="${i}">
            <span class="prod-drop-name">${escHtml(p.nom)}</span>
            <span class="prod-drop-meta">${fmtNum(p.prix_achat)} DA&nbsp;&nbsp;|&nbsp;&nbsp;vente: ${fmtNum(p.prix_vente)} DA&nbsp;&nbsp;|&nbsp;&nbsp;${fmtQte(qte)} u.</span>
            <span class="prod-drop-badge ${badgeCls}">${badgeLabel}</span>
            <button type="button" class="btn-entrer" data-idx="${i}">Entrer</button>
        </div>`;
    }).join('');
    prodDropdown.classList.add('open');

    prodDropdown.querySelectorAll('.btn-entrer').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const item = dropdownItems[parseInt(btn.dataset.idx)];
            if (item) addProductToTable(item.id, item.nom, item.prix_achat, qtyInput.value || 1);
        });
    });
    prodDropdown.querySelectorAll('.prod-drop-item').forEach(el => {
        el.addEventListener('click', (e) => {
            if (e.target.classList.contains('btn-entrer')) return;
            const item = dropdownItems[parseInt(el.dataset.idx)];
            if (item) addProductToTable(item.id, item.nom, item.prix_achat, qtyInput.value || 1);
        });
    });
}

function navigateDrop(dir) {
    const els = prodDropdown.querySelectorAll('.prod-drop-item');
    if (!els.length) return;
    els.forEach(e => e.classList.remove('highlighted'));
    highlightedIdx = Math.max(0, Math.min(els.length - 1, highlightedIdx + dir));
    els[highlightedIdx].classList.add('highlighted');
    if (dropdownItems[highlightedIdx]) {
        prixInput.value = dropdownItems[highlightedIdx].prix_achat;
    }
}

function enterHighlighted() {
    if (highlightedIdx >= 0 && dropdownItems[highlightedIdx]) {
        const p = dropdownItems[highlightedIdx];
        addProductToTable(p.id, p.nom, p.prix_achat, qtyInput.value || 1);
    }
}

function closeDrop() {
    prodDropdown.classList.remove('open');
    prodDropdown.innerHTML = '';
    highlightedIdx = -1;
    dropdownItems  = [];
}

document.addEventListener('click', (e) => {
    if (!prodDropdown.contains(e.target) && e.target !== prodSearch) closeDrop();
});

document.getElementById('btnValiderProduit').addEventListener('click', () => { enterHighlighted(); });

/* ── ADD PRODUCT TO TABLE ── */
function addProductToTable(id, nom, prix, qte) {
    const tbody = document.getElementById('detailLines');
    const existing = tbody.querySelector(`tr[data-id="${id}"]`);
    if (existing) {
        const qteInp = existing.querySelector('input[name="detail_qte[]"]');
        qteInp.value = (parseFloat(qteInp.value) + parseFloat(qte || 1)).toFixed(3).replace(/\.?0+$/, '') || '1';
        calcTotals();
        closeDrop(); prodSearch.value = '';
        return;
    }
    const pr = parseFloat(prixInput.value || prix || 0);
    const qt = parseFloat(qte || 1);
    const tr = document.createElement('tr');
    tr.dataset.id = id;
    tr.innerHTML = `
        <td style="color:var(--text-muted);font-size:12px;">${escHtml(String(id))}</td>
        <td style="font-weight:700;color:var(--text-primary);">${escHtml(nom)}</td>
        <td>
            <input type="number" name="detail_prix[]" class="table-input" value="${pr.toFixed(2)}" min="0" step="0.01"
                   onchange="calcTotals()" oninput="calcTotals()" style="width:110px;">
        </td>
        <td>
            <div class="qty-control">
                <button type="button" class="btn-qty" onclick="changeQty(this,-1)">−</button>
                <input type="number" name="detail_qte[]" class="table-input" value="${qt}" min="0.001" step="0.001"
                       onchange="calcTotals()" oninput="calcTotals()" style="width:70px;">
                <button type="button" class="btn-qty" onclick="changeQty(this,1)">+</button>
            </div>
            <input type="hidden" name="detail_produit[]" value="${id}">
        </td>
        <td class="line-total">${fmtNum(pr * qt)} DA</td>
        <td style="text-align:center;">
            <button type="button" class="btn-remove-line" onclick="removeLine(this)" title="Supprimer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    calcTotals();
    closeDrop();
    prodSearch.value = '';
    prixInput.value  = '';
    qtyInput.value   = '1';
}

function changeQty(btn, delta) {
    const inp = btn.closest('.qty-control').querySelector('input[name="detail_qte[]"]');
    let v = parseFloat(inp.value) + delta;
    if (v < 0.001) v = 0.001;
    inp.value = v;
    calcTotals();
}

function removeLine(btn) {
    btn.closest('tr').remove();
    calcTotals();
}

/* ── CALC TOTALS ── */
function calcTotals() {
    const rows     = document.getElementById('detailLines').querySelectorAll('tr');
    const emptyDiv = document.getElementById('emptyState');
    const countBdg = document.getElementById('countBadge');
    emptyDiv.style.display = rows.length === 0 ? 'block' : 'none';
    document.getElementById('detailTable').style.display = rows.length === 0 ? 'none' : '';

    let total = 0, count = rows.length;
    rows.forEach(tr => {
        const pInp = tr.querySelector('input[name="detail_prix[]"]');
        const qInp = tr.querySelector('input[name="detail_qte[]"]');
        const totCell = tr.querySelector('.line-total');
        const p = parseFloat(pInp?.value || 0);
        const q = parseFloat(qInp?.value || 0);
        const line = p * q;
        total += line;
        if (totCell) totCell.textContent = fmtNum(line) + ' DA';
    });

    const versement = parseFloat(document.getElementById('versementInput').value || 0);
    const reste     = Math.max(0, total - versement);

    document.getElementById('ticketArticles').textContent = count + ' produit' + (count > 1 ? 's' : '');
    document.getElementById('ticketSousTotal').textContent = fmtNum(total) + ' DA';
    document.getElementById('grandTotal').textContent = fmtNum(total) + ' DA';
    document.getElementById('ticketReste').textContent = fmtNum(reste) + ' DA';
    document.getElementById('ticketReste').style.color = reste > 0 ? '#ef4444' : '#10b981';
    countBdg.textContent = '— ' + count + ' article' + (count > 1 ? 's' : '');

    const gtEl = document.getElementById('grandTotal');
    const statutEl = document.getElementById('ticketStatut');
    if (versement >= total && total > 0) {
        gtEl.className = 'ticket-total-value paid';
        statutEl.className = 'badge-solde-t';
        statutEl.textContent = '✅ Soldé';
    } else if (versement > 0) {
        gtEl.className = 'ticket-total-value unpaid';
        statutEl.className = 'badge-partiel-t';
        statutEl.textContent = '⚡ Partiel';
    } else {
        gtEl.className = 'ticket-total-value unpaid';
        statutEl.className = 'badge-nonpaye-t';
        statutEl.textContent = '❌ Non payé';
    }
}

document.getElementById('versementInput').addEventListener('input', calcTotals);

/* ── PRE-FILL EDIT MODE ── */
<?php if ($edit_details): ?>
(function() {
    const editLines = <?= json_encode(array_map(function($d) {
        return [
            'id'       => (int)$d['id_produit'],
            'nom'      => $d['produit_nom'] ?? '—',
            'prix'     => floatval($d['prix_achat']),
            'qte'      => floatval($d['qte']),
        ];
    }, $edit_details)) ?>;
    editLines.forEach(l => addProductToTable(l.id, l.nom, l.prix, l.qte));
})();
<?php endif; ?>
calcTotals();

/* Show/hide empty state on load */
(function() {
    const rows = document.getElementById('detailLines').querySelectorAll('tr');
    document.getElementById('emptyState').style.display = rows.length === 0 ? 'block' : 'none';
    document.getElementById('detailTable').style.display = rows.length === 0 ? 'none' : '';
})();

/* ── FORM VALIDATION ── */
document.getElementById('bonAchatForm').addEventListener('submit', function(e) {
    const errPanel  = document.getElementById('formError');
    const errSearch = document.getElementById('formErrorSearch');
    errPanel.style.display = 'none';
    errSearch.style.display = 'none';

    const fournisseur = document.getElementById('selectFournisseur').value;
    const rows = document.getElementById('detailLines').querySelectorAll('tr');

    if (!fournisseur) {
        e.preventDefault();
        errPanel.textContent = 'Veuillez sélectionner un fournisseur.';
        errPanel.style.display = 'block';
        document.getElementById('selectFournisseur').focus();
        return;
    }
    if (rows.length === 0) {
        e.preventDefault();
        errSearch.textContent = 'Veuillez ajouter au moins un produit.';
        errSearch.style.display = 'block';
        return;
    }
    let valid = true;
    rows.forEach(tr => {
        const pid = tr.dataset.id;
        const qte = parseFloat(tr.querySelector('input[name="detail_qte[]"]')?.value || 0);
        if (!pid || qte <= 0) valid = false;
    });
    if (!valid) {
        e.preventDefault();
        errSearch.textContent = 'Certaines lignes contiennent des données invalides.';
        errSearch.style.display = 'block';
    }
});

/* ── HELPERS ── */
function fmtNum(n) {
    return parseFloat(n || 0).toLocaleString('fr-FR', { minimumFractionDigits:2, maximumFractionDigits:2 });
}
function fmtQte(n) {
    return parseFloat(n || 0).toLocaleString('fr-FR', { minimumFractionDigits:0, maximumFractionDigits:3 });
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ════════════════════════════════
   MODAL — AJOUTER FOURNISSEUR
════════════════════════════════ */

function openAddFournisseurModal() {
    // Reset fields and error
    document.getElementById('mf_nom').value       = '';
    document.getElementById('mf_telephone').value = '';
    document.getElementById('mf_adresse').value   = '';
    document.getElementById('modalFournisseurError').style.display = 'none';
    document.getElementById('modalFournisseurError').textContent   = '';

    // Show modal as flex
    const modal = document.getElementById('modalFournisseur');
    modal.style.display = 'flex';

    // Focus on name input
    setTimeout(() => document.getElementById('mf_nom').focus(), 80);
}

function closeAddFournisseurModal() {
    document.getElementById('modalFournisseur').style.display = 'none';
}

// Close modal on backdrop click
document.getElementById('modalFournisseur').addEventListener('click', function(e) {
    if (e.target === this) closeAddFournisseurModal();
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('modalFournisseur');
        if (modal.style.display === 'flex') closeAddFournisseurModal();
    }
});

// Allow pressing Enter in mf_nom or mf_telephone to trigger save
['mf_nom', 'mf_telephone'].forEach(id => {
    document.getElementById(id).addEventListener('keydown', function(e) {
        if (e.key === 'Enter') saveFournisseur();
    });
});

function saveFournisseur() {
    const nom       = document.getElementById('mf_nom').value.trim();
    const telephone = document.getElementById('mf_telephone').value.trim();
    const adresse   = document.getElementById('mf_adresse').value.trim();
    const errEl     = document.getElementById('modalFournisseurError');
    const btn       = document.getElementById('btnSaveFournisseur');
    const spinner   = document.getElementById('btnSaveFournisseurSpinner');
    const icon      = document.getElementById('btnSaveFournisseurIcon');

    // Client-side validation
    if (nom === '') {
        errEl.textContent   = 'Le nom du fournisseur est obligatoire.';
        errEl.style.display = 'block';
        document.getElementById('mf_nom').focus();
        return;
    }

    // Loading state
    errEl.style.display = 'none';
    btn.disabled        = true;
    spinner.style.display = 'inline-block';
    icon.style.display    = 'none';

    // AJAX POST to _add_fournisseur.php
    const formData = new FormData();
    formData.append('nom',       nom);
    formData.append('telephone', telephone);
    formData.append('adresse',   adresse);

    fetch('_add_fournisseur.php', {
        method: 'POST',
        body:   formData
    })
    .then(r => r.json())
    .then(data => {
        // Reset loading state
        btn.disabled          = false;
        spinner.style.display = 'none';
        icon.style.display    = '';

        if (!data.success) {
            errEl.textContent   = data.error || 'Erreur inconnue.';
            errEl.style.display = 'block';
            return;
        }

        // ── SUCCESS ──
        // 1. Rebuild the <select> with all fournisseurs from server response
        const select = document.getElementById('selectFournisseur');
        select.innerHTML = '<option value="">-- Sélectionner un fournisseur --</option>';
        data.all_fournisseurs.forEach(f => {
            const opt = document.createElement('option');
            opt.value       = f.id;
            opt.textContent = f.nom;
            if (f.id === data.new_id) opt.selected = true;
            select.appendChild(opt);
        });

        // 2. Auto-select the newly added fournisseur
        select.value = data.new_id;

        // 3. Show brief success feedback on the button label area
        const confirmMsg = document.createElement('div');
        confirmMsg.style.cssText = 'font-size:12px;color:#10b981;font-weight:600;margin-top:6px;text-align:center;';
        confirmMsg.textContent   = '✅ Fournisseur "' + escHtml(data.new_nom) + '" ajouté et sélectionné !';
        select.closest('div').appendChild(confirmMsg);
        setTimeout(() => confirmMsg.remove(), 3500);

        // 4. Close the modal
        closeAddFournisseurModal();
    })
    .catch(err => {
        btn.disabled          = false;
        spinner.style.display = 'none';
        icon.style.display    = '';
        errEl.textContent     = 'Erreur réseau. Vérifiez votre connexion.';
        errEl.style.display   = 'block';
    });
}
</script>

<!-- ════ MODAL: AJOUTER FOURNISSEUR ════ -->
<div id="modalFournisseur" style="
    display:none; position:fixed; inset:0; z-index:9998;
    background:rgba(0,0,0,0.55); backdrop-filter:blur(3px);
    align-items:center; justify-content:center;">

    <div style="
        background:var(--card-bg); border:1px solid var(--border-color);
        border-radius:var(--radius); box-shadow:var(--shadow-lg);
        padding:28px; width:100%; max-width:420px;
        margin:16px; position:relative; animation:fadeIn 0.2s ease;">

        <!-- Close button -->
        <button type="button" onclick="closeAddFournisseurModal()" style="
            position:absolute; top:14px; right:14px;
            background:transparent; border:none; cursor:pointer;
            font-size:18px; color:var(--text-muted); line-height:1;"
            aria-label="Fermer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <!-- Title -->
        <div style="font-size:16px;font-weight:800;color:var(--text-primary);margin-bottom:4px;">
            <i class="fa-solid fa-truck" style="color:#f97316;margin-right:8px;"></i>
            Nouveau Fournisseur
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:20px;">
            Le fournisseur sera ajouté et sélectionné automatiquement.
        </div>

        <!-- Error message -->
        <div id="modalFournisseurError" style="
            display:none; background:rgba(239,68,68,0.1); color:#ef4444;
            border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-sm);
            padding:10px 14px; font-size:13px; margin-bottom:14px;">
        </div>

        <!-- Form fields -->
        <div style="display:flex;flex-direction:column;gap:14px;">

            <div>
                <label class="form-label-custom" for="mf_nom">
                    Nom du fournisseur *
                </label>
                <input type="text" id="mf_nom" class="form-control-custom"
                       placeholder="Ex: Maxon Livreur"
                       autocomplete="off">
            </div>

            <div>
                <label class="form-label-custom" for="mf_telephone">
                    Téléphone
                </label>
                <input type="tel" id="mf_telephone" class="form-control-custom"
                       placeholder="Ex: 0555 123 456">
            </div>

            <div>
                <label class="form-label-custom" for="mf_adresse">
                    Adresse
                </label>
                <textarea id="mf_adresse" class="form-control-custom"
                          rows="2" placeholder="Adresse optionnelle..."
                          style="resize:vertical;"></textarea>
            </div>

        </div>

        <!-- Buttons -->
        <div style="display:flex;gap:10px;margin-top:20px;">
            <button type="button" id="btnSaveFournisseur"
                    onclick="saveFournisseur()"
                    class="btn-primary-custom" style="flex:1;justify-content:center;">
                <span id="btnSaveFournisseurSpinner"
                      style="display:none;width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);
                             border-top-color:white;border-radius:50%;
                             animation:spin 0.6s linear infinite;"></span>
                <i class="fa-solid fa-check" id="btnSaveFournisseurIcon"></i>
                Ajouter
            </button>
            <button type="button" onclick="closeAddFournisseurModal()"
                    class="btn-secondary-custom" style="padding:10px 18px;">
                Annuler
            </button>
        </div>

    </div>
</div>
</body>
</html>

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

// ─── FLASH MESSAGE ──────────────────────────────────────────────────────────
$msg_map = [
    'created' => ['Achat créé avec succès.',    'success'],
    'updated' => ['Achat modifié avec succès.', 'success'],
    'deleted' => ['Achat supprimé avec succès.','success'],
    'error'   => ["Une erreur s'est produite.", 'danger'],
];
$flash_msg  = '';
$flash_type = '';
$msg_key = trim($_GET['msg'] ?? '');
if (isset($msg_map[$msg_key])) {
    [$flash_msg, $flash_type] = $msg_map[$msg_key];
}

// ─── SEARCH & FILTER ────────────────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$search    = trim($_GET['search']    ?? '');

$where  = [];
$params = [];

if ($date_from !== '') {
    $where[]  = 'DATE(a.date) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $where[]  = 'DATE(a.date) <= ?';
    $params[] = $date_to;
}
if ($search !== '') {
    $where[]  = '(a.num LIKE ? OR f.nom LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ─── MAIN QUERY ─────────────────────────────────────────────────────────────
$sql = "
    SELECT
        a.id, a.num, a.date,
        f.nom AS fournisseur,
        COUNT(ad.id)                              AS nb_articles,
        COALESCE(SUM(ad.prix_achat * ad.qte), 0) AS montant_total,
        a.versement
    FROM achats a
    LEFT JOIN fournisseurs f  ON f.id  = a.id_fournisseur
    LEFT JOIN achat_details ad ON ad.id_achat = a.id
    $whereSQL
    GROUP BY a.id, a.num, a.date, f.nom, a.versement
    ORDER BY a.date DESC
";

$achats = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $achats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $achats = [];
}
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Achats — G-Business</title>
    <meta name="description" content="Consultez et gérez l'historique complet de vos achats fournisseurs.">

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
            font-family: 'Inter', sans-serif;
            background: var(--content-bg);
            color: var(--text-primary);
            transition: background var(--transition), color var(--transition);
            min-height: 100vh; overflow-x: hidden;
        }

        /* ── SIDEBAR ── */
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

        /* ── MAIN WRAPPER ── */
        #main-wrapper {
            margin-left: var(--sidebar-w); min-height: 100vh;
            display: flex; flex-direction: column;
            transition: margin-left var(--transition);
        }
        body.sidebar-collapsed #main-wrapper { margin-left: var(--sidebar-w-sm); }

        /* ── TOPBAR ── */
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

        /* ── PAGE CONTENT ── */
        #page-content { flex: 1; padding: 28px; }

        /* ── SECTION CARD ── */
        .section-card {
            background: var(--card-bg); border: 1px solid var(--card-border);
            border-radius: var(--radius); box-shadow: var(--shadow-sm);
            transition: background var(--transition), border-color var(--transition);
            overflow: hidden;
        }
        .section-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 22px 14px; border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap; gap: 10px;
        }
        .section-card-title {
            font-size: 15px; font-weight: 700; color: var(--text-primary);
            display: flex; align-items: center; gap: 10px;
        }
        .section-card-title i { color: var(--accent); font-size: 14px; }

        /* ── TABLE ── */
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
        .table-custom td { padding: 13px 14px; color: var(--text-secondary); vertical-align: middle; }
        .table-custom td.td-main { font-weight: 700; color: var(--text-primary); }

        /* ── BADGES ── */
        .badge-custom {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px;
        }
        .badge-solde   { background: rgba(16,185,129,0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.2); }
        .badge-partiel { background: rgba(245,158,11,0.1);  color: #f59e0b; border: 1px solid rgba(245,158,11,0.2); }
        .badge-nonpaye { background: rgba(239,68,68,0.1);   color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }
        .badge-pill-blue {
            background: rgba(59,130,246,0.1); color: #3b82f6;
            font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px;
            display: inline-block;
        }

        /* ── ACTION BTN ── */
        .btn-action {
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-color);
            background: transparent; color: var(--text-secondary);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 13px; transition: all var(--transition); text-decoration: none;
        }
        .btn-action:hover { background: var(--border-color); color: var(--text-primary); }
        .btn-action.view:hover { background: rgba(59,130,246,0.1); color: #3b82f6; border-color: rgba(59,130,246,0.3); }

        /* ── PRIMARY / SECONDARY BUTTONS ── */
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

        /* ── ALERT ── */
        .alert-custom {
            padding: 12px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: rgba(16,185,129,0.1); color: #10b981; border: 1px solid rgba(16,185,129,0.2); }
        .alert-danger  { background: rgba(239,68,68,0.1);  color: #ef4444; border: 1px solid rgba(239,68,68,0.2); }

        /* ── FILTER BAR ── */
        .filter-bar {
            display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap;
            background: var(--content-bg); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 16px 18px; margin-bottom: 24px;
        }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; }
        .filter-input {
            background: var(--card-bg); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 9px 13px; font-size: 13px;
            color: var(--text-primary); font-family: 'Inter', sans-serif;
            transition: all var(--transition);
        }
        .filter-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
        .filter-divider {
            height: 32px; width: 1px; background: var(--border-color);
            margin: 0 6px; align-self: flex-end; margin-bottom: 2px;
        }
        .filter-or {
            font-size: 11px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 1px;
            align-self: flex-end; padding-bottom: 12px;
        }

        /* ── FORM CONTROL ── */
        .form-control-custom {
            background: var(--content-bg); border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 10px 14px; font-size: 13px;
            color: var(--text-primary); font-family: 'Inter', sans-serif;
            transition: all var(--transition); width: 100%;
        }
        .form-control-custom:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }

        /* ── EMPTY STATE ── */
        .empty-state { text-align: center; padding: 56px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 40px; margin-bottom: 14px; opacity: 0.35; display: block; }
        .empty-state p { font-size: 13px; }

        /* ── MODAL CUSTOM ── */
        .modal-detail-header { background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; border-radius: 14px 14px 0 0; padding: 20px 24px; }
        .modal-detail-header .modal-title { font-size: 16px; font-weight: 700; }
        .modal-detail-body { padding: 22px 24px; }
        .modal-info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
        .modal-info-item { background: var(--content-bg); border-radius: 10px; padding: 12px 14px; }
        .modal-info-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); margin-bottom: 4px; }
        .modal-info-value { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .modal-total-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 13px; border-top: 1px solid var(--border-color); }
        .modal-total-row.grand { font-weight: 800; font-size: 15px; }

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
        @media (max-width: 767px) {
            #page-content { padding: 16px; }
            .topbar-title small { display: none; }
            .modal-info-grid { grid-template-columns: 1fr 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-divider, .filter-or { display: none; }
        }
        [data-theme="dark"] ::-webkit-scrollbar { width: 6px; height: 6px; }
        [data-theme="dark"] ::-webkit-scrollbar-track { background: #0f172a; }
        [data-theme="dark"] ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
        [data-theme="dark"] .filter-input { background: var(--card-bg); }
        [data-theme="dark"] .modal-content { background: var(--card-bg); }
        [data-theme="dark"] .modal-info-item { background: rgba(255,255,255,0.04); }
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

    <!-- TOPBAR -->
    <header id="topbar">
        <button class="topbar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="topbar-title">
            Historique des Achats
            <small><?= count($achats) ?> achat<?= count($achats) > 1 ? 's' : '' ?> trouvé<?= count($achats) > 1 ? 's' : '' ?></small>
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

    <!-- PAGE CONTENT -->
    <main id="page-content">

        <!-- PAGE HEADER -->
        <div class="d-flex align-items-center justify-content-between mb-4" style="flex-wrap:wrap;gap:12px;">
            <div>
                <h1 style="font-size:22px;font-weight:800;color:var(--text-primary);margin:0;">
                    <i class="fa-solid fa-clock-rotate-left me-2" style="color:#f97316;"></i>
                    Historique des Achats
                </h1>
                <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;">
                    Consultez et gérez tous vos bons d'achat fournisseurs
                </p>
            </div>
            <a href="bonAchat.php" class="btn-primary-custom" id="btn-nouvel-achat">
                <i class="fa-solid fa-plus"></i> Nouvel Achat
            </a>
        </div>

        <!-- FLASH MESSAGE -->
        <?php if ($flash_msg): ?>
            <div class="alert-custom alert-<?= $flash_type ?>" id="flashAlert">
                <i class="fa-solid fa-<?= $flash_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= htmlspecialchars($flash_msg) ?>
            </div>
        <?php endif; ?>

        <!-- SEARCH & FILTER BAR -->
        <form method="GET" action="achats.php" id="filterForm">
            <div class="filter-bar">
                <!-- Date range -->
                <div class="filter-group">
                    <label>Date début</label>
                    <input type="date" name="date_from" class="filter-input"
                           value="<?= htmlspecialchars($date_from) ?>"
                           id="input-date-from">
                </div>
                <div class="filter-group">
                    <label>Date fin</label>
                    <input type="date" name="date_to" class="filter-input"
                           value="<?= htmlspecialchars($date_to) ?>"
                           id="input-date-to">
                </div>
                <div class="filter-group" style="align-self:flex-end;">
                    <button type="submit" class="btn-primary-custom" style="padding:9px 16px;" id="btn-search-date">
                        <i class="fa-solid fa-magnifying-glass"></i> Rechercher
                    </button>
                </div>

                <div class="filter-divider"></div>
                <div class="filter-or">OU</div>
                <div class="filter-divider"></div>

                <!-- Free text search -->
                <div class="filter-group" style="flex:1;min-width:200px;">
                    <label>Recherche libre</label>
                    <input type="text" name="search" class="filter-input"
                           placeholder="N° BL, fournisseur..."
                           value="<?= htmlspecialchars($search) ?>"
                           id="input-search-text"
                           style="width:100%;">
                </div>
                <div class="filter-group" style="align-self:flex-end;">
                    <a href="achats.php" class="btn-secondary-custom" style="padding:9px 16px;" id="btn-show-all">
                        <i class="fa-solid fa-list"></i> Afficher tous
                    </a>
                </div>
            </div>
        </form>

        <!-- ACHATS TABLE -->
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title">
                    <i class="fa-solid fa-table-list"></i>
                    Liste des Achats
                    <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:4px;">
                        — <?= count($achats) ?> résultat<?= count($achats) > 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>

            <?php if (empty($achats)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    <p>Aucun achat trouvé<?= ($date_from || $date_to || $search) ? ' pour ce filtre' : '' ?>.</p>
                    <?php if ($date_from || $date_to || $search): ?>
                        <a href="achats.php" style="color:var(--accent);font-size:13px;margin-top:8px;display:inline-block;">
                            Effacer les filtres
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="table-custom" id="achatsTable">
                        <thead>
                            <tr>
                                <th>N° BL</th>
                                <th>Fournisseur</th>
                                <th>Date</th>
                                <th style="text-align:center;">Nb Articles</th>
                                <th style="text-align:center;">Statut</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($achats as $a):
                                $montant   = floatval($a['montant_total']);
                                $versement = floatval($a['versement']);
                                if ($versement >= $montant && $montant > 0) {
                                    $statut_class = 'badge-solde';
                                    $statut_label = '✅ Soldé';
                                } elseif ($versement > 0) {
                                    $statut_class = 'badge-partiel';
                                    $statut_label = '⚡ Partiel';
                                } else {
                                    $statut_class = 'badge-nonpaye';
                                    $statut_label = '❌ Non payé';
                                }
                                $date_fmt = date('d/m/Y H:i', strtotime($a['date']));
                            ?>
                            <tr>
                                <td class="td-main"><?= htmlspecialchars($a['num']) ?></td>
                                <td><?= $a['fournisseur'] ? htmlspecialchars($a['fournisseur']) : '<span style="color:var(--text-muted);">—</span>' ?></td>
                                <td style="white-space:nowrap;color:var(--text-secondary);"><?= $date_fmt ?></td>
                                <td style="text-align:center;">
                                    <span class="badge-pill-blue"><?= (int)$a['nb_articles'] ?> art.</span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="badge-custom <?= $statut_class ?>"><?= $statut_label ?></span>
                                </td>
                                <td style="text-align:center;">
                                    <button type="button"
                                            class="btn-action view btn-details"
                                            data-id="<?= (int)$a['id'] ?>"
                                            title="Voir les détails"
                                            id="btn-detail-<?= (int)$a['id'] ?>">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div><!-- /main-wrapper -->

<!-- ════════ DETAIL MODAL ════════ -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius:14px;border:none;overflow:hidden;">
            <div class="modal-detail-header d-flex align-items-center justify-content-between">
                <div>
                    <div class="modal-title" id="detailModalLabel">Détails Achat</div>
                    <div style="font-size:11px;color:rgba(255,255,255,0.5);margin-top:2px;" id="modalSubtitle"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-detail-body" id="modalBody">
                <div class="text-center py-4" id="modalLoader">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2" style="font-size:13px;color:var(--text-muted);">Chargement...</p>
                </div>
                <div id="modalContent" style="display:none;"></div>
            </div>
            <div class="modal-footer border-top" style="border-color:var(--border-color)!important;padding:14px 24px;gap:8px;" id="modalFooter">
                <button type="button" id="btnModifier" class="btn btn-sm btn-primary" style="border-radius:8px;font-weight:600;">
                    <i class="fa-solid fa-pen-to-square me-1"></i> Modifier
                </button>
                <button type="button" id="btnSupprimer" class="btn btn-sm btn-danger" style="border-radius:8px;font-weight:600;">
                    <i class="fa-solid fa-trash me-1"></i> Supprimer
                </button>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal" id="btn-modal-fermer" style="border-radius:8px;">
                    <i class="fa-solid fa-xmark me-1"></i> Fermer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form id="deleteForm" method="POST" action="_delete.php" style="display:none;">
    <input type="hidden" name="id" id="deleteAchatId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── THEME ── */
const htmlEl   = document.documentElement;
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

/* ── FLASH AUTO-DISMISS ── */
const flashAlert = document.getElementById('flashAlert');
if (flashAlert) {
    setTimeout(() => { flashAlert.style.transition = 'opacity 0.5s'; flashAlert.style.opacity = '0'; setTimeout(() => flashAlert.remove(), 500); }, 4000);
}

/* ── DETAIL MODAL ── */
let currentAchatId = null;
const detailModal  = new bootstrap.Modal(document.getElementById('detailModal'));
const modalBody    = document.getElementById('modalBody');
const modalLoader  = document.getElementById('modalLoader');
const modalContent = document.getElementById('modalContent');
const modalLabel   = document.getElementById('detailModalLabel');
const modalSub     = document.getElementById('modalSubtitle');
const btnModifier  = document.getElementById('btnModifier');
const btnSupprimer = document.getElementById('btnSupprimer');

document.querySelectorAll('.btn-details').forEach(btn => {
    btn.addEventListener('click', () => {
        currentAchatId = btn.dataset.id;
        openDetailModal(currentAchatId);
    });
});

function openDetailModal(id) {
    modalLoader.style.display = 'block';
    modalContent.style.display = 'none';
    modalLabel.textContent = 'Détails Achat';
    modalSub.textContent   = '';
    detailModal.show();

    fetch('_get_achat.php?id=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(data => {
            if (data.error) { modalContent.innerHTML = '<p class="text-danger">' + data.error + '</p>'; }
            else { renderModalContent(data); }
            modalLoader.style.display = 'none';
            modalContent.style.display = 'block';
        })
        .catch(() => {
            modalContent.innerHTML = '<p class="text-danger">Erreur lors du chargement.</p>';
            modalLoader.style.display = 'none';
            modalContent.style.display = 'block';
        });
}

function renderModalContent(data) {
    const a         = data.achat;
    const details   = data.details;
    const total     = parseFloat(data.montant_total || 0);
    const versement = parseFloat(a.versement || 0);
    const reste     = total - versement;

    modalLabel.textContent = 'Détails Achat N° ' + a.num;
    modalSub.textContent   = 'Fournisseur: ' + (a.fournisseur_nom || '—');

    let statut_class, statut_label;
    if (versement >= total && total > 0) {
        statut_class = 'badge-solde'; statut_label = '✅ Soldé';
    } else if (versement > 0) {
        statut_class = 'badge-partiel'; statut_label = '⚡ Partiel';
    } else {
        statut_class = 'badge-nonpaye'; statut_label = '❌ Non payé';
    }

    const date_fmt = a.date ? new Date(a.date).toLocaleString('fr-FR') : '—';

    let rows = '';
    details.forEach(d => {
        rows += `<tr>
            <td>${escHtml(d.produit_nom || '—')}</td>
            <td style="text-align:center;">${parseFloat(d.qte).toLocaleString('fr-FR', {minimumFractionDigits:0,maximumFractionDigits:3})}</td>
            <td style="text-align:right;">${parseFloat(d.prix_achat).toLocaleString('fr-FR', {minimumFractionDigits:2,maximumFractionDigits:2})} DA</td>
            <td style="text-align:right;font-weight:700;">${parseFloat(d.total_ligne).toLocaleString('fr-FR', {minimumFractionDigits:2,maximumFractionDigits:2})} DA</td>
        </tr>`;
    });

    modalContent.innerHTML = `
        <div class="modal-info-grid">
            <div class="modal-info-item">
                <div class="modal-info-label">Fournisseur</div>
                <div class="modal-info-value">${escHtml(a.fournisseur_nom || '—')}</div>
            </div>
            <div class="modal-info-item">
                <div class="modal-info-label">Date</div>
                <div class="modal-info-value">${date_fmt}</div>
            </div>
            <div class="modal-info-item">
                <div class="modal-info-label">Statut</div>
                <div class="modal-info-value"><span class="badge-custom ${statut_class}">${statut_label}</span></div>
            </div>
        </div>
        <div style="overflow-x:auto;margin-bottom:16px;">
            <table class="table-custom" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th style="text-align:center;">Qté</th>
                        <th style="text-align:right;">Prix Achat</th>
                        <th style="text-align:right;">Total ligne</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div style="background:var(--content-bg);border-radius:10px;padding:14px 16px;">
            <div class="modal-total-row">
                <span>Montant total</span>
                <span style="font-weight:700;">${total.toLocaleString('fr-FR', {minimumFractionDigits:2,maximumFractionDigits:2})} DA</span>
            </div>
            <div class="modal-total-row">
                <span>Versement</span>
                <span style="font-weight:700;color:#10b981;">${versement.toLocaleString('fr-FR', {minimumFractionDigits:2,maximumFractionDigits:2})} DA</span>
            </div>
            <div class="modal-total-row grand">
                <span>Reste à payer</span>
                <span style="color:${reste > 0 ? '#ef4444' : '#10b981'};">${Math.max(0, reste).toLocaleString('fr-FR', {minimumFractionDigits:2,maximumFractionDigits:2})} DA</span>
            </div>
        </div>
    `;

    btnModifier.onclick  = () => { window.location.href = 'bonAchat.php?id=' + a.id; };
    btnSupprimer.onclick = () => {
        if (confirm('Êtes-vous sûr de vouloir supprimer cet achat ? Cette action est irréversible.')) {
            document.getElementById('deleteAchatId').value = a.id;
            document.getElementById('deleteForm').submit();
        }
    };
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>

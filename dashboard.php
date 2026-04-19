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

// ─── HELPER FUNCTION ──────────────────────────────────────────────────────
function q($pdo, $sql, $column = null) {
    try {
        $stmt = $pdo->query($sql);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($column !== null) return $row[$column] ?? 0;
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $column !== null ? 0 : [];
    }
}

// ─── STATS CARDS ──────────────────────────────────────────────────────────
$total_ventes   = q($pdo, "SELECT COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS v FROM vente_details vd", 'v');
$total_achats   = q($pdo, "SELECT COALESCE(SUM(ad.prix_achat * ad.qte), 0) AS a FROM achat_details ad", 'a');
$total_clients  = q($pdo, "SELECT COUNT(*) AS c FROM clients", 'c');
$total_produits = q($pdo, "SELECT COUNT(*) AS p FROM produits", 'p');
$nb_ventes      = q($pdo, "SELECT COUNT(*) AS n FROM ventes", 'n');
$nb_achats      = q($pdo, "SELECT COUNT(*) AS n FROM achats", 'n');
$stock_total    = q($pdo, "SELECT COALESCE(SUM(qte), 0) AS s FROM produits", 's');
$valeur_stock   = q($pdo, "SELECT COALESCE(SUM(qte * prix_achat), 0) AS v FROM produits", 'v');

// ─── CHARTS DATA ───────────────────────────────────────────────────────────
// Ventes par mois (6 derniers mois)
$ventes_mois_raw = [];
try {
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(v.date, '%Y-%m') AS mois,
               COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS montant
        FROM ventes v
        LEFT JOIN vente_details vd ON vd.id_vente = v.id
        WHERE v.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(v.date, '%Y-%m')
        ORDER BY mois ASC
    ");
    $ventes_mois_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Achats par mois (6 derniers mois)
$achats_mois_raw = [];
try {
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(a.date, '%Y-%m') AS mois,
               COALESCE(SUM(ad.prix_achat * ad.qte), 0) AS montant
        FROM achats a
        LEFT JOIN achat_details ad ON ad.id_achat = a.id
        WHERE a.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(a.date, '%Y-%m')
        ORDER BY mois ASC
    ");
    $achats_mois_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Build unified month labels for the last 6 months
$all_months = [];
for ($i = 5; $i >= 0; $i--) {
    $all_months[] = date('Y-m', strtotime("-$i months"));
}
$month_labels_fr = ['Jan','Fév','Mar','Avr','Mai','Jui','Jul','Aoû','Sep','Oct','Nov','Déc'];

$ventes_map = array_column($ventes_mois_raw, 'montant', 'mois');
$achats_map = array_column($achats_mois_raw, 'montant', 'mois');

$chart_labels      = [];
$ventes_chart_data = [];
$achats_chart_data = [];
foreach ($all_months as $m) {
    list($y, $mo) = explode('-', $m);
    $chart_labels[]      = $month_labels_fr[(int)$mo - 1] . ' ' . $y;
    $ventes_chart_data[] = (float)($ventes_map[$m] ?? 0);
    $achats_chart_data[] = (float)($achats_map[$m] ?? 0);
}

// Top 5 produits doughnut
$top_produits_chart = [];
try {
    $stmt = $pdo->query("
        SELECT p.nom,
               COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS total
        FROM produits p
        LEFT JOIN vente_details vd ON vd.id_produit = p.id
        GROUP BY p.id, p.nom
        ORDER BY total DESC
        LIMIT 5
    ");
    $top_produits_chart = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ─── RECENT VENTES ─────────────────────────────────────────────────────────
$recent_ventes = [];
try {
    $stmt = $pdo->query("
        SELECT v.id, v.num, v.date, c.nom AS client,
               COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS total,
               v.versement,
               CASE
                   WHEN v.versement >= SUM(vd.prix_vente * vd.qte) THEN 'Payé'
                   WHEN v.versement > 0 THEN 'Partiel'
                   ELSE 'En attente'
               END AS statut
        FROM ventes v
        LEFT JOIN clients c ON c.id = v.id_clients
        LEFT JOIN vente_details vd ON vd.id_vente = v.id
        GROUP BY v.id, v.num, v.date, c.nom, v.versement
        ORDER BY v.date DESC
        LIMIT 5
    ");
    $recent_ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ─── TOP PRODUITS STOCK ────────────────────────────────────────────────────
$top_produits_stock = [];
$max_qte = 1;
try {
    $stmt = $pdo->query("
        SELECT p.nom, p.qte, p.prix_vente,
               (p.qte * p.prix_vente) AS valeur_stock,
               COALESCE(SUM(vd.qte), 0) AS qte_vendue
        FROM produits p
        LEFT JOIN vente_details vd ON vd.id_produit = p.id
        GROUP BY p.id, p.nom, p.qte, p.prix_vente
        ORDER BY qte_vendue DESC
        LIMIT 5
    ");
    $top_produits_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($top_produits_stock)) {
        $qtmax = $pdo->query("SELECT MAX(qte) AS m FROM produits")->fetchColumn();
        $max_qte = max(1, (int)$qtmax);
    }
} catch (Exception $e) {}

// ─── JSON for JS ───────────────────────────────────────────────────────────
$chart_labels_json      = json_encode($chart_labels);
$ventes_chart_json      = json_encode($ventes_chart_data);
$achats_chart_json      = json_encode($achats_chart_data);
$doughnut_labels_json   = json_encode(array_column($top_produits_chart, 'nom'));
$doughnut_data_json     = json_encode(array_map(fn($r) => (float)$r['total'], $top_produits_chart));
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — G-Business</title>
    <meta name="description" content="Tableau de bord de gestion commerciale G-Business">

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

            /* Gradients */
            --grad-blue:   linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            --grad-purple: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            --grad-green:  linear-gradient(135deg, #10b981 0%, #059669 100%);
            --grad-orange: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
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

        /* ══════════════════════════════════════════
           RESET & BASE
        ══════════════════════════════════════════ */
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
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            background: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            z-index: 1040;
            transition: width var(--transition), transform var(--transition);
            overflow: hidden;
        }

        #sidebar .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 22px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            text-decoration: none;
            white-space: nowrap;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: #fff;
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(99,102,241,0.4);
        }

        .brand-text {
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -0.3px;
        }

        .brand-text span { color: #6366f1; }

        /* NAV */
        .sidebar-nav {
            flex: 1;
            padding: 16px 0;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }

        .nav-section-label {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.3);
            padding: 12px 22px 6px;
            white-space: nowrap;
            overflow: hidden;
        }

        .nav-item {
            margin: 2px 10px;
        }

        .nav-link-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 10px;
            color: rgba(255,255,255,0.65);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all var(--transition);
            white-space: nowrap;
            position: relative;
        }

        .nav-link-item .nav-icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
            transition: transform var(--transition);
        }

        .nav-link-item .nav-text { flex: 1; }

        .nav-link-item:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }

        .nav-link-item:hover .nav-icon { transform: scale(1.1); }

        .nav-link-item.active {
            background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(139,92,246,0.2));
            color: #fff;
            box-shadow: inset 0 0 0 1px rgba(99,102,241,0.3);
        }

        .nav-link-item.active .nav-icon { color: #818cf8; }

        .nav-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            letter-spacing: 0;
        }

        /* SIDEBAR BOTTOM / USER */
        .sidebar-user {
            padding: 14px 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            gap: 12px;
            white-space: nowrap;
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a78bfa);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(99,102,241,0.3);
        }

        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 13px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-rank { font-size: 11px; color: rgba(255,255,255,0.45); text-transform: capitalize; }

        .btn-logout {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.2);
            color: #f87171;
            font-size: 14px;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
            flex-shrink: 0;
        }
        .btn-logout:hover { background: rgba(239,68,68,0.25); color: #fca5a5; }

        /* COLLAPSED STATE (tablet) */
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

        /* OVERLAY (mobile) */
        #sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(2px);
            z-index: 1035;
        }

        /* SIDEBAR TOOLTIP for collapsed state */
        body.sidebar-collapsed .nav-link-item { position: relative; }
        body.sidebar-collapsed .nav-link-item::after {
            content: attr(data-label);
            position: absolute;
            left: calc(100% + 12px);
            top: 50%;
            transform: translateY(-50%);
            background: #1e293b;
            color: #fff;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        body.sidebar-collapsed .nav-link-item:hover::after { opacity: 1; }

        /* ══════════════════════════════════════════
           MAIN WRAPPER
        ══════════════════════════════════════════ */
        #main-wrapper {
            margin-left: var(--sidebar-w);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left var(--transition);
        }

        body.sidebar-collapsed #main-wrapper { margin-left: var(--sidebar-w-sm); }

        /* ══════════════════════════════════════════
           TOP NAVBAR
        ══════════════════════════════════════════ */
        #topbar {
            height: var(--topbar-h);
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            padding: 0 24px;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 1030;
            transition: background var(--transition), border-color var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .topbar-toggle {
            width: 36px; height: 36px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: all var(--transition);
        }
        .topbar-toggle:hover { background: var(--border-color); color: var(--text-primary); }

        .topbar-title {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary);
            flex: 1;
        }

        .topbar-title small {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: var(--text-muted);
            margin-top: -2px;
        }

        .topbar-actions { display: flex; align-items: center; gap: 10px; }

        .topbar-btn {
            width: 38px; height: 38px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 15px;
            position: relative;
            transition: all var(--transition);
        }
        .topbar-btn:hover { background: var(--border-color); color: var(--text-primary); }

        .notif-badge {
            position: absolute;
            top: 6px; right: 6px;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #ef4444;
            border: 2px solid var(--card-bg);
        }

        /* Avatar dropdown */
        .avatar-dropdown { position: relative; }

        .avatar-btn {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a78bfa);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            border: 2px solid rgba(99,102,241,0.3);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all var(--transition);
        }
        .avatar-btn:hover { transform: scale(1.05); box-shadow: 0 4px 14px rgba(99,102,241,0.4); }

        .dropdown-menu-custom {
            position: absolute;
            right: 0; top: calc(100% + 10px);
            min-width: 200px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            padding: 6px;
            display: none;
            z-index: 9999;
        }
        .dropdown-menu-custom.show { display: block; animation: fadeIn 0.15s ease; }

        @keyframes fadeIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }

        .dropdown-menu-custom a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 6px;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all var(--transition);
        }
        .dropdown-menu-custom a:hover { background: var(--border-color); color: var(--text-primary); }
        .dropdown-menu-custom .divider { height: 1px; background: var(--border-color); margin: 4px 0; }
        .dropdown-menu-custom a.danger { color: #ef4444; }
        .dropdown-menu-custom a.danger:hover { background: rgba(239,68,68,0.08); }

        /* ══════════════════════════════════════════
           PAGE CONTENT
        ══════════════════════════════════════════ */
        #page-content {
            flex: 1;
            padding: 28px;
        }

        /* ══════════════════════════════════════════
           STATS CARDS
        ══════════════════════════════════════════ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow-sm);
            transition: transform var(--transition), box-shadow var(--transition), background var(--transition);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
        }
        .stat-card.blue::before   { background: var(--grad-blue); }
        .stat-card.purple::before { background: var(--grad-purple); }
        .stat-card.green::before  { background: var(--grad-green); }
        .stat-card.orange::before { background: var(--grad-orange); }

        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }

        .stat-card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }

        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            color: #fff;
        }
        .stat-card.blue   .stat-icon { background: var(--grad-blue); box-shadow: 0 6px 16px rgba(59,130,246,0.35); }
        .stat-card.purple .stat-icon { background: var(--grad-purple); box-shadow: 0 6px 16px rgba(139,92,246,0.35); }
        .stat-card.green  .stat-icon { background: var(--grad-green); box-shadow: 0 6px 16px rgba(16,185,129,0.35); }
        .stat-card.orange .stat-icon { background: var(--grad-orange); box-shadow: 0 6px 16px rgba(249,115,22,0.35); }

        .stat-trend {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 20px;
        }

        .stat-value {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-primary);
            letter-spacing: -0.5px;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        .stat-label { font-size: 13px; font-weight: 500; color: var(--text-secondary); margin-bottom: 12px; }

        .stat-secondary {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
            padding-top: 12px;
            border-top: 1px solid var(--border-color);
        }
        .stat-secondary i { font-size: 11px; }

        /* ══════════════════════════════════════════
           SECTION HEADINGS & CARDS
        ══════════════════════════════════════════ */
        .section-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            transition: background var(--transition), border-color var(--transition);
            overflow: hidden;
        }

        .section-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px 14px;
            border-bottom: 1px solid var(--border-color);
        }

        .section-card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-card-title i { color: var(--accent); font-size: 14px; }

        .section-card-body { padding: 20px 22px; }

        .view-all-btn {
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 5px 12px;
            border-radius: 20px;
            border: 1px solid rgba(99,102,241,0.25);
            transition: all var(--transition);
        }
        .view-all-btn:hover { background: rgba(99,102,241,0.08); color: var(--accent); }

        /* ══════════════════════════════════════════
           CHARTS ROW
        ══════════════════════════════════════════ */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }

        .chart-wrapper { position: relative; height: 280px; }
        .chart-wrapper canvas { width: 100% !important; height: 100% !important; }

        /* ══════════════════════════════════════════
           TABLE
        ══════════════════════════════════════════ */
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .table-custom thead th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--text-muted);
            padding: 8px 14px;
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        .table-custom tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background var(--transition);
        }
        .table-custom tbody tr:last-child { border-bottom: none; }
        .table-custom tbody tr:hover { background: rgba(99,102,241,0.03); }
        .table-custom td {
            padding: 13px 14px;
            color: var(--text-secondary);
            vertical-align: middle;
        }
        .table-custom td.td-main { font-weight: 600; color: var(--text-primary); }

        /* STATUS BADGES */
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .badge-status::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
        }
        .badge-paye   { background: rgba(16,185,129,0.1);  color: #10b981; }
        .badge-paye::before   { background: #10b981; }
        .badge-partiel { background: rgba(245,158,11,0.1); color: #f59e0b; }
        .badge-partiel::before { background: #f59e0b; }
        .badge-attente { background: rgba(239,68,68,0.1);  color: #ef4444; }
        .badge-attente::before { background: #ef4444; }

        /* ══════════════════════════════════════════
           BOTTOM GRID
        ══════════════════════════════════════════ */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 28px;
        }

        /* PRODUCT STOCK ROWS */
        .product-row {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .product-row:last-child { border-bottom: none; }

        .product-row-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .product-name { font-size: 13px; font-weight: 600; color: var(--text-primary); }
        .product-meta { font-size: 12px; color: var(--text-muted); margin-top: 1px; }

        .stock-progress {
            height: 6px;
            background: var(--border-color);
            border-radius: 10px;
            overflow: hidden;
        }
        .stock-progress-bar {
            height: 100%;
            border-radius: 10px;
            transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .bar-green  { background: var(--grad-green); }
        .bar-yellow { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .bar-red    { background: linear-gradient(135deg, #ef4444, #dc2626); }

        .stock-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .stock-ok     { background: rgba(16,185,129,0.12); color: #10b981; }
        .stock-low    { background: rgba(245,158,11,0.12); color: #f59e0b; }
        .stock-empty  { background: rgba(239,68,68,0.12);  color: #ef4444; }

        /* ══════════════════════════════════════════
           DARK MODE TOGGLE
        ══════════════════════════════════════════ */
        .theme-toggle-icon { transition: transform 0.3s ease; }

        /* ══════════════════════════════════════════
           EMPTY STATE
        ══════════════════════════════════════════ */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        .empty-state i { font-size: 36px; margin-bottom: 12px; opacity: 0.4; }
        .empty-state p { font-size: 13px; }

        /* ══════════════════════════════════════════
           SCROLLBAR (dark theme)
        ══════════════════════════════════════════ */
        [data-theme="dark"] ::-webkit-scrollbar { width: 6px; height: 6px; }
        [data-theme="dark"] ::-webkit-scrollbar-track { background: #0f172a; }
        [data-theme="dark"] ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }

        /* ══════════════════════════════════════════
           RESPONSIVE
        ══════════════════════════════════════════ */
        @media (max-width: 1199px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
        }

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
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 14px; }
            .bottom-grid { grid-template-columns: 1fr; }
            #page-content { padding: 16px; }
            .topbar-title small { display: none; }
        }

        @media (max-width: 479px) {
            .stats-grid { grid-template-columns: 1fr; }
            .stat-value { font-size: 18px; }
        }

        /* ══════════════════════════════════════════
           ANIMATIONS
        ══════════════════════════════════════════ */
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .animate-in {
            animation: slideUp 0.4s ease forwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.05s; }
        .stat-card:nth-child(2) { animation-delay: 0.10s; }
        .stat-card:nth-child(3) { animation-delay: 0.15s; }
        .stat-card:nth-child(4) { animation-delay: 0.20s; }

        /* ══════════════════════════════════════════
           LOADING PULSE (skeleton)
        ══════════════════════════════════════════ */
        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
    </style>
</head>
<body>

<!-- ════════════════════════════════════════════
     SIDEBAR OVERLAY (mobile)
════════════════════════════════════════════ -->
<div id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<!-- ════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════ -->
<aside id="sidebar" aria-label="Navigation principale">

    <!-- Brand -->
    <a href="dashboard.php" class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-chart-pie"></i></div>
        <span class="brand-text">G-<span>Business</span></span>
    </a>

    <!-- Nav -->
    <nav class="sidebar-nav">
        <div class="nav-section-label">Menu Principal</div>

        <div class="nav-item">
            <a href="dashboard.php" class="nav-link-item active" data-label="Dashboard" id="nav-dashboard">
                <i class="fa-solid fa-house nav-icon"></i>
                <span class="nav-text">Dashboard</span>
            </a>
        </div>

        <?php if ($rank === 'admin'): ?>
        <div class="nav-item">
            <a href="utilisateurs.php" class="nav-link-item" data-label="Utilisateurs" id="nav-users">
                <i class="fa-solid fa-users-gear nav-icon"></i>
                <span class="nav-text">Utilisateurs</span>
                <span class="nav-badge">Admin</span>
            </a>
        </div>
        <?php endif; ?>

        <div class="nav-item">
            <a href="products.php" class="nav-link-item" data-label="Produits" id="nav-products">
                <i class="fa-solid fa-boxes-stacked nav-icon"></i>
                <span class="nav-text">Produits</span>
            </a>
        </div>

        <div class="nav-section-label">Transactions</div>

        <div class="nav-item">
            <a href="achats/achats.php" class="nav-link-item" data-label="Achats" id="nav-achats">
                <i class="fa-solid fa-cart-shopping nav-icon"></i>
                <span class="nav-text">Achats</span>
            </a>
        </div>

        <div class="nav-item">
            <a href="ventes/ventes.php" class="nav-link-item" data-label="Ventes" id="nav-ventes">
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
            <a href="fournisseurs.php" class="nav-link-item" data-label="Fournisseurs" id="nav-fournisseurs">
                <i class="fa-solid fa-industry nav-icon"></i>
                <span class="nav-text">Fournisseurs</span>
            </a>
        </div>
    </nav>

    <!-- User block -->
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
            Tableau de Bord
            <small>Bienvenue, <?= htmlspecialchars($username) ?> — <?= date('d M Y') ?></small>
        </div>

        <div class="topbar-actions">
            <!-- Bell notification -->
            <button class="topbar-btn" aria-label="Notifications" id="btn-notif">
                <i class="fa-solid fa-bell"></i>
                <span class="notif-badge"></span>
            </button>

            <!-- Dark/Light toggle -->
            <button class="topbar-btn" id="themeToggle" aria-label="Basculer thème">
                <i class="fa-solid fa-moon theme-toggle-icon" id="themeIcon"></i>
            </button>

            <!-- Avatar dropdown -->
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
                    <i class="fa-solid fa-chart-line me-2" style="color:#6366f1;"></i>
                    Vue d'ensemble
                </h1>
                <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;">
                    Données en temps réel depuis la base de données
                </p>
            </div>
            <a href="ventes/bonVente.php" class="btn btn-sm" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:10px;padding:9px 18px;font-weight:600;font-size:13px;display:flex;align-items:center;gap:7px;box-shadow:0 4px 14px rgba(99,102,241,0.35);">
                <i class="fa-solid fa-plus"></i> Nouvelle Vente
            </a>
        </div>

        <!-- STATS CARDS -->
        <div class="stats-grid">

            <!-- Card 1: Total Ventes -->
            <div class="stat-card blue animate-in">
                <div class="stat-card-header">
                    <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
                    <span class="stat-trend" style="background:rgba(59,130,246,0.12);color:#3b82f6;">
                        <i class="fa-solid fa-arrow-trend-up me-1"></i>CA
                    </span>
                </div>
                <div class="stat-value"><?= number_format($total_ventes, 2, ',', ' ') ?> <span style="font-size:14px;font-weight:500;color:var(--text-muted);">DA</span></div>
                <div class="stat-label">Chiffre d'affaires total</div>
                <div class="stat-secondary">
                    <i class="fa-solid fa-receipt" style="color:#3b82f6;"></i>
                    <span><?= number_format($nb_ventes) ?> vente<?= $nb_ventes > 1 ? 's' : '' ?> enregistrée<?= $nb_ventes > 1 ? 's' : '' ?></span>
                </div>
            </div>

            <!-- Card 2: Total Achats -->
            <div class="stat-card purple animate-in">
                <div class="stat-card-header">
                    <div class="stat-icon"><i class="fa-solid fa-cart-shopping"></i></div>
                    <span class="stat-trend" style="background:rgba(139,92,246,0.12);color:#8b5cf6;">
                        <i class="fa-solid fa-bag-shopping me-1"></i>Coût
                    </span>
                </div>
                <div class="stat-value"><?= number_format($total_achats, 2, ',', ' ') ?> <span style="font-size:14px;font-weight:500;color:var(--text-muted);">DA</span></div>
                <div class="stat-label">Total des achats</div>
                <div class="stat-secondary">
                    <i class="fa-solid fa-truck" style="color:#8b5cf6;"></i>
                    <span><?= number_format($nb_achats) ?> achat<?= $nb_achats > 1 ? 's' : '' ?> enregistré<?= $nb_achats > 1 ? 's' : '' ?></span>
                </div>
            </div>

            <!-- Card 3: Total Clients -->
            <div class="stat-card green animate-in">
                <div class="stat-card-header">
                    <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    <span class="stat-trend" style="background:rgba(16,185,129,0.12);color:#10b981;">
                        <i class="fa-solid fa-user-plus me-1"></i>Base
                    </span>
                </div>
                <div class="stat-value"><?= number_format($total_clients) ?></div>
                <div class="stat-label">Clients enregistrés</div>
                <div class="stat-secondary">
                    <i class="fa-solid fa-address-book" style="color:#10b981;"></i>
                    <span>Répertoire clients actif</span>
                </div>
            </div>

            <!-- Card 4: Total Produits -->
            <div class="stat-card orange animate-in">
                <div class="stat-card-header">
                    <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    <span class="stat-trend" style="background:rgba(249,115,22,0.12);color:#f97316;">
                        <i class="fa-solid fa-warehouse me-1"></i>Stock
                    </span>
                </div>
                <div class="stat-value"><?= number_format($total_produits) ?></div>
                <div class="stat-label">Produits en catalogue</div>
                <div class="stat-secondary">
                    <i class="fa-solid fa-cubes" style="color:#f97316;"></i>
                    <span><?= number_format($stock_total) ?> unités · <?= number_format($valeur_stock, 2, ',', ' ') ?> DA</span>
                </div>
            </div>
        </div>

        <!-- CHARTS ROW -->
        <div class="charts-grid">

            <!-- Line Chart -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-solid fa-chart-area"></i>
                        Évolution Ventes & Achats
                        <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:4px;">— 6 derniers mois</span>
                    </div>
                    <a href="ventes/ventes.php" class="view-all-btn">
                        Voir tout <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
                <div class="section-card-body">
                    <div class="chart-wrapper">
                        <canvas id="lineChart" aria-label="Graphique évolution ventes et achats"></canvas>
                    </div>
                </div>
            </div>

            <!-- Doughnut Chart -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-solid fa-chart-pie"></i>
                        Top 5 Produits
                    </div>
                </div>
                <div class="section-card-body">
                    <?php if (empty($top_produits_chart)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-chart-pie d-block"></i>
                            <p>Aucune donnée disponible</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-wrapper" style="height:220px;">
                            <canvas id="doughnutChart" aria-label="Graphique top 5 produits"></canvas>
                        </div>
                        <div style="margin-top:16px;">
                            <?php
                            $doughnut_colors = ['#6366f1','#3b82f6','#10b981','#f59e0b','#ef4444'];
                            foreach ($top_produits_chart as $i => $tp):
                                $color = $doughnut_colors[$i % count($doughnut_colors)];
                            ?>
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;font-size:12px;">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="width:10px;height:10px;border-radius:3px;background:<?= $color ?>;display:inline-block;flex-shrink:0;"></span>
                                    <span style="color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px;"><?= htmlspecialchars($tp['nom']) ?></span>
                                </div>
                                <span style="font-weight:700;color:var(--text-primary);"><?= number_format($tp['total'], 0, ',', ' ') ?> DA</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- BOTTOM GRID: Recent Ventes + Top Produits Stock -->
        <div class="bottom-grid">

            <!-- Recent Ventes Table -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        Ventes Récentes
                    </div>
                    <a href="ventes/ventes.php" class="view-all-btn">
                        Voir tout <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <?php if (empty($recent_ventes)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-receipt d-block"></i>
                        <p>Aucune vente enregistrée</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <th>#Bon</th>
                                    <th>Client</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_ventes as $v): ?>
                                <tr>
                                    <td class="td-main"><?= htmlspecialchars($v['num'] ?? '#' . $v['id']) ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <span style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#a78bfa);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                                <?= strtoupper(mb_substr($v['client'] ?? '?', 0, 1)) ?>
                                            </span>
                                            <?= htmlspecialchars($v['client'] ?? 'N/A') ?>
                                        </div>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($v['date'])) ?></td>
                                    <td style="font-weight:600;color:var(--text-primary);"><?= number_format($v['total'], 2, ',', ' ') ?> DA</td>
                                    <td>
                                        <?php if ($v['statut'] === 'Payé'): ?>
                                            <span class="badge-status badge-paye">Payé</span>
                                        <?php elseif ($v['statut'] === 'Partiel'): ?>
                                            <span class="badge-status badge-partiel">Partiel</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-attente">En attente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Produits Stock -->
            <div class="section-card">
                <div class="section-card-header">
                    <div class="section-card-title">
                        <i class="fa-solid fa-trophy"></i>
                        Top Produits & Stock
                    </div>
                    <a href="products.php" class="view-all-btn">
                        Voir tout <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
                <div class="section-card-body" style="padding-top:8px;">
                    <?php if (empty($top_produits_stock)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-box-open d-block"></i>
                            <p>Aucun produit trouvé</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($top_produits_stock as $p):
                            $pct = $max_qte > 0 ? min(100, round(($p['qte'] / $max_qte) * 100)) : 0;
                            $bar_class = $p['qte'] > 10 ? 'bar-green' : ($p['qte'] >= 1 ? 'bar-yellow' : 'bar-red');
                            $badge_class = $p['qte'] > 10 ? 'stock-ok' : ($p['qte'] >= 1 ? 'stock-low' : 'stock-empty');
                            $badge_text = $p['qte'] > 10 ? 'En stock' : ($p['qte'] >= 1 ? 'Stock faible' : 'Rupture');
                        ?>
                        <div class="product-row">
                            <div class="product-row-header">
                                <div>
                                    <div class="product-name"><?= htmlspecialchars($p['nom']) ?></div>
                                    <div class="product-meta">
                                        <?= number_format($p['qte']) ?> unités ·
                                        <?= number_format($p['prix_vente'], 2, ',', ' ') ?> DA/u ·
                                        Vendu: <?= number_format($p['qte_vendue']) ?>
                                    </div>
                                </div>
                                <span class="stock-badge <?= $badge_class ?>"><?= $badge_text ?></span>
                            </div>
                            <div class="stock-progress">
                                <div class="stock-progress-bar <?= $bar_class ?>" style="width:<?= $pct ?>%;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </main><!-- /page-content -->
</div><!-- /main-wrapper -->

<!-- ════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

<script>
/* ─────────────────────────────────────────────
   DATA FROM PHP
───────────────────────────────────────────── */
const chartLabels     = <?= $chart_labels_json ?>;
const ventesData      = <?= $ventes_chart_json ?>;
const achatsData      = <?= $achats_chart_json ?>;
const doughnutLabels  = <?= $doughnut_labels_json ?>;
const doughnutData    = <?= $doughnut_data_json ?>;

/* ─────────────────────────────────────────────
   THEME SYSTEM
───────────────────────────────────────────── */
const htmlEl   = document.documentElement;
const themeBtn = document.getElementById('themeToggle');
const themeIco = document.getElementById('themeIcon');

function applyTheme(theme) {
    htmlEl.setAttribute('data-theme', theme);
    if (theme === 'dark') {
        themeIco.className = 'fa-solid fa-sun theme-toggle-icon';
        Chart.defaults.color = '#94a3b8';
    } else {
        themeIco.className = 'fa-solid fa-moon theme-toggle-icon';
        Chart.defaults.color = '#64748b';
    }
    localStorage.setItem('gbiz-theme', theme);
    updateChartsTheme();
}

// Load saved theme
(function() {
    const saved = localStorage.getItem('gbiz-theme') || 'light';
    applyTheme(saved);
})();

themeBtn.addEventListener('click', () => {
    const current = htmlEl.getAttribute('data-theme');
    applyTheme(current === 'dark' ? 'light' : 'dark');
});

/* ─────────────────────────────────────────────
   SIDEBAR TOGGLE
───────────────────────────────────────────── */
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

function closeMobileSidebar() {
    body.classList.remove('sidebar-mobile-open');
}

// Restore sidebar state on desktop
(function() {
    if (!isMobile() && localStorage.getItem('gbiz-sidebar') === 'collapsed') {
        body.classList.add('sidebar-collapsed');
    }
})();

window.addEventListener('resize', () => {
    if (!isMobile()) body.classList.remove('sidebar-mobile-open');
});

/* ─────────────────────────────────────────────
   AVATAR DROPDOWN
───────────────────────────────────────────── */
const avatarBtn      = document.getElementById('avatarBtn');
const avatarDropdown = document.getElementById('avatarDropdown');

avatarBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    avatarDropdown.classList.toggle('show');
});

document.addEventListener('click', () => avatarDropdown.classList.remove('show'));

/* ─────────────────────────────────────────────
   CHART.JS — GLOBAL DEFAULTS
───────────────────────────────────────────── */
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyleWidth = 8;

function getGridColor() {
    return htmlEl.getAttribute('data-theme') === 'dark'
        ? 'rgba(255,255,255,0.06)'
        : 'rgba(0,0,0,0.06)';
}

/* ─────────────────────────────────────────────
   LINE CHART — Ventes & Achats
───────────────────────────────────────────── */
let lineChart = null;
const lineCtx = document.getElementById('lineChart');

if (lineCtx) {
    lineChart = new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [
                {
                    label: 'Ventes (DA)',
                    data: ventesData,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99,102,241,0.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#6366f1',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4,
                },
                {
                    label: 'Achats (DA)',
                    data: achatsData,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249,115,22,0.06)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#f97316',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', align: 'end' },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f1f5f9',
                    bodyColor: '#94a3b8',
                    borderColor: '#334155',
                    borderWidth: 1,
                    padding: 12,
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ': ' + new Intl.NumberFormat('fr-DZ').format(ctx.parsed.y) + ' DA'
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: getGridColor(), drawBorder: false },
                    ticks: { color: '#94a3b8' },
                    border: { display: false }
                },
                y: {
                    grid: { color: getGridColor(), drawBorder: false },
                    ticks: {
                        color: '#94a3b8',
                        callback: v => new Intl.NumberFormat('fr-DZ', { notation: 'compact' }).format(v) + ' DA'
                    },
                    border: { display: false }
                }
            }
        }
    });
}

/* ─────────────────────────────────────────────
   DOUGHNUT CHART — Top 5 Produits
───────────────────────────────────────────── */
let doughnutChart = null;
const doughnutCtx = document.getElementById('doughnutChart');

if (doughnutCtx && doughnutData.length > 0) {
    doughnutChart = new Chart(doughnutCtx, {
        type: 'doughnut',
        data: {
            labels: doughnutLabels,
            datasets: [{
                data: doughnutData,
                backgroundColor: ['#6366f1','#3b82f6','#10b981','#f59e0b','#ef4444'],
                hoverBackgroundColor: ['#818cf8','#60a5fa','#34d399','#fbbf24','#f87171'],
                borderWidth: 0,
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f1f5f9',
                    bodyColor: '#94a3b8',
                    borderColor: '#334155',
                    borderWidth: 1,
                    padding: 12,
                    callbacks: {
                        label: ctx => ' ' + new Intl.NumberFormat('fr-DZ').format(ctx.parsed) + ' DA'
                    }
                }
            }
        }
    });
}

/* ─────────────────────────────────────────────
   UPDATE CHARTS WHEN THEME CHANGES
───────────────────────────────────────────── */
function updateChartsTheme() {
    const gridColor = getGridColor();
    if (lineChart) {
        lineChart.options.scales.x.grid.color = gridColor;
        lineChart.options.scales.y.grid.color = gridColor;
        lineChart.update('none');
    }
}

/* ─────────────────────────────────────────────
   ANIMATE PROGRESS BARS ON LOAD
───────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    const bars = document.querySelectorAll('.stock-progress-bar');
    bars.forEach(bar => {
        const target = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => { bar.style.width = target; }, 200);
    });
});
</script>

</body>
</html>

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

// ─── DATABASE ─────────────────────────────────────────────────────────────
$pdo = getDBConnection();
if ($pdo === null) die("Connexion impossible aux bases de données.");

// ─── DATE FILTER ──────────────────────────────────────────────────────────
$date_from = trim($_GET['date_from'] ?? date('Y-m-d'));
$date_to   = trim($_GET['date_to']   ?? date('Y-m-d'));

if (!strtotime($date_from)) $date_from = date('Y-m-d');
if (!strtotime($date_to))   $date_to   = date('Y-m-d');
if ($date_from > $date_to)  [$date_from, $date_to] = [$date_to, $date_from];

$date_from_dt = $date_from . ' 00:00:00';
$date_to_dt   = $date_to   . ' 23:59:59';

// ─── HELPER FUNCTION ──────────────────────────────────────────────────────
function fmt($val) {
    return number_format(floatval($val), 2, ',', ' ') . ' DA';
}

// ─── QUERIES ──────────────────────────────────────────────────────────────

// CA Brut
$r = $pdo->prepare("SELECT COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS ca_brut FROM ventes v LEFT JOIN vente_details vd ON vd.id_vente = v.id WHERE v.date BETWEEN :from AND :to");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$ca_brut = floatval($r->fetchColumn());

// Ventes encaissées (soldées)
$r = $pdo->prepare("SELECT COALESCE(SUM(sous.total), 0) AS total_encaisse, COUNT(*) AS nb_encaisse FROM (SELECT v.id, v.versement, COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS total FROM ventes v LEFT JOIN vente_details vd ON vd.id_vente = v.id WHERE v.date BETWEEN :from AND :to GROUP BY v.id, v.versement HAVING v.versement >= total AND total > 0) AS sous");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$row_enc = $r->fetch(PDO::FETCH_ASSOC);
$total_encaisse = floatval($row_enc['total_encaisse'] ?? 0);
$nb_encaisse    = intval($row_enc['nb_encaisse'] ?? 0);

// Ventes à crédit
$r = $pdo->prepare("SELECT COALESCE(SUM(sous.total), 0) AS total_credit, COALESCE(SUM(sous.versement), 0) AS verse_credit, COALESCE(SUM(sous.total - sous.versement), 0) AS reste_credit, COUNT(*) AS nb_credit FROM (SELECT v.id, v.versement, COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS total FROM ventes v LEFT JOIN vente_details vd ON vd.id_vente = v.id WHERE v.date BETWEEN :from AND :to GROUP BY v.id, v.versement HAVING v.versement < total) AS sous");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$row_cred = $r->fetch(PDO::FETCH_ASSOC);
$total_credit = floatval($row_cred['total_credit'] ?? 0);
$verse_credit = floatval($row_cred['verse_credit'] ?? 0);
$reste_credit = floatval($row_cred['reste_credit'] ?? 0);
$nb_credit    = intval($row_cred['nb_credit'] ?? 0);

// Cash réel reçu
$r = $pdo->prepare("SELECT COALESCE(SUM(v.versement), 0) AS cash_recu FROM ventes v WHERE v.date BETWEEN :from AND :to");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$cash_recu = floatval($r->fetchColumn());

// Bénéfice net (soldées uniquement)
$r = $pdo->prepare("SELECT COALESCE(SUM((vd.prix_vente - p.prix_achat) * vd.qte), 0) AS benefice_net FROM ventes v LEFT JOIN vente_details vd ON vd.id_vente = v.id LEFT JOIN produits p ON p.id = vd.id_produit WHERE v.date BETWEEN :from AND :to AND v.id IN (SELECT v2.id FROM ventes v2 LEFT JOIN vente_details vd2 ON vd2.id_vente = v2.id WHERE v2.date BETWEEN :from2 AND :to2 GROUP BY v2.id, v2.versement HAVING v2.versement >= COALESCE(SUM(vd2.prix_vente * vd2.qte), 0) AND COALESCE(SUM(vd2.prix_vente * vd2.qte), 0) > 0)");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt, ':from2' => $date_from_dt, ':to2' => $date_to_dt]);
$benefice_net = floatval($r->fetchColumn());

// Total achats
$r = $pdo->prepare("SELECT COALESCE(SUM(ad.prix_achat * ad.qte), 0) AS total_achats FROM achats a LEFT JOIN achat_details ad ON ad.id_achat = a.id WHERE a.date BETWEEN :from AND :to");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$total_achats = floatval($r->fetchColumn());

// Achats versés
$r = $pdo->prepare("SELECT COALESCE(SUM(a.versement), 0) AS verse_achats FROM achats a WHERE a.date BETWEEN :from AND :to");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$verse_achats = floatval($r->fetchColumn());

// Calculs dérivés
$solde_caisse  = $cash_recu - $verse_achats;
$dettes_fourn  = $total_achats - $verse_achats;

// Liste ventes
$r = $pdo->prepare("SELECT v.id, v.num, v.date, v.versement, c.nom AS client, COALESCE(SUM(vd.prix_vente * vd.qte), 0) AS total, COALESCE(SUM(vd.prix_vente * vd.qte), 0) - v.versement AS reste, CASE WHEN v.versement >= COALESCE(SUM(vd.prix_vente * vd.qte), 0) AND COALESCE(SUM(vd.prix_vente * vd.qte), 0) > 0 THEN 'solde' WHEN v.versement > 0 THEN 'partiel' ELSE 'credit' END AS statut FROM ventes v LEFT JOIN clients c ON c.id = v.id_clients LEFT JOIN vente_details vd ON vd.id_vente = v.id WHERE v.date BETWEEN :from AND :to GROUP BY v.id, v.num, v.date, v.versement, c.nom ORDER BY v.date DESC");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$liste_ventes = $r->fetchAll(PDO::FETCH_ASSOC);

// Liste achats
$r = $pdo->prepare("SELECT a.id, a.num, a.date, a.versement, f.nom AS fournisseur, COALESCE(SUM(ad.prix_achat * ad.qte), 0) AS total, COALESCE(SUM(ad.prix_achat * ad.qte), 0) - a.versement AS reste FROM achats a LEFT JOIN fournisseurs f ON f.id = a.id_fournisseur LEFT JOIN achat_details ad ON ad.id_achat = a.id WHERE a.date BETWEEN :from AND :to GROUP BY a.id, a.num, a.date, a.versement, f.nom ORDER BY a.date DESC");
$r->execute([':from' => $date_from_dt, ':to' => $date_to_dt]);
$liste_achats = $r->fetchAll(PDO::FETCH_ASSOC);

$current_page = 'analyse';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caisse & Comptabilité — G-Business</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">

    <style>
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
        body { font-family: 'Inter', sans-serif; background: var(--content-bg); color: var(--text-primary); transition: background var(--transition), color var(--transition); min-height: 100vh; overflow-x: hidden; }

        /* ── SIDEBAR ── */
        #sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh; background: var(--sidebar-bg); display: flex; flex-direction: column; z-index: 1040; transition: width var(--transition), transform var(--transition); overflow: hidden; }
        #sidebar .sidebar-brand { display: flex; align-items: center; gap: 12px; padding: 20px 22px; border-bottom: 1px solid rgba(255,255,255,0.08); text-decoration: none; white-space: nowrap; }
        .brand-icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #6366f1, #8b5cf6); display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; flex-shrink: 0; box-shadow: 0 4px 14px rgba(99,102,241,0.4); }
        .brand-text { font-size: 18px; font-weight: 700; color: #fff; letter-spacing: -0.3px; }
        .brand-text span { color: #6366f1; }
        .sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; overflow-x: hidden; }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }
        .nav-section-label { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: rgba(255,255,255,0.3); padding: 12px 22px 6px; white-space: nowrap; overflow: hidden; }
        .nav-item { margin: 2px 10px; }
        .nav-link-item { display: flex; align-items: center; gap: 12px; padding: 11px 14px; border-radius: 10px; color: rgba(255,255,255,0.65); text-decoration: none; font-size: 14px; font-weight: 500; transition: all var(--transition); white-space: nowrap; position: relative; }
        .nav-link-item .nav-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
        .nav-link-item .nav-text { flex: 1; }
        .nav-link-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-link-item.active { background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(139,92,246,0.2)); color: #fff; box-shadow: inset 0 0 0 1px rgba(99,102,241,0.3); }
        .nav-link-item.active .nav-icon { color: #818cf8; }
        .nav-badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
        .sidebar-user { padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 12px; white-space: nowrap; }
        .user-avatar { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #a78bfa); color: #fff; font-weight: 700; font-size: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .user-info { flex: 1; overflow: hidden; }
        .user-name { font-size: 13px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-rank { font-size: 11px; color: rgba(255,255,255,0.45); text-transform: capitalize; }
        .btn-logout { display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.2); color: #f87171; font-size: 14px; cursor: pointer; transition: all var(--transition); text-decoration: none; flex-shrink: 0; }
        .btn-logout:hover { background: rgba(239,68,68,0.25); color: #fca5a5; }

        /* COLLAPSED */
        body.sidebar-collapsed #sidebar { width: var(--sidebar-w-sm); }
        body.sidebar-collapsed .brand-text, body.sidebar-collapsed .nav-text, body.sidebar-collapsed .nav-badge, body.sidebar-collapsed .nav-section-label, body.sidebar-collapsed .user-info, body.sidebar-collapsed .btn-logout { display: none; }
        body.sidebar-collapsed .sidebar-brand { justify-content: center; padding: 20px; }
        body.sidebar-collapsed .nav-link-item { justify-content: center; padding: 12px; }
        body.sidebar-collapsed .nav-item { margin: 2px 8px; }
        body.sidebar-collapsed .sidebar-user { justify-content: center; padding: 14px 8px; }
        body.sidebar-collapsed .nav-link-item::after { content: attr(data-label); position: absolute; left: calc(100% + 12px); top: 50%; transform: translateY(-50%); background: #1e293b; color: #fff; padding: 5px 10px; border-radius: 6px; font-size: 12px; white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        body.sidebar-collapsed .nav-link-item:hover::after { opacity: 1; }

        #sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); z-index: 1035; }

        /* ── MAIN ── */
        #main-wrapper { margin-left: var(--sidebar-w); min-height: 100vh; display: flex; flex-direction: column; transition: margin-left var(--transition); }
        body.sidebar-collapsed #main-wrapper { margin-left: var(--sidebar-w-sm); }

        /* ── TOPBAR ── */
        #topbar { height: var(--topbar-h); background: var(--card-bg); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; padding: 0 24px; gap: 16px; position: sticky; top: 0; z-index: 1030; box-shadow: var(--shadow-sm); }
        .topbar-toggle { width: 36px; height: 36px; border-radius: 8px; border: 1px solid var(--border-color); background: transparent; color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; transition: all var(--transition); }
        .topbar-toggle:hover { background: var(--border-color); color: var(--text-primary); }
        .topbar-title { font-size: 17px; font-weight: 700; color: var(--text-primary); flex: 1; }
        .topbar-title small { display: block; font-size: 11px; font-weight: 400; color: var(--text-muted); margin-top: -2px; }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .topbar-btn { width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--border-color); background: transparent; color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 15px; transition: all var(--transition); }
        .topbar-btn:hover { background: var(--border-color); color: var(--text-primary); }
        .avatar-btn { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #6366f1, #a78bfa); color: #fff; font-weight: 700; font-size: 14px; border: 2px solid rgba(99,102,241,0.3); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all var(--transition); }
        .avatar-btn:hover { transform: scale(1.05); }
        .avatar-dropdown { position: relative; }
        .dropdown-menu-custom { position: absolute; right: 0; top: calc(100% + 10px); min-width: 200px; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: var(--radius-sm); box-shadow: var(--shadow-lg); padding: 6px; display: none; z-index: 9999; }
        .dropdown-menu-custom.show { display: block; }
        .dropdown-menu-custom a { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border-radius: 6px; color: var(--text-secondary); text-decoration: none; font-size: 13px; font-weight: 500; transition: all var(--transition); }
        .dropdown-menu-custom a:hover { background: var(--border-color); color: var(--text-primary); }
        .dropdown-menu-custom .divider { height: 1px; background: var(--border-color); margin: 4px 0; }
        .dropdown-menu-custom a.danger { color: #ef4444; }
        .dropdown-menu-custom a.danger:hover { background: rgba(239,68,68,0.08); }

        /* ── PAGE CONTENT ── */
        #page-content { flex: 1; padding: 28px; }

        /* ── SECTION CARD ── */
        .section-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 24px; }
        .section-card-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border-color); flex-wrap: wrap; gap: 8px; }
        .section-card-title { font-size: 14px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .section-card-title i { color: var(--accent); }
        .section-card-body { padding: 20px; }

        /* ── STATS CARDS ── */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .stats-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 16px; transition: transform 0.2s, box-shadow 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; flex-shrink: 0; }
        .stat-info { flex: 1; min-width: 0; }
        .stat-value { font-size: 18px; font-weight: 800; color: var(--text-primary); line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .stat-label { font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .grad-blue   { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .grad-green  { background: linear-gradient(135deg, #10b981, #059669); }
        .grad-orange { background: linear-gradient(135deg, #f97316, #ea580c); }
        .grad-purple { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .grad-red    { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .grad-gray   { background: linear-gradient(135deg, #64748b, #475569); }
        .card-glow-green { box-shadow: 0 0 0 2px rgba(16,185,129,0.3), var(--shadow-md); }
        .card-glow-red   { box-shadow: 0 0 0 2px rgba(239,68,68,0.3), var(--shadow-md); }

        /* ── FILTER BAR ── */
        .filter-bar { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius); padding: 16px 20px; margin-bottom: 24px; box-shadow: var(--shadow-sm); position: sticky; top: 64px; z-index: 500; }
        .filter-row { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; }
        .filter-input { padding: 8px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--content-bg); color: var(--text-primary); font-size: 13px; font-family: 'Inter', sans-serif; transition: border-color var(--transition); }
        .filter-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
        .btn-analyser { padding: 9px 20px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border: none; border-radius: var(--radius-sm); font-size: 13px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 7px; transition: all var(--transition); font-family: 'Inter', sans-serif; }
        .btn-analyser:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(99,102,241,0.4); }
        .shortcut-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .btn-shortcut { padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid var(--border-color); background: var(--card-bg); color: var(--text-secondary); cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; }
        .btn-shortcut:hover, .btn-shortcut.active { background: #6366f1; color: white; border-color: #6366f1; }

        /* ── RÉSUMÉ CAISSE ── */
        .resume-caisse { background: var(--card-bg); border: 2px solid var(--border-color); border-radius: 16px; padding: 24px 28px; box-shadow: var(--shadow-lg); max-width: 600px; margin: 0 auto 28px; }
        .resume-title { font-size: 16px; font-weight: 800; color: var(--text-primary); font-family: 'Inter', sans-serif; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .resume-periode { font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border-color); }
        .resume-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 13px; color: var(--text-secondary); border-bottom: 1px dotted var(--border-color); }
        .resume-row:last-child { border-bottom: none; }
        .resume-row.separator { border-top: 2px solid var(--border-color); border-bottom: 2px solid var(--border-color); margin: 10px 0; padding: 12px 0; }
        .resume-row .label { font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .resume-row .value { font-weight: 700; font-size: 14px; font-family: 'Courier New', monospace; }
        .value-positive { color: #10b981; }
        .value-negative { color: #ef4444; }
        .value-neutral  { color: var(--text-primary); }
        .solde-value { font-size: 24px; font-weight: 900; font-family: 'Courier New', monospace; }

        /* ── TABLE ── */
        .table-custom { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table-custom thead th { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: var(--text-muted); padding: 10px 12px; border-bottom: 1px solid var(--border-color); white-space: nowrap; }
        .table-custom tbody tr { border-bottom: 1px solid var(--border-color); transition: background 0.15s; }
        .table-custom tbody tr:last-child { border-bottom: none; }
        .table-custom td { padding: 10px 12px; color: var(--text-secondary); vertical-align: middle; }
        .row-solde   { background: rgba(16,185,129,0.04); }
        .row-partiel { background: rgba(245,158,11,0.04); }
        .row-credit  { background: rgba(239,68,68,0.06); }
        .tfoot-total td { font-weight: 800; font-size: 13px; color: var(--text-primary); border-top: 2px solid var(--border-color); background: var(--content-bg); padding: 10px 12px; }

        /* ── BADGES ── */
        .badge-solde   { background: #dcfce7; color: #166534; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge-partiel { background: #fef9c3; color: #854d0e; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .badge-credit  { background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; white-space: nowrap; }
        [data-theme="dark"] .badge-solde   { background: rgba(16,185,129,0.2); color: #6ee7b7; }
        [data-theme="dark"] .badge-partiel { background: rgba(245,158,11,0.2); color: #fcd34d; }
        [data-theme="dark"] .badge-credit  { background: rgba(239,68,68,0.2); color: #fca5a5; }

        /* ── TOGGLE BUTTON ── */
        .btn-toggle-table { font-size: 12px; padding: 4px 12px; border-radius: 6px; border: 1px solid var(--border-color); background: transparent; color: var(--text-secondary); cursor: pointer; transition: all 0.2s; font-family: 'Inter', sans-serif; }
        .btn-toggle-table:hover { background: var(--border-color); }

        /* ── LINK ICON ── */
        .btn-link-icon { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--border-color); background: transparent; color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-size: 12px; transition: all 0.2s; }
        .btn-link-icon:hover { background: #6366f1; color: white; border-color: #6366f1; }

        /* ── EMPTY STATE ── */
        .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; margin-bottom: 12px; opacity: 0.35; display: block; }
        .empty-state p { font-size: 13px; }

        /* ── SECTION LABEL ── */
        .section-group-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .section-group-label::after { content: ''; flex: 1; height: 1px; background: var(--border-color); }

        /* ── RESPONSIVE ── */
        @media (max-width: 991px) {
            #sidebar { transform: translateX(-100%); width: var(--sidebar-w) !important; }
            body.sidebar-mobile-open #sidebar { transform: translateX(0); }
            body.sidebar-mobile-open #sidebar-overlay { display: block; }
            #main-wrapper { margin-left: 0 !important; }
        }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 640px) {
            .stats-grid, .stats-grid-2 { grid-template-columns: 1fr; }
            #page-content { padding: 16px; }
        }
        [data-theme="dark"] ::-webkit-scrollbar { width: 6px; }
        [data-theme="dark"] ::-webkit-scrollbar-track { background: #0f172a; }
        [data-theme="dark"] ::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
    </style>
</head>
<body>

<div id="sidebar-overlay" onclick="closeMobileSidebar()"></div>

<nav id="sidebar">
    <a href="../dashboard.php" class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-chart-line"></i></div>
        <span class="brand-text">G-<span>Business</span></span>
    </a>
    <div class="sidebar-nav">
        <div class="nav-section-label">Navigation</div>
        <div class="nav-item">
            <a href="../dashboard.php" class="nav-link-item <?= $current_page==='dashboard'?'active':'' ?>" data-label="Dashboard">
                <i class="fa-solid fa-house nav-icon"></i><span class="nav-text">Dashboard</span>
            </a>
        </div>
        <?php if ($rank === 'admin'): ?>
        <div class="nav-item">
            <a href="../utilisateurs.php" class="nav-link-item <?= $current_page==='utilisateurs'?'active':'' ?>" data-label="Utilisateurs">
                <i class="fa-solid fa-users nav-icon"></i><span class="nav-text">Utilisateurs</span>
                <span class="nav-badge">Admin</span>
            </a>
        </div>
        <?php endif; ?>
        <div class="nav-item">
            <a href="../products.php" class="nav-link-item <?= $current_page==='products'?'active':'' ?>" data-label="Produits">
                <i class="fa-solid fa-boxes-stacked nav-icon"></i><span class="nav-text">Produits</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="../achats/achats.php" class="nav-link-item <?= $current_page==='achats'?'active':'' ?>" data-label="Achats">
                <i class="fa-solid fa-cart-shopping nav-icon"></i><span class="nav-text">Achats</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="../ventes/ventes.php" class="nav-link-item <?= $current_page==='ventes'?'active':'' ?>" data-label="Ventes">
                <i class="fa-solid fa-cash-register nav-icon"></i><span class="nav-text">Ventes</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="../clients.php" class="nav-link-item <?= $current_page==='clients'?'active':'' ?>" data-label="Clients">
                <i class="fa-solid fa-user nav-icon"></i><span class="nav-text">Clients</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="../fournisseurs.php" class="nav-link-item <?= $current_page==='fournisseurs'?'active':'' ?>" data-label="Fournisseurs">
                <i class="fa-solid fa-truck nav-icon"></i><span class="nav-text">Fournisseurs</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="caisse.php" class="nav-link-item <?= $current_page==='analyse'?'active':'' ?>" data-label="Analyse">
                <i class="fa-solid fa-calculator nav-icon"></i><span class="nav-text">Analyse</span>
            </a>
        </div>
    </div>
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
</nav>

<div id="main-wrapper">
    <header id="topbar">
        <button class="topbar-toggle" id="sidebarToggle"><i class="fa-solid fa-bars"></i></button>
        <div class="topbar-title">
            Caisse &amp; Comptabilité
            <small>Analyse financière de la période</small>
        </div>
        <div class="topbar-actions">
            <button class="topbar-btn" id="themeToggle"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
            <div class="avatar-dropdown">
                <button class="avatar-btn" id="avatarBtn"><?= htmlspecialchars($initials) ?></button>
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
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 style="font-size:22px;font-weight:800;color:var(--text-primary);margin:0;">
                    <i class="fa-solid fa-calculator me-2" style="color:#6366f1;"></i>
                    Caisse &amp; Comptabilité
                </h1>
                <p style="font-size:13px;color:var(--text-muted);margin:4px 0 0;">
                    Période : <?= htmlspecialchars($date_from) ?> → <?= htmlspecialchars($date_to) ?>
                </p>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <form method="GET" action="caisse.php" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label class="filter-label">Du</label>
                        <input type="date" name="date_from" id="date_from" class="filter-input"
                               value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Au</label>
                        <input type="date" name="date_to" id="date_to" class="filter-input"
                               value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div style="align-self:flex-end;">
                        <button type="submit" class="btn-analyser">
                            <i class="fa-solid fa-magnifying-glass"></i> Analyser
                        </button>
                    </div>
                </div>
                <div class="shortcut-btns" id="shortcutBtns">
                    <button type="button" class="btn-shortcut" data-range="today">Aujourd'hui</button>
                    <button type="button" class="btn-shortcut" data-range="yesterday">Hier</button>
                    <button type="button" class="btn-shortcut" data-range="7days">7 derniers jours</button>
                    <button type="button" class="btn-shortcut" data-range="month">Ce mois</button>
                    <button type="button" class="btn-shortcut" data-range="lastmonth">Mois dernier</button>
                </div>
            </form>
        </div>

        <!-- STATS VENTES -->
        <div class="section-group-label"><i class="fa-solid fa-cash-register" style="color:#10b981;"></i> Ventes</div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon grad-blue"><i class="fa-solid fa-chart-line"></i></div>
                <div class="stat-info">
                    <div class="stat-label">CA Brut</div>
                    <div class="stat-value"><?= fmt($ca_brut) ?></div>
                    <div class="stat-sub">Total HT vendu</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon grad-green"><i class="fa-solid fa-money-bill-wave"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Encaissé</div>
                    <div class="stat-value"><?= fmt($cash_recu) ?></div>
                    <div class="stat-sub"><?= $nb_encaisse ?> vente<?= $nb_encaisse > 1 ? 's' : '' ?> soldée<?= $nb_encaisse > 1 ? 's' : '' ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon grad-orange"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Ventes à Crédit</div>
                    <div class="stat-value"><?= fmt($total_credit) ?></div>
                    <div class="stat-sub"><?= $nb_credit ?> vente<?= $nb_credit > 1 ? 's' : '' ?> | Reste: <?= fmt($reste_credit) ?></div>
                </div>
            </div>
            <div class="stat-card <?= $benefice_net >= 0 ? 'card-glow-green' : 'card-glow-red' ?>">
                <div class="stat-icon grad-purple"><i class="fa-solid fa-coins"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Bénéfice Net</div>
                    <div class="stat-value" style="color:<?= $benefice_net >= 0 ? '#10b981' : '#ef4444' ?>;">
                        <?= fmt($benefice_net) ?>
                    </div>
                    <div class="stat-sub">Sur ventes soldées uniquement</div>
                </div>
            </div>
        </div>

        <!-- STATS ACHATS -->
        <div class="section-group-label"><i class="fa-solid fa-cart-shopping" style="color:#ef4444;"></i> Achats</div>
        <div class="stats-grid-2">
            <div class="stat-card">
                <div class="stat-icon grad-red"><i class="fa-solid fa-cart-shopping"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Total Achats</div>
                    <div class="stat-value"><?= fmt($total_achats) ?></div>
                    <div class="stat-sub">Montant total commandé</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon grad-gray"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Achats Versés</div>
                    <div class="stat-value"><?= fmt($verse_achats) ?></div>
                    <div class="stat-sub">Reste dû : <?= fmt(max(0, $dettes_fourn)) ?></div>
                </div>
            </div>
        </div>

        <!-- RÉSUMÉ CAISSE -->
        <div class="section-group-label"><i class="fa-solid fa-receipt" style="color:#6366f1;"></i> Résumé Comptable</div>
        <div class="resume-caisse">
            <div class="resume-title">
                <i class="fa-solid fa-calculator" style="color:#6366f1;"></i>
                RÉSUMÉ COMPTABLE
            </div>
            <div class="resume-periode">
                Période : <strong><?= htmlspecialchars(date('d/m/Y', strtotime($date_from))) ?></strong>
                → <strong><?= htmlspecialchars(date('d/m/Y', strtotime($date_to))) ?></strong>
            </div>

            <div class="resume-row">
                <span class="label"><span style="color:#10b981;">+</span> Cash encaissé ventes</span>
                <span class="value value-positive"><?= fmt($total_encaisse) ?></span>
            </div>
            <div class="resume-row">
                <span class="label"><span style="color:#f97316;">+</span> Acomptes crédits reçus</span>
                <span class="value" style="color:#f97316;"><?= fmt($verse_credit) ?></span>
            </div>
            <div class="resume-row">
                <span class="label"><span style="color:#ef4444;">−</span> Cash versé achats</span>
                <span class="value value-negative"><?= fmt($verse_achats) ?></span>
            </div>

            <div class="resume-row separator">
                <span class="label" style="font-size:15px;font-weight:800;color:var(--text-primary);">
                    <i class="fa-solid fa-equals" style="color:#6366f1;"></i>
                    SOLDE CAISSE
                </span>
                <span class="solde-value <?= $solde_caisse >= 0 ? 'value-positive' : 'value-negative' ?>">
                    <?= fmt($solde_caisse) ?>
                </span>
            </div>

            <div class="resume-row" style="margin-top:8px;">
                <span class="label"><i class="fa-solid fa-coins" style="color:#8b5cf6;width:16px;"></i> Bénéfice net (soldées)</span>
                <span class="value <?= $benefice_net >= 0 ? 'value-positive' : 'value-negative' ?>"><?= fmt($benefice_net) ?></span>
            </div>
            <div class="resume-row">
                <span class="label"><i class="fa-solid fa-file-invoice" style="color:#f97316;width:16px;"></i> Créances à recouvrer</span>
                <span class="value" style="color:#f97316;"><?= fmt($reste_credit) ?></span>
            </div>
            <div class="resume-row">
                <span class="label"><i class="fa-solid fa-truck" style="color:#ef4444;width:16px;"></i> Dettes fournisseurs</span>
                <span class="value value-negative"><?= fmt(max(0, $dettes_fourn)) ?></span>
            </div>
        </div>

        <!-- TABLEAU VENTES -->
        <?php
        $sum_v_total = array_sum(array_column($liste_ventes, 'total'));
        $sum_v_verse = array_sum(array_column($liste_ventes, 'versement'));
        $sum_v_reste = array_sum(array_column($liste_ventes, 'reste'));
        $show_ventes = count($liste_ventes) <= 10;
        ?>
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title">
                    <i class="fa-solid fa-cash-register"></i>
                    Ventes de la période
                    <span style="font-size:11px;font-weight:400;color:var(--text-muted);">— <?= count($liste_ventes) ?> vente<?= count($liste_ventes) > 1 ? 's' : '' ?></span>
                </div>
                <button class="btn-toggle-table" onclick="toggleTable('tableVentes')">
                    <i class="fa-solid fa-<?= $show_ventes ? 'eye-slash' : 'eye' ?>"></i>
                    <?= $show_ventes ? 'Masquer' : 'Afficher' ?>
                </button>
            </div>
            <div id="tableVentes" style="overflow-x:auto;<?= $show_ventes ? '' : 'display:none;' ?>">
                <?php if (empty($liste_ventes)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-cash-register"></i>
                        <p>Aucune vente sur cette période.</p>
                    </div>
                <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>N° BL</th>
                            <th>Client</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Versé</th>
                            <th>Reste</th>
                            <th>Statut</th>
                            <th style="text-align:center;">🔗</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liste_ventes as $v): ?>
                        <tr class="row-<?= htmlspecialchars($v['statut']) ?>">
                            <td style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($v['num'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($v['client'] ?? '—') ?></td>
                            <td style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($v['date'])) ?></td>
                            <td style="font-weight:600;"><?= fmt($v['total']) ?></td>
                            <td style="color:#10b981;font-weight:600;"><?= fmt($v['versement']) ?></td>
                            <td style="color:<?= floatval($v['reste']) > 0 ? '#ef4444' : '#10b981' ?>;font-weight:600;">
                                <?= fmt(max(0, $v['reste'])) ?>
                            </td>
                            <td>
                                <?php if ($v['statut'] === 'solde'): ?>
                                    <span class="badge-solde">✅ Soldé</span>
                                <?php elseif ($v['statut'] === 'partiel'): ?>
                                    <span class="badge-partiel">⚡ Partiel</span>
                                <?php else: ?>
                                    <span class="badge-credit">❌ Crédit</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <a href="../ventes/bonVente.php?id=<?= (int)$v['id'] ?>" class="btn-link-icon" title="Voir">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="tfoot-total">
                            <td colspan="3">TOTAL (<?= count($liste_ventes) ?> ventes)</td>
                            <td><?= fmt($sum_v_total) ?></td>
                            <td style="color:#10b981;"><?= fmt($sum_v_verse) ?></td>
                            <td style="color:#ef4444;"><?= fmt(max(0, $sum_v_reste)) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- TABLEAU ACHATS -->
        <?php
        $sum_a_total = array_sum(array_column($liste_achats, 'total'));
        $sum_a_verse = array_sum(array_column($liste_achats, 'versement'));
        $sum_a_reste = array_sum(array_column($liste_achats, 'reste'));
        $show_achats = count($liste_achats) <= 10;
        ?>
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title">
                    <i class="fa-solid fa-cart-shopping"></i>
                    Achats de la période
                    <span style="font-size:11px;font-weight:400;color:var(--text-muted);">— <?= count($liste_achats) ?> achat<?= count($liste_achats) > 1 ? 's' : '' ?></span>
                </div>
                <button class="btn-toggle-table" onclick="toggleTable('tableAchats')">
                    <i class="fa-solid fa-<?= $show_achats ? 'eye-slash' : 'eye' ?>"></i>
                    <?= $show_achats ? 'Masquer' : 'Afficher' ?>
                </button>
            </div>
            <div id="tableAchats" style="overflow-x:auto;<?= $show_achats ? '' : 'display:none;' ?>">
                <?php if (empty($liste_achats)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-cart-shopping"></i>
                        <p>Aucun achat sur cette période.</p>
                    </div>
                <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>N° BL</th>
                            <th>Fournisseur</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Versé</th>
                            <th>Reste</th>
                            <th style="text-align:center;">🔗</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liste_achats as $a): ?>
                        <tr>
                            <td style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($a['num'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($a['fournisseur'] ?? '—') ?></td>
                            <td style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($a['date'])) ?></td>
                            <td style="font-weight:600;"><?= fmt($a['total']) ?></td>
                            <td style="color:#10b981;font-weight:600;"><?= fmt($a['versement']) ?></td>
                            <td style="color:<?= floatval($a['reste']) > 0 ? '#ef4444' : '#10b981' ?>;font-weight:600;">
                                <?= fmt(max(0, $a['reste'])) ?>
                            </td>
                            <td style="text-align:center;">
                                <a href="../achats/bonAchat.php?id=<?= (int)$a['id'] ?>" class="btn-link-icon" title="Voir">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="tfoot-total">
                            <td colspan="3">TOTAL (<?= count($liste_achats) ?> achats)</td>
                            <td><?= fmt($sum_a_total) ?></td>
                            <td style="color:#10b981;"><?= fmt($sum_a_verse) ?></td>
                            <td style="color:#ef4444;"><?= fmt(max(0, $sum_a_reste)) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ── THEME ── */
const htmlEl   = document.documentElement;
const themeBtn = document.getElementById('themeToggle');
const themeIco = document.getElementById('themeIcon');
function applyTheme(t) {
    htmlEl.setAttribute('data-theme', t);
    themeIco.className = t === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
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

/* ── TOGGLE TABLE ── */
function toggleTable(id) {
    const el = document.getElementById(id);
    const btn = el.previousElementSibling.querySelector('.btn-toggle-table');
    const isHidden = el.style.display === 'none';
    el.style.display = isHidden ? '' : 'none';
    if (btn) btn.innerHTML = isHidden
        ? '<i class="fa-solid fa-eye-slash"></i> Masquer'
        : '<i class="fa-solid fa-eye"></i> Afficher';
}

/* ── DATE SHORTCUTS ── */
function padZ(n) { return String(n).padStart(2,'0'); }
function fmtDate(d) {
    return d.getFullYear() + '-' + padZ(d.getMonth()+1) + '-' + padZ(d.getDate());
}

function setDateRange(from, to) {
    document.getElementById('date_from').value = from;
    document.getElementById('date_to').value   = to;
    document.getElementById('filterForm').submit();
}

document.querySelectorAll('.btn-shortcut').forEach(btn => {
    btn.addEventListener('click', function() {
        const now   = new Date();
        const today = fmtDate(now);
        const range = this.dataset.range;

        if (range === 'today') {
            setDateRange(today, today);
        } else if (range === 'yesterday') {
            const y = new Date(now); y.setDate(y.getDate() - 1);
            setDateRange(fmtDate(y), fmtDate(y));
        } else if (range === '7days') {
            const d = new Date(now); d.setDate(d.getDate() - 6);
            setDateRange(fmtDate(d), today);
        } else if (range === 'month') {
            const first = new Date(now.getFullYear(), now.getMonth(), 1);
            setDateRange(fmtDate(first), today);
        } else if (range === 'lastmonth') {
            const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            const last  = new Date(now.getFullYear(), now.getMonth(), 0);
            setDateRange(fmtDate(first), fmtDate(last));
        }
    });
});

/* ── HIGHLIGHT ACTIVE SHORTCUT ── */
(function() {
    const now   = new Date();
    const today = fmtDate(now);
    const from  = document.getElementById('date_from').value;
    const to    = document.getElementById('date_to').value;

    const yest  = new Date(now); yest.setDate(yest.getDate() - 1);
    const ago7  = new Date(now); ago7.setDate(ago7.getDate() - 6);
    const fm    = new Date(now.getFullYear(), now.getMonth(), 1);
    const flm   = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    const llm   = new Date(now.getFullYear(), now.getMonth(), 0);

    const ranges = {
        'today':     [today, today],
        'yesterday': [fmtDate(yest), fmtDate(yest)],
        '7days':     [fmtDate(ago7), today],
        'month':     [fmtDate(fm), today],
        'lastmonth': [fmtDate(flm), fmtDate(llm)],
    };

    document.querySelectorAll('.btn-shortcut').forEach(btn => {
        const r = ranges[btn.dataset.range];
        if (r && r[0] === from && r[1] === to) btn.classList.add('active');
    });
})();
</script>

</body>
</html>
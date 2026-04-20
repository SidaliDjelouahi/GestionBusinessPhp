<?php
// ─── SESSION & AUTH ────────────────────────────────────────────────────────
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

// ─── DATABASE ───────────────────────────────────────────────────────────────
$pdo = getDBConnection();
if ($pdo === null) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Connexion impossible']);
    exit;
}

// ─── ID PARAM ───────────────────────────────────────────────────────────────
$id = trim($_GET['id'] ?? '');

header('Content-Type: application/json');

if (!$id || !is_numeric($id)) {
    echo json_encode(['error' => 'ID invalide']);
    exit;
}
$id = (int)$id;

// ─── QUERY VENTE HEADER ─────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT v.id, v.num, v.date, v.versement, v.id_clients,
               c.nom AS client_nom
        FROM ventes v
        LEFT JOIN clients c ON c.id = v.id_clients
        WHERE v.id = ?
    ");
    $stmt->execute([$id]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vente) {
        echo json_encode(['error' => 'Vente introuvable']);
        exit;
    }

    // ─── QUERY DETAILS ──────────────────────────────────────────────────────
    $stmt2 = $pdo->prepare("
        SELECT vd.id, vd.id_produit,
               p.nom AS produit_nom,
               vd.qte, vd.prix_vente,
               (vd.prix_vente * vd.qte) AS total_ligne
        FROM vente_details vd
        LEFT JOIN produits p ON p.id = vd.id_produit
        WHERE vd.id_vente = ?
        ORDER BY vd.id ASC
    ");
    $stmt2->execute([$id]);
    $details = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Numeric casting
    $details = array_map(function($d) {
        return [
            'id'          => (int)$d['id'],
            'id_produit'  => (int)$d['id_produit'],
            'produit_nom' => $d['produit_nom'] ?? '—',
            'qte'         => floatval($d['qte']),
            'prix_vente'  => floatval($d['prix_vente']),
            'total_ligne' => floatval($d['total_ligne']),
        ];
    }, $details);

    $montant_total = array_sum(array_column($details, 'total_ligne'));

    $result = [
        'vente'         => [
            'id'          => (int)$vente['id'],
            'num'         => $vente['num'],
            'date'        => $vente['date'],
            'client_nom'  => $vente['client_nom'],
            'versement'   => floatval($vente['versement']),
        ],
        'details'       => $details,
        'montant_total' => $montant_total,
    ];

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['error' => 'Erreur requête : ' . $e->getMessage()]);
}
exit;

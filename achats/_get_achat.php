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

// ─── QUERY ACHAT HEADER ─────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT a.id, a.num, a.date, a.versement, a.id_fournisseur,
               f.nom AS fournisseur_nom
        FROM achats a
        LEFT JOIN fournisseurs f ON f.id = a.id_fournisseur
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $achat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$achat) {
        echo json_encode(['error' => 'Achat introuvable']);
        exit;
    }

    // ─── QUERY DETAILS ──────────────────────────────────────────────────────
    $stmt2 = $pdo->prepare("
        SELECT ad.id, ad.id_produit,
               p.nom AS produit_nom,
               ad.qte, ad.prix_achat,
               (ad.prix_achat * ad.qte) AS total_ligne
        FROM achat_details ad
        LEFT JOIN produits p ON p.id = ad.id_produit
        WHERE ad.id_achat = ?
        ORDER BY ad.id ASC
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
            'prix_achat'  => floatval($d['prix_achat']),
            'total_ligne' => floatval($d['total_ligne']),
        ];
    }, $details);

    $montant_total = array_sum(array_column($details, 'total_ligne'));

    $result = [
        'achat'         => [
            'id'             => (int)$achat['id'],
            'num'            => $achat['num'],
            'date'           => $achat['date'],
            'fournisseur_nom'=> $achat['fournisseur_nom'],
            'versement'      => floatval($achat['versement']),
        ],
        'details'       => $details,
        'montant_total' => $montant_total,
    ];

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['error' => 'Erreur requête : ' . $e->getMessage()]);
}
exit;

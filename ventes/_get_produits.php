<?php
// ─── SESSION & AUTH ────────────────────────────────────────────────────────
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Non autorisé', 'data' => []]);
    exit;
}

// ─── DATABASE ───────────────────────────────────────────────────────────────
$pdo = getDBConnection();
if ($pdo === null) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Connexion impossible', 'data' => []]);
    exit;
}

// ─── SEARCH PARAM ───────────────────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');

header('Content-Type: application/json');

if ($q === '') {
    echo json_encode([]);
    exit;
}

// ─── QUERY ──────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, nom, qte, prix_achat, prix_vente
        FROM produits
        WHERE nom LIKE ?
        ORDER BY nom ASC
        LIMIT 20
    ");
    $stmt->execute(['%' . $q . '%']);
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ensure numeric types
    $produits = array_map(function($p) {
        return [
            'id'         => (int)$p['id'],
            'nom'        => $p['nom'],
            'qte'        => floatval($p['qte']),
            'prix_achat' => floatval($p['prix_achat']),
            'prix_vente' => floatval($p['prix_vente']),
        ];
    }, $produits);

    echo json_encode($produits);
} catch (Exception $e) {
    echo json_encode(['error' => 'Erreur requête', 'data' => []]);
}
exit;

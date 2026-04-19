<?php
session_start();
require_once '../db_connect.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Non autorisé']);
    exit;
}

$pdo = getDBConnection();
if ($pdo === null) {
    echo json_encode(['success' => false, 'error' => 'Connexion DB impossible']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Méthode invalide']);
    exit;
}

$nom       = trim($_POST['nom']       ?? '');
$telephone = trim($_POST['telephone'] ?? '');
$adresse   = trim($_POST['adresse']   ?? '');

// Validation
if ($nom === '') {
    echo json_encode(['success' => false, 'error' => 'Le nom du fournisseur est obligatoire.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO fournisseurs (nom, adresse, telephone) VALUES (?, ?, ?)"
    );
    $stmt->execute([$nom, $adresse, $telephone]);
    $new_id = (int)$pdo->lastInsertId();

    // Return the new fournisseur + full updated list
    $all = $pdo->query("SELECT id, nom FROM fournisseurs ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'          => true,
        'new_id'           => $new_id,
        'new_nom'          => $nom,
        'all_fournisseurs' => $all
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Erreur: ' . $e->getMessage()]);
}
exit;

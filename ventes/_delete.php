<?php
// ─── SESSION & AUTH ────────────────────────────────────────────────────────
session_start();
require_once '../db_connect.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php'); exit;
}

// ─── DATABASE ───────────────────────────────────────────────────────────────
$pdo = getDBConnection();
if ($pdo === null) {
    header('Location: ventes.php?msg=error'); exit;
}

// ─── POST ONLY ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ventes.php'); exit;
}

// ─── VALIDATE ID ────────────────────────────────────────────────────────────
$id = trim($_POST['id'] ?? '');
if (!$id || !is_numeric($id)) {
    header('Location: ventes.php?msg=error'); exit;
}
$id = (int)$id;

// ─── TRANSACTION ────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // Restore stock (deleting sale → ADD back)
    $stmt = $pdo->prepare("SELECT id_produit, qte FROM vente_details WHERE id_vente = ?");
    $stmt->execute([$id]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtStk = $pdo->prepare("UPDATE produits SET qte = qte + ? WHERE id = ?");
    foreach ($lines as $line) {
        $stmtStk->execute([floatval($line['qte']), (int)$line['id_produit']]);
    }

    // Delete details
    $stmt = $pdo->prepare("DELETE FROM vente_details WHERE id_vente = ?");
    $stmt->execute([$id]);

    // Delete vente
    $stmt = $pdo->prepare("DELETE FROM ventes WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();
    header('Location: ventes.php?msg=deleted'); exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: ventes.php?msg=error'); exit;
}

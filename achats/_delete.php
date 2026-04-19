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
    header('Location: achats.php?msg=error'); exit;
}

// ─── POST ONLY ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: achats.php'); exit;
}

// ─── VALIDATE ID ────────────────────────────────────────────────────────────
$id = trim($_POST['id'] ?? '');
if (!$id || !is_numeric($id)) {
    header('Location: achats.php?msg=error'); exit;
}
$id = (int)$id;

// ─── TRANSACTION ────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // Reverse stock
    $stmt = $pdo->prepare("SELECT id_produit, qte FROM achat_details WHERE id_achat = ?");
    $stmt->execute([$id]);
    $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtStk = $pdo->prepare("UPDATE produits SET qte = qte - ? WHERE id = ?");
    foreach ($lines as $line) {
        $stmtStk->execute([floatval($line['qte']), (int)$line['id_produit']]);
    }

    // Delete details
    $stmt = $pdo->prepare("DELETE FROM achat_details WHERE id_achat = ?");
    $stmt->execute([$id]);

    // Delete achat
    $stmt = $pdo->prepare("DELETE FROM achats WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();
    header('Location: achats.php?msg=deleted'); exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: achats.php?msg=error'); exit;
}

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

// ─── INPUTS ─────────────────────────────────────────────────────────────────
$num           = trim($_POST['num']           ?? '');
$date_achat    = trim($_POST['date_achat']    ?? date('Y-m-d H:i:s'));
$id_fournisseur = trim($_POST['id_fournisseur'] ?? '');
$versement     = floatval($_POST['versement'] ?? 0);
$edit_id       = isset($_POST['edit_id']) && is_numeric($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

$detail_produit = $_POST['detail_produit'] ?? [];
$detail_qte     = $_POST['detail_qte']     ?? [];
$detail_prix    = $_POST['detail_prix']    ?? [];

// ─── VALIDATION ─────────────────────────────────────────────────────────────
if (!$id_fournisseur || !is_numeric($id_fournisseur)) {
    header('Location: bonAchat.php' . ($edit_id ? '?id=' . $edit_id . '&' : '?') . 'error=' . urlencode('Fournisseur invalide ou manquant.')); exit;
}

$valid_lines = [];
foreach ($detail_produit as $i => $pid) {
    $pid = is_numeric($pid) ? (int)$pid : 0;
    $qte = floatval($detail_qte[$i] ?? 0);
    $prix = floatval($detail_prix[$i] ?? 0);
    if ($pid > 0 && $qte > 0) {
        $valid_lines[] = ['pid' => $pid, 'qte' => $qte, 'prix' => $prix];
    }
}

if (empty($valid_lines)) {
    header('Location: bonAchat.php' . ($edit_id ? '?id=' . $edit_id . '&' : '?') . 'error=' . urlencode('Aucune ligne de produit valide.')); exit;
}

// Validate date
$date_achat_clean = date('Y-m-d H:i:s', strtotime($date_achat));
if ($date_achat_clean === '1970-01-01 00:00:00') {
    $date_achat_clean = date('Y-m-d H:i:s');
}

// ─── TRANSACTION ────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    if ($edit_id) {
        // Reverse old stock
        $stmt = $pdo->prepare("SELECT id_produit, qte FROM achat_details WHERE id_achat = ?");
        $stmt->execute([$edit_id]);
        $old_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($old_lines as $ol) {
            $stmt2 = $pdo->prepare("UPDATE produits SET qte = qte - ? WHERE id = ?");
            $stmt2->execute([floatval($ol['qte']), (int)$ol['id_produit']]);
        }
        // Delete old details
        $stmt = $pdo->prepare("DELETE FROM achat_details WHERE id_achat = ?");
        $stmt->execute([$edit_id]);
        // Update achat header
        $stmt = $pdo->prepare("UPDATE achats SET num = ?, date = ?, id_fournisseur = ?, versement = ? WHERE id = ?");
        $stmt->execute([$num, $date_achat_clean, (int)$id_fournisseur, $versement, $edit_id]);
        $achat_id = $edit_id;
    } else {
        // Insert new achat
        $stmt = $pdo->prepare("INSERT INTO achats (num, date, id_fournisseur, versement) VALUES (?, ?, ?, ?)");
        $stmt->execute([$num, $date_achat_clean, (int)$id_fournisseur, $versement]);
        $achat_id = (int)$pdo->lastInsertId();
    }

    // Insert new lines + update stock
    $stmtIns = $pdo->prepare("INSERT INTO achat_details (id_achat, id_produit, prix_achat, qte) VALUES (?, ?, ?, ?)");
    $stmtStk = $pdo->prepare("UPDATE produits SET qte = qte + ? WHERE id = ?");
    foreach ($valid_lines as $line) {
        $stmtIns->execute([$achat_id, $line['pid'], $line['prix'], $line['qte']]);
        $stmtStk->execute([$line['qte'], $line['pid']]);
    }

    $pdo->commit();
    header('Location: achats.php?msg=' . ($edit_id ? 'updated' : 'created')); exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $err = urlencode('Erreur base de données : ' . $e->getMessage());
    header('Location: bonAchat.php' . ($edit_id ? '?id=' . $edit_id . '&error=' : '?error=') . $err); exit;
}

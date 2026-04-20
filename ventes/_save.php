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

// ─── INPUTS ─────────────────────────────────────────────────────────────────
$num           = trim($_POST['num']           ?? '');
$date_vente    = trim($_POST['date_vente']    ?? date('Y-m-d H:i:s'));
$id_clients    = isset($_POST['id_clients'])
    && is_numeric($_POST['id_clients'])
    && (int)$_POST['id_clients'] > 0
    ? (int)$_POST['id_clients'] : null;
// client is optional — null is accepted
$versement     = floatval($_POST['versement'] ?? 0);
$edit_id       = isset($_POST['edit_id']) && is_numeric($_POST['edit_id']) ? (int)$_POST['edit_id'] : null;

$detail_produit = $_POST['detail_produit'] ?? [];
$detail_qte     = $_POST['detail_qte']     ?? [];
$detail_prix    = $_POST['detail_prix']    ?? [];

// ─── VALIDATION ─────────────────────────────────────────────────────────────


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
    if (empty($id_clients) || $versement <= 0) {
        $msg = 'Pour valider un bon sans produits, vous devez obligatoirement sélectionner un client ET saisir un versement.';
        header('Location: bonVente.php' . ($edit_id ? '?id=' . $edit_id . '&' : '?') . 'error=' . urlencode($msg)); 
        exit;
    }
}

// Validate date
$date_vente_clean = date('Y-m-d H:i:s', strtotime($date_vente));
if ($date_vente_clean === '1970-01-01 00:00:00') {
    $date_vente_clean = date('Y-m-d H:i:s');
}

// ─── TRANSACTION ────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    if ($edit_id) {
        // Reverse old stock (undo old sale: ADD back)
        $stmt = $pdo->prepare("SELECT id_produit, qte FROM vente_details WHERE id_vente = ?");
        $stmt->execute([$edit_id]);
        $old_lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($old_lines as $ol) {
            $stmt2 = $pdo->prepare("UPDATE produits SET qte = qte + ? WHERE id = ?");
            $stmt2->execute([floatval($ol['qte']), (int)$ol['id_produit']]);
        }
        // Delete old details
        $stmt = $pdo->prepare("DELETE FROM vente_details WHERE id_vente = ?");
        $stmt->execute([$edit_id]);
        // Update vente header
        $stmt = $pdo->prepare("UPDATE ventes SET num = ?, date = ?, id_clients = ?, versement = ? WHERE id = ?");
        $stmt->execute([$num, $date_vente_clean, $id_clients, $versement, $edit_id]);
        $vente_id = $edit_id;
    } else {
        // Vérifier si le numéro de bon existe déjà pour éviter les doublons
        $stmtCheck = $pdo->prepare("SELECT num FROM ventes WHERE num = ?");
        $stmtCheck->execute([$num]);
        if ($stmtCheck->fetch()) {
            // Le numéro existe : récupérer le dernier numéro de la table
            $stmtLast = $pdo->query("SELECT num FROM ventes ORDER BY id DESC LIMIT 1");
            $lastRow = $stmtLast->fetch(PDO::FETCH_ASSOC);
            if ($lastRow && $lastRow['num']) {
                $dernier_num = $lastRow['num'];
                // Gérer l'incrémentation si le numéro contient des lettres (ex: VTE-20240419-001)
                if (preg_match('/^(.*?)(\d+)$/', $dernier_num, $matches)) {
                    $prefix = $matches[1];
                    $number = (int)$matches[2];
                    $pad_len = strlen($matches[2]);
                    $num = $prefix . str_pad($number + 1, $pad_len, '0', STR_PAD_LEFT);
                } else {
                    $num = $dernier_num . '-1';
                }
            }
        }

        // Insert new vente avec le numéro mis à jour si nécessaire
        $stmt = $pdo->prepare("INSERT INTO ventes (num, date, id_clients, versement) VALUES (?, ?, ?, ?)");
        $stmt->execute([$num, $date_vente_clean, $id_clients, $versement]);
        $vente_id = (int)$pdo->lastInsertId();
    }

    // Insert new lines + update stock (SUBTRACT for sale)
    $stmtIns = $pdo->prepare("INSERT INTO vente_details (id_vente, id_produit, prix_vente, qte) VALUES (?, ?, ?, ?)");
    $stmtStk = $pdo->prepare("UPDATE produits SET qte = qte - ? WHERE id = ?");
    foreach ($valid_lines as $line) {
        $stmtIns->execute([$vente_id, $line['pid'], $line['prix'], $line['qte']]);
        $stmtStk->execute([$line['qte'], $line['pid']]);
    }

    $pdo->commit();
    $msg = $edit_id ? 'Vente modifiée avec succès !' : 'Vente enregistrée avec succès !';
    header('Location: bonVente.php?success=' . urlencode($msg)); exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $err = urlencode('Erreur base de données : ' . $e->getMessage());
    header('Location: bonVente.php' . ($edit_id ? '?id=' . $edit_id . '&error=' : '?error=') . $err); exit;
}

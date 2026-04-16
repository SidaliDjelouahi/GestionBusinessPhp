<?php
// ============================================================
//  G-Business — logout.php
//  Déconnexion sécurisée de l'utilisateur
// ============================================================

// 1. Initialiser la session pour pouvoir y accéder
session_start();

// 2. Vider toutes les variables de session
$_SESSION = array();

// 3. Si vous utilisez des cookies de session, il faut les supprimer
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Détruire la session côté serveur
session_destroy();

// 5. Rediriger l'utilisateur vers la page de login
header("Location: login.php");
exit;
?>
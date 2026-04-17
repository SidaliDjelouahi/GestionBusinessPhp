<?php
// ============================================================
//  G-Business — db_connect.php
//  Centralized Database Connection Logic
// ============================================================

// === HOSTINGER (PRODUCTION) ===
define('DB_HOST_PROD',   'localhost');
define('DB_NAME_PROD',   'u174726466_g_business');
define('DB_USER_PROD',   'u174726466_g_business');
define('DB_PASS_PROD',   'Business@2027');

// === HOSTINGER (DEVELOPMENT / TEST) ===
define('DB_HOST_DEV',    'localhost');
define('DB_NAME_DEV',    'u174726466_g_bus_dev');
define('DB_USER_DEV',    'u174726466_g_bus_dev');
define('DB_PASS_DEV',    'BusDev@2027');

// === WAMP LOCALHOST (FALLBACK) ===
define('DB_HOST_LOCAL',  'localhost');
define('DB_NAME_LOCAL',  'gestion_business');
define('DB_USER_LOCAL',  'root');
define('DB_PASS_LOCAL',  '');

function makeConnection(string $host, string $db, string $user, string $pass): PDO
{
    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 3, // Réduit pour basculer plus vite si échec
    ];
    return new PDO($dsn, $user, $pass, $options);
}

function getDBConnection(): ?PDO
{
    // 1. Détection de l'environnement par l'URL
    $isDevServer = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'dev') !== false);

    try {
        if ($isDevServer) {
            // --- Tentative connexion Base DEV ---
            $pdo = makeConnection(DB_HOST_DEV, DB_NAME_DEV, DB_USER_DEV, DB_PASS_DEV);
            if (!defined('DB_ENVIRONMENT')) define('DB_ENVIRONMENT', 'hostinger_dev');
            return $pdo;
        } else {
            // --- Tentative connexion Base PROD ---
            $pdo = makeConnection(DB_HOST_PROD, DB_NAME_PROD, DB_USER_PROD, DB_PASS_PROD);
            if (!defined('DB_ENVIRONMENT')) define('DB_ENVIRONMENT', 'hostinger_prod');
            return $pdo;
        }
    } catch (PDOException $e) {
        // En cas d'échec sur Hostinger, on tente le fallback Local (WAMP)
        try {
            $pdo = makeConnection(DB_HOST_LOCAL, DB_NAME_LOCAL, DB_USER_LOCAL, DB_PASS_LOCAL);
            if (!defined('DB_ENVIRONMENT')) define('DB_ENVIRONMENT', 'localhost');
            return $pdo;
        } catch (PDOException $e2) {
            return null;
        }
    }
}
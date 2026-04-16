<?php
// ============================================================
//  G-Business — db_connect.php
//  Centralized Database Connection Logic
// ============================================================

// === HOSTINGER (PRIMARY) ===
define('DB_HOST_PRIMARY',   'srv1055.hstgr.io');
define('DB_NAME_PRIMARY',   'u174726466_g_business');
define('DB_USER_PRIMARY',   'u174726466_g_business');
define('DB_PASS_PRIMARY',   'Business@2027');

// === WAMP LOCALHOST (FALLBACK) ===
define('DB_HOST_FALLBACK',  'localhost');
define('DB_NAME_FALLBACK',  'gestion_business');
define('DB_USER_FALLBACK',  'root');
define('DB_PASS_FALLBACK',  '');

function makeConnection(string $host, string $db, string $user, string $pass): PDO
{
    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ];
    return new PDO($dsn, $user, $pass, $options);
}

function getDBConnection(): ?PDO
{
    // --- Try Hostinger first ---
    try {
        $pdo = makeConnection(
            DB_HOST_PRIMARY,
            DB_NAME_PRIMARY,
            DB_USER_PRIMARY,
            DB_PASS_PRIMARY
        );
        if (!defined('DB_ACTIVE')) {
            define('DB_ACTIVE', 'hostinger');
        }
        return $pdo;
    } catch (PDOException $e) {
        // Hostinger unreachable, try fallback
    }

    // --- Fallback: WAMP localhost ---
    try {
        $pdo = makeConnection(
            DB_HOST_FALLBACK,
            DB_NAME_FALLBACK,
            DB_USER_FALLBACK,
            DB_PASS_FALLBACK
        );
        if (!defined('DB_ACTIVE')) {
            define('DB_ACTIVE', 'localhost');
        }
        return $pdo;
    } catch (PDOException $e) {
        // Both failed
        return null;
    }
}

<?php
// ============================================================
// config/database.php
// Wildlife Sentinel — Database connection & core helpers
// ------------------------------------------------------------
// Default: XAMPP / MariaDB on localhost.
// Hosted PostgreSQL is selected by DATABASE_URL (Render/Neon).
//
// This file ONLY contains:
//   1. DB credentials
//   2. getDB()   — PDO singleton
//   3. jsonResponse() — JSON output helper (guarded)
//   4. Auto-includes includes/functions.php
//
// Environment variables (optional, for hosted deployments):
//   DATABASE_URL, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, DB_TLS
//   WS_URL, AI_SERVICE_URL
// ------------------------------------------------------------
// TLS:
//   Place the CA certificate at: config/ca.pem
//   Set env var DB_TLS=1 to enable.
// ============================================================

// ------------------------------------------------------------
// ENVIRONMENT DETECTION
// ------------------------------------------------------------
$databaseUrl = getenv('DATABASE_URL');
$usePostgres = is_string($databaseUrl) && trim($databaseUrl) !== '';
$envHost = getenv('DB_HOST');
$isHosted = !$usePostgres && (is_string($envHost) && $envHost !== '' && $envHost !== 'localhost' && $envHost !== '127.0.0.1');

// ------------------------------------------------------------
// CREDENTIALS
// ------------------------------------------------------------
if ($isHosted) {
    // ---- Hosted deployment ----
    if (!defined('DB_HOST'))    define('DB_HOST',    $envHost);
    if (!defined('DB_PORT'))    define('DB_PORT',    getenv('DB_PORT') ?: '3306');
    if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME') ?: 'wildlife_sentinel');
    if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER') ?: '');
    if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASSWORD') ?: '');
    if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
    // Enable TLS only if explicitly requested OR if the host looks like TiDB Cloud
    $tlsDefault = (stripos($envHost, 'tidbcloud.com') !== false);
    if (!defined('DB_USE_TLS')) {
        $tlsEnv = getenv('DB_TLS');
        define('DB_USE_TLS', $tlsEnv !== false ? ($tlsEnv === '1') : $tlsDefault);
    }
} else {
    // ---- Local XAMPP / MAMP / WAMP / Laragon ----
    if (!defined('DB_HOST'))    define('DB_HOST',    '127.0.0.1');
    if (!defined('DB_PORT'))    define('DB_PORT',    '3306');
    if (!defined('DB_NAME'))    define('DB_NAME',    'wildlife_sentinel');
    if (!defined('DB_USER'))    define('DB_USER',    'root');
    if (!defined('DB_PASS'))    define('DB_PASS',    '');       // XAMPP default
    if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
    if (!defined('DB_USE_TLS')) define('DB_USE_TLS', false);
}

if (!defined('DB_DRIVER')) {
    define('DB_DRIVER', $usePostgres ? 'pgsql' : 'mysql');
}
if (!defined('WS_PGSQL')) {
    define('WS_PGSQL', DB_DRIVER === 'pgsql');
}

// ------------------------------------------------------------
// EXTERNAL SERVICES
// ------------------------------------------------------------
if (!defined('WS_URL')) {
    define('WS_URL', getenv('WS_URL') ?: 'http://localhost:3001');
}
if (!defined('AI_SERVICE_URL')) {
    define('AI_SERVICE_URL', getenv('AI_SERVICE_URL') ?: 'http://localhost:5000');
}

// ------------------------------------------------------------
// SESSION
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // Conservative cookie flags — works on plain HTTP XAMPP
    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );
    @session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ------------------------------------------------------------
// getDB() — PDO singleton
// ------------------------------------------------------------
if (!function_exists('getDB')) {
    function getDB(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        try {
            $url = getenv('DATABASE_URL');
            if (WS_PGSQL && is_string($url) && trim($url) !== '') {
                $parts = parse_url(trim($url));
                if ($parts === false || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)
                    || empty($parts['host']) || empty($parts['path']) || empty($parts['user'])) {
                    throw new RuntimeException('DATABASE_URL is not a valid PostgreSQL URL.');
                }
                $host = $parts['host'];
                $port = isset($parts['port']) ? (int)$parts['port'] : 5432;
                $name = rawurldecode(ltrim($parts['path'], '/'));
                $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
                $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
                parse_str($parts['query'] ?? '', $query);
                $sslmode = (string)($query['sslmode'] ?? 'require');
                $localPostgres = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
                if (!in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true) && !($localPostgres && $sslmode === 'disable')) {
                    throw new RuntimeException('Hosted PostgreSQL requires TLS.');
                }
                foreach ([$host, $name] as $value) {
                    if ($value === '' || strpbrk($value, ";\r\n\0") !== false) {
                        throw new RuntimeException('Invalid PostgreSQL connection setting.');
                    }
                }
                // PDO does not expose channel_binding in its DSN; libpq reads this setting.
                $channelBinding = $query['channel_binding'] ?? 'prefer';
                if (!in_array($channelBinding, ['disable', 'prefer', 'require'], true)) {
                    throw new RuntimeException('Invalid PostgreSQL channel binding setting.');
                }
                putenv('PGCHANNELBINDING=' . $channelBinding);
                $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';sslmode=' . $sslmode . ';connect_timeout=15';
            } else {
                $user = DB_USER;
                $pass = DB_PASS;
                $dsn = 'mysql:host=' . DB_HOST
                     . ';port='     . DB_PORT
                     . ';dbname='   . DB_NAME
                     . ';charset='  . DB_CHARSET;
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 15,
            ];

            // Some builds of MariaDB on Windows reject MYSQL_ATTR_INIT_COMMAND.
            // Only add it if the constant exists.
            if (!WS_PGSQL && defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET;
            }

            // ---------- TLS (only for hosted / TiDB) ----------
            if (!WS_PGSQL && DB_USE_TLS) {
                $caPath = __DIR__ . '/ca.pem';
                if (is_file($caPath)) {
                    if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
                    }
                    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                    }
                } else {
                    // TLS requested but CA missing — disable CA verify so connection
                    // still works. Log a warning so admins know to add ca.pem.
                    error_log('[WS-DB] TLS enabled but config/ca.pem is missing');
                    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                    }
                }
            }

            if (WS_PGSQL) {
                require_once __DIR__ . '/PostgresPDO.php';
                $pdo = new WildlifePostgresPDO($dsn, $user, $pass, $options);
            } else {
                $pdo = new PDO($dsn, $user, $pass, $options);
            }
        } catch (Throwable $e) {
            // Do not log URLs, passwords, or driver messages that may include credentials.
            error_log('[WS-DB] Database connection failed (' . DB_DRIVER . ', code ' . $e->getCode() . ').');

            // If headers are already sent, don't try to emit JSON.
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success' => false,
                'error'   => 'Database connection failed. Please try again later.',
            ]);
            exit(PHP_SAPI === 'cli' ? 1 : 0);
        }

        return $pdo;
    }
}

if (!function_exists('ws_is_postgres')) {
    function ws_is_postgres(): bool {
        return defined('WS_PGSQL') && WS_PGSQL;
    }
}

// ------------------------------------------------------------
// jsonResponse() — universal JSON output helper (guarded)
// ------------------------------------------------------------
if (!function_exists('jsonResponse')) {
    function jsonResponse($data, $status = 200) {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data);
        exit();
    }
}

// ------------------------------------------------------------
// Load shared functions (auth, notifications, etc.)
// ------------------------------------------------------------
require_once __DIR__ . '/../includes/functions.php';

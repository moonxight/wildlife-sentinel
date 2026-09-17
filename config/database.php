<?php
// PostgreSQL connection for Neon. DATABASE_URL is required.
define("DB_DRIVER", "pgsql");

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
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Secure cookies on hosted HTTPS; allow local HTTP during development.
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
            if (!is_string($url) || trim($url) === '') {
                throw new RuntimeException('DATABASE_URL is required.');
            }
            {
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
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 15,
            ];

            $pdo = new PDO($dsn, $user, $pass, $options);
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

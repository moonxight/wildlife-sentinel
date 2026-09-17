<?php
// ============================================================
// login.php
// Wildlife Sentinel — Login + First-Time Admin Setup
// ------------------------------------------------------------
// DEBUG MODE:
//   Set WS_DEBUG=1 in the environment, or override below.
//   Default: OFF (safe for production).
//
// MAINTENANCE MODE:
//   When admin/settings.php turns on maintenance_mode,
//   only admins may log in. Non-admins are blocked at the
//   door with the maintenance message shown.
//
// FIRST TIME SETUP:
//   If no admin exists, the page shows a registration form
//   above the login form. Only one admin can ever be created.
// ============================================================

// ------------------------------------------------------------
// DEBUG FLAG — controlled by env or hardcoded fallback
// ------------------------------------------------------------
$wsDebugEnv = getenv('WS_DEBUG');
$DEBUG_MODE = ($wsDebugEnv === '1');
// If you want to force debug locally, uncomment the next line:
// $DEBUG_MODE = true;

if ($DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// ------------------------------------------------------------
// Boot
// ------------------------------------------------------------
require_once __DIR__ . '/config/database.php';      // ensures session + PDO
require_once __DIR__ . '/includes/functions.php';   // auth + helpers

// ------------------------------------------------------------
// LOGO
// ------------------------------------------------------------
$logoUrl = 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSNUo8sFW1IUxMnFDN_ZE2dSEegfrRcFqyzgZfg1L2I1g&s';

// ------------------------------------------------------------
// CSRF TOKEN for this page load
// ------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ------------------------------------------------------------
// MAINTENANCE MODE
// ------------------------------------------------------------
$maintenanceMode    = false;
$maintenanceMessage = 'System under maintenance. Please check back soon.';
try {
    $pdoTmp  = getDB();
    $stmtTmp = $pdoTmp->prepare("
        SELECT setting_key, setting_value
        FROM settings
        WHERE setting_key IN ('maintenance_mode','maintenance_message')
    ");
    $stmtTmp->execute();
    foreach ($stmtTmp->fetchAll() as $row) {
        if ($row['setting_key'] === 'maintenance_mode') {
            $maintenanceMode = ((string)$row['setting_value'] === '1');
        }
        if ($row['setting_key'] === 'maintenance_message' && !empty($row['setting_value'])) {
            $maintenanceMessage = (string)$row['setting_value'];
        }
    }
} catch (Throwable $e) {
    // settings table may not exist yet — ignore
}

// ------------------------------------------------------------
// REDIRECT IF ALREADY LOGGED IN
// ------------------------------------------------------------
if (isLoggedIn()) {
    $user = getCurrentUser();

    if ($maintenanceMode && $user && $user['role'] !== 'admin') {
        // Non-admins don't belong here during maintenance
        try { $pdo = getDB(); $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?")->execute([$user['id']]); } catch (Throwable $e) {}
        session_unset();
        session_destroy();
        header('Location: maintenance.php');
        exit();
    }

    if ($user) {
        $dashboardMap = [
            'admin'            => 'admin/dashboard.php',
            'zone_supervisor'  => 'supervisor/dashboard.php',
            'ranger'           => 'ranger/dashboard.php',
            'scout'            => 'scout/dashboard.php',
            'tourism'          => 'tourism/dashboard.php',
        ];
        header('Location: ' . ($dashboardMap[$user['role']] ?? 'admin/dashboard.php'));
        exit();
    }
}

$error      = '';
$success    = '';
$debugInfo  = [];

$pdo = getDB();

// ------------------------------------------------------------
// Check whether an admin exists
// ------------------------------------------------------------
$adminExists = false;
try {
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
    $adminExists = ((int)($stmt->fetch()['c'] ?? 0)) > 0;
} catch (PDOException $e) {
    error_log('[WS-LOGIN] users table check: ' . $e->getMessage());
    $error = 'Database is not set up yet. Please import the schema, then reload.';
    if ($DEBUG_MODE) $debugInfo[] = $e->getMessage();
}

// ------------------------------------------------------------
// Helper: verify CSRF
// ------------------------------------------------------------
function ws_check_csrf(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf']);
}

// ------------------------------------------------------------
// Helper: get client IP (handles proxies)
// ------------------------------------------------------------
function ws_client_ip(): string {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string)$_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}

// ------------------------------------------------------------
// FIRST-TIME ADMIN REGISTRATION
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['register_admin'])
    && !$adminExists
) {
    if (!ws_check_csrf()) {
        $error = 'Session expired. Please reload the page and try again.';
    } else {
        $fullName        = trim((string)($_POST['full_name'] ?? ''));
        $email           = trim((string)($_POST['email'] ?? ''));
        $phone           = trim((string)($_POST['phone'] ?? ''));
        $password        = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        $errors = [];

        // ---- Name ----
        if ($fullName === '') {
            $errors[] = 'Full name is required';
        } elseif (mb_strlen($fullName) < 2) {
            $errors[] = 'Full name must be at least 2 characters';
        } elseif (mb_strlen($fullName) > 100) {
            $errors[] = 'Full name must not exceed 100 characters';
        } elseif (!preg_match("/^[\p{L}\s'\-\.]+$/u", $fullName)) {
            $errors[] = 'Full name contains invalid characters';
        }

        // ---- Email ----
        if ($email === '') {
            $errors[] = 'Email is required';
        } elseif (!validateEmail($email)) {
            $errors[] = 'Please enter a valid email address';
        } elseif (mb_strlen($email) > 150) {
            $errors[] = 'Email address is too long';
        }

        // ---- Password ----
        if ($password === '') {
            $errors[] = 'Password is required';
        } elseif ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match';
        } else {
            foreach (validatePasswordStrength($password) as $pwErr) {
                $errors[] = $pwErr;
            }
        }

        // ---- Phone (optional) ----
        if ($phone !== '') {
            $normalized = preg_replace('/[^0-9]/', '', $phone);
            if (strpos($normalized, '260') === 0) $normalized = substr($normalized, 3);
            if (!preg_match('/^(09|07)[0-9]{8}$/', $normalized)) {
                $errors[] = 'Invalid phone format. Use 0971234567 or 0771234567, or leave blank.';
            } else {
                $phone = $normalized;
            }
        }

        if ($DEBUG_MODE) {
            $debugInfo[] = "Register: name='{$fullName}', email='{$email}', phone='{$phone}', pwd_len=" . strlen($password);
            $debugInfo[] = 'Errors: ' . (empty($errors) ? 'none' : implode(' | ', $errors));
        }

        if (!empty($errors)) {
            $error = implode('<br>', array_map('htmlspecialchars', $errors));
        } else {
            // Re-check inside a transaction to avoid concurrent duplicate registration
            try {
                $pdo->beginTransaction();

                $pdo->exec('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                $check = $pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'");
                if ((int)($check->fetch()['c'] ?? 0) > 0) {
                    $pdo->rollBack();
                    $error = 'An admin account already exists. Only one admin can be registered.';
                    $adminExists = true;
                } else {
                    // Duplicate email?
                    $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $dup->execute([$email]);
                    if ($dup->fetch()) {
                        $pdo->rollBack();
                        $error = 'This email is already registered. Use a different email.';
                    } else {
                        // Zone (first active zone, or create HQ)
                        $zoneId = null;
                        $zr = $pdo->query("SELECT id FROM zones WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
                        if ($zr) {
                            $zoneId = (int)$zr['id'];
                        } else {
                            $pdo->exec("
                                INSERT INTO zones (name, description, center_lat, center_lng, is_active)
                                VALUES ('Headquarters','Main Administrative Zone - Lusaka, Zambia',-15.3875,28.3228,1)
                            ");
                            $zoneId = (int)$pdo->query('SELECT lastval()')->fetchColumn();
                        }

                        $hash = hashPassword($password);
                        $stmt = $pdo->prepare("
                            INSERT INTO users
                                (email, phone, password_hash, full_name, role, zone_id, is_active, created_at)
                            VALUES (?, ?, ?, ?, 'admin', ?, 1, NOW())
                        ");
                        $stmt->execute([
                            $email,
                            $phone !== '' ? $phone : null,
                            $hash,
                            $fullName,
                            $zoneId,
                        ]);
                        $newId = (int)$pdo->query('SELECT lastval()')->fetchColumn();

                        // Seed per-admin AI settings row
                        try {
                            $pdo->prepare('INSERT INTO admin_ai_settings (admin_id) VALUES (?) ON CONFLICT DO NOTHING')->execute([$newId]);
                        } catch (Throwable $e) { /* optional */ }

                        // Seed per-user preferences
                        try {
                            $pdo->prepare('INSERT INTO user_preferences (user_id) VALUES (?) ON CONFLICT DO NOTHING')->execute([$newId]);
                        } catch (Throwable $e) { /* optional */ }

                        $pdo->commit();

                        try { logAudit($newId, 'create_admin', ['email' => $email, 'zone_id' => $zoneId]); }
                        catch (Throwable $e) { if ($DEBUG_MODE) $debugInfo[] = 'Audit log failed: ' . $e->getMessage(); }

                        $success = '✅ Admin account created. Please sign in below.';
                        $adminExists = true;

                        // Rotate CSRF after successful registration
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $csrf = $_SESSION['csrf_token'];
                    }
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[WS-LOGIN] register_admin: ' . $e->getMessage());
                $error = 'Database error while creating admin. Please try again.';
                if ($DEBUG_MODE) $debugInfo[] = $e->getMessage();
            }
        }
    }
}

// ------------------------------------------------------------
// LOGIN
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!ws_check_csrf()) {
        $error = 'Session expired. Please reload the page and try again.';
    } else {
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $ip       = ws_client_ip();

        // Rate limit key is IP-first so rotating emails doesn't help
        $rlKeys = [
            'login_ip_' . $ip,
            'login_email_' . strtolower($email),
        ];
        $rateLimited = false;
        foreach ($rlKeys as $k) {
            if (!checkRateLimit($k, 5, 300)) { $rateLimited = true; break; }
        }

        if ($rateLimited) {
            $error = 'Too many login attempts. Please wait 5 minutes and try again.';
        } elseif ($email === '' || $password === '') {
            $error = 'Please fill in all fields';
        } elseif (!validateEmail($email)) {
            $error = 'Please enter a valid email address';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && verifyPassword($password, $user['password_hash'])) {

                    if ($maintenanceMode && $user['role'] !== 'admin') {
                        // Block non-admin during maintenance
                        $error = '🚧 ' . htmlspecialchars($maintenanceMessage);
                        try { logAudit($user['id'], 'login_blocked_maintenance', ['email' => $email]); } catch (Throwable $e) {}
                    } else {
                        // ---- Successful login ----
                        session_regenerate_id(true);
                        $_SESSION['user_id']       = (int)$user['id'];
                        $_SESSION['user_name']     = $user['full_name'];
                        $_SESSION['user_role']     = $user['role'];
                        $_SESSION['user_zone']     = (int)$user['zone_id'];
                        $_SESSION['last_activity'] = time();

                        // Rotate CSRF after login
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        try {
                            $pdo->prepare("UPDATE users SET last_online = NOW(), is_online = 1 WHERE id = ?")
                                ->execute([$user['id']]);
                        } catch (Throwable $e) { /* non-fatal */ }

                        try {
                            logAudit($user['id'], 'login', [
                                'email'            => $email,
                                'ip'               => $ip,
                                'maintenance_mode' => $maintenanceMode ? 1 : 0,
                            ]);
                        } catch (Throwable $e) { /* non-fatal */ }

                        $dashboardMap = [
                            'admin'            => 'admin/dashboard.php',
                            'zone_supervisor'  => 'supervisor/dashboard.php',
                            'ranger'           => 'ranger/dashboard.php',
                            'scout'            => 'scout/dashboard.php',
                            'tourism'          => 'tourism/dashboard.php',
                        ];
                        header('Location: ' . ($dashboardMap[$user['role']] ?? 'admin/dashboard.php'));
                        exit();
                    }
                } else {
                    // Failed login — log but do not reveal which part was wrong
                    $error = 'Invalid email or password';
                    try { logAudit(0, 'login_failed', ['email' => $email, 'ip' => $ip]); } catch (Throwable $e) {}
                }
            } catch (PDOException $e) {
                error_log('[WS-LOGIN] login: ' . $e->getMessage());
                $error = 'Login error. Please try again.';
                if ($DEBUG_MODE) $debugInfo[] = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0d3b22">
    <title>Login - Wildlife Sentinel</title>
    <link rel="icon" href="<?= htmlspecialchars($logoUrl, ENT_QUOTES) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;800&family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ... same styles as before (unchanged) ... */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh; min-height: 100dvh;
            background: linear-gradient(135deg, #0a1a0f 0%, #1a5c3a 50%, #0d3b22 100%);
            display: flex; align-items: center; justify-content: center;
            padding: 16px; position: relative; overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute; inset: 0;
            background:
                radial-gradient(circle at 20% 50%, rgba(74,222,128,0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(74,222,128,0.05) 0%, transparent 50%);
            z-index: 0;
        }

        .animals-bg { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
        .animal { position: absolute; font-size: 40px; animation: floatAnimal linear infinite; opacity: 0.12; }
        .animal:nth-child(1)  { top: 5%;  left: 5%;    font-size: 50px; animation-duration: 25s; }
        .animal:nth-child(2)  { top: 15%; right: 10%;  font-size: 35px; animation-duration: 20s; animation-delay: 2s; }
        .animal:nth-child(3)  { bottom: 20%; left: 8%; font-size: 45px; animation-duration: 28s; animation-delay: 4s; }
        .animal:nth-child(4)  { bottom: 30%; right: 5%;font-size: 30px; animation-duration: 22s; animation-delay: 1s; }
        .animal:nth-child(5)  { top: 50%; left: 15%;   font-size: 25px; animation-duration: 18s; animation-delay: 3s; }
        .animal:nth-child(6)  { top: 60%; right: 15%;  font-size: 35px; animation-duration: 26s; animation-delay: 5s; }
        .animal:nth-child(7)  { top: 30%; left: 50%;   font-size: 28px; animation-duration: 30s; animation-delay: 2s; }
        .animal:nth-child(8)  { bottom: 10%; left: 50%;font-size: 32px; animation-duration: 24s; animation-delay: 4s; }
        .animal:nth-child(9)  { top: 10%; left: 30%;   font-size: 20px; animation-duration: 20s; animation-delay: 6s; }
        .animal:nth-child(10) { bottom: 40%; right: 30%; font-size: 22px; animation-duration: 28s; animation-delay: 3s; }
        .animal:nth-child(11) { top: 45%; left: 75%;   font-size: 18px; animation-duration: 22s; animation-delay: 1s; }
        .animal:nth-child(12) { bottom: 55%; left: 65%; font-size: 26px; animation-duration: 30s; animation-delay: 5s; }

        @keyframes floatAnimal {
            0%   { transform: translate(0,0) rotate(0deg); opacity: 0.1; }
            10%  { opacity: 0.2; }
            25%  { transform: translate(100px,-50px) rotate(10deg); opacity: 0.15; }
            50%  { transform: translate(200px,30px) rotate(-5deg); opacity: 0.2; }
            75%  { transform: translate(100px,50px) rotate(8deg); opacity: 0.15; }
            90%  { opacity: 0.1; }
            100% { transform: translate(0,0) rotate(0deg); opacity: 0.1; }
        }

        .particles { position: absolute; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
        .particle { position: absolute; width: 4px; height: 4px; background: rgba(74,222,128,0.12); border-radius: 50%; animation: floatParticle linear infinite; }
        @keyframes floatParticle {
            0%   { transform: translateY(100vh) rotate(0deg); opacity: 0; }
            10%  { opacity: 1; }
            90%  { opacity: 1; }
            100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; }
        }

        .login-wrapper { position: relative; z-index: 1; width: 100%; max-width: 440px; }
        .login-box {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px 28px;
            border: 1px solid rgba(255,255,255,0.08);
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            animation: slideUp 0.6s ease;
            max-height: 90vh; max-height: 90dvh;
            overflow-y: auto;
        }
        .login-box::-webkit-scrollbar { width: 3px; }
        .login-box::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
        .login-box::before {
            content: '';
            position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px;
            background: linear-gradient(45deg, #4ade80, #22d3ee, #4ade80, #22d3ee);
            background-size: 400% 400%;
            border-radius: 26px; z-index: -1;
            animation: gradientBorder 6s ease infinite;
            opacity: 0.25;
        }
        @keyframes gradientBorder {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .login-header { text-align: center; margin-bottom: 24px; }
        .login-header .logo-icon {
            width: 72px; height: 72px;
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 12px; border-radius: 20px;
            background: linear-gradient(135deg, rgba(74,222,128,0.15), rgba(34,211,238,0.1));
            border: 2px solid rgba(74,222,128,0.25);
            box-shadow: 0 8px 30px rgba(74,222,128,0.15);
            overflow: hidden; animation: pulseLogo 3s ease-in-out infinite;
        }
        .login-header .logo-icon img { width: 100%; height: 100%; object-fit: contain; padding: 8px; display: block; }
        @keyframes pulseLogo { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
        .login-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 24px; font-weight: 800;
            color: white; letter-spacing: -0.5px;
        }
        .login-header h1 .highlight {
            background: linear-gradient(135deg, #4ade80, #22d3ee);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .login-header p { color: rgba(255,255,255,0.5); font-size: 13px; margin-top: 4px; }
        .login-header .subtitle {
            display: inline-block; padding: 4px 16px;
            border-radius: 20px; font-size: 10px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1px;
            background: rgba(74,222,128,0.15); color: #4ade80;
            margin-top: 8px;
        }

        .maint-notice {
            background: rgba(255,193,7,0.12);
            border: 1px solid rgba(255,193,7,0.35);
            color: #ffe082;
            border-radius: 12px; padding: 14px 16px;
            font-size: 13px; line-height: 1.55;
            margin-bottom: 16px; display: flex; gap: 10px; align-items: flex-start;
        }
        .maint-notice .mn-icon { font-size: 22px; flex-shrink: 0; line-height: 1; }
        .maint-notice strong { color: #ffd54f; display: block; margin-bottom: 2px; }
        .maint-notice .mn-msg { color: #ffe082; font-size: 12.5px; }

        .alert { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; display: flex; align-items: center; gap: 8px; line-height: 1.5; }
        .alert-danger  { background: rgba(220,53,69,0.2);  border: 1px solid rgba(220,53,69,0.3);  color: #f8d7da; display: block; }
        .alert-success { background: rgba(40,167,69,0.2);  border: 1px solid rgba(40,167,69,0.3);  color: #d4edda; }
        .alert-info    { background: rgba(2,136,209,0.15); border: 1px solid rgba(2,136,209,0.3);  color: #b3e5fc; font-size: 12px; }
        .alert-warning { background: rgba(255,193,7,0.18); border: 1px solid rgba(255,193,7,0.35); color: #ffe082; }

        .debug-box {
            background: rgba(0,0,0,0.4);
            border: 1px solid rgba(255,193,7,0.4);
            border-radius: 10px; padding: 12px 14px;
            margin-bottom: 14px;
            font-family: 'Courier New', monospace;
            font-size: 11px; color: #ffd54f;
            max-height: 200px; overflow-y: auto;
            white-space: pre-wrap; word-break: break-word;
        }
        .debug-box strong { color: #ffeb3b; display: block; margin-bottom: 6px; }

        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: rgba(255,255,255,0.7); }
        .form-group .input-wrapper { position: relative; }
        .form-group .input-wrapper .input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 16px; opacity: 0.5; pointer-events: none; }

        .form-control {
            width: 100%; padding: 10px 44px 10px 40px;
            border: 2px solid rgba(255,255,255,0.1);
            border-radius: 10px; font-size: 15px;
            transition: all 0.3s;
            background: rgba(255,255,255,0.06);
            color: white; font-family: inherit;
            min-height: 44px;
        }
        .form-control::placeholder { color: rgba(255,255,255,0.3); }
        .form-control:focus {
            border-color: #4ade80; outline: none;
            box-shadow: 0 0 0 4px rgba(74,222,128,0.1);
            background: rgba(255,255,255,0.08);
        }
        .form-control.is-valid   { border-color: #4ade80; background: rgba(74,222,128,0.06); }
        .form-control.is-invalid { border-color: #f87171; background: rgba(248,113,113,0.06); }
        .form-control.is-invalid:focus { border-color: #f87171; box-shadow: 0 0 0 4px rgba(248,113,113,0.15); }

        .field-status { position: absolute; right: 44px; top: 50%; transform: translateY(-50%); font-size: 15px; pointer-events: none; opacity: 0; transition: opacity 0.2s; }
        .field-status.show { opacity: 1; }
        .field-status.valid   { color: #4ade80; }
        .field-status.invalid { color: #f87171; }

        .field-error { display: none; font-size: 11px; color: #fca5a5; margin-top: 4px; padding-left: 2px; line-height: 1.4; }
        .field-error.show { display: block; }

        .pw-strength { display: none; margin-top: 6px; }
        .pw-strength.show { display: block; }
        .pw-strength-bar { height: 5px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden; margin-bottom: 4px; }
        .pw-strength-fill { height: 100%; width: 0%; border-radius: 3px; transition: width 0.3s ease, background-color 0.3s ease; background: #f87171; }
        .pw-strength-text { font-size: 10.5px; color: rgba(255,255,255,0.5); font-weight: 500; }

        .pw-requirements { list-style: none; margin: 6px 0 0 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 3px 10px; }
        .pw-requirements li { font-size: 10.5px; color: rgba(255,255,255,0.35); display: flex; align-items: center; gap: 5px; transition: color 0.2s; }
        .pw-requirements li::before { content: '○'; font-size: 10px; color: rgba(255,255,255,0.3); transition: all 0.2s; }
        .pw-requirements li.met { color: #4ade80; }
        .pw-requirements li.met::before { content: '✓'; color: #4ade80; font-weight: bold; }

        .toggle-password {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: rgba(255,255,255,0.5);
            font-size: 17px; cursor: pointer;
            padding: 6px 4px; line-height: 1;
            transition: all 0.2s;
            display: flex; align-items: center; justify-content: center;
            min-width: 30px; min-height: 30px;
            border-radius: 6px;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .toggle-password:hover  { color: #4ade80; background: rgba(74,222,128,0.08); }
        .toggle-password:active { transform: translateY(-50%) scale(0.92); }

        .btn {
            width: 100%; padding: 12px;
            border: none; border-radius: 10px;
            font-size: 15px; font-weight: 600;
            cursor: pointer; transition: all 0.3s;
            font-family: 'Inter', sans-serif;
            display: flex; align-items: center; justify-content: center;
            gap: 10px; min-height: 46px;
        }
        .btn-primary { background: linear-gradient(135deg, #1a5c3a, #2d8a4e); color: white; box-shadow: 0 4px 25px rgba(26,92,58,0.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 40px rgba(26,92,58,0.5); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-success { background: linear-gradient(135deg, #28a745, #20c997); color: white; box-shadow: 0 4px 25px rgba(40,167,69,0.3); }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 40px rgba(40,167,69,0.5); }
        .btn-success:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

        .divider { display: flex; align-items: center; text-align: center; margin: 18px 0; color: rgba(255,255,255,0.3); font-size: 12px; }
        .divider::before, .divider::after { content: ''; flex: 1; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .divider::before { margin-right: 12px; }
        .divider::after  { margin-left: 12px; }

        .login-footer { text-align: center; margin-top: 16px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.06); }
        .login-footer a { color: rgba(255,255,255,0.5); text-decoration: none; font-size: 12px; }
        .login-footer a:hover { color: #4ade80; }
        .login-footer p { color: rgba(255,255,255,0.3); font-size: 11px; margin-top: 8px; }

        .password-hint { font-size: 11px; color: rgba(255,255,255,0.4); margin-top: 4px; }
        .password-hint ul { margin: 4px 0 0 16px; padding: 0; }
        .password-hint li { margin: 2px 0; }

        .info-box { background: rgba(74,222,128,0.08); border: 1px solid rgba(74,222,128,0.15); border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; text-align: center; }
        .info-box .info-icon { font-size: 22px; display: block; margin-bottom: 4px; }
        .info-box p { color: rgba(255,255,255,0.7); font-size: 12px; margin: 0; line-height: 1.5; }
        .info-box .highlight-text { color: #4ade80; font-weight: 600; }
        .info-box .warning-text { color: #ffc107; font-size: 11px; display: block; margin-top: 4px; }

        .forgot-password { text-align: right; margin-top: -6px; margin-bottom: 12px; }
        .forgot-password a { color: rgba(255,255,255,0.4); font-size: 11px; text-decoration: none; }
        .forgot-password a:hover { color: #4ade80; text-decoration: underline; }

        .registration-section { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.06); }

        @media (max-width: 480px) {
            .login-box { padding: 24px 20px; border-radius: 16px; }
            .login-header h1 { font-size: 20px; }
            .login-header .logo-icon { width: 60px; height: 60px; }
            .form-control { padding: 9px 40px 9px 38px; font-size: 14px; min-height: 42px; }
            .btn { padding: 11px; font-size: 14px; min-height: 44px; }
            .animal { font-size: 25px !important; opacity: 0.08; }
            .particle { display: none; }
            .toggle-password { font-size: 15px; min-width: 28px; min-height: 28px; }
            .field-status { right: 38px; font-size: 13px; }
            .pw-requirements { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) { input, select, textarea { font-size: 16px !important; } }
    </style>
</head>
<body>
    <div class="animals-bg">
        <span class="animal">🦁</span><span class="animal">🐘</span><span class="animal">🦒</span>
        <span class="animal">🦏</span><span class="animal">🐆</span><span class="animal">🦛</span>
        <span class="animal">🦅</span><span class="animal">🦓</span><span class="animal">🐃</span>
        <span class="animal">🐊</span><span class="animal">🦩</span><span class="animal">🐾</span>
    </div>

    <div class="particles" id="particles"></div>

    <div class="login-wrapper">
        <div class="login-box">
            <div class="login-header">
                <span class="logo-icon">
                    <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES) ?>" alt="Wildlife Sentinel">
                </span>
                <h1>Wildlife <span class="highlight">Sentinel</span></h1>
                <p>Zambia Wildlife Protection System</p>
                <span class="subtitle">
                    <?php if ($maintenanceMode): ?>
                        🚧 Maintenance Mode
                    <?php elseif ($adminExists): ?>
                        🔐 Secure Login
                    <?php else: ?>
                        🔑 First Time Setup
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($maintenanceMode): ?>
                <div class="maint-notice">
                    <span class="mn-icon">🚧</span>
                    <div>
                        <strong>Maintenance Mode is ON</strong>
                        <span class="mn-msg"><?= htmlspecialchars($maintenanceMessage) ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">❌ <?= $error /* already escaped where needed */ ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
                <div class="alert alert-success">✅ You have been logged out successfully.</div>
            <?php endif; ?>

            <?php if (isset($_GET['timeout'])): ?>
                <div class="alert alert-warning">⏱️ Your session expired due to inactivity. Please sign in again.</div>
            <?php endif; ?>

            <?php if ($DEBUG_MODE && !empty($debugInfo)): ?>
                <div class="debug-box">
                    <strong>🐛 DEBUG INFO</strong>
                    <?php foreach ($debugInfo as $line): ?><?= htmlspecialchars($line) ?>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- REGISTRATION (only if no admin) -->
            <?php if (!$adminExists): ?>
            <div class="registration-section">
                <div class="info-box">
                    <span class="info-icon">🔑</span>
                    <p>
                        <span class="highlight-text">First Time Setup</span><br>
                        Create the system administrator account to get started.
                        <span class="warning-text">⚠️ Only one admin account can be created</span>
                    </p>
                </div>

                <form method="POST" autocomplete="off" id="registerForm" novalidate>
                    <input type="hidden" name="register_admin" value="1">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

                    <div class="form-group">
                        <label for="reg-fullname">Full Name *</label>
                        <div class="input-wrapper">
                            <span class="input-icon">👤</span>
                            <input type="text" name="full_name" id="reg-fullname" class="form-control"
                                   placeholder="Enter your full name" required
                                   minlength="2" maxlength="100"
                                   autocomplete="name"
                                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                            <span class="field-status" id="reg-fullname-status"></span>
                        </div>
                        <div class="field-error" id="reg-fullname-error"></div>
                    </div>

                    <div class="form-group">
                        <label for="reg-email">Email Address *</label>
                        <div class="input-wrapper">
                            <span class="input-icon">📧</span>
                            <input type="email" name="email" id="reg-email" class="form-control"
                                   placeholder="Enter your email" required
                                   maxlength="150" autocomplete="email"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            <span class="field-status" id="reg-email-status"></span>
                        </div>
                        <div class="field-error" id="reg-email-error"></div>
                    </div>

                    <div class="form-group">
                        <label for="reg-phone">Phone Number (optional)</label>
                        <div class="input-wrapper">
                            <span class="input-icon">📞</span>
                            <input type="tel" name="phone" id="reg-phone" class="form-control"
                                   placeholder="e.g. 0971234567"
                                   maxlength="15" autocomplete="tel"
                                   inputmode="tel"
                                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                            <span class="field-status" id="reg-phone-status"></span>
                        </div>
                        <div class="field-error" id="reg-phone-error"></div>
                    </div>

                    <div class="form-group">
                        <label for="register-password">Password *</label>
                        <div class="input-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" name="password" id="register-password" class="form-control"
                                   placeholder="Min 8 characters" required minlength="8"
                                   autocomplete="new-password">
                            <span class="field-status" id="register-password-status"></span>
                            <button type="button" class="toggle-password"
                                    data-toggle-pw="register-password"
                                    aria-label="Show password">👁️</button>
                        </div>

                        <div class="pw-strength" id="pw-strength">
                            <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-strength-fill"></div></div>
                            <div class="pw-strength-text" id="pw-strength-text">Enter a password</div>
                        </div>

                        <ul class="pw-requirements" id="pw-requirements">
                            <li data-req="length">At least 8 characters</li>
                            <li data-req="upper">One uppercase letter</li>
                            <li data-req="lower">One lowercase letter</li>
                            <li data-req="number">One number</li>
                        </ul>

                        <div class="field-error" id="register-password-error"></div>
                    </div>

                    <div class="form-group">
                        <label for="register-confirm">Confirm Password *</label>
                        <div class="input-wrapper">
                            <span class="input-icon">✓</span>
                            <input type="password" name="confirm_password" id="register-confirm" class="form-control"
                                   placeholder="Confirm your password" required minlength="8"
                                   autocomplete="new-password">
                            <span class="field-status" id="register-confirm-status"></span>
                            <button type="button" class="toggle-password"
                                    data-toggle-pw="register-confirm"
                                    aria-label="Show password">👁️</button>
                        </div>
                        <div class="field-error" id="register-confirm-error"></div>
                    </div>

                    <div class="password-hint">
                        <strong>Password Requirements:</strong>
                        <ul>
                            <li>✅ Minimum 8 characters</li>
                            <li>✅ At least one uppercase letter</li>
                            <li>✅ At least one lowercase letter</li>
                            <li>✅ At least one number</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-success" id="registerSubmit">🔑 Create Admin Account</button>
                </form>
            </div>

            <div class="divider">or login below</div>
            <?php endif; ?>

            <!-- LOGIN -->
            <form method="POST" autocomplete="off" id="loginForm" novalidate>
                <input type="hidden" name="login" value="1">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

                <div class="form-group">
                    <label for="login-email">Email Address<?= $maintenanceMode ? ' (admins only)' : '' ?></label>
                    <div class="input-wrapper">
                        <span class="input-icon">📧</span>
                        <input type="email" name="email" id="login-email" class="form-control"
                               placeholder="Enter your email" required
                               autocomplete="username">
                        <span class="field-status" id="login-email-status"></span>
                    </div>
                    <div class="field-error" id="login-email-error"></div>
                </div>

                <div class="form-group">
                    <label for="login-password">Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" name="password" id="login-password" class="form-control"
                               placeholder="Enter your password" required
                               autocomplete="current-password">
                        <span class="field-status" id="login-password-status"></span>
                        <button type="button" class="toggle-password"
                                data-toggle-pw="login-password"
                                aria-label="Show password">👁️</button>
                    </div>
                    <div class="field-error" id="login-password-error"></div>
                </div>

                <div class="forgot-password">
                    <a href="forgot-password.php">Forgot Password?</a>
                </div>

                <button type="submit" class="btn btn-primary" id="loginSubmit">
                    <?= $maintenanceMode ? '🔐 Admin Sign In' : '🔐 Sign In' ?>
                </button>
            </form>

            <div class="login-footer">
                <a href="index.php">🏠 Back to Home</a>
                <p>© <?= date('Y') ?> Wildlife Sentinel — Protecting Zambia's Wildlife</p>
            </div>
        </div>
    </div>

    <script>
        // ============================================================
        // PARTICLES + FLOATING ANIMALS
        // ============================================================
        function createParticles() {
            const c = document.getElementById('particles');
            if (!c) return;
            for (let i = 0; i < 25; i++) {
                const p = document.createElement('div');
                p.className = 'particle';
                p.style.left = Math.random() * 100 + '%';
                p.style.width = (Math.random() * 4 + 2) + 'px';
                p.style.height = p.style.width;
                p.style.animationDuration = (Math.random() * 20 + 15) + 's';
                p.style.animationDelay = (Math.random() * 15) + 's';
                p.style.opacity = Math.random() * 0.3 + 0.1;
                c.appendChild(p);
            }
        }
        document.addEventListener('DOMContentLoaded', createParticles);

        document.querySelectorAll('.animal').forEach(a => {
            a.style.animationDuration = (18 + Math.random() * 12) + 's';
            a.style.animationDelay = (Math.random() * 6) + 's';
        });

        document.querySelectorAll('.form-control').forEach(i => {
            i.addEventListener('focus', function () {
                const ic = this.parentElement.querySelector('.input-icon');
                if (ic) ic.style.opacity = '1';
            });
            i.addEventListener('blur', function () {
                const ic = this.parentElement.querySelector('.input-icon');
                if (ic && !this.value) ic.style.opacity = '0.5';
            });
        });

        // ============================================================
        // PASSWORD TOGGLE — event delegation, CSP-friendly
        // ============================================================
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-toggle-pw]');
            if (!btn) return;
            const id = btn.getAttribute('data-toggle-pw');
            const input = document.getElementById(id);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🙈';
                btn.setAttribute('aria-label', 'Hide password');
            } else {
                input.type = 'password';
                btn.textContent = '👁️';
                btn.setAttribute('aria-label', 'Show password');
            }
        });

        // ============================================================
        // VALIDATION HELPERS
        // ============================================================
        const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
        const PHONE_RE = /^(09|07)[0-9]{8}$/;
        const NAME_RE  = /^[\p{L}\s'\-\.]+$/u;

        function setFieldState(inputEl, statusEl, errorEl, state, message) {
            inputEl.classList.remove('is-valid', 'is-invalid');
            if (statusEl) statusEl.classList.remove('show', 'valid', 'invalid');
            if (errorEl) { errorEl.classList.remove('show'); errorEl.textContent = ''; }

            if (state === 'valid') {
                inputEl.classList.add('is-valid');
                inputEl.setAttribute('aria-invalid', 'false');
                if (statusEl) { statusEl.textContent = '✓'; statusEl.classList.add('show', 'valid'); }
            } else if (state === 'invalid') {
                inputEl.classList.add('is-invalid');
                inputEl.setAttribute('aria-invalid', 'true');
                if (statusEl) { statusEl.textContent = '✕'; statusEl.classList.add('show', 'invalid'); }
                if (errorEl && message) { errorEl.textContent = message; errorEl.classList.add('show'); }
            }
        }

        function validateFullName(v) {
            v = (v || '').trim();
            if (!v) return { ok: false, msg: 'Full name is required' };
            if (v.length < 2) return { ok: false, msg: 'Must be at least 2 characters' };
            if (v.length > 100) return { ok: false, msg: 'Must not exceed 100 characters' };
            if (!NAME_RE.test(v)) return { ok: false, msg: 'Only letters, spaces, hyphens and apostrophes allowed' };
            return { ok: true };
        }
        function validateEmail(v) {
            v = (v || '').trim();
            if (!v) return { ok: false, msg: 'Email is required' };
            if (!EMAIL_RE.test(v)) return { ok: false, msg: 'Please enter a valid email address' };
            if (v.length > 150) return { ok: false, msg: 'Email is too long' };
            return { ok: true };
        }
        function validatePhone(v) {
            v = (v || '').trim();
            if (!v) return { ok: true };
            const normalized = v.replace(/[^0-9]/g, '').replace(/^260/, '');
            if (!PHONE_RE.test(normalized)) return { ok: false, msg: 'Use 0971234567 or 0771234567, or leave blank' };
            return { ok: true };
        }
        function getPasswordChecks(v) {
            return {
                length: v.length >= 8,
                upper:  /[A-Z]/.test(v),
                lower:  /[a-z]/.test(v),
                number: /[0-9]/.test(v),
            };
        }
        function validatePassword(v) {
            v = v || '';
            if (!v) return { ok: false, msg: 'Password is required' };
            const c = getPasswordChecks(v);
            const missing = [];
            if (!c.length) missing.push('8+ characters');
            if (!c.upper)  missing.push('an uppercase letter');
            if (!c.lower)  missing.push('a lowercase letter');
            if (!c.number) missing.push('a number');
            if (missing.length) return { ok: false, msg: 'Password needs: ' + missing.join(', ') };
            return { ok: true };
        }
        function validateConfirm(pw, confirm) {
            if (!confirm) return { ok: false, msg: 'Please confirm your password' };
            if (pw !== confirm) return { ok: false, msg: 'Passwords do not match' };
            return { ok: true };
        }
        function updatePasswordStrength(value) {
            const wrap = document.getElementById('pw-strength');
            const fill = document.getElementById('pw-strength-fill');
            const text = document.getElementById('pw-strength-text');
            const reqs = document.getElementById('pw-requirements');
            if (!wrap || !fill || !text) return;
            const v = value || '';
            if (!v) {
                wrap.classList.remove('show');
                if (reqs) reqs.querySelectorAll('li').forEach(li => li.classList.remove('met'));
                return;
            }
            wrap.classList.add('show');
            const c = getPasswordChecks(v);
            if (reqs) {
                reqs.querySelectorAll('li').forEach(li => {
                    const key = li.getAttribute('data-req');
                    li.classList.toggle('met', !!c[key]);
                });
            }
            let score = 0;
            if (c.length) score++;
            if (c.upper)  score++;
            if (c.lower)  score++;
            if (c.number) score++;
            if (v.length >= 12) score = Math.min(4, score + 0.5);
            const pct = (score / 4) * 100;
            fill.style.width = pct + '%';
            let color, label;
            if (score <= 1)      { color = '#f87171'; label = 'Very weak'; }
            else if (score <= 2) { color = '#fb923c'; label = 'Weak'; }
            else if (score <= 3) { color = '#facc15'; label = 'Fair'; }
            else if (score < 4)  { color = '#4ade80'; label = 'Good'; }
            else                 { color = '#22c55e'; label = 'Strong'; }
            fill.style.background = color;
            text.textContent = label;
            text.style.color = color;
        }
        function formatPhoneInput(el) {
            let v = el.value.replace(/[^\d+\s\-()]/g, '');
            if (/^260\d{0,9}$/.test(v)) v = '+' + v;
            el.value = v;
        }

        // ============================================================
        // REGISTER FORM
        // ============================================================
        (function () {
            const form = document.getElementById('registerForm');
            if (!form) return;

            const fullName = document.getElementById('reg-fullname');
            const email    = document.getElementById('reg-email');
            const phone    = document.getElementById('reg-phone');
            const password = document.getElementById('register-password');
            const confirm  = document.getElementById('register-confirm');
            const submit   = document.getElementById('registerSubmit');

            const s = {
                fnStatus:  document.getElementById('reg-fullname-status'),
                fnError:   document.getElementById('reg-fullname-error'),
                emStatus:  document.getElementById('reg-email-status'),
                emError:   document.getElementById('reg-email-error'),
                phStatus:  document.getElementById('reg-phone-status'),
                phError:   document.getElementById('reg-phone-error'),
                pwStatus:  document.getElementById('register-password-status'),
                pwError:   document.getElementById('register-password-error'),
                cfStatus:  document.getElementById('register-confirm-status'),
                cfError:   document.getElementById('register-confirm-error'),
            };

            function runFullName() {
                const r = validateFullName(fullName.value);
                setFieldState(fullName, s.fnStatus, s.fnError, r.ok ? 'valid' : (fullName.value ? 'invalid' : 'neutral'), r.msg);
                return r.ok;
            }
            function runEmail() {
                const r = validateEmail(email.value);
                setFieldState(email, s.emStatus, s.emError, r.ok ? 'valid' : (email.value ? 'invalid' : 'neutral'), r.msg);
                return r.ok;
            }
            function runPhone() {
                const r = validatePhone(phone.value);
                setFieldState(phone, s.phStatus, s.phError, r.ok ? (phone.value ? 'valid' : 'neutral') : 'invalid', r.msg);
                return r.ok;
            }
            function runPassword() {
                updatePasswordStrength(password.value);
                const r = validatePassword(password.value);
                setFieldState(password, s.pwStatus, s.pwError, r.ok ? 'valid' : (password.value ? 'invalid' : 'neutral'), r.msg);
                return r.ok;
            }
            function runConfirm() {
                const r = validateConfirm(password.value, confirm.value);
                setFieldState(confirm, s.cfStatus, s.cfError, r.ok ? 'valid' : (confirm.value ? 'invalid' : 'neutral'), r.msg);
                return r.ok;
            }

            fullName.addEventListener('input', runFullName);
            fullName.addEventListener('blur',  runFullName);
            email.addEventListener('input', runEmail);
            email.addEventListener('blur',  runEmail);
            phone.addEventListener('input', function () { formatPhoneInput(this); runPhone(); });
            phone.addEventListener('blur',  runPhone);
            phone.addEventListener('paste', function () {
                setTimeout(() => { formatPhoneInput(phone); runPhone(); }, 0);
            });
            password.addEventListener('input', function () { runPassword(); if (confirm.value) runConfirm(); });
            password.addEventListener('blur',  runPassword);
            confirm.addEventListener('input', runConfirm);
            confirm.addEventListener('blur',  runConfirm);

            form.addEventListener('submit', function (e) {
                const ok = runFullName() & runEmail() & runPhone() & runPassword() & runConfirm();
                if (!ok) {
                    e.preventDefault();
                    const firstInvalid = form.querySelector('.form-control.is-invalid');
                    if (firstInvalid) { firstInvalid.focus(); firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                    return false;
                }
                submit.disabled = true;
                submit.textContent = '⏳ Creating account...';
            });

            if (fullName.value) runFullName();
            if (email.value)    runEmail();
            if (phone.value)    runPhone();
        })();

        // ============================================================
        // LOGIN FORM
        // ============================================================
        (function () {
            const form = document.getElementById('loginForm');
            if (!form) return;

            const email    = document.getElementById('login-email');
            const password = document.getElementById('login-password');
            const submit   = document.getElementById('loginSubmit');

            const s = {
                emStatus: document.getElementById('login-email-status'),
                emError:  document.getElementById('login-email-error'),
                pwStatus: document.getElementById('login-password-status'),
                pwError:  document.getElementById('login-password-error'),
            };

            function runEmail() {
                const r = validateEmail(email.value);
                setFieldState(email, s.emStatus, s.emError, r.ok ? 'valid' : (email.value ? 'invalid' : 'neutral'), r.msg);
                return r.ok;
            }
            function runPassword() {
                const v = password.value || '';
                let ok = true, msg = '';
                if (!v) { ok = false; msg = 'Password is required'; }
                else if (v.length < 8) { ok = false; msg = 'Password must be at least 8 characters'; }
                setFieldState(password, s.pwStatus, s.pwError, ok ? 'valid' : (v ? 'invalid' : 'neutral'), msg);
                return ok;
            }

            email.addEventListener('input', runEmail);
            email.addEventListener('blur',  runEmail);
            password.addEventListener('input', runPassword);
            password.addEventListener('blur',  runPassword);

            form.addEventListener('submit', function (e) {
                const ok = runEmail() & runPassword();
                if (!ok) {
                    e.preventDefault();
                    const firstInvalid = form.querySelector('.form-control.is-invalid');
                    if (firstInvalid) { firstInvalid.focus(); firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                    return false;
                }
                submit.disabled = true;
                submit.textContent = '⏳ Signing in...';
            });
        })();

        console.log('✅ Wildlife Sentinel Login Page Loaded');
        console.log('🔧 DEBUG_MODE:', <?= $DEBUG_MODE ? 'true' : 'false' ?>);
        console.log('🚧 Maintenance Mode:', <?= $maintenanceMode ? 'true' : 'false' ?>);
    </script>
</body>
</html>
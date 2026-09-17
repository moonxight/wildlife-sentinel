<?php
// ============================================================
// supervisor/settings.php
// Zone Supervisor — Personal Account Settings
// ------------------------------------------------------------
// Sections:
//   - Profile Information (name, email, phone)
//   - Change Password
//   - Personal Notification Preferences
//   - Session / Security Info
//   - Recent Activity
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!hasRole('zone_supervisor')) {
    header('Location: ../index.php');
    exit();
}

$user         = getCurrentUser();
$pdo          = getDB();
$activeZoneId = (int)$user['zone_id'];

// ============================================================
// SAFE HELPERS
// ============================================================
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// AUTO-CREATE user_preferences TABLE
// ============================================================
try {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS user_preferences (
            user_id INTEGER PRIMARY KEY,
            theme VARCHAR(20) DEFAULT \'light\',
            language VARCHAR(10) DEFAULT \'en\',
            timezone VARCHAR(50) DEFAULT \'Africa/Lusaka\',
            date_format VARCHAR(50) DEFAULT \'M j, Y H:i\',
            items_per_page INT DEFAULT 25,
            email_notifications SMALLINT DEFAULT 1,
            sms_notifications SMALLINT DEFAULT 1,
            push_notifications SMALLINT DEFAULT 1,
            notify_incidents SMALLINT DEFAULT 1,
            notify_ai_alerts SMALLINT DEFAULT 1,
            notify_manpower SMALLINT DEFAULT 1,
            notify_alarms SMALLINT DEFAULT 1,
            quiet_hours_start TIME DEFAULT NULL,
            quiet_hours_end TIME DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} catch (PDOException $e) {}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- UPDATE PROFILE --------
    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');

        if (!$fullName || !$email) {
            $message = 'Name and email are required.';
            $messageType = 'danger';
        } elseif (!validateEmail($email)) {
            $message = 'Please enter a valid email address.';
            $messageType = 'danger';
        } else {
            try {
                // Email uniqueness check
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $user['id']]);
                if ($stmt->fetch()) {
                    $message = 'Email is already in use by another account.';
                    $messageType = 'danger';
                } else {
                    $pdo->prepare("
                        UPDATE users SET full_name = ?, email = ?, phone = ?
                        WHERE id = ?
                    ")->execute([$fullName, $email, $phone ?: null, $user['id']]);

                    logAudit($user['id'], 'update_profile', ['email' => $email]);
                    $message = 'Profile updated successfully.';

                    // Refresh local copy
                    $user['full_name'] = $fullName;
                    $user['email']     = $email;
                    $user['phone']     = $phone;
                }
            } catch (PDOException $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // -------- CHANGE PASSWORD --------
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            $message = 'All password fields are required.';
            $messageType = 'danger';
        } elseif (!verifyPassword($current, $user['password_hash'])) {
            $message = 'Current password is incorrect.';
            $messageType = 'danger';
        } elseif ($new !== $confirm) {
            $message = 'New passwords do not match.';
            $messageType = 'danger';
        } else {
            $pwErrors = validatePasswordStrength($new);
            if (!empty($pwErrors)) {
                $message = implode('<br>', $pwErrors);
                $messageType = 'danger';
            } else {
                try {
                    $hash = hashPassword($new);
                    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                        ->execute([$hash, $user['id']]);
                    logAudit($user['id'], 'change_password', ['email' => $user['email']]);
                    $message = 'Password changed successfully.';
                } catch (PDOException $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    // -------- UPDATE PREFERENCES --------
    if ($action === 'update_preferences') {
        $theme   = $_POST['theme']   ?? 'light';
        $lang    = $_POST['language'] ?? 'en';
        $tz      = $_POST['timezone'] ?? 'Africa/Lusaka';
        $items   = max(5, min(100, (int)($_POST['items_per_page'] ?? 25)));
        $emailN  = isset($_POST['email_notifications']) ? 1 : 0;
        $smsN    = isset($_POST['sms_notifications'])   ? 1 : 0;
        $pushN   = isset($_POST['push_notifications'])  ? 1 : 0;
        $onInc   = isset($_POST['notify_incidents'])    ? 1 : 0;
        $onAI    = isset($_POST['notify_ai_alerts'])    ? 1 : 0;
        $onMan   = isset($_POST['notify_manpower'])     ? 1 : 0;
        $onAlm   = isset($_POST['notify_alarms'])       ? 1 : 0;
        $qhStart = $_POST['quiet_hours_start'] ?: null;
        $qhEnd   = $_POST['quiet_hours_end']   ?: null;

        try {
            $pdo->prepare('
                INSERT INTO user_preferences
                    (user_id, theme, language, timezone, items_per_page,
                     email_notifications, sms_notifications, push_notifications,
                     notify_incidents, notify_ai_alerts, notify_manpower, notify_alarms,
                     quiet_hours_start, quiet_hours_end, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (user_id) DO UPDATE SET 
                    theme = EXCLUDED.theme,
                    language = EXCLUDED.language,
                    timezone = EXCLUDED.timezone,
                    items_per_page = EXCLUDED.items_per_page,
                    email_notifications = EXCLUDED.email_notifications,
                    sms_notifications = EXCLUDED.sms_notifications,
                    push_notifications = EXCLUDED.push_notifications,
                    notify_incidents = EXCLUDED.notify_incidents,
                    notify_ai_alerts = EXCLUDED.notify_ai_alerts,
                    notify_manpower = EXCLUDED.notify_manpower,
                    notify_alarms = EXCLUDED.notify_alarms,
                    quiet_hours_start = EXCLUDED.quiet_hours_start,
                    quiet_hours_end = EXCLUDED.quiet_hours_end,
                    updated_at = NOW()
            ')->execute([
                $user['id'], $theme, $lang, $tz, $items,
                $emailN, $smsN, $pushN,
                $onInc, $onAI, $onMan, $onAlm,
                $qhStart, $qhEnd,
            ]);

            logAudit($user['id'], 'update_preferences', ['email' => $user['email']]);
            $message = 'Preferences saved.';
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ============================================================
// LOAD PREFERENCES
// ============================================================
$prefs = [
    'theme' => 'light', 'language' => 'en',
    'timezone' => 'Africa/Lusaka', 'items_per_page' => 25,
    'email_notifications' => 1, 'sms_notifications' => 1, 'push_notifications' => 1,
    'notify_incidents' => 1, 'notify_ai_alerts' => 1,
    'notify_manpower' => 1, 'notify_alarms' => 1,
    'quiet_hours_start' => null, 'quiet_hours_end' => null,
];

try {
    $row = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
    $row->execute([$user['id']]);
    $p = $row->fetch();
    if ($p) {
        foreach ($prefs as $k => $v) {
            if (isset($p[$k])) $prefs[$k] = $p[$k];
        }
    }
} catch (PDOException $e) {}

// ============================================================
// RECENT ACTIVITY
// ============================================================
$recentActivity = safeFetchAll($pdo, "
    SELECT action, details, ip_address, created_at
    FROM audit_logs
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
", [$user['id']]);

// ============================================================
// PROFILE STATS
// ============================================================
$profileStats = [
    'last_login'   => $user['last_online'] ?? null,
    'member_since' => $user['created_at']  ?? null,
    'zone_name'    => getZoneName($activeZoneId),
    'role_label'   => ucfirst(str_replace('_', ' ', $user['role'])),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Settings - Supervisor</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 22px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 15px; }

        .profile-header {
            background: linear-gradient(135deg, #0d3b22, #1a5c3a);
            border-radius: 14px;
            padding: 24px 28px;
            color: white;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 4px 20px rgba(13,59,34,0.2);
        }
        .profile-header .avatar-lg {
            width: 80px; height: 80px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 34px; font-weight: 700;
            flex-shrink: 0;
            border: 3px solid rgba(255,255,255,0.3);
        }
        .profile-header .info { flex: 1; min-width: 200px; }
        .profile-header .name { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .profile-header .role {
            font-size: 12px; opacity: 0.8; margin-top: 4px;
            text-transform: uppercase; letter-spacing: 1px;
        }
        .profile-header .meta {
            font-size: 13px; opacity: 0.7; margin-top: 8px;
            display: flex; flex-wrap: wrap; gap: 14px;
        }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .info .number { font-size: 18px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .section-header .section-desc { font-size: 12px; color: #6c757d; margin-top: 2px; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-danger { background: #dc3545; color: white; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            font-size: 12px; font-weight: 600; color: #495057;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .form-group .hint {
            font-size: 11px; color: #6c757d; font-weight: normal;
            text-transform: none; letter-spacing: 0;
        }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 13px;
            background: #fafafa;
            font-family: inherit;
            transition: all 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #1a5c3a; background: white;
        }

        /* Toggle */
        .toggle-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 0; border-bottom: 1px solid #f0f0f0;
        }
        .toggle-row:last-child { border-bottom: none; }
        .toggle-row .toggle-info { flex: 1; padding-right: 12px; }
        .toggle-row .toggle-label { font-size: 13px; font-weight: 600; color: #0d3b22; }
        .toggle-row .toggle-desc { font-size: 11px; color: #6c757d; margin-top: 2px; }

        .switch { position: relative; display: inline-block; width: 46px; height: 26px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch .slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc; transition: .3s; border-radius: 26px;
        }
        .switch .slider:before {
            position: absolute; content: "";
            height: 20px; width: 20px; left: 3px; bottom: 3px;
            background-color: white; transition: .3s; border-radius: 50%;
        }
        .switch input:checked + .slider { background-color: #28a745; }
        .switch input:checked + .slider:before { transform: translateX(20px); }

        /* Activity */
        .activity-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 8px;
            background: #fafafa; margin-bottom: 6px;
            font-size: 13px;
        }
        .activity-icon {
            width: 30px; height: 30px; border-radius: 50%;
            background: #f0f7f4; display: flex; align-items: center;
            justify-content: center; font-size: 13px; flex-shrink: 0;
        }
        .activity-text { flex: 1; min-width: 0; }
        .activity-text .action { font-weight: 600; color: #0d3b22; text-transform: capitalize; }
        .activity-text .meta { font-size: 11px; color: #adb5bd; }
        .activity-time { font-size: 11px; color: #adb5bd; }

        .info-banner {
            background: #f8fbff; border-left: 4px solid #cce5ff;
            border-radius: 10px; padding: 14px 18px;
            font-size: 13px; color: #495057; line-height: 1.7;
        }

        .pw-requirements {
            font-size: 11px; color: #6c757d;
            margin-top: 4px; line-height: 1.7;
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .form-grid { grid-template-columns: 1fr; }
            .profile-header { flex-direction: column; text-align: center; }
            .profile-header .meta { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>My Settings</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>👤 My Settings</h1>
                    <p>Manage your personal profile, password, and notification preferences.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= $messageType ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- Profile header -->
                <div class="profile-header">
                    <div class="avatar-lg"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
                    <div class="info">
                        <div class="name"><?= htmlspecialchars($user['full_name']) ?></div>
                        <div class="role"><?= htmlspecialchars($profileStats['role_label']) ?> • <?= htmlspecialchars($profileStats['zone_name']) ?></div>
                        <div class="meta">
                            <span>📧 <?= htmlspecialchars($user['email']) ?></span>
                            <?php if (!empty($user['phone'])): ?>
                                <span>📞 <?= htmlspecialchars($user['phone']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($profileStats['last_login'])): ?>
                                <span>🕐 Last login: <?= timeAgo($profileStats['last_login']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon blue">🏛️</div><div class="info"><div class="number" style="font-size:13px;"><?= htmlspecialchars($profileStats['zone_name']) ?></div><div class="label">Assigned Zone</div></div></div>
                    <div class="stat-card"><div class="icon green">🎭</div><div class="info"><div class="number" style="font-size:15px;"><?= htmlspecialchars($profileStats['role_label']) ?></div><div class="label">Role</div></div></div>
                    <div class="stat-card"><div class="icon purple">📅</div><div class="info"><div class="number" style="font-size:13px;"><?= $profileStats['member_since'] ? date('M j, Y', strtotime($profileStats['member_since'])) : '—' ?></div><div class="label">Member Since</div></div></div>
                    <div class="stat-card"><div class="icon orange">🕐</div><div class="info"><div class="number" style="font-size:13px;"><?= $profileStats['last_login'] ? timeAgo($profileStats['last_login']) : '—' ?></div><div class="label">Last Login</div></div></div>
                </div>

                <!-- Two-column layout -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">
                    <!-- LEFT COLUMN -->
                    <div>
                        <!-- PROFILE INFO -->
                        <div class="section">
                            <div class="section-header">
                                <div>
                                    <h2>👤 Profile Information</h2>
                                    <div class="section-desc">Update your name, email, and phone.</div>
                                </div>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_profile">

                                <div class="form-group full">
                                    <label>Full Name *</label>
                                    <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                                </div>
                                <div class="form-group full">
                                    <label>Email Address *</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                                </div>
                                <div class="form-group full">
                                    <label>Phone Number</label>
                                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="e.g. 0971234567">
                                </div>

                                <div style="display:flex;justify-content:flex-end;">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Profile</button>
                                </div>
                            </form>
                        </div>

                        <!-- CHANGE PASSWORD -->
                        <div class="section">
                            <div class="section-header">
                                <div>
                                    <h2>🔒 Change Password</h2>
                                    <div class="section-desc">Update your account password.</div>
                                </div>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="change_password">

                                <div class="form-group full">
                                    <label>Current Password *</label>
                                    <input type="password" name="current_password" required placeholder="Enter your current password">
                                </div>
                                <div class="form-group full">
                                    <label>New Password *</label>
                                    <input type="password" name="new_password" required minlength="8" placeholder="Minimum 8 characters">
                                    <div class="pw-requirements">
                                        ✅ At least 8 characters<br>
                                        ✅ One uppercase letter<br>
                                        ✅ One lowercase letter<br>
                                        ✅ One number
                                    </div>
                                </div>
                                <div class="form-group full">
                                    <label>Confirm New Password *</label>
                                    <input type="password" name="confirm_password" required minlength="8" placeholder="Repeat your new password">
                                </div>

                                <div style="display:flex;justify-content:flex-end;">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Change Password</button>
                                </div>
                            </form>
                        </div>

                        <!-- PERSONAL PREFERENCES -->
                        <div class="section">
                            <div class="section-header">
                                <div>
                                    <h2>🎨 Preferences</h2>
                                    <div class="section-desc">Language, timezone, display options.</div>
                                </div>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_preferences">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Language</label>
                                        <select name="language">
                                            <option value="en" <?= $prefs['language'] === 'en' ? 'selected' : '' ?>>English</option>
                                            <option value="ny" <?= $prefs['language'] === 'ny' ? 'selected' : '' ?>>Chichewa</option>
                                            <option value="bem" <?= $prefs['language'] === 'bem' ? 'selected' : '' ?>>Bemba</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Theme</label>
                                        <select name="theme">
                                            <option value="light" <?= $prefs['theme'] === 'light' ? 'selected' : '' ?>>Light</option>
                                            <option value="dark"  <?= $prefs['theme'] === 'dark'  ? 'selected' : '' ?>>Dark</option>
                                            <option value="auto"  <?= $prefs['theme'] === 'auto'  ? 'selected' : '' ?>>Auto</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Timezone</label>
                                        <select name="timezone">
                                            <?php foreach (['Africa/Lusaka','Africa/Johannesburg','Africa/Nairobi','UTC','Europe/London'] as $tz): ?>
                                                <option value="<?= $tz ?>" <?= $prefs['timezone'] === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Items Per Page</label>
                                        <input type="number" name="items_per_page" value="<?= (int)$prefs['items_per_page'] ?>" min="5" max="100">
                                    </div>
                                </div>

                                <div style="font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;margin:16px 0 8px;">
                                    Personal Notification Channels
                                </div>

                                <div class="toggle-row">
                                    <div class="toggle-info"><div class="toggle-label">📧 Email Notifications</div></div>
                                    <label class="switch">
                                        <input type="checkbox" name="email_notifications" value="1" <?= (int)$prefs['email_notifications'] === 1 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="toggle-row">
                                    <div class="toggle-info"><div class="toggle-label">📱 SMS Notifications</div></div>
                                    <label class="switch">
                                        <input type="checkbox" name="sms_notifications" value="1" <?= (int)$prefs['sms_notifications'] === 1 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="toggle-row">
                                    <div class="toggle-info"><div class="toggle-label">📲 Push Notifications</div></div>
                                    <label class="switch">
                                        <input type="checkbox" name="push_notifications" value="1" <?= (int)$prefs['push_notifications'] === 1 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div style="font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;margin:16px 0 8px;">
                                    Personal Event Alerts
                                </div>

                                <div class="toggle-row">
                                    <div class="toggle-info"><div class="toggle-label">New Incidents</div></div>
                                    <label class="switch">
                                        <input type="checkbox" name="notify_incidents" value="1" <?= (int)$prefs['notify_incidents'] === 1 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="toggle-row">
                                    <div class="toggle-info"><div class="toggle-label">AI Alerts</div></div>
                                    <label class="switch">
                                        <input type="checkbox" name="notify_ai_alerts" value="1" <?= (int)$prefs['notify_ai_alerts'] === 1 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="toggle-row">
                                    <div class="toggle-info"><div class="toggle-label">Manpower Requests</div></div>
                                    <label class="switch">
                                        <input type="checkbox" name="notify_manpower" value="1" <?= (int)$prefs['notify_manpower'] === 1 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="toggle-row">
                                    <div class="toggle-info"><div class="toggle-label">Alarm Triggers</div></div>
                                    <label class="switch">
                                        <input type="checkbox" name="notify_alarms" value="1" <?= (int)$prefs['notify_alarms'] === 1 ? 'checked' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>

                                <div class="form-grid" style="margin-top:14px;">
                                    <div class="form-group">
                                        <label>Quiet Hours Start</label>
                                        <input type="time" name="quiet_hours_start" value="<?= htmlspecialchars($prefs['quiet_hours_start'] ?? '') ?>">
                                        <span class="hint">No alerts during these hours.</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Quiet Hours End</label>
                                        <input type="time" name="quiet_hours_end" value="<?= htmlspecialchars($prefs['quiet_hours_end'] ?? '') ?>">
                                    </div>
                                </div>

                                <div style="display:flex;justify-content:flex-end;">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Preferences</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN -->
                    <div>
                        <!-- RECENT ACTIVITY -->
                        <div class="section">
                            <div class="section-header">
                                <h2>📋 Recent Activity</h2>
                            </div>
                            <?php if (count($recentActivity) > 0): ?>
                                <?php
                                $activityIcons = [
                                    'login' => '🔑', 'logout' => '🚪',
                                    'update_profile' => '👤', 'change_password' => '🔒',
                                    'update_preferences' => '⚙️',
                                    'acknowledge_incident' => '✅', 'resolve_incident' => '🏁',
                                    'trigger_alarm' => '🔔', 'create_camera' => '📹',
                                    'update_zone' => '📍', 'update_zone_settings' => '⚙️',
                                ];
                                ?>
                                <?php foreach ($recentActivity as $a): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <?= $activityIcons[$a['action']] ?? '📌' ?>
                                        </div>
                                        <div class="activity-text">
                                            <div class="action"><?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?></div>
                                            <?php if (!empty($a['ip_address'])): ?>
                                                <div class="meta">🌐 <?= htmlspecialchars($a['ip_address']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-time"><?= timeAgo($a['created_at']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p style="font-size:13px;color:#6c757d;text-align:center;padding:14px;">
                                    No recent activity.
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- SECURITY INFO -->
                        <div class="section">
                            <div class="section-header">
                                <h2>🛡️ Account Security</h2>
                            </div>
                            <div class="info-banner">
                                <strong>Your Account:</strong><br>
                                • Role: <b><?= htmlspecialchars($profileStats['role_label']) ?></b><br>
                                • Zone: <b><?= htmlspecialchars($profileStats['zone_name']) ?></b><br>
                                • Email verified: <b>✅ Yes</b><br>
                                • Account created: <b><?= $profileStats['member_since'] ? date('M j, Y', strtotime($profileStats['member_since'])) : '—' ?></b>
                            </div>
                            <div style="margin-top:14px;">
                                <a href="<?= $rootPrefix ?>logout.php?role=supervisor" class="btn btn-danger" style="width:100%;justify-content:center;"
                                   onclick="return confirm('Log out of your account?')">
                                    <i class="fas fa-sign-out-alt"></i> Log Out
                                </a>
                            </div>
                        </div>

                        <!-- TIPS -->
                        <div class="section" style="border-left:4px solid #cce5ff;background:#f8fbff;">
                            <div style="font-size:13px;color:#495057;line-height:1.7;">
                                <strong>💡 Personal Tips</strong><br>
                                • Use a strong, unique password<br>
                                • Configure quiet hours to avoid late-night alerts<br>
                                • Enable SMS for critical alerts if you're often offline<br>
                                • Report suspicious activity to your administrator
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
</body>
</html>
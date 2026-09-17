<?php
// ============================================================
// tourism/notifications.php
// Tourism / Lodge Operator — Notification Center
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = getCurrentUser();
if (!$user || $user['role'] !== 'tourism') {
    header('Location: ../index.php');
    exit();
}

$pdo = getDB();

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
if (!function_exists('safeCount')) {
    function safeCount(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)($stmt->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // MARK ONE READ
    if ($action === 'mark_read') {
        $id = (int)$_POST['notification_id'];
        try {
            $pdo->prepare("
                UPDATE notifications SET is_read = 1, read_at = NOW()
                WHERE id = ? AND user_id = ?
            ")->execute([$id, $user['id']]);
            $message = 'Notification marked as read.';
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // MARK ALL READ
    if ($action === 'mark_all_read') {
        try {
            $pdo->prepare("
                UPDATE notifications SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND is_read = 0
            ")->execute([$user['id']]);
            $message = 'All notifications marked as read.';
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // DELETE ONE
    if ($action === 'delete') {
        $id = (int)$_POST['notification_id'];
        try {
            $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")
                ->execute([$id, $user['id']]);
            $message = 'Notification deleted.';
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // CLEAR ALL READ
    if ($action === 'clear_read') {
        try {
            $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")
                ->execute([$user['id']]);
            $message = 'All read notifications cleared.';
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ============================================================
// FETCH NOTIFICATIONS (filtered)
// ============================================================
$filter = $_GET['filter'] ?? 'all';
$where  = " WHERE user_id = ? ";
$params = [$user['id']];

if ($filter === 'unread') {
    $where .= " AND is_read = 0 ";
} elseif ($filter === 'read') {
    $where .= " AND is_read = 1 ";
}

$notifications = safeFetchAll($pdo, "
    SELECT * FROM notifications
    $where
    ORDER BY is_read ASC, created_at DESC
    LIMIT 200
", $params);

// ============================================================
// STATS
// ============================================================
$stats = [
    'total'  => safeCount($pdo, "SELECT COUNT(*) as count FROM notifications WHERE user_id = ?", [$user['id']]),
    'unread' => safeCount($pdo, "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0", [$user['id']]),
    'read'   => safeCount($pdo, "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 1", [$user['id']]),
    'today'  => safeCount($pdo, 'SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND DATE(created_at) = CURRENT_DATE', [$user['id']]),
];

// ============================================================
// ICONS
// ============================================================
$typeIcons = [
    'new_incident'     => ['icon' => '🚨', 'color' => 'red'],
    'acknowledged'     => ['icon' => '✅', 'color' => 'green'],
    'status_update'    => ['icon' => '🔄', 'color' => 'blue'],
    'system_alert'     => ['icon' => '⚠️', 'color' => 'orange'],
    'new_message'      => ['icon' => '💬', 'color' => 'teal'],
    'manpower_request' => ['icon' => '🆘', 'color' => 'purple'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Notifications - Tourism - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 22px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 15px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        /* Filter tabs */
        .filter-tabs { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
        .filter-tab {
            padding: 8px 16px; border-radius: 20px;
            background: #f0f0f0; color: #495057;
            text-decoration: none; font-size: 13px;
            font-weight: 600; transition: all 0.2s;
        }
        .filter-tab:hover { background: #e0e0e0; }
        .filter-tab.active { background: #1a5c3a; color: white; }

        /* Notification item */
        .notif-item {
            display: flex; gap: 14px;
            padding: 14px 16px; margin-bottom: 10px;
            background: white; border-radius: 12px;
            border: 1px solid #f0f0f0;
            transition: all 0.2s;
            align-items: flex-start;
        }
        .notif-item.unread {
            background: #f8fbff; border-color: #cce5ff;
            border-left: 4px solid #007bff;
        }
        .notif-item:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.06); }

        .notif-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
        }
        .notif-icon.red    { background: #f8d7da; color: #721c24; }
        .notif-icon.green  { background: #d4edda; color: #155724; }
        .notif-icon.blue   { background: #cce5ff; color: #004085; }
        .notif-icon.orange { background: #fff3cd; color: #856404; }
        .notif-icon.teal   { background: #d1ecf1; color: #0c5460; }
        .notif-icon.purple { background: #e8d5f5; color: #6f42c1; }

        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-weight: 700; font-size: 14px; color: #0d3b22; display: flex; align-items: center; gap: 6px; }
        .notif-title .unread-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #007bff; display: inline-block;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
        .notif-text { font-size: 13px; color: #495057; margin-top: 4px; line-height: 1.5; }
        .notif-meta { font-size: 11px; color: #adb5bd; margin-top: 6px; }

        .notif-actions { display: flex; flex-direction: column; gap: 6px; flex-shrink: 0; }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .notif-item { flex-wrap: wrap; }
            .notif-actions { flex-direction: row; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Notifications</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🔔 Notifications</h1>
                    <p>Updates about your reports, ranger responses, and zone safety alerts.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon blue">📬</div><div class="info"><div class="number"><?= $stats['total'] ?></div><div class="label">Total</div></div></div>
                    <div class="stat-card"><div class="icon red">🔴</div><div class="info"><div class="number"><?= $stats['unread'] ?></div><div class="label">Unread</div></div></div>
                    <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= $stats['read'] ?></div><div class="label">Read</div></div></div>
                    <div class="stat-card"><div class="icon orange">📅</div><div class="info"><div class="number"><?= $stats['today'] ?></div><div class="label">Today</div></div></div>
                </div>

                <!-- Filter + Bulk Actions -->
                <div class="section">
                    <div class="section-header">
                        <h2>🔎 Filter</h2>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button class="btn btn-sm btn-primary">✅ Mark All Read</button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Clear all READ notifications?')">
                                <input type="hidden" name="action" value="clear_read">
                                <button class="btn btn-sm btn-secondary">🧹 Clear Read</button>
                            </form>
                        </div>
                    </div>

                    <div class="filter-tabs">
                        <a href="?filter=all"    class="filter-tab <?= $filter === 'all'    ? 'active' : '' ?>">All (<?= $stats['total'] ?>)</a>
                        <a href="?filter=unread" class="filter-tab <?= $filter === 'unread' ? 'active' : '' ?>">Unread (<?= $stats['unread'] ?>)</a>
                        <a href="?filter=read"   class="filter-tab <?= $filter === 'read'   ? 'active' : '' ?>">Read (<?= $stats['read'] ?>)</a>
                    </div>
                </div>

                <!-- Notification list -->
                <div class="section">
                    <div class="section-header">
                        <h2>📋 Notifications (<?= count($notifications) ?>)</h2>
                    </div>

                    <?php if (count($notifications) > 0): ?>
                        <?php foreach ($notifications as $n): ?>
                            <?php
                            $meta = $typeIcons[$n['type']] ?? ['icon' => '🔔', 'color' => 'blue'];
                            ?>
                            <div class="notif-item <?= (int)$n['is_read'] === 0 ? 'unread' : '' ?>">
                                <div class="notif-icon <?= $meta['color'] ?>"><?= $meta['icon'] ?></div>
                                <div class="notif-body">
                                    <div class="notif-title">
                                        <?php if ((int)$n['is_read'] === 0): ?>
                                            <span class="unread-dot"></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($n['title'] ?? 'Notification') ?>
                                    </div>
                                    <?php if (!empty($n['body'])): ?>
                                        <div class="notif-text"><?= htmlspecialchars($n['body']) ?></div>
                                    <?php endif; ?>
                                    <div class="notif-meta">
                                        🕐 <?= timeAgo($n['created_at']) ?>
                                        <?php if (!empty($n['incident_id'])): ?>
                                            • 🚨 Report #<?= (int)$n['incident_id'] ?>
                                        <?php endif; ?>
                                        • <span style="text-transform:capitalize;">
                                            <?= htmlspecialchars(str_replace('_', ' ', $n['type'] ?? 'general')) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="notif-actions">
                                    <?php if ((int)$n['is_read'] === 0): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="mark_read">
                                            <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                                            <button class="btn btn-sm btn-secondary">✔️ Mark Read</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!empty($n['incident_id'])): ?>
                                        <a href="my-reports.php" class="btn btn-sm btn-primary">👁️ View Report</a>
                                    <?php endif; ?>

                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this notification?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                                        <button class="btn btn-sm btn-danger">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">🔔</span>
                            <h3 style="font-size:15px;color:#495057;">No notifications</h3>
                            <p>You're all caught up! Updates about your reports will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info notice -->
                <div class="section" style="border-left:4px solid #cce5ff;background:#f8fbff;">
                    <div style="font-size:13px;color:#495057;line-height:1.7;">
                        <strong>ℹ️ What you'll see here</strong><br>
                        • <b>🚨 New Incident</b> — confirmations of your submitted reports.<br>
                        • <b>✅ Acknowledged</b> — when a ranger responds to your report.<br>
                        • <b>🔄 Status Update</b> — progress on your reports (in progress, resolved).<br>
                        • <b>⚠️ System Alert</b> — zone-wide safety notifications.<br>
                        • <b>💬 New Message</b> — messages from rangers or supervisors.<br>
                        <br>
                        You will <b>never</b> see sensitive ranger information (their live location, patrol routes, or responder details). You will only see status updates about your own reports.
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
</body>
</html>
<?php
// ============================================================
// supervisor/manpower.php
// Zone Supervisor — Ranger Manpower Requests Review
// ------------------------------------------------------------
// Honors global settings: notify_on_manpower, items_per_page.
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once '../includes/functions.php';
requireLogin();

if (!function_exists('hasRole') || !hasRole('zone_supervisor')) {
    header('Location: ../index.php');
    exit();
}

$user         = getCurrentUser();
$pdo          = getDB();
$activeZoneId = (int)($user['zone_id'] ?? 0);

// ============================================================
// GLOBAL SETTINGS
// ============================================================
if (!function_exists('ws_mp_setting')) {
    function ws_mp_setting(string $key, $default = null) {
        if (function_exists('getSetting')) {
            $v = getSetting($key);
            return $v !== null ? $v : $default;
        }
        try {
            $stmt = getDB()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['setting_value'] : $default;
        } catch (PDOException $e) {
            return $default;
        }
    }
}

$setNotifyManpower = (string) ws_mp_setting('notify_on_manpower', '1') === '1';
$itemsPerPage      = (int)    ws_mp_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;

// ============================================================
// SAFE HELPERS
// ============================================================
if (!function_exists('safeCount')) {
    function safeCount(PDO $pdo, string $sql, array $params = []): int {
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params);
            return (int)($stmt->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
        try { $stmt = $pdo->prepare($sql); $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('ws_mp_sev_badge')) {
    function ws_mp_sev_badge(?string $sev): string {
        if (function_exists('getSeverityBadge')) return getSeverityBadge($sev);
        $sev = htmlspecialchars((string)$sev);
        return '<span class="sev-badge ' . $sev . '">' . strtoupper($sev) . '</span>';
    }
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message = ''; $messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ---- ACKNOWLEDGE MANPOWER REQUEST ----
    if ($action === 'ack') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        try {
            // Zone guard: only ack if the message belongs to this zone
            $chk = $pdo->prepare("
                SELECT m.id
                FROM messages m
                LEFT JOIN users s ON m.sender_id = s.id
                WHERE m.id = ? AND m.message_type = 'manpower_request'
                  AND (m.zone_id = ? OR (m.zone_id IS NULL AND s.zone_id = ?))
                LIMIT 1
            ");
            $chk->execute([$msgId, $activeZoneId, $activeZoneId]);

            if (!$chk->fetch()) {
                $message = 'Request not found in your zone.';
                $messageType = 'danger';
            } else {
                $pdo->prepare("
                    UPDATE messages
                    SET is_read = 1, read_at = NOW(), acknowledged_at = NOW()
                    WHERE id = ? AND message_type = 'manpower_request'
                ")->execute([$msgId]);

                logAudit($user['id'], 'acknowledge_manpower', ['message_id' => $msgId]);
                $message = '✅ Manpower request acknowledged.';
            }
        } catch (PDOException $e) {
            error_log('[WS-MP] ack: ' . $e->getMessage());
            $message = 'Could not acknowledge. Try again.';
            $messageType = 'danger';
        }
    }

    // ---- ASSIGN ADDITIONAL RANGER ----
    if ($action === 'assign') {
        $incidentId = (int)($_POST['incident_id'] ?? 0);
        $rangerId   = (int)($_POST['ranger_id'] ?? 0);

        try {
            // Zone guard: ranger must belong to this zone
            $ranger = safeFetchAll($pdo, "
                SELECT id FROM users
                WHERE id = ? AND zone_id = ? AND role = 'ranger' AND is_active = 1
            ", [$rangerId, $activeZoneId]);

            // Zone guard: incident must belong to this zone
            $incident = safeFetchAll($pdo, "
                SELECT id FROM incidents WHERE id = ? AND zone_id = ?
            ", [$incidentId, $activeZoneId]);

            if (!$ranger) {
                $message = 'Ranger not found in your zone.';
                $messageType = 'danger';
            } elseif (!$incident) {
                $message = 'Incident not found in your zone.';
                $messageType = 'danger';
            } else {
                if (function_exists('assignRangerToIncident')) {
                    assignRangerToIncident($incidentId, $rangerId, $user['id'], 'Assigned by supervisor for manpower request');
                    logAudit($user['id'], 'assign_incident', ['incident_id' => $incidentId, 'ranger_id' => $rangerId]);
                    $message = '✅ Ranger assigned successfully.';

                    // Best-effort notify the assigned ranger
                    if ($setNotifyManpower && function_exists('createNotification')) {
                        try {
                            createNotification($rangerId, 'manpower_request', '🆘 Assigned to Incident',
                                "You've been assigned to incident #{$incidentId} by your supervisor.", $incidentId);
                        } catch (Throwable $e) { /* silent */ }
                    }
                } else {
                    $message = '⚠️ assignRangerToIncident() helper not available.';
                    $messageType = 'danger';
                }
            }
        } catch (Throwable $e) {
            error_log('[WS-MP] assign: ' . $e->getMessage());
            $message = 'Could not assign ranger. Try again.';
            $messageType = 'danger';
        }
    }
}

// ============================================================
// FETCH MANPOWER REQUESTS
// ------------------------------------------------------------
// Correct columns: messages.subject, messages.content, messages.severity
// (was using these same names but the display was inconsistent).
// If your schema uses `description` instead of `content`, swap below.
// ============================================================
$requests = safeFetchAll($pdo, "
    SELECT m.*,
           s.full_name AS sender_name, s.phone AS sender_phone, s.badge_number,
           i.category AS incident_category, i.severity AS incident_severity,
           i.location_lat, i.location_lng, i.description AS incident_description,
           i.status AS incident_status
    FROM messages m
    LEFT JOIN users s ON m.sender_id = s.id
    LEFT JOIN incidents i ON m.incident_id = i.id
    WHERE m.message_type = 'manpower_request'
      AND (m.zone_id = ? OR (m.zone_id IS NULL AND s.zone_id = ?))
    ORDER BY m.is_read ASC, m.created_at DESC
    LIMIT " . (int)$itemsPerPage . "
", [$activeZoneId, $activeZoneId]);

// ============================================================
// AVAILABLE RANGERS
// ============================================================
$availableRangers = safeFetchAll($pdo, "
    SELECT u.id, u.full_name, u.badge_number, u.is_on_duty
    FROM users u
    WHERE u.zone_id = ? AND u.role = 'ranger' AND u.is_active = 1
    ORDER BY u.is_on_duty DESC, u.full_name
", [$activeZoneId]);

// ============================================================
// STATS
// ============================================================
$totalRequests    = count($requests);
$unreadRequests   = count(array_filter($requests, fn($r) => empty($r['is_read'])));
$criticalRequests = count(array_filter($requests, fn($r) => ($r['severity'] ?? '') === 'critical'));
$requestsToday    = safeCount($pdo, '
    SELECT COUNT(*) as count FROM messages m
    LEFT JOIN users s ON m.sender_id = s.id
    WHERE m.message_type = \'manpower_request\'
      AND DATE(m.created_at) = CURRENT_DATE
      AND (m.zone_id = ? OR (m.zone_id IS NULL AND s.zone_id = ?))
', [$activeZoneId, $activeZoneId]);

$zoneName = function_exists('getZoneName') ? (getZoneName($activeZoneId) ?: 'Your Zone') : 'Your Zone';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manpower Requests - Supervisor - Wildlife Sentinel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        .dashboard-greeting { margin-bottom: 24px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 16px; }

        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }

        .state-banner {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: 10px;
            margin-bottom: 14px; font-size: 12.5px;
        }
        .state-banner.warn { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
        .state-banner a { color: inherit; text-decoration: underline; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }

        .btn { padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-size: 12px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 11px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        .request-card { border: 1px solid #f0f0f0; border-left: 5px solid #ffc107; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; background: white; transition: all 0.2s; }
        .request-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
        .request-card.critical { border-left-color: #dc3545; background: #fffafa; }
        .request-card.high     { border-left-color: #ff5722; }
        .request-card.medium   { border-left-color: #ffc107; }
        .request-card.low      { border-left-color: #28a745; }
        .request-card.unread   { background: #fffdf5; }

        .request-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
        .request-title { font-weight: 700; font-size: 15px; color: #0d3b22; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .request-meta { font-size: 12px; color: #6c757d; margin-top: 2px; }
        .request-body { font-size: 13px; color: #495057; background: #fafafa; padding: 10px 14px; border-radius: 8px; margin: 10px 0; white-space: pre-wrap; font-family: 'Courier New', monospace; }
        .request-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; align-items: center; }

        .sev-badge { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .sev-badge.critical { background: #dc3545; color: white; animation: pulse 1.5s infinite; }
        .sev-badge.high     { background: #fff3cd; color: #856404; }
        .sev-badge.medium   { background: #fff3cd; color: #856404; }
        .sev-badge.low      { background: #d4edda; color: #155724; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }

        .ranger-select { padding: 6px 10px; border-radius: 6px; border: 1px solid #e0e0e0; font-size: 12px; background: white; max-width: 220px; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
<div class="app-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Manpower Requests</h1>
            <div class="header-right">
                <span class="online-status">● Online</span>
                <span class="data-honesty-badge">🟢 Live Data</span>
                <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
            </div>
        </header>

        <div class="content">
            <div class="dashboard-greeting">
                <h1>🆘 Manpower Requests</h1>
                <p>Ranger backup requests for incidents in <strong><?= htmlspecialchars($zoneName) ?></strong>.</p>
            </div>

            <div class="quick-nav">
                <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
                <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                <a href="rangers.php" class="btn btn-secondary">👥 Rangers</a>
                <a href="map.php" class="btn btn-secondary">🗺️ Live Map</a>
            </div>

            <?php if (!$setNotifyManpower): ?>
                <div class="state-banner warn">
                    <span>🔕</span>
                    <div>
                        <strong>Manpower notifications are suppressed</strong> by System Settings.
                        Requests still appear here, but SMS/in-app alerts are not being sent to recipients.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert <?= htmlspecialchars($messageType) ?>"><?= $message ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card"><div class="icon purple">🆘</div><div class="info"><div class="number"><?= (int)$totalRequests ?></div><div class="label">Total Requests</div></div></div>
                <div class="stat-card"><div class="icon orange">⏳</div><div class="info"><div class="number"><?= (int)$unreadRequests ?></div><div class="label">Pending</div></div></div>
                <div class="stat-card"><div class="icon red">🚨</div><div class="info"><div class="number"><?= (int)$criticalRequests ?></div><div class="label">Critical</div></div></div>
                <div class="stat-card"><div class="icon blue">📅</div><div class="info"><div class="number"><?= (int)$requestsToday ?></div><div class="label">Today</div></div></div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>📩 All Requests</h2>
                    <span style="font-size:12px;color:#6c757d;"><?= (int)$unreadRequests ?> pending</span>
                </div>

                <?php if (count($requests) > 0): ?>
                    <?php foreach ($requests as $r): ?>
                        <?php
                        $severityClass = in_array(($r['severity'] ?? ''), ['low','medium','high','critical'], true)
                            ? $r['severity']
                            : ($r['incident_severity'] ?? 'medium');
                        $isUnread = empty($r['is_read']);
                        ?>
                        <div class="request-card <?= htmlspecialchars($severityClass) ?> <?= $isUnread ? 'unread' : '' ?>">
                            <div class="request-header">
                                <div>
                                    <div class="request-title">
                                        🚨 <?= htmlspecialchars($r['subject'] ?? 'Manpower Request') ?>
                                        <span class="sev-badge <?= htmlspecialchars($severityClass) ?>"><?= strtoupper(htmlspecialchars($severityClass)) ?></span>
                                        <?php if ($isUnread): ?><span style="color:#dc3545;font-size:11px;">● NEW</span><?php endif; ?>
                                    </div>
                                    <div class="request-meta">
                                        👤 <strong><?= htmlspecialchars($r['sender_name'] ?? 'Unknown') ?></strong>
                                        <?php if (!empty($r['badge_number'])): ?> • Badge #<?= htmlspecialchars($r['badge_number']) ?><?php endif; ?>
                                        • 📞 <?= htmlspecialchars($r['sender_phone'] ?? 'N/A') ?>
                                        • 🕐 <?= timeAgo($r['created_at'] ?? null) ?>
                                    </div>
                                    <?php if (!empty($r['incident_id'])): ?>
                                        <div class="request-meta">
                                            🎯 Incident #<?= (int)$r['incident_id'] ?> —
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $r['incident_category'] ?? 'unknown'))) ?>
                                            <?php if (!empty($r['location_lat'])): ?>
                                                • 📍 <?= number_format((float)$r['location_lat'], 4) ?>, <?= number_format((float)$r['location_lng'], 4) ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="request-body"><?= htmlspecialchars($r['content'] ?? '') ?></div>

                            <div class="request-actions">
                                <?php if ($isUnread): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="ack">
                                        <input type="hidden" name="message_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">✔️ Acknowledge</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!empty($r['incident_id']) && count($availableRangers) > 0): ?>
                                    <form method="POST" style="display:inline-flex;gap:6px;align-items:center;">
                                        <input type="hidden" name="action" value="assign">
                                        <input type="hidden" name="incident_id" value="<?= (int)$r['incident_id'] ?>">
                                        <select name="ranger_id" class="ranger-select" required>
                                            <option value="">Assign ranger…</option>
                                            <?php foreach ($availableRangers as $rg): ?>
                                                <option value="<?= (int)$rg['id'] ?>">
                                                    <?= htmlspecialchars($rg['full_name']) ?>
                                                    <?= !empty($rg['is_on_duty']) ? ' (on duty)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm">➕ Assign</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!empty($r['incident_id'])): ?>
                                    <a href="incidents.php?id=<?= (int)$r['incident_id'] ?>" class="btn btn-secondary btn-sm">👁️ View Incident</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="icon">✅</span>
                        <h3>No manpower requests</h3>
                        <p>Your rangers have not requested backup recently.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/app.js"></script>
<script src="../assets/js/transitions.js"></script>
</body>
</html>
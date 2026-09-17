<?php
// admin/dashboard.php
require_once '../includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$pdo  = getDB();

// ============================================================
// SAFE QUERY HELPERS
// ============================================================
if (!function_exists('safeCount')) {
    function safeCount(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return (int)($row['count'] ?? 0);
        } catch (PDOException $e) {
            error_log('[WS-DASH] safeCount: ' . $e->getMessage());
            return 0;
        }
    }
}
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[WS-DASH] safeFetchAll: ' . $e->getMessage());
            return [];
        }
    }
}

// ============================================================
// GLOBAL SETTINGS (from admin/settings.php)
// ============================================================
if (!function_exists('ws_dash_setting')) {
    function ws_dash_setting(string $key, $default = null) {
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

$setAiEnabled       = (string) ws_dash_setting('ai_enabled', '1')             === '1';
$setNotifyIncident  = (string) ws_dash_setting('notify_on_incident', '1')    === '1';
$setNotifyAiAlert   = (string) ws_dash_setting('notify_on_ai_alert', '1')    === '1';
$setNotifyAlarm     = (string) ws_dash_setting('notify_on_alarm', '1')       === '1';
$setMaintenanceMode = (string) ws_dash_setting('maintenance_mode', '0')      === '1';
$setMaintenanceMsg  = (string) ws_dash_setting('maintenance_message', 'System under maintenance.');

$itemsPerPage = (int) ws_dash_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;
$listLimit = max(3, min(10, $itemsPerPage)); // dashboard cards stay small

// ============================================================
// AJAX ENDPOINT — live stats JSON
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');

    $payload = [
        'success'          => true,
        'server_time'      => date('c'),
        'totalIncidents'   => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents"),
        'activeIncidents'  => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE status NOT IN ('resolved','closed')"),
        'unacknowledged'   => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE status = 'reported'"),
        'activeAlarms'     => safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_triggers WHERE stopped_at IS NULL"),
        'pendingAIAlerts'  => safeCount($pdo, "SELECT COUNT(*) as count FROM ai_alerts WHERE is_acknowledged = 0"),
        'todayDetections'  => safeCount($pdo, 'SELECT COUNT(*) as count FROM ai_detections WHERE DATE(detected_at) = CURRENT_DATE'),
        'todayThreats'     => safeCount($pdo, 'SELECT COUNT(*) as count FROM ai_detections WHERE is_threat = 1 AND DATE(detected_at) = CURRENT_DATE'),
        'todaySMS'         => safeCount($pdo, 'SELECT COUNT(*) as count FROM sms_logs WHERE status = \'sent\' AND DATE(created_at) = CURRENT_DATE'),
    ];

    echo json_encode($payload);
    exit;
}

// ============================================================
// CORE STATISTICS
// ============================================================
$totalUsers        = safeCount($pdo, "SELECT COUNT(*) as count FROM users");
$totalRangers      = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE role = 'ranger' AND is_active = 1");
$totalSupervisors  = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE role = 'zone_supervisor' AND is_active = 1");
$totalScouts       = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE role = 'scout' AND is_active = 1");

$totalIncidents    = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents");
$activeIncidents   = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE status NOT IN ('resolved','closed')");
$unacknowledged    = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE status = 'reported'");

$totalZones        = safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE is_active = 1");
$registeredParks   = safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE park_type IN ('national_park','gma') AND is_registered = 1");

// ============================================================
// AI / CCTV / ALARM / SMS STATS
// ============================================================
$totalCameras      = safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE is_active = 1");
$onlineCameras     = safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE is_active = 1 AND is_recording = 1");
$todayDetections   = safeCount($pdo, 'SELECT COUNT(*) as count FROM ai_detections WHERE DATE(detected_at) = CURRENT_DATE');
$todayThreats      = safeCount($pdo, 'SELECT COUNT(*) as count FROM ai_detections WHERE is_threat = 1 AND DATE(detected_at) = CURRENT_DATE');
$pendingAIAlerts   = safeCount($pdo, "SELECT COUNT(*) as count FROM ai_alerts WHERE is_acknowledged = 0");
$activeAlarms      = safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_triggers WHERE stopped_at IS NULL");
$todaySMS          = safeCount($pdo, 'SELECT COUNT(*) as count FROM sms_logs WHERE status = \'sent\' AND DATE(created_at) = CURRENT_DATE');

// ============================================================
// RECENT LISTS
// ============================================================
$recentIncidents = safeFetchAll($pdo, "
    SELECT i.*, u.full_name as reporter_name, z.name as zone_name
    FROM incidents i
    JOIN users u ON i.reporter_id = u.id
    LEFT JOIN zones z ON i.zone_id = z.id
    ORDER BY i.reported_at DESC
    LIMIT " . (int)$listLimit . "
");

$recentDetections = safeFetchAll($pdo, "
    SELECT d.*, c.camera_name, z.name as zone_name
    FROM ai_detections d
    LEFT JOIN cctv_cameras c ON d.camera_id = c.id
    LEFT JOIN zones z ON d.zone_id = z.id
    ORDER BY d.detected_at DESC
    LIMIT " . (int)$listLimit . "
");

$recentActivity = safeFetchAll($pdo, "
    SELECT a.*, u.full_name as user_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.created_at DESC
    LIMIT " . (int)$listLimit . "
");

$activeAlarmList = safeFetchAll($pdo, "
    SELECT at.*, a.alarm_name, z.name as zone_name
    FROM alarm_triggers at
    LEFT JOIN alarm_systems a ON at.alarm_id = a.id
    LEFT JOIN zones z ON at.zone_id = z.id
    WHERE at.stopped_at IS NULL
    ORDER BY at.triggered_at DESC
    LIMIT 3
");

$aiAlerts = safeFetchAll($pdo, '
    SELECT a.*, z.name AS zone_name
    FROM ai_alerts a
    LEFT JOIN zones z ON a.zone_id = z.id
    WHERE a.is_acknowledged = 0
    ORDER BY CASE a.severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, a.created_at DESC
    LIMIT 3
');

// ============================================================
// CHARTS
// ============================================================
$severityStats = safeFetchAll($pdo, "
    SELECT severity, COUNT(*) as count FROM incidents GROUP BY severity
");
$statusStats = safeFetchAll($pdo, "
    SELECT status, COUNT(*) as count FROM incidents GROUP BY status
");
$statuses = ['reported' => 0, 'acknowledged' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
foreach ($statusStats as $row) {
    if (isset($statuses[$row['status']])) $statuses[$row['status']] = (int)$row['count'];
}

$registeredParksList = safeFetchAll($pdo, "
    SELECT id, name, park_type, park_code, buffer_radius,
           boundary_center_lat, boundary_center_lng
    FROM zones
    WHERE park_type IN ('national_park','gma') AND is_registered = 1
    LIMIT 6
");

// ============================================================
// HELPERS (guarded)
// ============================================================
if (!function_exists('ws_dash_sev_badge')) {
    function ws_dash_sev_badge(?string $sev): string {
        if (function_exists('getSeverityBadge')) return getSeverityBadge($sev);
        $sev = htmlspecialchars((string)$sev);
        return '<span class="threat-badge ' . $sev . '">' . strtoupper($sev) . '</span>';
    }
}
if (!function_exists('ws_dash_status_badge')) {
    function ws_dash_status_badge(?string $status): string {
        if (function_exists('getStatusBadge')) return getStatusBadge($status);
        $status = htmlspecialchars((string)$status);
        return '<span class="threat-badge low">' . strtoupper(str_replace('_',' ',$status)) . '</span>';
    }
}
if (!function_exists('ws_dash_cat_icon')) {
    function ws_dash_cat_icon(?string $cat): string {
        if (function_exists('getCategoryIcon')) return getCategoryIcon($cat);
        $map = ['poaching'=>'🎯','distressed_animal'=>'🦌','human_wildlife_conflict'=>'⚠️','environmental_risk'=>'🌍','other'=>'📌'];
        return $map[$cat] ?? '📌';
    }
}

// ============================================================
// FEATURE FLAGS
// ============================================================
$aiTablesAvailable    = ($totalCameras > 0 || count($recentDetections) > 0);
$alarmTablesAvailable = ($activeAlarms > 0 || count($activeAlarmList) > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Dashboard - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        .dashboard-greeting { margin-bottom: 24px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p { color: #6c757d; font-size: 16px; }

        /* Status banners */
        .status-banner {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px; border-radius: 10px;
            margin-bottom: 16px; font-size: 13px;
        }
        .status-banner.warn { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
        .status-banner.info { background: #eef7f1; border: 1px solid #c3e6cb; color: #155724; }
        .status-banner a { color: inherit; }
        .status-banner code { font-size: 11.5px; background: rgba(255,255,255,.6); padding: 1px 6px; border-radius: 4px; }

        /* Quick nav */
        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }
        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .heritage-banner { position: relative; height: 200px; border-radius: 16px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .heritage-banner .banner-slide { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-size: cover; background-position: center; opacity: 0; transition: opacity 1.5s ease-in-out; }
        .heritage-banner .banner-slide.active { opacity: 1; }
        .heritage-banner .banner-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(135deg, rgba(10,15,10,0.7) 0%, rgba(10,15,10,0.3) 50%, rgba(10,15,10,0.6) 100%); z-index: 1; }
        .heritage-banner .banner-content { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; height: 100%; padding: 24px 32px; color: white; }
        .heritage-banner .banner-content .left h2 { font-family: 'Playfair Display', serif; font-size: 28px; margin: 4px 0 8px; }
        .heritage-banner .banner-content .left .location { font-size: 12px; opacity: 0.7; letter-spacing: 2px; text-transform: uppercase; }
        .heritage-banner .banner-content .left .description { font-size: 13px; opacity: 0.8; max-width: 400px; }
        .heritage-banner .banner-content .right { text-align: center; padding: 10px 20px; background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); border-radius: 12px; border: 1px solid rgba(255,255,255,0.15); }
        .heritage-banner .banner-content .right .icon { font-size: 32px; display: block; }
        .heritage-banner .banner-content .right .tag { font-size: 10px; opacity: 0.7; text-transform: uppercase; letter-spacing: 1px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; text-decoration: none; color: inherit; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card.updated { animation: flash 1s ease; }
        @keyframes flash { 0%,100% { background: white; } 50% { background: #e8f5ec; } }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.green { background: #d4edda; color: #155724; }
        .stat-card .icon.blue { background: #cce5ff; color: #004085; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.red { background: #f8d7da; color: #721c24; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .icon.teal { background: #d1ecf1; color: #0c5460; }
        .stat-card .icon.pink { background: #fce4ec; color: #c62828; }
        .stat-card .icon.indigo { background: #e0e7ff; color: #3730a3; }
        .stat-card .icon.muted  { background: #e9ecef; color: #6c757d; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label { font-size: 11px; color: #6c757d; }
        .stat-card .info .sub   { font-size: 10px; color: #adb5bd; margin-top: 1px; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .section-header .view-all { color: #1a5c3a; text-decoration: none; font-size: 13px; font-weight: 500; }
        .section-header .view-all:hover { text-decoration: underline; }

        .two-col { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }

        .incident-item { display: flex; align-items: center; padding: 10px 14px; border-bottom: 1px solid #f0f0f0; gap: 12px; text-decoration: none; color: inherit; }
        .incident-item:last-child { border-bottom: none; }
        .incident-item:hover { background: #fafafa; }
        .incident-item .info { flex: 1; min-width: 0; }
        .incident-item .info .title { font-weight: 600; font-size: 14px; }
        .incident-item .info .meta { font-size: 12px; color: #6c757d; display: flex; gap: 12px; flex-wrap: wrap; margin-top: 2px; }
        .incident-item .badges { display: flex; gap: 6px; flex-shrink: 0; }

        .detection-item { display: flex; align-items: center; padding: 10px 14px; border-bottom: 1px solid #f0f0f0; gap: 12px; text-decoration: none; color: inherit; }
        .detection-item:last-child { border-bottom: none; }
        .detection-item:hover { background: #fafafa; }
        .detection-item .det-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .detection-item .det-info { flex: 1; }
        .detection-item .det-info .det-title { font-weight: 600; font-size: 13px; }
        .detection-item .det-info .det-meta { font-size: 11px; color: #6c757d; }
        .threat-badge { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .threat-badge.critical { background: #dc3545; color: white; animation: pulse 1.5s infinite; }
        .threat-badge.high { background: #f8d7da; color: #721c24; }
        .threat-badge.medium { background: #fff3cd; color: #856404; }
        .threat-badge.low { background: #d4edda; color: #155724; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }

        .activity-item { display: flex; align-items: center; padding: 8px 0; border-bottom: 1px solid #f0f0f0; gap: 10px; }
        .activity-item:last-child { border-bottom: none; }
        .activity-icon { width: 30px; height: 30px; border-radius: 50%; background: #f0f7f4; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
        .activity-text { flex: 1; font-size: 13px; }
        .activity-text .user { font-weight: 600; color: #0d3b22; }
        .activity-time { font-size: 11px; color: #adb5bd; }

        .alert-item { padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; border-left: 4px solid #ffc107; background: #fffdf5; }
        .alert-item.critical { border-left-color: #dc3545; background: #fdf5f5; }
        .alert-item .alert-icon { font-size: 20px; }
        .alert-item .alert-info { flex: 1; }
        .alert-item .alert-info .alert-title { font-weight: 600; font-size: 13px; }
        .alert-item .alert-info .alert-desc { font-size: 12px; color: #6c757d; }
        .alert-item .alert-time { font-size: 11px; color: #adb5bd; }

        .parks-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin-top: 10px; }
        .park-item { background: #f8f9fa; border-radius: 10px; padding: 12px 14px; text-align: center; border: 1px solid #e9ecef; transition: all 0.3s; text-decoration: none; color: inherit; }
        .park-item:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-color: #1a5c3a; }
        .park-item .park-icon { font-size: 24px; display: block; margin-bottom: 4px; }
        .park-item .park-name { font-size: 11px; font-weight: 600; color: #0d3b22; }
        .park-item .park-type { font-size: 9px; color: #6c757d; text-transform: uppercase; }
        .park-item .park-buffer { font-size: 9px; color: #4ade80; font-weight: 600; }

        .chart-container { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .chart-box { background: #f8f9fa; border-radius: 10px; padding: 14px 16px; }
        .chart-box h4 { font-size: 12px; color: #495057; margin-bottom: 10px; text-align: center; }
        .chart-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
        .chart-bar .bar-label { font-size: 11px; width: 70px; text-align: right; color: #6c757d; flex-shrink: 0; }
        .chart-bar .bar-track { flex: 1; height: 16px; background: #e9ecef; border-radius: 10px; overflow: hidden; }
        .chart-bar .bar-fill { height: 100%; border-radius: 10px; transition: width 0.8s ease; }
        .chart-bar .bar-fill.green { background: #28a745; }
        .chart-bar .bar-fill.blue { background: #007bff; }
        .chart-bar .bar-fill.orange { background: #ffc107; }
        .chart-bar .bar-fill.red { background: #dc3545; }
        .chart-bar .bar-fill.purple { background: #6f42c1; }
        .chart-bar .bar-count { font-size: 11px; font-weight: 600; width: 25px; color: #495057; text-align: center; }

        .empty-state { text-align:center; padding:16px; color:#6c757d; font-size:13px; }

        @media (max-width: 1024px) {
            .two-col { grid-template-columns: 1fr; }
            .chart-container { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .icon { width: 38px; height: 38px; font-size: 17px; }
            .stat-card .info .number { font-size: 18px; }
            .heritage-banner { height: 160px; }
            .heritage-banner .banner-content { padding: 16px 20px; flex-direction: column; justify-content: center; text-align: center; }
            .heritage-banner .banner-content .left h2 { font-size: 20px; }
            .heritage-banner .banner-content .left .description { font-size: 11px; max-width: 100%; }
            .heritage-banner .banner-content .right { display: none; }
            .chart-container { grid-template-columns: 1fr; }
            .parks-grid { grid-template-columns: 1fr 1fr; }
            .dashboard-greeting h1 { font-size: 22px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 10px 12px; }
            .stat-card .info .number { font-size: 16px; }
            .heritage-banner { height: 140px; }
            .parks-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Admin Dashboard</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>👋 Welcome back, <?= htmlspecialchars($user['full_name']) ?>!</h1>
                    <p>Here's what's happening in your wildlife protection network.</p>
                </div>

                <!-- Maintenance banner -->
                <?php if ($setMaintenanceMode): ?>
                    <div class="status-banner warn">
                        <span style="font-size:18px;">🚧</span>
                        <div>
                            <strong>Maintenance mode is ON.</strong>
                            Non-admin users are redirected to <code>maintenance.php</code>.
                            Message: "<?= htmlspecialchars($setMaintenanceMsg) ?>"
                            <a href="settings.php" style="color:inherit;text-decoration:underline;">Change</a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- AI disabled banner -->
                <?php if (!$setAiEnabled): ?>
                    <div class="status-banner warn">
                        <span style="font-size:18px;">⛔</span>
                        <div>
                            <strong>AI Detection is globally disabled.</strong>
                            Detections, alerts, and auto-triggered alarms are paused.
                            <a href="settings.php" style="color:inherit;text-decoration:underline;">Enable in System Settings</a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Notification state hint -->
                <?php if (!$setNotifyIncident || !$setNotifyAiAlert || !$setNotifyAlarm): ?>
                    <div class="status-banner warn">
                        <span style="font-size:18px;">🔕</span>
                        <div>
                            Notifications suppressed:
                            <?php if (!$setNotifyIncident): ?><strong>Incidents</strong><?php endif; ?>
                            <?php if (!$setNotifyAiAlert): ?><?= !$setNotifyIncident ? ', ' : '' ?><strong>AI alerts</strong><?php endif; ?>
                            <?php if (!$setNotifyAlarm): ?><?= (!$setNotifyIncident || !$setNotifyAiAlert) ? ', ' : '' ?><strong>Alarms</strong><?php endif; ?>
                            <a href="settings.php" style="color:inherit;text-decoration:underline;">Update settings</a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick nav -->
                <div class="quick-nav">
                    <a href="ai-dashboard.php" class="btn btn-secondary btn-sm">🤖 AI Dashboard</a>
                    <a href="incidents.php" class="btn btn-secondary btn-sm">📋 Incidents</a>
                    <a href="incidents.php?report=zone" class="btn btn-secondary btn-sm">🏛️ Zone Reports</a>
                    <a href="cctv-cameras.php" class="btn btn-secondary btn-sm">📹 Cameras</a>
                    <a href="alarm-systems.php" class="btn btn-secondary btn-sm">🔔 Alarms</a>
                    <a href="sms-gateway.php" class="btn btn-secondary btn-sm">📱 SMS</a>
                    <a href="simulation.php" class="btn btn-secondary btn-sm">🎮 Simulation</a>
                    <a href="settings.php" class="btn btn-secondary btn-sm">⚙️ Settings</a>
                </div>

                <!-- Heritage Slideshow Banner -->
                <div class="heritage-banner" id="heritageBanner">
                    <div class="banner-slide active" style="background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTUtWWebt__xADWWOadpUln6HrXozzeIAZEe5JVHEfAKA&s=10'); background-position: center 40%;"></div>
                    <div class="banner-slide" style="background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcS8zYC-UhhqKgRQnaG9QH8MMG6YJh3u-uBLyoxRo8nW7A&s=10'); background-position: center 35%;"></div>
                    <div class="banner-slide" style="background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTYJoFF6xk4spzjrRwze3LNwRMAu-CUmBHWepEZHcBUwQ&s=10'); background-position: center 30%;"></div>
                    <div class="banner-slide" style="background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTr-gQhTHK-0P59_Apn405B_o6YQdMlNYGn8nVAZvUSuA&s=10'); background-position: center 45%;"></div>
                    <div class="banner-slide" style="background-image: url('https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTQwZnwPstrR-36rpSKrI8pXwukkqiQhrEROJJz-ZVjow&s=10'); background-position: center 40%;"></div>

                    <div class="banner-overlay"></div>
                    <div class="banner-content">
                        <div class="left">
                            <div class="location" id="bannerLocation">📍 Livingstone, Zambia</div>
                            <h2 id="bannerName">Victoria Falls</h2>
                            <div class="description" id="bannerDesc">One of the Seven Natural Wonders of the World</div>
                        </div>
                        <div class="right">
                            <span class="icon" id="bannerIcon">🌊</span>
                            <span class="tag" id="bannerTag">UNESCO Heritage Site</span>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid (clickable) -->
                <div class="stats-grid">
                    <a class="stat-card" href="incidents.php" data-stat="totalIncidents">
                        <div class="icon green">🦁</div>
                        <div class="info">
                            <div class="number" id="statTotalIncidents"><?= $totalIncidents ?></div>
                            <div class="label">Total Incidents</div>
                        </div>
                    </a>
                    <a class="stat-card" href="incidents.php?status=reported" data-stat="activeIncidents">
                        <div class="icon red">🚨</div>
                        <div class="info">
                            <div class="number" id="statActiveIncidents"><?= $activeIncidents ?></div>
                            <div class="label">Active Incidents</div>
                        </div>
                    </a>
                    <a class="stat-card" href="incidents.php?status=reported" data-stat="unacknowledged">
                        <div class="icon orange">⏳</div>
                        <div class="info">
                            <div class="number" id="statUnack"><?= $unacknowledged ?></div>
                            <div class="label">Unacknowledged</div>
                        </div>
                    </a>
                    <a class="stat-card" href="users.php" data-stat="totalRangers">
                        <div class="icon blue">👤</div>
                        <div class="info">
                            <div class="number"><?= $totalRangers ?></div>
                            <div class="label">Active Rangers</div>
                        </div>
                    </a>
                    <a class="stat-card" href="cctv-cameras.php" data-stat="onlineCameras">
                        <div class="icon <?= $totalCameras === 0 ? 'muted' : 'purple' ?>">📹</div>
                        <div class="info">
                            <div class="number"><?= $onlineCameras ?>/<?= $totalCameras ?></div>
                            <div class="label">Cameras Online</div>
                        </div>
                    </a>
                    <a class="stat-card" href="ai-dashboard.php" data-stat="todayDetections">
                        <div class="icon <?= !$setAiEnabled ? 'muted' : 'teal' ?>">🤖</div>
                        <div class="info">
                            <div class="number" id="statDetections"><?= $todayDetections ?></div>
                            <div class="label">AI Detections Today</div>
                            <?php if (!$setAiEnabled): ?>
                                <div class="sub">⛔ AI paused</div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <a class="stat-card" href="ai-dashboard.php?threat=critical" data-stat="todayThreats">
                        <div class="icon <?= !$setAiEnabled ? 'muted' : 'pink' ?>">🚨</div>
                        <div class="info">
                            <div class="number" id="statThreats"><?= $todayThreats ?></div>
                            <div class="label">Threats Today</div>
                        </div>
                    </a>
                    <a class="stat-card" href="sms-gateway.php" data-stat="todaySMS">
                        <div class="icon indigo">📱</div>
                        <div class="info">
                            <div class="number" id="statSMS"><?= $todaySMS ?></div>
                            <div class="label">SMS Sent Today</div>
                        </div>
                    </a>
                </div>

                <!-- Two Column Layout -->
                <div class="two-col">
                    <!-- Left Column -->
                    <div>
                        <!-- AI Detections -->
                        <div class="section">
                            <div class="section-header">
                                <h2>🤖 Recent AI Detections</h2>
                                <a href="ai-dashboard.php" class="view-all">View All →</a>
                            </div>
                            <?php if (count($recentDetections) > 0): ?>
                                <?php foreach ($recentDetections as $detection): ?>
                                <a class="detection-item" href="ai-dashboard.php?dtype=<?= urlencode($detection['detection_type']) ?>">
                                    <div class="det-icon" style="background: <?= $detection['is_threat'] ? '#f8d7da' : '#d4edda' ?>;">
                                        <?php
                                        $icons = ['human'=>'👤','animal'=>'🦁','vehicle'=>'🚗','fire'=>'🔥','gunshot'=>'💥','motion'=>'📡','unknown'=>'❓'];
                                        echo $icons[$detection['detection_type']] ?? '📌';
                                        ?>
                                    </div>
                                    <div class="det-info">
                                        <div class="det-title"><?= htmlspecialchars(ucfirst($detection['detection_type'] ?? 'unknown')) ?> - <?= htmlspecialchars($detection['camera_name'] ?? 'Unknown camera') ?></div>
                                        <div class="det-meta">
                                            🕐 <?= timeAgo($detection['detected_at']) ?>
                                            • 🎯 <?= round(($detection['confidence'] ?? 0) * 100) ?>%
                                            • 📍 <?= htmlspecialchars($detection['zone_name'] ?? 'N/A') ?>
                                        </div>
                                    </div>
                                    <span class="threat-badge <?= $detection['is_threat'] ? ($detection['threat_level'] ?? 'medium') : 'low' ?>">
                                        <?= $detection['is_threat'] ? '🚨 ' . strtoupper($detection['threat_level'] ?? 'THREAT') : '✅ SAFE' ?>
                                    </span>
                                </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <p><?= $setAiEnabled ? 'No AI detections yet.' : '⛔ AI pipeline is paused.' ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Recent Incidents -->
                        <div class="section">
                            <div class="section-header">
                                <h2>📋 Recent Incidents</h2>
                                <a href="incidents.php" class="view-all">View All →</a>
                            </div>
                            <?php if (count($recentIncidents) > 0): ?>
                                <?php foreach ($recentIncidents as $incident): ?>
                                <a class="incident-item" href="incidents.php?id=<?= (int)$incident['id'] ?>">
                                    <div class="info">
                                        <div class="title">
                                            <?= ws_dash_cat_icon($incident['category']) ?>
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $incident['category']))) ?>
                                            <span style="font-weight:400;color:#6c757d;font-size:12px;">#<?= (int)$incident['id'] ?></span>
                                        </div>
                                        <div class="meta">
                                            <span>👤 <?= htmlspecialchars($incident['reporter_name'] ?? 'Unknown') ?></span>
                                            <span>📍 <?= htmlspecialchars($incident['zone_name'] ?? 'N/A') ?></span>
                                            <span>🕐 <?= timeAgo($incident['reported_at']) ?></span>
                                        </div>
                                    </div>
                                    <div class="badges">
                                        <?= ws_dash_status_badge($incident['status']) ?>
                                        <?= ws_dash_sev_badge($incident['severity']) ?>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><p>No incidents reported yet.</p></div>
                            <?php endif; ?>
                        </div>

                        <!-- Charts -->
                        <div class="section">
                            <div class="section-header"><h2>📊 Incident Statistics</h2></div>
                            <div class="chart-container">
                                <div class="chart-box">
                                    <h4>By Severity</h4>
                                    <?php
                                    $maxSeverity = 0;
                                    foreach ($severityStats as $s) { if ($s['count'] > $maxSeverity) $maxSeverity = $s['count']; }
                                    if ($maxSeverity == 0) $maxSeverity = 1;
                                    $severityColors = ['low'=>'green','medium'=>'blue','high'=>'orange','critical'=>'red'];
                                    $severityLabels = ['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'];
                                    ?>
                                    <?php if (count($severityStats) > 0): ?>
                                        <?php foreach ($severityStats as $stat): ?>
                                        <div class="chart-bar">
                                            <span class="bar-label"><?= $severityLabels[$stat['severity']] ?? htmlspecialchars($stat['severity']) ?></span>
                                            <div class="bar-track">
                                                <div class="bar-fill <?= $severityColors[$stat['severity']] ?? 'blue' ?>"
                                                     style="width: <?= ($stat['count'] / $maxSeverity) * 100 ?>%;"></div>
                                            </div>
                                            <span class="bar-count"><?= (int)$stat['count'] ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty-state"><p>No data.</p></div>
                                    <?php endif; ?>
                                </div>
                                <div class="chart-box">
                                    <h4>By Status</h4>
                                    <?php
                                    $maxStatus = max(array_merge($statuses, [1]));
                                    $statusColors = ['reported'=>'red','acknowledged'=>'orange','in_progress'=>'blue','resolved'=>'green','closed'=>'purple'];
                                    ?>
                                    <?php foreach ($statuses as $key => $count): ?>
                                    <div class="chart-bar">
                                        <span class="bar-label"><?= ucfirst(str_replace('_', ' ', $key)) ?></span>
                                        <div class="bar-track">
                                            <div class="bar-fill <?= $statusColors[$key] ?? 'blue' ?>"
                                                 style="width: <?= ($count / $maxStatus) * 100 ?>%;"></div>
                                        </div>
                                        <span class="bar-count"><?= $count ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div>
                        <!-- Active Alarms (always rendered) -->
                        <div class="section" style="border-left: 4px solid <?= $activeAlarms > 0 ? '#dc3545' : '#adb5bd' ?>;">
                            <div class="section-header">
                                <h2>🔔 Active Alarms</h2>
                                <?php if ($activeAlarms > 0): ?>
                                    <span class="threat-badge critical"><?= (int)$activeAlarms ?> ACTIVE</span>
                                <?php endif; ?>
                            </div>
                            <?php if (count($activeAlarmList) > 0): ?>
                                <?php foreach ($activeAlarmList as $alarm): ?>
                                <a class="alert-item critical" href="alarm-systems.php" style="text-decoration:none;color:inherit;">
                                    <div class="alert-icon">🔔</div>
                                    <div class="alert-info">
                                        <div class="alert-title"><?= htmlspecialchars($alarm['alarm_name'] ?? 'Unknown alarm') ?></div>
                                        <div class="alert-desc"><?= htmlspecialchars($alarm['zone_name'] ?? 'Unknown zone') ?> - <?= htmlspecialchars($alarm['trigger_reason'] ?? '') ?></div>
                                    </div>
                                    <div class="alert-time"><?= timeAgo($alarm['triggered_at'] ?? null) ?></div>
                                </a>
                                <?php endforeach; ?>
                                <div style="text-align:right;margin-top:8px;">
                                    <a href="alarm-systems.php" class="btn btn-secondary btn-sm" style="font-size:11px;padding:4px 10px;">⏹️ Stop alarms →</a>
                                </div>
                            <?php else: ?>
                                <div class="empty-state"><p>✅ No active alarms.</p></div>
                            <?php endif; ?>
                        </div>

                        <!-- AI Alerts -->
                        <div class="section">
                            <div class="section-header">
                                <h2>🚨 AI Alerts</h2>
                                <a href="ai-dashboard.php" class="view-all" style="font-size:12px;">View →</a>
                            </div>
                            <?php if (count($aiAlerts) > 0): ?>
                                <?php foreach ($aiAlerts as $alert): ?>
                                <a class="alert-item <?= ($alert['severity'] ?? '') === 'critical' ? 'critical' : '' ?>"
                                   href="ai-dashboard.php" style="text-decoration:none;color:inherit;">
                                    <div class="alert-icon"><?= ($alert['severity'] ?? '') === 'critical' ? '🚨' : '⚠️' ?></div>
                                    <div class="alert-info">
                                        <div class="alert-title"><?= htmlspecialchars($alert['title'] ?? 'Alert') ?></div>
                                        <div class="alert-desc"><?= htmlspecialchars($alert['zone_name'] ?? 'Unknown zone') ?></div>
                                    </div>
                                    <div class="alert-time"><?= timeAgo($alert['created_at'] ?? null) ?></div>
                                </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><p>✅ No pending AI alerts.</p></div>
                            <?php endif; ?>
                        </div>

                        <!-- Registered Parks -->
                        <div class="section">
                            <div class="section-header">
                                <h2>🏞️ Registered Parks</h2>
                                <a href="zones.php" class="view-all">Manage →</a>
                            </div>
                            <div class="parks-grid">
                                <?php if (count($registeredParksList) > 0): ?>
                                    <?php foreach ($registeredParksList as $park): ?>
                                    <a class="park-item" href="incidents.php?zone_id=<?= (int)$park['id'] ?>&report=zone">
                                        <span class="park-icon"><?= $park['park_type'] === 'national_park' ? '🏞️' : '🦁' ?></span>
                                        <div class="park-name"><?= htmlspecialchars(substr($park['name'], 0, 18)) ?></div>
                                        <div class="park-type"><?= htmlspecialchars(str_replace('_', ' ', $park['park_type'])) ?></div>
                                        <div class="park-buffer">📏 <?= (int)($park['buffer_radius'] ?? 500) ?>m</div>
                                    </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="grid-column:1/-1;text-align:center;color:#6c757d;padding:10px;">
                                        No parks registered yet.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="section">
                            <div class="section-header">
                                <h2>📋 Recent Activity</h2>
                                <a href="audit.php" class="view-all">View All →</a>
                            </div>
                            <?php if (count($recentActivity) > 0): ?>
                                <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <?php
                                        $actionIcons = [
                                            'login'=>'🔑','logout'=>'🚪','create_user'=>'👤',
                                            'update_user'=>'✏️','delete_user'=>'🗑️','create_zone'=>'🗺️',
                                            'update_zone'=>'📍','delete_zone'=>'🗑️','report_incident'=>'🚨',
                                            'acknowledge_incident'=>'✅','update_profile'=>'👤',
                                            'change_password'=>'🔒','register_zone'=>'🏛️',
                                            'create_camera'=>'📹','trigger_alarm'=>'🔔',
                                            'stop_alarm'=>'⏹️','acknowledge_ai_alert'=>'✅',
                                            'run_simulation'=>'🎮','broadcast_sms'=>'📢',
                                            'update_settings'=>'⚙️','update_ai_settings'=>'⚙️',
                                        ];
                                        echo $actionIcons[$activity['action']] ?? '📌';
                                        ?>
                                    </div>
                                    <div class="activity-text">
                                        <span class="user"><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></span>
                                        <?= htmlspecialchars(str_replace('_', ' ', $activity['action'])) ?>
                                    </div>
                                    <div class="activity-time"><?= timeAgo($activity['created_at']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><p>No recent activity.</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // ============================================================
        // BANNER SLIDESHOW
        // ============================================================
        const bannerData = [
            { icon: '🌊', name: 'Victoria Falls', location: '📍 Livingstone, Zambia', desc: 'One of the Seven Natural Wonders of the World', tag: 'UNESCO Heritage Site' },
            { icon: '🏞️', name: 'South Luangwa National Park', location: '📍 Eastern Province, Zambia', desc: 'One of Africa\'s greatest wildlife sanctuaries', tag: 'Premier Wildlife Destination' },
            { icon: '🦁', name: 'Kafue National Park', location: '📍 Central Zambia', desc: 'Zambia\'s largest national park, over 22,000 km²', tag: 'Largest Park in Zambia' },
            { icon: '🌿', name: 'Lower Zambezi National Park', location: '📍 Zambezi Valley, Zambia', desc: 'Pristine wilderness along the Zambezi River', tag: 'Zambezi Valley Wilderness' },
            { icon: '🦏', name: 'North Luangwa National Park', location: '📍 Northern Province, Zambia', desc: 'Remote wilderness known for rhino conservation', tag: 'Rhino Conservation Area' }
        ];

        let currentBanner = 0;
        const totalBanners = bannerData.length;

        function goToBanner(index) {
            document.querySelectorAll('.banner-slide').forEach((slide, i) => {
                slide.classList.toggle('active', i === index);
            });
            const data = bannerData[index];
            document.getElementById('bannerName').textContent = data.name;
            document.getElementById('bannerLocation').textContent = data.location;
            document.getElementById('bannerDesc').textContent = data.desc;
            document.getElementById('bannerIcon').textContent = data.icon;
            document.getElementById('bannerTag').textContent = data.tag;
            currentBanner = index;
        }
        setInterval(() => {
            if (!document.hidden) goToBanner((currentBanner + 1) % totalBanners);
        }, 5000);

        // ============================================================
        // LIVE STATS REFRESH (real JSON endpoint)
        // ============================================================
        const POLL_INTERVAL_MS = 30000;

        function flashStatCard(id) {
            const el = document.getElementById(id);
            if (!el) return;
            const card = el.closest('.stat-card');
            if (!card) return;
            card.classList.add('updated');
            setTimeout(() => card.classList.remove('updated'), 1000);
        }

        function updateNumber(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.textContent.trim() !== String(value)) {
                el.textContent = value;
                flashStatCard(id);
            }
        }

        async function refreshStats() {
            try {
                const res = await fetch('dashboard.php?ajax=stats', { cache: 'no-store', credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;

                updateNumber('statTotalIncidents', data.totalIncidents);
                updateNumber('statActiveIncidents', data.activeIncidents);
                updateNumber('statUnack', data.unacknowledged);
                updateNumber('statDetections', data.todayDetections);
                updateNumber('statThreats', data.todayThreats);
                updateNumber('statSMS', data.todaySMS);
            } catch (e) { /* silent */ }
        }

        setInterval(() => { if (!document.hidden) refreshStats(); }, POLL_INTERVAL_MS);

        console.log('✅ Admin Dashboard loaded — live stats refresh every ' + (POLL_INTERVAL_MS/1000) + 's');
    </script>
</body>
</html>
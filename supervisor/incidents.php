<?php
// ============================================================
// supervisor/incidents.php
// Zone Supervisor — Incidents & AI Alerts
// ============================================================
// Features:
//   - List all incidents in the supervisor's zone
//   - List pending AI alerts in the zone (respects ai_enabled)
//   - Filter by status / severity
//   - Assign a ranger (via api/incidents.php)
//   - View incident location on a map
//   - Track responding ranger route
//   - Photo lightbox
//   - Honors global settings:
//       ai_enabled, notify_on_incident, notify_on_ai_alert,
//       notify_on_alarm, items_per_page
// ============================================================

require_once '../includes/functions.php';
requireLogin();

if (!function_exists('hasRole') || !hasRole('zone_supervisor')) {
    header('Location: ../index.php');
    exit();
}

$user = getCurrentUser();
$pdo  = getDB();
$zoneId = (int)($user['zone_id'] ?? 0);

// ============================================================
// GLOBAL SETTINGS
// ============================================================
if (!function_exists('ws_sup_setting')) {
    function ws_sup_setting(string $key, $default = null) {
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

$setAiEnabled      = (string) ws_sup_setting('ai_enabled', '1')           === '1';
$setNotifyIncident = (string) ws_sup_setting('notify_on_incident', '1')   === '1';
$setNotifyAiAlert  = (string) ws_sup_setting('notify_on_ai_alert', '1')   === '1';
$setNotifyAlarm    = (string) ws_sup_setting('notify_on_alarm', '1')      === '1';

$itemsPerPage = (int) ws_sup_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;

// ============================================================
// HELPERS (guarded)
// ============================================================
if (!function_exists('ws_sup_sev_badge')) {
    function ws_sup_sev_badge(?string $sev): string {
        if (function_exists('getSeverityBadge')) return getSeverityBadge($sev);
        $sev = htmlspecialchars((string)$sev);
        return '<span class="badge sev-' . $sev . '">' . strtoupper($sev) . '</span>';
    }
}
if (!function_exists('ws_sup_status_badge')) {
    function ws_sup_status_badge(?string $status): string {
        if (function_exists('getStatusBadge')) return getStatusBadge($status);
        $status = htmlspecialchars((string)$status);
        return '<span class="badge status-' . $status . '">' . strtoupper(str_replace('_', ' ', $status)) . '</span>';
    }
}
if (!function_exists('ws_sup_cat_icon')) {
    function ws_sup_cat_icon(?string $cat): string {
        if (function_exists('getCategoryIcon')) return getCategoryIcon($cat);
        $map = ['poaching'=>'🎯','distressed_animal'=>'🦌','human_wildlife_conflict'=>'⚠️','environmental_risk'=>'🌍','other'=>'📌'];
        return $map[$cat] ?? '📌';
    }
}
if (!function_exists('ws_sup_safe_round')) {
    function ws_sup_safe_round($v, int $p = 5): string {
        return is_numeric($v) ? number_format((float)$v, $p) : '—';
    }
}

// ============================================================
// FILTERS (validated)
// ============================================================
$allowedStatuses   = ['reported','acknowledged','in_progress','resolved','closed'];
$allowedSeverities = ['low','medium','high','critical'];

$statusFilter   = in_array($_GET['status'] ?? '', $allowedStatuses, true)     ? (string)$_GET['status']   : '';
$severityFilter = in_array($_GET['severity'] ?? '', $allowedSeverities, true) ? (string)$_GET['severity'] : '';

// ============================================================
// FETCH INCIDENTS
// ============================================================
$incidents = [];
if ($zoneId > 0) {
    try {
        $sql = "
            SELECT i.*, u.full_name AS reporter_name, u.phone AS reporter_phone,
                   rlt.current_lat AS ranger_lat, rlt.current_lng AS ranger_lng
            FROM incidents i
            JOIN users u ON i.reporter_id = u.id
            LEFT JOIN ranger_live_tracking rlt ON i.acknowledged_by = rlt.ranger_id
            WHERE i.zone_id = ?
        ";
        $params = [$zoneId];

        if ($statusFilter !== '')   { $sql .= " AND i.status = ?";   $params[] = $statusFilter; }
        if ($severityFilter !== '') { $sql .= " AND i.severity = ?"; $params[] = $severityFilter; }

        $sql .= " ORDER BY
            CASE i.severity
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
                ELSE 5
            END, i.reported_at DESC
            LIMIT " . (int)$itemsPerPage;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $incidents = $stmt->fetchAll() ?: [];

        foreach ($incidents as &$incident) {
            if (!empty($incident['media_urls'])) {
                $decoded = json_decode($incident['media_urls'], true);
                $incident['media_urls'] = is_array($decoded) ? $decoded : [];
            } else {
                $incident['media_urls'] = [];
            }
        }
        unset($incident);
    } catch (PDOException $e) {
        error_log('[WS-SUP] incidents: ' . $e->getMessage());
    }
}

// ============================================================
// FETCH AI ALERTS (only if AI is enabled)
// ============================================================
$aiAlerts = [];
if ($setAiEnabled && $zoneId > 0) {
    try {
        $stmt = $pdo->prepare('
            SELECT a.*, c.camera_name
            FROM ai_alerts a
            LEFT JOIN ai_detections d ON a.detection_id = d.id
            LEFT JOIN cctv_cameras c ON d.camera_id = c.id
            WHERE a.zone_id = ? AND a.is_acknowledged = 0
            ORDER BY CASE a.severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, a.created_at DESC
            LIMIT 10
        ');
        $stmt->execute([$zoneId]);
        $aiAlerts = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('[WS-SUP] aiAlerts: ' . $e->getMessage());
    }
}

// ============================================================
// FETCH RANGERS IN ZONE
// ============================================================
$rangers = [];
if ($zoneId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name,
                   COALESCE(ra.is_available, 0) AS is_available,
                   rlt.current_lat, rlt.current_lng
            FROM users u
            LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
            LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
            WHERE u.zone_id = ? AND u.role = 'ranger' AND u.is_active = 1
            ORDER BY is_available DESC, u.full_name
        ");
        $stmt->execute([$zoneId]);
        $rangers = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('[WS-SUP] rangers: ' . $e->getMessage());
    }
}

// ============================================================
// STATS
// ============================================================
$stats = ['total'=>0,'reported'=>0,'acknowledged'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0];
if ($zoneId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'reported'     THEN 1 ELSE 0 END) AS reported,
                SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
                SUM(CASE WHEN status = 'in_progress'  THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN status = 'resolved'     THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN status = 'closed'       THEN 1 ELSE 0 END) AS closed
            FROM incidents WHERE zone_id = ?
        ");
        $stmt->execute([$zoneId]);
        $row = $stmt->fetch();
        if ($row) {
            foreach ($stats as $k => $_) {
                $stats[$k] = (int)($row[$k] ?? 0);
            }
        }
    } catch (PDOException $e) {
        error_log('[WS-SUP] stats: ' . $e->getMessage());
    }
}

$availableRangers = 0;
foreach ($rangers as $r) { if (!empty($r['is_available'])) $availableRangers++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zone Incidents - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.css" />
    <style>
        .stats-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .stat-chip { background: white; padding: 8px 16px; border-radius: 20px; border: 1px solid var(--gray-300, #dee2e6); font-size: 13px; }
        .stat-chip .num { font-weight: 700; color: var(--primary, #1a5c3a); }
        .stat-chip .num.danger { color: #dc3545; }
        .stat-chip .num.warning { color: #ffc107; }
        .stat-chip .num.success { color: #28a745; }
        .stat-chip .num.info { color: #17a2b8; }
        .stat-chip .num.purple { color: #6f42c1; }

        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }

        /* Settings echo banner */
        .state-banner {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: 10px;
            margin-bottom: 14px; font-size: 12.5px;
        }
        .state-banner.warn { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
        .state-banner.info { background: #eef7f1; border: 1px solid #c3e6cb; color: #155724; }
        .state-banner a { color: inherit; }
        .state-banner code { font-size: 11px; background: rgba(255,255,255,.6); padding: 1px 6px; border-radius: 4px; }

        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
        .filter-bar select { padding: 8px 12px; border: 1px solid var(--gray-300, #dee2e6); border-radius: 8px; font-size: 13px; }

        .incident-item { background: white; border: 1px solid #e9ecef; border-radius: 12px; padding: 16px 20px; margin-bottom: 12px; transition: all 0.3s; }
        .incident-item:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .incident-item.critical { border-left: 4px solid #dc3545; }
        .incident-item.high { border-left: 4px solid #fd7e14; }
        .incident-item.medium { border-left: 4px solid #ffc107; }
        .incident-item.low { border-left: 4px solid #28a745; }
        .incident-item.ai-alert { border-left: 4px solid #6f42c1; background: #f8f5ff; }

        .incident-header { display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 10px; }
        .incident-header h4 { margin: 0; font-size: 17px; }
        .incident-meta { display: flex; gap: 15px; flex-wrap: wrap; font-size: 13px; color: #6c757d; margin-top: 6px; }
        .incident-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }

        .ranger-assigned {
            background: #e7f5ff;
            padding: 8px 12px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .photo-thumbs { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; }
        .photo-thumb { width: 50px; height: 50px; border-radius: 6px; overflow: hidden; border: 2px solid #e9ecef; cursor: pointer; }
        .photo-thumb img { width: 100%; height: 100%; object-fit: cover; }

        /* Modal */
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 3000; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 16px; padding: 28px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; }
        .modal-header .close { background: none; border: none; font-size: 28px; cursor: pointer; }

        .ranger-option { padding: 12px 16px; border: 2px solid #e9ecef; border-radius: 10px; margin-bottom: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s; }
        .ranger-option:hover { border-color: #1a5c3a; background: #f0f7f4; }
        .ranger-option.selected { border-color: #1a5c3a; background: #d4edda; }
        .ranger-option.unavailable { opacity: 0.65; }

        #routeMap { height: 500px; width: 100%; }
        .map-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; z-index: 4000; padding: 20px; }
        .map-modal.show { display: flex; }
        .map-modal-content { background: white; border-radius: 16px; max-width: 900px; width: 100%; max-height: 90vh; overflow: hidden; }
        .map-modal-header { padding: 16px 22px; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; }

        .lightbox { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); display: none; align-items: center; justify-content: center; z-index: 5000; }
        .lightbox.show { display: flex; }
        .lightbox img { max-width: 90%; max-height: 90%; border-radius: 8px; }

        .loading-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 6000; align-items: center; justify-content: center;
            color: white; font-size: 14px; font-weight: 600;
            flex-direction: column; gap: 12px;
        }
        .loading-overlay.show { display: flex; }
        .loading-overlay .spinner {
            width: 50px; height: 50px;
            border: 4px solid rgba(255,255,255,0.2);
            border-top-color: #4ade80;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 768px) {
            .incident-item { padding: 14px 16px; }
            .incident-header h4 { font-size: 15px; }
            #routeMap { height: 350px; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Zone Incidents</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <!-- Quick nav -->
                <div class="quick-nav">
                    <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
                    <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                    <a href="rangers.php" class="btn btn-secondary">👥 Rangers</a>
                    <a href="manpower.php" class="btn btn-secondary">🆘 Manpower</a>
                </div>

                <!-- State banner -->
                <?php if (!$setAiEnabled || !$setNotifyIncident || !$setNotifyAlarm): ?>
                    <div class="state-banner warn">
                        <span style="font-size:18px;">ℹ️</span>
                        <div>
                            <?php if (!$setAiEnabled): ?>
                                <strong>AI detection is disabled</strong> by system settings.
                            <?php endif; ?>
                            <?php if (!$setNotifyIncident): ?>
                                <?= !$setAiEnabled ? ' • ' : '' ?><strong>Incident notifications</strong> are suppressed.
                            <?php endif; ?>
                            <?php if (!$setNotifyAlarm): ?>
                                <?= (!$setAiEnabled || !$setNotifyIncident) ? ' • ' : '' ?><strong>Alarm notifications</strong> are suppressed.
                            <?php endif; ?>
                            <a href="../admin/settings.php" style="color:inherit;text-decoration:underline;">View settings</a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-row">
                    <span class="stat-chip">📊 Total: <span class="num"><?= $stats['total'] ?></span></span>
                    <span class="stat-chip">🚨 Reported: <span class="num danger"><?= $stats['reported'] ?></span></span>
                    <span class="stat-chip">⏳ Acknowledged: <span class="num warning"><?= $stats['acknowledged'] ?></span></span>
                    <span class="stat-chip">🔄 In Progress: <span class="num info"><?= $stats['in_progress'] ?></span></span>
                    <span class="stat-chip">✅ Resolved: <span class="num success"><?= $stats['resolved'] ?></span></span>
                    <span class="stat-chip">👥 Rangers: <span class="num"><?= $availableRangers ?>/<?= count($rangers) ?></span></span>
                    <?php if ($setAiEnabled): ?>
                        <span class="stat-chip">🤖 AI Alerts: <span class="num purple"><?= count($aiAlerts) ?></span></span>
                    <?php endif; ?>
                </div>

                <!-- AI Alerts -->
                <?php if ($setAiEnabled && count($aiAlerts) > 0): ?>
                <div class="section" style="border-left: 4px solid #6f42c1;">
                    <div class="section-header">
                        <h2>🤖 AI Detected Threats (<?= count($aiAlerts) ?>)</h2>
                    </div>
                    <?php foreach ($aiAlerts as $alert): ?>
                    <div class="incident-item ai-alert">
                        <div class="incident-header">
                            <div>
                                <h4>🤖 <?= htmlspecialchars($alert['title'] ?? 'AI alert') ?></h4>
                                <div class="incident-meta">
                                    <span>📹 <?= htmlspecialchars($alert['camera_name'] ?? 'AI') ?></span>
                                    <span>📍 <?= ws_sup_safe_round($alert['location_lat']) ?>, <?= ws_sup_safe_round($alert['location_lng']) ?></span>
                                    <span>🕐 <?= timeAgo($alert['created_at'] ?? null) ?></span>
                                </div>
                                <?php if (!empty($alert['description'])): ?>
                                    <p style="font-size:13px;color:#6c757d;margin:8px 0;">
                                        <?= htmlspecialchars(mb_substr($alert['description'], 0, 120)) ?><?= mb_strlen($alert['description']) > 120 ? '…' : '' ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <?= ws_sup_sev_badge($alert['severity'] ?? 'medium') ?>
                        </div>
                        <div class="incident-actions">
                            <button class="btn-small btn-primary"
                                    data-alert-id="<?= (int)$alert['id'] ?>"
                                    data-lat="<?= htmlspecialchars((string)$alert['location_lat']) ?>"
                                    data-lng="<?= htmlspecialchars((string)$alert['location_lng']) ?>"
                                    onclick="assignRangerToAlert(this)">
                                👤 Assign Ranger
                            </button>
                            <button class="btn-small btn-info"
                                    data-lat="<?= htmlspecialchars((string)$alert['location_lat']) ?>"
                                    data-lng="<?= htmlspecialchars((string)$alert['location_lng']) ?>"
                                    data-title="<?= htmlspecialchars($alert['title'] ?? 'AI alert', ENT_QUOTES) ?>"
                                    onclick="showRouteFromBtn(this)">
                                🗺️ View on Map
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Filter -->
                <div class="filter-bar">
                    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="reported"     <?= $statusFilter === 'reported'     ? 'selected' : '' ?>>Reported</option>
                            <option value="acknowledged" <?= $statusFilter === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
                            <option value="in_progress"  <?= $statusFilter === 'in_progress'  ? 'selected' : '' ?>>In Progress</option>
                            <option value="resolved"     <?= $statusFilter === 'resolved'     ? 'selected' : '' ?>>Resolved</option>
                            <option value="closed"       <?= $statusFilter === 'closed'       ? 'selected' : '' ?>>Closed</option>
                        </select>
                        <select name="severity" onchange="this.form.submit()">
                            <option value="">All Severity</option>
                            <option value="critical" <?= $severityFilter === 'critical' ? 'selected' : '' ?>>Critical</option>
                            <option value="high"     <?= $severityFilter === 'high'     ? 'selected' : '' ?>>High</option>
                            <option value="medium"   <?= $severityFilter === 'medium'   ? 'selected' : '' ?>>Medium</option>
                            <option value="low"      <?= $severityFilter === 'low'      ? 'selected' : '' ?>>Low</option>
                        </select>
                        <button class="btn btn-primary btn-small" type="submit">Filter</button>
                        <a href="incidents.php" class="btn btn-secondary btn-small">Clear</a>
                    </form>
                </div>

                <!-- Incidents List -->
                <div class="section">
                    <h2>🚨 All Incidents (<?= count($incidents) ?>)</h2>

                    <?php if (count($incidents) > 0): ?>
                        <?php foreach ($incidents as $incident): ?>
                        <div class="incident-item <?= htmlspecialchars($incident['severity'] ?? 'low') ?>">
                            <div class="incident-header">
                                <div>
                                    <h4>
                                        <?= ws_sup_cat_icon($incident['category'] ?? null) ?>
                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $incident['category'] ?? 'other'))) ?>
                                        <span style="font-weight:400;color:#6c757d;font-size:13px;">#<?= (int)$incident['id'] ?></span>
                                    </h4>
                                    <div class="incident-meta">
                                        <span>👤 <?= htmlspecialchars($incident['reporter_name'] ?? 'Unknown') ?></span>
                                        <span>📞 <?= htmlspecialchars($incident['reporter_phone'] ?? 'N/A') ?></span>
                                        <span>📍 <?= ws_sup_safe_round($incident['location_lat']) ?>, <?= ws_sup_safe_round($incident['location_lng']) ?></span>
                                        <span>🕐 <?= timeAgo($incident['reported_at'] ?? null) ?></span>
                                    </div>
                                    <?php if (!empty($incident['description'])): ?>
                                        <p style="font-size:13px;color:#6c757d;margin:8px 0;">
                                            <?= htmlspecialchars(mb_substr($incident['description'], 0, 150)) ?><?= mb_strlen($incident['description']) > 150 ? '…' : '' ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (!empty($incident['media_urls'])): ?>
                                    <div class="photo-thumbs">
                                        <?php foreach (array_slice($incident['media_urls'], 0, 4) as $photo): ?>
                                        <div class="photo-thumb" onclick="openLightbox('../<?= htmlspecialchars($photo, ENT_QUOTES) ?>')">
                                            <img src="../<?= htmlspecialchars($photo, ENT_QUOTES) ?>" alt="Photo">
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($incident['acknowledged_by']) && !empty($incident['ranger_lat'])): ?>
                                    <div class="ranger-assigned">
                                        <strong>👤 Responding Ranger:</strong>
                                        <span>Location: <?= ws_sup_safe_round($incident['ranger_lat']) ?>, <?= ws_sup_safe_round($incident['ranger_lng']) ?></span>
                                        <button class="btn-small btn-info"
                                                data-rlat="<?= htmlspecialchars((string)$incident['ranger_lat']) ?>"
                                                data-rlng="<?= htmlspecialchars((string)$incident['ranger_lng']) ?>"
                                                data-dlat="<?= htmlspecialchars((string)$incident['location_lat']) ?>"
                                                data-dlng="<?= htmlspecialchars((string)$incident['location_lng']) ?>"
                                                onclick="showRangerRouteFromBtn(this)">
                                            🔄 Track Ranger
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
                                    <?= ws_sup_status_badge($incident['status'] ?? null) ?>
                                    <?= ws_sup_sev_badge($incident['severity'] ?? null) ?>
                                </div>
                            </div>

                            <div class="incident-actions">
                                <?php if (($incident['status'] ?? '') === 'reported'): ?>
                                    <button class="btn-small btn-primary" onclick="assignRanger(<?= (int)$incident['id'] ?>)">👤 Assign Ranger</button>
                                    <button class="btn-small btn-warning" onclick="acknowledgeIncident(<?= (int)$incident['id'] ?>)">✅ Acknowledge</button>
                                <?php endif; ?>
                                <button class="btn-small btn-info"
                                        data-lat="<?= htmlspecialchars((string)$incident['location_lat']) ?>"
                                        data-lng="<?= htmlspecialchars((string)$incident['location_lng']) ?>"
                                        data-title="Incident #<?= (int)$incident['id'] ?>"
                                        onclick="showRouteFromBtn(this)">
                                    🗺️ View on Map
                                </button>
                                <a href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode((string)$incident['location_lat']) ?>,<?= urlencode((string)$incident['location_lng']) ?>"
                                   target="_blank" rel="noopener" class="btn-small" style="background:#4285f4;color:white;">
                                    🧭 Navigate
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><p>No incidents match your filters.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Assign Ranger Modal -->
    <div class="modal" id="assignModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>👤 Assign Ranger</h3>
                <button class="close" onclick="closeModal('assignModal')">&times;</button>
            </div>
            <form id="assignForm">
                <input type="hidden" name="action" value="assign_ranger">
                <input type="hidden" name="incident_id" id="assignIncidentId">
                <input type="hidden" name="alert_id" id="assignAlertId">

                <?php if (count($rangers) > 0): ?>
                <div style="max-height:400px;overflow-y:auto;margin-bottom:15px;">
                    <?php foreach ($rangers as $r): ?>
                    <div class="ranger-option <?= empty($r['is_available']) ? 'unavailable' : '' ?>"
                         data-ranger-id="<?= (int)$r['id'] ?>"
                         onclick="selectRanger(this)">
                        <div>
                            <strong><?= htmlspecialchars($r['full_name']) ?></strong>
                            <div style="font-size:12px;color:#6c757d;">
                                <?= !empty($r['is_available']) ? '✅ Available' : '❌ Unavailable' ?>
                                <?php if (!empty($r['current_lat'])): ?>
                                    • 📍 <?= ws_sup_safe_round($r['current_lat'], 4) ?>, <?= ws_sup_safe_round($r['current_lng'], 4) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <input type="radio" name="ranger_id" value="<?= (int)$r['id'] ?>" style="display:none;">
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-block">📤 Assign Ranger</button>
                <?php else: ?>
                    <div class="empty-state" style="padding:20px;text-align:center;color:#6c757d;">
                        <p>No active rangers in this zone.</p>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Route Map Modal -->
    <div class="map-modal" id="mapModal">
        <div class="map-modal-content">
            <div class="map-modal-header">
                <h3 id="mapTitle">Route Map</h3>
                <button class="close" onclick="closeMapModal()" style="background:none;border:none;font-size:28px;cursor:pointer;">&times;</button>
            </div>
            <div id="routeMap"></div>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox" id="lightbox" onclick="closeLightbox()">
        <img id="lightboxImg" src="" alt="Photo">
    </div>

    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <div>Working…</div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine/dist/leaflet-routing-machine.min.js"></script>
    <script src="../assets/js/app.js"></script>
    <script>
        // ============================================================
        // ASSIGN RANGER
        // ============================================================
        function assignRanger(incidentId) {
            document.getElementById('assignIncidentId').value = incidentId;
            document.getElementById('assignAlertId').value = '';
            document.getElementById('assignModal').classList.add('show');
        }

        function assignRangerToAlert(btn) {
            const alertId = btn.getAttribute('data-alert-id');
            document.getElementById('assignIncidentId').value = '';
            document.getElementById('assignAlertId').value = alertId;
            document.getElementById('assignModal').classList.add('show');
        }

        function selectRanger(element) {
            document.querySelectorAll('.ranger-option').forEach(el => el.classList.remove('selected'));
            element.classList.add('selected');
            const radio = element.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        const assignForm = document.getElementById('assignForm');
        if (assignForm) {
            assignForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const incidentId = document.getElementById('assignIncidentId').value;
                const alertId    = document.getElementById('assignAlertId').value;
                const rangerId   = document.querySelector('input[name="ranger_id"]:checked')?.value;

                if (!rangerId) {
                    alert('Please select a ranger');
                    return;
                }

                const payload = { action: 'assign_ranger', ranger_id: rangerId };
                if (incidentId) payload.incident_id = incidentId;
                if (alertId)    payload.alert_id    = alertId;

                showLoading(true);
                fetch('../api/incidents.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                })
                .then(r => r.json())
                .then(data => {
                    showLoading(false);
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'Unknown'));
                    }
                })
                .catch(() => {
                    showLoading(false);
                    alert('Network error — please try again.');
                });
            });
        }

        // ============================================================
        // ACKNOWLEDGE
        // ============================================================
        function acknowledgeIncident(id) {
            if (!confirm('Acknowledge this incident?')) return;
            showLoading(true);
            fetch('../api/incidents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'acknowledge', incident_id: id })
            })
            .then(r => r.json())
            .then(data => {
                showLoading(false);
                if (data.success) location.reload();
                else alert('Error: ' + (data.error || 'Unknown'));
            })
            .catch(() => {
                showLoading(false);
                alert('Network error — please try again.');
            });
        }

        // ============================================================
        // MAP / ROUTING
        // ============================================================
        let routeMap = null;

        function showRouteFromBtn(btn) {
            const lat = parseFloat(btn.getAttribute('data-lat'));
            const lng = parseFloat(btn.getAttribute('data-lng'));
            const title = btn.getAttribute('data-title') || 'Location';
            if (!isFinite(lat) || !isFinite(lng)) return;
            showRoute(lat, lng, title);
        }

        function showRoute(destLat, destLng, title) {
            document.getElementById('mapTitle').textContent = title;
            document.getElementById('mapModal').classList.add('show');

            setTimeout(() => {
                if (routeMap) { routeMap.remove(); routeMap = null; }

                routeMap = L.map('routeMap').setView([destLat, destLng], 12);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(routeMap);

                L.marker([destLat, destLng]).addTo(routeMap).bindPopup(String(title)).openPopup();
                L.circle([destLat, destLng], {
                    radius: 200,
                    color: '#dc3545',
                    fillColor: '#dc3545',
                    fillOpacity: 0.2
                }).addTo(routeMap);
            }, 100);
        }

        function showRangerRouteFromBtn(btn) {
            const rlat = parseFloat(btn.getAttribute('data-rlat'));
            const rlng = parseFloat(btn.getAttribute('data-rlng'));
            const dlat = parseFloat(btn.getAttribute('data-dlat'));
            const dlng = parseFloat(btn.getAttribute('data-dlng'));
            if (![rlat, rlng, dlat, dlng].every(isFinite)) return;
            showRangerRoute(rlat, rlng, dlat, dlng);
        }

        function showRangerRoute(rangerLat, rangerLng, destLat, destLng) {
            document.getElementById('mapTitle').textContent = 'Ranger Tracking';
            document.getElementById('mapModal').classList.add('show');

            setTimeout(() => {
                if (routeMap) { routeMap.remove(); routeMap = null; }

                routeMap = L.map('routeMap').setView([destLat, destLng], 12);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(routeMap);

                L.marker([destLat, destLng], {
                    icon: L.divIcon({
                        html: '<div style="background:#dc3545;width:20px;height:20px;border-radius:50%;border:3px solid white;"></div>',
                        iconSize: [20, 20]
                    })
                }).addTo(routeMap).bindPopup('📍 Incident Location');

                L.marker([rangerLat, rangerLng], {
                    icon: L.divIcon({
                        html: '<div style="background:#28a745;width:20px;height:20px;border-radius:50%;border:3px solid white;"></div>',
                        iconSize: [20, 20]
                    })
                }).addTo(routeMap).bindPopup('👤 Ranger');

                try {
                    L.Routing.control({
                        waypoints: [L.latLng(rangerLat, rangerLng), L.latLng(destLat, destLng)],
                        addWaypoints: false,
                        fitSelectedRoutes: true,
                        lineOptions: { styles: [{ color: '#1a5c3a', weight: 5 }] }
                    }).addTo(routeMap);
                } catch (e) { /* routing lib may be blocked */ }
            }, 100);
        }

        function closeMapModal() {
            document.getElementById('mapModal').classList.remove('show');
            if (routeMap) { routeMap.remove(); routeMap = null; }
        }

        // ============================================================
        // LIGHTBOX
        // ============================================================
        function openLightbox(src) {
            document.getElementById('lightboxImg').src = src;
            document.getElementById('lightbox').classList.add('show');
        }
        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('show');
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeLightbox();
                closeMapModal();
                closeModal('assignModal');
            }
        });

        // ============================================================
        // LOADING
        // ============================================================
        function showLoading(on) {
            document.getElementById('loadingOverlay').classList.toggle('show', !!on);
        }

        console.log('✅ Supervisor Incidents loaded');
    </script>
</body>
</html>
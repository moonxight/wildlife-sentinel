<?php
// ============================================================
// ranger/incidents.php
// Ranger — Incident List with Photos + Turn-by-Turn Navigation
// ------------------------------------------------------------
// Features:
//   - List zone incidents + my assigned incidents
//   - View photos submitted by scouts/tourism
//   - View location on a map
//   - Live turn-by-turn navigation with:
//       • Voice guidance (Web Speech API)
//       • Distance countdown + upcoming turn announcements
//       • Auto-recalculation when off-route
//       • Alternative route suggestions
//       • Arrival detection
//       • Screen wake lock during navigation
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = getCurrentUser();
if (!$user || $user['role'] !== 'ranger') {
    header('Location: ../index.php');
    exit();
}

$pdo    = getDB();
$zoneId = (int)($user['zone_id'] ?? 0);

// ============================================================
// GLOBAL SETTINGS
// ============================================================
if (!function_exists('ws_ranger_setting')) {
    function ws_ranger_setting(string $key, $default = null) {
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

$setNotifyIncident = (string) ws_ranger_setting('notify_on_incident', '1') === '1';
$setNotifyAlarm    = (string) ws_ranger_setting('notify_on_alarm', '1')    === '1';

// ============================================================
// NOTIFICATION GATE
// ============================================================
if (!function_exists('ws_ranger_notify')) {
    function ws_ranger_notify(int $userId, string $type, string $title, string $body, ?int $incidentId = null): bool {
        $map = [
            'acknowledged'  => 'notify_on_incident',
            'status_update' => 'notify_on_incident',
            'new_incident'  => 'notify_on_incident',
            'alarm'         => 'notify_on_alarm',
        ];
        $key = $map[$type] ?? null;
        if ($key !== null && (string) ws_ranger_setting($key, '1') !== '1') {
            return false;
        }
        if (!function_exists('createNotification')) return false;
        try {
            createNotification($userId, $type, $title, $body, $incidentId);
            return true;
        } catch (Throwable $e) {
            error_log('[WS-RANGER] notify failed: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================
// SAFE HELPERS
// ============================================================
if (!function_exists('safeCount')) {
    function safeCount(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)($stmt->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}
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
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- ACKNOWLEDGE --------
    if ($action === 'acknowledge') {
        $id = (int)($_POST['incident_id'] ?? 0);

        $inc = safeFetchAll($pdo, "
            SELECT id, status, reporter_id FROM incidents
            WHERE id = ? AND zone_id = ? AND status = 'reported'
        ", [$id, $zoneId]);

        if (!$inc) {
            $message = 'Incident not found or already acknowledged.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->prepare("
                    UPDATE incidents
                    SET status = 'acknowledged',
                        acknowledged_by = ?,
                        acknowledged_at = NOW()
                    WHERE id = ? AND zone_id = ? AND status = 'reported'
                ")->execute([$user['id'], $id, $zoneId]);

                if (function_exists('updateRangerAvailability')) {
                    updateRangerAvailability($user['id'], false, $id);
                }
                $pdo->prepare("UPDATE users SET is_on_duty = 1 WHERE id = ?")->execute([$user['id']]);

                if (!empty($inc[0]['reporter_id'])) {
                    ws_ranger_notify(
                        (int)$inc[0]['reporter_id'],
                        'acknowledged',
                        '✅ Incident Acknowledged',
                        "Ranger {$user['full_name']} is responding to your report.",
                        $id
                    );
                }

                $supervisors = safeFetchAll($pdo, "
                    SELECT id FROM users
                    WHERE zone_id = ? AND role = 'zone_supervisor' AND is_active = 1
                ", [$zoneId]);
                foreach ($supervisors as $s) {
                    ws_ranger_notify(
                        (int)$s['id'],
                        'status_update',
                        '📍 Ranger Responding',
                        "Ranger {$user['full_name']} is responding to incident #{$id}.",
                        $id
                    );
                }

                logAudit($user['id'], 'acknowledge_incident', ['incident_id' => $id]);
                $message = "Incident #{$id} acknowledged. You are now the responder.";
            } catch (PDOException $e) {
                error_log('[WS-RANGER] acknowledge failed: ' . $e->getMessage());
                $message = 'Could not acknowledge the incident. Please try again.';
                $messageType = 'danger';
            }
        }
    }

    // -------- UPDATE STATUS --------
    if ($action === 'update_status') {
        $id     = (int)($_POST['incident_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes  = trim((string)($_POST['notes'] ?? ''));

        if (!in_array($status, ['in_progress', 'resolved'], true)) {
            $message = 'Invalid status.';
            $messageType = 'danger';
        } else {
            try {
                $extra = $status === 'resolved' ? ", resolved_at = NOW()" : "";
                $pdo->prepare("
                    UPDATE incidents
                    SET status = ?{$extra}
                    WHERE id = ? AND acknowledged_by = ?
                ")->execute([$status, $id, $user['id']]);

                try {
                    $pdo->prepare("
                        INSERT INTO incident_responses
                            (incident_id, ranger_id, status_update, notes, created_at)
                        VALUES (?, ?, ?, ?, NOW())
                    ")->execute([
                        $id,
                        $user['id'],
                        $status === 'resolved' ? 'resolved' : 'investigating',
                        $notes !== '' ? $notes : null,
                    ]);
                } catch (PDOException $e) { /* optional table */ }

                if ($status === 'resolved') {
                    if (function_exists('updateRangerAvailability')) {
                        updateRangerAvailability($user['id'], true, null);
                    }
                    $pdo->prepare("UPDATE users SET is_on_duty = 0 WHERE id = ?")->execute([$user['id']]);
                }

                logAudit($user['id'], 'update_incident_status', ['incident_id' => $id, 'status' => $status]);
                $message = "Incident #{$id} marked as " . str_replace('_', ' ', $status) . ".";
            } catch (PDOException $e) {
                error_log('[WS-RANGER] status update failed: ' . $e->getMessage());
                $message = 'Could not update status. Please try again.';
                $messageType = 'danger';
            }
        }
    }
}

// ============================================================
// FETCH INCIDENTS (with filters)
// ============================================================
$filter   = in_array($_GET['filter'] ?? 'zone', ['zone','mine','resolved'], true) ? $_GET['filter'] : 'zone';
$severity = in_array($_GET['severity'] ?? '', ['low','medium','high','critical'], true) ? $_GET['severity'] : '';
$search   = trim((string)($_GET['search'] ?? ''));

$where  = " WHERE i.zone_id = ? ";
$params = [$zoneId];

if ($filter === 'mine') {
    $where .= " AND i.acknowledged_by = ? ";
    $params[] = $user['id'];
    $where .= " AND i.status IN ('acknowledged','in_progress') ";
} elseif ($filter === 'resolved') {
    $where .= " AND i.acknowledged_by = ? AND i.status = 'resolved' ";
    $params[] = $user['id'];
} else {
    $where .= " AND i.status NOT IN ('resolved','closed') ";
}

if ($severity !== '') {
    $where .= " AND i.severity = ? ";
    $params[] = $severity;
}
if ($search !== '') {
    $where .= " AND (i.description LIKE ? OR i.category LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$incidents = safeFetchAll($pdo, "
    SELECT i.*,
           u.full_name AS reporter_name,
           u.phone AS reporter_phone,
           u.role AS reporter_role,
           r.full_name AS responder_name,
           z.name AS zone_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    LEFT JOIN users r ON i.acknowledged_by = r.id
    LEFT JOIN zones z ON i.zone_id = z.id
    $where
    ORDER BY CASE i.severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 0 END, i.reported_at DESC
    LIMIT 200
", $params);

foreach ($incidents as &$inc) {
    $inc['media_list'] = [];
    if (!empty($inc['media_urls'])) {
        $decoded = is_string($inc['media_urls']) ? json_decode($inc['media_urls'], true) : $inc['media_urls'];
        if (is_array($decoded)) $inc['media_list'] = $decoded;
    }
}
unset($inc);

// ============================================================
// STATS
// ============================================================
$stats = [
    'zone_open'     => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND status = 'reported'", [$zoneId]),
    'mine_active'   => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE acknowledged_by = ? AND status IN ('acknowledged','in_progress')", [$user['id']]),
    'mine_resolved' => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE acknowledged_by = ? AND status = 'resolved'", [$user['id']]),
    'critical'      => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND severity = 'critical' AND status NOT IN ('resolved','closed')", [$zoneId]),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Incidents - Ranger - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 22px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p { color: #6c757d; font-size: 15px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
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
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        .filter-tabs { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
        .filter-tab { padding: 8px 16px; border-radius: 20px; background: #f0f0f0; color: #495057; text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .filter-tab:hover { background: #e0e0e0; }
        .filter-tab.active { background: #1a5c3a; color: white; }

        .filter-bar { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .filter-group input, .filter-group select { padding: 9px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 13px; background: #fafafa; }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        .incident-card {
            border-radius: 12px; padding: 16px 18px;
            margin-bottom: 12px; background: white;
            border: 1px solid #f0f0f0;
            border-left: 5px solid #ffc107;
            transition: all 0.2s;
        }
        .incident-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
        .incident-card.critical { border-left-color: #dc3545; background: #fffafa; }
        .incident-card.high     { border-left-color: #fd7e14; }
        .incident-card.medium   { border-left-color: #ffc107; }
        .incident-card.low      { border-left-color: #28a745; }

        .incident-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
        .incident-title { font-weight: 700; font-size: 15px; color: #0d3b22; display: flex; align-items: center; gap: 8px; }
        .incident-meta  { font-size: 12px; color: #6c757d; margin-top: 3px; }

        .sev-pill { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .sev-pill.critical { background: #dc3545; color: white; animation: pulse 1.5s infinite; }
        .sev-pill.high     { background: #fff3cd; color: #856404; }
        .sev-pill.medium   { background: #fff3cd; color: #856404; }
        .sev-pill.low      { background: #d4edda; color: #155724; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }

        .status-pill { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .status-pill.reported     { background: #cce5ff; color: #004085; }
        .status-pill.acknowledged { background: #fff3cd; color: #856404; }
        .status-pill.in_progress  { background: #d1ecf1; color: #0c5460; }
        .status-pill.resolved     { background: #d4edda; color: #155724; }

        .incident-body { background: #fafafa; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #495057; margin: 10px 0; }

        .photo-strip { display: flex; gap: 8px; margin: 10px 0; overflow-x: auto; padding-bottom: 4px; }
        .photo-thumb {
            width: 90px; height: 90px;
            border-radius: 8px;
            background-size: cover; background-position: center;
            flex-shrink: 0; cursor: pointer;
            border: 2px solid #e0e0e0;
            transition: all 0.2s;
            position: relative;
        }
        .photo-thumb:hover { transform: scale(1.05); border-color: #1a5c3a; }

        .incident-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }

        /* Lightbox */
        .lightbox-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 5000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .lightbox-backdrop.show { display: flex; }
        .lightbox-img { max-width: 100%; max-height: 90vh; border-radius: 8px; box-shadow: 0 10px 60px rgba(0,0,0,0.6); }
        .lightbox-close { position: absolute; top: 20px; right: 24px; background: rgba(255,255,255,0.15); color: white; border: none; width: 44px; height: 44px; border-radius: 50%; font-size: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .lightbox-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15); color: white; border: none; width: 50px; height: 50px; border-radius: 50%; font-size: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .lightbox-prev { left: 20px; } .lightbox-next { right: 20px; }
        .lightbox-counter { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.6); color: white; padding: 6px 16px; border-radius: 20px; font-size: 13px; }

        /* Modals */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 4000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-backdrop.show { display: flex; }
        .modal { background: white; border-radius: 14px; max-width: 900px; width: 100%; max-height: 92vh; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.4); display: flex; flex-direction: column; }
        .modal-header { padding: 16px 22px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0; }
        .modal-header h3 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .modal-close { background: none; border: none; font-size: 26px; cursor: pointer; color: #6c757d; }
        .modal-body { padding: 20px 22px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 14px 22px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0; flex-wrap: wrap; }

        #incidentMap { height: 380px; border-radius: 12px; background: #e0e0e0; }
        #navMap { height: 380px; border-radius: 12px; background: #e0e0e0; }

        /* Navigation HUD */
        .nav-hud {
            background: linear-gradient(135deg, #0d3b22 0%, #1a5c3a 100%);
            color: white;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .nav-hud-top {
            display: flex; align-items: center; gap: 16px;
            margin-bottom: 10px;
        }
        .nav-hud .turn-icon {
            width: 60px; height: 60px;
            background: rgba(255,255,255,0.15);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 34px;
            flex-shrink: 0;
        }
        .nav-hud .turn-info { flex: 1; min-width: 0; }
        .nav-hud .turn-distance {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }
        .nav-hud .turn-instruction {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 3px;
            line-height: 1.35;
        }
        .nav-hud-bottom {
            display: flex; justify-content: space-between; align-items: center;
            gap: 10px; flex-wrap: wrap;
            padding-top: 10px;
            border-top: 1px solid rgba(255,255,255,0.15);
            font-size: 13px;
        }
        .nav-hud-bottom .eta { font-weight: 700; }
        .nav-hud-bottom .stat { opacity: 0.85; }

        .nav-voice-btn {
            background: rgba(255,255,255,0.15);
            border: none; color: white;
            width: 40px; height: 40px;
            border-radius: 10px;
            cursor: pointer; font-size: 18px;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s;
        }
        .nav-voice-btn.muted { background: rgba(220,53,69,0.4); }
        .nav-voice-btn:hover { background: rgba(255,255,255,0.25); }

        .nav-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: rgba(255,255,255,0.15);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .nav-badge.warn { background: rgba(255,193,7,0.3); }

        .nav-next-steps {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 14px;
            margin-top: 10px;
            font-size: 12.5px;
            color: #495057;
        }
        .nav-next-steps .step {
            display: flex; align-items: center; gap: 8px;
            padding: 5px 0;
            border-bottom: 1px dashed #e0e0e0;
        }
        .nav-next-steps .step:last-child { border-bottom: none; }
        .nav-next-steps .step .ico { width: 24px; text-align: center; font-size: 15px; }
        .nav-next-steps .step .dist { font-weight: 700; color: #0d3b22; min-width: 60px; }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .filter-bar { grid-template-columns: 1fr; }
            .modal { max-width: 100%; max-height: 95vh; }
            #incidentMap, #navMap { height: 260px; }
            .nav-hud .turn-icon { width: 50px; height: 50px; font-size: 28px; }
            .nav-hud .turn-distance { font-size: 22px; }
            .nav-hud .turn-instruction { font-size: 12.5px; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Incidents</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🚨 Incidents</h1>
                    <p>Reports from scouts and tourism operators in your zone. View photos, location, and start live navigation with voice guidance.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon red">🚨</div><div class="info"><div class="number"><?= (int)$stats['zone_open'] ?></div><div class="label">Open in Zone</div></div></div>
                    <div class="stat-card"><div class="icon orange">⏳</div><div class="info"><div class="number"><?= (int)$stats['mine_active'] ?></div><div class="label">My Active</div></div></div>
                    <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= (int)$stats['mine_resolved'] ?></div><div class="label">My Resolved</div></div></div>
                    <div class="stat-card"><div class="icon blue">⚠️</div><div class="info"><div class="number"><?= (int)$stats['critical'] ?></div><div class="label">Critical</div></div></div>
                </div>

                <!-- Filters -->
                <div class="section">
                    <div class="section-header"><h2>🔎 Filter Incidents</h2></div>

                    <div class="filter-tabs">
                        <a href="?filter=zone"     class="filter-tab <?= $filter === 'zone'     ? 'active' : '' ?>">📋 Zone Open</a>
                        <a href="?filter=mine"     class="filter-tab <?= $filter === 'mine'     ? 'active' : '' ?>">🙋 My Active</a>
                        <a href="?filter=resolved" class="filter-tab <?= $filter === 'resolved' ? 'active' : '' ?>">✅ My Resolved</a>
                    </div>

                    <form method="GET" class="filter-bar">
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                        <div class="filter-group">
                            <label>Severity</label>
                            <select name="severity">
                                <option value="">All Severities</option>
                                <option value="critical" <?= $severity === 'critical' ? 'selected' : '' ?>>Critical</option>
                                <option value="high"     <?= $severity === 'high'     ? 'selected' : '' ?>>High</option>
                                <option value="medium"   <?= $severity === 'medium'   ? 'selected' : '' ?>>Medium</option>
                                <option value="low"      <?= $severity === 'low'      ? 'selected' : '' ?>>Low</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Description or category">
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">🔎 Apply</button>
                        </div>
                    </form>
                </div>

                <!-- Incidents list -->
                <div class="section">
                    <div class="section-header">
                        <h2>📋 Incidents (<?= count($incidents) ?>)</h2>
                    </div>

                    <?php if (count($incidents) > 0): ?>
                        <?php foreach ($incidents as $inc): ?>
                            <?php
                            $lat    = (float)$inc['location_lat'];
                            $lng    = (float)$inc['location_lng'];
                            $photos = $inc['media_list'];
                            $photoUrls = array_map(fn($p) => '../' . ltrim($p, '/'), $photos);
                            ?>
                            <div class="incident-card <?= htmlspecialchars($inc['severity']) ?>" id="inc-<?= (int)$inc['id'] ?>">
                                <div class="incident-header">
                                    <div>
                                        <div class="incident-title">
                                            <?= function_exists('getCategoryIcon') ? getCategoryIcon($inc['category']) : '🚨' ?>
                                            <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $inc['category']))) ?>
                                            <span style="font-weight:400;color:#6c757d;font-size:12px;">#<?= (int)$inc['id'] ?></span>
                                        </div>
                                        <div class="incident-meta">
                                            👤 <?= htmlspecialchars($inc['reporter_name'] ?? 'N/A') ?>
                                            <?php if ($inc['reporter_role']): ?>
                                                <span style="background:#e8f5e9;color:#1a5c3a;padding:1px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;">
                                                    <?= htmlspecialchars($inc['reporter_role']) ?>
                                                </span>
                                            <?php endif; ?>
                                            • 📞 <?= htmlspecialchars($inc['reporter_phone'] ?? 'N/A') ?>
                                            • 🕐 <?= timeAgo($inc['reported_at']) ?>
                                            <?php if ($inc['responder_name']): ?>
                                                • 🛡️ <?= htmlspecialchars($inc['responder_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="incident-meta">
                                            📍 <?= number_format($lat, 5) ?>, <?= number_format($lng, 5) ?>
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <span class="sev-pill <?= htmlspecialchars($inc['severity']) ?>"><?= strtoupper(htmlspecialchars($inc['severity'])) ?></span>
                                        <span class="status-pill <?= htmlspecialchars($inc['status']) ?>"><?= strtoupper(str_replace('_', ' ', htmlspecialchars($inc['status']))) ?></span>
                                    </div>
                                </div>

                                <?php if ($inc['description']): ?>
                                    <div class="incident-body"><?= htmlspecialchars($inc['description']) ?></div>
                                <?php endif; ?>

                                <?php if (count($photos) > 0): ?>
                                    <div style="margin-top:8px;">
                                        <div style="font-size:12px;color:#6c757d;font-weight:600;margin-bottom:6px;">
                                            📷 <?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?> attached — tap to view
                                        </div>
                                        <div class="photo-strip">
                                            <?php foreach ($photoUrls as $idx => $url): ?>
                                                <div class="photo-thumb"
                                                     style="background-image: url('<?= htmlspecialchars($url, ENT_QUOTES) ?>');"
                                                     onclick='openLightbox(<?= json_encode($photoUrls, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, <?= (int)$idx ?>)'>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="incident-actions">
                                    <button class="btn btn-sm btn-info"
                                            data-incident='<?= htmlspecialchars(json_encode([
                                                'id' => (int)$inc['id'],
                                                'category' => $inc['category'],
                                                'severity' => $inc['severity'],
                                                'lat' => $lat,
                                                'lng' => $lng,
                                                'reporter' => $inc['reporter_name'],
                                                'zone' => $inc['zone_name'],
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>'
                                            onclick="showLocationModalFromBtn(this)">
                                        📍 View Location
                                    </button>

                                    <?php if (count($photos) > 0): ?>
                                        <button class="btn btn-sm btn-secondary"
                                                onclick='openLightbox(<?= json_encode($photoUrls, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>, 0)'>
                                            📷 View Photos (<?= count($photos) ?>)
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($inc['status'] === 'reported'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="acknowledge">
                                            <input type="hidden" name="incident_id" value="<?= (int)$inc['id'] ?>">
                                            <button class="btn btn-sm btn-primary" onclick="return confirm('Acknowledge and respond to this incident?')">
                                                🛡️ Acknowledge & Respond
                                            </button>
                                        </form>
                                    <?php elseif ((int)$inc['acknowledged_by'] === (int)$user['id']): ?>
                                        <?php if ($inc['status'] !== 'in_progress' && $inc['status'] !== 'resolved'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="incident_id" value="<?= (int)$inc['id'] ?>">
                                                <input type="hidden" name="status" value="in_progress">
                                                <button class="btn btn-sm btn-warning">🔄 Mark In Progress</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($inc['status'] !== 'resolved'): ?>
                                            <button class="btn btn-sm btn-success" onclick="openResolveModal(<?= (int)$inc['id'] ?>)">
                                                ✅ Mark Resolved
                                            </button>
                                        <?php endif; ?>

                                        <button class="btn btn-sm btn-danger"
                                                data-nav='<?= htmlspecialchars(json_encode([
                                                    'id' => (int)$inc['id'],
                                                    'lat' => $lat,
                                                    'lng' => $lng,
                                                    'category' => $inc['category'],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>'
                                                onclick="startNavigationFromBtn(this)">
                                            🧭 Start Navigation
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">🌿</span>
                            <h3 style="font-size:15px;color:#495057;">No incidents found</h3>
                            <p>Try changing the filters or check back later.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- LIGHTBOX -->
    <div class="lightbox-backdrop" id="lightbox" onclick="if(event.target===this) closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()">×</button>
        <button class="lightbox-nav lightbox-prev" onclick="lightboxNav(-1); event.stopPropagation();">‹</button>
        <img src="" class="lightbox-img" id="lightboxImg" onclick="event.stopPropagation()" alt="Incident photo">
        <button class="lightbox-nav lightbox-next" onclick="lightboxNav(1); event.stopPropagation();">›</button>
        <div class="lightbox-counter" id="lightboxCounter">1 / 1</div>
    </div>

    <!-- LOCATION MODAL -->
    <div class="modal-backdrop" id="locationModal">
        <div class="modal">
            <div class="modal-header">
                <h3 id="locationModalTitle">📍 Incident Location</h3>
                <button class="modal-close" onclick="closeLocationModal()">×</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:10px;">
                    <div id="locationMeta" style="font-size:13px;color:#495057;"></div>
                </div>
                <div id="incidentMap"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeLocationModal()">Close</button>
                <button class="btn btn-primary" id="locationNavBtn">🧭 Start Navigation</button>
            </div>
        </div>
    </div>

    <!-- NAVIGATION MODAL -->
    <div class="modal-backdrop" id="navModal">
        <div class="modal">
            <div class="modal-header">
                <h3>
                    🧭 Live Navigation
                    <span class="nav-badge" id="navVoiceBadge">🔊 Voice on</span>
                </h3>
                <button class="modal-close" onclick="closeNavModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="nav-hud" id="navHud">
                    <div class="nav-hud-top">
                        <div class="turn-icon" id="navTurnIcon">⬆️</div>
                        <div class="turn-info">
                            <div class="turn-distance" id="navTurnDistance">Calculating…</div>
                            <div class="turn-instruction" id="navInstruction">Getting your location…</div>
                        </div>
                        <button class="nav-voice-btn" id="navVoiceBtn" onclick="toggleVoice()" title="Toggle voice guidance">🔊</button>
                    </div>
                    <div class="nav-hud-bottom">
                        <span class="eta" id="navEta">—</span>
                        <span class="stat" id="navRemaining">—</span>
                        <span class="stat" id="navSpeed">—</span>
                    </div>
                </div>

                <div id="navMap"></div>

                <div class="nav-next-steps" id="navNextSteps" style="display:none;">
                    <div style="font-weight:700;color:#0d3b22;margin-bottom:6px;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">📋 Upcoming Steps</div>
                    <div id="navStepsList"></div>
                </div>

                <div style="font-size:11px;color:#6c757d;margin-top:8px;">
                    ℹ️ Live GPS navigation. Keep this page open and allow location access. Voice guidance uses your device's speaker.
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="recalculateRoute()">🔄 Recalculate</button>
                <button class="btn btn-danger" onclick="stopNavigation()">⏹️ Stop Navigation</button>
                <button class="btn btn-secondary" onclick="closeNavModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- RESOLVE MODAL -->
    <div class="modal-backdrop" id="resolveModal">
        <div class="modal" style="max-width:520px;">
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="status" value="resolved">
                <input type="hidden" name="incident_id" id="resolveIncidentId">

                <div class="modal-header">
                    <h3>✅ Mark as Resolved</h3>
                    <button type="button" class="modal-close" onclick="closeResolveModal()">×</button>
                </div>
                <div class="modal-body">
                    <label style="display:block;font-size:12px;color:#495057;font-weight:600;margin-bottom:6px;">Resolution Notes</label>
                    <textarea name="notes" rows="4" placeholder="Describe what you found and how it was resolved…"
                              style="width:100%;padding:10px 14px;border:1px solid #e0e0e0;border-radius:8px;font-family:inherit;font-size:13px;background:#fafafa;"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResolveModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">✅ Mark Resolved</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>

    <script>
        // ============================================================
        // PHOTO LIGHTBOX
        // ============================================================
        let lightboxPhotos = [];
        let lightboxIndex = 0;

        function openLightbox(photos, index) {
            lightboxPhotos = photos || [];
            lightboxIndex = index || 0;
            updateLightbox();
            document.getElementById('lightbox').classList.add('show');
        }
        function updateLightbox() {
            if (lightboxPhotos.length === 0) return;
            document.getElementById('lightboxImg').src = lightboxPhotos[lightboxIndex];
            document.getElementById('lightboxCounter').textContent = (lightboxIndex + 1) + ' / ' + lightboxPhotos.length;
            const showNav = lightboxPhotos.length > 1;
            document.querySelectorAll('.lightbox-nav').forEach(el => el.style.display = showNav ? 'flex' : 'none');
        }
        function lightboxNav(dir) {
            lightboxIndex = (lightboxIndex + dir + lightboxPhotos.length) % lightboxPhotos.length;
            updateLightbox();
        }
        function closeLightbox() { document.getElementById('lightbox').classList.remove('show'); }

        document.addEventListener('keydown', e => {
            const lb = document.getElementById('lightbox');
            if (lb.classList.contains('show')) {
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') lightboxNav(-1);
                if (e.key === 'ArrowRight') lightboxNav(1);
            }
        });

        // ============================================================
        // LOCATION MODAL
        // ============================================================
        let locationMap = null;

        function showLocationModalFromBtn(btn) {
            try {
                const incident = JSON.parse(btn.getAttribute('data-incident'));
                showLocationModal(incident);
            } catch (e) { console.error(e); }
        }

        function showLocationModal(incident) {
            document.getElementById('locationModalTitle').textContent = '📍 Incident #' + incident.id;

            document.getElementById('locationMeta').innerHTML = `
                <strong>${(incident.category || '').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</strong>
                · <span style="color:#dc3545;font-weight:700;">${(incident.severity || '').toUpperCase()}</span>
                <br>
                👤 Reported by: ${escapeHtml(incident.reporter || 'Unknown')}
                · 📍 ${escapeHtml(incident.zone || '')}
                <br>
                🌐 Coordinates: <code>${incident.lat.toFixed(6)}, ${incident.lng.toFixed(6)}</code>
            `;

            document.getElementById('locationModal').classList.add('show');

            setTimeout(() => {
                if (locationMap) locationMap.remove();
                locationMap = L.map('incidentMap').setView([incident.lat, incident.lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19, attribution: '&copy; OpenStreetMap'
                }).addTo(locationMap);

                L.marker([incident.lat, incident.lng], {
                    icon: L.divIcon({
                        html: `<div style="background:#dc3545;width:32px;height:32px;border-radius:50%;border:4px solid white;box-shadow:0 2px 10px rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;color:white;font-size:15px;">🚨</div>`,
                        iconSize: [32, 32], iconAnchor: [16, 16],
                    })
                }).addTo(locationMap).bindPopup(`Incident #${incident.id}`).openPopup();

                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(pos => {
                        const meLat = pos.coords.latitude, meLng = pos.coords.longitude;
                        L.marker([meLat, meLng], {
                            icon: L.divIcon({
                                html: `<div style="background:#1a5c3a;width:28px;height:28px;border-radius:50%;border:4px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;color:white;font-size:14px;">🛡️</div>`,
                                iconSize: [28, 28], iconAnchor: [14, 14],
                            })
                        }).addTo(locationMap).bindPopup('Your position');
                        L.polyline([[meLat, meLng], [incident.lat, incident.lng]], {
                            color: '#007bff', weight: 3, dashArray: '8,8', opacity: 0.7
                        }).addTo(locationMap);
                        locationMap.fitBounds(L.latLngBounds([[meLat, meLng], [incident.lat, incident.lng]]), { padding: [40, 40] });
                    }, () => {}, { enableHighAccuracy: true, timeout: 8000 });
                }
            }, 150);

            document.getElementById('locationNavBtn').onclick = () => {
                closeLocationModal();
                startNavigation({ id: incident.id, lat: incident.lat, lng: incident.lng, category: incident.category });
            };
        }

        function closeLocationModal() {
            document.getElementById('locationModal').classList.remove('show');
            if (locationMap) setTimeout(() => { locationMap.remove(); locationMap = null; }, 300);
        }

        // ============================================================
        // TURN-BY-TURN NAVIGATION
        // ============================================================
        let navMap = null;
        let navWatchId = null;
        let navDestination = null;
        let navMyMarker = null;
        let navRouteLine = null;
        let navRouteAltLine = null;
        let navRecalcTimer = null;
        let navLastRouteFetch = 0;
        let navLastRouteFrom = null;
        let navCurrentRoute = null;
        let navSteps = [];
        let navCurrentStepIdx = 0;
        let navLastSpokenStepIdx = -1;
        let navLastAnnouncedDistance = null;
        let navVoiceEnabled = true;
        let navWakeLock = null;
        let navArrived = false;
        let navManeuverIcons = {
            'turn-left': '⬅️',
            'turn-right': '➡️',
            'turn-slight left': '↖️',
            'turn-slight right': '↗️',
            'turn-sharp left': '↰',
            'turn-sharp right': '↱',
            'turn-straight': '⬆️',
            'depart': '🚗',
            'arrive': '🏁',
            'merge': '🔀',
            'fork-left': '↖️',
            'fork-right': '↗️',
            'roundabout': '🔄',
            'rotary': '🔄',
            'end of road-left': '↰',
            'end of road-right': '↱',
            'continue': '⬆️',
            'new name': '⬆️',
            'uturn': '🔄',
        };

        function startNavigationFromBtn(btn) {
            try {
                const data = JSON.parse(btn.getAttribute('data-nav'));
                startNavigation(data);
            } catch (e) { console.error(e); }
        }

        function startNavigation(incident) {
            navDestination = { lat: incident.lat, lng: incident.lng, id: incident.id };
            navArrived = false;

            document.getElementById('navModal').classList.add('show');
            document.getElementById('navTurnDistance').textContent = 'Calculating…';
            document.getElementById('navInstruction').textContent = 'Getting your location…';
            document.getElementById('navEta').textContent = '—';
            document.getElementById('navRemaining').textContent = '—';
            document.getElementById('navSpeed').textContent = '—';

            speak('Navigation started. Calculating route.', true);

            setTimeout(() => {
                if (navMap) navMap.remove();
                navMap = L.map('navMap', { zoomControl: true }).setView([incident.lat, incident.lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19, attribution: '&copy; OpenStreetMap'
                }).addTo(navMap);

                L.marker([incident.lat, incident.lng], {
                    icon: L.divIcon({
                        html: `<div style="background:#dc3545;width:36px;height:36px;border-radius:50%;border:4px solid white;box-shadow:0 2px 12px rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;color:white;font-size:17px;">🚨</div>`,
                        iconSize: [36, 36], iconAnchor: [18, 18],
                    })
                }).addTo(navMap).bindPopup('Incident #' + incident.id);

                if (!navigator.geolocation) {
                    document.getElementById('navInstruction').textContent = '❌ GPS not available';
                    speak('GPS is not available on this device.', true);
                    return;
                }

                if (navWatchId !== null) navigator.geolocation.clearWatch(navWatchId);

                navWatchId = navigator.geolocation.watchPosition(
                    pos => handlePositionUpdate(pos),
                    err => {
                        document.getElementById('navInstruction').textContent = '⚠️ GPS error: ' + err.message;
                    },
                    { enableHighAccuracy: true, maximumAge: 1000, timeout: 15000 }
                );

                // Safety: recalc every 30s even if not moving
                navRecalcTimer = setInterval(() => {
                    if (navMyMarker) {
                        const p = navMyMarker.getLatLng();
                        fetchRoute(p.lat, p.lng, incident.lat, incident.lng, true);
                    }
                }, 30000);

                requestWakeLock();
            }, 200);
        }

        async function requestWakeLock() {
            try {
                if ('wakeLock' in navigator) {
                    navWakeLock = await navigator.wakeLock.request('screen');
                }
            } catch (e) { /* silent */ }
        }

        function releaseWakeLock() {
            try { if (navWakeLock) navWakeLock.release(); } catch (e) {}
            navWakeLock = null;
        }

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && navMap) requestWakeLock();
        });

        function handlePositionUpdate(pos) {
            const meLat = pos.coords.latitude;
            const meLng = pos.coords.longitude;
            const speed = pos.coords.speed ? Math.round(pos.coords.speed * 3.6) : 0; // km/h
            const accuracy = Math.round(pos.coords.accuracy || 0);

            // Update my marker
            if (!navMyMarker) {
                navMyMarker = L.marker([meLat, meLng], {
                    icon: L.divIcon({
                        html: `<div style="position:relative;">
                            <div style="background:#1a5c3a;width:32px;height:32px;border-radius:50%;border:4px solid white;box-shadow:0 2px 10px rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;color:white;font-size:14px;">🛡️</div>
                            <div style="position:absolute;top:-4px;right:-4px;width:12px;height:12px;background:#4ade80;border-radius:50%;border:2px solid white;animation:pulse 1.5s infinite;"></div>
                        </div>`,
                        iconSize: [32, 32], iconAnchor: [16, 16],
                    })
                }).addTo(navMap);
            } else {
                navMyMarker.setLatLng([meLat, meLng]);
            }

            // Arrival detection
            if (!navArrived && navDestination) {
                const dist = haversineMeters(meLat, meLng, navDestination.lat, navDestination.lng);
                if (dist < 30) {
                    navArrived = true;
                    speak('You have arrived at the incident location.', true);
                    document.getElementById('navInstruction').textContent = '🏁 Arrived at destination';
                    document.getElementById('navTurnIcon').textContent = '🏁';
                    document.getElementById('navTurnDistance').textContent = 'Arrived';
                    // Stop watching after arrival
                    if (navWatchId !== null) {
                        navigator.geolocation.clearWatch(navWatchId);
                        navWatchId = null;
                    }
                    return;
                }
            }

            // Update speed display
            document.getElementById('navSpeed').textContent = speed > 0 ? `🚗 ${speed} km/h` : `📡 ±${accuracy}m`;

            // Recalculate route if moved significantly or first time
            const now = Date.now();
            const shouldFetch = !navLastRouteFrom
                || haversineMeters(navLastRouteFrom.lat, navLastRouteFrom.lng, meLat, meLng) > 25
                || (now - navLastRouteFetch) > 12000;

            if (shouldFetch) {
                fetchRoute(meLat, meLng, navDestination.lat, navDestination.lng, false);
            }

            // Update step progress on movement
            if (navCurrentRoute) {
                updateStepProgress(meLat, meLng);
            }
        }

        function fetchRoute(fromLat, fromLng, toLat, toLng, silent) {
            const url = `https://router.project-osrm.org/route/v1/driving/${fromLng},${fromLat};${toLng},${toLat}?overview=full&geometries=geojson&steps=true&alternatives=true&annotations=false`;

            navLastRouteFetch = Date.now();
            navLastRouteFrom = { lat: fromLat, lng: fromLng };

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (!data.routes || !data.routes.length) return;

                    const route = data.routes[0];
                    navCurrentRoute = route;

                    // Draw main route
                    const coords = route.geometry.coordinates.map(c => [c[1], c[0]]);
                    if (navRouteLine) {
                        navRouteLine.setLatLngs(coords);
                    } else {
                        navRouteLine = L.polyline(coords, { color: '#007bff', weight: 6, opacity: 0.85 }).addTo(navMap);
                    }

                    // Draw alternative route if significantly different
                    if (navRouteAltLine) { navMap.removeLayer(navRouteAltLine); navRouteAltLine = null; }
                    if (data.routes[1]) {
                        const alt = data.routes[1];
                        const altCoords = alt.geometry.coordinates.map(c => [c[1], c[0]]);
                        // Only show alt if it's within 30% of the main duration (a real shortcut)
                        if (alt.duration < route.duration * 1.3) {
                            navRouteAltLine = L.polyline(altCoords, {
                                color: '#9ca3af', weight: 4, opacity: 0.6, dashArray: '6,8'
                            }).addTo(navMap);
                        }
                    }

                    // Fit map to include both markers
                    const bounds = L.latLngBounds([[fromLat, fromLng], [toLat, toLng]]);
                    navMap.fitBounds(bounds, { padding: [60, 60] });

                    // Extract steps
                    navSteps = extractSteps(route);
                    navCurrentStepIdx = 0;
                    navLastSpokenStepIdx = -1;

                    updateNavigationHUD(route, navSteps);
                    renderUpcomingSteps(navSteps);
                })
                .catch(err => {
                    console.warn('Route fetch failed:', err);
                    if (!silent) {
                        document.getElementById('navInstruction').textContent = '⚠️ Route unavailable — showing straight line';
                    }
                    if (navRouteLine) navMap.removeLayer(navRouteLine);
                    navRouteLine = L.polyline([[fromLat, fromLng], [toLat, toLng]], {
                        color: '#007bff', weight: 4, dashArray: '8,8', opacity: 0.7
                    }).addTo(navMap);
                });
        }

        function extractSteps(route) {
            const steps = [];
            if (!route.legs || !route.legs.length) return steps;

            route.legs.forEach(leg => {
                if (!leg.steps) return;
                leg.steps.forEach(step => {
                    const man = step.maneuver || {};
                    const type = man.type || 'continue';
                    const modifier = man.modifier || '';
                    const iconKey = (type + (modifier ? '-' + modifier : '')).replace(/\s+/g, ' ').trim();

                    steps.push({
                        icon: navManeuverIcons[iconKey] || navManeuverIcons[type] || '⬆️',
                        type: type,
                        modifier: modifier,
                        name: step.name || '',
                        distance: step.distance || 0,
                        duration: step.duration || 0,
                        location: man.location ? [man.location[1], man.location[0]] : null,
                        instruction: buildInstruction(type, modifier, step.name || ''),
                    });
                });
            });
            return steps;
        }

        function buildInstruction(type, modifier, roadName) {
            let txt = '';
            if (type === 'depart') txt = 'Head out';
            else if (type === 'arrive') txt = 'Arrive at destination';
            else if (type === 'turn') txt = 'Turn ' + (modifier || '');
            else if (type === 'new name') txt = 'Continue';
            else if (type === 'continue') txt = 'Continue ' + (modifier || 'straight');
            else if (type === 'merge') txt = 'Merge ' + (modifier || '');
            else if (type === 'fork') txt = 'Keep ' + (modifier || '');
            else if (type === 'roundabout' || type === 'rotary') txt = 'Enter roundabout';
            else if (type === 'end of road') txt = 'At end of road, turn ' + (modifier || '');
            else if (type === 'uturn') txt = 'Make a U-turn';
            else txt = (type + ' ' + modifier).trim();

            txt = txt.charAt(0).toUpperCase() + txt.slice(1);
            if (roadName) txt += ' onto ' + roadName;
            return txt;
        }

        function updateStepProgress(meLat, meLng) {
            if (!navSteps.length) return;

            // Find which step we're on based on proximity to step's location
            let idx = navCurrentStepIdx;
            for (let i = navCurrentStepIdx; i < navSteps.length; i++) {
                const s = navSteps[i];
                if (!s.location) continue;
                const d = haversineMeters(meLat, meLng, s.location[0], s.location[1]);
                if (d < 40) {
                    idx = i;
                    break;
                }
            }

            // Progress if we've moved past the current step
            if (idx !== navCurrentStepIdx) {
                navCurrentStepIdx = idx;
                const step = navSteps[idx];

                // Announce step change
                if (navVoiceEnabled && idx !== navLastSpokenStepIdx) {
                    navLastSpokenStepIdx = idx;
                    navLastAnnouncedDistance = null;
                    speak(step.instruction, false);
                }
            }

            // Compute distance to next turn
            const currentStep = navSteps[navCurrentStepIdx];
            if (currentStep && currentStep.location) {
                const distToTurn = haversineMeters(meLat, meLng, currentStep.location[0], currentStep.location[1]);

                // Announce distance milestones
                if (navVoiceEnabled) {
                    const milestones = [300, 150, 80, 30];
                    for (const m of milestones) {
                        if (distToTurn <= m && (navLastAnnouncedDistance === null || navLastAnnouncedDistance > m)) {
                            const rounded = m >= 100 ? Math.round(distToTurn / 50) * 50 : m;
                            speak(`In ${rounded} meters, ${currentStep.instruction.toLowerCase()}`, false);
                            navLastAnnouncedDistance = m;
                            break;
                        }
                    }
                }

                // Update HUD distance
                document.getElementById('navTurnDistance').textContent = formatDistance(distToTurn);
                document.getElementById('navTurnIcon').textContent = currentStep.icon;
                document.getElementById('navInstruction').textContent = currentStep.instruction;
            }
        }

        function updateNavigationHUD(route, steps) {
            // Distance / duration total
            const distKm = route.distance / 1000;
            const durationMin = Math.round(route.duration / 60);
            const arrival = new Date(Date.now() + route.duration * 1000);

            document.getElementById('navEta').textContent =
                `⏱️ ~${durationMin} min · Arrival ${arrival.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
            document.getElementById('navRemaining').textContent =
                `📏 ${distKm < 1 ? Math.round(route.distance) + ' m' : distKm.toFixed(2) + ' km'}`;

            // Next step
            if (steps.length > 0) {
                const step = steps[0];
                document.getElementById('navTurnIcon').textContent = step.icon;
                document.getElementById('navTurnDistance').textContent = formatDistance(step.distance);
                document.getElementById('navInstruction').textContent = step.instruction;

                if (navVoiceEnabled && navLastSpokenStepIdx !== 0) {
                    navLastSpokenStepIdx = 0;
                    speak(step.instruction, false);
                }
            }
        }

        function renderUpcomingSteps(steps) {
            const container = document.getElementById('navNextSteps');
            const list = document.getElementById('navStepsList');

            // Show up to 3 upcoming steps
            const upcoming = steps.slice(navCurrentStepIdx + 1, navCurrentStepIdx + 4);
            if (!upcoming.length) {
                container.style.display = 'none';
                return;
            }

            list.innerHTML = upcoming.map(s => `
                <div class="step">
                    <span class="ico">${s.icon}</span>
                    <span class="dist">${formatDistance(s.distance)}</span>
                    <span>${escapeHtml(s.instruction)}</span>
                </div>
            `).join('');
            container.style.display = 'block';
        }

        // ============================================================
        // VOICE GUIDANCE
        // ============================================================
        function speak(text, interrupt) {
            if (!navVoiceEnabled) return;
            if (!('speechSynthesis' in window)) return;

            if (interrupt) window.speechSynthesis.cancel();

            const utter = new SpeechSynthesisUtterance(text);
            utter.rate = 1.05;
            utter.pitch = 1.0;
            utter.volume = 1.0;
            // Try to use a natural voice if available
            const voices = window.speechSynthesis.getVoices();
            const preferred = voices.find(v => /Google UK English Male|Samantha|Microsoft David|Daniel/i.test(v.name));
            if (preferred) utter.voice = preferred;
            try { window.speechSynthesis.speak(utter); } catch (e) {}
        }

        function toggleVoice() {
            navVoiceEnabled = !navVoiceEnabled;
            const btn = document.getElementById('navVoiceBtn');
            const badge = document.getElementById('navVoiceBadge');
            btn.textContent = navVoiceEnabled ? '🔊' : '🔇';
            btn.classList.toggle('muted', !navVoiceEnabled);
            badge.textContent = navVoiceEnabled ? '🔊 Voice on' : '🔇 Voice muted';
            badge.classList.toggle('warn', !navVoiceEnabled);

            if (!navVoiceEnabled && 'speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            } else if (navVoiceEnabled) {
                speak('Voice guidance enabled', true);
            }
        }

        // ============================================================
        // UTILITIES
        // ============================================================
        function haversineMeters(lat1, lng1, lat2, lng2) {
            const R = 6371000;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLng = (lng2 - lng1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2
                + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180)
                * Math.sin(dLng / 2) ** 2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        function formatDistance(meters) {
            if (!isFinite(meters)) return '—';
            if (meters < 10) return Math.round(meters) + ' m';
            if (meters < 1000) return Math.round(meters / 10) * 10 + ' m';
            return (meters / 1000).toFixed(2) + ' km';
        }

        function escapeHtml(s) {
            return String(s || '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));
        }

        // ============================================================
        // LIFECYCLE
        // ============================================================
        function recalculateRoute() {
            if (!navMyMarker || !navDestination) return;
            const p = navMyMarker.getLatLng();
            speak('Recalculating route', true);
            fetchRoute(p.lat, p.lng, navDestination.lat, navDestination.lng, false);
        }

        function stopNavigation() {
            if (navWatchId !== null) {
                navigator.geolocation.clearWatch(navWatchId);
                navWatchId = null;
            }
            if (navRecalcTimer) {
                clearInterval(navRecalcTimer);
                navRecalcTimer = null;
            }
            if (navMap) {
                navMap.remove();
                navMap = null;
            }
            releaseWakeLock();
            if ('speechSynthesis' in window) window.speechSynthesis.cancel();

            navMyMarker = null;
            navRouteLine = null;
            navRouteAltLine = null;
            navDestination = null;
            navCurrentRoute = null;
            navSteps = [];
            navLastRouteFrom = null;
            navLastRouteFetch = 0;

            document.getElementById('navModal').classList.remove('show');
        }

        function closeNavModal() {
            // Just hide — keep GPS running
            document.getElementById('navModal').classList.remove('show');
        }

        document.getElementById('navModal').addEventListener('click', function (e) {
            if (e.target === this) closeNavModal();
        });

        // ============================================================
        // RESOLVE MODAL
        // ============================================================
        function openResolveModal(id) {
            document.getElementById('resolveIncidentId').value = id;
            document.getElementById('resolveModal').classList.add('show');
        }
        function closeResolveModal() {
            document.getElementById('resolveModal').classList.remove('show');
        }
        document.getElementById('resolveModal').addEventListener('click', function (e) {
            if (e.target === this) closeResolveModal();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeResolveModal();
                closeLocationModal();
                closeLightbox();
            }
        });

        // Warm up voices (some browsers need this)
        if ('speechSynthesis' in window) {
            window.speechSynthesis.getVoices();
        }

        console.log('✅ Ranger incidents page loaded — turn-by-turn navigation with voice ready');
    </script>
</body>
</html>
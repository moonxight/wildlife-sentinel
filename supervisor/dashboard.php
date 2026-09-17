<?php
// ============================================================
// supervisor/dashboard.php
// Wildlife Sentinel - Zone Supervisor Dashboard
// SAME DESIGN as admin/dashboard.php
// but ONLY supervisor-appropriate modules
// + Zambian wildlife slideshow at the top
// ============================================================

require_once '../includes/functions.php';
requireLogin();

if (!hasRole('zone_supervisor')) {
    header('Location: ../index.php');
    exit();
}

$user = getCurrentUser();
$pdo  = getDB();

// ============================================================
// SAFE HELPERS
// ============================================================
function safeCount(PDO $pdo, string $sql, array $params = []): int {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int)($row['count'] ?? 0);
    } catch (PDOException $e) {
        error_log('[WS-SUPERVISOR] safeCount: ' . $e->getMessage());
        return 0;
    }
}
function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[WS-SUPERVISOR] safeFetchAll: ' . $e->getMessage());
        return [];
    }
}

$activeZoneId = $user['zone_id'];

// ============================================================
// ZONE DETAILS
// ============================================================
$zone = getZone($activeZoneId);
if ($zone && !empty($zone['boundary_geojson'])) {
    $boundary = is_string($zone['boundary_geojson'])
        ? json_decode($zone['boundary_geojson'], true)
        : $zone['boundary_geojson'];
    if ($boundary) {
        $zone['boundary_geojson'] = $boundary;
        $zone['buffer_geojson']   = computeBufferZone($boundary, $zone['buffer_radius'] ?? 500);
    }
}
$isRegistered  = $zone['is_registered']  ?? 0;
$parkType      = $zone['park_type']      ?? 'other';
$bufferRadius  = $zone['buffer_radius']  ?? 500;

// ============================================================
// ZAMBIAN WILDLIFE SLIDESHOW
// ------------------------------------------------------------
// Curated Unsplash images of animals found in Zambia.
// Falls back to a colour gradient if a URL fails to load.
// ============================================================
$zambianWildlifeSlides = [
    [
        'img'   => 'https://images.unsplash.com/photo-1546182990-dffeafbe841d?w=1600&q=80',
        'emoji' => '🦁',
        'title' => 'African Lion',
        'sub'   => 'South Luangwa National Park',
    ],
    [
        'img'   => 'https://images.unsplash.com/photo-1564760055775-d63b17a55c44?w=1600&q=80',
        'emoji' => '🐘',
        'title' => 'African Elephant',
        'sub'   => 'Kafue National Park',
    ],
    [
        'img'   => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQQhOz7n14ljST98yV6G701WYamCBV_o5WB0vKUmV36xw&s=10',
        'emoji' => '🦒',
        'title' => 'Thornicroft Giraffe',
        'sub'   => 'South Luangwa — endemic to Zambia',
    ],
    [
        'img'   => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTJbzUN3K3EQ64sX2YD9xgcwL3A4oADOIZUwCuK66H98A&s=10',
        'emoji' => '🦏',
        'title' => 'Black Rhinoceros',
        'sub'   => 'North Luangwa National Park',
    ],
    [
        'img'   => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTQJYHK55hkLEoADyeyCv9vjPGHRCSLxYUBB0rJVZq0tw&s=10',
        'emoji' => '🐆',
        'title' => 'African Leopard',
        'sub'   => 'South Luangwa — "Valley of the Leopard"',
    ],
    [
        'img'   => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTpcekf69JGsT2Xn9kYxZp3G04EIYStA8L4eVgKbh4sKw&s=10',
        'emoji' => '🦓',
        'title' => 'Crawshay\'s Zebra',
        'sub'   => 'Liuwa Plain National Park',
    ],
    [
        'img'   => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQ8q24-VplDEWd_Ld3wt0tiuUOOCzu_FKMcpSg9V1AyGg&s=10',
        'emoji' => '🦛',
        'title' => 'Hippopotamus',
        'sub'   => 'Luangwa River',
    ],
    [
        'img'   => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSc7duP8DctDpKKMBTERYyt4T9PAqkla8LE46PprS2XEA&s=10',
        'emoji' => '🐃',
        'title' => 'African Buffalo',
        'sub'   => 'Lower Zambezi National Park',
    ],
];

// ============================================================
// STATISTICS (ZONE-SCOPED ONLY)
// ============================================================
$totalIncidents   = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ?", [$activeZoneId]);
$unacknowledged   = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND status = 'reported'", [$activeZoneId]);
$inProgress       = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND status = 'in_progress'", [$activeZoneId]);
$resolved         = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND status = 'resolved'", [$activeZoneId]);

$rangersOnDuty    = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'ranger' AND is_on_duty = 1 AND is_active = 1", [$activeZoneId]);
$totalRangers     = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'ranger' AND is_active = 1", [$activeZoneId]);
$scoutsOnline     = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'scout' AND is_online = 1 AND is_active = 1", [$activeZoneId]);
$totalScouts      = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'scout' AND is_active = 1", [$activeZoneId]);
$manpowerRequests = safeCount($pdo, "SELECT COUNT(*) as count FROM messages WHERE message_type = 'manpower_request' AND is_read = 0 AND (zone_id = ? OR zone_id IS NULL)", [$activeZoneId]);
$aiAnomalies24h   = safeCount($pdo, 'SELECT COUNT(*) as count FROM ai_anomalies WHERE zone_id = ? AND detected_at >= (NOW() - (24) * INTERVAL \'1 hour\')', [$activeZoneId]);

$unreadCount      = getUnreadNotificationCount($user['id']);

// ============================================================
// LIVE DATA
// ============================================================
$scouts      = getScoutLiveLocations($activeZoneId);
$rangers     = getRangerLiveLocations($activeZoneId);
$aiAnomalies = getAIAnomalies($activeZoneId, 50);

$recentIncidents = safeFetchAll($pdo, "
    SELECT i.*, u.full_name as reporter_name
    FROM incidents i
    JOIN users u ON i.reporter_id = u.id
    WHERE i.zone_id = ?
    ORDER BY i.reported_at DESC
    LIMIT 10
", [$activeZoneId]);

$recentMessages = safeFetchAll($pdo, "
    SELECT m.*, s.full_name AS sender_name
    FROM messages m
    JOIN users s ON m.sender_id = s.id
    WHERE (m.zone_id = ? OR m.zone_id IS NULL)
    ORDER BY m.created_at DESC
    LIMIT 5
", [$activeZoneId]);

$recentActivity = safeFetchAll($pdo, "
    SELECT a.*, u.full_name as user_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE u.zone_id = ? OR a.user_id = ?
    ORDER BY a.created_at DESC
    LIMIT 5
", [$activeZoneId, $user['id']]);

$severityStats = safeFetchAll($pdo, "
    SELECT severity, COUNT(*) as count FROM incidents WHERE zone_id = ? GROUP BY severity
", [$activeZoneId]);

$statusStats = safeFetchAll($pdo, "
    SELECT status, COUNT(*) as count FROM incidents WHERE zone_id = ? GROUP BY status
", [$activeZoneId]);
$statuses = ['reported'=>0,'acknowledged'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0];
foreach ($statusStats as $row) { $statuses[$row['status']] = (int)$row['count']; }

$aiAnomalyAlerts = array_slice($aiAnomalies, 0, 3);

$activeAlarms    = safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_triggers WHERE stopped_at IS NULL AND zone_id = ?", [$activeZoneId]);
$activeAlarmList = safeFetchAll($pdo, "
    SELECT at.*, a.alarm_name
    FROM alarm_triggers at
    JOIN alarm_systems a ON at.alarm_id = a.id
    WHERE at.stopped_at IS NULL AND at.zone_id = ?
    ORDER BY at.triggered_at DESC
    LIMIT 3
", [$activeZoneId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Supervisor Dashboard - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <link rel="stylesheet" href="assets/css/supervisor.css">

    <style>
        /* ============================================================
           DESIGN-IDENTICAL TO ADMIN
           ============================================================ */
        .dashboard-greeting { margin-bottom: 24px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p { color: #6c757d; font-size: 16px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.green { background: #d4edda; color: #155724; }
        .stat-card .icon.blue { background: #cce5ff; color: #004085; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.red { background: #f8d7da; color: #721c24; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .icon.teal { background: #d1ecf1; color: #0c5460; }
        .stat-card .icon.pink { background: #fce4ec; color: #c62828; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .section-header .view-all { color: #1a5c3a; text-decoration: none; font-size: 13px; font-weight: 500; }

        .two-col { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }

        /* ============================================================
           ZAMBIAN WILDLIFE SLIDESHOW
           ============================================================ */
        .wildlife-slideshow {
            position: relative;
            border-radius: 18px;
            overflow: hidden;
            height: 320px;
            margin-bottom: 24px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            background: #0d3b22;
        }

        .wildlife-slideshow .slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transition: opacity 1.4s ease-in-out, transform 8s ease-out;
            transform: scale(1);
        }

        .wildlife-slideshow .slide.active {
            opacity: 1;
            transform: scale(1.06);
        }

        .wildlife-slideshow .slide::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(
                to top,
                rgba(0,0,0,0.78) 0%,
                rgba(0,0,0,0.30) 45%,
                rgba(0,0,0,0.05) 75%,
                transparent 100%
            );
        }

        .wildlife-slideshow .slide-caption {
            position: absolute;
            left: 28px;
            bottom: 26px;
            z-index: 3;
            color: white;
            max-width: 70%;
            opacity: 0;
            transform: translateY(14px);
            transition: opacity 0.9s ease 0.3s, transform 0.9s ease 0.3s;
        }

        .wildlife-slideshow .slide.active .slide-caption {
            opacity: 1;
            transform: translateY(0);
        }

        .wildlife-slideshow .slide-caption .emoji {
            font-size: 38px;
            display: inline-block;
            margin-bottom: 6px;
            filter: drop-shadow(0 4px 12px rgba(0,0,0,0.5));
        }

        .wildlife-slideshow .slide-caption h3 {
            font-family: 'Inter', sans-serif;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.4px;
            margin: 0 0 4px 0;
            text-shadow: 0 3px 14px rgba(0,0,0,0.6);
        }

        .wildlife-slideshow .slide-caption p {
            font-size: 13px;
            color: rgba(255,255,255,0.85);
            margin: 0;
            font-weight: 500;
            text-shadow: 0 2px 8px rgba(0,0,0,0.6);
        }

        .wildlife-slideshow .slide-counter {
            position: absolute;
            top: 18px;
            right: 20px;
            z-index: 3;
            background: rgba(0,0,0,0.45);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.6px;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .wildlife-slideshow .slide-dots {
            position: absolute;
            bottom: 22px;
            right: 26px;
            z-index: 4;
            display: flex;
            gap: 7px;
        }

        .wildlife-slideshow .slide-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.4);
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            padding: 0;
        }

        .wildlife-slideshow .slide-dot:hover {
            background: rgba(255,255,255,0.7);
        }

        .wildlife-slideshow .slide-dot.active {
            background: white;
            width: 24px;
            border-radius: 4px;
        }

        /* Slide arrows */
        .wildlife-slideshow .slide-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(0,0,0,0.35);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 4;
            transition: all 0.2s;
            opacity: 0;
        }

        .wildlife-slideshow:hover .slide-arrow {
            opacity: 1;
        }

        .wildlife-slideshow .slide-arrow:hover {
            background: rgba(26,92,58,0.85);
            border-color: #4ade80;
        }

        .wildlife-slideshow .slide-arrow.prev { left: 16px; }
        .wildlife-slideshow .slide-arrow.next { right: 16px; }

        /* Slide number badge (bottom-left) */
        .wildlife-slideshow .slide-progress {
            position: absolute;
            bottom: 26px;
            left: 28px;
            z-index: 3;
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.7);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .wildlife-slideshow { height: 220px; border-radius: 14px; }
            .wildlife-slideshow .slide-caption { left: 18px; bottom: 18px; max-width: 85%; }
            .wildlife-slideshow .slide-caption h3 { font-size: 18px; }
            .wildlife-slideshow .slide-caption .emoji { font-size: 28px; }
            .wildlife-slideshow .slide-caption p { font-size: 11.5px; }
            .wildlife-slideshow .slide-dots { bottom: 14px; right: 16px; }
            .wildlife-slideshow .slide-counter { top: 12px; right: 12px; font-size: 10px; padding: 4px 9px; }
            .wildlife-slideshow .slide-progress { bottom: 18px; left: 18px; }
            .wildlife-slideshow .slide-arrow { width: 34px; height: 34px; font-size: 15px; }
        }

        @media (max-width: 480px) {
            .wildlife-slideshow { height: 180px; }
            .wildlife-slideshow .slide-caption h3 { font-size: 16px; }
            .wildlife-slideshow .slide-caption .emoji { font-size: 24px; }
        }

        /* ============================================================
           REST OF DASHBOARD STYLES (unchanged)
           ============================================================ */
        .incident-item { display: flex; align-items: center; padding: 10px 14px; border-bottom: 1px solid #f0f0f0; gap: 12px; }
        .incident-item:last-child { border-bottom: none; }
        .incident-item .info { flex: 1; min-width: 0; }
        .incident-item .info .title { font-weight: 600; font-size: 14px; }
        .incident-item .info .meta { font-size: 12px; color: #6c757d; display: flex; gap: 12px; flex-wrap: wrap; margin-top: 2px; }
        .incident-item .badges { display: flex; gap: 6px; flex-shrink: 0; }

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
        .park-item { background: #f8f9fa; border-radius: 10px; padding: 12px 14px; text-align: center; border: 1px solid #e9ecef; transition: all 0.3s; }
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

        .map-panel { background: white; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; height: 480px; position: relative; }
        #supervisor-map { width: 100%; height: 100%; }
        .map-controls { position: absolute; top: 12px; left: 12px; z-index: 500; display: flex; flex-wrap: wrap; gap: 6px; background: rgba(255,255,255,0.95); padding: 8px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .map-toggle { padding: 6px 10px; border: 1px solid #e0e0e0; background: white; border-radius: 6px; font-size: 11px; cursor: pointer; transition: all 0.2s; }
        .map-toggle.active { background: #1a5c3a; color: white; border-color: #1a5c3a; }
        .map-legend { position: absolute; bottom: 12px; right: 12px; background: rgba(255,255,255,0.95); padding: 10px 12px; border-radius: 8px; font-size: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 500; }
        .legend-item { display: flex; align-items: center; gap: 6px; margin-bottom: 3px; }
        .legend-item span { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }

        .empty-state { text-align:center; padding:16px; color:#6c757d; font-size:13px; }

        @media (max-width: 1024px) { .two-col { grid-template-columns: 1fr; } .chart-container { grid-template-columns: 1fr 1fr; } .map-panel { height: 400px; } }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .icon { width: 38px; height: 38px; font-size: 17px; }
            .stat-card .info .number { font-size: 18px; }
            .chart-container { grid-template-columns: 1fr; }
            .parks-grid { grid-template-columns: 1fr 1fr; }
            .dashboard-greeting h1 { font-size: 22px; }
            .map-panel { height: 320px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 10px 12px; }
            .stat-card .info .number { font-size: 16px; }
            .parks-grid { grid-template-columns: 1fr; }
        }
    </style>

    <script>
        window.USER           = <?= json_encode(['id'=>(int)$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role'],'zone_id'=>(int)$user['zone_id']]) ?>;
        window.ACTIVE_ZONE_ID = <?= json_encode($activeZoneId) ?>;
        window.WS_URL         = <?= json_encode(defined('WS_URL') ? WS_URL : 'http://localhost:3001') ?>;
        window.ZONE           = <?= json_encode($zone) ?>;
        window.WILDLIFE_SLIDES = <?= json_encode($zambianWildlifeSlides) ?>;
        window.INITIAL_DATA   = <?= json_encode([
            'scouts'       => $scouts,
            'rangers'      => $rangers,
            'incidents'    => array_slice($recentIncidents, 0, 20),
            'ai_anomalies' => $aiAnomalies,
        ]) ?>;
    </script>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Supervisor Dashboard</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>👋 Welcome back, <?= htmlspecialchars($user['full_name']) ?>!</h1>
                    <p>Monitoring <strong><?= htmlspecialchars($zone['name'] ?? 'your zone') ?></strong> — here's what's happening.</p>
                </div>

                <!-- ============================================================
                     ZAMBIAN WILDLIFE SLIDESHOW
                     ============================================================ -->
                <div class="wildlife-slideshow" id="wildlifeSlideshow">
                    <?php foreach ($zambianWildlifeSlides as $i => $slide): ?>
                        <div class="slide<?= $i === 0 ? ' active' : '' ?>"
                             data-index="<?= $i ?>"
                             style="background-image: url('<?= htmlspecialchars($slide['img']) ?>');">
                            <div class="slide-caption">
                                <span class="emoji"><?= $slide['emoji'] ?></span>
                                <h3><?= htmlspecialchars($slide['title']) ?></h3>
                                <p><?= htmlspecialchars($slide['sub']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Top-right counter -->
                    <div class="slide-counter">
                        <span id="slideCurrent">1</span> / <span id="slideTotal"><?= count($zambianWildlifeSlides) ?></span> · Zambia Wildlife
                    </div>

                    <!-- Prev / Next arrows -->
                    <button class="slide-arrow prev" id="slidePrev" aria-label="Previous slide">❮</button>
                    <button class="slide-arrow next" id="slideNext" aria-label="Next slide">❯</button>

                    <!-- Dot indicators -->
                    <div class="slide-dots" id="slideDots">
                        <?php foreach ($zambianWildlifeSlides as $i => $slide): ?>
                            <button class="slide-dot<?= $i === 0 ? ' active' : '' ?>"
                                    data-index="<?= $i ?>"
                                    aria-label="Go to slide <?= $i + 1 ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Zone Info Banner -->
                <div class="section" style="border-left:5px solid #1a5c3a;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;">
                    <div>
                        <h2 style="color:#0d3b22;font-size:20px;margin:0;">🏛️ <?= htmlspecialchars($zone['name'] ?? 'Your Zone') ?></h2>
                        <p style="color:#6c757d;margin:4px 0;font-size:14px;"><?= htmlspecialchars($zone['description'] ?? 'No description') ?></p>
                        <p style="font-size:13px;color:#6c757d;margin:4px 0;">
                            📍 <?= $zone['center_lat'] ?? 'N/A' ?>, <?= $zone['center_lng'] ?? 'N/A' ?>
                            • 📏 <?= $bufferRadius ?>m buffer
                        </p>
                    </div>
                    <div style="text-align:right;">
                        <span class="badge <?= $isRegistered ? 'badge-success' : 'badge-danger' ?>" style="padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600;display:inline-block;">
                            <?= $isRegistered ? '✅ Registered' : '🔴 Not Registered' ?>
                        </span>
                        <div style="margin-top:6px;">
                            <span class="badge" style="padding:4px 14px;border-radius:20px;font-size:11px;background:#cce5ff;color:#004085;display:inline-block;">
                                <?= strtoupper(str_replace('_',' ',$parkType)) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon green">🦁</div><div class="info"><div class="number"><?= $totalIncidents ?></div><div class="label">Total Incidents</div></div></div>
                    <div class="stat-card"><div class="icon red">🚨</div><div class="info"><div class="number"><?= $unacknowledged ?></div><div class="label">Unacknowledged</div></div></div>
                    <div class="stat-card"><div class="icon orange">⏳</div><div class="info"><div class="number"><?= $inProgress ?></div><div class="label">In Progress</div></div></div>
                    <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= $resolved ?></div><div class="label">Resolved</div></div></div>
                    <div class="stat-card"><div class="icon blue">👤</div><div class="info"><div class="number"><?= $rangersOnDuty ?> / <?= $totalRangers ?></div><div class="label">Rangers On Duty</div></div></div>
                    <div class="stat-card"><div class="icon teal">👥</div><div class="info"><div class="number"><?= $scoutsOnline ?> / <?= $totalScouts ?></div><div class="label">Scouts Online</div></div></div>
                    <div class="stat-card"><div class="icon purple">🆘</div><div class="info"><div class="number"><?= $manpowerRequests ?></div><div class="label">Manpower Requests</div></div></div>
                    <div class="stat-card"><div class="icon pink">🤖</div><div class="info"><div class="number"><?= $aiAnomalies24h ?></div><div class="label">AI Anomalies (24h)</div></div></div>
                </div>

                <!-- Two-Column Layout -->
                <div class="two-col">
                    <!-- LEFT COLUMN -->
                    <div>
                        <div class="section">
                            <div class="section-header">
                                <h2>🗺️ Live Map — Zone + 500m Buffer</h2>
                                <a href="map.php?zone_id=<?= $activeZoneId ?>" class="view-all">Full Map →</a>
                            </div>
                            <div class="map-panel"><div id="supervisor-map"></div></div>
                        </div>

                        <div class="section">
                            <div class="section-header">
                                <h2>📋 Recent Incidents</h2>
                                <a href="incidents.php" class="view-all">View All →</a>
                            </div>
                            <?php if (count($recentIncidents) > 0): ?>
                                <?php foreach ($recentIncidents as $incident): ?>
                                <div class="incident-item">
                                    <div class="info">
                                        <div class="title">
                                            <?= getCategoryIcon($incident['category']) ?>
                                            <?= ucfirst(str_replace('_', ' ', $incident['category'])) ?>
                                            <span style="font-weight:400;color:#6c757d;font-size:12px;">#<?= $incident['id'] ?></span>
                                        </div>
                                        <div class="meta">
                                            <span>👤 <?= htmlspecialchars($incident['reporter_name']) ?></span>
                                            <span>🕐 <?= timeAgo($incident['reported_at']) ?></span>
                                        </div>
                                    </div>
                                    <div class="badges">
                                        <?= getStatusBadge($incident['status']) ?>
                                        <?= getSeverityBadge($incident['severity']) ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><p>No incidents reported yet.</p></div>
                            <?php endif; ?>
                        </div>

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
                                            <span class="bar-label"><?= $severityLabels[$stat['severity']] ?? $stat['severity'] ?></span>
                                            <div class="bar-track"><div class="bar-fill <?= $severityColors[$stat['severity']] ?? 'blue' ?>" style="width: <?= ($stat['count'] / $maxSeverity) * 100 ?>%;"></div></div>
                                            <span class="bar-count"><?= $stat['count'] ?></span>
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
                                        <span class="bar-label"><?= ucfirst(str_replace('_',' ', $key)) ?></span>
                                        <div class="bar-track"><div class="bar-fill <?= $statusColors[$key] ?? 'blue' ?>" style="width: <?= ($count / $maxStatus) * 100 ?>%;"></div></div>
                                        <span class="bar-count"><?= $count ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN -->
                    <div>
                        <?php if ($activeAlarms > 0 && count($activeAlarmList) > 0): ?>
                        <div class="section" style="border-left: 4px solid #dc3545;">
                            <div class="section-header">
                                <h2>🔔 Active Alarms</h2>
                                <span class="badge badge-critical"><?= $activeAlarms ?> ACTIVE</span>
                            </div>
                            <?php foreach ($activeAlarmList as $alarm): ?>
                            <div class="alert-item critical">
                                <div class="alert-icon">🔔</div>
                                <div class="alert-info">
                                    <div class="alert-title"><?= htmlspecialchars($alarm['alarm_name']) ?></div>
                                    <div class="alert-desc"><?= htmlspecialchars($alarm['trigger_reason'] ?? '') ?></div>
                                </div>
                                <div class="alert-time"><?= timeAgo($alarm['triggered_at']) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="section">
                            <div class="section-header"><h2>🤖 AI Anomalies (Zone)</h2></div>
                            <?php if (count($aiAnomalyAlerts) > 0): ?>
                                <?php foreach ($aiAnomalyAlerts as $a): ?>
                                <div class="alert-item <?= ($a['severity'] === 'critical' || $a['severity'] === 'high') ? 'critical' : '' ?>">
                                    <div class="alert-icon">🤖</div>
                                    <div class="alert-info">
                                        <div class="alert-title"><?= htmlspecialchars(str_replace('_',' ', $a['type'])) ?></div>
                                        <div class="alert-desc"><?= htmlspecialchars(substr($a['description'] ?? '', 0, 60)) ?></div>
                                    </div>
                                    <div class="alert-time"><?= timeAgo($a['detected_at']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><p>✅ No AI anomalies detected.</p></div>
                            <?php endif; ?>
                        </div>

                        <div class="section">
                            <div class="section-header">
                                <h2>🛡️ Rangers in Zone</h2>
                                <a href="rangers.php" class="view-all">Manage →</a>
                            </div>
                            <?php if (count($rangers) > 0): ?>
                                <div class="parks-grid">
                                    <?php foreach (array_slice($rangers, 0, 6) as $r): ?>
                                    <div class="park-item">
                                        <span class="park-icon"><?= $r['is_on_duty'] ? '🟢' : '⚪' ?></span>
                                        <div class="park-name"><?= htmlspecialchars($r['full_name']) ?></div>
                                        <div class="park-type"><?= htmlspecialchars($r['badge_number'] ?? 'Ranger') ?></div>
                                        <div class="park-buffer"><?= $r['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state"><p>No rangers assigned.</p></div>
                            <?php endif; ?>
                        </div>

                        <div class="section">
                            <div class="section-header">
                                <h2>💬 Recent Messages</h2>
                                <a href="messages.php" class="view-all">View All →</a>
                            </div>
                            <?php if (count($recentMessages) > 0): ?>
                                <?php foreach ($recentMessages as $m): ?>
                                <div class="activity-item">
                                    <div class="activity-icon"><?= $m['message_type'] === 'manpower_request' ? '🆘' : '💬' ?></div>
                                    <div class="activity-text">
                                        <span class="user"><?= htmlspecialchars($m['sender_name']) ?></span>
                                        <?= htmlspecialchars(substr($m['subject'] ?? $m['content'], 0, 50)) ?>
                                    </div>
                                    <div class="activity-time"><?= timeAgo($m['created_at']) ?></div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><p>No messages.</p></div>
                            <?php endif; ?>
                        </div>

                        <div class="section">
                            <div class="section-header">
                                <h2>📋 Recent Activity (Zone)</h2>
                                <a href="audit.php" class="view-all">View All →</a>
                            </div>
                            <?php if (count($recentActivity) > 0): ?>
                                <?php foreach ($recentActivity as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <?php
                                        $actionIcons = ['login'=>'🔑','logout'=>'🚪','create_user'=>'👤','update_user'=>'✏️','delete_user'=>'🗑️','report_incident'=>'🚨','acknowledge_incident'=>'✅'];
                                        echo $actionIcons[$activity['action']] ?? '📌';
                                        ?>
                                    </div>
                                    <div class="activity-text">
                                        <span class="user"><?= htmlspecialchars($activity['user_name'] ?? 'System') ?></span>
                                        <?= htmlspecialchars(str_replace('_',' ', $activity['action'])) ?>
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

                <!-- Quick Actions -->
                <div class="section">
                    <div class="section-header"><h2>⚡ Quick Actions</h2></div>
                    <div class="stats-grid">
                        <a href="incidents.php" class="stat-card" style="text-decoration:none;cursor:pointer;">
                            <div class="icon red">🚨</div>
                            <div class="info"><div class="number"><?= $unacknowledged ?></div><div class="label">View Incidents</div></div>
                        </a>
                        <a href="messages.php" class="stat-card" style="text-decoration:none;cursor:pointer;">
                            <div class="icon blue">💬</div>
                            <div class="info"><div class="number"><?= count($recentMessages) ?></div><div class="label">Messages</div></div>
                        </a>
                        <a href="rangers.php" class="stat-card" style="text-decoration:none;cursor:pointer;">
                            <div class="icon green">👤</div>
                            <div class="info"><div class="number"><?= $totalRangers ?></div><div class="label">Manage Rangers</div></div>
                        </a>
                        <a href="scouts.php" class="stat-card" style="text-decoration:none;cursor:pointer;">
                            <div class="icon teal">👥</div>
                            <div class="info"><div class="number"><?= $totalScouts ?></div><div class="label">Manage Scouts</div></div>
                        </a>
                        <a href="map.php?zone_id=<?= $activeZoneId ?>" class="stat-card" style="text-decoration:none;cursor:pointer;">
                            <div class="icon purple">📍</div>
                            <div class="info"><div class="number">🗺️</div><div class="label">View Map</div></div>
                        </a>
                        <a href="patrols.php" class="stat-card" style="text-decoration:none;cursor:pointer;">
                            <div class="icon orange">🛤️</div>
                            <div class="info"><div class="number">🛤️</div><div class="label">Patrol Routes</div></div>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="notification-panel" id="notification-panel" style="display:none">
        <div class="panel-header"><h4>Notifications</h4><button onclick="toggleNotifications()">✕</button></div>
        <div class="notification-list" id="notification-list"><p class="empty">No new notifications</p></div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script src="assets/js/supervisor-map.js"></script>
    <script src="assets/js/ai-tracker.js"></script>
    <script src="assets/js/dashboard.js"></script>

    <script>
        // ============================================================
        // WILDLIFE SLIDESHOW
        // ============================================================
        (function () {
            const container = document.getElementById('wildlifeSlideshow');
            if (!container) return;

            const slides    = container.querySelectorAll('.slide');
            const dots      = container.querySelectorAll('.slide-dot');
            const counter   = document.getElementById('slideCurrent');
            const prevBtn   = document.getElementById('slidePrev');
            const nextBtn   = document.getElementById('slideNext');
            const total     = slides.length;
            let current     = 0;
            let timer       = null;
            const INTERVAL  = 6000;

            function goTo(index) {
                if (index < 0) index = total - 1;
                if (index >= total) index = 0;

                slides.forEach((s, i) => s.classList.toggle('active', i === index));
                dots.forEach((d, i)   => d.classList.toggle('active', i === index));
                if (counter) counter.textContent = index + 1;
                current = index;
            }

            function next() { goTo(current + 1); }
            function prev() { goTo(current - 1); }

            function startAuto() {
                stopAuto();
                timer = setInterval(next, INTERVAL);
            }

            function stopAuto() {
                if (timer) { clearInterval(timer); timer = null; }
            }

            // Dot navigation
            dots.forEach(dot => {
                dot.addEventListener('click', () => {
                    const i = parseInt(dot.dataset.index, 10) || 0;
                    goTo(i);
                    startAuto();
                });
            });

            // Arrow navigation
            if (prevBtn) prevBtn.addEventListener('click', () => { prev(); startAuto(); });
            if (nextBtn) nextBtn.addEventListener('click', () => { next(); startAuto(); });

            // Pause on hover (desktop)
            container.addEventListener('mouseenter', stopAuto);
            container.addEventListener('mouseleave', startAuto);

            // Touch swipe (mobile)
            let touchStartX = 0;
            container.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            container.addEventListener('touchend', e => {
                const dx = e.changedTouches[0].screenX - touchStartX;
                if (Math.abs(dx) > 40) {
                    if (dx < 0) next(); else prev();
                    startAuto();
                }
            }, { passive: true });

            // Pause when tab is hidden
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) stopAuto();
                else startAuto();
            });

            startAuto();
        })();

        // ============================================================
        // DASHBOARD INIT
        // ============================================================
        document.addEventListener('DOMContentLoaded', function () {
            if (window.SupervisorMap && window.INITIAL_DATA && window.ZONE) {
                window.SupervisorMap.initMap('supervisor-map', {
                    zone: window.ZONE,
                    scouts: window.INITIAL_DATA.scouts,
                    rangers: window.INITIAL_DATA.rangers,
                    incidents: window.INITIAL_DATA.incidents,
                    ai_anomalies: window.INITIAL_DATA.ai_anomalies,
                    ws_url: window.WS_URL
                });
            }
            if (window.AITracker) window.AITracker.startAITracking(window.ACTIVE_ZONE_ID);
        });

        function toggleNotifications() {
            const panel = document.getElementById('notification-panel');
            if (panel) panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>
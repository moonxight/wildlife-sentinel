<?php
// ============================================================
// ranger/map.php
// Wildlife Sentinel — Ranger Live Map
// ------------------------------------------------------------
// Working buttons:
//   - 📍 My Location      → centers on GPS, shows marker + accuracy circle
//   - 🟢 My Zone          → fits zone boundary
//   - 🚨 Incidents        → fits all incidents in zone
//   - 🛡️ Rangers          → fits other rangers
//   - 👥 Scouts           → fits online scouts
//   - 🗺️ Reset            → reset to initial view
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
// SAFE HELPERS
// ============================================================
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[WS-RANGER-MAP] ' . $e->getMessage());
            return [];
        }
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
// ZONE + BUFFER
// ============================================================
$zone = getZone($zoneId);
if ($zone && !empty($zone['boundary_geojson'])) {
    $boundary = is_string($zone['boundary_geojson'])
        ? json_decode($zone['boundary_geojson'], true)
        : $zone['boundary_geojson'];
    if ($boundary && isset($boundary['type'])) {
        $zone['boundary_geojson'] = $boundary;
        $zone['buffer_geojson']   = computeBufferZone($boundary, (int)($zone['buffer_radius'] ?? 500));
    }
}

// ============================================================
// MY LIVE POSITION
// ============================================================
$myTracking = safeFetchAll($pdo, "
    SELECT current_lat, current_lng, heading, speed, last_update, is_offline
    FROM ranger_live_tracking
    WHERE ranger_id = ?
", [$user['id']]);
$myLocation = $myTracking[0] ?? null;

// ============================================================
// MY ASSIGNED PATROL ROUTE (last 2h trail)
// ============================================================
$myRoute = safeFetchAll($pdo, '
    SELECT lat, lng, heading, speed, timestamp
    FROM ranger_location_history
    WHERE ranger_id = ? AND timestamp >= (NOW() - (2) * INTERVAL \'1 hour\')
    ORDER BY timestamp ASC
', [$user['id']]);

// ============================================================
// ACTIVE INCIDENTS IN MY ZONE
// ============================================================
$incidents = safeFetchAll($pdo, '
    SELECT i.id, i.category, i.severity, i.status, i.description,
           i.location_lat, i.location_lng, i.reported_at,
           u.full_name AS reporter_name, u.phone AS reporter_phone,
           r.full_name AS responder_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    LEFT JOIN users r ON i.acknowledged_by = r.id
    WHERE i.zone_id = ? AND i.status NOT IN (\'resolved\',\'closed\')
    ORDER BY CASE i.severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, i.reported_at DESC
    LIMIT 100
', [$zoneId]);

// ============================================================
// OTHER RANGERS IN MY ZONE
// ============================================================
$otherRangers = safeFetchAll($pdo, "
    SELECT u.id, u.full_name, u.badge_number, u.is_on_duty, u.phone,
           rlt.current_lat, rlt.current_lng, rlt.heading, rlt.speed,
           rlt.last_update, rlt.is_offline,
           ra.is_available, ra.current_incident_id
    FROM users u
    LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
    LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
    WHERE u.zone_id = ? AND u.role = 'ranger' AND u.is_active = 1 AND u.id != ?
    ORDER BY u.is_on_duty DESC, u.full_name
", [$zoneId, $user['id']]);

// ============================================================
// SCOUTS IN MY ZONE
// ============================================================
$scouts = safeFetchAll($pdo, "
    SELECT u.id, u.full_name, u.phone, u.is_online, u.last_seen,
           slt.current_lat, slt.current_lng, slt.last_update
    FROM users u
    LEFT JOIN scout_live_tracking slt ON u.id = slt.scout_id
    WHERE u.zone_id = ? AND u.role = 'scout' AND u.is_active = 1
    ORDER BY u.is_online DESC, u.full_name
", [$zoneId]);

// ============================================================
// STATS
// ============================================================
$stats = [
    'incidents_active'   => count($incidents),
    'incidents_critical' => count(array_filter($incidents, fn($i) => $i['severity'] === 'critical')),
    'other_rangers'      => count($otherRangers),
    'rangers_on_duty'    => count(array_filter($otherRangers, fn($r) => (int)$r['is_on_duty'] === 1)),
    'scouts_online'      => count(array_filter($scouts, fn($s) => (int)$s['is_online'] === 1)),
    'gps_points_2h'      => count($myRoute),
];

// My current assigned incident (if any)
$myCurrentIncidentId = null;
foreach ($incidents as $inc) {
    if ((int)($inc['acknowledged_by'] ?? 0) === (int)$user['id']) {
        $myCurrentIncidentId = $inc['id'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ranger Live Map - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        #map {
            height: 620px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            background: #e0e0e0;
            z-index: 1;
        }

        .dashboard-greeting { margin-bottom: 20px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 15px; }

        /* Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-item {
            background: white; padding: 12px 16px; border-radius: 10px;
            text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #f0f0f0;
        }
        .stat-item .number { font-size: 22px; font-weight: 700; color: #0d3b22; display: block; }
        .stat-item .label  { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-item .number.green  { color: #28a745; }
        .stat-item .number.red    { color: #dc3545; }
        .stat-item .number.blue   { color: #007bff; }
        .stat-item .number.orange { color: #fd7e14; }
        .stat-item .number.purple { color: #6f42c1; }

        /* Map Controls */
        .map-controls {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .map-controls .btn {
            padding: 8px 16px;
            font-size: 13px;
            border-radius: 8px;
            min-height: 40px;
        }
        .map-controls .btn .icon { font-size: 16px; }

        /* Legend */
        .map-legend {
            background: white; padding: 12px 20px; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-top: 12px;
            display: flex; gap: 18px; flex-wrap: wrap; align-items: center;
            overflow-x: auto;
        }
        .legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; white-space: nowrap; }
        .legend-dot { width: 14px; height: 14px; border-radius: 50%; display: inline-block; border: 2px solid rgba(0,0,0,0.1); }
        .legend-dot.zone        { background: #1B5E20; }
        .legend-dot.buffer      { background: rgba(255,111,0,0.4); border: 1px dashed #FF6F00; }
        .legend-dot.me          { background: #2E7D32; border: 2px solid #1B5E20; }
        .legend-dot.other-ranger{ background: #607D8B; }
        .legend-dot.scout       { background: #0277BD; }
        .legend-dot.incident    { background: #dc3545; }
        .legend-line { width: 24px; height: 3px; display: inline-block; }
        .legend-line.route { background: #2E7D32; }

        /* Info window */
        .info-window { min-width: 200px; max-width: 260px; font-family: sans-serif; font-size: 13px; }
        .info-window .title { font-weight: 700; font-size: 14px; color: #0d3b22; }

        /* Side panel */
        .side-panel {
            background: white; border-radius: 14px; padding: 18px 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0;
            margin-top: 20px;
        }
        .side-panel h3 { font-size: 15px; color: #0d3b22; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .two-col { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }

        .incident-mini {
            padding: 10px 12px; border-radius: 10px; margin-bottom: 8px;
            background: #fafafa; border-left: 4px solid #ffc107;
            transition: all 0.2s; cursor: pointer; text-decoration: none; display: block; color: inherit;
        }
        .incident-mini:hover { background: #f0f0f0; }
        .incident-mini.critical { border-left-color: #dc3545; background: #fdf5f5; }
        .incident-mini.high     { border-left-color: #fd7e14; }
        .incident-mini.medium   { border-left-color: #ffc107; }
        .incident-mini.low      { border-left-color: #28a745; }
        .incident-mini .title   { font-weight: 600; font-size: 13px; color: #0d3b22; }
        .incident-mini .meta    { font-size: 11px; color: #6c757d; margin-top: 3px; }

        .ranger-mini {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; border-radius: 8px; background: #fafafa; margin-bottom: 6px;
        }
        .ranger-mini .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: #2E7D32; color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 12px; flex-shrink: 0;
        }
        .ranger-mini.off .avatar { background: #9E9E9E; }
        .ranger-mini .info { flex: 1; min-width: 0; }
        .ranger-mini .name { font-weight: 600; font-size: 13px; color: #0d3b22; }
        .ranger-mini .status { font-size: 11px; color: #6c757d; }

        /* Debug */
        .debug-info {
            background: #fff3cd; border: 1px solid #ffc107;
            border-radius: 8px; padding: 10px 14px;
            margin-top: 14px; font-size: 12px;
            color: #856404; font-family: monospace; line-height: 1.6;
        }

        /* Toast notification */
        .toast {
            position: fixed;
            top: 80px;
            right: 20px;
            background: #1a5c3a;
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.2);
            z-index: 5000;
            font-size: 14px;
            font-weight: 600;
            opacity: 0;
            transform: translateX(120%);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 320px;
        }
        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }
        .toast.error   { background: #dc3545; }
        .toast.warning { background: #ffc107; color: #212529; }
        .toast.info    { background: #0288d1; }

        /* Mobile */
        @media (max-width: 1024px) { .two-col { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            #map { height: 420px; }
            .stats-bar { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-item { padding: 8px 12px; }
            .stat-item .number { font-size: 18px; }
            .stat-item .label { font-size: 9px; }
            .map-controls .btn .btn-text { display: none; }
            .map-legend { gap: 12px; padding: 10px 14px; flex-wrap: nowrap; overflow-x: auto; }
            .legend-item { font-size: 11px; flex-shrink: 0; }
        }
        @media (max-width: 480px) {
            #map { height: 340px; }
            .stat-item .number { font-size: 16px; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Ranger Live Map</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🗺️ Your Live Operational View</h1>
                    <p>
                        Zone: <strong><?= htmlspecialchars($zone['name'] ?? 'Unassigned') ?></strong>
                        <?= $myCurrentIncidentId ? " · <span style='color:#dc3545;font-weight:600;'>🚨 Responding to incident #{$myCurrentIncidentId}</span>" : '' ?>
                    </p>
                </div>

                <!-- Stats Bar -->
                <div class="stats-bar">
                    <div class="stat-item">
                        <span class="number red"><?= $stats['incidents_active'] ?></span>
                        <span class="label">🚨 Active Incidents</span>
                    </div>
                    <div class="stat-item">
                        <span class="number orange"><?= $stats['incidents_critical'] ?></span>
                        <span class="label">⚠️ Critical</span>
                    </div>
                    <div class="stat-item">
                        <span class="number blue"><?= $stats['rangers_on_duty'] ?>/<?= $stats['other_rangers'] ?></span>
                        <span class="label">🛡️ Rangers On Duty</span>
                    </div>
                    <div class="stat-item">
                        <span class="number green"><?= $stats['scouts_online'] ?></span>
                        <span class="label">👥 Scouts Online</span>
                    </div>
                    <div class="stat-item">
                        <span class="number purple"><?= $stats['gps_points_2h'] ?></span>
                        <span class="label">📍 GPS Points (2h)</span>
                    </div>
                </div>

                <!-- Map Controls -->
                <div class="map-controls">
                    <button class="btn btn-primary" id="btnMyLocation" onclick="centerOnMe()">
                        <span class="icon">📍</span><span class="btn-text">My Location</span>
                    </button>
                    <button class="btn btn-success" onclick="centerOnZone()">
                        <span class="icon">🟢</span><span class="btn-text">My Zone</span>
                    </button>
                    <button class="btn btn-warning" onclick="showIncidents()">
                        <span class="icon">🚨</span><span class="btn-text">Incidents</span>
                    </button>
                    <button class="btn btn-info" onclick="showRangers()">
                        <span class="icon">🛡️</span><span class="btn-text">Rangers</span>
                    </button>
                    <button class="btn btn-secondary" onclick="showScouts()">
                        <span class="icon">👥</span><span class="btn-text">Scouts</span>
                    </button>
                    <button class="btn btn-secondary" onclick="resetView()">
                        <span class="icon">🗺️</span><span class="btn-text">Reset</span>
                    </button>
                </div>

                <!-- Legend -->
                <div class="map-legend">
                    <span class="legend-item"><span class="legend-dot zone"></span> Zone Boundary</span>
                    <span class="legend-item"><span class="legend-dot buffer"></span> 500m Buffer</span>
                    <span class="legend-item"><span class="legend-line route"></span> My Patrol Route</span>
                    <span class="legend-item"><span class="legend-dot me"></span> Me</span>
                    <span class="legend-item"><span class="legend-dot other-ranger"></span> Other Rangers</span>
                    <span class="legend-item"><span class="legend-dot scout"></span> Scouts</span>
                    <span class="legend-item"><span class="legend-dot incident"></span> Incidents</span>
                </div>

                <!-- Two-column layout -->
                <div class="two-col">
                    <div id="map" style="position:relative;"></div>

                    <div class="side-panel">
                        <h3>🚨 Active Incidents (<?= count($incidents) ?>)</h3>
                        <?php if (count($incidents) > 0): ?>
                            <?php foreach (array_slice($incidents, 0, 6) as $inc): ?>
                                <div class="incident-mini <?= htmlspecialchars($inc['severity']) ?>"
                                     onclick="focusIncident(<?= (float)$inc['location_lat'] ?>, <?= (float)$inc['location_lng'] ?>, <?= (int)$inc['id'] ?>)">
                                    <div class="title">
                                        🚨 <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $inc['category']))) ?>
                                        <span style="font-weight:400;color:#6c757d;font-size:11px;">#<?= (int)$inc['id'] ?></span>
                                    </div>
                                    <div class="meta">
                                        <?= strtoupper(htmlspecialchars($inc['severity'])) ?>
                                        • 🕐 <?= timeAgo($inc['reported_at']) ?>
                                        • 👤 <?= htmlspecialchars($inc['reporter_name'] ?? 'N/A') ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="font-size:13px;color:#6c757d;text-align:center;padding:12px;">
                                ✅ No active incidents in your zone.
                            </p>
                        <?php endif; ?>

                        <h3 style="margin-top:18px;">🛡️ Other Rangers (<?= count($otherRangers) ?>)</h3>
                        <?php if (count($otherRangers) > 0): ?>
                            <?php foreach (array_slice($otherRangers, 0, 5) as $r): ?>
                                <div class="ranger-mini <?= (int)$r['is_on_duty'] === 1 ? '' : 'off' ?>"
                                     onclick="focusRanger(<?= (float)($r['current_lat'] ?? 0) ?>, <?= (float)($r['current_lng'] ?? 0) ?>, '<?= htmlspecialchars($r['full_name'], ENT_QUOTES) ?>')">
                                    <div class="avatar"><?= strtoupper(substr($r['full_name'], 0, 1)) ?></div>
                                    <div class="info">
                                        <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                                        <div class="status">
                                            <?= (int)$r['is_on_duty'] === 1 ? '🟢 On Duty' : '⚪ Off Duty' ?>
                                            <?= $r['current_incident_id'] ? ' • Incident #' . (int)$r['current_incident_id'] : '' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="font-size:13px;color:#6c757d;text-align:center;padding:12px;">
                                No other rangers in your zone.
                            </p>
                        <?php endif; ?>

                        <h3 style="margin-top:18px;">👥 Scouts (<?= count($scouts) ?>)</h3>
                        <?php if (count($scouts) > 0): ?>
                            <?php foreach (array_slice($scouts, 0, 5) as $s): ?>
                                <div class="ranger-mini <?= (int)$s['is_online'] === 1 ? '' : 'off' ?>"
                                     onclick="focusScout(<?= (float)($s['current_lat'] ?? 0) ?>, <?= (float)($s['current_lng'] ?? 0) ?>, '<?= htmlspecialchars($s['full_name'], ENT_QUOTES) ?>')">
                                    <div class="avatar" style="background:#0277BD;"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                                    <div class="info">
                                        <div class="name"><?= htmlspecialchars($s['full_name']) ?></div>
                                        <div class="status">
                                            <?= (int)$s['is_online'] === 1 ? '🟢 Online' : '⚪ Offline' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="font-size:13px;color:#6c757d;text-align:center;padding:12px;">
                                No scouts registered in your zone.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Debug block — remove once confirmed working -->
                <div class="debug-info">
                    <strong>🐛 Debug</strong><br>
                    User ID: <?= (int)$user['id'] ?> |
                    Zone ID: <?= $zoneId ?> |
                    GPS in DB: <?= $myLocation ? '✅' : '❌ (no GPS yet)' ?> |
                    Incidents: <?= count($incidents) ?> |
                    Rangers: <?= count($otherRangers) ?> |
                    Scouts: <?= count($scouts) ?> |
                    Route points: <?= count($myRoute) ?>
                </div>
            </div>
        </main>
    </div>

    <!-- TOAST -->
    <div class="toast" id="toast"></div>

    <!-- ============================================================
         SCRIPTS
         ============================================================ -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>

    <script>
        // ============================================================
        // DATA FROM PHP
        // ============================================================
        const ZONE_ID    = <?= json_encode($zoneId) ?>;
        const USER_ID    = <?= json_encode((int)$user['id']) ?>;
        const USER_NAME  = <?= json_encode($user['full_name']) ?>;
        const ZONE       = <?= json_encode($zone, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const MY_LOC     = <?= json_encode($myLocation, JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
        const MY_ROUTE   = <?= json_encode($myRoute, JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
        const INCIDENTS  = <?= json_encode($incidents, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const OTHER_RANGERS = <?= json_encode($otherRangers, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const SCOUTS     = <?= json_encode($scouts, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const WS_URL     = <?= json_encode(defined('WS_URL') ? WS_URL : 'http://localhost:3001') ?>;

        // ============================================================
        // INITIAL CENTER
        // ============================================================
        let initialCenter = [-14.5, 27.0];
        let initialZoom   = 6;

        if (MY_LOC && MY_LOC.current_lat && MY_LOC.current_lng) {
            initialCenter = [parseFloat(MY_LOC.current_lat), parseFloat(MY_LOC.current_lng)];
            initialZoom = 14;
        } else if (ZONE && (ZONE.center_lat || ZONE.boundary_center_lat)) {
            initialCenter = [
                parseFloat(ZONE.center_lat || ZONE.boundary_center_lat),
                parseFloat(ZONE.center_lng || ZONE.boundary_center_lng)
            ];
            initialZoom = 11;
        }

        // ============================================================
        // MAP
        // ============================================================
        const map = L.map('map', {
            center: initialCenter,
            zoom: initialZoom,
            zoomControl: true,
        });

        // Base layers
        const osmStandard = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap',
        });
        const esriSatellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: '&copy; Esri, Maxar' }
        );
        const osmHumanitarian = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OSM Humanitarian',
        });

        osmStandard.addTo(map);

        L.control.layers(
            {
                '🗺️ Street Map':  osmStandard,
                '🛰️ Satellite':   esriSatellite,
                '🌍 Humanitarian': osmHumanitarian,
            },
            null,
            { position: 'topright', collapsed: false }
        ).addTo(map);

        L.control.scale({ position: 'bottomleft', metric: true, imperial: false }).addTo(map);

        // ============================================================
        // LAYERS
        // ============================================================
        const layers = {
            zone: null,
            buffer: null,
            myRoute: null,
            myMarker: null,
            myAccuracyCircle: null,
            incidents: L.layerGroup().addTo(map),
            rangers: L.layerGroup().addTo(map),
            scouts: L.layerGroup().addTo(map),
        };

        // ============================================================
        // ZONE + BUFFER
        // ============================================================
        if (ZONE && ZONE.boundary_geojson && ZONE.boundary_geojson.type === 'Polygon') {
            try {
                layers.zone = L.geoJSON(ZONE.boundary_geojson, {
                    style: { color: '#1B5E20', weight: 3, fillColor: '#1B5E20', fillOpacity: 0.08 }
                }).addTo(map).bindPopup(`<strong>${ZONE.name}</strong><br>Your assigned zone`);
            } catch (e) { console.warn('Zone error', e); }
        }

        if (ZONE && ZONE.buffer_geojson && ZONE.buffer_geojson.type === 'Polygon') {
            try {
                layers.buffer = L.geoJSON(ZONE.buffer_geojson, {
                    style: { color: '#FF6F00', weight: 2, dashArray: '6,6', fillColor: '#FF6F00', fillOpacity: 0.08 }
                }).addTo(map).bindPopup(`<strong>${ZONE.name} — Buffer</strong><br>🟠 ${ZONE.buffer_radius || 500}m`);
            } catch (e) { console.warn('Buffer error', e); }
        }

        // ============================================================
        // MY PATROL ROUTE
        // ============================================================
        if (MY_ROUTE && MY_ROUTE.length >= 2) {
            const points = MY_ROUTE.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
            layers.myRoute = L.polyline(points, {
                color: '#2E7D32', weight: 4, opacity: 0.75,
            }).addTo(map).bindPopup(`🛤️ My patrol route — ${points.length} points (last 2h)`);
        }

        // ============================================================
        // MY LOCATION MARKER
        // ============================================================
        function buildMeIcon() {
            return L.divIcon({
                className: 'me-marker',
                html: `
                    <div style="position:relative;">
                        <div style="background:#2E7D32;width:30px;height:30px;border-radius:50%;
                                    border:4px solid white;box-shadow:0 2px 10px rgba(0,0,0,0.4);
                                    display:flex;align-items:center;justify-content:center;
                                    color:white;font-size:14px;font-weight:bold;">🛡️</div>
                        <div style="position:absolute;top:-4px;right:-4px;width:12px;height:12px;
                                    background:#4CAF50;border-radius:50%;border:2px solid white;
                                    animation:pulse 2s infinite;"></div>
                    </div>
                    <style>
                        @keyframes pulse {
                            0%,100% { opacity: 1; transform: scale(1); }
                            50% { opacity: 0.6; transform: scale(1.3); }
                        }
                    </style>
                `,
                iconSize: [30, 30],
                iconAnchor: [15, 15],
            });
        }

        function buildAccuracyCircle(lat, lng, accuracy) {
            return L.circle([lat, lng], {
                radius: Math.max(accuracy, 20),
                color: '#2E7D32',
                fillColor: '#4CAF50',
                fillOpacity: 0.12,
                weight: 1,
                dashArray: '4,4',
            });
        }

        // Place initial marker if we have a DB position
        if (MY_LOC && MY_LOC.current_lat && MY_LOC.current_lng) {
            const lat = parseFloat(MY_LOC.current_lat);
            const lng = parseFloat(MY_LOC.current_lng);

            layers.myMarker = L.marker([lat, lng], { icon: buildMeIcon(), zIndexOffset: 1000 }).addTo(map);
            layers.myMarker.bindPopup(`
                <div class="info-window">
                    <div class="title">🛡️ You — ${USER_NAME}</div>
                    Status: 🟢 Live (from DB)<br>
                    Speed: ${(MY_LOC.speed || 0).toFixed(1)} m/s<br>
                    Heading: ${(MY_LOC.heading || 0).toFixed(0)}°<br>
                    Updated: ${MY_LOC.last_update ? new Date(MY_LOC.last_update).toLocaleTimeString() : 'N/A'}
                </div>
            `).openPopup();
        } else if (ZONE && (ZONE.center_lat || ZONE.boundary_center_lat)) {
            // No GPS yet — placeholder at zone center
            const lat = parseFloat(ZONE.center_lat || ZONE.boundary_center_lat);
            const lng = parseFloat(ZONE.center_lng || ZONE.boundary_center_lng);
            L.circleMarker([lat, lng], {
                radius: 8, color: '#dc3545', fillColor: '#dc3545', fillOpacity: 0.7, weight: 3,
            }).addTo(map).bindPopup('⚠️ GPS not available. Click 📍 My Location to enable.');
        }

        // ============================================================
        // INCIDENTS
        // ============================================================
        const severityColors = { critical:'#dc3545', high:'#fd7e14', medium:'#ffc107', low:'#28a745' };
        const severityRadius = { critical:14, high:11, medium:8, low:6 };
        const incidentMarkers = {};

        INCIDENTS.forEach(inc => {
            if (!inc.location_lat || !inc.location_lng) return;
            const sev = inc.severity || 'medium';
            const color = severityColors[sev] || '#6c757d';
            const radius = severityRadius[sev] || 6;

            const marker = L.circleMarker(
                [parseFloat(inc.location_lat), parseFloat(inc.location_lng)],
                { radius, fillColor: color, color: '#fff', weight: 2, fillOpacity: 0.9 }
            ).addTo(layers.incidents);

            marker.bindPopup(`
                <div class="info-window">
                    <div class="title">🚨 Incident #${inc.id}</div>
                    <strong style="color:${color};">${sev.toUpperCase()}</strong><br>
                    Type: ${(inc.category || '').replace(/_/g, ' ')}<br>
                    Status: ${(inc.status || '').replace(/_/g, ' ')}<br>
                    Reporter: ${inc.reporter_name || 'N/A'}<br>
                    📞 ${inc.reporter_phone || 'N/A'}<br>
                    🕐 ${new Date(inc.reported_at).toLocaleString()}
                </div>
            `);

            incidentMarkers[inc.id] = marker;

            if (sev === 'critical') {
                let dir = 1;
                setInterval(() => {
                    const r = marker.getRadius();
                    if (r >= radius * 1.8) dir = -1;
                    if (r <= radius) dir = 1;
                    marker.setRadius(r + dir * 0.3);
                }, 50);
            }
        });

        // ============================================================
        // OTHER RANGERS
        // ============================================================
        const rangerMarkers = {};

        OTHER_RANGERS.forEach(r => {
            if (!r.current_lat || !r.current_lng) return;
            const onDuty = parseInt(r.is_on_duty) === 1;
            const color = onDuty ? '#607D8B' : '#9E9E9E';

            const marker = L.marker(
                [parseFloat(r.current_lat), parseFloat(r.current_lng)],
                {
                    icon: L.divIcon({
                        className: 'ranger-marker',
                        html: `<div style="background:${color};width:24px;height:24px;border-radius:50%;
                                    border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.4);
                                    display:flex;align-items:center;justify-content:center;
                                    color:white;font-size:11px;font-weight:bold;">R</div>`,
                        iconSize: [24, 24], iconAnchor: [12, 12],
                    })
                }
            ).addTo(layers.rangers);

            marker.bindPopup(`
                <div class="info-window">
                    <div class="title">🛡️ ${r.full_name}</div>
                    Badge: ${r.badge_number || 'N/A'}<br>
                    Status: ${onDuty ? '🟢 On Duty' : '⚪ Off Duty'}<br>
                    📞 ${r.phone || 'N/A'}<br>
                    ${r.current_incident_id ? '🚨 Responding to #' + r.current_incident_id + '<br>' : ''}
                    Updated: ${r.last_update ? new Date(r.last_update).toLocaleTimeString() : 'N/A'}
                </div>
            `);

            rangerMarkers[r.id] = marker;
        });

        // ============================================================
        // SCOUTS
        // ============================================================
        const scoutMarkers = {};

        SCOUTS.forEach(s => {
            if (!s.current_lat || !s.current_lng) return;
            const online = parseInt(s.is_online) === 1;
            const color = online ? '#0277BD' : '#9E9E9E';

            const marker = L.marker(
                [parseFloat(s.current_lat), parseFloat(s.current_lng)],
                {
                    icon: L.divIcon({
                        className: 'scout-marker',
                        html: `<div style="background:${color};width:22px;height:22px;border-radius:50%;
                                    border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);
                                    display:flex;align-items:center;justify-content:center;
                                    color:white;font-size:10px;font-weight:bold;">S</div>`,
                        iconSize: [22, 22], iconAnchor: [11, 11],
                    })
                }
            ).addTo(layers.scouts);

            marker.bindPopup(`
                <div class="info-window">
                    <div class="title">👤 ${s.full_name}</div>
                    Status: ${online ? '🟢 Online' : '⚪ Offline'}<br>
                    📞 ${s.phone || 'N/A'}<br>
                    Last seen: ${s.last_seen ? new Date(s.last_seen).toLocaleTimeString() : 'N/A'}
                </div>
            `);

            scoutMarkers[s.id] = marker;
        });

        // ============================================================
        // TOAST HELPER
        // ============================================================
        function showToast(message, type = 'info', duration = 3500) {
            const toast = document.getElementById('toast');
            const icons = { info: 'ℹ️', success: '✅', error: '❌', warning: '⚠️' };
            toast.className = 'toast ' + type;
            toast.innerHTML = `<span style="font-size:18px;">${icons[type] || 'ℹ️'}</span> <span>${message}</span>`;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), duration);
        }

        // ============================================================
        // 📍 MY LOCATION — WORKING
        // ============================================================
        function centerOnMe() {
            const btn = document.getElementById('btnMyLocation');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="icon">⏳</span><span class="btn-text">Locating…</span>';
            btn.disabled = true;

            if (!navigator.geolocation) {
                showToast('GPS not supported by your browser', 'error');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                return;
            }

            navigator.geolocation.getCurrentPosition(
                pos => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    const accuracy = pos.coords.accuracy;

                    // Update map
                    map.setView([lat, lng], 16, { animate: true });

                    // Update or create marker
                    if (!layers.myMarker) {
                        layers.myMarker = L.marker([lat, lng], {
                            icon: buildMeIcon(),
                            zIndexOffset: 1000
                        }).addTo(map);
                        layers.myMarker.bindPopup(`
                            <div class="info-window">
                                <div class="title">🛡️ You — ${USER_NAME}</div>
                                Status: 🟢 Live GPS<br>
                                📍 ${lat.toFixed(6)}, ${lng.toFixed(6)}<br>
                                🎯 Accuracy: ±${accuracy.toFixed(0)}m<br>
                                Updated: ${new Date().toLocaleTimeString()}
                            </div>
                        `);
                    } else {
                        layers.myMarker.setLatLng([lat, lng]);
                    }

                    // Update popup
                    layers.myMarker.setPopupContent(`
                        <div class="info-window">
                            <div class="title">🛡️ You — ${USER_NAME}</div>
                            Status: 🟢 Live GPS<br>
                            📍 ${lat.toFixed(6)}, ${lng.toFixed(6)}<br>
                            🎯 Accuracy: ±${accuracy.toFixed(0)}m<br>
                            Updated: ${new Date().toLocaleTimeString()}
                        </div>
                    `);
                    layers.myMarker.openPopup();

                    // Accuracy circle
                    if (layers.myAccuracyCircle) {
                        layers.myAccuracyCircle.setLatLng([lat, lng]);
                        layers.myAccuracyCircle.setRadius(Math.max(accuracy, 20));
                    } else {
                        layers.myAccuracyCircle = buildAccuracyCircle(lat, lng, accuracy).addTo(map);
                    }

                    showToast(`Located! Accuracy ±${accuracy.toFixed(0)}m`, 'success');
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;

                    // Send to server so others can see the ranger
                    fetch('update_location.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            lat: lat,
                            lng: lng,
                            heading: pos.coords.heading || 0,
                            speed: pos.coords.speed || 0,
                        })
                    }).catch(() => {});
                },
                err => {
                    let msg = 'Could not get location';
                    if (err.code === 1) msg = 'Location permission denied. Enable it in browser settings.';
                    if (err.code === 2) msg = 'Location unavailable. Check your GPS.';
                    if (err.code === 3) msg = 'Location request timed out.';
                    showToast(msg, 'error', 5000);
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                },
                { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
            );
        }

        // ============================================================
        // 🟢 MY ZONE
        // ============================================================
        function centerOnZone() {
            if (ZONE && ZONE.boundary_geojson) {
                try {
                    const bounds = L.geoJSON(ZONE.boundary_geojson).getBounds();
                    if (bounds.isValid()) {
                        map.fitBounds(bounds, { padding: [40, 40], animate: true });
                        showToast('Centered on ' + (ZONE.name || 'your zone'), 'success');
                        return;
                    }
                } catch (e) {}
            }
            if (ZONE && (ZONE.center_lat || ZONE.boundary_center_lat)) {
                const lat = parseFloat(ZONE.center_lat || ZONE.boundary_center_lat);
                const lng = parseFloat(ZONE.center_lng || ZONE.boundary_center_lng);
                map.setView([lat, lng], 11, { animate: true });
                showToast('Centered on zone', 'success');
                return;
            }
            showToast('No zone boundary available', 'warning');
        }

        // ============================================================
        // 🚨 INCIDENTS
        // ============================================================
        function showIncidents() {
            const valid = INCIDENTS
                .filter(i => i.location_lat && i.location_lng)
                .map(i => [parseFloat(i.location_lat), parseFloat(i.location_lng)]);

            if (valid.length === 0) {
                showToast('No active incidents to display', 'warning');
                return;
            }
            const bounds = L.latLngBounds(valid);
            map.fitBounds(bounds, { padding: [60, 60], animate: true });
            showToast(`Showing ${valid.length} incident${valid.length > 1 ? 's' : ''}`, 'info');
        }

        // ============================================================
        // 🛡️ RANGERS
        // ============================================================
        function showRangers() {
            const valid = OTHER_RANGERS
                .filter(r => r.current_lat && r.current_lng)
                .map(r => [parseFloat(r.current_lat), parseFloat(r.current_lng)]);

            if (valid.length === 0) {
                showToast('No other rangers with live locations', 'warning');
                return;
            }

            // Include my own location
            if (layers.myMarker) {
                const p = layers.myMarker.getLatLng();
                valid.push([p.lat, p.lng]);
            }

            const bounds = L.latLngBounds(valid);
            map.fitBounds(bounds, { padding: [60, 60], animate: true });
            showToast(`Showing ${valid.length} ranger${valid.length > 1 ? 's' : ''}`, 'info');
        }

        // ============================================================
        // 👥 SCOUTS
        // ============================================================
        function showScouts() {
            const valid = SCOUTS
                .filter(s => s.current_lat && s.current_lng && parseInt(s.is_online) === 1)
                .map(s => [parseFloat(s.current_lat), parseFloat(s.current_lng)]);

            if (valid.length === 0) {
                showToast('No online scouts with locations', 'warning');
                return;
            }
            const bounds = L.latLngBounds(valid);
            map.fitBounds(bounds, { padding: [60, 60], animate: true });
            showToast(`Showing ${valid.length} online scout${valid.length > 1 ? 's' : ''}`, 'info');
        }

        // ============================================================
        // 🗺️ RESET
        // ============================================================
        function resetView() {
            map.setView(initialCenter, initialZoom, { animate: true });
            showToast('View reset', 'info');
        }

        // ============================================================
        // FOCUS HELPERS (used by side panel)
        // ============================================================
        function focusIncident(lat, lng, id) {
            if (!lat || !lng) return;
            map.setView([lat, lng], 16, { animate: true });
            const m = incidentMarkers[id];
            if (m) setTimeout(() => m.openPopup(), 500);
            showToast('Focused on incident #' + id, 'info');
        }

        function focusRanger(lat, lng, name) {
            if (!lat || !lng) {
                showToast('This ranger has no GPS position yet', 'warning');
                return;
            }
            map.setView([lat, lng], 16, { animate: true });
            showToast('Focused on ' + name, 'info');
        }

        function focusScout(lat, lng, name) {
            if (!lat || !lng) {
                showToast('This scout has no GPS position yet', 'warning');
                return;
            }
            map.setView([lat, lng], 16, { animate: true });
            showToast('Focused on ' + name, 'info');
        }

        // ============================================================
        // WEBSOCKET LIVE UPDATES
        // ============================================================
        try {
            const ws = io(WS_URL, {
                auth: { userId: USER_ID, role: 'ranger', zoneId: ZONE_ID }
            });

            ws.on('connect', () => console.log('✅ WS connected'));
            ws.on('disconnect', () => console.log('❌ WS disconnected'));

            // My own or another ranger's position update
            ws.on('ranger-location', data => {
                if (data.ranger_id === USER_ID) {
                    if (layers.myMarker && data.location) {
                        layers.myMarker.setLatLng([data.location.lat, data.location.lng]);
                    }
                    return;
                }

                // Another ranger
                const r = OTHER_RANGERS.find(x => x.id == data.ranger_id);
                if (r && data.location) {
                    r.current_lat = data.location.lat;
                    r.current_lng = data.location.lng;

                    if (rangerMarkers[data.ranger_id]) {
                        rangerMarkers[data.ranger_id].setLatLng([data.location.lat, data.location.lng]);
                    }
                }
            });

            // Scout location updates
            ws.on('scout-location', data => {
                const s = SCOUTS.find(x => x.id == data.scout_id);
                if (s && data.location) {
                    s.current_lat = data.location.lat;
                    s.current_lng = data.location.lng;
                    s.is_online = 1;

                    if (scoutMarkers[data.scout_id]) {
                        scoutMarkers[data.scout_id].setLatLng([data.location.lat, data.location.lng]);
                    }
                }
            });

            // New incident
            ws.on('new-incident', inc => {
                if (inc.zone_id != ZONE_ID) return;
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('🚨 New Incident', {
                        body: `${inc.category} — ${inc.severity}`,
                        tag: 'incident-' + inc.id,
                    });
                }
                showToast(`🚨 New incident in your zone: ${inc.category}`, 'warning', 6000);
                setTimeout(() => window.location.reload(), 2500);
            });
        } catch (e) {
            console.warn('WebSocket unavailable:', e);
        }

        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // ============================================================
        // AUTO GPS PUSH (every 15s)
        // ============================================================
        if (navigator.geolocation) {
            navigator.geolocation.watchPosition(
                pos => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;

                    // Update marker
                    if (layers.myMarker) {
                        layers.myMarker.setLatLng([lat, lng]);
                    }
                    if (layers.myAccuracyCircle) {
                        layers.myAccuracyCircle.setLatLng([lat, lng]);
                        layers.myAccuracyCircle.setRadius(Math.max(pos.coords.accuracy || 20, 20));
                    }

                    // Send to server
                    fetch('update_location.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            lat: lat,
                            lng: lng,
                            heading: pos.coords.heading || 0,
                            speed: pos.coords.speed || 0,
                        })
                    }).catch(() => {});
                },
                () => {},
                { enableHighAccuracy: true, maximumAge: 5000, timeout: 15000 }
            );
        }

        // ============================================================
        // RESPONSIVE
        // ============================================================
        window.addEventListener('resize', () => map.invalidateSize());
        document.addEventListener('sidebarToggled', () => setTimeout(() => map.invalidateSize(), 400));

        console.log('✅ Ranger live map loaded');
        console.log('📍 Zone:', <?= json_encode($zone['name'] ?? 'N/A') ?>);
        console.log('📍 My GPS:', MY_LOC ? '✅' : '❌');
        console.log('📍 Incidents:', INCIDENTS.length);
        console.log('📍 Rangers:', OTHER_RANGERS.length);
        console.log('📍 Scouts:', SCOUTS.length);
    </script>
</body>
</html>
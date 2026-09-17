<?php
// ============================================================
// supervisor/map.php
// Zone Supervisor — Live Operational Map
// ============================================================
// Shows:
//   - Zone boundary + 500m buffer
//   - Rangers in zone (on duty / off duty)
//   - Scouts in zone (online / offline)
//   - Active incidents
//   - AI anomalies (last 24h)
//   - Assigned patrol routes
//
// JSON endpoints (used by assets/js/ai-tracker.js):
//   ?ajax=ai_scan    → { success, zone_id, anomalies, stats, ts }
//   ?ajax=snapshot   → full dashboard state
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
        } catch (PDOException $e) {
            error_log('[WS-SUPERVISOR-MAP] ' . $e->getMessage());
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
if (!function_exists('safeExec')) {
    function safeExec(PDO $pdo, string $sql, array $params = []): bool {
        try { $stmt = $pdo->prepare($sql); return $stmt->execute($params); }
        catch (PDOException $e) { return false; }
    }
}

// ============================================================
// DATA BUILDERS (shared between HTML render + JSON endpoints)
// ============================================================
function ws_fetchZone(PDO $pdo, int $zoneId): ?array {
    $rows = safeFetchAll($pdo, "SELECT * FROM zones WHERE id = ? LIMIT 1", [$zoneId]);
    if (!$rows) return null;
    $zone = $rows[0];
    if (!empty($zone['boundary_geojson'])) {
        $boundary = is_string($zone['boundary_geojson'])
            ? json_decode($zone['boundary_geojson'], true)
            : $zone['boundary_geojson'];
        if ($boundary && isset($boundary['type'])) {
            $zone['boundary_geojson'] = $boundary;
            if (function_exists('computeBufferZone')) {
                $zone['buffer_geojson'] = computeBufferZone($boundary, (int)($zone['buffer_radius'] ?? 500));
            }
        }
    }
    return $zone;
}

function ws_fetchRangers(PDO $pdo, int $zoneId): array {
    return safeFetchAll($pdo, "
        SELECT u.id, u.full_name, u.badge_number, u.phone, u.is_on_duty,
               rlt.current_lat, rlt.current_lng, rlt.heading, rlt.speed,
               rlt.last_update, rlt.is_offline,
               ra.is_available, ra.current_incident_id
        FROM users u
        LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
        LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
        WHERE u.zone_id = ? AND u.role = 'ranger' AND u.is_active = 1
        ORDER BY u.is_on_duty DESC, u.full_name
    ", [$zoneId]);
}

function ws_fetchScouts(PDO $pdo, int $zoneId): array {
    return safeFetchAll($pdo, "
        SELECT u.id, u.full_name, u.phone, u.is_online, u.last_seen,
               slt.current_lat, slt.current_lng, slt.last_update
        FROM users u
        LEFT JOIN scout_live_tracking slt ON u.id = slt.scout_id
        WHERE u.zone_id = ? AND u.role = 'scout' AND u.is_active = 1
        ORDER BY u.is_online DESC, u.full_name
    ", [$zoneId]);
}

function ws_fetchIncidents(PDO $pdo, int $zoneId): array {
    return safeFetchAll($pdo, '
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
}

function ws_fetchAIAnomalies(PDO $pdo, int $zoneId): array {
    return safeFetchAll($pdo, '
        SELECT a.id, a.type, a.severity, a.description, a.confidence,
               a.location_lat, a.location_lng, a.radius_meters,
               a.detected_at,
               u.full_name AS subject_name, u.role AS subject_role
        FROM ai_anomalies a
        LEFT JOIN users u ON (a.ranger_id = u.id OR a.scout_id = u.id)
        WHERE a.zone_id = ? AND a.detected_at >= (NOW() - (24) * INTERVAL \'1 hour\')
        ORDER BY a.detected_at DESC
        LIMIT 50
    ', [$zoneId]);
}

function ws_fetchPatrolRoutes(PDO $pdo, array $rangers): array {
    $routes = [];
    foreach ($rangers as $r) {
        $routes[$r['id']] = safeFetchAll($pdo, '
            SELECT lat, lng, timestamp
            FROM ranger_location_history
            WHERE ranger_id = ? AND timestamp >= (NOW() - (2) * INTERVAL \'1 hour\')
            ORDER BY timestamp ASC
        ', [$r['id']]);
    }
    return $routes;
}

// ============================================================
// JSON ENDPOINT: ?ajax=ai_scan
// ------------------------------------------------------------
// Returns ONLY the AI anomalies + a small stats block.
// Supports ETag / If-None-Match so a 304 can be sent back.
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ai_scan') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=0, must-revalidate');

    try {
        $zoneId    = (int)($_GET['zone_id'] ?? $activeZoneId);
        $anomalies = ws_fetchAIAnomalies($pdo, $zoneId);

        $stats = [
            'total'    => count($anomalies),
            'critical' => count(array_filter($anomalies, fn($a) => ($a['severity'] ?? '') === 'critical')),
            'high'     => count(array_filter($anomalies, fn($a) => ($a['severity'] ?? '') === 'high')),
            'pending'  => count(array_filter($anomalies, fn($a) => empty($a['is_acknowledged']))),
        ];

        // Cheap ETag — latest anomaly id + count
        $latestId = $anomalies[0]['id'] ?? 0;
        $etag     = '"ai-' . $zoneId . '-' . $latestId . '-' . count($anomalies) . '"';
        header('ETag: ' . $etag);

        $clientEtag = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            http_response_code(304);
            exit;
        }

        echo json_encode([
            'success'   => true,
            'zone_id'   => $zoneId,
            'anomalies' => $anomalies,
            'stats'     => $stats,
            'ts'        => time(),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// JSON ENDPOINT: ?ajax=snapshot
// ------------------------------------------------------------
// Full state for the dashboard — used by refresh helpers.
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'snapshot') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=0, must-revalidate');

    try {
        $zoneId = (int)($_GET['zone_id'] ?? $activeZoneId);

        $zone      = ws_fetchZone($pdo, $zoneId);
        $rangers   = ws_fetchRangers($pdo, $zoneId);
        $scouts    = ws_fetchScouts($pdo, $zoneId);
        $incidents = ws_fetchIncidents($pdo, $zoneId);
        $aiAnom    = ws_fetchAIAnomalies($pdo, $zoneId);
        $patrol    = ws_fetchPatrolRoutes($pdo, $rangers);

        $stats = [
            'rangers_on_duty'  => count(array_filter($rangers,   fn($r) => (int)$r['is_on_duty'] === 1)),
            'rangers_total'    => count($rangers),
            'scouts_online'    => count(array_filter($scouts,    fn($s) => (int)$s['is_online'] === 1)),
            'scouts_total'     => count($scouts),
            'active_incidents' => count($incidents),
            'critical'         => count(array_filter($incidents, fn($i) => $i['severity'] === 'critical')),
            'ai_anomalies'     => count($aiAnom),
        ];

        echo json_encode([
            'success'   => true,
            'zone_id'   => $zoneId,
            'zone'      => $zone,
            'rangers'   => $rangers,
            'scouts'    => $scouts,
            'incidents' => $incidents,
            'anomalies' => $aiAnom,
            'patrol'    => $patrol,
            'stats'     => $stats,
            'ts'        => time(),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// NORMAL PAGE RENDER — fetch everything once
// ============================================================
$zone        = ws_fetchZone($pdo, $activeZoneId);
$rangers     = ws_fetchRangers($pdo, $activeZoneId);
$scouts      = ws_fetchScouts($pdo, $activeZoneId);
$incidents   = ws_fetchIncidents($pdo, $activeZoneId);
$aiAnomalies = ws_fetchAIAnomalies($pdo, $activeZoneId);
$patrolRoutes = ws_fetchPatrolRoutes($pdo, $rangers);

$stats = [
    'rangers_on_duty'  => count(array_filter($rangers,   fn($r) => (int)$r['is_on_duty'] === 1)),
    'rangers_total'    => count($rangers),
    'scouts_online'    => count(array_filter($scouts,    fn($s) => (int)$s['is_online'] === 1)),
    'scouts_total'     => count($scouts),
    'active_incidents' => count($incidents),
    'critical'         => count(array_filter($incidents, fn($i) => $i['severity'] === 'critical')),
    'ai_anomalies'     => count($aiAnomalies),
];

// Sound preference — ?sound=1 or ?sound=0 overrides the default
$soundEnabled = true;
if (isset($_GET['sound'])) {
    $soundEnabled = $_GET['sound'] === '1';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zone Map - Supervisor - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        #map {
            height: 620px;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            z-index: 1;
            background: #e0e0e0;
        }

        .dashboard-greeting { margin-bottom: 20px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 15px; }

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

        .map-controls { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .map-controls .btn { padding: 8px 16px; font-size: 13px; border-radius: 8px; min-height: 40px; }

        .map-legend {
            background: white; padding: 12px 20px; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-top: 12px;
            display: flex; gap: 18px; flex-wrap: wrap; align-items: center;
            overflow-x: auto;
        }
        .legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; white-space: nowrap; }
        .legend-dot { width: 14px; height: 14px; border-radius: 50%; display: inline-block; border: 2px solid rgba(0,0,0,0.1); }
        .legend-dot.zone       { background: #1B5E20; }
        .legend-dot.buffer     { background: rgba(255,111,0,0.4); border: 1px dashed #FF6F00; }
        .legend-dot.ranger     { background: #2E7D32; }
        .legend-dot.ranger-off { background: #9E9E9E; }
        .legend-dot.scout      { background: #0277BD; }
        .legend-dot.incident   { background: #dc3545; }
        .legend-dot.ai         { background: #9C27B0; }
        .legend-line { width: 24px; height: 3px; display: inline-block; }
        .legend-line.route { background: #2E7D32; }

        .info-window { min-width: 200px; max-width: 260px; font-family: sans-serif; font-size: 13px; }

        .live-dot { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: #28a745; font-weight: 600; }
        .live-dot .dot { width: 8px; height: 8px; border-radius: 50%; background: #28a745; animation: pulseDot 1.6s infinite; }
        @keyframes pulseDot { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.7); } }

        .debug-info {
            background: #fff3cd; border: 1px solid #ffc107;
            border-radius: 8px; padding: 10px 14px; margin-top: 14px;
            font-size: 12px; color: #856404; font-family: monospace;
        }

        /* Toast for new anomalies */
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 3000; display: flex; flex-direction: column; gap: 10px; max-width: 340px; }
        .toast { background: white; border-radius: 12px; padding: 12px 16px; box-shadow: 0 8px 30px rgba(0,0,0,0.15); border-left: 4px solid #9C27B0; animation: toastIn 0.3s ease; display: flex; gap: 10px; align-items: flex-start; }
        .toast.critical { border-left-color: #dc3545; background: #fdf5f5; }
        .toast .t-icon { font-size: 20px; flex-shrink: 0; }
        .toast .t-body { flex: 1; min-width: 0; }
        .toast .t-title { font-size: 13px; font-weight: 700; color: #0d3b22; }
        .toast .t-desc  { font-size: 11.5px; color: #6c757d; margin-top: 2px; line-height: 1.4; }
        .toast .t-close { background: none; border: none; color: #adb5bd; cursor: pointer; font-size: 16px; padding: 0; }
        @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }

        @media (max-width: 768px) {
            #map { height: 440px; }
            .stats-bar { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-item { padding: 8px 12px; }
            .stat-item .number { font-size: 18px; }
            .stat-item .label { font-size: 9px; }
            .map-controls .btn .btn-text { display: none; }
            .map-legend { gap: 12px; padding: 10px 14px; flex-wrap: nowrap; overflow-x: auto; }
            .legend-item { font-size: 11px; flex-shrink: 0; }
            .toast-container { left: 12px; right: 12px; max-width: none; }
        }
        @media (max-width: 480px) {
            #map { height: 360px; }
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
                <h1>Zone Map</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting" style="display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap;">
                    <div>
                        <h1>🗺️ Live Zone Map</h1>
                        <p>Real-time view of <strong><?= htmlspecialchars($zone['name'] ?? 'your zone') ?></strong> — rangers, scouts, incidents, and AI anomalies.</p>
                    </div>
                    <span class="live-dot" id="liveIndicator"><span class="dot"></span> Live — refreshing anomalies every 60s</span>
                </div>

                <div class="stats-bar">
                    <div class="stat-item">
                        <span class="number green" id="statRangers"><?= $stats['rangers_on_duty'] ?>/<?= $stats['rangers_total'] ?></span>
                        <span class="label">🛡️ Rangers On Duty</span>
                    </div>
                    <div class="stat-item">
                        <span class="number blue" id="statScouts"><?= $stats['scouts_online'] ?>/<?= $stats['scouts_total'] ?></span>
                        <span class="label">👥 Scouts Online</span>
                    </div>
                    <div class="stat-item">
                        <span class="number orange" id="statIncidents"><?= $stats['active_incidents'] ?></span>
                        <span class="label">🚨 Active Incidents</span>
                    </div>
                    <div class="stat-item">
                        <span class="number red" id="statCritical"><?= $stats['critical'] ?></span>
                        <span class="label">⚠️ Critical</span>
                    </div>
                    <div class="stat-item">
                        <span class="number purple" id="statAI"><?= $stats['ai_anomalies'] ?></span>
                        <span class="label">🤖 AI Anomalies</span>
                    </div>
                </div>

                <div class="map-controls">
                    <button class="btn btn-primary" onclick="centerOnZone()">
                        <span class="icon">🟢</span><span class="btn-text">My Zone</span>
                    </button>
                    <button class="btn btn-success" onclick="showRangers()">
                        <span class="icon">🛡️</span><span class="btn-text">Rangers</span>
                    </button>
                    <button class="btn btn-info" onclick="showScouts()">
                        <span class="icon">👥</span><span class="btn-text">Scouts</span>
                    </button>
                    <button class="btn btn-warning" onclick="showIncidents()">
                        <span class="icon">🚨</span><span class="btn-text">Incidents</span>
                    </button>
                    <button class="btn btn-secondary" onclick="resetView()">
                        <span class="icon">🗺️</span><span class="btn-text">Reset</span>
                    </button>
                </div>

                <div class="map-legend">
                    <span class="legend-item"><span class="legend-dot zone"></span> Zone Boundary</span>
                    <span class="legend-item"><span class="legend-dot buffer"></span> 500m Buffer</span>
                    <span class="legend-item"><span class="legend-line route"></span> Patrol Routes</span>
                    <span class="legend-item"><span class="legend-dot ranger"></span> Rangers On Duty</span>
                    <span class="legend-item"><span class="legend-dot ranger-off"></span> Rangers Off Duty</span>
                    <span class="legend-item"><span class="legend-dot scout"></span> Scouts</span>
                    <span class="legend-item"><span class="legend-dot incident"></span> Incidents</span>
                    <span class="legend-item"><span class="legend-dot ai"></span> AI Anomaly</span>
                </div>

                <div id="map" style="position:relative;"></div>

                <div class="debug-info">
                    🐛 Zone ID: <?= $activeZoneId ?> |
                    Rangers: <?= count($rangers) ?> |
                    Scouts: <?= count($scouts) ?> |
                    Incidents: <?= count($incidents) ?> |
                    AI: <?= count($aiAnomalies) ?> |
                    DB: <?= htmlspecialchars(defined('DB_NAME') ? DB_NAME : '?') ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Toast container -->
    <div class="toast-container" id="toastContainer"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>

    <script>
        // ============================================================
        // DATA FROM PHP
        // ============================================================
        const ZONE_ID    = <?= json_encode($activeZoneId) ?>;
        const USER_ID    = <?= json_encode((int)$user['id']) ?>;
        const ZONE       = <?= json_encode($zone, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        let   RANGERS    = <?= json_encode($rangers, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        let   SCOUTS     = <?= json_encode($scouts, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const INCIDENTS  = <?= json_encode($incidents, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        let   AI_ANOM    = <?= json_encode($aiAnomalies, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const PATROL     = <?= json_encode($patrolRoutes, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const WS_URL     = <?= json_encode(defined('WS_URL') ? WS_URL : 'http://localhost:3001') ?>;

        // Sound flag — read by ai-tracker.js
        window.SupervisorSoundEnabled = <?= $soundEnabled ? 'true' : 'false' ?>;

        // ============================================================
        // INIT MAP
        // ============================================================
        let center = [-14.5, 27.0];
        let zoom   = 6;

        if (ZONE && (ZONE.center_lat || ZONE.boundary_center_lat)) {
            center = [
                parseFloat(ZONE.center_lat || ZONE.boundary_center_lat),
                parseFloat(ZONE.center_lng || ZONE.boundary_center_lng)
            ];
            zoom = 11;
        }

        const map = L.map('map', { center, zoom });

        const osmStandard = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
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
        // LAYER GROUPS
        // ============================================================
        const layers = {
            zone: null,
            buffer: null,
            patrolRoutes: L.layerGroup().addTo(map),
            rangers: L.layerGroup().addTo(map),
            scouts: L.layerGroup().addTo(map),
            incidents: L.layerGroup().addTo(map),
            ai: L.layerGroup().addTo(map),
        };

        // ============================================================
        // ZONE BOUNDARY + BUFFER
        // ============================================================
        if (ZONE && ZONE.boundary_geojson && ZONE.boundary_geojson.type === 'Polygon') {
            try {
                layers.zone = L.geoJSON(ZONE.boundary_geojson, {
                    style: {
                        color: '#1B5E20', weight: 3,
                        fillColor: '#1B5E20', fillOpacity: 0.08,
                    }
                }).addTo(map);
                layers.zone.bindPopup(`<strong>${ZONE.name}</strong><br>Your zone`);
            } catch (e) { console.warn('Zone error', e); }
        }

        if (ZONE && ZONE.buffer_geojson && ZONE.buffer_geojson.type === 'Polygon') {
            try {
                layers.buffer = L.geoJSON(ZONE.buffer_geojson, {
                    style: {
                        color: '#FF6F00', weight: 2, dashArray: '6,6',
                        fillColor: '#FF6F00', fillOpacity: 0.08,
                    }
                }).addTo(map);
                layers.buffer.bindPopup(`<strong>${ZONE.name} — Buffer</strong><br>🟠 ${ZONE.buffer_radius || 500}m`);
            } catch (e) { console.warn('Buffer error', e); }
        }

        // ============================================================
        // PATROL ROUTES
        // ============================================================
        Object.entries(PATROL).forEach(([rangerId, route]) => {
            if (!route || route.length < 2) return;
            const points = route.map(p => [parseFloat(p.lat), parseFloat(p.lng)]);
            L.polyline(points, {
                color: '#2E7D32', weight: 3, opacity: 0.7,
            }).addTo(layers.patrolRoutes);
        });

        // ============================================================
        // ICON BUILDERS
        // ============================================================
        function rangerIcon(onDuty) {
            const color = onDuty ? '#2E7D32' : '#9E9E9E';
            return L.divIcon({
                className: 'ranger-marker',
                html: `<div style="position:relative;">
                    <div style="background:${color};width:26px;height:26px;border-radius:50%;
                                border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.4);
                                display:flex;align-items:center;justify-content:center;
                                color:white;font-size:11px;font-weight:bold;">R</div>
                    ${onDuty ? '<div style="position:absolute;top:-2px;right:-2px;width:10px;height:10px;background:#4CAF50;border-radius:50%;border:2px solid white;"></div>' : ''}
                </div>`,
                iconSize: [26, 26], iconAnchor: [13, 13],
            });
        }

        function scoutIcon(online) {
            const color = online ? '#0277BD' : '#9E9E9E';
            return L.divIcon({
                className: 'scout-marker',
                html: `<div style="background:${color};width:22px;height:22px;border-radius:50%;
                            border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);
                            display:flex;align-items:center;justify-content:center;
                            color:white;font-size:10px;font-weight:bold;">S</div>`,
                iconSize: [22, 22], iconAnchor: [11, 11],
            });
        }

        // ============================================================
        // RANGERS — initial render
        // ============================================================
        function renderRangers() {
            layers.rangers.clearLayers();
            RANGERS.forEach(r => {
                if (!r.current_lat || !r.current_lng) return;
                const onDuty = parseInt(r.is_on_duty) === 1;

                L.marker(
                    [parseFloat(r.current_lat), parseFloat(r.current_lng)],
                    { icon: rangerIcon(onDuty) }
                ).addTo(layers.rangers).bindPopup(`
                    <div class="info-window">
                        <strong>🛡️ ${r.full_name}</strong><br>
                        Badge: ${r.badge_number || 'N/A'}<br>
                        Status: ${onDuty ? '🟢 On Duty' : '⚪ Off Duty'}<br>
                        📞 ${r.phone || 'N/A'}<br>
                        ${r.current_incident_id ? '🚨 Responding to #' + r.current_incident_id + '<br>' : ''}
                        Updated: ${r.last_update ? new Date(r.last_update).toLocaleTimeString() : 'N/A'}
                    </div>
                `);
            });
        }
        renderRangers();

        // ============================================================
        // SCOUTS — initial render
        // ============================================================
        function renderScouts() {
            layers.scouts.clearLayers();
            SCOUTS.forEach(s => {
                if (!s.current_lat || !s.current_lng) return;
                const online = parseInt(s.is_online) === 1;

                L.marker(
                    [parseFloat(s.current_lat), parseFloat(s.current_lng)],
                    { icon: scoutIcon(online) }
                ).addTo(layers.scouts).bindPopup(`
                    <div class="info-window">
                        <strong>👤 ${s.full_name}</strong><br>
                        Status: ${online ? '🟢 Online' : '⚪ Offline'}<br>
                        📞 ${s.phone || 'N/A'}<br>
                        Last seen: ${s.last_seen ? new Date(s.last_seen).toLocaleTimeString() : 'N/A'}
                    </div>
                `);
            });
        }
        renderScouts();

        // ============================================================
        // INCIDENTS
        // ============================================================
        const sevColors = { critical:'#dc3545', high:'#fd7e14', medium:'#ffc107', low:'#28a745' };
        const sevRadius = { critical:14, high:11, medium:8, low:6 };

        INCIDENTS.forEach(inc => {
            if (!inc.location_lat || !inc.location_lng) return;
            const sev = inc.severity || 'medium';
            const color = sevColors[sev] || '#6c757d';
            const radius = sevRadius[sev] || 6;

            const marker = L.circleMarker(
                [parseFloat(inc.location_lat), parseFloat(inc.location_lng)],
                { radius, fillColor: color, color: '#fff', weight: 2, fillOpacity: 0.9 }
            ).addTo(layers.incidents);

            marker.bindPopup(`
                <div class="info-window">
                    <strong>🚨 Incident #${inc.id}</strong><br>
                    <strong style="color:${color};">${sev.toUpperCase()}</strong><br>
                    Type: ${(inc.category || '').replace(/_/g, ' ')}<br>
                    Status: ${(inc.status || '').replace(/_/g, ' ')}<br>
                    Reporter: ${inc.reporter_name || 'N/A'}<br>
                    📞 ${inc.reporter_phone || 'N/A'}<br>
                    ${inc.responder_name ? 'Responder: ' + inc.responder_name + '<br>' : ''}
                    🕐 ${new Date(inc.reported_at).toLocaleString()}
                </div>
            `);

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
        // AI ANOMALIES — initial render + refresh helper
        // ============================================================
        function renderAIAnomalies(list) {
            layers.ai.clearLayers();
            (list || []).forEach(a => {
                if (!a.location_lat || !a.location_lng) return;
                L.circle([parseFloat(a.location_lat), parseFloat(a.location_lng)], {
                    radius: a.radius_meters || 200,
                    color: '#9C27B0',
                    fillColor: '#9C27B0',
                    fillOpacity: 0.15,
                    weight: 2,
                    dashArray: '4,4',
                }).addTo(layers.ai).bindPopup(`
                    <div class="info-window">
                        <strong>🤖 AI Anomaly</strong><br>
                        Type: ${(a.type || '').replace(/_/g, ' ')}<br>
                        Severity: ${a.severity || 'N/A'}<br>
                        Confidence: ${Math.round((a.confidence || 0) * 100)}%<br>
                        Subject: ${a.subject_name || 'Unknown'}<br>
                        ${a.description || ''}
                    </div>
                `);
            });
        }
        renderAIAnomalies(AI_ANOM);

        // ============================================================
        // BRIDGE FOR ai-tracker.js
        // ------------------------------------------------------------
        // When ai-tracker.js fetches new anomalies, it calls:
        //   window.SupervisorMap.refreshData({ anomalies, stats })
        // ============================================================
        window.SupervisorMap = {
            refreshData: function (payload) {
                if (!payload) return;

                if (Array.isArray(payload.anomalies)) {
                    AI_ANOM = payload.anomalies;
                    renderAIAnomalies(AI_ANOM);
                    const aiEl = document.getElementById('statAI');
                    if (aiEl) aiEl.textContent = AI_ANOM.length;
                }

                if (payload.stats) {
                    const s = payload.stats;
                    if (typeof s.total === 'number') {
                        const aiEl = document.getElementById('statAI');
                        if (aiEl) aiEl.textContent = s.total;
                    }
                }
            },

            // Full state refresh from ?ajax=snapshot
            refreshSnapshot: function (data) {
                if (!data || !data.success) return;
                if (Array.isArray(data.rangers))   { RANGERS = data.rangers;   renderRangers(); }
                if (Array.isArray(data.scouts))    { SCOUTS  = data.scouts;    renderScouts(); }
                if (Array.isArray(data.anomalies)) { AI_ANOM = data.anomalies; renderAIAnomalies(AI_ANOM); }
                if (data.stats) {
                    const s = data.stats;
                    const mapEl = (id) => document.getElementById(id);
                    if (mapEl('statRangers'))   mapEl('statRangers').textContent   = s.rangers_on_duty + '/' + s.rangers_total;
                    if (mapEl('statScouts'))    mapEl('statScouts').textContent    = s.scouts_online + '/' + s.scouts_total;
                    if (mapEl('statIncidents')) mapEl('statIncidents').textContent = s.active_incidents;
                    if (mapEl('statCritical'))  mapEl('statCritical').textContent  = s.critical;
                    if (mapEl('statAI'))        mapEl('statAI').textContent        = s.ai_anomalies;
                }
            }
        };

        // ============================================================
        // TOAST HELPER (also used by ai:anomalies listener)
        // ============================================================
        function showToast(title, desc, type) {
            const c = document.getElementById('toastContainer');
            if (!c) return;
            const el = document.createElement('div');
            el.className = 'toast ' + (type || '');
            el.innerHTML = `
                <div class="t-icon">${type === 'critical' ? '🚨' : '🤖'}</div>
                <div class="t-body">
                    <div class="t-title"></div>
                    <div class="t-desc"></div>
                </div>
                <button class="t-close" onclick="this.parentElement.remove()">✕</button>
            `;
            el.querySelector('.t-title').textContent = title || '';
            el.querySelector('.t-desc').textContent  = desc  || '';
            c.appendChild(el);
            setTimeout(() => el.remove(), 8000);
        }

        // ============================================================
        // LISTEN FOR ANOMALY EVENTS FROM ai-tracker.js
        // ============================================================
        document.addEventListener('ai:anomalies', function (e) {
            const d = e.detail || {};
            if (d.new_critical) {
                showToast(
                    'Critical AI anomaly',
                    (d.stats && d.stats.total ? d.stats.total + ' active' : 'Check dashboard'),
                    'critical'
                );
            }
        });

        // ============================================================
        // CONTROLS
        // ============================================================
        function centerOnZone() {
            if (ZONE && ZONE.boundary_geojson) {
                const b = L.geoJSON(ZONE.boundary_geojson).getBounds();
                if (b.isValid()) {
                    map.fitBounds(b, { padding: [40, 40] });
                    return;
                }
            }
            map.setView(center, zoom);
        }

        function showRangers() {
            const valid = RANGERS.filter(r => r.current_lat && r.current_lng)
                .map(r => [parseFloat(r.current_lat), parseFloat(r.current_lng)]);
            if (!valid.length) return alert('No rangers with live locations.');
            map.fitBounds(L.latLngBounds(valid), { padding: [50, 50] });
        }

        function showScouts() {
            const valid = SCOUTS.filter(s => s.current_lat && s.current_lng)
                .map(s => [parseFloat(s.current_lat), parseFloat(s.current_lng)]);
            if (!valid.length) return alert('No scouts with live locations.');
            map.fitBounds(L.latLngBounds(valid), { padding: [50, 50] });
        }

        function showIncidents() {
            const valid = INCIDENTS.filter(i => i.location_lat && i.location_lng)
                .map(i => [parseFloat(i.location_lat), parseFloat(i.location_lng)]);
            if (!valid.length) return alert('No active incidents.');
            map.fitBounds(L.latLngBounds(valid), { padding: [50, 50] });
        }

        function resetView() {
            map.setView(center, zoom);
        }

        // ============================================================
        // WEBSOCKET (unchanged behaviour)
        // ============================================================
        try {
            const ws = io(WS_URL, { auth: { userId: USER_ID, role: 'zone_supervisor', zoneId: ZONE_ID } });

            ws.on('connect', () => console.log('✅ WS connected'));

            ws.on('ranger-location', data => {
                const r = RANGERS.find(x => x.id == data.ranger_id);
                if (r && data.location) {
                    r.current_lat = data.location.lat;
                    r.current_lng = data.location.lng;
                    renderRangers();
                }
            });

            ws.on('new-incident', inc => {
                if (inc.zone_id != ZONE_ID) return;
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('🚨 New Incident', {
                        body: `${inc.category} — ${inc.severity}`,
                    });
                }
            });

            ws.on('scout-location', data => {
                const s = SCOUTS.find(x => x.id == data.scout_id);
                if (s && data.location) {
                    s.current_lat = data.location.lat;
                    s.current_lng = data.location.lng;
                    renderScouts();
                }
            });

            ws.on('ai-anomaly', a => {
                if (a.zone_id && a.zone_id != ZONE_ID) return;
                // Prepend to list and re-render
                AI_ANOM.unshift(a);
                AI_ANOM = AI_ANOM.slice(0, 50);
                renderAIAnomalies(AI_ANOM);
                const aiEl = document.getElementById('statAI');
                if (aiEl) aiEl.textContent = AI_ANOM.length;

                if ((a.severity || '').toLowerCase() === 'critical') {
                    showToast(
                        'Critical AI anomaly',
                        (a.type || '').replace(/_/g, ' ') + (a.subject_name ? ' — ' + a.subject_name : ''),
                        'critical'
                    );
                }
            });
        } catch (e) {
            console.warn('WebSocket unavailable:', e);
        }

        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // ============================================================
        // RESPONSIVE
        // ============================================================
        window.addEventListener('resize', () => map.invalidateSize());
        document.addEventListener('sidebarToggled', () => setTimeout(() => map.invalidateSize(), 400));

        console.log('✅ Supervisor zone map loaded');
        console.log('📍 Rangers:', RANGERS.length);
        console.log('📍 Scouts:', SCOUTS.length);
        console.log('📍 Incidents:', INCIDENTS.length);
        console.log('📍 AI anomalies:', AI_ANOM.length);
    </script>
    <script src="assets/js/ai-tracker.js"></script>
    <script>
        // Kick off AI anomaly polling (uses the ?ajax=ai_scan endpoint)
        if (window.AITracker && typeof window.AITracker.startAITracking === 'function') {
            window.AITracker.startAITracking(<?= (int)$activeZoneId ?>);
        }
    </script>
</body>
</html>
<?php
// ============================================================
// admin/map.php
// Wildlife Sentinel — Admin Live Map
// ------------------------------------------------------------
// Base layers: OpenStreetMap + Esri Satellite (NO API KEY needed)
// Overlays:    Zones, Buffers, Incidents, Rangers, Scouts, AI
// ------------------------------------------------------------
// Newly added zones (from zones.php → Add New Zone) appear
// immediately on the map, even before a boundary polygon has
// been drawn. They fall back to a center marker + circular
// buffer so the admin can see them right away.
// ------------------------------------------------------------
// FEATURE: Draggable "Zone Status" panel — drag anywhere on the
// page, position persists via localStorage, collapse by clicking
// the header, reset with the ⌖ button.
// ------------------------------------------------------------
// DEBUG: append ?debug=1 to the URL to see column/probe info.
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$pdo  = getDB();

// Debug flag
$showDebug = (isset($_GET['debug']) && $_GET['debug'] === '1');

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
            error_log('[WS-MAP] safeFetchAll: ' . $e->getMessage());
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
        } catch (PDOException $e) {
            return 0;
        }
    }
}

// ============================================================
// HELPER: Circle GeoJSON fallback
// ============================================================
if (!function_exists('circleGeoJSON')) {
    function circleGeoJSON(float $lat, float $lng, int $radiusMeters, int $points = 48): array {
        $coords = [];
        $earthRadius = 6371000;
        $latRad = deg2rad($lat);
        $lngRad = deg2rad($lng);
        $angular = $radiusMeters / $earthRadius;

        for ($i = 0; $i < $points; $i++) {
            $bearing = (2 * M_PI * $i) / $points;
            $latPoint = asin(
                sin($latRad) * cos($angular) +
                cos($latRad) * sin($angular) * cos($bearing)
            );
            $lngPoint = $lngRad + atan2(
                sin($bearing) * sin($angular) * cos($latRad),
                cos($angular) - sin($latRad) * sin($latPoint)
            );
            $coords[] = [rad2deg($lngPoint), rad2deg($latPoint)];
        }
        $coords[] = $coords[0];

        return [
            'type' => 'Polygon',
            'coordinates' => [$coords],
        ];
    }
}

// ============================================================
// COLUMN DETECTION
// ============================================================
$zoneCols = [];
try {
    $colStmt = $pdo->query('SELECT column_name AS "Field", data_type AS "Type", is_nullable AS "Null", column_default AS "Default" FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=\'zones\' ORDER BY ordinal_position');
    while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $zoneCols[$c['Field']] = true;
    }
} catch (PDOException $e) { /* fall through */ }

$hasBoundaryCenter = isset($zoneCols['boundary_center_lat'], $zoneCols['boundary_center_lng']);
$hasCenterLat      = isset($zoneCols['center_lat']);
$hasCenterLng      = isset($zoneCols['center_lng']);
$hasBoundaryGeo    = isset($zoneCols['boundary_geojson']);
$hasBufferRadius   = isset($zoneCols['buffer_radius']);
$hasParkType       = isset($zoneCols['park_type']);
$hasParkCode       = isset($zoneCols['park_code']);
$hasIsRegistered   = isset($zoneCols['is_registered']);
$hasDescription    = isset($zoneCols['description']);

// ============================================================
// FETCH ZONES
// ============================================================
$selectParts = ['id', 'name', 'is_active', 'created_at'];
$selectParts[] = $hasParkType     ? 'park_type'        : "'other' AS park_type";
$selectParts[] = $hasParkCode     ? 'park_code'        : "NULL AS park_code";
$selectParts[] = $hasIsRegistered ? 'is_registered'    : "0 AS is_registered";
$selectParts[] = $hasCenterLat    ? 'center_lat'       : "NULL AS center_lat";
$selectParts[] = $hasCenterLng    ? 'center_lng'       : "NULL AS center_lng";
$selectParts[] = $hasBoundaryGeo  ? 'boundary_geojson' : "NULL AS boundary_geojson";
$selectParts[] = $hasBufferRadius ? 'buffer_radius'    : "500 AS buffer_radius";
$selectParts[] = $hasDescription  ? 'description'      : "NULL AS description";
if ($hasBoundaryCenter) {
    $selectParts[] = 'boundary_center_lat AS lat';
    $selectParts[] = 'boundary_center_lng AS lng';
} else {
    $selectParts[] = 'NULL AS lat';
    $selectParts[] = 'NULL AS lng';
}

$parks = safeFetchAll($pdo, "
    SELECT " . implode(', ', $selectParts) . "
    FROM zones
    WHERE is_active = 1
    ORDER BY " . ($hasParkType ? 'CASE park_type WHEN \'national_park\' THEN 1 WHEN \'gma\' THEN 2 WHEN \'other\' THEN 3 ELSE 0 END, ' : "") . "name
");

$parks = array_values(array_filter($parks, function ($z) {
    $hasBoundary = !empty($z['boundary_geojson']);
    $hasCenter   = (!empty($z['center_lat']) && !empty($z['center_lng']))
                || (!empty($z['lat'])        && !empty($z['lng']));
    return $hasBoundary || $hasCenter;
}));

foreach ($parks as &$p) {
    $hasBoundary = false;
    if (!empty($p['boundary_geojson'])) {
        $boundary = is_string($p['boundary_geojson'])
            ? json_decode($p['boundary_geojson'], true)
            : $p['boundary_geojson'];
        if (is_array($boundary) && isset($boundary['type'], $boundary['coordinates'])
            && in_array($boundary['type'], ['Polygon', 'MultiPolygon'], true)) {
            $p['boundary_geojson'] = $boundary;
            $p['buffer_geojson']   = computeBufferZone($boundary, (int)($p['buffer_radius'] ?? 500));
            $hasBoundary = true;
        } else {
            $p['boundary_geojson'] = null;
        }
    }
    if (!$hasBoundary) {
        $lat = $p['center_lat'] ?? $p['lat'] ?? null;
        $lng = $p['center_lng'] ?? $p['lng'] ?? null;
        if (is_string($lat) && $lat !== '') $lat = (float)$lat;
        if (is_string($lng) && $lng !== '') $lng = (float)$lng;
        $validLat = is_numeric($lat) && $lat >= -90  && $lat <= 90;
        $validLng = is_numeric($lng) && $lng >= -180 && $lng <= 180;
        if ($validLat && $validLng) {
            $fallbackRadius = 2000;
            $p['boundary_geojson']  = circleGeoJSON((float)$lat, (float)$lng, $fallbackRadius, 64);
            $p['buffer_geojson']    = circleGeoJSON((float)$lat, (float)$lng,
                                        $fallbackRadius + (int)($p['buffer_radius'] ?? 500), 64);
            $p['is_fallback_shape'] = true;
            $p['lat'] = (float)$lat;
            $p['lng'] = (float)$lng;
        } else {
            $p['_skip_map'] = true;
        }
    }
}
unset($p);
$parks = array_values(array_filter($parks, fn($p) => empty($p['_skip_map'])));

$zoneDebug = [
    'table_columns'       => array_keys($zoneCols),
    'has_boundary_center' => $hasBoundaryCenter,
    'has_center_lat'      => $hasCenterLat,
    'has_center_lng'      => $hasCenterLng,
    'has_boundary_geo'    => $hasBoundaryGeo,
    'has_buffer_radius'   => $hasBufferRadius,
    'total_zones'         => safeCount($pdo, "SELECT COUNT(*) as count FROM zones"),
    'loaded_for_map'      => count($parks),
];

// ============================================================
// INCIDENTS / RANGERS / SCOUTS / AI
// ============================================================
$incidents = safeFetchAll($pdo, '
    SELECT i.id, i.category, i.severity, i.status, i.description,
           i.location_lat, i.location_lng, i.reported_at,
           u.full_name AS reporter_name,
           z.name AS zone_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    LEFT JOIN zones z ON i.zone_id = z.id
    WHERE i.status NOT IN (\'closed\', \'resolved\')
    ORDER BY CASE i.severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, i.reported_at DESC
    LIMIT 200
');

$rangers = safeFetchAll($pdo, "
    SELECT u.id, u.full_name, u.email, u.phone, u.badge_number, u.is_on_duty,
           rlt.current_lat, rlt.current_lng, rlt.heading, rlt.speed,
           rlt.last_update,
           ra.is_available, ra.current_incident_id,
           z.name AS zone_name
    FROM users u
    LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
    LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
    LEFT JOIN zones z ON u.zone_id = z.id
    WHERE u.role = 'ranger' AND u.is_active = 1
    ORDER BY u.full_name
");

$scouts = safeFetchAll($pdo, "
    SELECT u.id, u.full_name, u.phone, u.is_online, u.last_seen,
           slt.current_lat, slt.current_lng, slt.last_update,
           z.name AS zone_name
    FROM users u
    LEFT JOIN scout_live_tracking slt ON u.id = slt.scout_id
    LEFT JOIN zones z ON u.zone_id = z.id
    WHERE u.role = 'scout' AND u.is_active = 1
    ORDER BY u.full_name
");

$aiAnomalies = safeFetchAll($pdo, '
    SELECT a.id, a.type, a.severity, a.description, a.confidence,
           a.location_lat, a.location_lng, a.radius_meters,
           a.detected_at,
           u.full_name AS subject_name, u.role AS subject_role
    FROM ai_anomalies a
    LEFT JOIN users u ON (a.ranger_id = u.id OR a.scout_id = u.id)
    WHERE a.detected_at >= (NOW() - (24) * INTERVAL \'1 hour\')
    ORDER BY a.detected_at DESC
    LIMIT 50
');

// ============================================================
// STATS
// ============================================================
$totalParks      = count($parks);
$registeredCount = count(array_filter($parks, fn($p) => (int)$p['is_registered'] === 1));
$availableCount  = $totalParks - $registeredCount;
$activeIncidents = count($incidents);
$activeRangers   = count(array_filter($rangers, fn($r) => (int)$r['is_available'] === 1));
$activeScouts    = count(array_filter($scouts, fn($s) => (int)$s['is_online'] === 1));
$criticalCount   = count(array_filter($incidents, fn($i) => $i['severity'] === 'critical'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Map - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        #map {
            height: 650px;
            border-radius: 12px;
            margin-top: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            z-index: 1;
            background: #e0e0e0;
        }

        .dashboard-greeting { margin-bottom: 20px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p { color: #6c757d; font-size: 15px; }

        /* Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .stat-item {
            background: white;
            padding: 12px 16px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #f0f0f0;
        }
        .stat-item .number {
            font-size: 22px;
            font-weight: 700;
            color: #0d3b22;
            display: block;
        }
        .stat-item .label {
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
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

        /* Map Legend */
        .map-legend {
            background: white;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-top: 12px;
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            white-space: nowrap;
        }
        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            display: inline-block;
            border: 2px solid rgba(0,0,0,0.1);
        }
        .legend-dot.registered { background: #28a745; border-color: #1e7e34; }
        .legend-dot.available  { background: #dc3545; border-color: #c62828; }
        .legend-dot.buffer     { background: rgba(255,111,0,0.4); border: 1px dashed #FF6F00; }
        .legend-dot.incident   { background: #dc3545; }
        .legend-dot.ranger     { background: #28a745; border: 2px solid #0056b3; }
        .legend-dot.scout      { background: #0277BD; }
        .legend-dot.ai         { background: #9C27B0; }

        /* Info Window */
        .info-window { min-width: 200px; max-width: 280px; font-family: sans-serif; }
        .info-window .title { font-weight: 700; font-size: 15px; color: #0d3b22; }
        .info-window .subtitle { font-size: 12px; color: #6c757d; }
        .info-window .status {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-top: 4px;
        }
        .info-window .status.registered { background: #d4edda; color: #155724; }
        .info-window .status.available  { background: #f8d7da; color: #721c24; }

        /* ============================================================
           DRAGGABLE ZONE STATUS PANEL
           ============================================================ */
        #zoneStatusPanel {
            position: fixed;
            top: 100px;
            right: 24px;
            width: 220px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.18);
            border: 1px solid #f0f0f0;
            z-index: 1500;
            font-size: 12px;
            user-select: none;
            transition: box-shadow 0.2s, opacity 0.2s;
            opacity: 0.98;
        }
        #zoneStatusPanel.dragging {
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
            opacity: 1;
            transition: none;
        }
        #zoneStatusPanel.collapsed .zsp-body {
            display: none;
        }

        .zsp-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: linear-gradient(135deg, #0d3b22, #1a5c3a);
            color: white;
            border-radius: 11px 11px 0 0;
            cursor: grab;
            gap: 6px;
        }
        .zsp-header:active { cursor: grabbing; }
        .zsp-header .zsp-title {
            font-weight: 700;
            font-size: 12.5px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .zsp-header .zsp-grip {
            font-size: 14px;
            opacity: 0.55;
            letter-spacing: -2px;
            line-height: 1;
        }
        .zsp-header .zsp-btn {
            background: rgba(255,255,255,0.15);
            border: none;
            color: white;
            width: 22px;
            height: 22px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            line-height: 1;
            transition: background 0.2s;
            flex-shrink: 0;
        }
        .zsp-header .zsp-btn:hover { background: rgba(255,255,255,0.3); }

        .zsp-body {
            padding: 10px 14px 12px;
        }
        .zsp-body .zsp-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 12px;
            color: #495057;
            border-bottom: 1px dashed #f0f0f0;
        }
        .zsp-body .zsp-row:last-child { border-bottom: none; }
        .zsp-body .zsp-row strong { color: #0d3b22; }

        /* Loading Overlay */
        .map-loading {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            border-radius: 12px;
            font-size: 15px;
            color: #6c757d;
        }
        .map-loading .spinner {
            width: 40px; height: 40px;
            border: 4px solid #f0f0f0;
            border-top-color: #1a5c3a;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 12px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Debug info */
        .debug-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 14px;
            font-size: 12px;
            color: #856404;
            font-family: monospace;
            line-height: 1.6;
        }
        .debug-info ul { margin: 6px 0 0 18px; padding: 0; }
        .debug-info li { margin-bottom: 3px; }

        /* Mobile */
        @media (max-width: 768px) {
            #map { height: 450px; }
            .stats-bar { grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); gap: 8px; }
            .stat-item { padding: 8px 12px; }
            .stat-item .number { font-size: 18px; }
            .stat-item .label { font-size: 9px; }
            .map-legend { gap: 12px; padding: 10px 14px; flex-wrap: nowrap; overflow-x: auto; }
            .legend-item { font-size: 11px; flex-shrink: 0; }
            .map-controls .btn { padding: 6px 12px; font-size: 12px; min-height: 36px; }
            .map-controls .btn .btn-text { display: none; }

            #zoneStatusPanel { width: 190px; top: 80px; right: 12px; font-size: 11.5px; }
            .zsp-header { padding: 8px 10px; }
            .zsp-header .zsp-title { font-size: 11.5px; }
        }
        @media (max-width: 480px) {
            #map { height: 350px; }
            .stats-bar { grid-template-columns: 1fr 1fr; gap: 6px; }
            .stat-item { padding: 6px 10px; }
            .stat-item .number { font-size: 16px; }
            .stat-item .label { font-size: 8px; }

            #zoneStatusPanel { width: 170px; top: 70px; right: 8px; }
        }

        /* Leaflet zoom control */
        .leaflet-control-zoom a {
            width: 36px !important; height: 36px !important;
            line-height: 36px !important; font-size: 18px !important;
        }

        /* Layer switcher */
        .leaflet-control-layers {
            background: white !important;
            border-radius: 8px !important;
            padding: 8px 12px !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
            font-size: 12px;
        }
        .leaflet-control-layers label { margin-bottom: 4px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Admin Map</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🗺️ Live Operational Map</h1>
                    <p>Real-time view of all zones, incidents, rangers, scouts, and AI anomalies. Newly added zones appear immediately.</p>
                </div>

                <!-- Stats Bar -->
                <div class="stats-bar">
                    <div class="stat-item">
                        <span class="number green"><?= $registeredCount ?></span>
                        <span class="label">✅ Registered</span>
                    </div>
                    <div class="stat-item">
                        <span class="number red"><?= $availableCount ?></span>
                        <span class="label">🔴 Available</span>
                    </div>
                    <div class="stat-item">
                        <span class="number blue"><?= $totalParks ?></span>
                        <span class="label">🏞️ Total Parks</span>
                    </div>
                    <div class="stat-item">
                        <span class="number orange"><?= $activeIncidents ?></span>
                        <span class="label">🚨 Incidents</span>
                    </div>
                    <div class="stat-item">
                        <span class="number purple"><?= $activeRangers ?></span>
                        <span class="label">🛡️ Rangers</span>
                    </div>
                    <div class="stat-item">
                        <span class="number blue"><?= $activeScouts ?></span>
                        <span class="label">👥 Scouts</span>
                    </div>
                    <div class="stat-item">
                        <span class="number red"><?= $criticalCount ?></span>
                        <span class="label">⚠️ Critical</span>
                    </div>
                </div>

                <!-- Map Controls -->
                <div class="map-controls">
                    <button class="btn btn-primary" onclick="zoomToZambia()">
                        <span class="icon">🇿🇲</span>
                        <span class="btn-text">Zambia</span>
                    </button>
                    <button class="btn btn-success" onclick="showRegistered()">
                        <span class="icon">✅</span>
                        <span class="btn-text">Registered</span>
                    </button>
                    <button class="btn btn-danger" onclick="showAvailable()">
                        <span class="icon">🔴</span>
                        <span class="btn-text">Available</span>
                    </button>
                    <button class="btn btn-warning" onclick="showIncidents()">
                        <span class="icon">🚨</span>
                        <span class="btn-text">Incidents</span>
                    </button>
                    <button class="btn btn-info" onclick="showRangers()">
                        <span class="icon">🛡️</span>
                        <span class="btn-text">Rangers</span>
                    </button>
                    <button class="btn btn-secondary" onclick="resetMap()">
                        <span class="icon">🗺️</span>
                        <span class="btn-text">Reset</span>
                    </button>
                </div>

                <!-- Legend -->
                <div class="map-legend">
                    <span class="legend-item"><span class="legend-dot registered"></span> Registered Park</span>
                    <span class="legend-item"><span class="legend-dot available"></span> Available Park</span>
                    <span class="legend-item"><span class="legend-dot buffer"></span> 500m Buffer</span>
                    <span class="legend-item"><span class="legend-dot incident"></span> Incident</span>
                    <span class="legend-item"><span class="legend-dot ranger"></span> Ranger</span>
                    <span class="legend-item"><span class="legend-dot scout"></span> Scout</span>
                    <span class="legend-item"><span class="legend-dot ai"></span> AI Anomaly</span>
                </div>

                <!-- Map Container -->
                <div id="map" style="position:relative;">
                    <div class="map-loading" id="mapLoading">
                        <div class="spinner"></div>
                        <span>Loading map…</span>
                    </div>
                </div>

                <!-- DEBUG (opt-in via ?debug=1) -->
                <?php if ($showDebug): ?>
                <div class="debug-info">
                    <strong>🐛 Debug Info — Zones</strong><br>
                    Total zones in DB: <b><?= (int)$zoneDebug['total_zones'] ?></b> |
                    Loaded for map: <b style="color:#0a5c00;"><?= (int)$zoneDebug['loaded_for_map'] ?></b><br>
                    Columns detected:
                    boundary_center = <?= $zoneDebug['has_boundary_center'] ? '✅' : '❌' ?> |
                    center_lat = <?= $zoneDebug['has_center_lat'] ? '✅' : '❌' ?> |
                    center_lng = <?= $zoneDebug['has_center_lng'] ? '✅' : '❌' ?> |
                    boundary_geojson = <?= $zoneDebug['has_boundary_geo'] ? '✅' : '❌' ?> |
                    buffer_radius = <?= $zoneDebug['has_buffer_radius'] ? '✅' : '❌' ?><br>
                    <span style="color:#6c757d;">Zones without a boundary and without center coords are skipped.</span>
                    <br><br>
                    <strong>Loaded zones:</strong>
                    <?php if (count($parks) > 0): ?>
                        <ul>
                            <?php foreach ($parks as $p): ?>
                                <li>
                                    #<?= (int)$p['id'] ?> <?= htmlspecialchars($p['name']) ?>
                                    — <?= htmlspecialchars($p['park_type'] ?? 'other') ?>
                                    — <?= ((int)($p['is_registered'] ?? 0) === 1) ? 'Registered' : 'Available' ?>
                                    <?= !empty($p['is_fallback_shape']) ? ' — <span style="color:#0a5c00;">fallback circle</span>' : '' ?>
                                    — lat=<?= htmlspecialchars((string)($p['lat'] ?? $p['center_lat'] ?? '—')) ?>,
                                      lng=<?= htmlspecialchars((string)($p['lng'] ?? $p['center_lng'] ?? '—')) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span style="color:#dc3545;">No zones were loadable.</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- ============================================================
         DRAGGABLE ZONE STATUS PANEL
         ============================================================ -->
    <div id="zoneStatusPanel" aria-label="Zone status panel">
        <div class="zsp-header" id="zspHeader" title="Drag to move">
            <span class="zsp-grip">⋮⋮</span>
            <span class="zsp-title">📊 Zone Status</span>
            <button type="button" class="zsp-btn" id="zspReset" title="Reset position">⌖</button>
            <button type="button" class="zsp-btn" id="zspToggle" title="Collapse / expand">–</button>
        </div>
        <div class="zsp-body" id="zspBody">
            <div class="zsp-row"><span>✅ Registered</span><strong><?= $registeredCount ?></strong></div>
            <div class="zsp-row"><span>🔴 Available</span><strong><?= $availableCount ?></strong></div>
            <div class="zsp-row"><span>🚨 Incidents</span><strong><?= $activeIncidents ?></strong></div>
            <div class="zsp-row"><span>🛡️ Rangers</span><strong><?= count($rangers) ?></strong></div>
            <div class="zsp-row"><span>👥 Scouts</span><strong><?= count($scouts) ?></strong></div>
            <div class="zsp-row"><span>🤖 AI Anomalies</span><strong><?= count($aiAnomalies) ?></strong></div>
        </div>
    </div>

    <!-- ============================================================
         SCRIPTS
         ============================================================ -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>

    <script>
        // ============================================================
        // INIT MAP
        // ============================================================
        const map = L.map('map', {
            center: [-14.5, 27.0],
            zoom: 6,
            zoomControl: false,
        });

        // ============================================================
        // BASE LAYERS
        // ============================================================
        const osmStandard = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        });
        const osmHumanitarian = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap Humanitarian',
        });
        const esriSatellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: '&copy; Esri, Maxar, Earthstar Geographics' }
        );
        const esriTerrain = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Terrain_Base/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 13, attribution: '&copy; Esri' }
        );
        const cartoDark = L.tileLayer(
            'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
            { maxZoom: 19, attribution: '&copy; CartoDB' }
        );

        osmStandard.addTo(map);

        L.control.layers(
            {
                '🗺️ Street Map':  osmStandard,
                '🛰️ Satellite':   esriSatellite,
                '🌍 Humanitarian': osmHumanitarian,
                '⛰️ Terrain':      esriTerrain,
                '🌙 Dark Mode':    cartoDark,
            },
            null,
            { position: 'topright', collapsed: false }
        ).addTo(map);

        L.control.zoom({ position: 'topright' }).addTo(map);
        L.control.scale({ position: 'bottomleft', metric: true, imperial: false }).addTo(map);

        document.getElementById('mapLoading').style.display = 'none';

        // ============================================================
        // LAYER GROUPS
        // ============================================================
        const parkLayers = { registered: [], available: [], buffers: [] };
        const incidentLayers = [];
        const rangerLayers = [];
        const scoutLayers = [];
        const anomalyLayers = [];

        // ============================================================
        // PARKS + BUFFERS
        // ============================================================
        const parksData = <?= json_encode($parks, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        parksData.forEach(park => {
            const isRegistered = parseInt(park.is_registered) === 1;
            const isFallback   = !!park.is_fallback_shape;
            const color = isRegistered ? '#28a745' : '#dc3545';
            const dashArray = isRegistered ? null : '5,5';

            const statusBadge = isRegistered
                ? `<span class="status registered">✅ Registered</span>`
                : `<span class="status available">🔴 Available</span>`;

            const newZoneNote = isFallback
                ? `<div style="margin-top:6px;font-size:11px;color:#004085;background:#cce5ff;padding:6px 8px;border-radius:6px;">
                     🆕 Newly added — no boundary drawn yet.<br>Approximate area shown as a circle.
                   </div>`
                : '';

            const popupHtml = `
                <div class="info-window">
                    <div class="title">${isRegistered ? '✅' : '🔴'} ${park.name}</div>
                    <div class="subtitle">
                        ${(park.park_type || 'other').toUpperCase().replace('_', ' ')}
                        ${park.park_code ? '• ' + park.park_code : ''}
                    </div>
                    ${statusBadge}
                    ${newZoneNote}
                    ${park.description ? `<div style="margin-top:6px;font-size:11px;color:#6c757d;">${park.description.substring(0, 100)}</div>` : ''}
                </div>
            `;

            if (park.boundary_geojson && (park.boundary_geojson.type === 'Polygon' || park.boundary_geojson.type === 'MultiPolygon')) {
                try {
                    const layer = L.geoJSON(park.boundary_geojson, {
                        style: {
                            color: color,
                            weight: isFallback ? 3 : 2.5,
                            opacity: 1,
                            fillColor: color,
                            fillOpacity: isFallback ? 0.30 : (isRegistered ? 0.15 : 0.08),
                            dashArray: isFallback ? '6,4' : dashArray,
                        }
                    }).addTo(map);
                    layer.bindPopup(popupHtml);
                    parkLayers[isRegistered ? 'registered' : 'available'].push(layer);
                } catch (e) { console.warn('Boundary error for', park.name, e); }
            }

            if (park.buffer_geojson && (park.buffer_geojson.type === 'Polygon' || park.buffer_geojson.type === 'MultiPolygon')) {
                try {
                    const bufLayer = L.geoJSON(park.buffer_geojson, {
                        style: {
                            color: '#FF6F00',
                            weight: 2,
                            opacity: 0.8,
                            fillColor: '#FF6F00',
                            fillOpacity: isFallback ? 0.12 : 0.08,
                            dashArray: '6,6',
                        }
                    }).addTo(map);
                    bufLayer.bindPopup(`
                        <strong>${park.name}</strong><br>
                        <span style="color:#FF6F00;">🟠 ${park.buffer_radius || 500}m Buffer Zone</span>
                    `);
                    parkLayers.buffers.push(bufLayer);
                } catch (e) { console.warn('Buffer error for', park.name, e); }
            }

            const lat = park.lat || park.center_lat;
            const lng = park.lng || park.center_lng;
            if (lat && lng) {
                const markerColor = isRegistered ? '#28a745' : '#dc3545';
                const marker = L.marker([parseFloat(lat), parseFloat(lng)], {
                    icon: L.divIcon({
                        className: 'park-marker',
                        html: `<div style="position:relative;">
                            <div style="background:${markerColor};width:18px;height:18px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>
                            ${isFallback ? '<div style="position:absolute;top:-16px;left:50%;transform:translateX(-50%);font-size:14px;">🆕</div>' : ''}
                        </div>`,
                        iconSize: [18, 18],
                        iconAnchor: [9, 9],
                    })
                }).addTo(map);
                marker.bindPopup(popupHtml);
                parkLayers[isRegistered ? 'registered' : 'available'].push(marker);
            }
        });

        // ============================================================
        // INCIDENTS
        // ============================================================
        const incidentsData = <?= json_encode($incidents, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const severityColors = { critical:'#dc3545', high:'#fd7e14', medium:'#ffc107', low:'#28a745' };
        const severityRadius = { critical:14, high:11, medium:8, low:6 };

        incidentsData.forEach(inc => {
            if (!inc.location_lat || !inc.location_lng) return;
            const sev = inc.severity || 'medium';
            const color = severityColors[sev] || '#6c757d';
            const radius = severityRadius[sev] || 6;

            const marker = L.circleMarker(
                [parseFloat(inc.location_lat), parseFloat(inc.location_lng)],
                { radius: radius, fillColor: color, color: '#fff', weight: 2, opacity: 1, fillOpacity: 0.9 }
            ).addTo(map);

            marker.bindPopup(`
                <div class="info-window">
                    <strong>🚨 Incident #${inc.id}</strong><br>
                    <span style="color:#6c757d;font-size:11px;">
                        Type: ${(inc.category || '').replace(/_/g, ' ')}<br>
                        Severity: <strong style="color:${color}">${sev.toUpperCase()}</strong><br>
                        Status: ${(inc.status || '').replace(/_/g, ' ')}<br>
                        Reporter: ${inc.reporter_name || 'N/A'}<br>
                        Zone: ${inc.zone_name || 'N/A'}<br>
                        Time: ${inc.reported_at ? new Date(inc.reported_at).toLocaleString() : 'N/A'}
                    </span>
                </div>
            `);
            incidentLayers.push(marker);

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
        // RANGERS
        // ============================================================
        const rangersData = <?= json_encode($rangers, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        rangersData.forEach(r => {
            if (!r.current_lat || !r.current_lng) return;
            const isOnDuty = parseInt(r.is_on_duty) === 1;
            const color = isOnDuty ? '#28a745' : '#9E9E9E';

            const marker = L.marker(
                [parseFloat(r.current_lat), parseFloat(r.current_lng)],
                {
                    icon: L.divIcon({
                        className: 'ranger-marker',
                        html: `<div style="position:relative;">
                            <div style="background:${color};width:26px;height:26px;border-radius:50%;border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:bold;">R</div>
                            ${isOnDuty ? '<div style="position:absolute;top:-2px;right:-2px;width:10px;height:10px;background:#4CAF50;border-radius:50%;border:2px solid white;"></div>' : ''}
                        </div>`,
                        iconSize: [26, 26],
                        iconAnchor: [13, 13],
                    })
                }
            ).addTo(map);

            marker.bindPopup(`
                <div class="info-window">
                    <strong>🛡️ ${r.full_name}</strong><br>
                    <span style="color:#6c757d;font-size:11px;">
                        Badge: ${r.badge_number || 'N/A'}<br>
                        Status: ${isOnDuty ? '🟢 On Duty' : '⚪ Off Duty'}<br>
                        Zone: ${r.zone_name || 'Unassigned'}<br>
                        ${r.current_incident_id ? 'Incident: #' + r.current_incident_id + '<br>' : ''}
                        Last update: ${r.last_update ? new Date(r.last_update).toLocaleTimeString() : 'N/A'}
                    </span>
                </div>
            `);
            rangerLayers.push(marker);
        });

        // ============================================================
        // SCOUTS
        // ============================================================
        const scoutsData = <?= json_encode($scouts, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        scoutsData.forEach(s => {
            if (!s.current_lat || !s.current_lng) return;
            const isOnline = parseInt(s.is_online) === 1;
            const color = isOnline ? '#0277BD' : '#9E9E9E';

            const marker = L.marker(
                [parseFloat(s.current_lat), parseFloat(s.current_lng)],
                {
                    icon: L.divIcon({
                        className: 'scout-marker',
                        html: `<div style="position:relative;">
                            <div style="background:${color};width:22px;height:22px;border-radius:50%;border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;color:white;font-size:11px;font-weight:bold;">S</div>
                            ${isOnline ? '<div style="position:absolute;top:-2px;right:-2px;width:10px;height:10px;background:#4CAF50;border-radius:50%;border:2px solid white;"></div>' : ''}
                        </div>`,
                        iconSize: [22, 22],
                        iconAnchor: [11, 11],
                    })
                }
            ).addTo(map);

            marker.bindPopup(`
                <div class="info-window">
                    <strong>👤 ${s.full_name}</strong><br>
                    <span style="color:#6c757d;font-size:11px;">
                        Status: ${isOnline ? '🟢 Online' : '⚪ Offline'}<br>
                        Zone: ${s.zone_name || 'Unassigned'}<br>
                        📞 ${s.phone || 'N/A'}<br>
                        Last seen: ${s.last_seen ? new Date(s.last_seen).toLocaleTimeString() : 'N/A'}
                    </span>
                </div>
            `);
            scoutLayers.push(marker);
        });

        // ============================================================
        // AI ANOMALIES
        // ============================================================
        const anomaliesData = <?= json_encode($aiAnomalies, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        anomaliesData.forEach(a => {
            if (!a.location_lat || !a.location_lng) return;
            const circle = L.circle(
                [parseFloat(a.location_lat), parseFloat(a.location_lng)],
                {
                    radius: a.radius_meters || 200,
                    color: '#9C27B0',
                    fillColor: '#9C27B0',
                    fillOpacity: 0.15,
                    weight: 2,
                    dashArray: '4,4',
                }
            ).addTo(map);

            circle.bindPopup(`
                <div class="info-window">
                    <strong>🤖 AI Anomaly</strong><br>
                    <span style="color:#6c757d;font-size:11px;">
                        Type: ${(a.type || '').replace(/_/g, ' ')}<br>
                        Severity: ${a.severity || 'N/A'}<br>
                        Confidence: ${Math.round((a.confidence || 0) * 100)}%<br>
                        Subject: ${a.subject_name || 'Unknown'}<br>
                        ${a.description || ''}
                    </span>
                </div>
            `);
            anomalyLayers.push(circle);
        });

        // ============================================================
        // MAP CONTROLS
        // ============================================================
        const zambiaBounds = [[-18.0, 22.0], [-8.0, 34.0]];

        function zoomToZambia() { map.fitBounds(zambiaBounds); }

        function showRegistered() {
            parkLayers.available.forEach(l => map.removeLayer(l));
            parkLayers.buffers.forEach(l => map.removeLayer(l));
            parkLayers.registered.forEach(l => { if (!map.hasLayer(l)) l.addTo(map); });
            const bounds = L.featureGroup(parkLayers.registered).getBounds();
            if (bounds.isValid()) map.fitBounds(bounds, { padding: [50, 50] });
        }

        function showAvailable() {
            parkLayers.registered.forEach(l => map.removeLayer(l));
            parkLayers.buffers.forEach(l => map.removeLayer(l));
            parkLayers.available.forEach(l => { if (!map.hasLayer(l)) l.addTo(map); });
            const bounds = L.featureGroup(parkLayers.available).getBounds();
            if (bounds.isValid()) map.fitBounds(bounds, { padding: [50, 50] });
        }

        function showIncidents() {
            if (incidentLayers.length === 0) { alert('No active incidents to display.'); return; }
            const bounds = L.featureGroup(incidentLayers).getBounds();
            if (bounds.isValid()) map.fitBounds(bounds, { padding: [50, 50] });
        }

        function showRangers() {
            if (rangerLayers.length === 0) { alert('No rangers with live locations.'); return; }
            const bounds = L.featureGroup(rangerLayers).getBounds();
            if (bounds.isValid()) map.fitBounds(bounds, { padding: [50, 50] });
        }

        function resetMap() {
            [parkLayers.registered, parkLayers.available, parkLayers.buffers,
             incidentLayers, rangerLayers, scoutLayers, anomalyLayers].forEach(arr => {
                arr.forEach(l => { if (!map.hasLayer(l)) l.addTo(map); });
            });
            map.setView([-14.5, 27.0], 6);
        }

        // ============================================================
        // DRAGGABLE ZONE STATUS PANEL
        // ============================================================
        (function () {
            const panel  = document.getElementById('zoneStatusPanel');
            const header = document.getElementById('zspHeader');
            const toggle = document.getElementById('zspToggle');
            const reset  = document.getElementById('zspReset');
            if (!panel || !header) return;

            const STORAGE_KEY   = 'ws_map_zone_panel_pos_v1';
            const COLLAPSE_KEY  = 'ws_map_zone_panel_collapsed_v1';
            const DEFAULT_TOP   = 100;
            const DEFAULT_RIGHT = 24;

            let dragging = false;
            let startX = 0, startY = 0;
            let startLeft = 0, startTop = 0;
            let moved = false;   // distinguish click vs drag for the toggle

            // ---- helpers ----
            function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

            function applyPosition(top, left) {
                // We track top + left for consistency across viewports
                // (right: auto so left wins)
                panel.style.top    = top + 'px';
                panel.style.left   = left + 'px';
                panel.style.right  = 'auto';
            }

            function getDefaultLeft() {
                // Right-aligned default
                return Math.max(8, window.innerWidth - panel.offsetWidth - DEFAULT_RIGHT);
            }

            function savePosition() {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify({
                        top:  parseFloat(panel.style.top)  || DEFAULT_TOP,
                        left: parseFloat(panel.style.left) || getDefaultLeft(),
                    }));
                } catch (e) {}
            }

            function loadPosition() {
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    if (!raw) return null;
                    const p = JSON.parse(raw);
                    if (typeof p.top === 'number' && typeof p.left === 'number') {
                        // Clamp into current viewport
                        const maxLeft = Math.max(0, window.innerWidth  - panel.offsetWidth);
                        const maxTop  = Math.max(0, window.innerHeight - panel.offsetHeight);
                        return {
                            top:  clamp(p.top,  0, maxTop),
                            left: clamp(p.left, 0, maxLeft),
                        };
                    }
                } catch (e) {}
                return null;
            }

            // ---- initial placement ----
            requestAnimationFrame(() => {
                const saved = loadPosition();
                if (saved) {
                    applyPosition(saved.top, saved.left);
                } else {
                    applyPosition(DEFAULT_TOP, getDefaultLeft());
                }

                // Restore collapsed state
                try {
                    if (localStorage.getItem(COLLAPSE_KEY) === '1') {
                        panel.classList.add('collapsed');
                        if (toggle) toggle.textContent = '+';
                    }
                } catch (e) {}
            });

            // ---- drag handlers ----
            function dragStart(clientX, clientY, e) {
                // Ignore clicks on the buttons
                if (e && e.target && e.target.closest && e.target.closest('.zsp-btn')) return;

                dragging = true;
                moved = false;
                startX = clientX;
                startY = clientY;

                const rect = panel.getBoundingClientRect();
                startLeft = rect.left;
                startTop  = rect.top;

                panel.classList.add('dragging');
                document.body.style.userSelect = 'none';
            }

            function dragMove(clientX, clientY) {
                if (!dragging) return;
                const dx = clientX - startX;
                const dy = clientY - startY;

                if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;

                const maxLeft = Math.max(0, window.innerWidth  - panel.offsetWidth);
                const maxTop  = Math.max(0, window.innerHeight - panel.offsetHeight);

                const newLeft = clamp(startLeft + dx, 0, maxLeft);
                const newTop  = clamp(startTop  + dy, 0, maxTop);

                applyPosition(newTop, newLeft);
            }

            function dragEnd() {
                if (!dragging) return;
                dragging = false;
                panel.classList.remove('dragging');
                document.body.style.userSelect = '';
                savePosition();
            }

            // Mouse
            header.addEventListener('mousedown', e => {
                e.preventDefault();
                dragStart(e.clientX, e.clientY, e);
            });
            document.addEventListener('mousemove', e => dragMove(e.clientX, e.clientY));
            document.addEventListener('mouseup', () => dragEnd());

            // Touch
            header.addEventListener('touchstart', e => {
                const t = e.touches[0];
                if (!t) return;
                dragStart(t.clientX, t.clientY, e);
            }, { passive: true });
            document.addEventListener('touchmove', e => {
                const t = e.touches[0];
                if (!t) return;
                if (dragging) {
                    // Prevent scroll while dragging
                    e.preventDefault();
                    dragMove(t.clientX, t.clientY);
                }
            }, { passive: false });
            document.addEventListener('touchend', () => dragEnd());

            // ---- collapse toggle ----
            if (toggle) {
                toggle.addEventListener('click', e => {
                    e.stopPropagation();
                    panel.classList.toggle('collapsed');
                    const isCollapsed = panel.classList.contains('collapsed');
                    toggle.textContent = isCollapsed ? '+' : '–';
                    try { localStorage.setItem(COLLAPSE_KEY, isCollapsed ? '1' : '0'); } catch (err) {}
                });
            }

            // ---- reset position ----
            if (reset) {
                reset.addEventListener('click', e => {
                    e.stopPropagation();
                    panel.classList.remove('collapsed');
                    if (toggle) toggle.textContent = '–';
                    applyPosition(DEFAULT_TOP, getDefaultLeft());
                    try {
                        localStorage.removeItem(STORAGE_KEY);
                        localStorage.removeItem(COLLAPSE_KEY);
                    } catch (err) {}
                });
            }

            // ---- keep inside viewport on resize ----
            let resizeTimer = null;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => {
                    const rect = panel.getBoundingClientRect();
                    const maxLeft = Math.max(0, window.innerWidth  - panel.offsetWidth);
                    const maxTop  = Math.max(0, window.innerHeight - panel.offsetHeight);
                    applyPosition(
                        clamp(rect.top,  0, maxTop),
                        clamp(rect.left, 0, maxLeft)
                    );
                }, 120);
            });
        })();

        // ============================================================
        // RESPONSIVE
        // ============================================================
        window.addEventListener('resize', () => map.invalidateSize());
        document.addEventListener('sidebarToggled', () => {
            setTimeout(() => map.invalidateSize(), 400);
        });

        console.log('✅ Admin map loaded');
        console.log('📍 Parks:', <?= $totalParks ?>);
        console.log('📍 Incidents:', <?= $activeIncidents ?>);
        console.log('📍 Rangers:', <?= count($rangers) ?>);
        console.log('📍 Scouts:', <?= count($scouts) ?>);
        console.log('📍 AI Anomalies:', <?= count($aiAnomalies) ?>);
    </script>
</body>
</html>
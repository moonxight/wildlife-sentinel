<?php
// ============================================================
// tourism/safety-map.php
// Tourism / Lodge Operator — Safety Map (read-only)
// ------------------------------------------------------------
// Shows:
//   - Zone boundary (colored by safety level)
//   - 500m buffer around the zone
//   - General risk indicator (low / medium / high / critical)
//   - Count of active incidents (aggregate only)
//   - Recommended safe areas (lodges/hotspots if registered)
//
// Does NOT show:
//   - Ranger locations
//   - Ranger patrol routes
//   - Reporter names/details
//   - Sensitive poaching intelligence
//   - AI detection specifics
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

$pdo          = getDB();
$activeZoneId = (int)($user['zone_id'] ?? 0);

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
// LOAD ZONE + BUFFER
// ============================================================
$zone = getZone($activeZoneId);
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
// AGGREGATE INCIDENT COUNTS (only counts — no details)
// ============================================================
$zoneActive    = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND status NOT IN ('resolved','closed')", [$activeZoneId]);
$zoneCritical  = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND severity = 'critical' AND status NOT IN ('resolved','closed')", [$activeZoneId]);
$zoneHigh      = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND severity = 'high' AND status NOT IN ('resolved','closed')", [$activeZoneId]);
$zoneMedium    = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND severity = 'medium' AND status NOT IN ('resolved','closed')", [$activeZoneId]);
$zoneLow       = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND severity = 'low' AND status NOT IN ('resolved','closed')", [$activeZoneId]);
$zoneResolved7 = safeCount($pdo, 'SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND status = \'resolved\' AND resolved_at >= (NOW() - (7) * INTERVAL \'1 day\')', [$activeZoneId]);

// ============================================================
// COMPUTE SAFETY LEVEL
// ============================================================
$safetyLevel = 'low';
$safetyColor = '#28a745';
$safetyLabel = 'Low Risk';
$safetyIcon  = '🟢';
$safetyDesc  = 'Your zone is currently calm. Enjoy your visit responsibly and follow park rules.';

if ($zoneCritical > 0) {
    $safetyLevel = 'critical';
    $safetyColor = '#dc3545';
    $safetyLabel = 'High Alert';
    $safetyIcon  = '🔴';
    $safetyDesc  = 'Critical incidents are active in your zone. Stay within secured areas and follow ranger guidance at all times.';
} elseif ($zoneHigh > 0 || $zoneActive >= 5) {
    $safetyLevel = 'high';
    $safetyColor = '#fd7e14';
    $safetyLabel = 'Elevated Risk';
    $safetyIcon  = '🟠';
    $safetyDesc  = 'Elevated risk in your zone. Avoid remote areas, travel in groups, and report anything unusual immediately.';
} elseif ($zoneMedium > 0 || $zoneActive > 0) {
    $safetyLevel = 'medium';
    $safetyColor = '#ffc107';
    $safetyLabel = 'Moderate Risk';
    $safetyIcon  = '🟡';
    $safetyDesc  = 'A few active incidents in your zone. Normal activity is fine — stay alert and follow standard safety guidelines.';
}

// ============================================================
// REGISTERED CAMPS / LODGES IN THIS ZONE (safe areas)
// If you have a `lodges` table, this shows them; otherwise empty.
// ============================================================
$lodges = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, location_lat, location_lng, description
        FROM lodges
        WHERE zone_id = ? AND is_active = 1
        LIMIT 20
    ");
    $stmt->execute([$activeZoneId]);
    $lodges = $stmt->fetchAll();
} catch (PDOException $e) {
    // lodges table doesn't exist — fine, we just show none
    $lodges = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Safety Map - Tourism - Wildlife Sentinel</title>

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

        /* Safety banner */
        .safety-banner {
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border-left: 6px solid;
        }
        .safety-banner .safety-icon { font-size: 44px; flex-shrink: 0; }
        .safety-banner .safety-info { flex: 1; min-width: 220px; }
        .safety-banner .safety-info .level {
            font-size: 20px; font-weight: 800;
            letter-spacing: -0.3px; margin-bottom: 4px;
        }
        .safety-banner .safety-info .desc {
            font-size: 13px; color: #495057;
            line-height: 1.6;
        }

        /* Stats bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .stat-item {
            background: white;
            padding: 14px 16px;
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
        .stat-item .number.orange { color: #fd7e14; }
        .stat-item .number.yellow { color: #b38600; }
        .stat-item .number.blue   { color: #007bff; }

        /* Map controls */
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
            background: white;
            padding: 14px 22px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-top: 12px;
            display: flex;
            gap: 22px;
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
            width: 14px; height: 14px;
            border-radius: 50%;
            display: inline-block;
            border: 2px solid rgba(0,0,0,0.1);
        }
        .legend-dot.safe     { background: #28a745; }
        .legend-dot.medium   { background: #ffc107; }
        .legend-dot.high     { background: #fd7e14; }
        .legend-dot.critical { background: #dc3545; }
        .legend-dot.buffer   { background: rgba(255,111,0,0.4); border: 1px dashed #FF6F00; }
        .legend-dot.lodge    { background: #007bff; }

        /* Info window */
        .info-window { min-width: 200px; max-width: 280px; font-family: sans-serif; font-size: 13px; }
        .info-window .title { font-weight: 700; font-size: 15px; color: #0d3b22; }
        .info-window .subtitle { font-size: 12px; color: #6c757d; }

        /* Privacy notice */
        .privacy-notice {
            background: #f8fbff;
            border-left: 4px solid #cce5ff;
            border-radius: 10px;
            padding: 14px 18px;
            margin-top: 16px;
            font-size: 13px;
            color: #495057;
            line-height: 1.7;
        }

        /* Debug */
        .debug-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 10px 14px;
            margin-top: 14px;
            font-size: 12px;
            color: #856404;
            font-family: monospace;
            line-height: 1.6;
        }

        /* Mobile */
        @media (max-width: 768px) {
            #map { height: 440px; }
            .stats-bar { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-item { padding: 10px 12px; }
            .stat-item .number { font-size: 18px; }
            .stat-item .label { font-size: 9px; }
            .map-controls .btn .btn-text { display: none; }
            .map-legend { gap: 12px; padding: 10px 14px; flex-wrap: nowrap; overflow-x: auto; }
            .legend-item { font-size: 11px; flex-shrink: 0; }
            .safety-banner { flex-direction: column; text-align: center; }
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
                <h1>Safety Map</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🗺️ Zone Safety Map</h1>
                    <p>Real-time safety status for <strong><?= htmlspecialchars($zone['name'] ?? 'your zone') ?></strong>.</p>
                </div>

                <!-- Safety banner -->
                <div class="safety-banner" style="border-left-color: <?= $safetyColor ?>; background: <?= $safetyColor ?>10;">
                    <div class="safety-icon"><?= $safetyIcon ?></div>
                    <div class="safety-info">
                        <div class="level" style="color: <?= $safetyColor ?>;">
                            Safety Level: <?= htmlspecialchars($safetyLabel) ?>
                        </div>
                        <div class="desc"><?= htmlspecialchars($safetyDesc) ?></div>
                    </div>
                </div>

                <!-- Stats bar -->
                <div class="stats-bar">
                    <div class="stat-item">
                        <span class="number red"><?= $zoneCritical ?></span>
                        <span class="label">🔴 Critical</span>
                    </div>
                    <div class="stat-item">
                        <span class="number orange"><?= $zoneHigh ?></span>
                        <span class="label">🟠 High</span>
                    </div>
                    <div class="stat-item">
                        <span class="number yellow"><?= $zoneMedium ?></span>
                        <span class="label">🟡 Medium</span>
                    </div>
                    <div class="stat-item">
                        <span class="number green"><?= $zoneLow ?></span>
                        <span class="label">🟢 Low</span>
                    </div>
                    <div class="stat-item">
                        <span class="number blue"><?= $zoneResolved7 ?></span>
                        <span class="label">✅ Resolved (7d)</span>
                    </div>
                </div>

                <!-- Map controls -->
                <div class="map-controls">
                    <button class="btn btn-primary" onclick="centerOnZone()">
                        <span class="icon">🟢</span>
                        <span class="btn-text">Center on Zone</span>
                    </button>
                    <button class="btn btn-secondary" onclick="toggleBuffer()">
                        <span class="icon">🟠</span>
                        <span class="btn-text">Toggle Buffer</span>
                    </button>
                    <button class="btn btn-secondary" onclick="toggleLodges()">
                        <span class="icon">🏨</span>
                        <span class="btn-text">Toggle Lodges</span>
                    </button>
                    <button class="btn btn-secondary" onclick="resetView()">
                        <span class="icon">🗺️</span>
                        <span class="btn-text">Reset</span>
                    </button>
                </div>

                <!-- Legend -->
                <div class="map-legend">
                    <span class="legend-item"><span class="legend-dot safe"></span> Low Risk</span>
                    <span class="legend-item"><span class="legend-dot medium"></span> Moderate</span>
                    <span class="legend-item"><span class="legend-dot high"></span> Elevated</span>
                    <span class="legend-item"><span class="legend-dot critical"></span> High Alert</span>
                    <span class="legend-item"><span class="legend-dot buffer"></span> 500m Buffer</span>
                    <span class="legend-item"><span class="legend-dot lodge"></span> Lodges / Camps</span>
                </div>

                <!-- Map -->
                <div id="map"></div>

                <!-- Privacy notice -->
                <div class="privacy-notice">
                    <strong>🔒 Privacy Notice</strong><br>
                    This map shows <b>general zone safety</b> only — the current risk level and the total count of active incidents by severity.
                    For the protection of rangers and investigations, this view does <b>not</b> show:
                    ranger locations or patrol routes, individual incident details, reporter information, AI detection specifics, or any operational intelligence.
                    If you need more detail about a specific incident you reported, check <a href="my-reports.php">My Reports</a>.
                </div>

                <!-- Debug (remove later) -->
                <div class="debug-info">
                    🐛 Zone #<?= $activeZoneId ?> |
                    Safety: <?= $safetyLevel ?> |
                    Incidents: <?= $zoneActive ?> active (<?= $zoneCritical ?>c / <?= $zoneHigh ?>h / <?= $zoneMedium ?>m / <?= $zoneLow ?>l) |
                    Lodges: <?= count($lodges) ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ============================================================
         SCRIPTS
         ============================================================ -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>

    <script>
        // ============================================================
        // DATA FROM PHP
        // ============================================================
        const ZONE        = <?= json_encode($zone, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const LODGES      = <?= json_encode($lodges, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const SAFETY      = <?= json_encode([
            'level' => $safetyLevel,
            'color' => $safetyColor,
            'label' => $safetyLabel,
        ], JSON_UNESCAPED_UNICODE) ?>;
        const ZONE_STATS  = <?= json_encode([
            'active'   => $zoneActive,
            'critical' => $zoneCritical,
            'high'     => $zoneHigh,
            'medium'   => $zoneMedium,
            'low'      => $zoneLow,
            'resolved' => $zoneResolved7,
        ], JSON_UNESCAPED_UNICODE) ?>;

        // ============================================================
        // INIT MAP
        // ============================================================
        let initialCenter = [-14.5, 27.0];
        let initialZoom = 6;

        if (ZONE && (ZONE.center_lat || ZONE.boundary_center_lat)) {
            initialCenter = [
                parseFloat(ZONE.center_lat || ZONE.boundary_center_lat),
                parseFloat(ZONE.center_lng || ZONE.boundary_center_lng)
            ];
            initialZoom = 11;
        }

        const map = L.map('map', {
            center: initialCenter,
            zoom: initialZoom,
            zoomControl: true,
            scrollWheelZoom: true,
        });

        // Base layers
        const osmStandard = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        });

        const esriSatellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            {
                maxZoom: 19,
                attribution: '&copy; Esri, Maxar',
            }
        );

        const osmHumanitarian = L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OSM Humanitarian',
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
        // ZONE BOUNDARY (color = safety level)
        // ============================================================
        let zoneLayer = null;
        if (ZONE && ZONE.boundary_geojson && ZONE.boundary_geojson.type === 'Polygon') {
            try {
                zoneLayer = L.geoJSON(ZONE.boundary_geojson, {
                    style: {
                        color: SAFETY.color,
                        weight: 3,
                        fillColor: SAFETY.color,
                        fillOpacity: 0.15,
                    }
                }).addTo(map);

                zoneLayer.bindPopup(`
                    <div class="info-window">
                        <div class="title">${ZONE.name}</div>
                        <div class="subtitle">
                            Safety: <strong style="color:${SAFETY.color};">${SAFETY.label.toUpperCase()}</strong>
                        </div>
                        <hr style="margin:6px 0;border:none;border-top:1px solid #eee;">
                        Active incidents: <strong>${ZONE_STATS.active}</strong><br>
                        Critical: <strong style="color:#dc3545;">${ZONE_STATS.critical}</strong><br>
                        High: <strong style="color:#fd7e14;">${ZONE_STATS.high}</strong><br>
                        Medium: <strong style="color:#b38600;">${ZONE_STATS.medium}</strong><br>
                        Low: <strong style="color:#28a745;">${ZONE_STATS.low}</strong>
                    </div>
                `);
            } catch (e) { console.warn('Zone boundary error:', e); }
        }

        // ============================================================
        // 500m BUFFER
        // ============================================================
        let bufferLayer = null;
        if (ZONE && ZONE.buffer_geojson && ZONE.buffer_geojson.type === 'Polygon') {
            try {
                bufferLayer = L.geoJSON(ZONE.buffer_geojson, {
                    style: {
                        color: '#FF6F00',
                        weight: 2,
                        dashArray: '6,6',
                        fillColor: '#FF6F00',
                        fillOpacity: 0.05,
                    }
                }).addTo(map);
            } catch (e) { console.warn('Buffer error:', e); }
        }

        // ============================================================
        // LODGES / CAMPS (safe areas)
        // ============================================================
        const lodgeLayer = L.layerGroup().addTo(map);
        LODGES.forEach(l => {
            if (!l.location_lat || !l.location_lng) return;
            L.marker(
                [parseFloat(l.location_lat), parseFloat(l.location_lng)],
                {
                    icon: L.divIcon({
                        className: 'lodge-marker',
                        html: `<div style="background:#007bff;width:26px;height:26px;border-radius:50%;
                                    border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);
                                    display:flex;align-items:center;justify-content:center;
                                    color:white;font-size:13px;">🏨</div>`,
                        iconSize: [26, 26],
                        iconAnchor: [13, 13],
                    })
                }
            ).addTo(lodgeLayer).bindPopup(`
                <div class="info-window">
                    <div class="title">🏨 ${l.name}</div>
                    ${l.description ? '<div class="subtitle" style="margin-top:4px;">' + l.description.substring(0, 100) + '</div>' : ''}
                </div>
            `);
        });

        // ============================================================
        // MAP CONTROLS
        // ============================================================
        function centerOnZone() {
            if (ZONE && ZONE.boundary_geojson) {
                const bounds = L.geoJSON(ZONE.boundary_geojson).getBounds();
                if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [40, 40] });
                    return;
                }
            }
            map.setView(initialCenter, initialZoom);
        }

        let bufferVisible = true;
        function toggleBuffer() {
            if (!bufferLayer) return;
            if (bufferVisible) {
                map.removeLayer(bufferLayer);
            } else {
                bufferLayer.addTo(map);
            }
            bufferVisible = !bufferVisible;
        }

        let lodgesVisible = true;
        function toggleLodges() {
            if (lodgesVisible) {
                map.removeLayer(lodgeLayer);
            } else {
                lodgeLayer.addTo(map);
            }
            lodgesVisible = !lodgesVisible;
        }

        function resetView() {
            map.setView(initialCenter, initialZoom);
            if (bufferLayer && !map.hasLayer(bufferLayer)) { bufferLayer.addTo(map); bufferVisible = true; }
            if (!map.hasLayer(lodgeLayer)) { lodgeLayer.addTo(map); lodgesVisible = true; }
            if (zoneLayer && !map.hasLayer(zoneLayer)) zoneLayer.addTo(map);
        }

        // ============================================================
        // RESPONSIVE
        // ============================================================
        window.addEventListener('resize', () => map.invalidateSize());
        document.addEventListener('sidebarToggled', () => {
            setTimeout(() => map.invalidateSize(), 400);
        });

        console.log('✅ Tourism safety map loaded');
        console.log('📍 Zone:', <?= json_encode($zone['name'] ?? 'N/A') ?>);
        console.log('🛡️ Safety:', SAFETY.label, '(' + SAFETY.level + ')');
        console.log('🚨 Active incidents:', ZONE_STATS.active);
    </script>
</body>
</html>
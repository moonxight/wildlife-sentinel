<?php
// ============================================================
// ranger/dashboard.php
// Wildlife Sentinel — Ranger Dashboard
// ------------------------------------------------------------
// Layout includes:
//   - Animal photo slideshow (8 rotating images)
//   - Live stats (assigned, active, resolved, etc.)
//   - Assigned incidents list
//   - Quick actions
//   - Mini live map
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
$zone   = getZone($zoneId);

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
// MY LIVE GPS POSITION
// ============================================================
$myTracking = safeFetchAll($pdo, "
    SELECT current_lat, current_lng, heading, speed, last_update, is_offline
    FROM ranger_live_tracking
    WHERE ranger_id = ?
", [$user['id']]);
$myLocation = $myTracking[0] ?? null;

// ============================================================
// MY ASSIGNED INCIDENTS
// ============================================================
$myIncidents = safeFetchAll($pdo, '
    SELECT i.*, u.full_name AS reporter_name, u.phone AS reporter_phone
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    WHERE i.acknowledged_by = ?
      AND i.status IN (\'acknowledged\',\'in_progress\')
    ORDER BY CASE i.severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, i.reported_at DESC
', [$user['id']]);

// ============================================================
// ZONE INCIDENTS (unassigned / open)
// ============================================================
$zoneIncidents = safeFetchAll($pdo, '
    SELECT i.*, u.full_name AS reporter_name
    FROM incidents i
    LEFT JOIN users u ON i.reporter_id = u.id
    WHERE i.zone_id = ?
      AND i.status = \'reported\'
    ORDER BY CASE i.severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, i.reported_at DESC
    LIMIT 10
', [$zoneId]);

// ============================================================
// STATS
// ============================================================
$stats = [
    'assigned'         => count($myIncidents),
    'zone_open'        => count($zoneIncidents),
    'zone_active'      => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND status NOT IN ('resolved','closed')", [$zoneId]),
    'my_resolved'      => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE acknowledged_by = ? AND status = 'resolved'", [$user['id']]),
    'my_total'         => safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE acknowledged_by = ?", [$user['id']]),
    'scouts_online'    => safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'scout' AND is_online = 1 AND is_active = 1", [$zoneId]),
    'rangers_on_duty'  => safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'ranger' AND is_on_duty = 1 AND is_active = 1", [$zoneId]),
    'unread_notifs'    => function_exists('getUnreadNotificationCount') ? getUnreadNotificationCount($user['id']) : 0,
    'unread_messages'  => function_exists('getUnreadMessageCount')      ? getUnreadMessageCount($user['id'])      : 0,
];

// ============================================================
// ANIMAL SLIDESHOW — Wikimedia/Unsplash links (stable URLs)
// ============================================================
$animalSlides = [
    [
        'url'  => 'https://images.unsplash.com/photo-1546182990-dffeafbe841d?w=1400&q=80',
        'icon' => '🦁',
        'name' => 'African Lion',
        'desc' => 'Apex predator of the Luangwa Valley',
        'tag'  => 'Kafue & South Luangwa',
    ],
    [
        'url'  => 'https://images.unsplash.com/photo-1549366021-9f761d450615?w=1400&q=80',
        'icon' => '🐘',
        'name' => 'African Elephant',
        'desc' => 'Largest land mammal on Earth',
        'tag'  => 'South Luangwa',
    ],
    [
        'url'  => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSYP7VqLogQw2wpqkXSYkUOxvSt270IqV5ZrwybDem6dQ&s=10',
        'icon' => '🦒',
        'name' => 'Thornicroft\'s Giraffe',
        'desc' => 'Endemic to the Luangwa Valley',
        'tag'  => 'South Luangwa',
    ],
    [
        'url'  => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSHxZBk463Ny8y1LfDY6ELcgSQfx3iOwrxgJnOrIzfdRg&s=10',
        'icon' => '🦏',
        'name' => 'Black Rhinoceros',
        'desc' => 'Critically endangered — heavily protected',
        'tag'  => 'North Luangwa',
    ],
    [
        'url'  => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSKmt4OZGYlj1vArbRdd4hqSCd34tJoKcJvgMAGVMHkVQ&s=10',
        'icon' => '🐆',
        'name' => 'African Leopard',
        'desc' => 'Elusive nocturnal predator',
        'tag'  => 'Liuwa Plain',
    ],
    [
        'url'  => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQHIozGzzwY3Hu0LtfRZxP5O9jb_5qn5-x4Kryyr9bzSg&s=10',
        'icon' => '🦓',
        'name' => 'Plains Zebra',
        'desc' => 'Famous Liuwa migration herds',
        'tag'  => 'Liuwa Plain',
    ],
    [
        'url'  => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTGxaGAuDE-ikcYySJ3eTQXD1B6uU4XjskMfQxDy9LWrw&s=10',
        'icon' => '🐊',
        'name' => 'Nile Crocodile',
        'desc' => 'Ancient apex predator of the Zambezi',
        'tag'  => 'Lower Zambezi',
    ],
    [
        'url'  => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSM610TVyf3T0Pno29pe95w2Aetx5W0BPQr0LVC3IMaFA&s=10',
        'icon' => '🦅',
        'name' => 'African Fish Eagle',
        'desc' => 'Zambia\'s national bird',
        'tag'  => 'Nationwide',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ranger Dashboard - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 22px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p { color: #6c757d; font-size: 15px; }

        /* ============================================================
           ANIMAL SLIDESHOW
           ============================================================ */
        .animal-slideshow {
            position: relative;
            height: 280px;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.15);
            background: #0d3b22;
        }

        .animal-slideshow .slide {
            position: absolute;
            inset: 0;
            background-size: cover;
            background-position: center;
            opacity: 0;
            transition: opacity 1.4s ease-in-out;
            transform: scale(1.05);
        }
        .animal-slideshow .slide.active {
            opacity: 1;
            animation: kenburns 8s ease-in-out infinite alternate;
        }
        @keyframes kenburns {
            from { transform: scale(1); }
            to   { transform: scale(1.08); }
        }

        .animal-slideshow .slide-overlay {
            position: absolute;
            inset: 0;
            z-index: 1;
            background: linear-gradient(
                135deg,
                rgba(10,20,10,0.75) 0%,
                rgba(10,20,10,0.25) 45%,
                rgba(10,20,10,0.75) 100%
            );
        }

        .animal-slideshow .slide-content {
            position: relative;
            z-index: 2;
            height: 100%;
            padding: 28px 36px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: white;
        }

        .animal-slideshow .slide-content .animal-icon {
            font-size: 48px;
            display: block;
            margin-bottom: 10px;
            filter: drop-shadow(0 2px 10px rgba(0,0,0,0.4));
        }

        .animal-slideshow .slide-content .animal-name {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.5px;
            text-shadow: 0 2px 12px rgba(0,0,0,0.5);
            margin: 0;
        }

        .animal-slideshow .slide-content .animal-desc {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 6px;
            max-width: 480px;
            text-shadow: 0 1px 6px rgba(0,0,0,0.5);
        }

        .animal-slideshow .slide-content .animal-tag {
            display: inline-block;
            align-self: flex-start;
            margin-top: 14px;
            padding: 5px 14px;
            border-radius: 20px;
            background: rgba(74, 222, 128, 0.2);
            border: 1px solid rgba(74, 222, 128, 0.4);
            color: #d4ffe0;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            backdrop-filter: blur(6px);
        }

        .animal-slideshow .slide-indicators {
            position: absolute;
            bottom: 14px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 3;
            display: flex;
            gap: 8px;
        }
        .animal-slideshow .slide-indicators .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            cursor: pointer;
            transition: all 0.3s;
        }
        .animal-slideshow .slide-indicators .dot.active {
            background: #4ade80;
            transform: scale(1.3);
        }

        .animal-slideshow .slide-counter {
            position: absolute;
            top: 16px;
            right: 20px;
            z-index: 3;
            background: rgba(0,0,0,0.4);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            backdrop-filter: blur(6px);
        }

        /* ============================================================
           STATS GRID
           ============================================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid #f0f0f0;
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .stat-card .icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .icon.teal   { background: #d1ecf1; color: #0c5460; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        /* ============================================================
           SECTIONS
           ============================================================ */
        .section {
            background: white;
            border-radius: 14px;
            padding: 20px 22px;
            margin-bottom: 20px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid #f0f0f0;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .section-header h2 {
            font-size: 17px;
            color: #0d3b22;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-header .view-all {
            color: #1a5c3a;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        .section-header .view-all:hover { text-decoration: underline; }

        /* ============================================================
           INCIDENT ITEM
           ============================================================ */
        .incident-item {
            display: flex;
            align-items: center;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 8px;
            background: #fafafa;
            border-left: 4px solid #ffc107;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            gap: 12px;
        }
        .incident-item:hover {
            background: #f0f0f0;
            transform: translateX(2px);
        }
        .incident-item.critical { border-left-color: #dc3545; background: #fdf5f5; }
        .incident-item.high     { border-left-color: #fd7e14; }
        .incident-item.medium   { border-left-color: #ffc107; }
        .incident-item.low      { border-left-color: #28a745; }

        .incident-item .inc-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .incident-item .inc-info { flex: 1; min-width: 0; }
        .incident-item .inc-title { font-weight: 600; font-size: 14px; color: #0d3b22; }
        .incident-item .inc-meta  { font-size: 11px; color: #6c757d; margin-top: 3px; }

        .sev-pill {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .sev-pill.critical { background: #dc3545; color: white; animation: pulse 1.5s infinite; }
        .sev-pill.high     { background: #fff3cd; color: #856404; }
        .sev-pill.medium   { background: #fff3cd; color: #856404; }
        .sev-pill.low      { background: #d4edda; color: #155724; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.6; } }

        /* ============================================================
           QUICK ACTIONS
           ============================================================ */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }
        .quick-tile {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 18px 12px;
            background: #fafafa;
            border-radius: 10px;
            text-decoration: none;
            color: #495057;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        .quick-tile:hover {
            background: white;
            border-color: #1a5c3a;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .quick-tile .icon { font-size: 26px; margin-bottom: 6px; }
        .quick-tile .label { font-size: 12px; font-weight: 600; text-align: center; }
        .quick-tile .badge {
            font-size: 10px; padding: 2px 8px;
            border-radius: 10px; background: #dc3545;
            color: white; margin-top: 4px;
        }

        /* ============================================================
           MINI MAP
           ============================================================ */
        #miniMap {
            height: 320px;
            border-radius: 12px;
            background: #e0e0e0;
        }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #6c757d;
            font-size: 13px;
        }
        .empty-state .icon {
            font-size: 40px;
            display: block;
            margin-bottom: 8px;
            opacity: 0.4;
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .animal-slideshow { height: 200px; border-radius: 12px; }
            .animal-slideshow .slide-content { padding: 20px 24px; }
            .animal-slideshow .slide-content .animal-icon { font-size: 36px; }
            .animal-slideshow .slide-content .animal-name { font-size: 22px; }
            .animal-slideshow .slide-content .animal-desc { font-size: 12px; }
            .animal-slideshow .slide-content .animal-tag { font-size: 9px; padding: 3px 10px; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            #miniMap { height: 260px; }
        }
        @media (max-width: 480px) {
            .animal-slideshow { height: 170px; }
            .animal-slideshow .slide-content { padding: 14px 18px; }
            .animal-slideshow .slide-content .animal-icon { font-size: 28px; margin-bottom: 4px; }
            .animal-slideshow .slide-content .animal-name { font-size: 18px; }
            .animal-slideshow .slide-content .animal-desc { font-size: 11px; }
            .animal-slideshow .slide-counter { top: 10px; right: 12px; font-size: 10px; padding: 2px 8px; }
            .stat-card { padding: 10px 12px; }
            .stat-card .icon { width: 36px; height: 36px; font-size: 16px; }
            .stat-card .info .number { font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Ranger Dashboard</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🛡️ Welcome back, <?= htmlspecialchars($user['full_name']) ?>!</h1>
                    <p>
                        Zone: <strong><?= htmlspecialchars($zone['name'] ?? 'Unassigned') ?></strong>
                        <?= (int)($user['is_on_duty'] ?? 0) === 1 ? ' · <span style="color:#28a745;">🟢 On Duty</span>' : ' · <span style="color:#adb5bd;">⚪ Off Duty</span>' ?>
                    </p>
                </div>

                <!-- ============================================================
                     ANIMAL SLIDESHOW
                     ============================================================ -->
                <div class="animal-slideshow" id="animalSlideshow">
                    <?php foreach ($animalSlides as $i => $slide): ?>
                        <div class="slide <?= $i === 0 ? 'active' : '' ?>"
                             data-index="<?= $i ?>"
                             style="background-image: url('<?= htmlspecialchars($slide['url']) ?>');">
                        </div>
                    <?php endforeach; ?>

                    <div class="slide-overlay"></div>

                    <div class="slide-content" id="slideContent">
                        <span class="animal-icon" id="slideIcon"><?= htmlspecialchars($animalSlides[0]['icon']) ?></span>
                        <h2 class="animal-name" id="slideName"><?= htmlspecialchars($animalSlides[0]['name']) ?></h2>
                        <div class="animal-desc" id="slideDesc"><?= htmlspecialchars($animalSlides[0]['desc']) ?></div>
                        <span class="animal-tag" id="slideTag"><?= htmlspecialchars($animalSlides[0]['tag']) ?></span>
                    </div>

                    <div class="slide-counter" id="slideCounter">1 / <?= count($animalSlides) ?></div>

                    <div class="slide-indicators" id="slideIndicators">
                        <?php foreach ($animalSlides as $i => $slide): ?>
                            <span class="dot <?= $i === 0 ? 'active' : '' ?>" data-index="<?= $i ?>"></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ============================================================
                     STATS
                     ============================================================ -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="icon red">🚨</div>
                        <div class="info">
                            <div class="number"><?= $stats['assigned'] ?></div>
                            <div class="label">My Assigned</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="icon orange">📋</div>
                        <div class="info">
                            <div class="number"><?= $stats['zone_open'] ?></div>
                            <div class="label">Open in Zone</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="icon green">✅</div>
                        <div class="info">
                            <div class="number"><?= $stats['my_resolved'] ?></div>
                            <div class="label">My Resolved</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="icon blue">👥</div>
                        <div class="info">
                            <div class="number"><?= $stats['scouts_online'] ?></div>
                            <div class="label">Scouts Online</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="icon purple">🛡️</div>
                        <div class="info">
                            <div class="number"><?= $stats['rangers_on_duty'] ?></div>
                            <div class="label">Rangers On Duty</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="icon teal">🔔</div>
                        <div class="info">
                            <div class="number"><?= $stats['unread_notifs'] ?></div>
                            <div class="label">Notifications</div>
                        </div>
                    </div>
                </div>

                <!-- ============================================================
                     MY ASSIGNED INCIDENTS
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <h2>🚨 My Assigned Incidents (<?= count($myIncidents) ?>)</h2>
                        <a href="incidents.php" class="view-all">View All →</a>
                    </div>

                    <?php if (count($myIncidents) > 0): ?>
                        <?php foreach ($myIncidents as $inc): ?>
                            <a href="incidents.php?id=<?= (int)$inc['id'] ?>" class="incident-item <?= htmlspecialchars($inc['severity']) ?>">
                                <div class="inc-icon">
                                    <?= function_exists('getCategoryIcon') ? getCategoryIcon($inc['category']) : '🚨' ?>
                                </div>
                                <div class="inc-info">
                                    <div class="inc-title">
                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $inc['category']))) ?>
                                        <span style="font-weight:400;color:#6c757d;font-size:12px;">#<?= (int)$inc['id'] ?></span>
                                    </div>
                                    <div class="inc-meta">
                                        👤 <?= htmlspecialchars($inc['reporter_name'] ?? 'N/A') ?>
                                        • 📞 <?= htmlspecialchars($inc['reporter_phone'] ?? 'N/A') ?>
                                        • 🕐 <?= timeAgo($inc['reported_at']) ?>
                                    </div>
                                </div>
                                <span class="sev-pill <?= htmlspecialchars($inc['severity']) ?>">
                                    <?= strtoupper(htmlspecialchars($inc['severity'])) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">✅</span>
                            <h3 style="font-size:14px;color:#495057;">No assigned incidents</h3>
                            <p>You're all caught up. Check the open incidents list below.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ============================================================
                     OPEN INCIDENTS IN ZONE
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <h2>📋 Open Incidents in Your Zone (<?= count($zoneIncidents) ?>)</h2>
                        <a href="incidents.php" class="view-all">View All →</a>
                    </div>

                    <?php if (count($zoneIncidents) > 0): ?>
                        <?php foreach ($zoneIncidents as $inc): ?>
                            <a href="incidents.php?id=<?= (int)$inc['id'] ?>" class="incident-item <?= htmlspecialchars($inc['severity']) ?>">
                                <div class="inc-icon">
                                    <?= function_exists('getCategoryIcon') ? getCategoryIcon($inc['category']) : '🚨' ?>
                                </div>
                                <div class="inc-info">
                                    <div class="inc-title">
                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $inc['category']))) ?>
                                        <span style="font-weight:400;color:#6c757d;font-size:12px;">#<?= (int)$inc['id'] ?></span>
                                    </div>
                                    <div class="inc-meta">
                                        👤 <?= htmlspecialchars($inc['reporter_name'] ?? 'N/A') ?>
                                        • 🕐 <?= timeAgo($inc['reported_at']) ?>
                                        • 📍 <?= number_format((float)$inc['location_lat'], 4) ?>, <?= number_format((float)$inc['location_lng'], 4) ?>
                                    </div>
                                </div>
                                <span class="sev-pill <?= htmlspecialchars($inc['severity']) ?>">
                                    <?= strtoupper(htmlspecialchars($inc['severity'])) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">🌿</span>
                            <h3 style="font-size:14px;color:#495057;">No open incidents</h3>
                            <p>Your zone is currently peaceful.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ============================================================
                     QUICK ACTIONS
                     ============================================================ -->
                <div class="section">
                    <div class="section-header"><h2>⚡ Quick Actions</h2></div>
                    <div class="quick-grid">
                        <a href="map.php" class="quick-tile">
                            <span class="icon">🗺️</span>
                            <span class="label">Live Map</span>
                        </a>
                        <a href="incidents.php" class="quick-tile">
                            <span class="icon">🚨</span>
                            <span class="label">Incidents</span>
                            <?php if ($stats['assigned'] > 0): ?>
                                <span class="badge"><?= $stats['assigned'] ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="request-manpower.php" class="quick-tile">
                            <span class="icon">🆘</span>
                            <span class="label">Request Help</span>
                        </a>
                        <a href="messages.php" class="quick-tile">
                            <span class="icon">💬</span>
                            <span class="label">Messages</span>
                            <?php if ($stats['unread_messages'] > 0): ?>
                                <span class="badge"><?= $stats['unread_messages'] ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="notifications.php" class="quick-tile">
                            <span class="icon">🔔</span>
                            <span class="label">Notifications</span>
                            <?php if ($stats['unread_notifs'] > 0): ?>
                                <span class="badge"><?= $stats['unread_notifs'] ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="profile.php" class="quick-tile">
                            <span class="icon">👤</span>
                            <span class="label">My Profile</span>
                        </a>
                    </div>
                </div>

                <!-- ============================================================
                     MINI LIVE MAP
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <h2>🗺️ My Live Position</h2>
                        <a href="map.php" class="view-all">Full Map →</a>
                    </div>
                    <div id="miniMap"></div>
                    <div style="margin-top:10px;font-size:12px;color:#6c757d;">
                        <?php if ($myLocation && $myLocation['current_lat']): ?>
                            📍 Last update: <?= timeAgo($myLocation['last_update']) ?>
                            <?php if ($myLocation['speed']): ?>
                                • ⚡ <?= number_format((float)$myLocation['speed'], 1) ?> m/s
                            <?php endif; ?>
                            <?php if ($myLocation['heading']): ?>
                                • 🧭 <?= number_format((float)$myLocation['heading'], 0) ?>°
                            <?php endif; ?>
                        <?php else: ?>
                            ⚠️ GPS position not available yet. Open <a href="map.php">Live Map</a> to start tracking.
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Debug (remove later) -->
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:11px;color:#856404;margin-top:14px;">
                    🐛 Assigned: <?= $stats['assigned'] ?> |
                    Zone open: <?= $stats['zone_open'] ?> |
                    GPS: <?= $myLocation ? '✅' : '❌' ?> |
                    User: #<?= (int)$user['id'] ?> |
                    Zone: #<?= $zoneId ?>
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
        // ANIMAL SLIDESHOW
        // ============================================================
        (function () {
            const slides = <?= json_encode($animalSlides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            const totalSlides = slides.length;
            const slideEls = document.querySelectorAll('.animal-slideshow .slide');
            const dotEls   = document.querySelectorAll('.animal-slideshow .dot');
            const el = {
                icon:    document.getElementById('slideIcon'),
                name:    document.getElementById('slideName'),
                desc:    document.getElementById('slideDesc'),
                tag:     document.getElementById('slideTag'),
                counter: document.getElementById('slideCounter'),
            };
            let current = 0;
            let timer = null;

            function goTo(index) {
                index = ((index % totalSlides) + totalSlides) % totalSlides;

                // Slides
                slideEls.forEach((s, i) => s.classList.toggle('active', i === index));
                dotEls.forEach((d, i) => d.classList.toggle('active', i === index));

                // Content
                const slide = slides[index];
                el.icon.textContent = slide.icon;
                el.name.textContent = slide.name;
                el.desc.textContent = slide.desc;
                el.tag.textContent  = slide.tag;
                el.counter.textContent = (index + 1) + ' / ' + totalSlides;

                current = index;
            }

            function next() { goTo(current + 1); }

            function start() {
                stop();
                timer = setInterval(next, 5500);
            }

            function stop() {
                if (timer) clearInterval(timer);
                timer = null;
            }

            // Dots
            dotEls.forEach(d => {
                d.addEventListener('click', () => {
                    goTo(parseInt(d.dataset.index));
                    start();
                });
            });

            // Pause on hover (desktop)
            const wrapper = document.getElementById('animalSlideshow');
            wrapper.addEventListener('mouseenter', stop);
            wrapper.addEventListener('mouseleave', start);

            // Pause when tab hidden
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) stop(); else start();
            });

            // Start
            goTo(0);
            start();
        })();

        // ============================================================
        // MINI LIVE MAP
        // ============================================================
        (function () {
            const user = <?= json_encode([
                'id'   => (int)$user['id'],
                'name' => $user['full_name'],
            ], JSON_UNESCAPED_UNICODE) ?>;

            const zone = <?= json_encode($zone, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            const myLoc = <?= json_encode($myLocation, JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
            const incidents = <?= json_encode($myIncidents, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

            // Center
            let center = [-14.5, 27.0];
            let zoom = 6;
            if (myLoc && myLoc.current_lat && myLoc.current_lng) {
                center = [parseFloat(myLoc.current_lat), parseFloat(myLoc.current_lng)];
                zoom = 14;
            } else if (zone && (zone.center_lat || zone.boundary_center_lat)) {
                center = [
                    parseFloat(zone.center_lat || zone.boundary_center_lat),
                    parseFloat(zone.center_lng || zone.boundary_center_lng)
                ];
                zoom = 11;
            }

            const map = L.map('miniMap', {
                center: center,
                zoom: zoom,
                zoomControl: true,
                scrollWheelZoom: false, // avoid accidental zoom while scrolling dashboard
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);

            // Zone boundary
            if (zone && zone.boundary_geojson && zone.boundary_geojson.type === 'Polygon') {
                try {
                    L.geoJSON(zone.boundary_geojson, {
                        style: {
                            color: '#1B5E20',
                            weight: 3,
                            fillColor: '#1B5E20',
                            fillOpacity: 0.08,
                        }
                    }).addTo(map).bindPopup(`<strong>${zone.name}</strong><br>Your zone`);
                } catch (e) {}
            }

            // My position
            if (myLoc && myLoc.current_lat && myLoc.current_lng) {
                const meIcon = L.divIcon({
                    className: 'me-mini',
                    html: `<div style="background:#2E7D32;width:24px;height:24px;border-radius:50%;
                                border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.4);
                                display:flex;align-items:center;justify-content:center;
                                color:white;font-size:12px;font-weight:bold;">🛡️</div>`,
                    iconSize: [24, 24],
                    iconAnchor: [12, 12],
                });
                L.marker([parseFloat(myLoc.current_lat), parseFloat(myLoc.current_lng)], { icon: meIcon })
                    .addTo(map)
                    .bindPopup(`<strong>${user.name}</strong><br>Your current position`)
                    .openPopup();
            }

            // My incidents
            const severityColors = { critical:'#dc3545', high:'#fd7e14', medium:'#ffc107', low:'#28a745' };
            incidents.forEach(inc => {
                if (!inc.location_lat || !inc.location_lng) return;
                const sev = inc.severity || 'medium';
                const color = severityColors[sev] || '#6c757d';

                L.circleMarker([parseFloat(inc.location_lat), parseFloat(inc.location_lng)], {
                    radius: 9,
                    fillColor: color,
                    color: '#fff',
                    weight: 2,
                    fillOpacity: 0.9,
                }).addTo(map).bindPopup(`
                    <strong>🚨 #${inc.id}</strong><br>
                    ${(inc.category || '').replace(/_/g, ' ')}<br>
                    <strong style="color:${color};">${sev.toUpperCase()}</strong>
                `);
            });

            // Resize on sidebar toggle
            document.addEventListener('sidebarToggled', () => {
                setTimeout(() => map.invalidateSize(), 400);
            });
            window.addEventListener('resize', () => map.invalidateSize());

            console.log('✅ Mini map loaded — GPS:', myLoc ? 'yes' : 'no');
        })();

        console.log('✅ Ranger dashboard loaded');
    </script>
</body>
</html>
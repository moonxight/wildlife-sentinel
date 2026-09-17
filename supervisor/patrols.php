<?php
// ============================================================
// supervisor/patrols.php
// Zone Supervisor — Patrol Routes & Coverage
// ============================================================

require_once '../includes/functions.php';
requireLogin();

if (!hasRole('zone_supervisor')) {
    header('Location: ../index.php');
    exit();
}

$user = getCurrentUser();
$pdo  = getDB();
$activeZoneId = $user['zone_id'];

function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message = ''; $messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ---- ASSIGN PATROL ROUTE ----
    if ($action === 'assign_route') {
        $rangerId = (int)$_POST['ranger_id'];
        $routeName = trim($_POST['route_name'] ?? '');
        $startLat  = $_POST['start_lat'] ?? null;
        $startLng  = $_POST['start_lng'] ?? null;
        $endLat    = $_POST['end_lat'] ?? null;
        $endLng    = $_POST['end_lng'] ?? null;
        $notes     = trim($_POST['notes'] ?? '');

        // Verify ranger in zone
        $ranger = safeFetchAll($pdo, "SELECT id FROM users WHERE id = ? AND zone_id = ? AND role = 'ranger' AND is_active = 1", [$rangerId, $activeZoneId]);
        if (!$ranger) {
            $message = 'Ranger not found in your zone.';
            $messageType = 'danger';
        } elseif (!$routeName || !$startLat || !$startLng) {
            $message = 'Route name and start coordinates are required.';
            $messageType = 'danger';
        } else {
            // Store in ranger_live_tracking metadata (or a dedicated table if you have one)
            try {
                $pdo->prepare("
                    INSERT INTO ranger_patrol_routes
                        (ranger_id, zone_id, route_name, start_lat, start_lng, end_lat, end_lng, notes, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$rangerId, $activeZoneId, $routeName, $startLat, $startLng, $endLat, $endLng, $notes, $user['id']]);
                logAudit($user['id'], 'assign_patrol_route', ['ranger_id' => $rangerId, 'route' => $routeName]);
                $message = 'Patrol route assigned successfully.';
            } catch (PDOException $e) {
                // If table doesn't exist, fall back to storing as a message
                try {
                    sendUserMessage(
                        $user['id'],
                        $rangerId,
                        "New patrol route assigned: {$routeName}\n"
                      . "Start: {$startLat}, {$startLng}\n"
                      . ($endLat ? "End: {$endLat}, {$endLng}\n" : '')
                      . ($notes ? "Notes: {$notes}" : ''),
                        null,
                        'status_update',
                        'high'
                    );
                    $message = 'Patrol route sent to ranger (message).';
                } catch (Exception $e2) {
                    $message = 'Failed to assign route.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

// ============================================================
// FETCH RANGERS (with live status)
// ============================================================
$rangers = safeFetchAll($pdo, '
    SELECT u.id, u.full_name, u.badge_number, u.is_on_duty, u.is_online,
           rlt.current_lat, rlt.current_lng, rlt.last_update AS location_updated,
           ra.is_available,
           (SELECT COUNT(*) FROM ranger_location_history rlh WHERE rlh.ranger_id = u.id AND rlh.timestamp >= (NOW() - (24) * INTERVAL \'1 hour\')) AS gps_points_24h
    FROM users u
    LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
    LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
    WHERE u.zone_id = ? AND u.role = \'ranger\' AND u.is_active = 1
    ORDER BY u.is_on_duty DESC, u.full_name
', [$activeZoneId]);

// ============================================================
// FETCH PATROL ROUTES (if table exists)
// ============================================================
$routes = [];
try {
    $stmt = $pdo->prepare("
        SELECT pr.*, u.full_name AS ranger_name, u.badge_number
        FROM ranger_patrol_routes pr
        JOIN users u ON pr.ranger_id = u.id
        WHERE pr.zone_id = ?
        ORDER BY pr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$activeZoneId]);
    $routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $routes = [];
}

// ============================================================
// STATS
// ============================================================
$totalRangers    = count($rangers);
$onDutyRangers   = count(array_filter($rangers, fn($r) => $r['is_on_duty']));
$totalGpsPoints  = array_sum(array_column($rangers, 'gps_points_24h'));
$totalRoutes     = count($routes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Patrol Routes - Supervisor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        .dashboard-greeting { margin-bottom: 24px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 16px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-sm { padding: 6px 12px; font-size: 11px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        .rangers-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .ranger-card { border: 1px solid #f0f0f0; border-radius: 12px; padding: 16px; background: white; transition: all 0.2s; }
        .ranger-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.06); border-color: #1a5c3a; }
        .ranger-card.on-duty { border-left: 5px solid #28a745; }
        .ranger-card.off-duty { border-left: 5px solid #adb5bd; }

        .ranger-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .ranger-avatar { width: 44px; height: 44px; border-radius: 50%; background: #1a5c3a; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 18px; flex-shrink: 0; }
        .ranger-name { font-weight: 700; font-size: 14px; color: #0d3b22; }
        .ranger-badge { font-size: 11px; color: #6c757d; }

        .ranger-detail { font-size: 12px; color: #495057; margin: 4px 0; }
        .ranger-status-pill { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; display: inline-block; margin-left: auto; }
        .ranger-status-pill.on  { background: #d4edda; color: #155724; }
        .ranger-status-pill.off { background: #e9ecef; color: #495057; }

        .route-item { padding: 12px 14px; border-bottom: 1px solid #f5f5f5; display: flex; align-items: center; gap: 12px; }
        .route-item:last-child { border-bottom: none; }
        .route-icon { width: 36px; height: 36px; border-radius: 10px; background: #e8f5e9; color: #1a5c3a; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }

        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-backdrop.show { display: flex; }
        .modal { background: white; border-radius: 14px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-header { padding: 18px 22px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 18px; color: #0d3b22; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6c757d; }
        .modal-body { padding: 22px; }
        .modal-footer { padding: 16px 22px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 10px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; color: #495057; font-weight: 600; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 8px;
            font-size: 13px; background: #fafafa; transition: all 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #1a5c3a; background: white; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Patrol Routes</h1>
            <div class="header-right">
                <span class="online-status">● Online</span>
                <span class="data-honesty-badge">🟢 Live Data</span>
                <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
            </div>
        </header>

        <div class="content">
            <div class="dashboard-greeting">
                <h1>🛤️ Patrol Routes</h1>
                <p>Assign and monitor patrol coverage in <strong><?= htmlspecialchars(getZoneName($activeZoneId)) ?></strong>.</p>
            </div>

            <?php if ($message): ?>
                <div class="alert <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card"><div class="icon blue">👤</div><div class="info"><div class="number"><?= $totalRangers ?></div><div class="label">Total Rangers</div></div></div>
                <div class="stat-card"><div class="icon green">🟢</div><div class="info"><div class="number"><?= $onDutyRangers ?></div><div class="label">On Duty</div></div></div>
                <div class="stat-card"><div class="icon orange">📍</div><div class="info"><div class="number"><?= number_format($totalGpsPoints) ?></div><div class="label">GPS Points (24h)</div></div></div>
                <div class="stat-card"><div class="icon purple">🛤️</div><div class="info"><div class="number"><?= $totalRoutes ?></div><div class="label">Assigned Routes</div></div></div>
            </div>

            <!-- Rangers Grid -->
            <div class="section">
                <div class="section-header">
                    <h2>🛡️ Rangers in Zone</h2>
                    <a href="rangers.php" class="btn btn-secondary btn-sm">Manage →</a>
                </div>

                <?php if (count($rangers) > 0): ?>
                    <div class="rangers-grid">
                        <?php foreach ($rangers as $r): ?>
                        <div class="ranger-card <?= $r['is_on_duty'] ? 'on-duty' : 'off-duty' ?>">
                            <div class="ranger-header">
                                <div class="ranger-avatar"><?= strtoupper(substr($r['full_name'], 0, 1)) ?></div>
                                <div>
                                    <div class="ranger-name"><?= htmlspecialchars($r['full_name']) ?></div>
                                    <div class="ranger-badge">Badge #<?= htmlspecialchars($r['badge_number'] ?? 'N/A') ?></div>
                                </div>
                                <span class="ranger-status-pill <?= $r['is_on_duty'] ? 'on' : 'off' ?>">
                                    <?= $r['is_on_duty'] ? '🟢 On Duty' : '⚪ Off Duty' ?>
                                </span>
                            </div>

                            <?php if ($r['current_lat'] && $r['current_lng']): ?>
                                <div class="ranger-detail">📍 <?= number_format($r['current_lat'], 4) ?>, <?= number_format($r['current_lng'], 4) ?></div>
                                <div class="ranger-detail" style="color:#6c757d;">Last update: <?= timeAgo($r['location_updated']) ?></div>
                            <?php else: ?>
                                <div class="ranger-detail" style="color:#adb5bd;">No location available</div>
                            <?php endif; ?>

                            <div class="ranger-detail">📊 <?= (int)$r['gps_points_24h'] ?> GPS points (24h)</div>

                            <div style="display:flex;gap:6px;margin-top:10px;">
                                <button class="btn btn-primary btn-sm" onclick="openAssignModal(<?= $r['id'] ?>, '<?= htmlspecialchars($r['full_name']) ?>')">
                                    ➕ Assign Route
                                </button>
                                <a href="map.php?zone_id=<?= $activeZoneId ?>&focus_ranger=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">
                                    📍 View on Map
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="icon">👤</span>
                        <h3>No rangers in your zone</h3>
                        <p>Assign rangers to your zone to start tracking patrol routes.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Assigned Routes -->
            <div class="section">
                <div class="section-header">
                    <h2>🛤️ Assigned Patrol Routes</h2>
                    <span style="font-size:12px;color:#6c757d;"><?= $totalRoutes ?> routes</span>
                </div>

                <?php if (count($routes) > 0): ?>
                    <?php foreach ($routes as $r): ?>
                    <div class="route-item">
                        <div class="route-icon">🛤️</div>
                        <div style="flex:1;">
                            <div style="font-weight:600;font-size:13px;color:#0d3b22;"><?= htmlspecialchars($r['route_name']) ?></div>
                            <div style="font-size:11px;color:#6c757d;">
                                👤 <?= htmlspecialchars($r['ranger_name']) ?> (Badge #<?= htmlspecialchars($r['badge_number'] ?? 'N/A') ?>)
                                • 🕐 <?= timeAgo($r['created_at']) ?>
                            </div>
                        </div>
                        <a href="map.php?zone_id=<?= $activeZoneId ?>" class="btn btn-secondary btn-sm">View</a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="icon">🛤️</span>
                        <h3>No routes assigned yet</h3>
                        <p>Assign a route to a ranger to start tracking coverage.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- ASSIGN MODAL -->
<div class="modal-backdrop" id="assignModal">
    <div class="modal">
        <form method="POST">
            <div class="modal-header">
                <h3>🛤️ Assign Patrol Route</h3>
                <button type="button" class="modal-close" onclick="closeAssignModal()">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="assign_route">
                <input type="hidden" name="ranger_id" id="assignRangerId">

                <div class="form-group">
                    <label>Ranger</label>
                    <input type="text" id="assignRangerName" disabled style="background:#f0f0f0;">
                </div>

                <div class="form-group">
                    <label>Route Name *</label>
                    <input type="text" name="route_name" required placeholder="e.g. North Boundary Patrol">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Start Latitude *</label>
                        <input type="number" step="0.000001" name="start_lat" required placeholder="-13.000000">
                    </div>
                    <div class="form-group">
                        <label>Start Longitude *</label>
                        <input type="number" step="0.000001" name="start_lng" required placeholder="31.500000">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>End Latitude</label>
                        <input type="number" step="0.000001" name="end_lat" placeholder="-13.100000">
                    </div>
                    <div class="form-group">
                        <label>End Longitude</label>
                        <input type="number" step="0.000001" name="end_lng" placeholder="31.600000">
                    </div>
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Any special instructions for this patrol…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Route</button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/app.js"></script>
<script src="../assets/js/transitions.js"></script>
<script>
    function openAssignModal(rangerId, rangerName) {
        document.getElementById('assignRangerId').value = rangerId;
        document.getElementById('assignRangerName').value = rangerName;
        document.getElementById('assignModal').classList.add('show');
    }
    function closeAssignModal() {
        document.getElementById('assignModal').classList.remove('show');
    }
</script>
</body>
</html>
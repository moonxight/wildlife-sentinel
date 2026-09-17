<?php
// ============================================================
// supervisor/scouts.php
// Zone Supervisor — Community Scout Management
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

// ============================================================
// SAFE HELPERS
// ============================================================
function safeCount(PDO $pdo, string $sql, array $params = []): int {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return (int)($stmt->fetch()['count'] ?? 0);
    } catch (PDOException $e) { return 0; }
}
function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) { return []; }
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ---- CREATE SCOUT ----
    if ($action === 'create') {
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$fullName || !$password) {
            $message = 'Name, email, and password are required.';
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email address.';
            $messageType = 'danger';
        } else {
            $result = createUser([
                'email'     => $email,
                'phone'     => $phone,
                'password'  => $password,
                'full_name' => $fullName,
                'role'      => 'scout',
                'zone_id'   => $activeZoneId,
            ], $user['id']);

            if ($result['success']) {
                logAudit($user['id'], 'create_user', ['target' => $result['user_id'], 'role' => 'scout']);
                $message = "Scout '{$fullName}' created successfully.";
            } else {
                $message = $result['error'];
                $messageType = 'danger';
            }
        }
    }

    // ---- TOGGLE ACTIVE ----
    if ($action === 'toggle') {
        $scoutId = (int)$_POST['scout_id'];
        // Verify scout belongs to this zone
        $scout = safeFetchAll($pdo, "SELECT id, is_active FROM users WHERE id = ? AND zone_id = ? AND role = 'scout'", [$scoutId, $activeZoneId]);
        if ($scout) {
            $newState = $scout[0]['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newState, $scoutId]);
            logAudit($user['id'], 'update_user', ['target' => $scoutId, 'is_active' => $newState]);
            $message = $newState ? 'Scout activated.' : 'Scout deactivated.';
        } else {
            $message = 'Scout not found in your zone.';
            $messageType = 'danger';
        }
    }

    // ---- DELETE ----
    if ($action === 'delete') {
        $scoutId = (int)$_POST['scout_id'];
        $scout = safeFetchAll($pdo, "SELECT id FROM users WHERE id = ? AND zone_id = ? AND role = 'scout'", [$scoutId, $activeZoneId]);
        if ($scout) {
            $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$scoutId]);
            logAudit($user['id'], 'delete_user', ['target' => $scoutId]);
            $message = 'Scout removed (soft-deleted).';
        } else {
            $message = 'Scout not found in your zone.';
            $messageType = 'danger';
        }
    }
}

// ============================================================
// FETCH SCOUTS (live tracking joined)
// ============================================================
$scouts = safeFetchAll($pdo, "
    SELECT u.*, 
           slt.current_lat, slt.current_lng, slt.last_update AS location_updated,
           slt.is_offline,
           (SELECT COUNT(*) FROM incidents i WHERE i.reporter_id = u.id) AS total_reports,
           (SELECT COUNT(*) FROM incidents i WHERE i.reporter_id = u.id AND i.zone_id = ?) AS zone_reports
    FROM users u
    LEFT JOIN scout_live_tracking slt ON u.id = slt.scout_id
    WHERE u.role = 'scout' AND u.zone_id = ?
    ORDER BY u.is_active DESC, u.is_online DESC, u.full_name
", [$activeZoneId, $activeZoneId]);

// ============================================================
// STATS
// ============================================================
$totalScouts    = count($scouts);
$activeScouts   = count(array_filter($scouts, fn($s) => $s['is_active']));
$onlineScouts   = count(array_filter($scouts, fn($s) => $s['is_online']));
$reportsToday   = safeCount($pdo, 'SELECT COUNT(*) as count FROM incidents WHERE zone_id = ? AND DATE(reported_at) = CURRENT_DATE', [$activeZoneId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Community Scouts - Supervisor</title>
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
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.teal   { background: #d1ecf1; color: #0c5460; }
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
        .btn-danger:hover { background: #c62828; }
        .btn-success { background: #28a745; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        .users-table { width: 100%; border-collapse: collapse; }
        .users-table thead th {
            text-align: left; font-size: 11px; color: #6c757d;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 10px 12px; border-bottom: 2px solid #f0f0f0;
            background: #fafafa; font-weight: 700;
        }
        .users-table tbody tr { border-bottom: 1px solid #f5f5f5; transition: background 0.15s; }
        .users-table tbody tr:hover { background: #fafafa; }
        .users-table td { padding: 12px; font-size: 13px; vertical-align: middle; }

        .user-cell { display: flex; align-items: center; gap: 10px; }
        .user-avatar-sm { width: 36px; height: 36px; border-radius: 50%; background: #1a5c3a; color: white; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; flex-shrink: 0; }
        .user-name { font-weight: 600; color: #0d3b22; }
        .user-email { font-size: 11px; color: #6c757d; }

        .status-pill { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .status-pill.online   { background: #d4edda; color: #155724; }
        .status-pill.offline  { background: #e9ecef; color: #495057; }
        .status-pill.inactive { background: #f8d7da; color: #721c24; }
        .status-pill.onduty   { background: #fff3cd; color: #856404; }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }

        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-backdrop.show { display: flex; }
        .modal { background: white; border-radius: 14px; max-width: 520px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-header { padding: 18px 22px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 18px; color: #0d3b22; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6c757d; }
        .modal-body { padding: 22px; }
        .modal-footer { padding: 16px 22px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 10px; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 12px; color: #495057; font-weight: 600; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 8px;
            font-size: 13px; background: #fafafa; transition: all 0.2s;
        }
        .form-group input:focus { outline: none; border-color: #1a5c3a; background: white; }

        @media (max-width: 1024px) {
            .users-table thead { display: none; }
            .users-table, .users-table tbody, .users-table tr, .users-table td { display: block; width: 100%; }
            .users-table tr { margin-bottom: 12px; padding: 12px; border-radius: 10px; background: #fafafa; border: 1px solid #f0f0f0; }
            .users-table td { padding: 4px 0; border: none; }
            .users-table td::before { content: attr(data-label); font-size: 10px; text-transform: uppercase; color: #adb5bd; display: block; margin-bottom: 2px; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
<div class="app-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
            <h1>Community Scouts</h1>
            <div class="header-right">
                <span class="online-status">● Online</span>
                <span class="data-honesty-badge">🟢 Live Data</span>
                <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
            </div>
        </header>

        <div class="content">
            <div class="dashboard-greeting">
                <h1>👥 Community Scouts</h1>
                <p>Manage scouts in <strong><?= htmlspecialchars(getZoneName($activeZoneId)) ?></strong>.</p>
            </div>

            <?php if ($message): ?>
                <div class="alert <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card"><div class="icon blue">👥</div><div class="info"><div class="number"><?= $totalScouts ?></div><div class="label">Total Scouts</div></div></div>
                <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= $activeScouts ?></div><div class="label">Active</div></div></div>
                <div class="stat-card"><div class="icon teal">🟢</div><div class="info"><div class="number"><?= $onlineScouts ?></div><div class="label">Online Now</div></div></div>
                <div class="stat-card"><div class="icon orange">📋</div><div class="info"><div class="number"><?= $reportsToday ?></div><div class="label">Reports Today</div></div></div>
            </div>

            <div class="section">
                <div class="section-header">
                    <h2>👥 Scout List</h2>
                    <button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('show')">
                        <i class="fas fa-plus"></i> Add Scout
                    </button>
                </div>

                <?php if (count($scouts) > 0): ?>
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Scout</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>Reports</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scouts as $s): ?>
                            <tr>
                                <td data-label="Scout">
                                    <div class="user-cell">
                                        <div class="user-avatar-sm"><?= strtoupper(substr($s['full_name'], 0, 1)) ?></div>
                                        <div>
                                            <div class="user-name"><?= htmlspecialchars($s['full_name']) ?></div>
                                            <div class="user-email"><?= htmlspecialchars($s['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Contact">
                                    <div style="font-size:12px;">📞 <?= htmlspecialchars($s['phone'] ?? 'N/A') ?></div>
                                    <div style="font-size:11px;color:#6c757d;"><?= timeAgo($s['last_seen'] ?? null) ?></div>
                                </td>
                                <td data-label="Status">
                                    <?php if (!$s['is_active']): ?>
                                        <span class="status-pill inactive">Inactive</span>
                                    <?php elseif ($s['is_online']): ?>
                                        <span class="status-pill online">🟢 Online</span>
                                    <?php else: ?>
                                        <span class="status-pill offline">⚪ Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Location">
                                    <?php if ($s['current_lat'] && $s['current_lng']): ?>
                                        <div style="font-size:12px;">📍 <?= number_format($s['current_lat'], 4) ?>, <?= number_format($s['current_lng'], 4) ?></div>
                                        <div style="font-size:11px;color:#6c757d;"><?= timeAgo($s['location_updated']) ?></div>
                                    <?php else: ?>
                                        <span style="color:#adb5bd;font-size:12px;">No location</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Reports">
                                    <div style="font-weight:700;color:#0d3b22;"><?= (int)$s['zone_reports'] ?></div>
                                    <div style="font-size:11px;color:#6c757d;"><?= (int)$s['total_reports'] ?> total</div>
                                </td>
                                <td data-label="Actions">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="scout_id" value="<?= $s['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-secondary' : 'btn-success' ?>">
                                            <?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $s['id'] ?>, '<?= htmlspecialchars($s['full_name']) ?>')">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="icon">👥</span>
                        <h3>No scouts yet</h3>
                        <p>Add your first community scout to start receiving reports.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<!-- CREATE MODAL -->
<div class="modal-backdrop" id="createModal">
    <div class="modal">
        <form method="POST">
            <div class="modal-header">
                <h3>➕ Add New Scout</h3>
                <button type="button" class="modal-close" onclick="document.getElementById('createModal').classList.remove('show')">×</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required placeholder="e.g. John Banda">
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required placeholder="scout@example.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" placeholder="+260 97 1234567">
                </div>
                <div class="form-group">
                    <label>Temporary Password *</label>
                    <input type="text" name="password" required placeholder="Min 8 characters" minlength="8">
                </div>
                <p style="font-size:12px;color:#6c757d;">Scout will be assigned to your zone automatically.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('createModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Scout</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE FORM -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="scout_id" id="deleteScoutId">
</form>

<script src="../assets/js/app.js"></script>
<script src="../assets/js/transitions.js"></script>
<script>
    function confirmDelete(id, name) {
        if (confirm('Remove scout "' + name + '"? They will be deactivated.')) {
            document.getElementById('deleteScoutId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
</script>
</body>
</html>
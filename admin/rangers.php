<?php
require_once '../includes/functions.php';
requireSupervisor();

$user = getCurrentUser();
$pdo = getDB();

// Get rangers with availability
$sql = "SELECT u.id, u.full_name, u.email, u.phone, u.zone_id, u.is_active, z.name as zone_name, ra.is_available, ra.current_incident_id, ra.last_status_update, rlt.current_lat, rlt.current_lng, rlt.last_update as last_location_update FROM users u LEFT JOIN zones z ON u.zone_id = z.id LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id WHERE u.role = 'ranger'";
if ($user['role'] === 'zone_supervisor') {
    $sql .= " AND u.zone_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user['zone_id']]);
} else {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}
$rangers = $stmt->fetchAll();

// Toggle ranger availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_availability') {
    $rangerId = $_POST['ranger_id'];
    $status = isset($_POST['is_available']) ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO ranger_availability (ranger_id, is_available, last_status_update) VALUES (?, ?, NOW())  ON CONFLICT (ranger_id) DO UPDATE SET  is_available = ?, last_status_update = NOW()');
    $stmt->execute([$rangerId, $status, $status]);
    logAudit($user['id'], 'toggle_ranger_availability', ['ranger_id' => $rangerId, 'status' => $status]);
    header('Location: rangers.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rangers - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ranger-card { background: white; border: 1px solid var(--gray-200); border-radius: 8px; padding: 20px; display: flex; align-items: center; gap: 20px; margin-bottom: 15px; transition: box-shadow 0.2s; }
        .ranger-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .ranger-avatar { width: 60px; height: 60px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
        .ranger-info { flex: 1; }
        .ranger-info h3 { margin: 0; font-size: 18px; }
        .ranger-info .ranger-role { font-size: 13px; color: var(--gray-600); }
        .ranger-status { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 5px; }
        .ranger-status .status-badge { font-size: 12px; padding: 2px 10px; border-radius: 12px; }
        .status-badge.available { background: #d4edda; color: #155724; }
        .status-badge.unavailable { background: #f8d7da; color: #721c24; }
        .status-badge.on-duty { background: #cce5ff; color: #004085; }
        .ranger-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        @media (max-width: 768px) { .ranger-card { flex-wrap: wrap; } .ranger-avatar { width: 50px; height: 50px; font-size: 20px; } }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Rangers</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="user-name"><?= $user['full_name'] ?></span>
                </div>
            </header>
            
            <div class="content">
                <div class="section">
                    <h2>All Rangers</h2>
                    <p style="color:var(--gray-600);margin-bottom:20px;">Manage ranger availability and view their current status.</p>
                    
                    <?php if (count($rangers) > 0): ?>
                        <?php foreach ($rangers as $ranger): ?>
                        <div class="ranger-card">
                            <div class="ranger-avatar"><?= substr($ranger['full_name'], 0, 1) ?></div>
                            <div class="ranger-info">
                                <h3><?= $ranger['full_name'] ?></h3>
                                <div class="ranger-role"><?= $ranger['email'] ?> • <?= $ranger['phone'] ?? 'No phone' ?> • <?= $ranger['zone_name'] ?? 'No zone assigned' ?></div>
                                <div class="ranger-status">
                                    <span class="status-badge <?= $ranger['is_active'] ? 'available' : 'unavailable' ?>"><?= $ranger['is_active'] ? '🟢 Active' : '🔴 Inactive' ?></span>
                                    <span class="status-badge <?= $ranger['is_available'] ? 'available' : 'unavailable' ?>"><?= $ranger['is_available'] ? '✅ Available' : '❌ Unavailable' ?></span>
                                    <?php if ($ranger['current_incident_id']): ?><span class="status-badge on-duty">📍 On incident #<?= $ranger['current_incident_id'] ?></span><?php endif; ?>
                                    <?php if ($ranger['current_lat'] && $ranger['current_lng']): ?><span class="status-badge" style="background:#e2e3e5;color:#383d41;">📡 Live: <?= round($ranger['current_lat'], 4) ?>, <?= round($ranger['current_lng'], 4) ?></span><?php endif; ?>
                                </div>
                                <?php if ($ranger['last_location_update']): ?><div style="font-size:12px;color:var(--gray-500);margin-top:5px;">Last update: <?= timeAgo($ranger['last_location_update']) ?></div><?php endif; ?>
                            </div>
                            <div class="ranger-actions">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_availability">
                                    <input type="hidden" name="ranger_id" value="<?= $ranger['id'] ?>">
                                    <input type="hidden" name="is_available" value="<?= $ranger['is_available'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn-small <?= $ranger['is_available'] ? 'btn-danger' : 'btn-success' ?>"><?= $ranger['is_available'] ? 'Mark Unavailable' : 'Mark Available' ?></button>
                                </form>
                                <a href="edit-user.php?id=<?= $ranger['id'] ?>" class="btn-small btn-primary">Edit</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><p>No rangers found in your zone.</p><a href="users.php" class="btn btn-primary" style="margin-top:15px;">Add a Ranger</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    <script src="../assets/js/app.js"></script>
</body>
</html>
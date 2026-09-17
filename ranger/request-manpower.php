<?php
// ============================================================
// ranger/request-manpower.php
// Ranger — Request Manpower / Backup
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
        } catch (PDOException $e) { return []; }
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
// HANDLE FORM SUBMIT
// ============================================================
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_request') {
    $incidentId     = (int)($_POST['incident_id'] ?? 0);
    $urgency        = $_POST['urgency'] ?? 'medium';
    $requiredCount  = max(1, min(20, (int)($_POST['required_count'] ?? 2)));
    $equipment      = trim($_POST['equipment'] ?? 'Standard');
    $description    = trim($_POST['description'] ?? '');

    if (!$description) {
        $message = 'Please describe the situation.';
        $messageType = 'danger';
    } elseif ($incidentId <= 0) {
        $message = 'Please select an incident.';
        $messageType = 'danger';
    } else {
        try {
            if (function_exists('sendManpowerRequest')) {
                $result = sendManpowerRequest(
                    $user['id'],
                    $incidentId,
                    $urgency,
                    $description,
                    $requiredCount,
                    $equipment
                );
                if ($result['success'] ?? false) {
                    logAudit($user['id'], 'manpower_request', [
                        'incident_id' => $incidentId,
                        'urgency' => $urgency,
                    ]);
                    $message = "✅ Manpower request sent to all available rangers and supervisors.";
                } else {
                    $message = 'Failed: ' . ($result['error'] ?? 'Unknown error');
                    $messageType = 'danger';
                }
            } else {
                // Fallback: insert message manually
                $incident = getIncident($incidentId);
                $subject = "🚨 MANPOWER REQUEST: " . strtoupper($urgency) . " - Incident #{$incidentId}";
                $content = "Incident: {$incident['category']} - {$incident['description']}\n"
                         . "Location: {$incident['location_lat']}, {$incident['location_lng']}\n"
                         . "Severity: {$incident['severity']}\n"
                         . "Requesting Ranger: {$user['full_name']}\n\n"
                         . "Details: {$description}\n"
                         . "Required Personnel: {$requiredCount}\n"
                         . "Equipment Needed: {$equipment}";

                $stmt = $pdo->prepare("
                    INSERT INTO messages
                        (sender_id, recipient_id, incident_id, message_type, subject, content, severity, is_broadcast, requires_acknowledgment, created_at)
                    VALUES (?, NULL, ?, 'manpower_request', ?, ?, ?, 1, 1, NOW())
                ");
                $stmt->execute([
                    $user['id'],
                    $incidentId,
                    $subject,
                    $content,
                    $urgency === 'critical' ? 'critical' : 'high',
                ]);
                $mid = $pdo->query('SELECT lastval()')->fetchColumn();

                // Notify supervisors
                $recipients = safeFetchAll($pdo, "
                    SELECT id FROM users
                    WHERE zone_id = ? AND role IN ('zone_supervisor','admin') AND is_active = 1
                ", [$zoneId]);
                foreach ($recipients as $r) {
                    createNotification($r['id'], 'manpower_request', $subject, $content, $incidentId, $mid);
                }

                logAudit($user['id'], 'manpower_request', ['incident_id' => $incidentId, 'urgency' => $urgency]);
                $message = "✅ Manpower request sent (fallback mode).";
            }
        } catch (Throwable $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ============================================================
// FETCH MY ACTIVE INCIDENTS (for the dropdown)
// ============================================================
$myIncidents = safeFetchAll($pdo, '
    SELECT id, category, severity, status, location_lat, location_lng
    FROM incidents
    WHERE acknowledged_by = ?
      AND status IN (\'acknowledged\',\'in_progress\')
    ORDER BY CASE severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, reported_at DESC
', [$user['id']]);

// ============================================================
// RECENT MANPOWER REQUESTS I'VE SENT
// ============================================================
$myRequests = safeFetchAll($pdo, "
    SELECT m.*, i.category AS incident_category, i.severity AS incident_severity
    FROM messages m
    LEFT JOIN incidents i ON m.incident_id = i.id
    WHERE m.sender_id = ?
      AND m.message_type = 'manpower_request'
    ORDER BY m.created_at DESC
    LIMIT 10
", [$user['id']]);

// ============================================================
// AVAILABLE RANGERS IN ZONE
// ============================================================
$availableRangers = safeFetchAll($pdo, "
    SELECT u.id, u.full_name, u.badge_number, u.is_on_duty
    FROM users u
    LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
    WHERE u.zone_id = ? AND u.role = 'ranger' AND u.is_active = 1 AND u.id != ?
    ORDER BY u.is_on_duty DESC, u.full_name
", [$zoneId, $user['id']]);

// ============================================================
// STATS
// ============================================================
$stats = [
    'my_active_incidents'  => count($myIncidents),
    'my_requests'          => safeCount($pdo, "SELECT COUNT(*) as count FROM messages WHERE sender_id = ? AND message_type = 'manpower_request'", [$user['id']]),
    'rangers_on_duty'      => safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'ranger' AND is_on_duty = 1 AND is_active = 1", [$zoneId]),
    'total_rangers'        => safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ? AND role = 'ranger' AND is_active = 1", [$zoneId]),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Request Manpower - Ranger - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 22px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p { color: #6c757d; font-size: 15px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
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
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        /* Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: 12px; font-weight: 600; color: #495057; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-group .hint { font-size: 11px; color: #6c757d; font-weight: normal; text-transform: none; letter-spacing: 0; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 13px;
            background: #fafafa;
            font-family: inherit;
            transition: all 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #1a5c3a; background: white; }

        /* Urgency buttons */
        .urgency-options { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .urgency-btn {
            padding: 14px 12px;
            border-radius: 10px;
            border: 2px solid #e0e0e0;
            background: #fafafa;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            font-weight: 600;
        }
        .urgency-btn:hover { border-color: #1a5c3a; background: white; }
        .urgency-btn.selected { border-color: #1a5c3a; background: #e8f5e9; }
        .urgency-btn input { display: none; }
        .urgency-btn .icon { font-size: 22px; display: block; margin-bottom: 4px; }
        .urgency-btn .label { font-size: 12px; color: #495057; }
        .urgency-btn.selected .label { color: #1a5c3a; }
        .urgency-btn.critical.selected { border-color: #dc3545; background: #fdf5f5; }
        .urgency-btn.critical.selected .label { color: #dc3545; }
        .urgency-btn.high.selected { border-color: #fd7e14; background: #fff8f2; }
        .urgency-btn.high.selected .label { color: #fd7e14; }

        /* Warning banner */
        .warning-banner {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .warning-banner strong { display: block; margin-bottom: 4px; }

        /* Recent requests */
        .request-item {
            padding: 12px 14px;
            border-radius: 10px;
            background: #fafafa;
            border-left: 4px solid #6f42c1;
            margin-bottom: 10px;
            font-size: 13px;
        }
        .request-item.critical { border-left-color: #dc3545; background: #fdf5f5; }
        .request-item.high     { border-left-color: #fd7e14; }
        .request-item .title { font-weight: 600; color: #0d3b22; }
        .request-item .meta  { font-size: 11px; color: #6c757d; margin-top: 4px; }

        /* Ranger list */
        .ranger-mini {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; border-radius: 8px;
            background: #fafafa; margin-bottom: 6px;
            font-size: 13px;
        }
        .ranger-mini .avatar {
            width: 32px; height: 32px; border-radius: 50%;
            background: #2E7D32; color: white;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 12px; flex-shrink: 0;
        }
        .ranger-mini.off .avatar { background: #9E9E9E; }
        .ranger-mini .name { font-weight: 600; font-size: 13px; color: #0d3b22; }
        .ranger-mini .status { font-size: 11px; color: #6c757d; }

        .empty-state { text-align: center; padding: 30px 20px; color: #6c757d; font-size: 13px; }
        .empty-state .icon { font-size: 40px; display: block; margin-bottom: 8px; opacity: 0.4; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .form-grid { grid-template-columns: 1fr; }
            .urgency-options { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Request Manpower</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🆘 Request Backup</h1>
                    <p>Send a manpower request to supervisors and other rangers in your zone.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon red">🚨</div><div class="info"><div class="number"><?= $stats['my_active_incidents'] ?></div><div class="label">My Active Incidents</div></div></div>
                    <div class="stat-card"><div class="icon purple">🆘</div><div class="info"><div class="number"><?= $stats['my_requests'] ?></div><div class="label">My Requests</div></div></div>
                    <div class="stat-card"><div class="icon green">🛡️</div><div class="info"><div class="number"><?= $stats['rangers_on_duty'] ?>/<?= $stats['total_rangers'] ?></div><div class="label">Rangers On Duty</div></div></div>
                </div>

                <!-- Warning -->
                <div class="warning-banner">
                    <strong>⚠️ Use Responsibly</strong>
                    Manpower requests notify supervisors and all available rangers in your zone. Only use for genuine backup needs. Include clear details to help responders prepare.
                </div>

                <!-- Two-column layout -->
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;align-items:start;">
                    <!-- LEFT: FORM -->
                    <div class="section">
                        <div class="section-header">
                            <h2>📝 New Request</h2>
                        </div>

                        <?php if (count($myIncidents) === 0): ?>
                            <div class="warning-banner" style="margin-bottom:0;">
                                <strong>⚠️ No active incidents</strong>
                                You have no acknowledged incidents to attach a manpower request to. Acknowledge an incident first from the <a href="incidents.php">Incidents page</a>.
                            </div>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="action" value="send_request">

                                <div class="form-group">
                                    <label>Incident *</label>
                                    <select name="incident_id" required>
                                        <option value="">— Select an incident —</option>
                                        <?php foreach ($myIncidents as $inc): ?>
                                            <option value="<?= (int)$inc['id'] ?>">
                                                #<?= (int)$inc['id'] ?> — <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $inc['category']))) ?>
                                                (<?= htmlspecialchars(strtoupper($inc['severity'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Urgency Level *</label>
                                    <div class="urgency-options">
                                        <label class="urgency-btn medium selected">
                                            <input type="radio" name="urgency" value="medium" checked>
                                            <span class="icon">⚠️</span>
                                            <span class="label">Medium</span>
                                        </label>
                                        <label class="urgency-btn high">
                                            <input type="radio" name="urgency" value="high">
                                            <span class="icon">🚨</span>
                                            <span class="label">High</span>
                                        </label>
                                        <label class="urgency-btn critical">
                                            <input type="radio" name="urgency" value="critical">
                                            <span class="icon">🔥</span>
                                            <span class="label">Critical</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Number of Rangers Needed</label>
                                        <input type="number" name="required_count" value="2" min="1" max="20">
                                    </div>
                                    <div class="form-group">
                                        <label>Equipment Needed</label>
                                        <input type="text" name="equipment" placeholder="e.g. Vehicle, rifles, first-aid" value="Standard">
                                    </div>
                                </div>

                                <div class="form-group full">
                                    <label>Describe the Situation *</label>
                                    <textarea name="description" rows="5" required placeholder="What is happening? What support do you need? Any specific skills or equipment required?"></textarea>
                                    <span class="hint">Be specific — this goes out to all supervisors and available rangers.</span>
                                </div>

                                <div style="display:flex;gap:10px;justify-content:flex-end;">
                                    <button type="submit" class="btn btn-primary" style="padding:12px 24px;">
                                        <i class="fas fa-paper-plane"></i> Send Manpower Request
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- RIGHT: INFO -->
                    <div>
                        <!-- My Recent Requests -->
                        <div class="section">
                            <div class="section-header">
                                <h2>📨 My Recent Requests</h2>
                            </div>
                            <?php if (count($myRequests) > 0): ?>
                                <?php foreach (array_slice($myRequests, 0, 5) as $req): ?>
                                    <?php
                                    $sev = $req['severity'] ?? 'medium';
                                    $cls = in_array($sev, ['critical', 'high']) ? $sev : '';
                                    ?>
                                    <div class="request-item <?= $cls ?>">
                                        <div class="title">
                                            🆘 <?= htmlspecialchars(strtoupper($sev)) ?> — Incident #<?= (int)$req['incident_id'] ?>
                                        </div>
                                        <div class="meta">
                                            <?= htmlspecialchars(substr($req['content'] ?? '', 0, 100)) ?>
                                            <br>🕐 <?= timeAgo($req['created_at']) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <span class="icon">📭</span>
                                    No requests sent yet.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Available Rangers -->
                        <div class="section">
                            <div class="section-header">
                                <h2>🛡️ Other Rangers in Zone</h2>
                            </div>
                            <?php if (count($availableRangers) > 0): ?>
                                <?php foreach (array_slice($availableRangers, 0, 6) as $r): ?>
                                    <div class="ranger-mini <?= (int)$r['is_on_duty'] === 1 ? '' : 'off' ?>">
                                        <div class="avatar"><?= strtoupper(substr($r['full_name'], 0, 1)) ?></div>
                                        <div style="flex:1;min-width:0;">
                                            <div class="name"><?= htmlspecialchars($r['full_name']) ?></div>
                                            <div class="status">
                                                <?= (int)$r['is_on_duty'] === 1 ? '🟢 On Duty' : '⚪ Off Duty' ?>
                                                <?= $r['badge_number'] ? ' • Badge #' . htmlspecialchars($r['badge_number']) : '' ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <span class="icon">👤</span>
                                    No other rangers in your zone.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // Urgency button select
        document.querySelectorAll('.urgency-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.urgency-btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                btn.querySelector('input').checked = true;
            });
        });
    </script>
</body>
</html>
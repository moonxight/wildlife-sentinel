<?php
require_once '../includes/functions.php';
requireLogin();

if (!hasRole('zone_supervisor')) {
    header('Location: ../index.php');
    exit();
}

$user = getCurrentUser();
$pdo = getDB();

// Get rangers in zone with detailed information
$stmt = $pdo->prepare("
    SELECT 
        u.*,
        z.name as zone_name,
        ra.is_available,
        ra.current_incident_id,
        ra.last_status_update as availability_last_update,
        ra.shift_start,
        ra.shift_end,
        ra.days_available,
        rlt.current_lat,
        rlt.current_lng,
        rlt.last_update as last_location_update,
        rlt.is_offline,
        i.category as current_incident_category,
        i.severity as current_incident_severity,
        i.status as current_incident_status,
        i.location_lat as incident_lat,
        i.location_lng as incident_lng
    FROM users u
    LEFT JOIN zones z ON u.zone_id = z.id
    LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
    LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
    LEFT JOIN incidents i ON ra.current_incident_id = i.id
    WHERE u.zone_id = ? AND u.role = 'ranger'
    ORDER BY 
        ra.is_available DESC,
        CASE WHEN ra.current_incident_id IS NOT NULL THEN 1 ELSE 0 END DESC,
        u.full_name
");
$stmt->execute([$user['zone_id']]);
$rangers = $stmt->fetchAll();

// Get zone name
$zoneName = getZoneName($user['zone_id']);

// Get statistics
$totalRangers = count($rangers);
$availableRangers = array_filter($rangers, function($r) { return $r['is_available'] == 1; });
$availableCount = count($availableRangers);
$onDutyCount = array_filter($rangers, function($r) { return $r['current_incident_id'] !== null; });
$onDutyCount = count($onDutyCount);
$offlineCount = array_filter($rangers, function($r) { return $r['is_offline'] == 1; });
$offlineCount = count($offlineCount);

// Handle toggle availability
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'toggle_availability') {
        $rangerId = $_POST['ranger_id'];
        $status = isset($_POST['is_available']) ? 1 : 0;
        
        $stmt = $pdo->prepare('
            INSERT INTO ranger_availability (ranger_id, is_available, last_status_update) 
            VALUES (?, ?, NOW()) 
             ON CONFLICT (ranger_id) DO UPDATE SET  is_available = ?, last_status_update = NOW()
        ');
        $stmt->execute([$rangerId, $status, $status]);
        
        logAudit($user['id'], 'toggle_ranger_availability', ['ranger_id' => $rangerId, 'status' => $status]);
        header('Location: rangers.php');
        exit();
    }
    
    if ($action === 'message_ranger') {
        $rangerId = $_POST['ranger_id'];
        $message = sanitize($_POST['message']);
        
        if (!empty($message)) {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, message_type, content, created_at)
                VALUES (?, ?, 'general', ?, NOW())
            ");
            $stmt->execute([$user['id'], $rangerId, $message]);
            
            createNotification(
                $rangerId,
                'new_message',
                '💬 New Message from Supervisor',
                "Message from {$user['full_name']}: " . substr($message, 0, 50)
            );
            
            $success = 'Message sent successfully';
        }
    }
}

// Get current incident details for on-duty rangers
$onDutyRangers = array_filter($rangers, function($r) { return $r['current_incident_id'] !== null; });
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Rangers - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        /* ============================================
           RANGERS PAGE STYLES
           ============================================ */

        /* Stats Row */
        .stats-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .stat-chip {
            background: white;
            padding: 8px 16px;
            border-radius: 20px;
            border: 1px solid var(--gray-300);
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stat-chip .count {
            font-weight: 700;
            color: var(--primary);
        }

        .stat-chip .count.green { color: #28a745; }
        .stat-chip .count.red { color: #dc3545; }
        .stat-chip .count.orange { color: #ffc107; }
        .stat-chip .count.blue { color: #17a2b8; }

        /* Ranger Card */
        .ranger-card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 12px;
            transition: all 0.3s;
        }

        .ranger-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-color: #1a5c3a;
        }

        .ranger-card .ranger-header {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .ranger-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1a5c3a, #2d8a4e);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 600;
            flex-shrink: 0;
        }

        .ranger-main-info {
            flex: 1;
            min-width: 0;
        }

        .ranger-main-info .ranger-name {
            font-size: 17px;
            font-weight: 700;
            color: #0d3b22;
            margin: 0;
        }

        .ranger-main-info .ranger-email {
            font-size: 13px;
            color: #6c757d;
        }

        .ranger-main-info .ranger-phone {
            font-size: 13px;
            color: #6c757d;
        }

        .ranger-status-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .status-badge {
            font-size: 11px;
            padding: 2px 12px;
            border-radius: 12px;
            font-weight: 600;
        }

        .status-badge.available { background: #d4edda; color: #155724; }
        .status-badge.unavailable { background: #f8d7da; color: #721c24; }
        .status-badge.on-duty { background: #cce5ff; color: #004085; }
        .status-badge.offline { background: #e2e3e5; color: #383d41; }

        /* Ranger Details */
        .ranger-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e9ecef;
        }

        .ranger-details .detail-item {
            font-size: 13px;
            color: #495057;
        }

        .ranger-details .detail-item strong {
            color: #0d3b22;
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
        }

        .ranger-details .detail-item .value {
            margin-top: 2px;
        }

        .ranger-details .detail-item .value .badge-small {
            font-size: 11px;
            padding: 1px 8px;
            border-radius: 10px;
            font-weight: 600;
        }

        .badge-small.critical { background: #dc3545; color: white; }
        .badge-small.high { background: #fd7e14; color: white; }
        .badge-small.medium { background: #ffc107; color: #333; }
        .badge-small.low { background: #28a745; color: white; }

        /* Ranger Actions */
        .ranger-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e9ecef;
        }

        .ranger-actions .btn-small {
            min-height: 32px;
            font-size: 12px;
            padding: 4px 14px;
        }

        /* Incident Details in Card */
        .incident-details-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 14px;
            margin-top: 8px;
            border-left: 3px solid #ffc107;
        }

        .incident-details-card .incident-title {
            font-weight: 600;
            font-size: 13px;
        }

        .incident-details-card .incident-meta {
            font-size: 12px;
            color: #6c757d;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 2px;
        }

        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .modal-header h3 {
            font-size: 20px;
            color: #0d3b22;
        }

        .modal-header .close {
            font-size: 28px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px 8px;
            color: var(--gray-500);
            transition: all 0.3s;
        }

        .modal-header .close:hover {
            color: var(--danger);
            transform: rotate(90deg);
        }

        .modal-body .form-group {
            margin-bottom: 14px;
        }

        .modal-body .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 13px;
            color: #495057;
        }

        .modal-body .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
        }

        .modal-body .form-control:focus {
            border-color: #1a5c3a;
            outline: none;
            box-shadow: 0 0 0 4px rgba(26, 92, 58, 0.1);
        }

        .modal-body textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }

        .modal-footer .btn {
            flex: 1;
            justify-content: center;
            min-height: 44px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .ranger-details {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .ranger-card {
                padding: 14px 16px;
            }

            .ranger-avatar {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .ranger-main-info .ranger-name {
                font-size: 15px;
            }

            .ranger-actions {
                flex-direction: column;
            }

            .ranger-actions .btn-small {
                width: 100%;
                justify-content: center;
                min-height: 38px;
            }

            .stats-row {
                gap: 8px;
            }

            .stat-chip {
                font-size: 12px;
                padding: 6px 12px;
            }

            .modal-content {
                padding: 20px;
                margin: 10px;
            }
        }

        @media (max-width: 480px) {
            .ranger-card {
                padding: 12px 14px;
            }

            .ranger-main-info .ranger-name {
                font-size: 14px;
            }

            .ranger-main-info .ranger-email,
            .ranger-main-info .ranger-phone {
                font-size: 12px;
            }

            .status-badge {
                font-size: 10px;
                padding: 1px 8px;
            }

            .ranger-details .detail-item {
                font-size: 12px;
            }

            .stat-chip {
                font-size: 10px;
                padding: 4px 10px;
            }

            .modal-content {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>My Rangers</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= $user['full_name'] ?></span>
                </div>
            </header>
            
            <div class="content">
                <!-- Stats -->
                <div class="stats-row">
                    <span class="stat-chip">👤 Total: <span class="count"><?= $totalRangers ?></span></span>
                    <span class="stat-chip">✅ Available: <span class="count green"><?= $availableCount ?></span></span>
                    <span class="stat-chip">📋 On Duty: <span class="count orange"><?= $onDutyCount ?></span></span>
                    <span class="stat-chip">📡 Offline: <span class="count red"><?= $offlineCount ?></span></span>
                    <span class="stat-chip">📍 Zone: <span class="count blue"><?= $zoneName ?></span></span>
                </div>

                <div class="section">
                    <h2>👤 Rangers in <?= $zoneName ?></h2>
                    <p style="color:var(--gray-600);margin-bottom:15px;">
                        View all rangers assigned to your zone with their complete details and current status.
                    </p>
                    
                    <?php if (count($rangers) > 0): ?>
                        <?php foreach ($rangers as $ranger): ?>
                        <div class="ranger-card">
                            <!-- Header -->
                            <div class="ranger-header">
                                <div class="ranger-avatar"><?= substr($ranger['full_name'], 0, 1) ?></div>
                                <div class="ranger-main-info">
                                    <div class="ranger-name"><?= $ranger['full_name'] ?></div>
                                    <div class="ranger-email">📧 <?= $ranger['email'] ?></div>
                                    <div class="ranger-phone">📞 <?= $ranger['phone'] ?? 'No phone number' ?></div>
                                    <div class="ranger-status-badges">
                                        <span class="status-badge <?= $ranger['is_active'] ? 'available' : 'unavailable' ?>">
                                            <?= $ranger['is_active'] ? '🟢 Active' : '🔴 Inactive' ?>
                                        </span>
                                        <span class="status-badge <?= $ranger['is_available'] ? 'available' : ($ranger['current_incident_id'] ? 'on-duty' : 'unavailable') ?>">
                                            <?= $ranger['is_available'] ? '✅ Available' : ($ranger['current_incident_id'] ? '📋 On Duty' : '❌ Unavailable') ?>
                                        </span>
                                        <?php if ($ranger['current_incident_id']): ?>
                                        <span class="status-badge on-duty">📍 Incident #<?= $ranger['current_incident_id'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($ranger['current_lat'] && $ranger['current_lng']): ?>
                                        <span class="status-badge" style="background:#e2e3e5;color:#383d41;">
                                            📡 Live: <?= round($ranger['current_lat'], 4) ?>, <?= round($ranger['current_lng'], 4) ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="status-badge offline">📡 Offline</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Current Incident Details (if on duty) -->
                            <?php if ($ranger['current_incident_id']): ?>
                            <div class="incident-details-card">
                                <div class="incident-title">
                                    🚨 <?= ucfirst(str_replace('_', ' ', $ranger['current_incident_category'] ?? 'Unknown')) ?>
                                    <span class="badge-small <?= $ranger['current_incident_severity'] ?? 'medium' ?>">
                                        <?= strtoupper($ranger['current_incident_severity'] ?? 'UNKNOWN') ?>
                                    </span>
                                </div>
                                <div class="incident-meta">
                                    <span>📍 <?= $ranger['incident_lat'] ? round($ranger['incident_lat'], 6) : 'N/A' ?>, <?= $ranger['incident_lng'] ? round($ranger['incident_lng'], 6) : 'N/A' ?></span>
                                    <span>📊 Status: <?= ucfirst($ranger['current_incident_status'] ?? 'Unknown') ?></span>
                                    <span>🕐 Since: <?= $ranger['availability_last_update'] ? timeAgo($ranger['availability_last_update']) : 'N/A' ?></span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Ranger Details -->
                            <div class="ranger-details">
                                <div class="detail-item">
                                    <strong>📋 Personal Info</strong>
                                    <div class="value">
                                        <div><strong>Full Name:</strong> <?= $ranger['full_name'] ?></div>
                                        <div><strong>Email:</strong> <?= $ranger['email'] ?></div>
                                        <div><strong>Phone:</strong> <?= $ranger['phone'] ?? 'N/A' ?></div>
                                        <div><strong>Zone:</strong> <?= $ranger['zone_name'] ?? 'N/A' ?></div>
                                    </div>
                                </div>
                                <div class="detail-item">
                                    <strong>📊 Status Info</strong>
                                    <div class="value">
                                        <div><strong>Account:</strong> <?= $ranger['is_active'] ? 'Active' : 'Inactive' ?></div>
                                        <div><strong>Availability:</strong> <?= $ranger['is_available'] ? 'Available' : 'Unavailable' ?></div>
                                        <?php if ($ranger['shift_start'] && $ranger['shift_end']): ?>
                                        <div><strong>Shift:</strong> <?= date('H:i', strtotime($ranger['shift_start'])) ?> - <?= date('H:i', strtotime($ranger['shift_end'])) ?></div>
                                        <?php endif; ?>
                                        <div><strong>Last Location Update:</strong> <?= $ranger['last_location_update'] ? timeAgo($ranger['last_location_update']) : 'Never' ?></div>
                                    </div>
                                </div>
                                <?php if ($ranger['days_available']): ?>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <strong>📅 Available Days</strong>
                                    <div class="value">
                                        <?php 
                                        $days = json_decode($ranger['days_available'], true);
                                        if (is_array($days)) {
                                            echo implode(', ', array_map('ucfirst', $days));
                                        } else {
                                            echo 'All days';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($ranger['current_incident_id']): ?>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <strong>🚨 Current Incident Details</strong>
                                    <div class="value">
                                        <div><strong>Incident ID:</strong> #<?= $ranger['current_incident_id'] ?></div>
                                        <div><strong>Category:</strong> <?= ucfirst(str_replace('_', ' ', $ranger['current_incident_category'] ?? 'Unknown')) ?></div>
                                        <div><strong>Severity:</strong> <?= ucfirst($ranger['current_incident_severity'] ?? 'Unknown') ?></div>
                                        <div><strong>Status:</strong> <?= ucfirst($ranger['current_incident_status'] ?? 'Unknown') ?></div>
                                        <div><strong>Location:</strong> <?= $ranger['incident_lat'] ? round($ranger['incident_lat'], 6) . ', ' . round($ranger['incident_lng'], 6) : 'N/A' ?></div>
                                        <div><strong>Assigned Since:</strong> <?= $ranger['availability_last_update'] ? timeAgo($ranger['availability_last_update']) : 'N/A' ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Actions -->
                            <div class="ranger-actions">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_availability">
                                    <input type="hidden" name="ranger_id" value="<?= $ranger['id'] ?>">
                                    <input type="hidden" name="is_available" value="<?= $ranger['is_available'] ? 0 : 1 ?>">
                                    <button type="submit" class="btn-small <?= $ranger['is_available'] ? 'btn-danger' : 'btn-success' ?>">
                                        <?= $ranger['is_available'] ? '❌ Mark Unavailable' : '✅ Mark Available' ?>
                                    </button>
                                </form>
                                <button class="btn-small btn-primary" onclick="openMessageModal(<?= $ranger['id'] ?>, '<?= addslashes($ranger['full_name']) ?>')">
                                    💬 Message
                                </button>
                                <?php if ($ranger['current_lat'] && $ranger['current_lng']): ?>
                                <a href="map.php?ranger=<?= $ranger['id'] ?>" class="btn-small btn-info">📍 View on Map</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No rangers assigned to your zone yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Message Modal -->
    <div id="messageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>💬 Send Message to <span id="messageRangerName"></span></h3>
                <button class="close" onclick="closeModal('messageModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="message_ranger">
                <input type="hidden" name="ranger_id" id="messageRangerId">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Message *</label>
                        <textarea name="message" class="form-control" rows="4" required 
                                  placeholder="Type your message here..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('messageModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Message</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        
        function openMessageModal(rangerId, rangerName) {
            document.getElementById('messageRangerId').value = rangerId;
            document.getElementById('messageRangerName').textContent = rangerName;
            document.getElementById('messageModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
            document.body.style.overflow = '';
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
                document.body.style.overflow = '';
            }
        }
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(function(modal) {
                    modal.classList.remove('show');
                    document.body.style.overflow = '';
                });
            }
        });

        console.log('✅ Supervisor Rangers page loaded');
        console.log('👤 Total Rangers: <?= $totalRangers ?>');
        console.log('✅ Available: <?= $availableCount ?>');
        console.log('📋 On Duty: <?= $onDutyCount ?>');
    </script>
</body>
</html>
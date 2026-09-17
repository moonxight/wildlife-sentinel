<?php
// ============================================================
// supervisor/audit.php
// Zone Supervisor — Audit Log Viewer
// ------------------------------------------------------------
// Features:
//   - View zone-scoped audit logs (users in zone + own actions)
//   - Clear logs (last 24h / last 7d / last 30d / all zone logs)
//   - Filter by action, user, date range, search
//   - Expandable rows with readable details
//   - Export to CSV
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
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- CLEAR LOGS --------
    if ($action === 'clear_logs') {
        $scope = $_POST['scope'] ?? '';

        // Build the WHERE clause — always zone-scoped
        $where  = " WHERE (u.zone_id = ? OR a.user_id = ?) ";
        $params = [$activeZoneId, $user['id']];

        switch ($scope) {
            case '24h':
                $where .= ' AND a.created_at >= (NOW() - (24) * INTERVAL \'1 hour\') ';
                $label = 'last 24 hours';
                break;
            case '7d':
                $where .= ' AND a.created_at >= (NOW() - (7) * INTERVAL \'1 day\') ';
                $label = 'last 7 days';
                break;
            case '30d':
                $where .= ' AND a.created_at >= (NOW() - (30) * INTERVAL \'1 day\') ';
                $label = 'last 30 days';
                break;
            case 'all':
                $label = 'all time';
                break;
            default:
                $where = null;
                $label = '';
        }

        if ($where === null) {
            $message = 'Invalid clear scope.';
            $messageType = 'danger';
        } else {
            try {
                // Get count first for reporting
                $count = safeCount($pdo, "
                    SELECT COUNT(*) as count
                    FROM audit_logs a
                    LEFT JOIN users u ON a.user_id = u.id
                    $where
                ", $params);

                if ($count === 0) {
                    $message = "No logs found to clear for {$label}.";
                    $messageType = 'warning';
                } else {
                    // Delete them
                    $stmt = $pdo->prepare("
                        DELETE FROM audit_logs WHERE id IN (SELECT a.id FROM audit_logs a
                        LEFT JOIN users u ON a.user_id = u.id
                        $where)
                    ");
                    $stmt->execute($params);

                    // Record the clear action itself
                    logAudit(
                        $user['id'],
                        'clear_audit_logs',
                        ['scope' => $scope, 'cleared_count' => $count]
                    );

                    $message = "✅ Cleared {$count} log entries for {$label}.";
                }
            } catch (PDOException $e) {
                $message = 'Clear failed: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// ============================================================
// EXPORT CSV (before any HTML output)
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filterAction = trim($_GET['action_filter'] ?? '');
    $filterUser   = (int)($_GET['user_id'] ?? 0);
    $filterFrom   = trim($_GET['from'] ?? '');
    $filterTo     = trim($_GET['to'] ?? '');
    $search       = trim($_GET['search'] ?? '');

    $where  = " WHERE (u.zone_id = ? OR a.user_id = ?) ";
    $params = [$activeZoneId, $user['id']];

    if ($filterAction !== '') {
        $where .= " AND a.action LIKE ? ";
        $params[] = '%' . $filterAction . '%';
    }
    if ($filterUser > 0) {
        $where .= " AND a.user_id = ? ";
        $params[] = $filterUser;
    }
    if ($filterFrom !== '') {
        $where .= " AND a.created_at >= ? ";
        $params[] = $filterFrom . ' 00:00:00';
    }
    if ($filterTo !== '') {
        $where .= " AND a.created_at <= ? ";
        $params[] = $filterTo . ' 23:59:59';
    }
    if ($search !== '') {
        $where .= " AND (a.action LIKE ? OR a.details LIKE ?) ";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $rows = safeFetchAll($pdo, "
        SELECT a.id, a.created_at, a.action, a.ip_address,
               u.full_name AS user_name, u.role AS user_role,
               a.details
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        $where
        ORDER BY a.created_at DESC
        LIMIT 5000
    ", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_logs_' . date('Ymd_His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Timestamp', 'Action', 'User', 'Role', 'IP Address', 'Details']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            $r['created_at'],
            $r['action'],
            $r['user_name'] ?? 'System',
            $r['user_role'] ?? '',
            $r['ip_address'] ?? '',
            $r['details'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ============================================================
// FILTERS
// ============================================================
$filterAction = trim($_GET['action_filter'] ?? '');
$filterUser   = (int)($_GET['user_id'] ?? 0);
$filterFrom   = trim($_GET['from'] ?? '');
$filterTo     = trim($_GET['to'] ?? '');
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$where  = " WHERE (u.zone_id = ? OR a.user_id = ?) ";
$params = [$activeZoneId, $user['id']];

if ($filterAction !== '') {
    $where .= " AND a.action LIKE ? ";
    $params[] = '%' . $filterAction . '%';
}
if ($filterUser > 0) {
    $where .= " AND a.user_id = ? ";
    $params[] = $filterUser;
}
if ($filterFrom !== '') {
    $where .= " AND a.created_at >= ? ";
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo !== '') {
    $where .= " AND a.created_at <= ? ";
    $params[] = $filterTo . ' 23:59:59';
}
if ($search !== '') {
    $where .= " AND (a.action LIKE ? OR a.details LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

// Total count
$totalLogs  = safeCount($pdo, "
    SELECT COUNT(*) as count
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    $where
", $params);
$totalPages = max(1, (int)ceil($totalLogs / $perPage));

// Fetch page
$logs = safeFetchAll($pdo, "
    SELECT a.*, u.full_name AS user_name, u.role AS user_role, u.zone_id
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    $where
    ORDER BY a.created_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

// ============================================================
// ZONE USERS (filter dropdown)
// ============================================================
$zoneUsers = safeFetchAll($pdo, "
    SELECT id, full_name, role
    FROM users
    WHERE zone_id = ? AND is_active = 1
    ORDER BY full_name
", [$activeZoneId]);

// ============================================================
// DISTINCT ACTIONS (filter dropdown)
// ============================================================
$distinctActions = safeFetchAll($pdo, "
    SELECT DISTINCT a.action
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE (u.zone_id = ? OR a.user_id = ?)
      AND a.action IS NOT NULL
    ORDER BY a.action
    LIMIT 40
", [$activeZoneId, $user['id']]);

// ============================================================
// SUMMARY STATS
// ============================================================
$stats = [
    '24h'  => safeCount($pdo, 'SELECT COUNT(*) as count FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id WHERE (u.zone_id = ? OR a.user_id = ?) AND a.created_at >= (NOW() - (24) * INTERVAL \'1 hour\')', [$activeZoneId, $user['id']]),
    '7d'   => safeCount($pdo, 'SELECT COUNT(*) as count FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id WHERE (u.zone_id = ? OR a.user_id = ?) AND a.created_at >= (NOW() - (7) * INTERVAL \'1 day\')', [$activeZoneId, $user['id']]),
    '30d'  => safeCount($pdo, 'SELECT COUNT(*) as count FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id WHERE (u.zone_id = ? OR a.user_id = ?) AND a.created_at >= (NOW() - (30) * INTERVAL \'1 day\')', [$activeZoneId, $user['id']]),
    'total'=> $totalLogs,
];

// ============================================================
// ACTION ICON + COLOR MAP (expanded)
// ============================================================
$actionMeta = [
    // Auth
    'login'                   => ['🔑', 'green',  'Login'],
    'logout'                  => ['🚪', 'blue',   'Logout'],
    'login_failed'            => ['⚠️', 'red',    'Failed Login'],

    // Users
    'create_user'             => ['👤', 'green',  'Create User'],
    'update_user'             => ['✏️', 'orange', 'Update User'],
    'delete_user'             => ['🗑️', 'red',    'Delete User'],
    'change_password'         => ['🔒', 'orange', 'Change Password'],
    'update_profile'          => ['👤', 'blue',   'Update Profile'],

    // Zones
    'create_zone'             => ['🗺️', 'green',  'Create Zone'],
    'update_zone'             => ['📍', 'orange', 'Update Zone'],
    'delete_zone'             => ['🗑️', 'red',    'Delete Zone'],
    'register_zone'           => ['🏛️', 'green',  'Register Zone'],
    'update_zone_settings'    => ['⚙️', 'blue',   'Update Zone Settings'],

    // Incidents
    'report_incident'         => ['🚨', 'red',    'Report Incident'],
    'acknowledge_incident'    => ['✅', 'green',  'Acknowledge Incident'],
    'resolve_incident'        => ['🏁', 'teal',   'Resolve Incident'],
    'update_incident_status'  => ['🔄', 'blue',   'Update Incident Status'],

    // Cameras
    'create_camera'           => ['📹', 'green',  'Create Camera'],
    'update_camera'           => ['📹', 'orange', 'Update Camera'],
    'delete_camera'           => ['📹', 'red',    'Delete Camera'],
    'toggle_camera'           => ['📹', 'blue',   'Toggle Camera'],
    'toggle_camera_recording' => ['⏺️', 'purple', 'Toggle Camera Recording'],

    // Alarms
    'create_alarm'            => ['🔔', 'green',  'Create Alarm'],
    'update_alarm'            => ['🔔', 'orange', 'Update Alarm'],
    'delete_alarm'            => ['🔔', 'red',    'Delete Alarm'],
    'toggle_alarm'            => ['🔔', 'blue',   'Toggle Alarm'],
    'trigger_alarm'           => ['🚨', 'red',    'Trigger Alarm'],
    'stop_alarm'              => ['⏹️', 'green',  'Stop Alarm'],

    // SMS
    'send_test_sms'           => ['📱', 'blue',   'Send Test SMS'],
    'broadcast_sms'           => ['📢', 'purple', 'Broadcast SMS'],

    // Manpower
    'manpower_request'        => ['🆘', 'red',    'Manpower Request'],

    // AI
    'acknowledge_ai_alert'    => ['🤖', 'green',  'Acknowledge AI Alert'],

    // Simulation
    'run_simulation'          => ['🎮', 'purple', 'Run Simulation'],
    'clear_simulations'       => ['🧹', 'orange', 'Clear Simulations'],
    'delete_simulation_history' => ['🗑️', 'red', 'Delete Sim History'],

    // Patrol
    'assign_patrol_route'     => ['🛤️', 'teal',   'Assign Patrol Route'],

    // Settings
    'update_settings'         => ['⚙️', 'blue',   'Update Settings'],

    // Audit
    'clear_audit_logs'        => ['🧹', 'red',    'Clear Audit Logs'],
];

function actionMeta(string $action, array $meta): array {
    if (isset($meta[$action])) return $meta[$action];
    return ['📌', 'gray', ucwords(str_replace('_', ' ', $action))];
}

// ============================================================
// HUMAN-READABLE DATE
// ============================================================
function hrDate($dt): string {
    if (!$dt) return '—';
    return date('M j, Y · g:i:s A', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Audit Logs - Supervisor - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 22px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 15px; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        /* Sections */
        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }

        /* Buttons */
        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
        .btn-primary   { background: #1a5c3a; color: white; }
        .btn-primary:hover   { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-danger    { background: #dc3545; color: white; }
        .btn-danger:hover { background: #b02a37; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* Alerts */
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }
        .alert.warning { background: #fff3cd; color: #856404; }

        /* Filter bar */
        .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .filter-group input,
        .filter-group select {
            padding: 9px 12px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
            font-family: inherit; transition: all 0.2s;
        }
        .filter-group input:focus,
        .filter-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        .filter-actions { display: flex; gap: 8px; }

        /* Danger zone */
        .danger-zone {
            border: 2px solid #dc3545;
            background: #fff5f5;
            border-radius: 14px;
            padding: 18px 22px;
            margin-bottom: 20px;
        }
        .danger-zone h2 { color: #dc3545; font-size: 16px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .danger-zone p  { font-size: 12px; color: #6c757d; margin-bottom: 12px; }
        .danger-actions { display: flex; flex-wrap: wrap; gap: 8px; }

        /* Log list — clear design */
        .log-list { display: flex; flex-direction: column; gap: 8px; }

        .log-row {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            background: white;
            transition: all 0.15s;
            overflow: hidden;
        }
        .log-row:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-color: #d0d7de; }

        .log-row.critical-log {
            border-left: 4px solid #dc3545;
            background: #fffafa;
        }

        .log-main {
            display: grid;
            grid-template-columns: 44px 1fr 220px 200px 40px;
            gap: 14px;
            align-items: center;
            padding: 12px 16px;
            cursor: pointer;
        }
        .log-main:hover { background: #fafbfc; }

        .log-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .log-icon.green  { background: #d4edda; color: #155724; }
        .log-icon.blue   { background: #cce5ff; color: #004085; }
        .log-icon.orange { background: #fff3cd; color: #856404; }
        .log-icon.red    { background: #f8d7da; color: #721c24; }
        .log-icon.purple { background: #e8d5f5; color: #6f42c1; }
        .log-icon.teal   { background: #d1ecf1; color: #0c5460; }
        .log-icon.gray   { background: #e9ecef; color: #495057; }

        .log-action-name {
            font-weight: 700;
            font-size: 14px;
            color: #0d3b22;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .log-action-key {
            font-size: 10px;
            color: #adb5bd;
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 1px 6px;
            border-radius: 4px;
        }
        .log-sub {
            font-size: 12px;
            color: #6c757d;
            margin-top: 3px;
        }

        .log-user {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .log-user-name {
            font-weight: 600;
            font-size: 13px;
            color: #0d3b22;
        }
        .badge-role {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            width: fit-content;
        }
        .badge-role.admin           { background: #e8d5f5; color: #6f42c1; }
        .badge-role.zone_supervisor { background: #cce5ff; color: #004085; }
        .badge-role.ranger          { background: #d4edda; color: #155724; }
        .badge-role.scout           { background: #fff3cd; color: #856404; }
        .badge-role.tourism         { background: #fce4ec; color: #c62828; }
        .badge-role.system          { background: #e9ecef; color: #495057; }

        .log-time {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .log-time-main {
            font-size: 13px;
            font-weight: 600;
            color: #0d3b22;
        }
        .log-time-rel {
            font-size: 11px;
            color: #adb5bd;
        }

        .log-expand-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: #6c757d;
            transition: all 0.2s;
            cursor: pointer;
        }
        .log-row.open .log-expand-btn {
            background: #1a5c3a;
            color: white;
            border-color: #1a5c3a;
            transform: rotate(180deg);
        }

        .log-details {
            display: none;
            border-top: 1px solid #f0f0f0;
            padding: 14px 16px;
            background: #fafbfc;
        }
        .log-row.open .log-details { display: block; }

        .log-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        .log-detail-item {
            background: white;
            border-radius: 8px;
            padding: 10px 12px;
            border: 1px solid #e9ecef;
        }
        .log-detail-item .label {
            font-size: 10px;
            text-transform: uppercase;
            color: #adb5bd;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .log-detail-item .value {
            font-size: 13px;
            color: #0d3b22;
            font-family: 'Courier New', monospace;
            word-break: break-all;
        }

        .log-json {
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 8px;
            padding: 12px 14px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
            max-height: 260px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
        .pagination a, .pagination span {
            padding: 8px 14px; border-radius: 8px;
            text-decoration: none; font-size: 13px;
            color: #495057; background: #f0f0f0;
            transition: all 0.2s;
            min-width: 38px; text-align: center;
        }
        .pagination a:hover { background: #1a5c3a; color: white; }
        .pagination .active { background: #1a5c3a; color: white; font-weight: 700; }
        .pagination .disabled { opacity: 0.4; pointer-events: none; }

        /* Empty state */
        .empty-state { text-align: center; padding: 50px 20px; color: #6c757d; }
        .empty-state .icon { font-size: 56px; display: block; margin-bottom: 12px; opacity: 0.4; }
        .empty-state h3 { font-size: 17px; color: #495057; margin-bottom: 6px; }
        .empty-state p { font-size: 13px; }

        /* Info notice */
        .info-notice {
            border-left: 4px solid #cce5ff;
            background: #f8fbff;
            padding: 14px 18px;
            border-radius: 10px;
            font-size: 13px;
            color: #495057;
            line-height: 1.6;
        }

        /* Modal */
        .modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: center; justify-content: center;
            padding: 20px;
        }
        .modal-backdrop.show { display: flex; }
        .modal {
            background: white;
            border-radius: 14px;
            max-width: 460px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { font-size: 17px; color: #0d3b22; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6c757d; }
        .modal-body { padding: 22px; }
        .modal-footer {
            padding: 16px 22px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .log-main {
                grid-template-columns: 40px 1fr 40px;
                grid-template-areas:
                    "icon action expand"
                    "user time expand";
                row-gap: 8px;
            }
            .log-icon    { grid-area: icon; }
            .log-action  { grid-area: action; }
            .log-user    { grid-area: user; }
            .log-time    { grid-area: time; text-align: right; }
            .log-expand-btn { grid-area: expand; align-self: center; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .filter-bar { grid-template-columns: 1fr; }
            .log-details-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Audit Logs</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>📋 Zone Audit Logs</h1>
                    <p>
                        Activity history for users in
                        <strong><?= htmlspecialchars(getZoneName($activeZoneId)) ?></strong>
                        plus your own actions.
                    </p>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="icon blue">🕐</div>
                        <div class="info"><div class="number"><?= $stats['24h'] ?></div><div class="label">Last 24 Hours</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="icon green">📅</div>
                        <div class="info"><div class="number"><?= $stats['7d'] ?></div><div class="label">Last 7 Days</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="icon orange">📊</div>
                        <div class="info"><div class="number"><?= $stats['30d'] ?></div><div class="label">Last 30 Days</div></div>
                    </div>
                    <div class="stat-card">
                        <div class="icon purple">📋</div>
                        <div class="info"><div class="number"><?= $stats['total'] ?></div><div class="label">Total Logs</div></div>
                    </div>
                </div>

                <!-- DANGER ZONE — Clear logs -->
                <div class="danger-zone">
                    <h2>⚠️ Clear Audit Logs</h2>
                    <p>
                        Permanently deletes audit log entries for your zone.
                        <strong>This action cannot be undone.</strong>
                        A record of the clear action itself is preserved in the system log.
                    </p>
                    <div class="danger-actions">
                        <button class="btn btn-danger btn-sm"
                                onclick="openClearModal('24h', 'last 24 hours')">
                            🧹 Clear Last 24 Hours
                        </button>
                        <button class="btn btn-danger btn-sm"
                                onclick="openClearModal('7d', 'last 7 days')">
                            🧹 Clear Last 7 Days
                        </button>
                        <button class="btn btn-danger btn-sm"
                                onclick="openClearModal('30d', 'last 30 days')">
                            🧹 Clear Last 30 Days
                        </button>
                        <button class="btn btn-danger btn-sm"
                                onclick="openClearModal('all', 'ALL zone logs')">
                            🧨 Clear All Zone Logs
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="section">
                    <div class="section-header">
                        <h2>🔎 Filter Logs</h2>
                        <div style="display:flex;gap:8px;">
                            <?php if ($filterAction || $filterUser || $filterFrom || $filterTo || $search): ?>
                                <a href="audit.php" class="btn btn-secondary btn-sm">✕ Clear filters</a>
                            <?php endif; ?>
                            <a href="?export=csv&action_filter=<?= urlencode($filterAction) ?>&user_id=<?= $filterUser ?>&from=<?= urlencode($filterFrom) ?>&to=<?= urlencode($filterTo) ?>&search=<?= urlencode($search) ?>"
                               class="btn btn-primary btn-sm">
                                ⬇️ Export CSV
                            </a>
                        </div>
                    </div>

                    <form method="GET" class="filter-bar">
                        <div class="filter-group">
                            <label>Action</label>
                            <select name="action_filter">
                                <option value="">All actions</option>
                                <?php foreach ($distinctActions as $a): ?>
                                    <option value="<?= htmlspecialchars($a['action']) ?>"
                                        <?= $filterAction === $a['action'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(str_replace('_', ' ', $a['action'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>User</label>
                            <select name="user_id">
                                <option value="0">All users</option>
                                <?php foreach ($zoneUsers as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label>From</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>">
                        </div>

                        <div class="filter-group">
                            <label>To</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>">
                        </div>

                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Action or details...">
                        </div>

                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">🔎 Apply</button>
                                <a href="audit.php" class="btn btn-secondary">↺</a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Log List -->
                <div class="section">
                    <div class="section-header">
                        <h2>📜 Activity Log (<?= $totalLogs ?> entries)</h2>
                        <span style="font-size:12px;color:#6c757d;">Page <?= $page ?> of <?= $totalPages ?></span>
                    </div>

                    <?php if (count($logs) > 0): ?>
                        <div class="log-list">
                            <?php foreach ($logs as $idx => $log): ?>
                                <?php
                                [$icon, $color, $label] = actionMeta($log['action'], $actionMeta);
                                $details = null;
                                if (!empty($log['details'])) {
                                    $details = json_decode($log['details'], true);
                                }
                                $roleClass = $log['user_role'] ?? 'system';
                                $isCritical = in_array($log['action'], [
                                    'delete_user', 'delete_zone', 'delete_alarm',
                                    'delete_camera', 'clear_audit_logs', 'clear_simulations',
                                    'trigger_alarm', 'login_failed'
                                ]);
                                ?>
                                <div class="log-row <?= $isCritical ? 'critical-log' : '' ?>"
                                     id="log-row-<?= (int)$log['id'] ?>">

                                    <!-- Header row (clickable) -->
                                    <div class="log-main" onclick="toggleLog(<?= (int)$log['id'] ?>)">
                                        <div class="log-icon <?= $color ?>"><?= $icon ?></div>

                                        <div class="log-action">
                                            <div class="log-action-name">
                                                <?= htmlspecialchars($label) ?>
                                                <span class="log-action-key"><?= htmlspecialchars($log['action']) ?></span>
                                            </div>
                                            <div class="log-sub">
                                                🆔 Log #<?= (int)$log['id'] ?>
                                                <?php if (!empty($log['ip_address'])): ?>
                                                    · 🌐 <?= htmlspecialchars($log['ip_address']) ?>
                                                <?php endif; ?>
                                                <?php if ($isCritical): ?>
                                                    · <span style="color:#dc3545;font-weight:700;">⚠️ CRITICAL</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="log-user">
                                            <div class="log-user-name">
                                                <?= htmlspecialchars($log['user_name'] ?? 'System') ?>
                                            </div>
                                            <span class="badge-role <?= htmlspecialchars($roleClass) ?>">
                                                <?= htmlspecialchars(str_replace('_', ' ', $log['user_role'] ?? 'system')) ?>
                                            </span>
                                        </div>

                                        <div class="log-time">
                                            <div class="log-time-main">
                                                <?= date('M j, H:i:s', strtotime($log['created_at'])) ?>
                                            </div>
                                            <div class="log-time-rel">
                                                <?= timeAgo($log['created_at']) ?>
                                            </div>
                                        </div>

                                        <div class="log-expand-btn">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>

                                    <!-- Expanded details -->
                                    <div class="log-details">
                                        <div class="log-details-grid">
                                            <div class="log-detail-item">
                                                <div class="label">Timestamp</div>
                                                <div class="value"><?= hrDate($log['created_at']) ?></div>
                                            </div>
                                            <div class="log-detail-item">
                                                <div class="label">Action Key</div>
                                                <div class="value"><?= htmlspecialchars($log['action']) ?></div>
                                            </div>
                                            <div class="log-detail-item">
                                                <div class="label">User ID</div>
                                                <div class="value"><?= $log['user_id'] ? (int)$log['user_id'] : 'System' ?></div>
                                            </div>
                                            <div class="log-detail-item">
                                                <div class="label">IP Address</div>
                                                <div class="value"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></div>
                                            </div>
                                            <div class="log-detail-item">
                                                <div class="label">User Agent</div>
                                                <div class="value"><?= htmlspecialchars(substr($log['user_agent'] ?? '—', 0, 80)) ?></div>
                                            </div>
                                            <div class="log-detail-item">
                                                <div class="label">Zone ID</div>
                                                <div class="value"><?= $log['zone_id'] ? (int)$log['zone_id'] : '—' ?></div>
                                            </div>
                                        </div>

                                        <?php if ($details): ?>
                                            <div style="font-size:11px;text-transform:uppercase;color:#adb5bd;font-weight:700;letter-spacing:0.5px;margin-bottom:6px;">
                                                Details
                                            </div>
                                            <pre class="log-json"><?= htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                                        <?php else: ?>
                                            <div style="font-size:12px;color:#adb5bd;font-style:italic;">
                                                No additional details recorded for this action.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <?php
                            $qs = $_GET; unset($qs['page']);
                            $q = http_build_query($qs);
                            $q = $q ? '&' . $q : '';
                            ?>
                            <div class="pagination">
                                <a href="?page=1<?= $q ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">«</a>
                                <a href="?page=<?= max(1, $page - 1) ?><?= $q ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">‹ Prev</a>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($totalPages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <a href="?page=<?= $i ?><?= $q ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <a href="?page=<?= min($totalPages, $page + 1) ?><?= $q ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">Next ›</a>
                                <a href="?page=<?= $totalPages ?><?= $q ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">»</a>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">📋</span>
                            <h3>No audit logs found</h3>
                            <p>No activity matches your current filters.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info notice -->
                <div class="info-notice">
                    <strong>ℹ️ Scope & Security</strong><br>
                    • You are viewing logs for users in <strong><?= htmlspecialchars(getZoneName($activeZoneId)) ?></strong>
                       plus your own actions.<br>
                    • System-wide logs across all zones are visible only to administrators.<br>
                    • Every clear action is itself logged and preserved.<br>
                    • Exports include up to 5,000 most recent entries matching your filters.
                </div>
            </div>
        </main>
    </div>

    <!-- CLEAR MODAL -->
    <div class="modal-backdrop" id="clearModal">
        <div class="modal">
            <form method="POST" id="clearForm">
                <input type="hidden" name="action" value="clear_logs">
                <input type="hidden" name="scope" id="clearScope">

                <div class="modal-header">
                    <h3>🧹 Confirm Clear</h3>
                    <button type="button" class="modal-close" onclick="closeClearModal()">×</button>
                </div>

                <div class="modal-body">
                    <p style="font-size:14px;color:#495057;line-height:1.6;margin-bottom:12px;">
                        You are about to <strong style="color:#dc3545;">permanently delete</strong>
                        audit log entries for
                        <strong id="clearScopeLabel">—</strong>
                        in zone <strong><?= htmlspecialchars(getZoneName($activeZoneId)) ?></strong>.
                    </p>
                    <p style="font-size:12px;color:#6c757d;">
                        This action cannot be undone. The clear action itself will be recorded in the system log.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeClearModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">🧹 Yes, Clear Logs</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // ============================================================
        // EXPAND/COLLAPSE LOG ROWS
        // ============================================================
        function toggleLog(id) {
            const row = document.getElementById('log-row-' + id);
            if (!row) return;
            row.classList.toggle('open');
        }

        // ============================================================
        // CLEAR MODAL
        // ============================================================
        function openClearModal(scope, label) {
            document.getElementById('clearScope').value = scope;
            document.getElementById('clearScopeLabel').textContent = label;
            document.getElementById('clearModal').classList.add('show');
        }
        function closeClearModal() {
            document.getElementById('clearModal').classList.remove('show');
        }
        document.getElementById('clearModal').addEventListener('click', function (e) {
            if (e.target === this) closeClearModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeClearModal();
        });
    </script>
</body>
</html>
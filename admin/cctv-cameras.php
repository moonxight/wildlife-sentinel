<?php
// ============================================================
// admin/cctv-cameras.php
// Wildlife Sentinel — CCTV Camera Management (Admin)
// ============================================================
// Features:
//   - List all cameras (filter by zone, status, type)
//   - Add / edit camera
//   - Activate / deactivate camera
//   - Toggle recording (guarded)
//   - Delete camera (with detection safety)
//   - Camera statistics + online/offline freshness
//   - Honors global settings:
//       ai_enabled, cctv_retention_days, cctv_snapshot_dir,
//       items_per_page
// ============================================================

require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$pdo  = getDB();

// ============================================================
// SAFE HELPERS
// ============================================================
if (!function_exists('safeCount')) {
    function safeCount(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return (int)($row['count'] ?? 0);
        } catch (PDOException $e) {
            error_log('[WS-CCTV] safeCount: ' . $e->getMessage());
            return 0;
        }
    }
}
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[WS-CCTV] safeFetchAll: ' . $e->getMessage());
            return [];
        }
    }
}

// ============================================================
// GLOBAL SETTINGS (from admin/settings.php)
// ============================================================
if (!function_exists('ws_cctv_setting')) {
    function ws_cctv_setting(string $key, $default = null) {
        if (function_exists('getSetting')) {
            $v = getSetting($key);
            return $v !== null ? $v : $default;
        }
        try {
            $stmt = getDB()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['setting_value'] : $default;
        } catch (PDOException $e) {
            return $default;
        }
    }
}

$setAiEnabled       = (string) ws_cctv_setting('ai_enabled', '1')             === '1';
$setCctvRetention   = (int)    ws_cctv_setting('cctv_retention_days', 30);
$setCctvSnapshotDir = (string) ws_cctv_setting('cctv_snapshot_dir', 'uploads/cctv/');

$itemsPerPage = (int) ws_cctv_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;

// ============================================================
// HELPERS — stream URL validation
// ============================================================
if (!function_exists('ws_validate_stream_url')) {
    function ws_validate_stream_url(?string $url): array {
        $url = trim((string)$url);
        if ($url === '') return ['ok' => true, 'scheme' => null]; // empty is OK
        $p = parse_url($url);
        if (!$p || empty($p['scheme'])) {
            return ['ok' => false, 'error' => 'Missing scheme (rtsp://, http://, https://)'];
        }
        $scheme = strtolower($p['scheme']);
        $allowed = ['rtsp', 'rtsps', 'http', 'https'];
        if (!in_array($scheme, $allowed, true)) {
            return ['ok' => false, 'error' => 'Unsupported scheme: ' . $scheme];
        }
        return ['ok' => true, 'scheme' => $scheme];
    }
}

if (!function_exists('ws_camera_freshness')) {
    /**
     * Returns 'online', 'stale', or 'unknown' based on last_seen.
     * A camera is "online" if it was seen in the last 5 minutes.
     */
    function ws_camera_freshness(?string $lastSeen): string {
        if (empty($lastSeen)) return 'unknown';
        $ts = strtotime($lastSeen);
        if (!$ts) return 'unknown';
        $age = time() - $ts;
        if ($age <= 300)  return 'online';
        if ($age <= 3600) return 'stale';
        return 'offline';
    }
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- CREATE CAMERA --------
    if ($action === 'create') {
        $zoneId     = (int)($_POST['zone_id'] ?? 0);
        $cameraName = trim($_POST['camera_name'] ?? '');
        $cameraCode = trim($_POST['camera_code'] ?? '');
        $streamUrl  = trim($_POST['stream_url'] ?? '');
        $lat        = ($_POST['location_lat'] ?? '') !== '' ? (float)$_POST['location_lat'] : null;
        $lng        = ($_POST['location_lng'] ?? '') !== '' ? (float)$_POST['location_lng'] : null;
        $cameraType = $_POST['camera_type'] ?? 'fixed';
        $resolution = trim($_POST['resolution'] ?? '1080p');

        if (!$zoneId || !$cameraName) {
            $message = 'Zone and camera name are required.';
            $messageType = 'danger';
        } elseif (!in_array($cameraType, ['fixed','ptz','thermal','drone'], true)) {
            $message = 'Invalid camera type.';
            $messageType = 'danger';
        } else {
            $streamCheck = ws_validate_stream_url($streamUrl);
            if (!$streamCheck['ok']) {
                $message = 'Invalid stream URL: ' . $streamCheck['error'];
                $messageType = 'danger';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO cctv_cameras
                            (zone_id, camera_name, camera_code, stream_url,
                             location_lat, location_lng, camera_type, resolution,
                             is_active, is_recording, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())
                    ");
                    $stmt->execute([
                        $zoneId, $cameraName, $cameraCode ?: null, $streamUrl ?: null,
                        $lat, $lng, $cameraType, $resolution,
                    ]);
                    $newId = (int)$pdo->query('SELECT lastval()')->fetchColumn();
                    logAudit($user['id'], 'create_camera', ['camera_id' => $newId, 'zone_id' => $zoneId]);
                    $message = "✅ Camera '{$cameraName}' added successfully.";
                } catch (PDOException $e) {
                    $message = 'Database error: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    // -------- UPDATE CAMERA --------
    if ($action === 'update') {
        $id         = (int)($_POST['camera_id'] ?? 0);
        $zoneId     = (int)($_POST['zone_id'] ?? 0);
        $cameraName = trim($_POST['camera_name'] ?? '');
        $cameraCode = trim($_POST['camera_code'] ?? '');
        $streamUrl  = trim($_POST['stream_url'] ?? '');
        $lat        = ($_POST['location_lat'] ?? '') !== '' ? (float)$_POST['location_lat'] : null;
        $lng        = ($_POST['location_lng'] ?? '') !== '' ? (float)$_POST['location_lng'] : null;
        $cameraType = $_POST['camera_type'] ?? 'fixed';
        $resolution = trim($_POST['resolution'] ?? '1080p');

        if (!$id || !$zoneId || !$cameraName) {
            $message = 'Zone and camera name are required.';
            $messageType = 'danger';
        } elseif (!in_array($cameraType, ['fixed','ptz','thermal','drone'], true)) {
            $message = 'Invalid camera type.';
            $messageType = 'danger';
        } else {
            $streamCheck = ws_validate_stream_url($streamUrl);
            if (!$streamCheck['ok']) {
                $message = 'Invalid stream URL: ' . $streamCheck['error'];
                $messageType = 'danger';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE cctv_cameras SET
                            zone_id = ?, camera_name = ?, camera_code = ?, stream_url = ?,
                            location_lat = ?, location_lng = ?, camera_type = ?, resolution = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $zoneId, $cameraName, $cameraCode ?: null, $streamUrl ?: null,
                        $lat, $lng, $cameraType, $resolution, $id,
                    ]);
                    logAudit($user['id'], 'update_camera', ['camera_id' => $id, 'name' => $cameraName]);
                    $message = "✅ Camera '{$cameraName}' updated successfully.";
                } catch (PDOException $e) {
                    $message = 'Database error: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    // -------- TOGGLE ACTIVE --------
    if ($action === 'toggle') {
        $id = (int)($_POST['camera_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT camera_name, is_active, is_recording FROM cctv_cameras WHERE id = ? LIMIT 1");
            $row->execute([$id]);
            $c = $row->fetch();

            if (!$c) {
                $message = '❌ Camera not found.';
                $messageType = 'danger';
            } else {
                $wasActive = ((int)$c['is_active']) === 1;
                $pdo->prepare("UPDATE cctv_cameras SET is_active = NOT is_active WHERE id = ?")->execute([$id]);

                // If deactivating while recording, stop recording too
                $extra = '';
                if ($wasActive && ((int)$c['is_recording']) === 1) {
                    $pdo->prepare("UPDATE cctv_cameras SET is_recording = 0 WHERE id = ?")->execute([$id]);
                    $extra = ' (recording stopped)';
                }

                $newState = $wasActive ? 'deactivated' : 'activated';
                logAudit($user['id'], 'toggle_camera', ['camera_id' => $id, 'new_state' => $newState]);
                $message = "✅ Camera '{$c['camera_name']}' {$newState}{$extra}.";
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // -------- TOGGLE RECORDING --------
    if ($action === 'toggle_recording') {
        $id = (int)($_POST['camera_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT camera_name, is_active, is_recording FROM cctv_cameras WHERE id = ? LIMIT 1");
            $row->execute([$id]);
            $c = $row->fetch();

            if (!$c) {
                $message = '❌ Camera not found.';
                $messageType = 'danger';
            } elseif (((int)$c['is_active']) !== 1) {
                $message = "❌ Camera '{$c['camera_name']}' is inactive. Activate it first.";
                $messageType = 'danger';
            } else {
                $pdo->prepare("UPDATE cctv_cameras SET is_recording = NOT is_recording WHERE id = ?")->execute([$id]);
                $newState = ((int)$c['is_recording']) === 1 ? 'stopped' : 'started';
                logAudit($user['id'], 'toggle_camera_recording', ['camera_id' => $id, 'new_state' => $newState]);
                $message = "✅ Recording {$newState} on '{$c['camera_name']}'.";
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // -------- DELETE --------
    if ($action === 'delete') {
        $id = (int)($_POST['camera_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT camera_name, is_recording FROM cctv_cameras WHERE id = ? LIMIT 1");
            $row->execute([$id]);
            $c = $row->fetch();

            if (!$c) {
                $message = '❌ Camera not found.';
                $messageType = 'danger';
            } elseif (((int)$c['is_recording']) === 1) {
                $message = "❌ Cannot delete '{$c['camera_name']}' — stop recording first.";
                $messageType = 'danger';
            } else {
                // Check for detections — warn but allow (admin's choice)
                $detCount = safeCount($pdo, "SELECT COUNT(*) AS count FROM ai_detections WHERE camera_id = ?", [$id]);

                $pdo->prepare("DELETE FROM cctv_cameras WHERE id = ?")->execute([$id]);
                logAudit($user['id'], 'delete_camera', [
                    'camera_id' => $id,
                    'name'      => $c['camera_name'],
                    'detections_left' => $detCount,
                ]);

                $message = "🗑️ Camera '{$c['camera_name']}' deleted.";
                if ($detCount > 0) {
                    $message .= " ({$detCount} historical detection(s) preserved.)";
                }
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ============================================================
// FETCH FILTERS
// ============================================================
$filterZone   = (int)($_GET['zone_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';
$search       = trim($_GET['search'] ?? '');

$where  = " WHERE 1=1 ";
$params = [];

if ($filterZone > 0) {
    $where .= " AND c.zone_id = ? ";
    $params[] = $filterZone;
}
if ($filterStatus === 'active')    $where .= " AND c.is_active = 1 ";
if ($filterStatus === 'inactive')  $where .= " AND c.is_active = 0 ";
if ($filterStatus === 'recording') $where .= " AND c.is_recording = 1 ";
if ($filterType !== '' && in_array($filterType, ['fixed','ptz','thermal','drone'], true)) {
    $where .= " AND c.camera_type = ? ";
    $params[] = $filterType;
}
if ($search !== '') {
    $where .= " AND (c.camera_name LIKE ? OR c.camera_code LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

// ============================================================
// FETCH CAMERAS
// ============================================================
$cameras = safeFetchAll($pdo, "
    SELECT c.*, z.name AS zone_name, z.park_type,
           (SELECT COUNT(*) FROM ai_detections d
             WHERE d.camera_id = c.id AND DATE(d.detected_at) = CURRENT_DATE) AS detections_today
    FROM cctv_cameras c
    JOIN zones z ON c.zone_id = z.id
    $where
    ORDER BY c.is_active DESC, c.camera_name
    LIMIT " . (int)$itemsPerPage . "
", $params);

// ============================================================
// STATS
// ============================================================
$stats = [
    'total'     => safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras"),
    'active'    => safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE is_active = 1"),
    'inactive'  => safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE is_active = 0"),
    'recording' => safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE is_recording = 1"),
    'ptz'       => safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE camera_type = 'ptz'"),
    'thermal'   => safeCount($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE camera_type = 'thermal'"),
];

// ============================================================
// ZONES
// ============================================================
$zones = safeFetchAll($pdo, "SELECT id, name, park_type FROM zones WHERE is_active = 1 ORDER BY name");

// ============================================================
// ICON MAP
// ============================================================
$typeIcons = [
    'fixed'   => '📹',
    'ptz'     => '🎥',
    'thermal' => '🌡️',
    'drone'   => '🚁',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>CCTV Cameras - Admin - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 24px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 16px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .icon.teal   { background: #d1ecf1; color: #0c5460; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .section-header .header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c62828; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-xs { padding: 3px 8px; font-size: 10.5px; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }
        .alert.warning { background: #fff3cd; color: #856404; }
        .alert.info    { background: #d1ecf1; color: #0c5460; }
        .alert a { color: inherit; }

        /* Settings echo banner */
        .settings-echo {
            background: #eef7f1; border: 1px solid #c3e6cb;
            border-radius: 10px; padding: 12px 16px;
            margin-bottom: 16px; font-size: 12.5px;
            color: #155724;
        }
        .settings-echo strong { color: #0d3b22; }
        .settings-echo code { font-size: 11.5px; background: rgba(255,255,255,.6); padding: 1px 6px; border-radius: 4px; }
        .settings-echo .off { color: #721c24; font-weight: 700; }

        /* AI disabled banner */
        .ai-off-banner {
            background: #fff3cd; border: 1px solid #ffc107;
            color: #856404; border-radius: 10px;
            padding: 12px 16px; margin-bottom: 16px;
            display: flex; align-items: center; gap: 10px;
            font-size: 13px;
        }

        /* Quick nav */
        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }

        /* Filters */
        .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .filter-group input, .filter-group select {
            padding: 9px 12px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
            transition: all 0.2s;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        /* Table */
        .cam-table { width: 100%; border-collapse: collapse; }
        .cam-table thead th {
            text-align: left; font-size: 11px; color: #6c757d;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 10px 12px; border-bottom: 2px solid #f0f0f0;
            background: #fafafa; font-weight: 700;
        }
        .cam-table tbody tr { border-bottom: 1px solid #f5f5f5; transition: background 0.15s; }
        .cam-table tbody tr:hover { background: #fafafa; }
        .cam-table td { padding: 12px; font-size: 13px; vertical-align: middle; }

        .cam-icon {
            width: 38px; height: 38px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px; background: #f0f7f4; flex-shrink: 0;
        }

        .status-pill {
            padding: 3px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; display: inline-block;
        }
        .status-pill.active    { background: #d4edda; color: #155724; }
        .status-pill.inactive  { background: #e9ecef; color: #495057; }
        .status-pill.recording { background: #f8d7da; color: #721c24; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }

        /* Freshness dot */
        .fresh-dot {
            display: inline-block; width: 8px; height: 8px;
            border-radius: 50%; margin-right: 5px;
            vertical-align: middle;
        }
        .fresh-dot.online  { background: #28a745; box-shadow: 0 0 4px #28a745; }
        .fresh-dot.stale   { background: #ffc107; }
        .fresh-dot.offline { background: #adb5bd; }
        .fresh-dot.unknown { background: #e0e0e0; }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }
        .empty-state h3 { font-size: 16px; color: #495057; margin-bottom: 6px; }

        /* Modal */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-backdrop.show { display: flex; }
        .modal { background: white; border-radius: 14px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-header { padding: 18px 22px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 18px; color: #0d3b22; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6c757d; }
        .modal-body { padding: 22px; }
        .modal-footer { padding: 16px 22px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 10px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; color: #495057; font-weight: 600; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
            transition: all 0.2s; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #1a5c3a; background: white; }
        .field-hint { font-size: 11.5px; color: #6c757d; margin-top: 4px; line-height: 1.4; }
        .field-hint.ok  { color: #28a745; }
        .field-hint.err { color: #dc3545; }

        @media (max-width: 1024px) {
            .cam-table thead { display: none; }
            .cam-table, .cam-table tbody, .cam-table tr, .cam-table td { display: block; width: 100%; }
            .cam-table tr { margin-bottom: 12px; padding: 12px; border-radius: 10px; background: #fafafa; border: 1px solid #f0f0f0; }
            .cam-table td { padding: 4px 0; border: none; }
            .cam-table td::before { content: attr(data-label); font-size: 10px; text-transform: uppercase; color: #adb5bd; display: block; margin-bottom: 2px; }
        }
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
                <h1>CCTV Cameras</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>📹 CCTV Camera Management</h1>
                    <p>Configure and monitor all surveillance cameras across zones.</p>
                </div>

                <!-- Quick nav -->
                <div class="quick-nav">
                    <a href="ai-dashboard.php" class="btn btn-secondary">🤖 AI Dashboard</a>
                    <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                    <a href="alarm-systems.php" class="btn btn-secondary">🔔 Alarms</a>
                    <a href="simulation.php" class="btn btn-secondary">🎮 Simulation</a>
                    <a href="settings.php" class="btn btn-secondary">⚙️ System Settings</a>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= htmlspecialchars($messageType) ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- Settings echo -->
                <div class="settings-echo">
                    <strong>🧾 Camera pipeline governed by System Settings:</strong>
                    AI pipeline: <strong><?= $setAiEnabled ? 'enabled' : '<span class="off">DISABLED</span>' ?></strong>
                    • Snapshot directory: <code><?= htmlspecialchars($setCctvSnapshotDir) ?></code>
                    • Retention: <strong><?= (int)$setCctvRetention ?> days</strong>
                    <a href="settings.php" style="color:inherit;text-decoration:underline;">Change</a>
                </div>

                <?php if (!$setAiEnabled): ?>
                    <div class="ai-off-banner">
                        <span style="font-size:18px;">⛔</span>
                        <div>
                            <strong>AI Detection is globally disabled.</strong>
                            Cameras can still be managed below, but no detections or alerts will be generated.
                            Enable it in <a href="settings.php">System Settings → AI / CCTV</a>.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon blue">📹</div><div class="info"><div class="number"><?= $stats['total'] ?></div><div class="label">Total Cameras</div></div></div>
                    <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= $stats['active'] ?></div><div class="label">Active</div></div></div>
                    <div class="stat-card"><div class="icon orange">⚪</div><div class="info"><div class="number"><?= $stats['inactive'] ?></div><div class="label">Inactive</div></div></div>
                    <div class="stat-card"><div class="icon red">⏺️</div><div class="info"><div class="number"><?= $stats['recording'] ?></div><div class="label">Recording</div></div></div>
                    <div class="stat-card"><div class="icon purple">🎥</div><div class="info"><div class="number"><?= $stats['ptz'] ?></div><div class="label">PTZ</div></div></div>
                    <div class="stat-card"><div class="icon teal">🌡️</div><div class="info"><div class="number"><?= $stats['thermal'] ?></div><div class="label">Thermal</div></div></div>
                </div>

                <!-- Filters -->
                <div class="section">
                    <div class="section-header">
                        <h2>🔎 Filter Cameras</h2>
                        <?php if ($filterZone || $filterStatus || $filterType || $search): ?>
                            <a href="cctv-cameras.php" class="btn btn-secondary btn-sm">✕ Clear filters</a>
                        <?php endif; ?>
                    </div>
                    <form method="GET" class="filter-bar">
                        <div class="filter-group">
                            <label>Zone</label>
                            <select name="zone_id">
                                <option value="0">All Zones</option>
                                <?php foreach ($zones as $z): ?>
                                    <option value="<?= (int)$z['id'] ?>" <?= $filterZone == $z['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($z['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All</option>
                                <option value="active"    <?= $filterStatus === 'active'    ? 'selected' : '' ?>>Active</option>
                                <option value="inactive"  <?= $filterStatus === 'inactive'  ? 'selected' : '' ?>>Inactive</option>
                                <option value="recording" <?= $filterStatus === 'recording' ? 'selected' : '' ?>>Recording</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Type</label>
                            <select name="type">
                                <option value="">All Types</option>
                                <option value="fixed"   <?= $filterType === 'fixed'   ? 'selected' : '' ?>>Fixed</option>
                                <option value="ptz"     <?= $filterType === 'ptz'     ? 'selected' : '' ?>>PTZ</option>
                                <option value="thermal" <?= $filterType === 'thermal' ? 'selected' : '' ?>>Thermal</option>
                                <option value="drone"   <?= $filterType === 'drone'   ? 'selected' : '' ?>>Drone</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Camera name or code">
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">🔎 Apply</button>
                        </div>
                    </form>
                </div>

                <!-- Camera List -->
                <div class="section">
                    <div class="section-header">
                        <h2>📋 Cameras (<?= count($cameras) ?>)</h2>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <i class="fas fa-plus"></i> Add Camera
                        </button>
                    </div>

                    <?php if (count($cameras) > 0): ?>
                        <table class="cam-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;"></th>
                                    <th>Camera</th>
                                    <th>Zone</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Today</th>
                                    <th>Location</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cameras as $cam): ?>
                                    <?php
                                    $icon      = $typeIcons[$cam['camera_type']] ?? '📹';
                                    $freshness = ws_camera_freshness($cam['last_seen'] ?? null);
                                    ?>
                                    <tr>
                                        <td data-label="">
                                            <span class="cam-icon"><?= $icon ?></span>
                                        </td>
                                        <td data-label="Camera">
                                            <div style="font-weight:600;color:#0d3b22;font-size:13px;">
                                                <?= htmlspecialchars($cam['camera_name']) ?>
                                            </div>
                                            <?php if ($cam['camera_code']): ?>
                                                <div style="font-size:11px;color:#6c757d;">
                                                    Code: <?= htmlspecialchars($cam['camera_code']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="font-size:10.5px;color:#adb5bd;margin-top:2px;">
                                                <span class="fresh-dot <?= $freshness ?>"></span>
                                                <?php
                                                switch ($freshness) {
                                                    case 'online':  echo 'Online (seen &lt;5m ago)'; break;
                                                    case 'stale':   echo 'Stale (&lt;1h)'; break;
                                                    case 'offline': echo 'Offline (&gt;1h)'; break;
                                                    default:        echo 'Never seen'; break;
                                                }
                                                ?>
                                            </div>
                                        </td>
                                        <td data-label="Zone">
                                            <div style="font-size:12px;"><?= htmlspecialchars($cam['zone_name']) ?></div>
                                            <div style="font-size:10px;color:#6c757d;text-transform:uppercase;">
                                                <?= htmlspecialchars(str_replace('_', ' ', $cam['park_type'])) ?>
                                            </div>
                                        </td>
                                        <td data-label="Type">
                                            <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">
                                                <?= htmlspecialchars($cam['camera_type']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <?php if (!$cam['is_active']): ?>
                                                <span class="status-pill inactive">⚪ Inactive</span>
                                            <?php elseif ($cam['is_recording']): ?>
                                                <span class="status-pill recording">⏺️ Recording</span>
                                            <?php else: ?>
                                                <span class="status-pill active">✅ Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Today">
                                            <?php if ($cam['detections_today'] > 0): ?>
                                                <a href="ai-dashboard.php?camera=<?= (int)$cam['id'] ?>&win=24h"
                                                   class="btn btn-xs btn-secondary"
                                                   title="View today's detections">
                                                    🤖 <?= (int)$cam['detections_today'] ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#adb5bd;">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Location">
                                            <?php if ($cam['location_lat'] && $cam['location_lng']): ?>
                                                <div style="font-size:11px;">
                                                    📍 <?= number_format($cam['location_lat'], 4) ?>, <?= number_format($cam['location_lng'], 4) ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#adb5bd;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <button class="btn btn-sm btn-secondary"
                                                        onclick='openEditModal(<?= htmlspecialchars(json_encode($cam, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)'>✏️ Edit</button>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="camera_id" value="<?= (int)$cam['id'] ?>">
                                                    <button class="btn btn-sm <?= $cam['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                                                        <?= $cam['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_recording">
                                                    <input type="hidden" name="camera_id" value="<?= (int)$cam['id'] ?>">
                                                    <button class="btn btn-sm <?= $cam['is_recording'] ? 'btn-warning' : 'btn-primary' ?>"
                                                            <?= !$cam['is_active'] ? 'disabled title="Activate the camera first"' : '' ?>>
                                                        <?= $cam['is_recording'] ? '⏹️ Stop' : '⏺️ Record' ?>
                                                    </button>
                                                </form>
                                                <button class="btn btn-sm btn-danger"
                                                        onclick="confirmDelete(<?= (int)$cam['id'] ?>, '<?= htmlspecialchars($cam['camera_name'], ENT_QUOTES) ?>', <?= (int)$cam['detections_today'] ?>)">🗑️</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">📹</span>
                            <h3>No cameras found</h3>
                            <p>Click "Add Camera" to register your first CCTV camera.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Setup Notice -->
                <div class="section" style="border-left:4px solid #cce5ff;background:#f8fbff;">
                    <div style="font-size:13px;color:#495057;line-height:1.7;">
                        <strong>ℹ️ Camera Integration</strong><br>
                        Stream URL supports:<br>
                        • <b>RTSP</b>: <code>rtsp://user:pass@ip:554/stream</code><br>
                        • <b>RTSPS</b>: <code>rtsps://camera.example.com:322/stream</code><br>
                        • <b>HTTP/HLS</b>: <code>https://camera.example.com/stream.m3u8</code><br>
                        • <b>HTTPS</b>: <code>https://camera.example.com/feed</code><br>
                        The AI detection pipeline uses RTSP cameras for real-time analysis.
                        Snapshots are saved to <code><?= htmlspecialchars($setCctvSnapshotDir) ?></code>
                        and retained for <strong><?= (int)$setCctvRetention ?> days</strong>.
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- CREATE/EDIT MODAL -->
    <div class="modal-backdrop" id="cameraModal">
        <div class="modal">
            <form method="POST" id="cameraForm">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="camera_id" id="formCameraId" value="">

                <div class="modal-header">
                    <h3 id="modalTitle">➕ Add Camera</h3>
                    <button type="button" class="modal-close" onclick="closeModal()">×</button>
                </div>

                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Zone *</label>
                            <select name="zone_id" id="formZoneId" required>
                                <option value="">Select zone…</option>
                                <?php foreach ($zones as $z): ?>
                                    <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Camera Type *</label>
                            <select name="camera_type" id="formCameraType" required>
                                <option value="fixed">Fixed</option>
                                <option value="ptz">PTZ</option>
                                <option value="thermal">Thermal</option>
                                <option value="drone">Drone</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Camera Name *</label>
                        <input type="text" name="camera_name" id="formCameraName" required placeholder="e.g. North Gate Camera 1">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Camera Code</label>
                            <input type="text" name="camera_code" id="formCameraCode" placeholder="e.g. SLNP-NG-001">
                        </div>
                        <div class="form-group">
                            <label>Resolution</label>
                            <input type="text" name="resolution" id="formResolution" value="1080p" placeholder="1080p, 4K…">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Stream URL</label>
                        <input type="text" name="stream_url" id="formStreamUrl" placeholder="rtsp:// or https://" oninput="validateStreamUrl()">
                        <div class="field-hint" id="streamUrlHint">Supports rtsp://, rtsps://, http://, https://</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Latitude</label>
                            <input type="number" step="0.000001" name="location_lat" id="formLat" placeholder="-13.000000">
                        </div>
                        <div class="form-group">
                            <label>Longitude</label>
                            <input type="number" step="0.000001" name="location_lng" id="formLng" placeholder="31.500000">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Save Camera</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE FORM -->
    <form method="POST" id="deleteForm" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="camera_id" id="deleteCameraId">
    </form>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = '➕ Add Camera';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formCameraId').value = '';
            document.getElementById('cameraForm').reset();
            document.getElementById('formCameraType').value = 'fixed';
            document.getElementById('formResolution').value = '1080p';
            document.getElementById('modalSubmitBtn').textContent = 'Add Camera';
            validateStreamUrl();
            document.getElementById('cameraModal').classList.add('show');
        }

        function openEditModal(cam) {
            document.getElementById('modalTitle').textContent = '✏️ Edit Camera';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formCameraId').value = cam.id;
            document.getElementById('formZoneId').value = cam.zone_id;
            document.getElementById('formCameraType').value = cam.camera_type || 'fixed';
            document.getElementById('formCameraName').value = cam.camera_name || '';
            document.getElementById('formCameraCode').value = cam.camera_code || '';
            document.getElementById('formResolution').value = cam.resolution || '1080p';
            document.getElementById('formStreamUrl').value = cam.stream_url || '';
            document.getElementById('formLat').value = cam.location_lat || '';
            document.getElementById('formLng').value = cam.location_lng || '';
            document.getElementById('modalSubmitBtn').textContent = 'Save Changes';
            validateStreamUrl();
            document.getElementById('cameraModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('cameraModal').classList.remove('show');
        }

        function confirmDelete(id, name, detectionsToday) {
            let msg = 'Delete camera "' + name + '"?\n\n';
            msg += 'Historical detections and alerts will be preserved.\n';
            if (detectionsToday > 0) {
                msg += '⚠️ This camera has ' + detectionsToday + ' detection(s) today.\n';
            }
            msg += '\nThis action cannot be undone.';
            if (confirm(msg)) {
                document.getElementById('deleteCameraId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // Live stream URL validation
        function validateStreamUrl() {
            const el = document.getElementById('formStreamUrl');
            const hint = document.getElementById('streamUrlHint');
            const v = (el.value || '').trim();
            if (!v) {
                hint.textContent = 'Supports rtsp://, rtsps://, http://, https://';
                hint.className = 'field-hint';
                return;
            }
            try {
                const url = new URL(v);
                const scheme = url.protocol.replace(':', '').toLowerCase();
                if (!['rtsp','rtsps','http','https'].includes(scheme)) {
                    hint.textContent = '⚠️ Unsupported scheme: ' + scheme;
                    hint.className = 'field-hint err';
                } else {
                    hint.textContent = '✅ Valid ' + scheme.toUpperCase() + ' stream';
                    hint.className = 'field-hint ok';
                }
            } catch (e) {
                hint.textContent = '⚠️ Invalid URL — missing scheme or malformed';
                hint.className = 'field-hint err';
            }
        }
        document.getElementById('formStreamUrl').addEventListener('input', validateStreamUrl);

        // Close modal on backdrop click
        document.getElementById('cameraModal').addEventListener('click', function (e) {
            if (e.target === this) closeModal();
        });

        // Close modal on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>
<?php
// ============================================================
// admin/alarm-systems.php
// Wildlife Sentinel — Alarm Systems Management (Admin)
// ============================================================
// Features:
//   - List all alarms (filter by zone, status, type)
//   - Add new alarm
//   - Edit alarm
//   - Activate / deactivate alarm
//   - Manually trigger an alarm (hardware hook ready)
//   - Stop an active alarm (hardened, idempotent, audit)
//   - Stop ALL active triggers (with zone scope)
//   - Delete alarm (with trigger-history warning)
//   - View active alarm triggers
//   - Honors global settings: notify_on_alarm, items_per_page
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
// GLOBAL SETTINGS (from admin/settings.php)
// ============================================================
if (!function_exists('ws_alarm_setting')) {
    function ws_alarm_setting(string $key, $default = null) {
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

$setNotifyAlarm = (string) ws_alarm_setting('notify_on_alarm', '1') === '1';

$itemsPerPage = (int) ws_alarm_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;
$alarmsLimit   = $itemsPerPage;
$triggersLimit = max(5, min(30, $itemsPerPage));

// ============================================================
// NOTIFICATION GATE
// ============================================================
if (!function_exists('ws_alarm_notify')) {
    function ws_alarm_notify(int $userId, string $title, string $body): bool {
        // Respect notify_on_alarm global setting
        if ((string) ws_alarm_setting('notify_on_alarm', '1') !== '1') {
            return false;
        }
        if (function_exists('createNotification')) {
            try {
                createNotification($userId, 'system_alert', $title, $body, null);
                return true;
            } catch (Throwable $e) {
                error_log('[WS-ALARM] notify failed: ' . $e->getMessage());
                return false;
            }
        }
        return false;
    }
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- CREATE ALARM --------
    if ($action === 'create') {
        $zoneId    = (int)($_POST['zone_id'] ?? 0);
        $alarmName = trim($_POST['alarm_name'] ?? '');
        $alarmType = $_POST['alarm_type'] ?? 'siren';
        $lat       = ($_POST['location_lat'] ?? '') !== '' ? (float)$_POST['location_lat'] : null;
        $lng       = ($_POST['location_lng'] ?? '') !== '' ? (float)$_POST['location_lng'] : null;

        if (!$zoneId || !$alarmName) {
            $message = 'Zone and alarm name are required.';
            $messageType = 'danger';
        } elseif (!in_array($alarmType, ['siren','bell','strobe','speaker','combined'], true)) {
            $message = 'Invalid alarm type.';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO alarm_systems
                        (zone_id, alarm_name, alarm_type, location_lat, location_lng,
                         is_active, trigger_count, created_at)
                    VALUES (?, ?, ?, ?, ?, 1, 0, NOW())
                ");
                $stmt->execute([$zoneId, $alarmName, $alarmType, $lat, $lng]);
                $newId = (int)$pdo->query('SELECT lastval()')->fetchColumn();
                logAudit($user['id'], 'create_alarm', ['alarm_id' => $newId, 'zone_id' => $zoneId]);
                $message = "✅ Alarm '{$alarmName}' added successfully.";
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // -------- UPDATE ALARM --------
    if ($action === 'update') {
        $id        = (int)($_POST['alarm_id'] ?? 0);
        $zoneId    = (int)($_POST['zone_id'] ?? 0);
        $alarmName = trim($_POST['alarm_name'] ?? '');
        $alarmType = $_POST['alarm_type'] ?? 'siren';
        $lat       = ($_POST['location_lat'] ?? '') !== '' ? (float)$_POST['location_lat'] : null;
        $lng       = ($_POST['location_lng'] ?? '') !== '' ? (float)$_POST['location_lng'] : null;

        if (!$id || !$zoneId || !$alarmName) {
            $message = 'Zone and alarm name are required.';
            $messageType = 'danger';
        } elseif (!in_array($alarmType, ['siren','bell','strobe','speaker','combined'], true)) {
            $message = 'Invalid alarm type.';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE alarm_systems SET
                        zone_id = ?, alarm_name = ?, alarm_type = ?,
                        location_lat = ?, location_lng = ?
                    WHERE id = ?
                ");
                $stmt->execute([$zoneId, $alarmName, $alarmType, $lat, $lng, $id]);
                logAudit($user['id'], 'update_alarm', ['alarm_id' => $id, 'name' => $alarmName]);
                $message = "✅ Alarm '{$alarmName}' updated successfully.";
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // -------- TOGGLE ACTIVE --------
    if ($action === 'toggle') {
        $id = (int)($_POST['alarm_id'] ?? 0);
        try {
            $row = $pdo->prepare("SELECT alarm_name, is_active FROM alarm_systems WHERE id = ? LIMIT 1");
            $row->execute([$id]);
            $a = $row->fetch();

            if (!$a) {
                $message = '❌ Alarm not found.';
                $messageType = 'danger';
            } else {
                $pdo->prepare("UPDATE alarm_systems SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
                $newState = ((int)$a['is_active']) === 1 ? 'disabled' : 'enabled';
                logAudit($user['id'], 'toggle_alarm', ['alarm_id' => $id, 'new_state' => $newState]);
                $message = "✅ Alarm '{$a['alarm_name']}' {$newState}.";
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // -------- MANUAL TRIGGER --------
    if ($action === 'trigger') {
        $id = (int)($_POST['alarm_id'] ?? 0);
        try {
            $alarm = $pdo->prepare("SELECT * FROM alarm_systems WHERE id = ? LIMIT 1");
            $alarm->execute([$id]);
            $a = $alarm->fetch();

            if (!$a) {
                $message = '❌ Alarm not found.';
                $messageType = 'danger';
            } elseif ((int)$a['is_active'] !== 1) {
                $message = "❌ Alarm '{$a['alarm_name']}' is inactive. Enable it first.";
                $messageType = 'danger';
            } else {
                // Prevent duplicate active trigger on same alarm
                $dup = $pdo->prepare("SELECT id FROM alarm_triggers WHERE alarm_id = ? AND stopped_at IS NULL LIMIT 1");
                $dup->execute([$id]);
                if ($dup->fetch()) {
                    $message = "ℹ️ Alarm '{$a['alarm_name']}' is already active. Stop it first.";
                    $messageType = 'warning';
                } else {
                    $pdo->prepare("
                        INSERT INTO alarm_triggers
                            (alarm_id, zone_id, triggered_by, trigger_reason, triggered_at)
                        VALUES (?, ?, 'manual', 'Manual trigger by admin', NOW())
                    ")->execute([$id, $a['zone_id']]);
                    $triggerId = (int)$pdo->query('SELECT lastval()')->fetchColumn();

                    $pdo->prepare("
                        UPDATE alarm_systems
                        SET last_triggered = NOW(), trigger_count = trigger_count + 1
                        WHERE id = ?
                    ")->execute([$id]);

                    // Best-effort hardware signal
                    if (function_exists('ws_alarm_trigger_hardware')) {
                        try { ws_alarm_trigger_hardware((int)$a['id'], $triggerId); }
                        catch (Throwable $e) { error_log('[WS-ALARM] trigger hardware: ' . $e->getMessage()); }
                    }

                    // Notify rangers + supervisors in the zone (respects notify_on_alarm)
                    $notified = 0; $suppressed = 0;
                    $recipients = $pdo->prepare("
                        SELECT id FROM users
                        WHERE zone_id = ? AND role IN ('ranger','zone_supervisor') AND is_active = 1
                    ");
                    $recipients->execute([$a['zone_id']]);
                    foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                        if (ws_alarm_notify((int)$uid, '🔔 Alarm Triggered', "{$a['alarm_name']} triggered manually.")) {
                            $notified++;
                        } else {
                            $suppressed++;
                        }
                    }

                    // WebSocket broadcast
                    if (function_exists('broadcastToWS')) {
                        try {
                            broadcastToWS('alarm-triggered', [
                                'zone_id'    => (int)$a['zone_id'],
                                'alarm_id'   => (int)$a['id'],
                                'alarm_name' => $a['alarm_name'],
                                'trigger_id' => $triggerId,
                            ]);
                        } catch (Throwable $e) { /* silent */ }
                    }

                    logAudit($user['id'], 'trigger_alarm', [
                        'alarm_id'   => $id,
                        'trigger_id' => $triggerId,
                        'notified'   => $notified,
                    ]);

                    $notifyNote = $notified > 0
                        ? " • {$notified} ranger(s) notified"
                        : ($suppressed > 0 ? ' • notifications suppressed (notify_on_alarm = OFF)' : '');

                    $message = "🚨 Alarm '{$a['alarm_name']}' triggered manually{$notifyNote}.";
                }
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // -------- STOP ACTIVE TRIGGER (hardened) --------
    if ($action === 'stop_trigger') {
        $triggerId = (int)($_POST['trigger_id'] ?? 0);

        if ($triggerId <= 0) {
            $message = '❌ Invalid trigger ID.';
            $messageType = 'danger';
        } else {
            try {
                $row = $pdo->prepare("
                    SELECT at.id, at.alarm_id, at.zone_id, at.triggered_at, at.stopped_at,
                           a.alarm_name, z.name AS zone_name
                    FROM alarm_triggers at
                    LEFT JOIN alarm_systems a ON at.alarm_id = a.id
                    LEFT JOIN zones z ON at.zone_id = z.id
                    WHERE at.id = ?
                    LIMIT 1
                ");
                $row->execute([$triggerId]);
                $t = $row->fetch();

                if (!$t) {
                    $message = '❌ Trigger not found.';
                    $messageType = 'danger';
                } elseif (!empty($t['stopped_at'])) {
                    $message = 'ℹ️ This alarm was already stopped at ' . htmlspecialchars($t['stopped_at']) . '.';
                    $messageType = 'success';
                } else {
                    $stmt = $pdo->prepare('
                        UPDATE alarm_triggers SET
                            stopped_at = NOW(),
                            duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1),
                            was_acknowledged = 1,
                            acknowledged_by = ?,
                            acknowledged_at = NOW()
                        WHERE id = ? AND stopped_at IS NULL
                    ');
                    $stmt->execute([$user['id'], $triggerId]);
                    $affected = $stmt->rowCount();

                    if ($affected === 0) {
                        $message = 'ℹ️ Alarm was stopped by another admin just now.';
                        $messageType = 'success';
                    } else {
                        // Best-effort hardware signal
                        if (function_exists('ws_alarm_stop_hardware') && !empty($t['alarm_id'])) {
                            try { ws_alarm_stop_hardware((int)$t['alarm_id'], $triggerId); }
                            catch (Throwable $e) { error_log('[WS-ALARM] stop hardware: ' . $e->getMessage()); }
                        }

                        logAudit($user['id'], 'stop_alarm', [
                            'trigger_id' => $triggerId,
                            'alarm_id'   => $t['alarm_id'],
                            'alarm_name' => $t['alarm_name'],
                            'zone_id'    => $t['zone_id'],
                            'zone_name'  => $t['zone_name'],
                            'duration_s' => (int)(time() - strtotime($t['triggered_at'])),
                        ]);

                        $message = '✅ Alarm stopped'
                            . (!empty($t['alarm_name']) ? ' — ' . htmlspecialchars($t['alarm_name']) : '')
                            . (!empty($t['zone_name'])  ? ' (' . htmlspecialchars($t['zone_name']) . ')' : '');
                        $messageType = 'success';
                    }
                }
            } catch (PDOException $e) {
                error_log('[WS-ALARM] stop_trigger failed: ' . $e->getMessage());
                $message = '❌ Could not stop alarm. Please try again.';
                $messageType = 'danger';
            }
        }
    }

    // -------- STOP ALL ACTIVE TRIGGERS --------
    if ($action === 'stop_all_triggers') {
        $scopeZone = (int)($_POST['scope_zone'] ?? 0);
        try {
            $where  = "stopped_at IS NULL";
            $params = [];
            if ($scopeZone > 0) {
                $where   .= " AND zone_id = ?";
                $params[] = $scopeZone;
            }

            $before = safeFetchAll($pdo, "
                SELECT at.id, at.alarm_id, at.zone_id, a.alarm_name, z.name AS zone_name
                FROM alarm_triggers at
                LEFT JOIN alarm_systems a ON at.alarm_id = a.id
                LEFT JOIN zones z ON at.zone_id = z.id
                WHERE $where
            ", $params);

            if (empty($before)) {
                $message = 'ℹ️ No active triggers to stop.';
                $messageType = 'success';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE alarm_triggers SET
                        stopped_at = NOW(),
                        duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1),
                        was_acknowledged = 1,
                        acknowledged_by = ?,
                        acknowledged_at = NOW()
                    WHERE $where
                ");
                $stmt->execute(array_merge([$user['id']], $params));
                $count = $stmt->rowCount();

                if (function_exists('ws_alarm_stop_hardware')) {
                    foreach ($before as $b) {
                        if (!empty($b['alarm_id'])) {
                            try { ws_alarm_stop_hardware((int)$b['alarm_id'], (int)$b['id']); }
                            catch (Throwable $e) { error_log('[WS-ALARM] stop_all hardware: ' . $e->getMessage()); }
                        }
                    }
                }

                logAudit($user['id'], 'stop_all_triggers', [
                    'count'      => $count,
                    'scope_zone' => $scopeZone,
                    'triggers'   => array_column($before, 'id'),
                ]);

                $message = '✅ Stopped ' . $count . ' active alarm' . ($count === 1 ? '' : 's') . '.';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            error_log('[WS-ALARM] stop_all_triggers failed: ' . $e->getMessage());
            $message = '❌ Could not stop alarms. Please try again.';
            $messageType = 'danger';
        }
    }

    // -------- DELETE --------
    if ($action === 'delete') {
        $id = (int)($_POST['alarm_id'] ?? 0);

        try {
            // Check for active triggers first — don't delete while alarm is sounding
            $activeCount = safeCount($pdo, "
                SELECT COUNT(*) AS count FROM alarm_triggers
                WHERE alarm_id = ? AND stopped_at IS NULL
            ", [$id]);

            if ($activeCount > 0) {
                $message = "❌ Cannot delete — this alarm has {$activeCount} active trigger(s). Stop them first.";
                $messageType = 'danger';
            } else {
                $row = $pdo->prepare("SELECT alarm_name FROM alarm_systems WHERE id = ? LIMIT 1");
                $row->execute([$id]);
                $a = $row->fetch();

                if (!$a) {
                    $message = '❌ Alarm not found.';
                    $messageType = 'danger';
                } else {
                    $pdo->prepare("DELETE FROM alarm_systems WHERE id = ?")->execute([$id]);
                    logAudit($user['id'], 'delete_alarm', ['alarm_id' => $id, 'name' => $a['alarm_name']]);
                    $message = "🗑️ Alarm '{$a['alarm_name']}' deleted.";
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

if ($filterZone > 0) { $where .= " AND a.zone_id = ? "; $params[] = $filterZone; }
if ($filterStatus === 'active')   $where .= " AND a.is_active = 1 ";
if ($filterStatus === 'inactive') $where .= " AND a.is_active = 0 ";
if ($filterType !== '' && in_array($filterType, ['siren','bell','strobe','speaker','combined'], true)) {
    $where .= " AND a.alarm_type = ? ";
    $params[] = $filterType;
}
if ($search !== '') {
    $where .= " AND a.alarm_name LIKE ? ";
    $params[] = '%' . $search . '%';
}

// ============================================================
// FETCH ALARMS
// ============================================================
$alarms = safeFetchAll($pdo, "
    SELECT a.*, z.name AS zone_name, z.park_type,
           (SELECT COUNT(*) FROM alarm_triggers at WHERE at.alarm_id = a.id AND at.stopped_at IS NULL) AS active_triggers
    FROM alarm_systems a
    JOIN zones z ON a.zone_id = z.id
    $where
    ORDER BY a.is_active DESC, a.alarm_name
    LIMIT " . (int)$alarmsLimit . "
", $params);

// ============================================================
// FETCH ACTIVE TRIGGERS
// ============================================================
$activeTriggers = safeFetchAll($pdo, "
    SELECT at.*, a.alarm_name, z.name AS zone_name
    FROM alarm_triggers at
    JOIN alarm_systems a ON at.alarm_id = a.id
    JOIN zones z ON at.zone_id = z.id
    WHERE at.stopped_at IS NULL
    ORDER BY at.triggered_at DESC
    LIMIT " . (int)$triggersLimit . "
");

// ============================================================
// STATS
// ============================================================
$stats = [
    'total'           => safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_systems"),
    'active'          => safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_systems WHERE is_active = 1"),
    'inactive'        => safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_systems WHERE is_active = 0"),
    'active_triggers' => safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_triggers WHERE stopped_at IS NULL"),
    'today_triggers'  => safeCount($pdo, 'SELECT COUNT(*) as count FROM alarm_triggers WHERE DATE(triggered_at) = CURRENT_DATE'),
    'total_triggers'  => safeCount($pdo, "SELECT COUNT(*) as count FROM alarm_triggers"),
];

// ============================================================
// ZONES
// ============================================================
$zones = safeFetchAll($pdo, "SELECT id, name, park_type FROM zones WHERE is_active = 1 ORDER BY name");

// ============================================================
// ICON MAP
// ============================================================
$typeIcons = [
    'siren'    => '🚨',
    'bell'     => '🔔',
    'strobe'   => '💡',
    'speaker'  => '📢',
    'combined' => '🚨🔔',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Alarm Systems - Admin - Wildlife Sentinel</title>

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
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        /* Table */
        .alarm-table { width: 100%; border-collapse: collapse; }
        .alarm-table thead th {
            text-align: left; font-size: 11px; color: #6c757d;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 10px 12px; border-bottom: 2px solid #f0f0f0;
            background: #fafafa; font-weight: 700;
        }
        .alarm-table tbody tr { border-bottom: 1px solid #f5f5f5; transition: background 0.15s; }
        .alarm-table tbody tr:hover { background: #fafafa; }
        .alarm-table td { padding: 12px; font-size: 13px; vertical-align: middle; }

        .alarm-icon {
            width: 38px; height: 38px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px; background: #fdf5f5; flex-shrink: 0;
        }

        .status-pill {
            padding: 3px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; display: inline-block;
        }
        .status-pill.active   { background: #d4edda; color: #155724; }
        .status-pill.inactive { background: #e9ecef; color: #495057; }
        .status-pill.triggered { background: #dc3545; color: white; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }

        /* Active trigger card */
        .trigger-card {
            padding: 14px 18px; border-radius: 12px;
            background: #fdf5f5; border-left: 5px solid #dc3545;
            margin-bottom: 12px; display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .trigger-card .trigger-icon { font-size: 28px; }
        .trigger-card .trigger-info { flex: 1; min-width: 200px; }
        .trigger-card .trigger-info .trigger-title { font-weight: 700; font-size: 14px; color: #0d3b22; }
        .trigger-card .trigger-info .trigger-meta { font-size: 12px; color: #6c757d; margin-top: 2px; }

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
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
            transition: all 0.2s; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        @media (max-width: 1024px) {
            .alarm-table thead { display: none; }
            .alarm-table, .alarm-table tbody, .alarm-table tr, .alarm-table td { display: block; width: 100%; }
            .alarm-table tr { margin-bottom: 12px; padding: 12px; border-radius: 10px; background: #fafafa; border: 1px solid #f0f0f0; }
            .alarm-table td { padding: 4px 0; border: none; }
            .alarm-table td::before { content: attr(data-label); font-size: 10px; text-transform: uppercase; color: #adb5bd; display: block; margin-bottom: 2px; }
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
                <h1>Alarm Systems</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🔔 Alarm Systems</h1>
                    <p>Manage zone alarms, monitor active triggers, and test responses.</p>
                </div>

                <!-- Quick nav -->
                <div class="quick-nav">
                    <a href="ai-dashboard.php" class="btn btn-secondary">🤖 AI Dashboard</a>
                    <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                    <a href="sms-gateway.php" class="btn btn-secondary">📱 SMS Gateway</a>
                    <a href="simulation.php" class="btn btn-secondary">🎮 Simulation</a>
                    <a href="settings.php" class="btn btn-secondary">⚙️ System Settings</a>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= htmlspecialchars($messageType) ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- Settings echo -->
                <div class="settings-echo">
                    <strong>🧾 Alarm behaviour governed by System Settings:</strong>
                    Alarm notifications:
                    <strong><?= $setNotifyAlarm ? 'ON' : '<span class="off">OFF</span>' ?></strong>
                    • Manual triggers always allowed regardless of settings
                    • AI auto-trigger setting: <code>ai_auto_trigger_alarm</code> (applies to AI dashboard, not this page)
                    <a href="settings.php" style="color:inherit;text-decoration:underline;">Change</a>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon blue">🔔</div><div class="info"><div class="number"><?= $stats['total'] ?></div><div class="label">Total Alarms</div></div></div>
                    <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= $stats['active'] ?></div><div class="label">Active</div></div></div>
                    <div class="stat-card"><div class="icon orange">⚪</div><div class="info"><div class="number"><?= $stats['inactive'] ?></div><div class="label">Inactive</div></div></div>
                    <div class="stat-card"><div class="icon red">🚨</div><div class="info"><div class="number"><?= $stats['active_triggers'] ?></div><div class="label">Active Triggers</div></div></div>
                    <div class="stat-card"><div class="icon purple">📅</div><div class="info"><div class="number"><?= $stats['today_triggers'] ?></div><div class="label">Today</div></div></div>
                    <div class="stat-card"><div class="icon blue">📊</div><div class="info"><div class="number"><?= $stats['total_triggers'] ?></div><div class="label">Total Triggers</div></div></div>
                </div>

                <!-- Active Triggers -->
                <div class="section" style="border-left:4px solid <?= count($activeTriggers) > 0 ? '#dc3545' : '#adb5bd' ?>;">
                    <div class="section-header">
                        <h2>
                            🚨 Active Triggers
                            <?php if (count($activeTriggers) > 0): ?>
                                <span class="status-pill triggered"><?= count($activeTriggers) ?> LIVE</span>
                            <?php else: ?>
                                <span class="status-pill active">0</span>
                            <?php endif; ?>
                        </h2>
                        <div class="header-actions">
                            <?php if (count($activeTriggers) > 0): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('⏹️ Stop ALL active alarms? This cannot be undone.');">
                                    <input type="hidden" name="action" value="stop_all_triggers">
                                    <input type="hidden" name="scope_zone" value="0">
                                    <button class="btn btn-danger btn-sm">⏹️ Stop All</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (count($activeTriggers) > 0): ?>
                        <?php foreach ($activeTriggers as $t): ?>
                            <div class="trigger-card">
                                <div class="trigger-icon">🚨</div>
                                <div class="trigger-info">
                                    <div class="trigger-title"><?= htmlspecialchars($t['alarm_name']) ?></div>
                                    <div class="trigger-meta">
                                        📍 <?= htmlspecialchars($t['zone_name']) ?>
                                        • 👤 <?= htmlspecialchars(ucfirst($t['triggered_by'])) ?>
                                        • 🕐 <?= timeAgo($t['triggered_at']) ?>
                                        • running <?= max(0, time() - strtotime($t['triggered_at'])) ?>s
                                        <?php if ($t['trigger_reason']): ?>
                                            • 📝 <?= htmlspecialchars($t['trigger_reason']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('⏹️ Stop this alarm?');">
                                    <input type="hidden" name="action" value="stop_trigger">
                                    <input type="hidden" name="trigger_id" value="<?= (int)$t['id'] ?>">
                                    <button class="btn btn-danger btn-sm">⏹️ Stop Alarm</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">✅</span>
                            <h3>No active alarms</h3>
                            <p>All quiet. Alarms triggered manually or by AI will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Filters -->
                <div class="section">
                    <div class="section-header">
                        <h2>🔎 Filter Alarms</h2>
                        <?php if ($filterZone || $filterStatus || $filterType || $search): ?>
                            <a href="alarm-systems.php" class="btn btn-secondary btn-sm">✕ Clear filters</a>
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
                                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Type</label>
                            <select name="type">
                                <option value="">All Types</option>
                                <option value="siren"    <?= $filterType === 'siren'    ? 'selected' : '' ?>>Siren</option>
                                <option value="bell"     <?= $filterType === 'bell'     ? 'selected' : '' ?>>Bell</option>
                                <option value="strobe"   <?= $filterType === 'strobe'   ? 'selected' : '' ?>>Strobe</option>
                                <option value="speaker"  <?= $filterType === 'speaker'  ? 'selected' : '' ?>>Speaker</option>
                                <option value="combined" <?= $filterType === 'combined' ? 'selected' : '' ?>>Combined</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Alarm name">
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">🔎 Apply</button>
                        </div>
                    </form>
                </div>

                <!-- Alarm List -->
                <div class="section">
                    <div class="section-header">
                        <h2>📋 Alarms (<?= count($alarms) ?>)</h2>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <i class="fas fa-plus"></i> Add Alarm
                        </button>
                    </div>

                    <?php if (count($alarms) > 0): ?>
                        <table class="alarm-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;"></th>
                                    <th>Alarm</th>
                                    <th>Zone</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Triggered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alarms as $a): ?>
                                    <?php $icon = $typeIcons[$a['alarm_type']] ?? '🔔'; ?>
                                    <tr>
                                        <td data-label="">
                                            <span class="alarm-icon"><?= $icon ?></span>
                                        </td>
                                        <td data-label="Alarm">
                                            <div style="font-weight:600;color:#0d3b22;font-size:13px;">
                                                <?= htmlspecialchars($a['alarm_name']) ?>
                                            </div>
                                        </td>
                                        <td data-label="Zone">
                                            <div style="font-size:12px;"><?= htmlspecialchars($a['zone_name']) ?></div>
                                            <div style="font-size:10px;color:#6c757d;text-transform:uppercase;">
                                                <?= htmlspecialchars(str_replace('_', ' ', $a['park_type'])) ?>
                                            </div>
                                        </td>
                                        <td data-label="Type">
                                            <span style="font-size:11px;text-transform:uppercase;">
                                                <?= htmlspecialchars($a['alarm_type']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <?php if (!$a['is_active']): ?>
                                                <span class="status-pill inactive">⚪ Inactive</span>
                                            <?php elseif ($a['active_triggers'] > 0): ?>
                                                <span class="status-pill triggered">🚨 TRIGGERED</span>
                                            <?php else: ?>
                                                <span class="status-pill active">✅ Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Triggered">
                                            <?php if ($a['last_triggered']): ?>
                                                <div style="font-size:12px;"><?= timeAgo($a['last_triggered']) ?></div>
                                                <div style="font-size:10px;color:#6c757d;">
                                                    <?= (int)$a['trigger_count'] ?> total
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size:11px;color:#adb5bd;">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <button class="btn btn-sm btn-secondary" onclick='openEditModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)'>✏️ Edit</button>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="trigger">
                                                    <input type="hidden" name="alarm_id" value="<?= (int)$a['id'] ?>">
                                                    <button class="btn btn-sm btn-danger"
                                                            <?= ((int)$a['is_active'] !== 1 || (int)$a['active_triggers'] > 0) ? 'disabled' : '' ?>
                                                            onclick="return confirm('🚨 Trigger this alarm manually?')">
                                                        🚨 Trigger
                                                    </button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="alarm_id" value="<?= (int)$a['id'] ?>">
                                                    <button class="btn btn-sm <?= $a['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                                                        <?= $a['is_active'] ? 'Disable' : 'Enable' ?>
                                                    </button>
                                                </form>
                                                <button class="btn btn-sm btn-danger"
                                                        <?= ((int)$a['active_triggers'] > 0) ? 'disabled title="Stop active triggers first"' : '' ?>
                                                        onclick="confirmDelete(<?= (int)$a['id'] ?>, '<?= htmlspecialchars($a['alarm_name'], ENT_QUOTES) ?>')">🗑️</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">🔔</span>
                            <h3>No alarms found</h3>
                            <p>Click "Add Alarm" to create your first alarm system.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- CREATE/EDIT MODAL -->
    <div class="modal-backdrop" id="alarmModal">
        <div class="modal">
            <form method="POST" id="alarmForm">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="alarm_id" id="formAlarmId" value="">

                <div class="modal-header">
                    <h3 id="modalTitle">➕ Add Alarm</h3>
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
                            <label>Alarm Type *</label>
                            <select name="alarm_type" id="formAlarmType" required>
                                <option value="siren">🚨 Siren</option>
                                <option value="bell">🔔 Bell</option>
                                <option value="strobe">💡 Strobe</option>
                                <option value="speaker">📢 Speaker</option>
                                <option value="combined">🚨🔔 Combined</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Alarm Name *</label>
                        <input type="text" name="alarm_name" id="formAlarmName" required placeholder="e.g. North Gate Siren">
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
                    <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Save Alarm</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE FORM -->
    <form method="POST" id="deleteForm" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="alarm_id" id="deleteAlarmId">
    </form>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = '➕ Add Alarm';
            document.getElementById('formAction').value = 'create';
            document.getElementById('formAlarmId').value = '';
            document.getElementById('alarmForm').reset();
            document.getElementById('formAlarmType').value = 'siren';
            document.getElementById('modalSubmitBtn').textContent = 'Add Alarm';
            document.getElementById('alarmModal').classList.add('show');
        }

        function openEditModal(a) {
            document.getElementById('modalTitle').textContent = '✏️ Edit Alarm';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formAlarmId').value = a.id;
            document.getElementById('formZoneId').value = a.zone_id;
            document.getElementById('formAlarmType').value = a.alarm_type || 'siren';
            document.getElementById('formAlarmName').value = a.alarm_name || '';
            document.getElementById('formLat').value = a.location_lat || '';
            document.getElementById('formLng').value = a.location_lng || '';
            document.getElementById('modalSubmitBtn').textContent = 'Save Changes';
            document.getElementById('alarmModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('alarmModal').classList.remove('show');
        }

        function confirmDelete(id, name) {
            if (confirm('Delete alarm "' + name + '"?\n\nThis will also remove its trigger history. This cannot be undone.')) {
                document.getElementById('deleteAlarmId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        document.getElementById('alarmModal').addEventListener('click', function (e) {
            if (e.target === this) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>
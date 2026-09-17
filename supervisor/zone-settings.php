<?php
// ============================================================
// supervisor/zone-settings.php
// Zone Supervisor — Zone System Settings
// ------------------------------------------------------------
// Sections:
//   - Zone Information (name, description, buffer)
//   - AI Detection Settings (enable, thresholds, auto-actions)
//   - CCTV Defaults (retention, storage, quality)
//   - Alarm System Defaults (delay, auto-trigger, siren duration)
//   - Notification Channels (SMS, email, push)
//   - Operational Permissions (what rangers/scouts can do)
//   - Global Overrides (read-only — admin-enforced settings)
//   - Danger Zone (reset zone settings to defaults)
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!function_exists('hasRole') || !hasRole('zone_supervisor')) {
    header('Location: ../index.php');
    exit();
}

$user         = getCurrentUser();
$pdo          = getDB();
$activeZoneId = (int)($user['zone_id'] ?? 0);

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
if (!function_exists('safeExec')) {
    function safeExec(PDO $pdo, string $sql, array $params = []): bool {
        try { $stmt = $pdo->prepare($sql); return $stmt->execute($params); }
        catch (PDOException $e) { return false; }
    }
}

// ============================================================
// GLOBAL SETTINGS (admin-enforced — read-only for supervisors)
// ============================================================
if (!function_exists('ws_zs_global')) {
    function ws_zs_global(string $key, $default = null) {
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

$globalAiEnabled       = (string) ws_zs_global('ai_enabled', '1')             === '1';
$globalSmsEnabled      = (string) ws_zs_global('sms_enabled', '1')            === '1';
$globalNotifyIncident  = (string) ws_zs_global('notify_on_incident', '1')     === '1';
$globalNotifyAiAlert   = (string) ws_zs_global('notify_on_ai_alert', '1')     === '1';
$globalNotifyAlarm     = (string) ws_zs_global('notify_on_alarm', '1')        === '1';
$globalNotifyManpower  = (string) ws_zs_global('notify_on_manpower', '1')     === '1';
$globalAiConfidence    = (int)    ws_zs_global('ai_confidence_min', 70);
$globalCctvRetention   = (int)    ws_zs_global('cctv_retention_days', 30);
$globalCctvSnapshotDir = (string) ws_zs_global('cctv_snapshot_dir', 'uploads/cctv/');

// ============================================================
// AUTO-CREATE TABLES
// ============================================================
try {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS zone_notification_settings (
            zone_id INTEGER PRIMARY KEY,
            sms_enabled SMALLINT DEFAULT 1,
            alarm_enabled SMALLINT DEFAULT 1,
            ai_detection_enabled SMALLINT DEFAULT 1,
            alarm_delay_seconds INT DEFAULT 120,
            ai_confidence_threshold INT DEFAULT 70,
            auto_create_incidents SMALLINT DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS zone_system_settings (
            zone_id INTEGER PRIMARY KEY,
            ai_enabled SMALLINT DEFAULT 1,
            ai_confidence_min INT DEFAULT 70,
            ai_auto_create_alert SMALLINT DEFAULT 1,
            ai_auto_trigger_alarm SMALLINT DEFAULT 0,
            ai_detection_types VARCHAR(255) DEFAULT \'human,animal,vehicle,fire,gunshot\',
            cctv_retention_days INT DEFAULT 30,
            cctv_default_quality VARCHAR(20) DEFAULT \'1080p\',
            cctv_auto_record SMALLINT DEFAULT 1,
            cctv_snapshot_dir VARCHAR(255) DEFAULT \'uploads/cctv/\',
            alarm_default_type VARCHAR(20) DEFAULT \'siren\',
            alarm_siren_duration INT DEFAULT 180,
            alarm_auto_stop SMALLINT DEFAULT 1,
            alarm_sms_blast SMALLINT DEFAULT 1,
            notif_sms SMALLINT DEFAULT 1,
            notif_email SMALLINT DEFAULT 0,
            notif_push SMALLINT DEFAULT 1,
            notif_on_incident SMALLINT DEFAULT 1,
            notif_on_ai_alert SMALLINT DEFAULT 1,
            notif_on_alarm SMALLINT DEFAULT 1,
            notif_on_manpower SMALLINT DEFAULT 1,
            notif_offline_reminder SMALLINT DEFAULT 1,
            perm_rangers_ack SMALLINT DEFAULT 1,
            perm_rangers_trigger_alarm SMALLINT DEFAULT 0,
            perm_rangers_request_manpower SMALLINT DEFAULT 1,
            perm_scouts_report SMALLINT DEFAULT 1,
            perm_scouts_see_sensitive SMALLINT DEFAULT 0,
            perm_tourism_see_risk SMALLINT DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} catch (PDOException $e) {
    error_log('[WS-ZS] DDL: ' . $e->getMessage());
}

// ============================================================
// HELPERS
// ============================================================
if (!function_exists('ws_zs_sanitize_dir')) {
    function ws_zs_sanitize_dir(string $dir): string {
        // Only allow relative paths under uploads/ with alnum, dash, underscore, and slash
        $dir = trim($dir);
        $dir = preg_replace('#\.\.+#', '', $dir); // strip ".."
        $dir = preg_replace('#[^a-zA-Z0-9/_\-]#', '', $dir);
        $dir = trim($dir, '/');
        if ($dir === '' || strpos($dir, 'uploads/') !== 0) {
            $dir = 'uploads/cctv/';
        } else {
            $dir .= '/';
        }
        return $dir;
    }
}

// ============================================================
// HANDLE SAVE
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- UPDATE ZONE BASIC INFO --------
    if ($action === 'update_zone') {
        $name        = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $buffer      = max(100, min(5000, (int)($_POST['buffer_radius'] ?? 500)));

        if ($name === '') {
            $message = 'Zone name is required.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->prepare("
                    UPDATE zones SET name = ?, description = ?, buffer_radius = ?
                    WHERE id = ?
                ")->execute([$name, $description, $buffer, $activeZoneId]);
                logAudit($user['id'], 'update_zone', ['zone_id' => $activeZoneId, 'name' => $name]);
                $message = '✅ Zone information updated.';
            } catch (PDOException $e) {
                error_log('[WS-ZS] update_zone: ' . $e->getMessage());
                $message = 'Could not update zone information. Please try again.';
                $messageType = 'danger';
            }
        }
    }

    // -------- UPDATE AI SETTINGS --------
    if ($action === 'update_ai') {
        $enabled   = isset($_POST['ai_enabled']) ? 1 : 0;
        $minConf   = max(0, min(100, (int)($_POST['ai_confidence_min'] ?? 70)));
        $autoAlert = isset($_POST['ai_auto_create_alert']) ? 1 : 0;
        $autoAlarm = isset($_POST['ai_auto_trigger_alarm']) ? 1 : 0;

        $typesArr = is_array($_POST['ai_detection_types'] ?? null) ? $_POST['ai_detection_types'] : [];
        $typesArr = array_values(array_unique(array_map('strval', $typesArr)));
        $types    = implode(',', array_intersect($typesArr, ['human','animal','vehicle','fire','gunshot']));

        if ($enabled === 1 && !$globalAiEnabled) {
            $message = '⚠️ AI is disabled globally. Your zone setting was saved but the pipeline stays off until an admin enables it.';
        }

        try {
            $pdo->prepare('
                INSERT INTO zone_system_settings
                    (zone_id, ai_enabled, ai_confidence_min, ai_auto_create_alert,
                     ai_auto_trigger_alarm, ai_detection_types, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (zone_id) DO UPDATE SET 
                    ai_enabled = EXCLUDED.ai_enabled,
                    ai_confidence_min = EXCLUDED.ai_confidence_min,
                    ai_auto_create_alert = EXCLUDED.ai_auto_create_alert,
                    ai_auto_trigger_alarm = EXCLUDED.ai_auto_trigger_alarm,
                    ai_detection_types = EXCLUDED.ai_detection_types,
                    updated_at = NOW()
            ')->execute([$activeZoneId, $enabled, $minConf, $autoAlert, $autoAlarm, $types]);

            logAudit($user['id'], 'update_zone_settings', ['zone_id' => $activeZoneId, 'section' => 'ai']);
            if (!$message) $message = '✅ AI settings saved.';
        } catch (PDOException $e) {
            error_log('[WS-ZS] update_ai: ' . $e->getMessage());
            $message = 'Could not save AI settings.';
            $messageType = 'danger';
        }
    }

    // -------- UPDATE CCTV SETTINGS --------
    if ($action === 'update_cctv') {
        $retention   = max(1, min(365, (int)($_POST['cctv_retention_days'] ?? 30)));
        $quality     = (string)($_POST['cctv_default_quality'] ?? '1080p');
        $autoRecord  = isset($_POST['cctv_auto_record']) ? 1 : 0;
        $snapshotDir = ws_zs_sanitize_dir((string)($_POST['cctv_snapshot_dir'] ?? 'uploads/cctv/'));

        if (!in_array($quality, ['720p','1080p','1440p','4K'], true)) $quality = '1080p';

        try {
            $pdo->prepare('
                INSERT INTO zone_system_settings
                    (zone_id, cctv_retention_days, cctv_default_quality,
                     cctv_auto_record, cctv_snapshot_dir, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (zone_id) DO UPDATE SET 
                    cctv_retention_days = EXCLUDED.cctv_retention_days,
                    cctv_default_quality = EXCLUDED.cctv_default_quality,
                    cctv_auto_record = EXCLUDED.cctv_auto_record,
                    cctv_snapshot_dir = EXCLUDED.cctv_snapshot_dir,
                    updated_at = NOW()
            ')->execute([$activeZoneId, $retention, $quality, $autoRecord, $snapshotDir]);

            logAudit($user['id'], 'update_zone_settings', ['zone_id' => $activeZoneId, 'section' => 'cctv']);
            $message = '✅ CCTV settings saved.';
        } catch (PDOException $e) {
            error_log('[WS-ZS] update_cctv: ' . $e->getMessage());
            $message = 'Could not save CCTV settings.';
            $messageType = 'danger';
        }
    }

    // -------- UPDATE ALARM SETTINGS --------
    if ($action === 'update_alarms') {
        $defaultType = (string)($_POST['alarm_default_type'] ?? 'siren');
        $sirenDur    = max(10, min(3600, (int)($_POST['alarm_siren_duration'] ?? 180)));
        $autoStop    = isset($_POST['alarm_auto_stop']) ? 1 : 0;
        $smsBlast    = isset($_POST['alarm_sms_blast']) ? 1 : 0;
        $delay       = max(0, min(600, (int)($_POST['alarm_delay_seconds'] ?? 120)));

        if (!in_array($defaultType, ['siren','bell','strobe','speaker','combined'], true)) {
            $defaultType = 'siren';
        }

        try {
            $pdo->prepare('
                INSERT INTO zone_system_settings
                    (zone_id, alarm_default_type, alarm_siren_duration,
                     alarm_auto_stop, alarm_sms_blast, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (zone_id) DO UPDATE SET 
                    alarm_default_type = EXCLUDED.alarm_default_type,
                    alarm_siren_duration = EXCLUDED.alarm_siren_duration,
                    alarm_auto_stop = EXCLUDED.alarm_auto_stop,
                    alarm_sms_blast = EXCLUDED.alarm_sms_blast,
                    updated_at = NOW()
            ')->execute([$activeZoneId, $defaultType, $sirenDur, $autoStop, $smsBlast]);

            // Also update zone_notification_settings.alarm_delay_seconds
            $pdo->prepare('
                INSERT INTO zone_notification_settings (zone_id, alarm_delay_seconds, updated_at)
                VALUES (?, ?, NOW())
                 ON CONFLICT (zone_id) DO UPDATE SET 
                    alarm_delay_seconds = EXCLUDED.alarm_delay_seconds,
                    updated_at = NOW()
            ')->execute([$activeZoneId, $delay]);

            logAudit($user['id'], 'update_zone_settings', ['zone_id' => $activeZoneId, 'section' => 'alarms']);
            $message = '✅ Alarm settings saved.';
        } catch (PDOException $e) {
            error_log('[WS-ZS] update_alarms: ' . $e->getMessage());
            $message = 'Could not save alarm settings.';
            $messageType = 'danger';
        }
    }

    // -------- UPDATE NOTIFICATION CHANNELS --------
    if ($action === 'update_notif_channels') {
        $sms    = isset($_POST['notif_sms']) ? 1 : 0;
        $email  = isset($_POST['notif_email']) ? 1 : 0;
        $push   = isset($_POST['notif_push']) ? 1 : 0;
        $onInc  = isset($_POST['notif_on_incident']) ? 1 : 0;
        $onAI   = isset($_POST['notif_on_ai_alert']) ? 1 : 0;
        $onAlm  = isset($_POST['notif_on_alarm']) ? 1 : 0;
        $onManp = isset($_POST['notif_on_manpower']) ? 1 : 0;
        $offRem = isset($_POST['notif_offline_reminder']) ? 1 : 0;

        // Warn if a zone toggle is on but the global is off
        $warnings = [];
        if ($sms === 1 && !$globalSmsEnabled)              $warnings[] = 'SMS';
        if ($onInc === 1 && !$globalNotifyIncident)        $warnings[] = 'Incident notifications';
        if ($onAI  === 1 && !$globalNotifyAiAlert)         $warnings[] = 'AI alert notifications';
        if ($onAlm === 1 && !$globalNotifyAlarm)           $warnings[] = 'Alarm notifications';
        if ($onManp=== 1 && !$globalNotifyManpower)        $warnings[] = 'Manpower notifications';

        try {
            $pdo->prepare('
                INSERT INTO zone_system_settings
                    (zone_id, notif_sms, notif_email, notif_push,
                     notif_on_incident, notif_on_ai_alert, notif_on_alarm,
                     notif_on_manpower, notif_offline_reminder, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (zone_id) DO UPDATE SET 
                    notif_sms = EXCLUDED.notif_sms,
                    notif_email = EXCLUDED.notif_email,
                    notif_push = EXCLUDED.notif_push,
                    notif_on_incident = EXCLUDED.notif_on_incident,
                    notif_on_ai_alert = EXCLUDED.notif_on_ai_alert,
                    notif_on_alarm = EXCLUDED.notif_on_alarm,
                    notif_on_manpower = EXCLUDED.notif_on_manpower,
                    notif_offline_reminder = EXCLUDED.notif_offline_reminder,
                    updated_at = NOW()
            ')->execute([$activeZoneId, $sms, $email, $push, $onInc, $onAI, $onAlm, $onManp, $offRem]);

            logAudit($user['id'], 'update_zone_settings', ['zone_id' => $activeZoneId, 'section' => 'notif']);

            if (!empty($warnings)) {
                $message = '✅ Notification settings saved. ⚠️ Admin has globally disabled: '
                         . htmlspecialchars(implode(', ', $warnings)) . '.';
            } else {
                $message = '✅ Notification settings saved.';
            }
        } catch (PDOException $e) {
            error_log('[WS-ZS] update_notif: ' . $e->getMessage());
            $message = 'Could not save notification settings.';
            $messageType = 'danger';
        }
    }

    // -------- UPDATE OPERATIONAL PERMISSIONS --------
    if ($action === 'update_perms') {
        $rAck       = isset($_POST['perm_rangers_ack']) ? 1 : 0;
        $rTrig      = isset($_POST['perm_rangers_trigger_alarm']) ? 1 : 0;
        $rMan       = isset($_POST['perm_rangers_request_manpower']) ? 1 : 0;
        $sReport    = isset($_POST['perm_scouts_report']) ? 1 : 0;
        $sSensitive = isset($_POST['perm_scouts_see_sensitive']) ? 1 : 0;
        $tRisk      = isset($_POST['perm_tourism_see_risk']) ? 1 : 0;

        try {
            $pdo->prepare('
                INSERT INTO zone_system_settings
                    (zone_id, perm_rangers_ack, perm_rangers_trigger_alarm,
                     perm_rangers_request_manpower, perm_scouts_report,
                     perm_scouts_see_sensitive, perm_tourism_see_risk, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (zone_id) DO UPDATE SET 
                    perm_rangers_ack = EXCLUDED.perm_rangers_ack,
                    perm_rangers_trigger_alarm = EXCLUDED.perm_rangers_trigger_alarm,
                    perm_rangers_request_manpower = EXCLUDED.perm_rangers_request_manpower,
                    perm_scouts_report = EXCLUDED.perm_scouts_report,
                    perm_scouts_see_sensitive = EXCLUDED.perm_scouts_see_sensitive,
                    perm_tourism_see_risk = EXCLUDED.perm_tourism_see_risk,
                    updated_at = NOW()
            ')->execute([$activeZoneId, $rAck, $rTrig, $rMan, $sReport, $sSensitive, $tRisk]);

            logAudit($user['id'], 'update_zone_settings', ['zone_id' => $activeZoneId, 'section' => 'permissions']);
            $message = '✅ Operational permissions saved.';
        } catch (PDOException $e) {
            error_log('[WS-ZS] update_perms: ' . $e->getMessage());
            $message = 'Could not save permissions.';
            $messageType = 'danger';
        }
    }

    // -------- RESET ZONE SETTINGS --------
    if ($action === 'reset_settings') {
        try {
            $pdo->prepare("DELETE FROM zone_system_settings WHERE zone_id = ?")->execute([$activeZoneId]);
            $pdo->prepare("DELETE FROM zone_notification_settings WHERE zone_id = ?")->execute([$activeZoneId]);
            logAudit($user['id'], 'reset_zone_settings', ['zone_id' => $activeZoneId]);
            $message = '🧹 Zone settings reset to defaults. Admin-enforced global settings still apply.';
        } catch (PDOException $e) {
            error_log('[WS-ZS] reset: ' . $e->getMessage());
            $message = 'Could not reset settings.';
            $messageType = 'danger';
        }
    }
}

// ============================================================
// LOAD ZONE
// ============================================================
$zone = null;
if (function_exists('getZone')) {
    $zone = getZone($activeZoneId);
}
if (!$zone) {
    $rows = safeFetchAll($pdo, "SELECT * FROM zones WHERE id = ? LIMIT 1", [$activeZoneId]);
    $zone = $rows[0] ?? [];
}

// Compute buffer for map (if helper available)
if ($zone && !empty($zone['boundary_geojson']) && function_exists('computeBufferZone')) {
    $boundary = is_string($zone['boundary_geojson'])
        ? json_decode($zone['boundary_geojson'], true)
        : $zone['boundary_geojson'];
    if ($boundary) {
        $zone['boundary_geojson'] = $boundary;
        $zone['buffer_geojson']   = computeBufferZone($boundary, (int)($zone['buffer_radius'] ?? 500));
    }
}

// ============================================================
// LOAD ZONE SETTINGS (with defaults)
// ============================================================
$settings = [
    'ai_enabled'                    => 1,
    'ai_confidence_min'             => 70,
    'ai_auto_create_alert'          => 1,
    'ai_auto_trigger_alarm'         => 0,
    'ai_detection_types'            => 'human,animal,vehicle,fire,gunshot',
    'cctv_retention_days'           => 30,
    'cctv_default_quality'          => '1080p',
    'cctv_auto_record'              => 1,
    'cctv_snapshot_dir'             => 'uploads/cctv/',
    'alarm_default_type'            => 'siren',
    'alarm_siren_duration'          => 180,
    'alarm_auto_stop'               => 1,
    'alarm_sms_blast'               => 1,
    'alarm_delay_seconds'           => 120,
    'notif_sms'                     => 1,
    'notif_email'                   => 0,
    'notif_push'                    => 1,
    'notif_on_incident'             => 1,
    'notif_on_ai_alert'             => 1,
    'notif_on_alarm'                => 1,
    'notif_on_manpower'             => 1,
    'notif_offline_reminder'        => 1,
    'perm_rangers_ack'              => 1,
    'perm_rangers_trigger_alarm'    => 0,
    'perm_rangers_request_manpower' => 1,
    'perm_scouts_report'            => 1,
    'perm_scouts_see_sensitive'     => 0,
    'perm_tourism_see_risk'         => 1,
];

$settingsUpdatedAt = null;

try {
    $row = $pdo->prepare("SELECT * FROM zone_system_settings WHERE zone_id = ?");
    $row->execute([$activeZoneId]);
    $s = $row->fetch();
    if ($s) {
        foreach ($settings as $k => $_) {
            if (isset($s[$k])) $settings[$k] = $s[$k];
        }
        if (!empty($s['updated_at'])) $settingsUpdatedAt = $s['updated_at'];
    }

    $n = $pdo->prepare("SELECT alarm_delay_seconds, ai_confidence_threshold, updated_at FROM zone_notification_settings WHERE zone_id = ?");
    $n->execute([$activeZoneId]);
    $ns = $n->fetch();
    if ($ns) {
        if (isset($ns['alarm_delay_seconds']))     $settings['alarm_delay_seconds'] = $ns['alarm_delay_seconds'];
        if (isset($ns['ai_confidence_threshold'])) $settings['ai_confidence_min']  = $ns['ai_confidence_threshold'];
        if (!empty($ns['updated_at']) && (!$settingsUpdatedAt || $ns['updated_at'] > $settingsUpdatedAt)) {
            $settingsUpdatedAt = $ns['updated_at'];
        }
    }
} catch (PDOException $e) {
    error_log('[WS-ZS] load settings: ' . $e->getMessage());
}

// ============================================================
// ZONE STATS
// ============================================================
$zoneStats = ['rangers'=>0,'scouts'=>0,'incidents'=>0,'cameras'=>0,'alarms'=>0];
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM users WHERE zone_id = ? AND role = 'ranger' AND is_active = 1");
    $stmt->execute([$activeZoneId]);
    $zoneStats['rangers'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM users WHERE zone_id = ? AND role = 'scout' AND is_active = 1");
    $stmt->execute([$activeZoneId]);
    $zoneStats['scouts'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM incidents WHERE zone_id = ? AND status NOT IN ('resolved','closed')");
    $stmt->execute([$activeZoneId]);
    $zoneStats['incidents'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM cctv_cameras WHERE zone_id = ? AND is_active = 1");
    $stmt->execute([$activeZoneId]);
    $zoneStats['cameras'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM alarm_systems WHERE zone_id = ? AND is_active = 1");
    $stmt->execute([$activeZoneId]);
    $zoneStats['alarms'] = (int)$stmt->fetch()['c'];
} catch (PDOException $e) { /* non-fatal */ }

// ============================================================
// GLOBAL OVERRIDES — for the read-only summary panel
// ============================================================
$globalOverrides = [];
if (!$globalAiEnabled)      $globalOverrides[] = ['AI detection', 'DISABLED'];
if (!$globalSmsEnabled)     $globalOverrides[] = ['SMS', 'DISABLED'];
if (!$globalNotifyIncident) $globalOverrides[] = ['Incident notifications', 'SUPPRESSED'];
if (!$globalNotifyAiAlert)  $globalOverrides[] = ['AI alert notifications', 'SUPPRESSED'];
if (!$globalNotifyAlarm)    $globalOverrides[] = ['Alarm notifications', 'SUPPRESSED'];
if (!$globalNotifyManpower) $globalOverrides[] = ['Manpower notifications', 'SUPPRESSED'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zone System Settings - Supervisor</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 22px; }
        .dashboard-greeting h1 { font-size: 26px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 15px; }

        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .section-header .section-desc { font-size: 12px; color: #6c757d; margin-top: 2px; }
        .section-header .section-meta { font-size: 11px; color: #adb5bd; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert.danger  { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert.warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }

        /* Global overrides panel */
        .overrides-panel {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-left: 4px solid #ffc107;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #856404;
        }
        .overrides-panel strong { color: #664d03; }
        .overrides-panel ul { margin: 8px 0 0 20px; padding: 0; }
        .overrides-panel li { margin-bottom: 3px; }
        .overrides-panel code {
            background: rgba(255,255,255,.5);
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 11.5px;
        }

        /* Admin-enforced hint inside a section */
        .admin-fixed {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff3cd; color: #856404;
            padding: 4px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 700;
        }

        /* Form grid */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid.three { grid-template-columns: 1fr 1fr 1fr; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label {
            font-size: 12px; font-weight: 600; color: #495057;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .form-group .hint {
            font-size: 11px; color: #6c757d; font-weight: normal;
            text-transform: none; letter-spacing: 0;
        }
        .form-group input, .form-group select, .form-group textarea {
            padding: 10px 14px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 13px;
            background: #fafafa;
            font-family: inherit;
            transition: all 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #1a5c3a; background: white;
        }
        .form-group input:disabled,
        .form-group select:disabled { background: #f0f0f0; cursor: not-allowed; }

        /* Toggles */
        .toggle-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 0; border-bottom: 1px solid #f0f0f0;
        }
        .toggle-row:last-child { border-bottom: none; }
        .toggle-row .toggle-info { flex: 1; padding-right: 12px; }
        .toggle-row .toggle-label { font-size: 13px; font-weight: 600; color: #0d3b22; }
        .toggle-row .toggle-desc { font-size: 11px; color: #6c757d; margin-top: 2px; }

        .switch { position: relative; display: inline-block; width: 46px; height: 26px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch .slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc; transition: .3s; border-radius: 26px;
        }
        .switch .slider:before {
            position: absolute; content: "";
            height: 20px; width: 20px; left: 3px; bottom: 3px;
            background-color: white; transition: .3s; border-radius: 50%;
        }
        .switch input:checked + .slider { background-color: #28a745; }
        .switch input:checked + .slider:before { transform: translateX(20px); }
        .switch.disabled { opacity: 0.45; pointer-events: none; }

        /* Checkbox chips */
        .checkbox-group {
            display: flex; flex-wrap: wrap; gap: 10px; padding: 8px 0;
        }
        .checkbox-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; border-radius: 20px;
            border: 2px solid #e0e0e0; background: #fafafa;
            cursor: pointer; font-size: 13px; font-weight: 600;
            color: #495057; transition: all 0.2s;
            user-select: none;
        }
        .checkbox-chip input { display: none; }
        .checkbox-chip:hover { border-color: #1a5c3a; background: white; }
        .checkbox-chip.checked {
            border-color: #1a5c3a; background: #e8f5e9; color: #1a5c3a;
        }

        /* Danger zone */
        .danger-zone {
            border: 2px solid #dc3545; background: #fff5f5;
            border-radius: 14px; padding: 18px 22px; margin-bottom: 20px;
        }
        .danger-zone h2 { color: #dc3545; font-size: 16px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .danger-zone p { font-size: 12px; color: #6c757d; margin-bottom: 12px; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .form-grid, .form-grid.three { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Zone System Settings</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>⚙️ Zone System Settings</h1>
                    <p>Control AI, CCTV, alarms, notifications, and permissions for <strong><?= htmlspecialchars($zone['name'] ?? 'your zone') ?></strong>.</p>
                </div>

                <div class="quick-nav">
                    <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
                    <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                    <a href="ai-dashboard.php" class="btn btn-secondary">🤖 AI Dashboard</a>
                    <a href="rangers.php" class="btn btn-secondary">👥 Rangers</a>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= htmlspecialchars($messageType) ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- Global overrides panel -->
                <?php if (!empty($globalOverrides)): ?>
                    <div class="overrides-panel">
                        <strong>⚠️ Admin-enforced settings currently overriding your zone:</strong>
                        <ul>
                            <?php foreach ($globalOverrides as [$label, $state]): ?>
                                <li><strong><?= htmlspecialchars($label) ?></strong> — <code><?= htmlspecialchars($state) ?></code> (contact admin to change)</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon green">🛡️</div><div class="info"><div class="number"><?= (int)$zoneStats['rangers'] ?></div><div class="label">Rangers</div></div></div>
                    <div class="stat-card"><div class="icon blue">👥</div><div class="info"><div class="number"><?= (int)$zoneStats['scouts'] ?></div><div class="label">Scouts</div></div></div>
                    <div class="stat-card"><div class="icon red">🚨</div><div class="info"><div class="number"><?= (int)$zoneStats['incidents'] ?></div><div class="label">Active Incidents</div></div></div>
                    <div class="stat-card"><div class="icon purple">📹</div><div class="info"><div class="number"><?= (int)$zoneStats['cameras'] ?></div><div class="label">Cameras</div></div></div>
                    <div class="stat-card"><div class="icon orange">🔔</div><div class="info"><div class="number"><?= (int)$zoneStats['alarms'] ?></div><div class="label">Alarms</div></div></div>
                </div>

                <!-- ============================================================
                     ZONE INFO
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2>🏛️ Zone Information</h2>
                            <div class="section-desc">Basic zone details.</div>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_zone">
                        <div class="form-group full">
                            <label>Zone Name *</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($zone['name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group full">
                            <label>Description</label>
                            <textarea name="description" rows="3"><?= htmlspecialchars($zone['description'] ?? '') ?></textarea>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Buffer Radius (m)</label>
                                <input type="number" name="buffer_radius" value="<?= (int)($zone['buffer_radius'] ?? 500) ?>" min="100" max="5000">
                                <span class="hint">100–5000m around the zone boundary.</span>
                            </div>
                            <div class="form-group">
                                <label>Zone Code</label>
                                <input type="text" value="<?= htmlspecialchars($zone['park_code'] ?? '—') ?>" disabled>
                                <span class="hint">Assigned by administrator — cannot be changed.</span>
                            </div>
                        </div>
                        <div style="display:flex;justify-content:flex-end;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Zone Info</button>
                        </div>
                    </form>
                </div>

                <!-- ============================================================
                     AI DETECTION SETTINGS
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2>🤖 AI Detection Settings</h2>
                            <div class="section-desc">Control how the AI analyzes camera feeds for this zone.</div>
                        </div>
                        <?php if (!$globalAiEnabled): ?>
                            <span class="admin-fixed">⚠️ Admin overrides — pipeline off</span>
                        <?php endif; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_ai">

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Enable AI Detection</div>
                                <div class="toggle-desc">
                                    Master switch for AI analysis on your zone's cameras.
                                    <?php if (!$globalAiEnabled): ?>
                                        <br><span style="color:#856404;">⚠️ Global setting is OFF — this will take effect once admin enables it.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="ai_enabled" value="1" <?= (int)$settings['ai_enabled'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Auto-Create AI Alerts</div>
                                <div class="toggle-desc">Automatically create alerts from threat detections.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="ai_auto_create_alert" value="1" <?= (int)$settings['ai_auto_create_alert'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Auto-Trigger Alarms</div>
                                <div class="toggle-desc">Automatically trigger alarms on critical AI detections.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="ai_auto_trigger_alarm" value="1" <?= (int)$settings['ai_auto_trigger_alarm'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="form-grid" style="margin-top:16px;">
                            <div class="form-group">
                                <label>Minimum Confidence (%)</label>
                                <input type="number" name="ai_confidence_min" value="<?= (int)$settings['ai_confidence_min'] ?>" min="0" max="100">
                                <span class="hint">
                                    Detections below this are ignored.
                                    Global floor: <strong><?= (int)$globalAiConfidence ?>%</strong>
                                    <?php if ((int)$settings['ai_confidence_min'] < $globalAiConfidence): ?>
                                        — <span style="color:#856404;">⚠️ admin's floor overrides values below this.</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <div class="form-group full" style="margin-top:6px;">
                            <label>Detection Types to Monitor</label>
                            <div class="checkbox-group">
                                <?php
                                $activeTypes = explode(',', (string)($settings['ai_detection_types'] ?? ''));
                                $allTypes = [
                                    'human'   => '👤 Human',
                                    'animal'  => '🦁 Animal',
                                    'vehicle' => '🚗 Vehicle',
                                    'fire'    => '🔥 Fire',
                                    'gunshot' => '💥 Gunshot',
                                ];
                                foreach ($allTypes as $key => $label):
                                    $checked = in_array($key, $activeTypes, true);
                                ?>
                                    <label class="checkbox-chip <?= $checked ? 'checked' : '' ?>">
                                        <input type="checkbox" name="ai_detection_types[]" value="<?= htmlspecialchars($key) ?>" <?= $checked ? 'checked' : '' ?>>
                                        <?= $label ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="display:flex;justify-content:flex-end;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save AI Settings</button>
                        </div>
                    </form>
                </div>

                <!-- ============================================================
                     CCTV DEFAULTS
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2>📹 CCTV Defaults</h2>
                            <div class="section-desc">Default video quality, storage, and recording for new cameras.</div>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_cctv">

                        <div class="form-grid three">
                            <div class="form-group">
                                <label>Retention (days)</label>
                                <input type="number" name="cctv_retention_days" value="<?= (int)$settings['cctv_retention_days'] ?>" min="1" max="365">
                                <span class="hint">
                                    How long to keep recordings. Global: <strong><?= (int)$globalCctvRetention ?>d</strong>
                                </span>
                            </div>
                            <div class="form-group">
                                <label>Default Quality</label>
                                <select name="cctv_default_quality">
                                    <?php foreach (['720p','1080p','1440p','4K'] as $q): ?>
                                        <option value="<?= $q ?>" <?= $settings['cctv_default_quality'] === $q ? 'selected' : '' ?>><?= $q ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Snapshot Directory</label>
                                <input type="text" name="cctv_snapshot_dir" value="<?= htmlspecialchars($settings['cctv_snapshot_dir']) ?>">
                                <span class="hint">Global default: <code><?= htmlspecialchars($globalCctvSnapshotDir) ?></code></span>
                            </div>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Auto-Record New Cameras</div>
                                <div class="toggle-desc">Start recording automatically when a camera is added.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="cctv_auto_record" value="1" <?= (int)$settings['cctv_auto_record'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save CCTV Settings</button>
                        </div>
                    </form>
                </div>

                <!-- ============================================================
                     ALARM SYSTEM DEFAULTS
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2>🔔 Alarm System Defaults</h2>
                            <div class="section-desc">Default behavior for new alarms in your zone.</div>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_alarms">

                        <div class="form-grid three">
                            <div class="form-group">
                                <label>Default Alarm Type</label>
                                <select name="alarm_default_type">
                                    <option value="siren"    <?= $settings['alarm_default_type'] === 'siren'    ? 'selected' : '' ?>>🚨 Siren</option>
                                    <option value="bell"     <?= $settings['alarm_default_type'] === 'bell'     ? 'selected' : '' ?>>🔔 Bell</option>
                                    <option value="strobe"   <?= $settings['alarm_default_type'] === 'strobe'   ? 'selected' : '' ?>>💡 Strobe</option>
                                    <option value="speaker"  <?= $settings['alarm_default_type'] === 'speaker'  ? 'selected' : '' ?>>📢 Speaker</option>
                                    <option value="combined" <?= $settings['alarm_default_type'] === 'combined' ? 'selected' : '' ?>>🚨🔔 Combined</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Siren Duration (s)</label>
                                <input type="number" name="alarm_siren_duration" value="<?= (int)$settings['alarm_siren_duration'] ?>" min="10" max="3600">
                                <span class="hint">How long the siren sounds.</span>
                            </div>
                            <div class="form-group">
                                <label>Alarm Delay (s)</label>
                                <input type="number" name="alarm_delay_seconds" value="<?= (int)$settings['alarm_delay_seconds'] ?>" min="0" max="600">
                                <span class="hint">Grace period before alarm fires.</span>
                            </div>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Auto-Stop Alarm</div>
                                <div class="toggle-desc">Stop the alarm automatically after siren duration.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="alarm_auto_stop" value="1" <?= (int)$settings['alarm_auto_stop'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">SMS Blast on Trigger</div>
                                <div class="toggle-desc">
                                    Send SMS to on-duty rangers when alarm fires.
                                    <?php if (!$globalSmsEnabled): ?>
                                        <br><span style="color:#856404;">⚠️ SMS is globally disabled — no messages will send.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="alarm_sms_blast" value="1" <?= (int)$settings['alarm_sms_blast'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Alarm Settings</button>
                        </div>
                    </form>
                </div>

                <!-- ============================================================
                     NOTIFICATION CHANNELS
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2>🔔 Notification Channels & Events</h2>
                            <div class="section-desc">Which channels to use and which events trigger notifications.</div>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_notif_channels">

                        <div style="font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Channels</div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">📱 SMS</div>
                                <?php if (!$globalSmsEnabled): ?>
                                    <div class="toggle-desc">⚠️ Disabled globally by admin.</div>
                                <?php endif; ?>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notif_sms" value="1" <?= (int)$settings['notif_sms'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info"><div class="toggle-label">📧 Email</div></div>
                            <label class="switch">
                                <input type="checkbox" name="notif_email" value="1" <?= (int)$settings['notif_email'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info"><div class="toggle-label">📲 Push</div></div>
                            <label class="switch">
                                <input type="checkbox" name="notif_push" value="1" <?= (int)$settings['notif_push'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div style="font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;margin:16px 0 8px;">Events</div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">New Incidents</div>
                                <?php if (!$globalNotifyIncident): ?>
                                    <div class="toggle-desc">⚠️ Suppressed globally by admin.</div>
                                <?php endif; ?>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notif_on_incident" value="1" <?= (int)$settings['notif_on_incident'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">AI Alerts</div>
                                <?php if (!$globalNotifyAiAlert): ?>
                                    <div class="toggle-desc">⚠️ Suppressed globally by admin.</div>
                                <?php endif; ?>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notif_on_ai_alert" value="1" <?= (int)$settings['notif_on_ai_alert'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Alarm Triggers</div>
                                <?php if (!$globalNotifyAlarm): ?>
                                    <div class="toggle-desc">⚠️ Suppressed globally by admin.</div>
                                <?php endif; ?>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notif_on_alarm" value="1" <?= (int)$settings['notif_on_alarm'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Manpower Requests</div>
                                <?php if (!$globalNotifyManpower): ?>
                                    <div class="toggle-desc">⚠️ Suppressed globally by admin.</div>
                                <?php endif; ?>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notif_on_manpower" value="1" <?= (int)$settings['notif_on_manpower'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Offline Reminders</div>
                                <div class="toggle-desc">Notify users who haven't opened the app recently.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="notif_offline_reminder" value="1" <?= (int)$settings['notif_offline_reminder'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Notification Settings</button>
                        </div>
                    </form>
                </div>

                <!-- ============================================================
                     OPERATIONAL PERMISSIONS
                     ============================================================ -->
                <div class="section">
                    <div class="section-header">
                        <div>
                            <h2>🔐 Operational Permissions</h2>
                            <div class="section-desc">Control what each role can do in your zone.</div>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_perms">

                        <div style="font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">🛡️ Rangers</div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Can Acknowledge Incidents</div>
                                <div class="toggle-desc">Allow rangers to respond to reported incidents.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="perm_rangers_ack" value="1" <?= (int)$settings['perm_rangers_ack'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Can Trigger Alarms Manually</div>
                                <div class="toggle-desc">Allow rangers to manually trigger zone alarms.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="perm_rangers_trigger_alarm" value="1" <?= (int)$settings['perm_rangers_trigger_alarm'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Can Request Manpower</div>
                                <div class="toggle-desc">Allow rangers to send backup requests.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="perm_rangers_request_manpower" value="1" <?= (int)$settings['perm_rangers_request_manpower'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div style="font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;margin:16px 0 8px;">👥 Community Scouts</div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Can Report Incidents</div>
                                <div class="toggle-desc">Allow scouts to submit incident reports.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="perm_scouts_report" value="1" <?= (int)$settings['perm_scouts_report'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Can See Sensitive Poaching Details</div>
                                <div class="toggle-desc">Show sensitive incident details (poacher info, etc.).</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="perm_scouts_see_sensitive" value="1" <?= (int)$settings['perm_scouts_see_sensitive'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div style="font-size:12px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:0.5px;margin:16px 0 8px;">🏨 Tourism Operators</div>

                        <div class="toggle-row">
                            <div class="toggle-info">
                                <div class="toggle-label">Can See Zone Risk Level</div>
                                <div class="toggle-desc">Show general risk information on the safety map.</div>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="perm_tourism_see_risk" value="1" <?= (int)$settings['perm_tourism_see_risk'] === 1 ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div style="display:flex;justify-content:flex-end;margin-top:12px;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Permissions</button>
                        </div>
                    </form>
                </div>

                <!-- ============================================================
                     DANGER ZONE
                     ============================================================ -->
                <div class="danger-zone">
                    <h2>⚠️ Reset Zone Settings</h2>
                    <p>
                        Reset all AI, CCTV, alarm, notification, and permission settings for this zone
                        back to system defaults. <strong>This does not delete any data</strong> — only
                        the configuration is reset.
                        <?php if (!empty($globalOverrides)): ?>
                            <br><strong>Note:</strong> Admin-enforced global settings will still apply after reset.
                        <?php endif; ?>
                    </p>
                    <form method="POST" onsubmit="return confirm('Reset all zone settings to defaults? This cannot be undone.')">
                        <input type="hidden" name="action" value="reset_settings">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-rotate-left"></i> Reset to Defaults
                        </button>
                    </form>
                </div>

                <!-- Info notice -->
                <div class="section" style="border-left:4px solid #cce5ff;background:#f8fbff;">
                    <div style="font-size:13px;color:#495057;line-height:1.7;">
                        <strong>ℹ️ About Zone System Settings</strong><br>
                        • These settings apply to your zone only — other zones have their own configuration.<br>
                        • Changes apply to new events immediately. Existing incidents/alarms are unaffected.<br>
                        • Permission toggles control what rangers and scouts can do in the app.<br>
                        • Some settings are <strong>admin-enforced at the system level</strong> and will override your zone's values.<br>
                        • System-wide defaults are managed by your administrator.
                        <?php if ($settingsUpdatedAt): ?>
                            <br>• Your zone settings were last changed on <strong><?= htmlspecialchars(date('M j, Y \a\t H:i', strtotime($settingsUpdatedAt))) ?></strong>.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // Toggle chip appearance (single, correct handler)
        document.querySelectorAll('.checkbox-chip').forEach(chip => {
            const input = chip.querySelector('input');
            chip.addEventListener('click', function (e) {
                // Let the native input handle the toggle
                // Only sync the visual class
                setTimeout(() => {
                    chip.classList.toggle('checked', input.checked);
                }, 0);
            });
        });
    </script>
</body>
</html>
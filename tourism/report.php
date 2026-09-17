<?php
// ============================================================
// tourism/report.php
// Tourism / Lodge Operator — Report Incident
// ------------------------------------------------------------
// - Captures reporter info (auto)
// - Auto-captures GPS location
// - Uploads photos (validated content + size)
// - IMMEDIATELY notifies all rangers AND supervisors in the zone
// - AUTOMATICALLY sends SMS to every ranger + supervisor phone
//   registered for that zone (respects sms_enabled + notify_on_incident)
// - Broadcasts via WebSocket so ranger's incidents page updates live
// ============================================================

// Do NOT leak errors in production. Set display_errors off; log instead.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = getCurrentUser();
if (!$user || $user['role'] !== 'tourism') {
    header('Location: ../index.php');
    exit();
}

$pdo          = getDB();
$activeZoneId = (int)($user['zone_id'] ?? 0);
$zone         = function_exists('getZone') ? getZone($activeZoneId) : null;

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

// ============================================================
// GLOBAL SETTINGS (from admin/settings.php)
// ============================================================
if (!function_exists('ws_tour_setting')) {
    function ws_tour_setting(string $key, $default = null) {
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

$setSmsEnabled      = (string) ws_tour_setting('sms_enabled', '1')            === '1';
$setNotifyIncident  = (string) ws_tour_setting('notify_on_incident', '1')     === '1';
$setNotifyAiAlert   = (string) ws_tour_setting('notify_on_ai_alert', '1')     === '1';
$setNotifyAlarm     = (string) ws_tour_setting('notify_on_alarm', '1')        === '1';

// ============================================================
// NOTIFICATION GATE
// ============================================================
if (!function_exists('ws_tour_notify')) {
    /**
     * Wraps createNotification() only when the matching setting is on.
     * Returns true on success, false if suppressed or failed.
     */
    function ws_tour_notify(int $userId, string $type, string $title, string $body, ?int $incidentId = null): bool {
        $map = [
            'new_incident' => 'notify_on_incident',
            'system_alert' => 'notify_on_ai_alert',
            'alarm'        => 'notify_on_alarm',
        ];
        $key = $map[$type] ?? null;
        if ($key !== null && (string) ws_tour_setting($key, '1') !== '1') {
            return false;
        }
        if (!function_exists('createNotification')) return false;
        try {
            createNotification($userId, $type, $title, $body, $incidentId);
            return true;
        } catch (Throwable $e) {
            error_log('[WS-TOURISM] notify failed: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================
// SMS DISPATCH — sends to every ranger + supervisor in a zone
// ------------------------------------------------------------
// Behaviour:
//   - Respects global sms_enabled setting.
//   - Uses sendSMS() if available (from functions.php or an
//     included gateway file). Handles both bool and array returns.
//   - Writes every attempt to sms_logs regardless of gateway.
//   - Per-recipient errors are logged but never fatal.
//   - Returns [sent, failed, skipped, suppressed, recipients]
// ============================================================
if (!function_exists('dispatchZoneIncidentSMS')) {
    function dispatchZoneIncidentSMS(
        PDO $pdo,
        int $zoneId,
        int $incidentId,
        string $severity,
        string $category,
        string $reporterName,
        string $zoneName
    ): array {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'suppressed' => 0, 'recipients' => []];

        // Global gate
        if ((string) ws_tour_setting('sms_enabled', '1') !== '1') {
            $result['suppressed'] = 1;
            return $result;
        }
        if ($zoneId <= 0) return $result;

        // Fetch every active ranger + supervisor in this zone with a phone
        $recipients = safeFetchAll($pdo, "
            SELECT id, full_name, role, phone
            FROM users
            WHERE zone_id = ?
              AND role IN ('ranger','zone_supervisor')
              AND is_active = 1
              AND phone IS NOT NULL
              AND phone <> ''
        ", [$zoneId]);

        if (empty($recipients)) return $result;

        $sevUpper = strtoupper($severity);
        $catLabel = ucwords(str_replace('_', ' ', $category));

        foreach ($recipients as $r) {
            $phone = trim((string)$r['phone']);
            if ($phone === '') { $result['skipped']++; continue; }

            if ($r['role'] === 'zone_supervisor') {
                $msg = "WS OVERSEER: {$sevUpper} {$catLabel} reported in {$zoneName} by {$reporterName}. "
                     . "Incident #{$incidentId}. Check the dashboard.";
            } else {
                $msg = "WS ALERT: {$sevUpper} {$catLabel} reported in {$zoneName} by {$reporterName}. "
                     . "Incident #{$incidentId}. Open the app NOW.";
            }

            $sent = false;

            // 1. Try the real SMS gateway if it exists
            if (function_exists('sendSMS')) {
                try {
                    // Handle both bool and array returns from different sendSMS() implementations
                    $raw = sendSMS($phone, $msg);
                    if (is_array($raw)) {
                        $sent = !empty($raw['success']);
                    } else {
                        $sent = (bool) $raw;
                    }
                } catch (Throwable $e) {
                    error_log('[WS-SMS] sendSMS failed for ' . $phone . ': ' . $e->getMessage());
                    $sent = false;
                }
            }

            // 2. Log to sms_logs either way (queued for background worker if unsent)
            try {
                $pdo->prepare("
                    INSERT INTO sms_logs
                        (user_id, phone, message, message_type, incident_id, status, sent_at, created_at)
                    VALUES (?, ?, ?, 'incident', ?, ?, " . ($sent ? "NOW()" : "NULL") . ", NOW())
                ")->execute([
                    (int)$r['id'],
                    $phone,
                    $msg,
                    $incidentId,
                    $sent ? 'sent' : 'pending',
                ]);
            } catch (Throwable $e) {
                error_log('[WS-SMS] sms_logs insert failed: ' . $e->getMessage());
            }

            if ($sent) $result['sent']++;
            else       $result['failed']++;

            $result['recipients'][] = [
                'id'    => (int)$r['id'],
                'name'  => $r['full_name'],
                'role'  => $r['role'],
                'phone' => $phone,
                'sent'  => $sent,
            ];
        }

        return $result;
    }
}

// ============================================================
// HANDLE SUBMIT
// ============================================================
$message       = '';
$messageType   = 'success';
$newIncidentId = null;
$smsResult     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {

    $category    = (string)($_POST['category']    ?? '');
    $severity    = (string)($_POST['severity']    ?? 'medium');
    $description = trim((string)($_POST['description'] ?? ''));
    $latRaw      = (string)($_POST['location_lat'] ?? '');
    $lngRaw      = (string)($_POST['location_lng'] ?? '');
    $lat         = $latRaw !== '' ? (float)$latRaw : null;
    $lng         = $lngRaw !== '' ? (float)$lngRaw : null;

    $errors = [];

    if (!in_array($category, ['poaching','distressed_animal','human_wildlife_conflict','environmental_risk','other'], true)) {
        $errors[] = 'Please select a valid category.';
    }
    if (!in_array($severity, ['low','medium','high','critical'], true)) {
        $errors[] = 'Please select a valid severity.';
    }
    if (mb_strlen($description) < 10) {
        $errors[] = 'Please describe the incident (at least 10 characters).';
    }
    if ($lat === null || $lng === null) {
        $errors[] = 'Location is required. Please enable GPS or pick a point on the map.';
    } elseif (function_exists('validateCoordinates') && !validateCoordinates($lat, $lng)) {
        $errors[] = 'Location looks invalid. Please try again.';
    }
    if ($activeZoneId <= 0) {
        $errors[] = 'Your account is not assigned to a zone. Contact your administrator.';
    }

    // -------- PHOTO UPLOADS (validated) --------
    $mediaUrls = [];
    if (!empty($_FILES['photos']['tmp_name'][0])) {
        $uploadDir = __DIR__ . '/../uploads/incidents/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $allowed  = ['image/jpeg','image/png','image/gif','image/webp','image/heic','image/heif'];
        $maxFiles = 5;
        $maxBytes = 5 * 1024 * 1024;
        $fileCount = count(array_filter((array)$_FILES['photos']['tmp_name']));

        if ($fileCount > $maxFiles) {
            $errors[] = "Maximum {$maxFiles} photos allowed.";
        } else {
            foreach ($_FILES['photos']['tmp_name'] as $k => $tmp) {
                if (empty($tmp) || !is_uploaded_file($tmp)) continue;

                $type = $_FILES['photos']['type'][$k] ?? '';
                $size = (int)($_FILES['photos']['size'][$k] ?? 0);

                if (!in_array($type, $allowed, true)) continue;
                if ($size > $maxBytes) continue;

                // Extra safety: content check for JPEG/PNG/etc
                $info = @getimagesize($tmp);
                if ($info === false) continue;

                $ext = strtolower(pathinfo($_FILES['photos']['name'][$k], PATHINFO_EXTENSION));
                $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';

                $newName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $newName)) {
                    $mediaUrls[] = 'uploads/incidents/' . $newName;
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $geojson = "POINT($lng $lat)";

            $stmt = $pdo->prepare("
                INSERT INTO incidents
                    (reporter_id, reporter_type, zone_id, category, severity,
                     description, location_lat, location_lng, location_geojson,
                     media_urls, status, reported_at, is_simulated)
                VALUES (?, 'tourism', ?, ?, ?, ?, ?, ?, ws_point_from_wkt(?), ?, 'reported', NOW(), 0)
            ");
            $stmt->execute([
                $user['id'],
                $activeZoneId,
                $category,
                $severity,
                $description,
                $lat,
                $lng,
                $geojson,
                $mediaUrls ? json_encode($mediaUrls) : null,
            ]);
            $newIncidentId = (int)$pdo->query('SELECT lastval()')->fetchColumn();

            // ------------------------------------------------
            // 1. IN-APP NOTIFICATIONS — respects notify_on_incident
            // ------------------------------------------------
            $reporterLabel = 'Tourism Operator: ' . $user['full_name'];
            $notifyTitle   = '🚨 NEW Incident (Tourism)';
            $notifyBody    = "{$reporterLabel} reported a " . strtoupper($severity) . " "
                           . str_replace('_', ' ', $category) . " incident at " . date('H:i');

            $notifiedCount = 0;
            $suppressedCount = 0;

            $recipientsNotif = safeFetchAll($pdo, "
                SELECT id FROM users
                WHERE zone_id = ? AND role IN ('ranger','zone_supervisor') AND is_active = 1
            ", [$activeZoneId]);

            foreach ($recipientsNotif as $r) {
                if (ws_tour_notify((int)$r['id'], 'new_incident', $notifyTitle, $notifyBody, $newIncidentId)) {
                    $notifiedCount++;
                } else {
                    $suppressedCount++;
                }
            }

            // ------------------------------------------------
            // 2. AUTOMATIC SMS DISPATCH
            // ------------------------------------------------
            $zoneName  = $zone['name'] ?? ('Zone #' . $activeZoneId);
            $smsResult = dispatchZoneIncidentSMS(
                $pdo,
                $activeZoneId,
                $newIncidentId,
                $severity,
                $category,
                $user['full_name'],
                $zoneName
            );

            // ------------------------------------------------
            // 3. WEBSOCKET BROADCAST
            // ------------------------------------------------
            if (function_exists('broadcastToWS')) {
                try {
                    broadcastToWS('new-incident', [
                        'zone_id'       => $activeZoneId,
                        'id'            => $newIncidentId,
                        'category'      => $category,
                        'severity'      => $severity,
                        'description'   => mb_substr($description, 0, 120),
                        'reporter_name' => $user['full_name'],
                        'reporter_type' => 'tourism',
                        'location'      => ['lat' => $lat, 'lng' => $lng],
                        'reported_at'   => date('c'),
                    ]);
                } catch (Throwable $e) { /* silent */ }
            }

            // Audit
            logAudit($user['id'], 'report_incident', [
                'incident_id'  => $newIncidentId,
                'zone_id'      => $activeZoneId,
                'severity'     => $severity,
                'notified'     => $notifiedCount,
                'suppressed'   => $suppressedCount,
                'sms_sent'     => $smsResult['sent']    ?? 0,
                'sms_failed'   => $smsResult['failed']  ?? 0,
                'sms_skipped'  => $smsResult['skipped'] ?? 0,
            ]);

            // ------------------------------------------------
            // 4. SUCCESS MESSAGE
            // ------------------------------------------------
            $totalRecipients = count($smsResult['recipients'] ?? []);
            $smsSent         = $smsResult['sent']    ?? 0;
            $smsFailed       = $smsResult['failed']  ?? 0;
            $smsSuppressed   = $smsResult['suppressed'] ?? 0;

            $message = "✅ Report submitted! Incident <strong>#{$newIncidentId}</strong> has been recorded.";
            if ($notifiedCount > 0) {
                $message .= "<br>🔔 <strong>{$notifiedCount}</strong> ranger(s)/supervisor(s) notified in-app.";
            }
            if ($suppressedCount > 0 && !$setNotifyIncident) {
                $message .= "<br>🔕 In-app notifications are currently <strong>disabled</strong> by system settings.";
            }
            if ($smsSuppressed) {
                $message .= "<br>📱 SMS dispatch is <strong>disabled</strong> by system settings.";
            } elseif ($totalRecipients > 0) {
                $message .= "<br>📱 SMS sent to <strong>{$smsSent}</strong> of <strong>{$totalRecipients}</strong> recipients.";
                if ($smsFailed > 0) {
                    $message .= " <span style=\"color:#856404;\">⚠️ {$smsFailed} pending/failed — check sms_logs.</span>";
                }
            } else {
                $message .= "<br>ℹ️ No rangers or supervisors with a phone number are registered for this zone yet.";
            }

            $messageType = 'success';

        } catch (PDOException $e) {
            error_log('[WS-TOURISM] insert failed: ' . $e->getMessage());
            $message = 'We could not save your report. Please try again, or call your zone supervisor directly.';
            $messageType = 'danger';
        }
    } else {
        $message = implode('<br>', array_map('htmlspecialchars', $errors));
        $messageType = 'danger';
    }
}

// System-state flags for the UI
$uiSmsOff         = !$setSmsEnabled;
$uiNotifyOff      = !$setNotifyIncident;
$uiShowStateBadge = ($uiSmsOff || $uiNotifyOff);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Report Incident - Tourism - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 20px; }
        .dashboard-greeting h1 { font-size: 24px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 14px; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 16px; color: #0d3b22; display: flex; align-items: center; gap: 8px; }

        .btn { padding: 12px 22px; border-radius: 10px; border: none; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #b02a37; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-block { width: 100%; }
        .btn:disabled { opacity: .6; cursor: not-allowed; }

        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; line-height: 1.6; }
        .alert.success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert.danger  { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert.warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }

        .state-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: #fff3cd; color: #856404;
            border: 1px solid #ffc107;
            border-radius: 10px; padding: 8px 14px;
            font-size: 12.5px; margin-bottom: 14px;
        }

        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; font-size: 13px;
            font-weight: 700; color: #0d3b22;
            margin-bottom: 8px; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-group .hint { font-size: 11px; color: #6c757d; margin-top: 4px; display: block; text-transform: none; letter-spacing: 0; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 12px 14px;
            border: 1.5px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px; background: #fafafa;
            font-family: inherit; transition: all 0.2s;
            -webkit-appearance: none;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #1a5c3a; background: white;
            box-shadow: 0 0 0 3px rgba(26,92,58,0.1);
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
            gap: 10px;
        }
        .category-btn {
            padding: 14px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            background: #fafafa;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .category-btn:hover { border-color: #1a5c3a; background: white; }
        .category-btn.selected { border-color: #1a5c3a; background: #e8f5e9; }
        .category-btn input { display: none; }
        .category-btn .icon { font-size: 24px; display: block; margin-bottom: 4px; }
        .category-btn .label { font-size: 11px; font-weight: 700; color: #495057; }
        .category-btn.selected .label { color: #1a5c3a; }

        .severity-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .severity-btn {
            padding: 12px 6px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            background: #fafafa;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        .severity-btn input { display: none; }
        .severity-btn .icon { font-size: 20px; display: block; margin-bottom: 2px; }
        .severity-btn .label { font-size: 10px; font-weight: 700; color: #495057; text-transform: uppercase; }

        .severity-btn.low.selected     { border-color: #28a745; background: #e8f5e9; }
        .severity-btn.medium.selected  { border-color: #ffc107; background: #fff8e1; }
        .severity-btn.high.selected    { border-color: #fd7e14; background: #fff3e0; }
        .severity-btn.critical.selected{ border-color: #dc3545; background: #fdecea; }
        .severity-btn.low.selected .label     { color: #28a745; }
        .severity-btn.medium.selected .label  { color: #b38600; }
        .severity-btn.high.selected .label    { color: #fd7e14; }
        .severity-btn.critical.selected .label{ color: #dc3545; }

        #locationMap { height: 260px; border-radius: 12px; background: #e0e0e0; margin-top: 8px; }
        .location-row { display: flex; gap: 10px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
        .location-display {
            flex: 1; padding: 10px 14px;
            background: #f0f7f4; border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 12px; color: #0d3b22;
            min-width: 200px;
        }

        .photos-drop {
            border: 2px dashed #c0c0c0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            background: #fafafa;
            transition: all 0.2s;
            cursor: pointer;
        }
        .photos-drop:hover { border-color: #1a5c3a; background: white; }
        .photos-drop .icon { font-size: 32px; color: #adb5bd; margin-bottom: 8px; }
        .photos-drop .text { font-size: 13px; color: #6c757d; }
        .photo-previews {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 8px; margin-top: 12px;
        }
        .photo-preview {
            position: relative;
            padding-top: 100%;
            border-radius: 8px;
            overflow: hidden;
            background-size: cover;
            background-position: center;
            border: 2px solid #e0e0e0;
        }
        .photo-preview .remove {
            position: absolute;
            top: 4px; right: 4px;
            width: 24px; height: 24px;
            background: rgba(220,53,69,0.9);
            color: white; border: none; border-radius: 50%;
            cursor: pointer; font-size: 12px;
            display: flex; align-items: center; justify-content: center;
        }

        .submit-panel {
            position: sticky; bottom: 0;
            background: white;
            padding: 16px 20px;
            border-radius: 14px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
            display: flex; justify-content: space-between;
            align-items: center; gap: 12px;
            margin-top: 10px;
        }

        .sending-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            z-index: 3000;
            align-items: center; justify-content: center;
            flex-direction: column; gap: 16px;
            color: white;
        }
        .sending-overlay.show { display: flex; }
        .sending-overlay .spinner {
            width: 60px; height: 60px;
            border: 5px solid rgba(255,255,255,0.2);
            border-top-color: #4ade80;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .sending-overlay .text { font-size: 16px; font-weight: 600; letter-spacing: 0.5px; }
        .sending-overlay .sub { font-size: 13px; opacity: 0.8; }

        @media (max-width: 600px) {
            .section { padding: 16px 16px; }
            .category-grid { grid-template-columns: repeat(2, 1fr); }
            .severity-grid { grid-template-columns: repeat(2, 1fr); }
            #locationMap { height: 220px; }
            .submit-panel { flex-direction: column; }
            .submit-panel .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Report Incident</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🚨 Report an Incident</h1>
                    <p>
                        Your report will be sent <strong>immediately</strong> via SMS and in-app alert to all
                        rangers and supervisors in
                        <strong><?= htmlspecialchars($zone['name'] ?? 'your zone') ?></strong>.
                    </p>
                </div>

                <?php if ($uiShowStateBadge): ?>
                    <div class="state-badge">
                        <span>ℹ️</span>
                        <span>
                            Some alert channels are currently disabled by the administrator:
                            <?php if ($uiSmsOff): ?><strong>SMS</strong><?php endif; ?>
                            <?php if ($uiSmsOff && $uiNotifyOff): ?> and <?php endif; ?>
                            <?php if ($uiNotifyOff): ?><strong>in-app notifications</strong><?php endif; ?>.
                            Your report will still be recorded and visible on the dashboard.
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="alert <?= htmlspecialchars($messageType) ?>">
                        <?= $message ?>
                        <?php if (!empty($newIncidentId)): ?>
                            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                                <a href="my-reports.php" class="btn btn-primary" style="padding:8px 14px;font-size:12px;">📋 View My Reports</a>
                                <a href="report.php" class="btn btn-secondary" style="padding:8px 14px;font-size:12px;">➕ Report Another</a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="reportForm" onsubmit="return prepareSubmit(event)">

                    <input type="hidden" name="action" value="submit_report">
                    <input type="hidden" name="location_lat" id="inputLat" value="">
                    <input type="hidden" name="location_lng" id="inputLng" value="">

                    <!-- CATEGORY -->
                    <div class="section">
                        <div class="section-header">
                            <h2>🎯 What kind of incident?</h2>
                        </div>
                        <div class="category-grid">
                            <?php
                            $cats = [
                                'poaching'                => ['🦏', 'Poaching'],
                                'distressed_animal'       => ['🐘', 'Animal in Distress'],
                                'human_wildlife_conflict' => ['🐆', 'Human-Wildlife Conflict'],
                                'environmental_risk'      => ['🔥', 'Environmental Risk'],
                                'other'                   => ['📌', 'Other'],
                            ];
                            foreach ($cats as $key => [$icon, $label]):
                            ?>
                                <label class="category-btn">
                                    <input type="radio" name="category" value="<?= htmlspecialchars($key) ?>" required>
                                    <span class="icon"><?= $icon ?></span>
                                    <span class="label"><?= htmlspecialchars($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- SEVERITY -->
                    <div class="section">
                        <div class="section-header">
                            <h2>⚠️ How serious is it?</h2>
                        </div>
                        <div class="severity-grid">
                            <?php
                            $sevs = [
                                'low'      => ['🟢', 'Low'],
                                'medium'   => ['🟡', 'Medium'],
                                'high'     => ['🟠', 'High'],
                                'critical' => ['🔴', 'Critical'],
                            ];
                            foreach ($sevs as $key => [$icon, $label]):
                            ?>
                                <label class="severity-btn <?= $key ?> <?= $key === 'medium' ? 'selected' : '' ?>">
                                    <input type="radio" name="severity" value="<?= htmlspecialchars($key) ?>" <?= $key === 'medium' ? 'checked' : '' ?>>
                                    <span class="icon"><?= $icon ?></span>
                                    <span class="label"><?= htmlspecialchars($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- DESCRIPTION -->
                    <div class="section">
                        <div class="section-header">
                            <h2>📝 Description</h2>
                        </div>
                        <div class="form-group">
                            <textarea name="description" rows="5" required minlength="10"
                                placeholder="Describe what you see. Include landmarks, animal count, direction of travel, vehicle descriptions if any..."></textarea>
                            <span class="hint">Be specific — rangers will use this to prepare.</span>
                        </div>
                    </div>

                    <!-- LOCATION -->
                    <div class="section">
                        <div class="section-header">
                            <h2>📍 Location</h2>
                            <button type="button" class="btn btn-secondary" style="padding:8px 14px;font-size:12px;" onclick="detectLocation()">
                                <i class="fas fa-crosshairs"></i> Use My GPS
                            </button>
                        </div>
                        <div class="form-group">
                            <div id="locationMap"></div>
                            <div class="location-row">
                                <div class="location-display" id="locationDisplay">
                                    📍 Location not set — click "Use My GPS" or tap on the map
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PHOTOS -->
                    <div class="section">
                        <div class="section-header">
                            <h2>📷 Photos (optional)</h2>
                        </div>
                        <label class="photos-drop" for="photoInput">
                            <div class="icon">📷</div>
                            <div class="text"><strong>Tap to add photos</strong> (max 5, up to 5MB each)</div>
                            <input type="file" name="photos[]" id="photoInput" accept="image/*" multiple
                                   style="display:none" onchange="previewPhotos(event)">
                        </label>
                        <div class="photo-previews" id="photoPreviews"></div>
                    </div>

                    <!-- SUBMIT -->
                    <div class="submit-panel">
                        <div style="font-size:13px;color:#6c757d;">
                            <strong>ℹ️</strong> Your report is sent instantly via SMS + in-app alert to rangers and supervisors in your zone.
                        </div>
                        <button type="submit" class="btn btn-danger" style="padding:14px 28px;font-size:15px;">
                            <i class="fas fa-paper-plane"></i> Submit & Notify Rangers
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- SENDING OVERLAY -->
    <div class="sending-overlay" id="sendingOverlay">
        <div class="spinner"></div>
        <div class="text">Sending to rangers…</div>
        <div class="sub">Dispatching SMS to rangers and supervisors in your zone</div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>

    <script>
        // ============================================================
        // MINI MAP FOR LOCATION
        // ============================================================
        const ZONE = <?= json_encode($zone, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        let initialCenter = [-14.5, 27.0];
        let initialZoom = 6;

        if (ZONE && (ZONE.center_lat || ZONE.boundary_center_lat)) {
            initialCenter = [
                parseFloat(ZONE.center_lat || ZONE.boundary_center_lat),
                parseFloat(ZONE.center_lng || ZONE.boundary_center_lng)
            ];
            initialZoom = 11;
        }

        const map = L.map('locationMap', { center: initialCenter, zoom: initialZoom });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        if (ZONE && ZONE.boundary_geojson && ZONE.boundary_geojson.type === 'Polygon') {
            try {
                L.geoJSON(ZONE.boundary_geojson, {
                    style: { color: '#1B5E20', weight: 2, fillColor: '#1B5E20', fillOpacity: 0.05 }
                }).addTo(map);
            } catch (e) {}
        }

        let marker = null;
        let selectedLat = null;
        let selectedLng = null;

        function setLocation(lat, lng) {
            selectedLat = lat;
            selectedLng = lng;

            if (marker) map.removeLayer(marker);
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', () => {
                const p = marker.getLatLng();
                setLocation(p.lat, p.lng);
            });

            document.getElementById('inputLat').value = lat.toFixed(8);
            document.getElementById('inputLng').value = lng.toFixed(8);
            document.getElementById('locationDisplay').innerHTML =
                '📍 <strong>' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '</strong>';
        }

        map.on('click', e => setLocation(e.latlng.lat, e.latlng.lng));

        function detectLocation() {
            if (!navigator.geolocation) {
                alert('GPS not available in your browser.');
                return;
            }
            const display = document.getElementById('locationDisplay');
            display.innerHTML = '📍 Detecting your location…';

            navigator.geolocation.getCurrentPosition(
                pos => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    setLocation(lat, lng);
                    map.setView([lat, lng], 15);
                },
                err => {
                    display.innerHTML = '⚠️ GPS error: ' + err.message + ' — tap the map to pick a point.';
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        }

        // Auto-detect only once the user first interacts with the page
        // (avoids surprising permission prompts on page load)
        let autoDetectDone = false;
        function autoDetectOnce() {
            if (autoDetectDone) return;
            autoDetectDone = true;
            detectLocation();
        }
        document.addEventListener('click', autoDetectOnce, { once: true });
        document.addEventListener('touchstart', autoDetectOnce, { once: true });

        // Also allow auto-detect after 3s as a fallback if user does nothing
        setTimeout(autoDetectOnce, 3000);

        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                btn.querySelector('input').checked = true;
            });
        });

        document.querySelectorAll('.severity-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.severity-btn').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                btn.querySelector('input').checked = true;
            });
        });

        const selectedFiles = new DataTransfer();

        function previewPhotos(event) {
            const previews = document.getElementById('photoPreviews');
            previews.innerHTML = '';
            selectedFiles.items.clear();

            const files = Array.from(event.target.files).slice(0, 5);
            files.forEach(file => {
                if (file.size > 5 * 1024 * 1024) return;
                selectedFiles.items.add(file);

                const reader = new FileReader();
                reader.onload = e => {
                    const div = document.createElement('div');
                    div.className = 'photo-preview';
                    div.style.backgroundImage = `url(${e.target.result})`;
                    div.innerHTML = '<button type="button" class="remove">×</button>';
                    div.querySelector('.remove').addEventListener('click', ev => {
                        ev.stopPropagation();
                        div.remove();
                    });
                    previews.appendChild(div);
                };
                reader.readAsDataURL(file);
            });

            try {
                document.getElementById('photoInput').files = selectedFiles.files;
            } catch (e) { /* ignore */ }
        }

        function prepareSubmit(e) {
            if (selectedLat === null || selectedLng === null) {
                e.preventDefault();
                alert('📍 Please set a location first.\n\nClick "Use My GPS" or tap on the map.');
                return false;
            }

            const cat = document.querySelector('input[name="category"]:checked');
            if (!cat) {
                e.preventDefault();
                alert('Please select an incident category.');
                return false;
            }

            const desc = document.querySelector('textarea[name="description"]').value.trim();
            if (desc.length < 10) {
                e.preventDefault();
                alert('Please describe the incident (at least 10 characters).');
                return false;
            }

            document.getElementById('sendingOverlay').classList.add('show');
            return true;
        }
    </script>
</body>
</html>
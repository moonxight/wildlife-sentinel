<?php
// ============================================================
// admin/ai-dashboard.php
// Wildlife Sentinel — AI Detection Dashboard (Admin)
// ------------------------------------------------------------
// Reads from ai_detections_v2 (AI Engine v2) with fallback to
// the legacy ai_detections table.
//
// Honors global settings:
//   ai_enabled, ai_confidence_min, ai_auto_create_alert,
//   ai_auto_trigger_alarm, cctv_retention_days,
//   cctv_snapshot_dir, notify_on_ai_alert, notify_on_alarm,
//   items_per_page
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$pdo  = getDB();

// ============================================================
// SAFE HELPERS
// ============================================================
if (!function_exists('ai_dash_safe_count')) {
    function ai_dash_safe_count(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return (int)($row['count'] ?? $row['c'] ?? 0);
        } catch (PDOException $e) {
            return 0;
        }
    }
}
if (!function_exists('ai_dash_safe_fetch_all')) {
    function ai_dash_safe_fetch_all(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}
if (!function_exists('ai_dash_table_exists')) {
    function ai_dash_table_exists(PDO $pdo, string $table): bool {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS c
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = current_schema() AND TABLE_NAME = ?
            ');
            $stmt->execute([$table]);
            $cache[$table] = ((int)($stmt->fetch()['c'] ?? 0)) > 0;
        } catch (PDOException $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

// ============================================================
// GLOBAL SETTINGS
// ============================================================
$globalAiEnabled       = (string) getSetting('ai_enabled', '1')             === '1';
$globalAiConfidenceMin = (int)    getSetting('ai_confidence_min', 70);
$globalAiAutoCreate    = (string) getSetting('ai_auto_create_alert', '1')  === '1';
$globalAiAutoAlarm     = (string) getSetting('ai_auto_trigger_alarm', '0') === '1';
$globalCctvRetention   = (int)    getSetting('cctv_retention_days', 30);
$globalCctvSnapshotDir = (string) getSetting('cctv_snapshot_dir', 'uploads/cctv/');
$globalNotifyAiAlert   = (string) getSetting('notify_on_ai_alert', '1')    === '1';
$globalNotifyAlarm     = (string) getSetting('notify_on_alarm', '1')       === '1';

$itemsPerPage = (int) getSetting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;
$detectionsLimit = $itemsPerPage;
$alertsLimit     = max(3, min(10, $itemsPerPage));
$alarmsLimit     = max(3, min(10, $itemsPerPage));

$confidenceFloorPct = max(0, min(100, $globalAiConfidenceMin));

// ============================================================
// TABLE PROBES
// ============================================================
$hasV2         = ai_dash_table_exists($pdo, 'ai_detections_v2');
$hasLegacy     = ai_dash_table_exists($pdo, 'ai_detections');
$hasAiAlerts   = ai_dash_table_exists($pdo, 'ai_alerts');
$hasAiAlarms   = ai_dash_table_exists($pdo, 'alarm_triggers');
$hasCameras    = ai_dash_table_exists($pdo, 'cctv_cameras');
$hasTracks     = ai_dash_table_exists($pdo, 'ai_tracks');
$hasFeedback   = ai_dash_table_exists($pdo, 'ai_feedback');
$hasModels     = ai_dash_table_exists($pdo, 'ai_models');
$hasQueue      = ai_dash_table_exists($pdo, 'ai_queue');
$hasTelemetry  = ai_dash_table_exists($pdo, 'ai_telemetry');

// Detection source (v2 preferred)
$detSrc = $hasV2 ? 'v2' : ($hasLegacy ? 'legacy' : 'none');

// ============================================================
// AUTO-CREATE ADMIN AI SETTINGS ROW
// ============================================================
try {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS admin_ai_settings (
            admin_id INTEGER PRIMARY KEY,
            sound_alerts_enabled SMALLINT DEFAULT 1,
            auto_ack_low_risk SMALLINT DEFAULT 0,
            auto_ack_minutes INT DEFAULT 10,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
} catch (PDOException $e) { /* optional */ }

$adminSettings = ai_dash_safe_fetch_all($pdo, "SELECT * FROM admin_ai_settings WHERE admin_id = ? LIMIT 1", [$user['id']]);
if (empty($adminSettings)) {
    try {
        $pdo->prepare("INSERT INTO admin_ai_settings (admin_id) VALUES (?)")->execute([$user['id']]);
        $adminSettings = [[
            'admin_id'              => $user['id'],
            'sound_alerts_enabled'  => 1,
            'auto_ack_low_risk'     => 0,
            'auto_ack_minutes'      => 10,
        ]];
    } catch (PDOException $e) {
        $adminSettings = [[
            'admin_id'              => $user['id'],
            'sound_alerts_enabled'  => 1,
            'auto_ack_low_risk'     => 0,
            'auto_ack_minutes'      => 10,
        ]];
    }
}
$as             = $adminSettings[0];
$asSound        = (int)($as['sound_alerts_enabled'] ?? 1) === 1;
$asAutoAck      = (int)($as['auto_ack_low_risk']     ?? 0) === 1;
$asAutoAckMins  = max(1, (int)($as['auto_ack_minutes'] ?? 10));

// ============================================================
// FILTERS
// ============================================================
$filterZone    = (int)($_GET['zone']   ?? 0);
$filterType    = in_array($_GET['dtype']  ?? '', ['human','animal','vehicle','fire','gunshot','unknown'], true) ? $_GET['dtype']  : '';
$filterThreat  = in_array($_GET['threat'] ?? '', ['critical','high','medium','low','safe'], true)                  ? $_GET['threat'] : '';
$filterCamera  = (int)($_GET['camera'] ?? 0);
$filterWindow  = in_array($_GET['win']    ?? '', ['1h','24h','7d'], true) ? $_GET['win'] : '24h';
$filterMinConf = isset($_GET['minconf']) ? max(0, min(100, (int)$_GET['minconf'])) : $confidenceFloorPct;

$windowSql = [
    '1h'  => '(NOW() - (1) * INTERVAL \'1 hour\')',
    '24h' => '(NOW() - (24) * INTERVAL \'1 hour\')',
    '7d'  => '(NOW() - (7) * INTERVAL \'1 day\')',
][$filterWindow] ?? '(NOW() - (24) * INTERVAL \'1 hour\')';

$zoneWhere  = $filterZone > 0 ? ' AND zone_id = ? ' : ' ';
$zoneParams = $filterZone > 0 ? [$filterZone] : [];

// ============================================================
// AJAX ENDPOINTS
// ============================================================
if (isset($_GET['ajax'])) {

    // ---- LIVE POLL ----
    if ($_GET['ajax'] === 'poll') {
        header('Content-Type: application/json');

        if (!$globalAiEnabled) {
            echo json_encode([
                'success' => true, 'ai_disabled' => true,
                'stats'   => ['pending_alerts' => 0, 'active_alarms' => 0,
                              'today_detections' => 0, 'today_threats' => 0],
                'new_detections' => [], 'new_alerts' => [],
            ]);
            exit;
        }

        $latestDetectionId = (int)($_GET['last_detection'] ?? 0);
        $latestAlertId     = (int)($_GET['last_alert'] ?? 0);
        $pollMinConf       = isset($_GET['minconf']) ? max(0, min(100, (int)$_GET['minconf'])) : $confidenceFloorPct;
        $minConfSql        = $pollMinConf > 0 ? ' AND d.confidence >= ' . ($pollMinConf / 100) : '';
        $zoneSql           = $filterZone > 0 ? 'AND d.zone_id = ' . (int)$filterZone : '';

        // Detections source
        if ($detSrc === 'v2') {
            $newDetections = ai_dash_safe_fetch_all($pdo, "
                SELECT d.id, d.detection_type, d.subclass, d.threat_level, d.is_threat,
                       d.confidence, d.ensemble_confidence, d.threat_score,
                       d.detected_at, c.camera_name, z.name AS zone_name
                FROM ai_detections_v2 d
                LEFT JOIN cctv_cameras c ON d.camera_id = c.id
                LEFT JOIN zones z ON d.zone_id = z.id
                WHERE d.id > ? $zoneSql $minConfSql
                ORDER BY d.id DESC LIMIT 20
            ", [$latestDetectionId]);
        } elseif ($detSrc === 'legacy') {
            $newDetections = ai_dash_safe_fetch_all($pdo, "
                SELECT d.id, d.detection_type, NULL AS subclass,
                       d.threat_level, d.is_threat, d.confidence,
                       NULL AS ensemble_confidence, NULL AS threat_score,
                       d.detected_at, c.camera_name, z.name AS zone_name
                FROM ai_detections d
                LEFT JOIN cctv_cameras c ON d.camera_id = c.id
                LEFT JOIN zones z ON d.zone_id = z.id
                WHERE d.id > ? $zoneSql $minConfSql
                ORDER BY d.id DESC LIMIT 20
            ", [$latestDetectionId]);
        } else {
            $newDetections = [];
        }

        $zoneSql2 = $filterZone > 0 ? 'AND zone_id = ' . (int)$filterZone : '';
        $newAlerts = $hasAiAlerts ? ai_dash_safe_fetch_all($pdo, "
            SELECT a.id, a.title, a.alert_type, a.severity, a.created_at,
                   z.name AS zone_name
            FROM ai_alerts a
            LEFT JOIN zones z ON a.zone_id = z.id
            WHERE a.id > ? AND a.is_acknowledged = 0 $zoneSql2
            ORDER BY a.id DESC LIMIT 10
        ", [$latestAlertId]) : [];

        $stats = [
            'pending_alerts'   => $hasAiAlerts ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM ai_alerts WHERE is_acknowledged = 0 $zoneSql2") : 0,
            'active_alarms'    => $hasAiAlarms ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM alarm_triggers WHERE stopped_at IS NULL " . ($filterZone > 0 ? 'AND zone_id = ' . (int)$filterZone : '')) : 0,
            'today_detections' => $detSrc !== 'none' ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM " . ($detSrc === 'v2' ? 'ai_detections_v2' : 'ai_detections') . ' WHERE DATE(detected_at) = CURRENT_DATE ' . ($filterZone > 0 ? 'AND zone_id = ' . (int)$filterZone : '')) : 0,
            'today_threats'    => $detSrc !== 'none' ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM " . ($detSrc === 'v2' ? 'ai_detections_v2' : 'ai_detections') . ' WHERE is_threat = 1 AND DATE(detected_at) = CURRENT_DATE ' . ($filterZone > 0 ? 'AND zone_id = ' . (int)$filterZone : '')) : 0,
        ];

        echo json_encode([
            'success'        => true,
            'stats'          => $stats,
            'new_detections' => $newDetections,
            'new_alerts'     => $newAlerts,
            'server_time'    => date('c'),
        ]);
        exit;
    }

    // ---- CSV EXPORT ----
    if ($_GET['ajax'] === 'export') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="ai-detections-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');

        if ($detSrc === 'v2') {
            fputcsv($out, ['ID','Time','Zone','Camera','Type','Subclass','Threat','Level','Score','Confidence','Ensemble','Behaviour','Track','Lat','Lng']);
            $params = [];
            $sql = "
                SELECT d.id, d.detected_at, z.name AS zone_name, c.camera_name,
                       d.detection_type, d.subclass, d.is_threat, d.threat_level, d.threat_score,
                       d.confidence, d.ensemble_confidence, d.behaviour, d.track_id,
                       d.location_lat, d.location_lng
                FROM ai_detections_v2 d
                LEFT JOIN cctv_cameras c ON d.camera_id = c.id
                LEFT JOIN zones z ON d.zone_id = z.id
                WHERE d.detected_at >= $windowSql
            ";
            if ($filterZone > 0) { $sql .= " AND d.zone_id = ?"; $params[] = $filterZone; }
            if ($filterType !== '') { $sql .= " AND d.detection_type = ?"; $params[] = $filterType; }
            if ($filterMinConf > 0) { $sql .= " AND d.confidence >= ?"; $params[] = $filterMinConf / 100; }
            $sql .= " ORDER BY d.detected_at DESC LIMIT 5000";
            $rows = ai_dash_safe_fetch_all($pdo, $sql, $params);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'], $r['detected_at'], $r['zone_name'] ?? '', $r['camera_name'] ?? '',
                    $r['detection_type'], $r['subclass'] ?? '',
                    $r['is_threat'] ? 'YES' : 'no', $r['threat_level'] ?? '',
                    $r['threat_score'] ?? '', $r['confidence'] ? round($r['confidence'] * 100) . '%' : '',
                    $r['ensemble_confidence'] ? round($r['ensemble_confidence'] * 100) . '%' : '',
                    $r['behaviour'] ?? '', $r['track_id'] ?? '',
                    $r['location_lat'], $r['location_lng'],
                ]);
            }
        } elseif ($detSrc === 'legacy') {
            fputcsv($out, ['ID','Time','Zone','Camera','Type','Threat','Level','Confidence','Lat','Lng']);
            $params = [];
            $sql = "
                SELECT d.id, d.detected_at, z.name AS zone_name, c.camera_name,
                       d.detection_type, d.is_threat, d.threat_level, d.confidence,
                       d.location_lat, d.location_lng
                FROM ai_detections d
                LEFT JOIN cctv_cameras c ON d.camera_id = c.id
                LEFT JOIN zones z ON d.zone_id = z.id
                WHERE d.detected_at >= $windowSql
            ";
            if ($filterZone > 0) { $sql .= " AND d.zone_id = ?"; $params[] = $filterZone; }
            if ($filterType !== '') { $sql .= " AND d.detection_type = ?"; $params[] = $filterType; }
            if ($filterMinConf > 0) { $sql .= " AND d.confidence >= ?"; $params[] = $filterMinConf / 100; }
            $sql .= " ORDER BY d.detected_at DESC LIMIT 5000";
            $rows = ai_dash_safe_fetch_all($pdo, $sql, $params);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'], $r['detected_at'], $r['zone_name'] ?? '', $r['camera_name'] ?? '',
                    $r['detection_type'], $r['is_threat'] ? 'YES' : 'no',
                    $r['threat_level'] ?? '', $r['confidence'] ? round($r['confidence'] * 100) . '%' : '',
                    $r['location_lat'], $r['location_lng'],
                ]);
            }
        }
        fclose($out);
        exit;
    }
}

// ============================================================
// POST ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ACK ALERT
    if ($action === 'ack_alert' && $hasAiAlerts) {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        try {
            $existing = ai_dash_safe_fetch_all($pdo, "SELECT title, is_acknowledged FROM ai_alerts WHERE id = ? LIMIT 1", [$alertId]);
            if (empty($existing)) {
                $message = 'Alert not found.'; $messageType = 'danger';
            } elseif ((int)$existing[0]['is_acknowledged'] === 1) {
                $message = 'This alert was already acknowledged.';
            } else {
                $pdo->prepare("UPDATE ai_alerts SET is_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ?")
                    ->execute([$user['id'], $alertId]);
                if ($hasAiAlarms) {
                    $pdo->prepare('
                        UPDATE alarm_triggers SET stopped_at = NOW(),
                            duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1),
                            was_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW()
                        WHERE alert_id = ? AND stopped_at IS NULL
                    ')->execute([$user['id'], $alertId]);
                }
                logAudit($user['id'], 'acknowledge_ai_alert', ['alert_id' => $alertId, 'title' => $existing[0]['title'] ?? '']);
                $message = '✅ AI alert acknowledged.';
            }
        } catch (PDOException $e) {
            $message = 'Could not acknowledge alert.'; $messageType = 'danger';
        }
    }

    // STOP ALARM
    if ($action === 'stop_alarm' && $hasAiAlarms) {
        $triggerId = (int)($_POST['trigger_id'] ?? 0);
        try {
            $existing = ai_dash_safe_fetch_all($pdo, "
                SELECT at.id, at.stopped_at, a.alarm_name
                FROM alarm_triggers at
                LEFT JOIN alarm_systems a ON at.alarm_id = a.id
                WHERE at.id = ? LIMIT 1
            ", [$triggerId]);

            if (empty($existing)) {
                $message = 'Alarm not found.'; $messageType = 'danger';
            } elseif (!empty($existing[0]['stopped_at'])) {
                $message = 'This alarm was already stopped.';
            } else {
                $pdo->prepare('
                    UPDATE alarm_triggers SET stopped_at = NOW(),
                        duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1),
                        was_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW()
                    WHERE id = ? AND stopped_at IS NULL
                ')->execute([$user['id'], $triggerId]);

                if (function_exists('ws_alarm_stop_hardware')) {
                    try { ws_alarm_stop_hardware(0, $triggerId); } catch (Throwable $e) {}
                }

                logAudit($user['id'], 'stop_alarm', ['trigger_id' => $triggerId, 'alarm_name' => $existing[0]['alarm_name'] ?? '']);
                $message = '✅ Alarm stopped.';
            }
        } catch (PDOException $e) {
            $message = 'Could not stop alarm.'; $messageType = 'danger';
        }
    }

    // STOP ALL ALARMS
    if ($action === 'stop_all_alarms' && $hasAiAlarms) {
        $scopeZone = (int)($_POST['scope_zone'] ?? 0);
        try {
            $where  = "stopped_at IS NULL";
            $params = [];
            if ($scopeZone > 0) { $where .= " AND zone_id = ?"; $params[] = $scopeZone; }

            $stmt = $pdo->prepare("
                UPDATE alarm_triggers SET stopped_at = NOW(),
                    duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1),
                    was_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW()
                WHERE $where
            ");
            $stmt->execute(array_merge([$user['id']], $params));
            $count = $stmt->rowCount();
            logAudit($user['id'], 'stop_all_alarms', ['count' => $count, 'scope_zone' => $scopeZone]);
            $message = "✅ Stopped {$count} active alarm" . ($count === 1 ? '' : 's') . ".";
        } catch (PDOException $e) {
            $message = 'Could not stop alarms.'; $messageType = 'danger';
        }
    }

    // SAVE AI SETTINGS
    if ($action === 'save_settings') {
        if (!$globalAiEnabled) {
            $message = '⛔ AI is globally disabled. Enable it in System Settings first.';
            $messageType = 'danger';
        } else {
            $sound = isset($_POST['sound_alerts_enabled']) ? 1 : 0;
            $auto  = isset($_POST['auto_ack_low_risk'])     ? 1 : 0;
            $mins  = max(1, min(120, (int)($_POST['auto_ack_minutes'] ?? 10)));
            try {
                $pdo->prepare('
                    INSERT INTO admin_ai_settings (admin_id, sound_alerts_enabled, auto_ack_low_risk, auto_ack_minutes)
                    VALUES (?, ?, ?, ?)
                     ON CONFLICT (admin_id) DO UPDATE SET 
                        sound_alerts_enabled = EXCLUDED.sound_alerts_enabled,
                        auto_ack_low_risk     = EXCLUDED.auto_ack_low_risk,
                        auto_ack_minutes      = EXCLUDED.auto_ack_minutes
                ')->execute([$user['id'], $sound, $auto, $mins]);
                $asSound = $sound === 1;
                $asAutoAck = $auto === 1;
                $asAutoAckMins = $mins;
                logAudit($user['id'], 'update_ai_settings', ['sound' => $sound, 'auto_ack' => $auto, 'minutes' => $mins]);
                $message = '✅ AI settings saved.';
            } catch (PDOException $e) {
                $message = 'Could not save AI settings.'; $messageType = 'danger';
            }
        }
    }

    // FEEDBACK (mark detection as false positive)
    if ($action === 'feedback' && $hasFeedback) {
        $detectionId = (int)($_POST['detection_id'] ?? 0);
        $verdict     = in_array($_POST['verdict'] ?? '', ['true_positive','false_positive','uncertain'], true) ? $_POST['verdict'] : 'uncertain';
        $notes       = trim((string)($_POST['notes'] ?? ''));
        try {
            $pdo->prepare("
                INSERT INTO ai_feedback (detection_v2_id, user_id, verdict, notes, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$detectionId ?: null, $user['id'], $verdict, $notes ?: null]);
            logAudit($user['id'], 'ai_feedback', ['detection_id' => $detectionId, 'verdict' => $verdict]);
            $message = '✅ Feedback recorded — thresholds will auto-adjust.';
        } catch (PDOException $e) {
            $message = 'Could not record feedback.'; $messageType = 'danger';
        }
    }
}

// ============================================================
// STATS
// ============================================================
$zf    = $filterZone > 0 ? 'WHERE zone_id = ' . (int)$filterZone : '';
$zfAnd = $filterZone > 0 ? 'AND zone_id = '   . (int)$filterZone : '';

$totalCameras    = $hasCameras ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM cctv_cameras") : 0;
$activeCameras   = $hasCameras ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE is_active = 1 " . $zfAnd) : 0;
$recordingCams   = $hasCameras ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM cctv_cameras WHERE is_recording = 1 " . $zfAnd) : 0;

$detTable = $detSrc === 'v2' ? 'ai_detections_v2' : ($detSrc === 'legacy' ? 'ai_detections' : null);

$todayDetections = $detTable ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM {$detTable} WHERE DATE(detected_at) = CURRENT_DATE " . $zfAnd) : 0;
$todayThreats    = $detTable ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM {$detTable} WHERE is_threat = 1 AND DATE(detected_at) = CURRENT_DATE " . $zfAnd) : 0;
$pendingAlerts   = $hasAiAlerts ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM ai_alerts WHERE is_acknowledged = 0 " . $zfAnd) : 0;
$activeAlarms    = $hasAiAlarms ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM alarm_triggers WHERE stopped_at IS NULL " . $zfAnd) : 0;

// v2 extras
$activeTracks    = $hasTracks ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM ai_tracks WHERE is_active = 1 " . $zfAnd) : 0;
$queuePending    = $hasQueue  ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM ai_queue WHERE status = 'pending'") : 0;
$feedbackTotal   = $hasFeedback ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM ai_feedback") : 0;
$feedbackFP      = $hasFeedback ? ai_dash_safe_count($pdo, "SELECT COUNT(*) as count FROM ai_feedback WHERE verdict = 'false_positive'") : 0;

// ============================================================
// BREAKDOWNS
// ============================================================
$detectionBreakdown = [];
$threatBreakdown    = [];
$behaviourBreakdown = [];

if ($detTable) {
    $detectionBreakdown = ai_dash_safe_fetch_all($pdo, "
        SELECT detection_type, COUNT(*) AS count
        FROM {$detTable}
        WHERE detected_at >= $windowSql " . $zfAnd . "
        GROUP BY detection_type ORDER BY count DESC
    ");

    $threatBreakdown = ai_dash_safe_fetch_all($pdo, "
        SELECT threat_level, COUNT(*) AS count
        FROM {$detTable}
        WHERE is_threat = 1 AND detected_at >= $windowSql " . $zfAnd . '
        GROUP BY threat_level
        ORDER BY CASE threat_level WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END
    ');
}

if ($detSrc === 'v2') {
    $behaviourBreakdown = ai_dash_safe_fetch_all($pdo, "
        SELECT behaviour, COUNT(*) AS count
        FROM ai_detections_v2
        WHERE behaviour IS NOT NULL AND detected_at >= $windowSql " . $zfAnd . "
        GROUP BY behaviour ORDER BY count DESC LIMIT 8
    ");
}

$alertSeverityBreakdown = $hasAiAlerts ? ai_dash_safe_fetch_all($pdo, "
    SELECT severity, COUNT(*) AS count
    FROM ai_alerts WHERE is_acknowledged = 0 " . $zfAnd . '
    GROUP BY severity ORDER BY CASE severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END
') : [];

// 7-day trend
$trendRows = $detTable ? ai_dash_safe_fetch_all($pdo, "
    SELECT DATE(detected_at) AS d, COUNT(*) AS c
    FROM {$detTable}
    WHERE is_threat = 1 AND detected_at >= (CURRENT_DATE - (6) * INTERVAL '1 day') " . $zfAnd . "
    GROUP BY DATE(detected_at)
") : [];
$trendByDate = [];
foreach ($trendRows as $r) { $trendByDate[$r['d']] = (int)$r['c']; }
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $trend[$d] = $trendByDate[$d] ?? 0;
}
$trendMax = max(1, max($trend));

// Per-zone breakdown
$zoneBreakdown = [];
if ($hasCameras && $detTable) {
    $zoneBreakdown = ai_dash_safe_fetch_all($pdo, "
        SELECT z.id, z.name,
            (SELECT COUNT(*) FROM cctv_cameras c WHERE c.zone_id = z.id) AS cameras,
            (SELECT COUNT(*) FROM {$detTable} d WHERE d.zone_id = z.id AND d.detected_at >= (NOW() - (24) * INTERVAL '1 hour')) AS det_24h,
            (SELECT COUNT(*) FROM {$detTable} d WHERE d.zone_id = z.id AND d.is_threat = 1 AND d.detected_at >= (NOW() - (24) * INTERVAL '1 hour')) AS threats_24h,
            " . ($hasAiAlerts ? "(SELECT COUNT(*) FROM ai_alerts a WHERE a.zone_id = z.id AND a.is_acknowledged = 0)" : "0") . " AS pending_alerts,
            " . ($hasAiAlarms ? "(SELECT COUNT(*) FROM alarm_triggers t WHERE t.zone_id = z.id AND t.stopped_at IS NULL)" : "0") . " AS active_alarms
        FROM zones z WHERE z.is_active = 1
        ORDER BY det_24h DESC, threats_24h DESC LIMIT 30
    ");
}

// ============================================================
// RECENT DETECTIONS
// ============================================================
$recentDetections = [];
if ($detSrc === 'v2') {
    $detWhere  = " WHERE d.detected_at >= $windowSql ";
    $detParams = [];
    if ($filterZone > 0)   { $detWhere .= " AND d.zone_id = ? ";        $detParams[] = $filterZone; }
    if ($filterType !== '') { $detWhere .= " AND d.detection_type = ? "; $detParams[] = $filterType; }
    if ($filterThreat !== '') {
        if ($filterThreat === 'safe') $detWhere .= " AND (d.is_threat = 0 OR d.is_threat IS NULL) ";
        else { $detWhere .= " AND d.is_threat = 1 AND d.threat_level = ? "; $detParams[] = $filterThreat; }
    }
    if ($filterCamera > 0)  { $detWhere .= " AND d.camera_id = ? ";      $detParams[] = $filterCamera; }
    if ($filterMinConf > 0) { $detWhere .= " AND d.confidence >= ? ";    $detParams[] = $filterMinConf / 100; }

    $recentDetections = ai_dash_safe_fetch_all($pdo, "
        SELECT d.id, d.detection_type, d.subclass, d.confidence, d.ensemble_confidence,
               d.is_threat, d.threat_level, d.threat_score,
               d.behaviour, d.behaviour_score, d.track_id,
               d.is_night, d.inside_zone, d.near_boundary,
               d.snapshot_url, d.detected_at,
               c.camera_name, z.name AS zone_name
        FROM ai_detections_v2 d
        LEFT JOIN cctv_cameras c ON d.camera_id = c.id
        LEFT JOIN zones z ON d.zone_id = z.id
        $detWhere
        ORDER BY d.detected_at DESC LIMIT " . (int)$detectionsLimit . "
    ", $detParams);
} elseif ($detSrc === 'legacy') {
    $detWhere  = " WHERE d.detected_at >= $windowSql ";
    $detParams = [];
    if ($filterZone > 0)   { $detWhere .= " AND d.zone_id = ? ";        $detParams[] = $filterZone; }
    if ($filterType !== '') { $detWhere .= " AND d.detection_type = ? "; $detParams[] = $filterType; }
    if ($filterCamera > 0)  { $detWhere .= " AND d.camera_id = ? ";      $detParams[] = $filterCamera; }
    if ($filterMinConf > 0) { $detWhere .= " AND d.confidence >= ? ";    $detParams[] = $filterMinConf / 100; }

    $recentDetections = ai_dash_safe_fetch_all($pdo, "
        SELECT d.*, c.camera_name, z.name AS zone_name
        FROM ai_detections d
        LEFT JOIN cctv_cameras c ON d.camera_id = c.id
        LEFT JOIN zones z ON d.zone_id = z.id
        $detWhere
        ORDER BY d.detected_at DESC LIMIT " . (int)$detectionsLimit . "
    ", $detParams);
}

// Cameras
$cameras = $hasCameras ? ai_dash_safe_fetch_all($pdo, "
    SELECT id, camera_name, camera_code, zone_id, is_active, is_recording, last_seen
    FROM cctv_cameras " . ($filterZone > 0 ? 'WHERE zone_id = ' . (int)$filterZone : '') . "
    ORDER BY camera_name
") : [];

// Pending alerts
$pendingAlertsList = $hasAiAlerts ? ai_dash_safe_fetch_all($pdo, "
    SELECT a.*, z.name AS zone_name
    FROM ai_alerts a LEFT JOIN zones z ON a.zone_id = z.id
    WHERE a.is_acknowledged = 0 " . $zfAnd . '
    ORDER BY CASE a.severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END, a.created_at DESC
    LIMIT ' . (int)$alertsLimit . "
") : [];

// Active alarms
$activeAlarmsList = $hasAiAlarms ? ai_dash_safe_fetch_all($pdo, "
    SELECT at.*, a.alarm_name, z.name AS zone_name
    FROM alarm_triggers at
    LEFT JOIN alarm_systems a ON at.alarm_id = a.id
    LEFT JOIN zones z ON at.zone_id = z.id
    WHERE at.stopped_at IS NULL " . $zfAnd . "
    ORDER BY at.triggered_at DESC LIMIT " . (int)$alarmsLimit . "
") : [];

// Active tracks (v2)
$activeTracksList = $hasTracks ? ai_dash_safe_fetch_all($pdo, "
    SELECT t.*, z.name AS zone_name, c.camera_name
    FROM ai_tracks t
    LEFT JOIN zones z ON t.zone_id = z.id
    LEFT JOIN cctv_cameras c ON t.camera_id = c.id
    WHERE t.is_active = 1 " . $zfAnd . "
    ORDER BY t.start_time DESC LIMIT 10
") : [];

// Models (v2)
$modelsList = $hasModels ? ai_dash_safe_fetch_all($pdo, "
    SELECT * FROM ai_models WHERE is_active = 1 ORDER BY is_default DESC, name
") : [];

// Latest IDs for poller
$latestDetectionId = 0;
$latestAlertId     = 0;
if ($detTable) {
    $r = ai_dash_safe_fetch_all($pdo, "SELECT MAX(id) AS mx FROM {$detTable} " . $zf);
    $latestDetectionId = (int)($r[0]['mx'] ?? 0);
}
if ($hasAiAlerts) {
    $r = ai_dash_safe_fetch_all($pdo, "SELECT MAX(id) AS mx FROM ai_alerts " . $zf);
    $latestAlertId = (int)($r[0]['mx'] ?? 0);
}

$detectionIcons = [
    'human'=>'👤','animal'=>'🦁','vehicle'=>'🚗',
    'fire'=>'🔥','gunshot'=>'💥','unknown'=>'❓',
];
$threatColors = ['critical'=>'red','high'=>'orange','medium'=>'orange','low'=>'green'];

// Summary panel variables
$zones = ai_dash_safe_fetch_all($pdo, "SELECT id, name FROM zones WHERE is_active = 1 ORDER BY name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Detection - Admin - Wildlife Sentinel</title>
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
        .stat-card .icon.pink   { background: #fce4ec; color: #c62828; }
        .stat-card .icon.muted  { background: #e9ecef; color: #6c757d; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }
        .stat-card .info .sub    { font-size: 10px; color: #adb5bd; margin-top: 1px; }
        .stat-card.updated { animation: flash 1s ease; }
        @keyframes flash { 0%,100% { background: white; } 50% { background: #e8f5ec; } }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .section-header .view-all { color: #1a5c3a; text-decoration: none; font-size: 13px; font-weight: 500; }
        .section-header .header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }
        .alert.warning { background: #fff3cd; color: #856404; }

        .two-col { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }

        .live-dot { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: #28a745; font-weight: 600; }
        .live-dot .dot { width: 8px; height: 8px; border-radius: 50%; background: #28a745; animation: pulseDot 1.6s infinite; }
        @keyframes pulseDot { 0%,100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.7); } }

        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }

        .state-banner {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; border-radius: 10px;
            margin-bottom: 14px; font-size: 12.5px;
        }
        .state-banner.warn { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
        .state-banner.info { background: #eef7f1; border: 1px solid #c3e6cb; color: #155724; }
        .state-banner a { color: inherit; text-decoration: underline; }
        .state-banner code { font-size: 11.5px; background: rgba(255,255,255,.6); padding: 1px 6px; border-radius: 4px; }

        .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 10.5px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .filter-group select { padding: 8px 12px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 12.5px; background: #fafafa; }
        .filter-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        .detection-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #f0f0f0; }
        .detection-item:last-child { border-bottom: none; }
        .detection-item.is-new { background: #e8f5ec; animation: slideIn 0.4s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
        .detection-item .det-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .detection-item .det-icon.threat { background: #f8d7da; color: #721c24; }
        .detection-item .det-icon.safe   { background: #d4edda; color: #155724; }
        .detection-item .det-info { flex: 1; min-width: 0; }
        .detection-item .det-title { font-weight: 600; font-size: 13px; color: #0d3b22; }
        .detection-item .det-meta  { font-size: 11px; color: #6c757d; margin-top: 2px; }

        .threat-badge { padding: 3px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .threat-badge.critical { background: #dc3545; color: white; animation: pulse 1.5s infinite; }
        .threat-badge.high     { background: #f8d7da; color: #721c24; }
        .threat-badge.medium   { background: #fff3cd; color: #856404; }
        .threat-badge.low      { background: #d4edda; color: #155724; }
        .threat-badge.safe     { background: #e9ecef; color: #495057; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.6; } }

        .alert-item { padding: 12px 14px; border-radius: 10px; margin-bottom: 8px; display: flex; align-items: center; gap: 12px; border-left: 4px solid #ffc107; background: #fffdf5; }
        .alert-item.critical { border-left-color: #dc3545; background: #fdf5f5; }
        .alert-item .alert-icon { font-size: 20px; }
        .alert-item .alert-info { flex: 1; min-width: 0; }
        .alert-item .alert-title { font-weight: 600; font-size: 13px; color: #0d3b22; }
        .alert-item .alert-desc  { font-size: 12px; color: #6c757d; margin-top: 2px; }
        .alert-item .alert-time  { font-size: 11px; color: #adb5bd; }
        .alert-item .alert-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

        .breakdown-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .breakdown-bar .label { font-size: 12px; width: 90px; text-transform: capitalize; color: #495057; flex-shrink: 0; }
        .breakdown-bar .track { flex: 1; height: 18px; background: #f0f0f0; border-radius: 10px; overflow: hidden; }
        .breakdown-bar .fill { height: 100%; border-radius: 10px; transition: width 0.8s ease; }
        .breakdown-bar .fill.blue   { background: #007bff; }
        .breakdown-bar .fill.green  { background: #28a745; }
        .breakdown-bar .fill.red    { background: #dc3545; }
        .breakdown-bar .fill.orange { background: #fd7e14; }
        .breakdown-bar .fill.purple { background: #6f42c1; }
        .breakdown-bar .count { font-size: 12px; font-weight: 600; width: 40px; text-align: right; color: #495057; }

        .quick-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
        .quick-tile { display: flex; flex-direction: column; align-items: center; padding: 18px 12px; background: #fafafa; border-radius: 10px; text-decoration: none; color: #495057; border: 2px solid transparent; transition: all 0.2s; }
        .quick-tile:hover { background: white; border-color: #1a5c3a; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .quick-tile .icon { font-size: 28px; margin-bottom: 6px; }
        .quick-tile .label { font-size: 12px; font-weight: 600; text-align: center; }
        .quick-tile .badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; background: #dc3545; color: white; margin-top: 4px; }

        .empty-state { text-align:center; padding:30px 20px; color:#6c757d; font-size:13px; }
        .empty-state .icon { font-size: 40px; display: block; margin-bottom: 8px; opacity: 0.4; }

        .cam-table, .zone-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .cam-table thead th, .zone-table thead th { text-align: left; font-size: 10.5px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 10px; border-bottom: 2px solid #f0f0f0; background: #fafafa; font-weight: 700; }
        .cam-table tbody td, .zone-table tbody td { padding: 10px; border-bottom: 1px solid #f5f5f5; }
        .cam-table tbody tr:hover, .zone-table tbody tr:hover { background: #fafafa; }
        .zone-table a { color: #1a5c3a; font-weight: 600; text-decoration: none; }
        .zone-table a:hover { text-decoration: underline; }
        .zone-table .zone-sub { font-size: 10.5px; color: #adb5bd; margin-top: 2px; }

        .trend-chart { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; align-items: end; height: 70px; margin-top: 6px; }
        .trend-bar { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .trend-bar .bar { width: 100%; background: linear-gradient(180deg, #dc3545, #f87171); border-radius: 4px 4px 0 0; min-height: 3px; transition: height 0.3s; }
        .trend-bar .bar-label { font-size: 9px; color: #6c757d; text-transform: uppercase; }
        .trend-bar .bar-count { font-size: 10px; font-weight: 700; color: #0d3b22; }

        .settings-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f5f5f5; }
        .settings-row:last-child { border-bottom: none; }
        .settings-row .sr-label { font-size: 13px; color: #0d3b22; font-weight: 600; }
        .settings-row .sr-hint  { font-size: 11px; color: #6c757d; margin-top: 2px; }
        .settings-row input[type="number"] { width: 70px; padding: 6px 10px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 12.5px; }
        .settings-row.readonly { opacity: 0.9; }
        .settings-row code { font-size: 11px; background: #f5f5f5; padding: 1px 6px; border-radius: 4px; }

        .toggle { position: relative; width: 42px; height: 22px; }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle .slider { position: absolute; inset: 0; background: #ccc; border-radius: 22px; cursor: pointer; transition: 0.2s; }
        .toggle .slider::before { content: ''; position: absolute; height: 16px; width: 16px; left: 3px; top: 3px; background: white; border-radius: 50%; transition: 0.2s; }
        .toggle input:checked + .slider { background: #1a5c3a; }
        .toggle input:checked + .slider::before { transform: translateX(20px); }
        .toggle.disabled { opacity: 0.5; pointer-events: none; }

        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 3000; display: flex; flex-direction: column; gap: 10px; max-width: 340px; }
        .toast { background: white; border-radius: 12px; padding: 12px 16px; box-shadow: 0 8px 30px rgba(0,0,0,0.15); border-left: 4px solid #ffc107; animation: toastIn 0.3s ease; display: flex; gap: 10px; align-items: flex-start; }
        .toast.critical { border-left-color: #dc3545; background: #fdf5f5; }
        .toast.info     { border-left-color: #17a2b8; background: #f0fbfd; }
        .toast .t-icon { font-size: 20px; flex-shrink: 0; }
        .toast .t-body { flex: 1; min-width: 0; }
        .toast .t-title { font-size: 13px; font-weight: 700; color: #0d3b22; }
        .toast .t-desc  { font-size: 11.5px; color: #6c757d; margin-top: 2px; line-height: 1.4; }
        .toast .t-close { background: none; border: none; color: #adb5bd; cursor: pointer; font-size: 16px; padding: 0; }
        @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }

        .track-card { padding: 10px 14px; border-radius: 10px; background: #f8f9fa; border-left: 3px solid #6f42c1; margin-bottom: 8px; font-size: 12.5px; }
        .track-card .track-title { font-weight: 600; color: #0d3b22; }
        .track-card .track-meta { font-size: 11px; color: #6c757d; margin-top: 2px; }

        @media (max-width: 1024px) { .two-col { grid-template-columns: 1fr; } }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .toast-container { left: 12px; right: 12px; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>AI Detection</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name'] ?? 'Admin') ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting" style="display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap;">
                    <div>
                        <h1>🤖 AI Detection Center</h1>
                        <p>Real-time AI camera analysis, threat detection, and alarm status<?= $filterZone > 0 ? ' — filtered to a single zone' : ' — all zones' ?>.</p>
                    </div>
                    <span class="live-dot" id="liveIndicator"><span class="dot"></span> Live — updating every 15s</span>
                </div>

                <div class="quick-nav">
                    <a href="ai-dashboard.php" class="btn btn-secondary">🤖 AI Dashboard</a>
                    <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                    <a href="cctv-cameras.php" class="btn btn-secondary">📹 Cameras</a>
                    <a href="alarm-systems.php" class="btn btn-secondary">🔔 Alarms</a>
                    <a href="sms-gateway.php" class="btn btn-secondary">📱 SMS</a>
                    <a href="simulation.php" class="btn btn-secondary">🎮 Simulation</a>
                    <a href="settings.php" class="btn btn-secondary">⚙️ Settings</a>
                </div>

                <?php if ($detSrc === 'none'): ?>
                    <div class="state-banner warn">
                        <span style="font-size:18px;">ℹ️</span>
                        <div>
                            <strong>No AI detection table found.</strong>
                            Import the AI v2 schema or run the legacy <code>ai_detections</code> table.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$globalAiEnabled): ?>
                    <div class="state-banner warn">
                        <span style="font-size:20px;">⛔</span>
                        <div>
                            <strong>AI Detection is globally disabled.</strong>
                            Detections shown below are historical. Live polling is paused.
                            Enable it in <a href="settings.php">System Settings → AI / CCTV</a>.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="state-banner info">
                        <span style="font-size:18px;">🤖</span>
                        <div>
                            AI pipeline <strong>active</strong> —
                            engine <strong><?= $detSrc === 'v2' ? 'v2' : 'legacy' ?></strong>,
                            confidence floor <strong><?= (int)$confidenceFloorPct ?>%</strong>,
                            auto-create alerts <strong><?= $globalAiAutoCreate ? 'ON' : 'OFF' ?></strong>,
                            auto-trigger alarms <strong><?= $globalAiAutoAlarm ? 'ON' : 'OFF' ?></strong>,
                            snapshots <code><?= htmlspecialchars($globalCctvSnapshotDir) ?></code>,
                            retention <strong><?= (int)$globalCctvRetention ?>d</strong>.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$globalNotifyAiAlert || !$globalNotifyAlarm): ?>
                    <div class="state-banner warn">
                        <span>🔕</span>
                        <div>
                            Notifications suppressed:
                            <?php if (!$globalNotifyAiAlert): ?><strong>AI alerts</strong><?php endif; ?>
                            <?php if (!$globalNotifyAlarm): ?><?= !$globalNotifyAiAlert ? ' and ' : '' ?><strong>Alarm triggers</strong><?php endif; ?>
                            are not being sent.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="alert <?= htmlspecialchars($messageType) ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card" data-stat="active_cameras">
                        <div class="icon <?= $totalCameras === 0 ? 'muted' : 'blue' ?>">📹</div>
                        <div class="info">
                            <div class="number"><?= (int)$activeCameras ?>/<?= (int)$totalCameras ?></div>
                            <div class="label">Cameras Online</div>
                        </div>
                    </div>
                    <div class="stat-card" data-stat="recording">
                        <div class="icon red">⏺️</div>
                        <div class="info"><div class="number"><?= (int)$recordingCams ?></div><div class="label">Recording</div></div>
                    </div>
                    <div class="stat-card" data-stat="today_detections">
                        <div class="icon <?= !$globalAiEnabled ? 'muted' : 'purple' ?>">🤖</div>
                        <div class="info">
                            <div class="number"><?= (int)$todayDetections ?></div>
                            <div class="label">Detections Today</div>
                            <?php if (!$globalAiEnabled): ?><div class="sub">⛔ AI paused</div><?php endif; ?>
                        </div>
                    </div>
                    <div class="stat-card" data-stat="today_threats">
                        <div class="icon <?= !$globalAiEnabled ? 'muted' : 'pink' ?>">🚨</div>
                        <div class="info"><div class="number"><?= (int)$todayThreats ?></div><div class="label">Threats Today</div></div>
                    </div>
                    <div class="stat-card" data-stat="pending_alerts">
                        <div class="icon orange">⚠️</div>
                        <div class="info"><div class="number"><?= (int)$pendingAlerts ?></div><div class="label">Pending Alerts</div></div>
                    </div>
                    <div class="stat-card" data-stat="active_alarms">
                        <div class="icon red">🔔</div>
                        <div class="info"><div class="number"><?= (int)$activeAlarms ?></div><div class="label">Active Alarms</div></div>
                    </div>
                </div>

                <!-- v2 sub-stats -->
                <?php if ($detSrc === 'v2'): ?>
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon purple">🎯</div><div class="info"><div class="number"><?= (int)$activeTracks ?></div><div class="label">Active Tracks</div></div></div>
                    <div class="stat-card"><div class="icon <?= $queuePending > 0 ? 'orange' : 'green' ?>">⏱️</div><div class="info"><div class="number"><?= (int)$queuePending ?></div><div class="label">Queue Jobs</div></div></div>
                    <div class="stat-card"><div class="icon <?= $feedbackFP > 0 ? 'orange' : 'green' ?>">📊</div><div class="info"><div class="number"><?= (int)$feedbackTotal ?></div><div class="label">Feedback Items</div><div class="sub"><?= (int)$feedbackFP ?> marked FP</div></div></div>
                </div>
                <?php endif; ?>

                <!-- Filters -->
                <form method="GET" class="section" style="padding:14px 18px;">
                    <div class="filter-bar" style="margin:0;">
                        <div class="filter-group">
                            <label>Zone</label>
                            <select name="zone" onchange="this.form.submit()">
                                <option value="0">All zones</option>
                                <?php foreach ($zones as $z): ?>
                                    <option value="<?= (int)$z['id'] ?>" <?= $filterZone === (int)$z['id'] ? 'selected' : '' ?>><?= htmlspecialchars($z['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Type</label>
                            <select name="dtype" onchange="this.form.submit()">
                                <option value="">All types</option>
                                <?php foreach (['human','animal','vehicle','fire','gunshot','unknown'] as $t): ?>
                                    <option value="<?= $t ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Threat</label>
                            <select name="threat" onchange="this.form.submit()">
                                <option value="">All</option>
                                <option value="critical" <?= $filterThreat === 'critical' ? 'selected' : '' ?>>Critical</option>
                                <option value="high"     <?= $filterThreat === 'high'     ? 'selected' : '' ?>>High</option>
                                <option value="medium"   <?= $filterThreat === 'medium'   ? 'selected' : '' ?>>Medium</option>
                                <option value="low"      <?= $filterThreat === 'low'      ? 'selected' : '' ?>>Low</option>
                                <option value="safe"     <?= $filterThreat === 'safe'     ? 'selected' : '' ?>>Safe only</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Camera</label>
                            <select name="camera" onchange="this.form.submit()">
                                <option value="0">All cameras</option>
                                <?php foreach ($cameras as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= $filterCamera === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['camera_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Window</label>
                            <select name="win" onchange="this.form.submit()">
                                <option value="1h"  <?= $filterWindow === '1h'  ? 'selected' : '' ?>>Last 1 hour</option>
                                <option value="24h" <?= $filterWindow === '24h' ? 'selected' : '' ?>>Last 24 hours</option>
                                <option value="7d"  <?= $filterWindow === '7d'  ? 'selected' : '' ?>>Last 7 days</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Min confidence</label>
                            <select name="minconf" onchange="this.form.submit()">
                                <?php foreach ([0,50,60,70,80,90] as $c): ?>
                                    <option value="<?= $c ?>" <?= $filterMinConf === $c ? 'selected' : '' ?>><?= $c === 0 ? 'Any' : $c . '%+' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <a href="ai-dashboard.php" class="btn btn-secondary btn-sm">✕ Clear</a>
                        </div>
                    </div>
                </form>

                <div class="two-col">
                    <!-- LEFT -->
                    <div>
                        <div class="section">
                            <div class="section-header">
                                <h2>🤖 Recent AI Detections</h2>
                                <div class="header-actions">
                                    <a href="?ajax=export&win=<?= urlencode($filterWindow) ?>&zone=<?= (int)$filterZone ?>&dtype=<?= urlencode($filterType) ?>&minconf=<?= (int)$filterMinConf ?>" class="btn btn-secondary btn-sm">⬇️ CSV</a>
                                    <a href="cctv-cameras.php" class="view-all">Manage Cameras →</a>
                                </div>
                            </div>

                            <div id="detectionList">
                                <?php if (count($recentDetections) > 0): ?>
                                    <?php foreach ($recentDetections as $d): ?>
                                        <?php
                                        $icon = $detectionIcons[$d['detection_type']] ?? '❓';
                                        $scoreLabel = isset($d['threat_score']) && $d['threat_score'] !== null
                                            ? ' • 🎯 ' . round((float)$d['threat_score']) . '/100' : '';
                                        $ensLabel = !empty($d['ensemble_confidence'])
                                            ? ' • 🧠 ' . round((float)$d['ensemble_confidence'] * 100) . '%' : '';
                                        $behLabel = !empty($d['behaviour'])
                                            ? ' • 🎭 ' . htmlspecialchars(str_replace('_', ' ', $d['behaviour'])) : '';
                                        ?>
                                        <div class="detection-item">
                                            <div class="det-icon <?= !empty($d['is_threat']) ? 'threat' : 'safe' ?>"><?= $icon ?></div>
                                            <div class="det-info">
                                                <div class="det-title">
                                                    <?= htmlspecialchars(ucfirst($d['detection_type'] ?? 'unknown')) ?>
                                                    <?php if (!empty($d['subclass'])): ?>
                                                        <span style="color:#6c757d;font-weight:400;">(<?= htmlspecialchars($d['subclass']) ?>)</span>
                                                    <?php endif; ?>
                                                    <?= !empty($d['camera_name']) ? ' — ' . htmlspecialchars($d['camera_name']) : '' ?>
                                                </div>
                                                <div class="det-meta">
                                                    🕐 <?= timeAgo($d['detected_at'] ?? null) ?>
                                                    <?php if (isset($d['confidence'])): ?> • 🎯 <?= round((float)$d['confidence'] * 100) ?>%<?php endif; ?>
                                                    <?= $ensLabel ?>
                                                    <?= $scoreLabel ?>
                                                    <?= $behLabel ?>
                                                    <?php if (!empty($d['zone_name'])): ?> • 📍 <?= htmlspecialchars($d['zone_name']) ?><?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="threat-badge <?= !empty($d['is_threat']) ? ($d['threat_level'] ?? 'medium') : 'safe' ?>">
                                                <?= !empty($d['is_threat']) ? '🚨 ' . strtoupper($d['threat_level'] ?? 'THREAT') : '✅ SAFE' ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">
                                        <span class="icon">🤖</span>
                                        <?= $globalAiEnabled ? 'No AI detections match the current filters.' : '⛔ AI pipeline is paused.' ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="section">
                            <div class="section-header"><h2>📊 Detection Breakdown (<?= htmlspecialchars($filterWindow) ?>)</h2></div>
                            <?php if (count($detectionBreakdown) > 0): ?>
                                <?php
                                $maxDet = 1;
                                foreach ($detectionBreakdown as $b) if ($b['count'] > $maxDet) $maxDet = $b['count'];
                                $detColors = ['human'=>'blue','animal'=>'green','vehicle'=>'purple','fire'=>'red','gunshot'=>'red','unknown'=>'orange'];
                                ?>
                                <?php foreach ($detectionBreakdown as $b): ?>
                                    <div class="breakdown-bar">
                                        <span class="label"><?= htmlspecialchars($b['detection_type']) ?></span>
                                        <div class="track"><div class="fill <?= $detColors[$b['detection_type']] ?? 'blue' ?>" style="width: <?= ($b['count'] / $maxDet) * 100 ?>%;"></div></div>
                                        <span class="count"><?= (int)$b['count'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">No detections in this window.</div>
                            <?php endif; ?>
                        </div>

                        <div class="section">
                            <div class="section-header"><h2>🚨 Threat Levels (<?= htmlspecialchars($filterWindow) ?>)</h2></div>
                            <?php if (count($threatBreakdown) > 0): ?>
                                <?php
                                $maxThreat = 1;
                                foreach ($threatBreakdown as $b) if ($b['count'] > $maxThreat) $maxThreat = $b['count'];
                                ?>
                                <?php foreach ($threatBreakdown as $b): ?>
                                    <div class="breakdown-bar">
                                        <span class="label"><?= htmlspecialchars($b['threat_level']) ?></span>
                                        <div class="track"><div class="fill <?= $threatColors[$b['threat_level']] ?? 'red' ?>" style="width: <?= ($b['count'] / $maxThreat) * 100 ?>%;"></div></div>
                                        <span class="count"><?= (int)$b['count'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">No threats in this window.</div>
                            <?php endif; ?>

                            <div style="margin-top:16px;padding-top:14px;border-top:1px dashed #e0e0e0;">
                                <div style="font-size:12px;color:#6c757d;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;margin-bottom:6px;">📈 7-day Threat Trend</div>
                                <div class="trend-chart">
                                    <?php foreach ($trend as $d => $c): ?>
                                        <div class="trend-bar" title="<?= htmlspecialchars($d) ?>: <?= (int)$c ?> threats">
                                            <span class="bar-count"><?= (int)$c ?></span>
                                            <div class="bar" style="height: <?= max(3, (int)(($c / $trendMax) * 55)) ?>px;"></div>
                                            <span class="bar-label"><?= date('D', strtotime($d)) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($detSrc === 'v2' && count($behaviourBreakdown) > 0): ?>
                        <div class="section">
                            <div class="section-header"><h2>🎭 Behaviour Breakdown</h2></div>
                            <?php
                            $maxBeh = 1;
                            foreach ($behaviourBreakdown as $b) if ($b['count'] > $maxBeh) $maxBeh = $b['count'];
                            ?>
                            <?php foreach ($behaviourBreakdown as $b): ?>
                                <div class="breakdown-bar">
                                    <span class="label"><?= htmlspecialchars(str_replace('_',' ',$b['behaviour'])) ?></span>
                                    <div class="track"><div class="fill purple" style="width: <?= ($b['count'] / $maxBeh) * 100 ?>%;"></div></div>
                                    <span class="count"><?= (int)$b['count'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (count($zoneBreakdown) > 0): ?>
                        <div class="section">
                            <div class="section-header"><h2>🗺️ Per-Zone Breakdown (24h)</h2><span style="font-size:11.5px;color:#6c757d;"><?= count($zoneBreakdown) ?> zones</span></div>
                            <div style="overflow-x:auto;">
                                <table class="zone-table">
                                    <thead><tr><th>Zone</th><th style="text-align:right;">Cameras</th><th style="text-align:right;">Detections</th><th style="text-align:right;">Threats</th><th style="text-align:right;">Pending</th><th style="text-align:right;">Alarms</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($zoneBreakdown as $zb): ?>
                                        <tr>
                                            <td>
                                                <a href="?zone=<?= (int)$zb['id'] ?>"><?= htmlspecialchars($zb['name']) ?></a>
                                                <div class="zone-sub"><a href="incidents.php?zone_id=<?= (int)$zb['id'] ?>&report=zone">view incidents →</a></div>
                                            </td>
                                            <td style="text-align:right;"><?= (int)$zb['cameras'] ?></td>
                                            <td style="text-align:right;"><?= (int)$zb['det_24h'] ?></td>
                                            <td style="text-align:right;color:<?= $zb['threats_24h'] > 0 ? '#dc3545' : '#6c757d' ?>;font-weight:<?= $zb['threats_24h'] > 0 ? 700 : 400 ?>;"><?= (int)$zb['threats_24h'] ?></td>
                                            <td style="text-align:right;color:<?= $zb['pending_alerts'] > 0 ? '#856404' : '#6c757d' ?>;font-weight:<?= $zb['pending_alerts'] > 0 ? 700 : 400 ?>;"><?= (int)$zb['pending_alerts'] ?></td>
                                            <td style="text-align:right;color:<?= $zb['active_alarms'] > 0 ? '#721c24' : '#6c757d' ?>;font-weight:<?= $zb['active_alarms'] > 0 ? 700 : 400 ?>;"><?= (int)$zb['active_alarms'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- RIGHT -->
                    <div>
                        <!-- Active Alarms (always) -->
                        <div class="section" style="border-left:4px solid <?= count($activeAlarmsList) > 0 ? '#dc3545' : '#adb5bd' ?>;">
                            <div class="section-header">
                                <h2>🔔 Active Alarms <?php if (count($activeAlarmsList) > 0): ?><span class="threat-badge critical"><?= count($activeAlarmsList) ?> LIVE</span><?php endif; ?></h2>
                                <?php if (count($activeAlarmsList) > 0): ?>
                                <div class="header-actions">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('⏹️ Stop ALL active alarms?');">
                                        <input type="hidden" name="action" value="stop_all_alarms">
                                        <input type="hidden" name="scope_zone" value="0">
                                        <button class="btn btn-danger btn-sm">⏹️ Stop All</button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if (count($activeAlarmsList) > 0): ?>
                                <?php foreach ($activeAlarmsList as $a): ?>
                                    <div class="alert-item critical">
                                        <div class="alert-icon">🚨</div>
                                        <div class="alert-info">
                                            <div class="alert-title"><?= htmlspecialchars($a['alarm_name'] ?? 'Unknown alarm') ?></div>
                                            <div class="alert-desc">
                                                📍 <?= htmlspecialchars($a['zone_name'] ?? 'Unknown zone') ?>
                                                • <?= htmlspecialchars(ucfirst($a['triggered_by'] ?? 'manual')) ?>
                                                <?php if (!empty($a['trigger_reason'])): ?> • <?= htmlspecialchars($a['trigger_reason']) ?><?php endif; ?>
                                            </div>
                                            <div class="alert-time">🕐 <?= timeAgo($a['triggered_at'] ?? null) ?> • running <?= max(0, time() - strtotime($a['triggered_at'] ?? 'now')) ?>s</div>
                                        </div>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('⏹️ Stop this alarm?');">
                                            <input type="hidden" name="action" value="stop_alarm">
                                            <input type="hidden" name="trigger_id" value="<?= (int)$a['id'] ?>">
                                            <button class="btn btn-danger btn-sm">⏹️ Stop</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><span class="icon">✅</span>No active alarms. All clear.</div>
                            <?php endif; ?>
                        </div>

                        <!-- Pending Alerts -->
                        <div class="section">
                            <div class="section-header"><h2>⚠️ Pending AI Alerts</h2></div>

                            <?php if (count($alertSeverityBreakdown) > 0): ?>
                                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                                    <?php foreach ($alertSeverityBreakdown as $sb): ?>
                                        <?php $sevClass = ['critical'=>'critical','high'=>'high','medium'=>'medium','low'=>'low'][$sb['severity']] ?? 'low'; ?>
                                        <span class="threat-badge <?= $sevClass ?>"><?= strtoupper($sb['severity']) ?> · <?= (int)$sb['count'] ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div id="pendingAlertsList">
                                <?php if (count($pendingAlertsList) > 0): ?>
                                    <?php foreach ($pendingAlertsList as $a): ?>
                                        <div class="alert-item <?= ($a['severity'] ?? '') === 'critical' ? 'critical' : '' ?>">
                                            <div class="alert-icon"><?= ($a['severity'] ?? '') === 'critical' ? '🚨' : '⚠️' ?></div>
                                            <div class="alert-info">
                                                <div class="alert-title"><?= htmlspecialchars($a['title'] ?? 'Alert') ?></div>
                                                <div class="alert-desc"><?= htmlspecialchars($a['zone_name'] ?? 'Unknown zone') ?> • <?= htmlspecialchars(str_replace('_',' ',$a['alert_type'] ?? 'other')) ?></div>
                                                <div class="alert-time"><?= timeAgo($a['created_at'] ?? null) ?></div>
                                            </div>
                                            <div class="alert-actions">
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="ack_alert">
                                                    <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
                                                    <button class="btn btn-primary btn-sm">✅ Ack</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state"><span class="icon">✅</span>No pending AI alerts.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($detSrc === 'v2' && count($activeTracksList) > 0): ?>
                        <div class="section">
                            <div class="section-header"><h2>🎯 Active Tracks</h2></div>
                            <?php foreach ($activeTracksList as $t): ?>
                                <div class="track-card">
                                    <div class="track-title"><?= htmlspecialchars(ucfirst($t['primary_type'])) ?> — <?= htmlspecialchars($t['track_uid']) ?></div>
                                    <div class="track-meta">
                                        📹 <?= htmlspecialchars($t['camera_name'] ?? '—') ?>
                                        • 🕐 <?= timeAgo($t['start_time']) ?>
                                        • 🎞️ <?= (int)$t['frame_count'] ?> frames
                                        <?php if ($t['duration_seconds']): ?> • ⏱️ <?= (int)$t['duration_seconds'] ?>s<?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($detSrc === 'v2' && count($modelsList) > 0): ?>
                        <div class="section">
                            <div class="section-header"><h2>🧠 Active Models</h2></div>
                            <?php foreach ($modelsList as $m): ?>
                                <div class="settings-row readonly">
                                    <div>
                                        <div class="sr-label"><?= htmlspecialchars($m['name']) ?> <code>v<?= htmlspecialchars($m['version']) ?></code></div>
                                        <div class="sr-hint">
                                            <?= htmlspecialchars($m['model_type']) ?>
                                            <?php if (!empty($m['framework'])): ?> • <?= htmlspecialchars($m['framework']) ?><?php endif; ?>
                                            • <?= (int)$m['avg_latency_ms'] ?>ms
                                            <?php if (!empty($m['accuracy'])): ?> • acc <?= round((float)$m['accuracy'] * 100) ?>%<?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ((int)$m['is_default'] === 1): ?><span class="threat-badge low">DEFAULT</span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- AI Settings -->
                        <div class="section">
                            <div class="section-header"><h2>⚙️ My AI Preferences</h2></div>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_settings">
                                <div class="settings-row">
                                    <div>
                                        <div class="sr-label">🔊 Sound alert on critical events</div>
                                        <div class="sr-hint">Play a short beep when a critical alert arrives.</div>
                                    </div>
                                    <label class="toggle <?= !$globalAiEnabled ? 'disabled' : '' ?>">
                                        <input type="checkbox" name="sound_alerts_enabled" value="1" <?= $asSound ? 'checked' : '' ?> <?= !$globalAiEnabled ? 'disabled' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="settings-row">
                                    <div>
                                        <div class="sr-label">🤖 Auto-acknowledge low-risk</div>
                                        <div class="sr-hint">Automatically ack low-severity alerts after a delay.</div>
                                    </div>
                                    <label class="toggle <?= !$globalAiEnabled ? 'disabled' : '' ?>">
                                        <input type="checkbox" name="auto_ack_low_risk" value="1" <?= $asAutoAck ? 'checked' : '' ?> <?= !$globalAiEnabled ? 'disabled' : '' ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                                <div class="settings-row">
                                    <div>
                                        <div class="sr-label">⏱️ Delay (minutes)</div>
                                        <div class="sr-hint">Used by auto-acknowledge.</div>
                                    </div>
                                    <input type="number" name="auto_ack_minutes" min="1" max="120" value="<?= (int)$asAutoAckMins ?>" <?= !$globalAiEnabled ? 'disabled' : '' ?>>
                                </div>

                                <div class="settings-row readonly">
                                    <div><div class="sr-label">🗂️ Snapshot directory</div><div class="sr-hint"><code><?= htmlspecialchars($globalCctvSnapshotDir) ?></code></div></div>
                                    <span style="font-size:11px;color:#6c757d;">from System Settings</span>
                                </div>
                                <div class="settings-row readonly">
                                    <div><div class="sr-label">🗓️ CCTV retention</div><div class="sr-hint"><?= (int)$globalCctvRetention ?> days</div></div>
                                    <a href="settings.php" class="btn btn-secondary btn-sm">Edit</a>
                                </div>

                                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:10px;width:100%;justify-content:center;" <?= !$globalAiEnabled ? 'disabled' : '' ?>>💾 Save AI Settings</button>
                            </form>
                        </div>

                        <div class="section">
                            <div class="section-header"><h2>⚡ Quick Actions</h2></div>
                            <div class="quick-grid">
                                <a href="cctv-cameras.php" class="quick-tile"><span class="icon">📹</span><span class="label">Cameras</span><?php if ($activeCameras > 0): ?><span class="badge"><?= (int)$activeCameras ?></span><?php endif; ?></a>
                                <a href="alarm-systems.php" class="quick-tile"><span class="icon">🔔</span><span class="label">Alarms</span><?php if ($activeAlarms > 0): ?><span class="badge"><?= (int)$activeAlarms ?></span><?php endif; ?></a>
                                <a href="sms-gateway.php" class="quick-tile"><span class="icon">📱</span><span class="label">SMS</span></a>
                                <a href="simulation.php" class="quick-tile"><span class="icon">🎮</span><span class="label">Simulation</span></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        const FILTER_ZONE       = <?= (int)$filterZone ?>;
        const MIN_CONF          = <?= (int)$filterMinConf ?>;
        const SOUND_ENABLED     = <?= $asSound && $globalAiEnabled ? 'true' : 'false' ?>;
        const AI_ENABLED        = <?= $globalAiEnabled ? 'true' : 'false' ?>;
        const POLL_INTERVAL_MS  = 15000;

        let latestDetectionId = <?= (int)$latestDetectionId ?>;
        let latestAlertId     = <?= (int)$latestAlertId ?>;
        let soundReady        = false;

        const detectionIcons = { human:'👤', animal:'🦁', vehicle:'🚗', fire:'🔥', gunshot:'💥', unknown:'❓' };

        function playBeep() {
            if (!SOUND_ENABLED || !soundReady) return;
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.0001, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
                osc.connect(gain).connect(ctx.destination);
                osc.start(); osc.stop(ctx.currentTime + 0.4);
            } catch (e) {}
        }

        function showToast(title, desc, type) {
            const c = document.getElementById('toastContainer');
            const el = document.createElement('div');
            el.className = 'toast ' + (type || '');
            el.innerHTML = `
                <div class="t-icon">${type === 'critical' ? '🚨' : (type === 'info' ? 'ℹ️' : '⚠️')}</div>
                <div class="t-body"><div class="t-title"></div><div class="t-desc"></div></div>
                <button class="t-close" onclick="this.parentElement.remove()">✕</button>
            `;
            el.querySelector('.t-title').textContent = title || '';
            el.querySelector('.t-desc').textContent  = desc  || '';
            c.appendChild(el);
            setTimeout(() => el.remove(), 8000);
        }

        function escapeHtml(s) {
            return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
        }

        function updateStats(stats) {
            Object.keys(stats).forEach(function (k) {
                const card = document.querySelector('.stat-card[data-stat="' + k + '"]');
                if (!card) return;
                const num = card.querySelector('.number');
                if (!num) return;
                if (num.textContent.trim() !== String(stats[k])) {
                    num.textContent = stats[k];
                    card.classList.add('updated');
                    setTimeout(() => card.classList.remove('updated'), 1000);
                }
            });
        }

        function appendDetections(list) {
            const container = document.getElementById('detectionList');
            if (!container) return;
            const empty = container.querySelector('.empty-state');
            if (empty) empty.remove();

            list.slice().reverse().forEach(function (d) {
                const icon = detectionIcons[d.detection_type] || '❓';
                const item = document.createElement('div');
                item.className = 'detection-item is-new';
                const sub = d.subclass ? ' <span style="color:#6c757d;font-weight:400;">(' + escapeHtml(d.subclass) + ')</span>' : '';
                const scoreLabel = d.threat_score != null ? ' • 🎯 ' + Math.round(d.threat_score) + '/100' : '';
                const ensLabel = d.ensemble_confidence ? ' • 🧠 ' + Math.round(d.ensemble_confidence * 100) + '%' : '';
                item.innerHTML = `
                    <div class="det-icon ${d.is_threat ? 'threat' : 'safe'}">${icon}</div>
                    <div class="det-info">
                        <div class="det-title">${escapeHtml((d.detection_type || 'unknown').charAt(0).toUpperCase() + (d.detection_type || 'unknown').slice(1))}${sub}${d.camera_name ? ' — ' + escapeHtml(d.camera_name) : ''}</div>
                        <div class="det-meta">🕐 just now${d.confidence ? ' • 🎯 ' + Math.round(d.confidence * 100) + '%' : ''}${ensLabel}${scoreLabel}${d.zone_name ? ' • 📍 ' + escapeHtml(d.zone_name) : ''}</div>
                    </div>
                    <span class="threat-badge ${d.is_threat ? (d.threat_level || 'medium') : 'safe'}">${d.is_threat ? '🚨 ' + (d.threat_level || 'threat').toUpperCase() : '✅ SAFE'}</span>
                `;
                container.insertBefore(item, container.firstChild);

                if (d.is_threat) {
                    showToast(
                        'Threat detected',
                        (d.detection_type || 'unknown') + (d.camera_name ? ' — ' + d.camera_name : '') + (d.zone_name ? ' (' + d.zone_name + ')' : '') + (d.threat_level ? ' · ' + d.threat_level : ''),
                        d.threat_level === 'critical' ? 'critical' : ''
                    );
                    if (d.threat_level === 'critical') playBeep();
                }
            });

            while (container.children.length > 25) container.removeChild(container.lastChild);
        }

        function prependAlerts(list) {
            const container = document.getElementById('pendingAlertsList');
            if (!container) return;
            const empty = container.querySelector('.empty-state');
            if (empty) empty.remove();

            list.slice().reverse().forEach(function (a) {
                const item = document.createElement('div');
                item.className = 'alert-item ' + (a.severity === 'critical' ? 'critical' : '');
                item.innerHTML = `
                    <div class="alert-icon">${a.severity === 'critical' ? '🚨' : '⚠️'}</div>
                    <div class="alert-info">
                        <div class="alert-title">${escapeHtml(a.title || 'Alert')}</div>
                        <div class="alert-desc">${escapeHtml(a.zone_name || '')} ${a.alert_type ? '• ' + escapeHtml(a.alert_type.replace(/_/g,' ')) : ''}</div>
                        <div class="alert-time">just now</div>
                    </div>
                    <div class="alert-actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="ack_alert">
                            <input type="hidden" name="alert_id" value="${a.id}">
                            <button class="btn btn-primary btn-sm">✅ Ack</button>
                        </form>
                    </div>
                `;
                container.insertBefore(item, container.firstChild);
                showToast(a.severity === 'critical' ? '🚨 Critical AI alert' : 'AI alert', a.title || (a.alert_type || 'New alert'), a.severity === 'critical' ? 'critical' : '');
                if (a.severity === 'critical') playBeep();
            });

            while (container.children.length > 12) container.removeChild(container.lastChild);
        }

        async function poll() {
            try {
                const url = `?ajax=poll&last_detection=${latestDetectionId}&last_alert=${latestAlertId}&zone=${FILTER_ZONE}&minconf=${MIN_CONF}`;
                const res = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) return;
                if (data.ai_disabled) return;

                if (data.stats) updateStats(data.stats);

                if (Array.isArray(data.new_detections) && data.new_detections.length > 0) {
                    appendDetections(data.new_detections);
                    data.new_detections.forEach(d => { if (+d.id > latestDetectionId) latestDetectionId = +d.id; });
                }
                if (Array.isArray(data.new_alerts) && data.new_alerts.length > 0) {
                    prependAlerts(data.new_alerts);
                    data.new_alerts.forEach(a => { if (+a.id > latestAlertId) latestAlertId = +a.id; });
                }
            } catch (e) {}
        }

        document.addEventListener('click', function once() {
            soundReady = true;
            document.removeEventListener('click', once);
        }, { once: true });

        if (AI_ENABLED) {
            setInterval(poll, POLL_INTERVAL_MS);
            console.log('✅ Admin AI Dashboard — polling every ' + (POLL_INTERVAL_MS/1000) + 's');
        } else {
            const li = document.getElementById('liveIndicator');
            if (li) {
                li.innerHTML = '<span class="dot" style="background:#dc3545;animation:none;"></span> AI disabled globally';
                li.style.color = '#dc3545';
            }
            console.log('⛔ AI Dashboard polling paused — ai_enabled = 0');
        }
    </script>
</body>
</html>
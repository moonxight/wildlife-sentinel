<?php
// ============================================================
// supervisor/alarm-auto-trigger.php
// SERVER-SIDE AUTO-TRIGGER RULE
// ------------------------------------------------------------
// Runs every 30 seconds via cron / Windows Task Scheduler:
//   * * * * *  php /path/to/supervisor/alarm-auto-trigger.php >> /var/log/ws-alarm.log 2>&1
//
// RULE:
//   For every incident still 'reported' after the alarm's
//   trigger_delay_seconds, fire every active auto-sound alarm
//   in that zone ONCE.
//   Also: auto-stop alarms that have exceeded their siren_duration.
//
// Honors global settings:
//   ai_enabled, ai_auto_trigger_alarm, notify_on_alarm
// ============================================================

require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// ============================================================
// GLOBAL SETTINGS
// ============================================================
if (!function_exists('ws_at_global')) {
    function ws_at_global(string $key, $default = null) {
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

$globalAiEnabled      = (string) ws_at_global('ai_enabled', '1')                === '1';
$globalAutoTrigger    = (string) ws_at_global('ai_auto_trigger_alarm', '0')     === '1';
$globalNotifyAlarm    = (string) ws_at_global('notify_on_alarm', '1')           === '1';

// Refuse to run the AI-based triggers when AI is disabled
// (auto-stop still runs regardless — it's housekeeping).
if ($globalAiEnabled && $globalAutoTrigger) {
    runAutoTriggers($pdo, $globalNotifyAlarm);
} else {
    echo "[skip] AI auto-trigger disabled (ai_enabled=" . ($globalAiEnabled ? '1' : '0')
       . ", ai_auto_trigger_alarm=" . ($globalAutoTrigger ? '1' : '0') . ")\n";
}

// Always run auto-stop
runAutoStop($pdo);

echo "Auto-trigger run complete at " . date('c') . "\n";

// ============================================================
// FUNCTIONS
// ============================================================
function runAutoTriggers(PDO $pdo, bool $notifyAlarm): void {
    // 1. Find unacknowledged incidents older than the smallest possible delay
    $incidents = [];
    try {
        $stmt = $pdo->query('
            SELECT i.id, i.zone_id, i.severity, i.category, i.reported_at
            FROM incidents i
            WHERE i.status = \'reported\'
              AND TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (i.reported_at))) / 1) >= 30
              AND i.zone_id IS NOT NULL
        ');
        $incidents = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[WS-ALARM-AUTO] incidents query failed: ' . $e->getMessage());
        return;
    }

    if (!$incidents) {
        echo "No unacknowledged incidents to check.\n";
        return;
    }

    $triggeredCount = 0;

    foreach ($incidents as $inc) {
        $zoneId = (int)$inc['zone_id'];
        if ($zoneId <= 0) continue;

        // Fetch alarms in this zone with auto_sound enabled
        try {
            $stmt = $pdo->prepare("
                SELECT id, alarm_name, trigger_delay_seconds
                FROM alarm_systems
                WHERE zone_id = ?
                  AND is_active = 1
                  AND auto_sound_on_incident = 1
            ");
            $stmt->execute([$zoneId]);
            $alarms = $stmt->fetchAll();
        } catch (PDOException $e) {
            continue;
        }

        if (!$alarms) continue;

        $elapsed = time() - strtotime($inc['reported_at']);

        foreach ($alarms as $a) {
            $delay = (int)($a['trigger_delay_seconds'] ?? 120) ?: 120;
            if ($elapsed < $delay) continue;

            // Already triggered for this incident?
            try {
                $stmt = $pdo->prepare("
                    SELECT id FROM alarm_triggers
                    WHERE alarm_id = ? AND incident_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$a['id'], $inc['id']]);
                if ($stmt->fetch()) continue;
            } catch (PDOException $e) {
                // Column may be missing — add it
                try { $pdo->exec('ALTER TABLE alarm_triggers ADD COLUMN incident_id INTEGER NULL'); } catch (PDOException $e2) {}
            }

            // Fire the alarm
            try {
                $pdo->prepare("
                    INSERT INTO alarm_triggers
                        (alarm_id, incident_id, zone_id, triggered_by, trigger_reason, triggered_at)
                    VALUES (?, ?, ?, 'auto_incident', ?, NOW())
                ")->execute([
                    $a['id'],
                    $inc['id'],
                    $zoneId,
                    "Incident #{$inc['id']} not acknowledged after {$delay}s",
                ]);

                $pdo->prepare("
                    UPDATE alarm_systems
                    SET last_triggered = NOW(), trigger_count = trigger_count + 1
                    WHERE id = ?
                ")->execute([$a['id']]);

                // Notify (respects setting)
                if ($notifyAlarm) {
                    try {
                        $stmt = $pdo->prepare("
                            SELECT id FROM users
                            WHERE zone_id = ? AND role IN ('zone_supervisor','ranger') AND is_active = 1
                        ");
                        $stmt->execute([$zoneId]);
                        $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        foreach ($recipients as $rid) {
                            if (function_exists('createNotification')) {
                                createNotification(
                                    (int)$rid,
                                    'alarm',
                                    '🚨 AUTO-ALARM: ' . $a['alarm_name'],
                                    "Incident #{$inc['id']} unacknowledged for {$delay}s. Alarm sounding.",
                                    $inc['id']
                                );
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('[WS-ALARM-AUTO] notify failed: ' . $e->getMessage());
                    }
                }

                // WebSocket broadcast
                if (function_exists('broadcastToWS')) {
                    try {
                        broadcastToWS('alarm-triggered', [
                            'zone_id'    => $zoneId,
                            'alarm_id'   => (int)$a['id'],
                            'alarm_name' => $a['alarm_name'],
                            'incident_id'=> (int)$inc['id'],
                            'auto'       => true,
                        ]);
                    } catch (Throwable $e) { /* silent */ }
                }

                // Best-effort hardware hook
                if (function_exists('ws_alarm_trigger_hardware')) {
                    try { ws_alarm_trigger_hardware((int)$a['id'], 0); }
                    catch (Throwable $e) { /* silent */ }
                }

                $triggeredCount++;
                echo "[ALARM] Zone {$zoneId} → '{$a['alarm_name']}' triggered for incident #{$inc['id']}\n";
            } catch (PDOException $e) {
                error_log('[WS-ALARM-AUTO] insert failed: ' . $e->getMessage());
            }
        }
    }

    echo "Triggered: {$triggeredCount} alarm(s).\n";
}

function runAutoStop(PDO $pdo): void {
    try {
        $stmt = $pdo->exec('
            UPDATE alarm_triggers at SET stopped_at = NOW(),
                duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (at.triggered_at))) / 1),
                was_acknowledged = 1 FROM alarm_systems a WHERE at.alarm_id=a.id AND at.stopped_at IS NULL
              AND a.siren_duration > 0
              AND TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (at.triggered_at))) / 1) > a.siren_duration
        ');
        echo "Auto-stopped expired alarms.\n";
    } catch (PDOException $e) {
        error_log('[WS-ALARM-AUTO] auto-stop failed: ' . $e->getMessage());
    }
}
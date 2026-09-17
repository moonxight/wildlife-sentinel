<?php
/**
 * Wildlife Sentinel — Alarm Control System (v3.1)
 * ------------------------------------------------------------
 * Manages zone alarms for unacknowledged incidents and AI alerts.
 *   - Timer scheduling (via alarm_triggers queue + cron)
 *   - Device trigger / stop with retries and timeouts
 *   - Duplicate suppression and rate limits
 *   - SMS + WebSocket notifications (respecting global settings)
 *   - Audit logging
 *
 * Honors global settings:
 *   ai_enabled, ai_auto_trigger_alarm, notify_on_alarm, sms_enabled
 *
 * CLI:
 *   * * * * * php /path/to/includes/alarm_control.php
 *
 *   Dry-run:      WS_ALARM_DRY_RUN=1 php alarm_control.php
 *   JSON output:  WS_ALARM_JSON=1 php alarm_control.php
 * ============================================================
 */

require_once __DIR__ . '/functions.php';

// ------------------------------------------------------------
// Logging helper (plain or JSON)
// ------------------------------------------------------------
if (!function_exists('alarm_log')) {
    function alarm_log(string $level, string $message, array $ctx = []): void {
        $json = getenv('WS_ALARM_JSON') === '1';
        $ts   = date('Y-m-d H:i:s');
        if ($json) {
            error_log(json_encode([
                'ts'      => $ts,
                'level'   => $level,
                'module'  => 'alarm_control',
                'message' => $message,
                'ctx'     => $ctx,
            ]));
        } else {
            $suffix = empty($ctx) ? '' : ' ' . json_encode($ctx);
            error_log("[{$ts}] [{$level}] [alarm_control] {$message}{$suffix}");
        }
    }
}

if (!class_exists('AlarmControl')) {

class AlarmControl
{
    /** @var PDO */
    private $pdo;

    /** @var array */
    private $zoneSettingsCache = [];

    /** @var bool */
    private $dryRun = false;

    // ------------------------------------------------------------
    // Global settings (cached once per process)
    // ------------------------------------------------------------
    private $globalSettings = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo    = $pdo ?: getDB();
        $this->dryRun = getenv('WS_ALARM_DRY_RUN') === '1';

        $this->loadGlobalSettings();

        if ($this->dryRun) {
            alarm_log('info', 'DRY RUN MODE — no device calls will be made');
        }
    }

    private function loadGlobalSettings(): void
    {
        $this->globalSettings = [
            'ai_enabled'            => true,
            'ai_auto_trigger_alarm' => false,
            'notify_on_alarm'       => true,
            'sms_enabled'           => true,
        ];

        if (!function_exists('getSetting')) return;

        $this->globalSettings['ai_enabled']            = (string)getSetting('ai_enabled', '1')            === '1';
        $this->globalSettings['ai_auto_trigger_alarm'] = (string)getSetting('ai_auto_trigger_alarm', '0') === '1';
        $this->globalSettings['notify_on_alarm']       = (string)getSetting('notify_on_alarm', '1')       === '1';
        $this->globalSettings['sms_enabled']           = (string)getSetting('sms_enabled', '1')           === '1';
    }

    private function globalEnabled(string $key, bool $default = true): bool
    {
        return (bool)($this->globalSettings[$key] ?? $default);
    }

    // ============================================================
    // SCHEMA SAFETY (idempotent, cached via INFORMATION_SCHEMA)
    // ============================================================
    private function ensureSchema(): void
    {
        // Only run the schema check the FIRST time this class is instantiated
        // in the current request. Cache a marker in a static variable.
        static $alreadyChecked = false;
        if ($alreadyChecked) return;
        $alreadyChecked = true;

        $wanted = [
            ['alarm_systems',   'alarm_code',                "VARCHAR(60) NULL"],
            ['alarm_systems',   'api_endpoint',              "VARCHAR(500) NULL"],
            ['alarm_systems',   'api_key',                   "VARCHAR(255) NULL"],
            ['alarm_systems',   'trigger_duration',          "INT DEFAULT 60"],
            ['alarm_systems',   'auto_trigger',              "SMALLINT DEFAULT 1"],
            ['alarm_systems',   'max_acknowledge_time_seconds', "INT DEFAULT 120"],
            ['alarm_systems',   'sound_url',                 "VARCHAR(500) NULL"],
            ['alarm_systems',   'sound_volume',              "INT DEFAULT 80"],
            ['alarm_systems',   'siren_duration',            "INT DEFAULT 180"],
            ['alarm_systems',   'trigger_delay_seconds',     "INT DEFAULT 120"],
            ['alarm_systems',   'auto_sound_on_incident',    "SMALLINT DEFAULT 1"],

            ['alarm_triggers',  'alert_id',                  "INT NULL"],
            ['alarm_triggers',  'incident_id',               "INT NULL"],
            ['alarm_triggers',  'triggered_by',              "VARCHAR(40) DEFAULT 'ai_detection'"],
            ['alarm_triggers',  'trigger_reason',            "VARCHAR(255) NULL"],
            ['alarm_triggers',  'triggered_at',              "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"],
            ['alarm_triggers',  'stopped_at',                "TIMESTAMP NULL"],
            ['alarm_triggers',  'duration_seconds',          "INT NULL"],
            ['alarm_triggers',  'was_acknowledged',          "SMALLINT DEFAULT 0"],
            ['alarm_triggers',  'acknowledged_by',           "INT NULL"],
            ['alarm_triggers',  'acknowledged_at',           "TIMESTAMP NULL"],
        ];

        try {
            $cursor = $this->pdo->query('SELECT current_schema() AS db');
            $db = $cursor->fetch()['db'] ?? null;
            if (!$db) return;

            foreach ($wanted as [$table, $column, $ddl]) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) AS c
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
                ");
                $stmt->execute([$db, $table, $column]);
                $exists = (int)($stmt->fetch()['c'] ?? 0);
                if (!$exists) {
                    try {
                        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}");
                        alarm_log('info', "Schema: added {$table}.{$column}");
                    } catch (PDOException $e) {
                        alarm_log('warning', "Schema add skipped", [
                            'table' => $table, 'column' => $column, 'err' => $e->getMessage()
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            alarm_log('warning', 'Schema check skipped', ['err' => $e->getMessage()]);
        }
    }

    // ============================================================
    // ZONE SETTINGS
    // ============================================================
    private function getZoneSettings(int $zoneId): array
    {
        if (isset($this->zoneSettingsCache[$zoneId])) {
            return $this->zoneSettingsCache[$zoneId];
        }

        $defaults = [
            'alarm_enabled'              => 1,
            'alarm_delay_seconds'        => 120,
            'sms_enabled'                => 1,
            'ai_detection_enabled'       => 1,
            'auto_stop_on_resolve'       => 1,
            'alarm_retrigger_cooldown_seconds' => 300,
        ];

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM zone_notification_settings WHERE zone_id = ? LIMIT 1");
            $stmt->execute([$zoneId]);
            $row = $stmt->fetch();
            if ($row) {
                $merged = array_merge($defaults, array_filter($row, fn($v) => $v !== null));
                $this->zoneSettingsCache[$zoneId] = $merged;
                return $merged;
            }
        } catch (PDOException $e) {
            alarm_log('warning', 'zone settings lookup failed', ['zone_id' => $zoneId, 'err' => $e->getMessage()]);
        }

        $this->zoneSettingsCache[$zoneId] = $defaults;
        return $defaults;
    }

    // ============================================================
    // START ALARM TIMER
    // ============================================================
    public function startAlarmTimer(int $incidentId, int $zoneId): bool
    {
        $settings = $this->getZoneSettings($zoneId);

        if (empty($settings['alarm_enabled'])) {
            alarm_log('info', 'Alarm timer skipped (alarm disabled for zone)', ['zone_id' => $zoneId]);
            return false;
        }

        $delay = (int)($settings['alarm_delay_seconds'] ?? 120);

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO alarm_triggers (
                    zone_id, incident_id, triggered_by, trigger_reason,
                    triggered_at, was_acknowledged
                ) VALUES (?, ?, \'unacknowledged_incident\', ?, (NOW() + (?) * INTERVAL \'1 second\'), 0)
            ');
            $stmt->execute([
                $zoneId,
                $incidentId,
                "Incident #{$incidentId} pending acknowledgment (delay {$delay}s)",
                $delay,
            ]);
            alarm_log('info', 'Alarm timer scheduled', [
                'incident_id' => $incidentId, 'zone_id' => $zoneId, 'delay_s' => $delay,
            ]);
            return true;
        } catch (PDOException $e) {
            alarm_log('error', 'Failed to schedule alarm timer', [
                'incident_id' => $incidentId, 'err' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ============================================================
    // CHECK PENDING ALARMS (called by cron)
    // ============================================================
    public function checkPendingAlarms(): int
    {
        // Refuse to run if AI auto-trigger is globally disabled.
        // (Auto-stop still runs elsewhere — this function is only for triggers.)
        if (!$this->globalEnabled('ai_enabled', true)
            || !$this->globalEnabled('ai_auto_trigger_alarm', false)) {
            alarm_log('info', 'checkPendingAlarms skipped', [
                'ai_enabled'            => $this->globalEnabled('ai_enabled'),
                'ai_auto_trigger_alarm' => $this->globalEnabled('ai_auto_trigger_alarm'),
            ]);
            return 0;
        }

        $triggered = 0;

        try {
            $stmt = $this->pdo->prepare('
                SELECT i.id AS incident_id, i.zone_id
                FROM incidents i
                LEFT JOIN zone_notification_settings zns ON i.zone_id = zns.zone_id
                WHERE i.status = \'reported\'
                  AND i.reported_at < (NOW() - (COALESCE(zns.alarm_delay_seconds, 120)) * INTERVAL \'1 second\')
                  AND COALESCE(zns.alarm_enabled, 1) = 1
                  AND NOT EXISTS (
                      SELECT 1 FROM alarm_triggers at
                      WHERE at.incident_id = i.id
                        AND at.triggered_by = \'unacknowledged_incident\'
                        AND at.triggered_at <= NOW()
                  )
                LIMIT 100
            ');
            $stmt->execute();
            $pending = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            alarm_log('error', 'checkPendingAlarms query failed', ['err' => $e->getMessage()]);
            return 0;
        }

        foreach ($pending as $incident) {
            $result = $this->triggerAlarmForIncident(
                (int)$incident['incident_id'],
                (int)$incident['zone_id']
            );
            if ($result['triggered'] > 0) $triggered++;
        }

        return $triggered;
    }

    // ============================================================
    // TRIGGER ALARM FOR INCIDENT
    // ============================================================
    public function triggerAlarmForIncident(int $incidentId, int $zoneId): array
    {
        $result = ['triggered' => 0, 'failed' => 0, 'skipped' => 0, 'alarms' => []];

        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM alarm_systems WHERE zone_id = ? AND is_active = 1
            ");
            $stmt->execute([$zoneId]);
            $alarms = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            alarm_log('error', 'alarm_systems lookup failed', ['zone_id' => $zoneId, 'err' => $e->getMessage()]);
            return $result;
        }

        if (empty($alarms)) {
            alarm_log('info', 'No active alarms for zone', ['zone_id' => $zoneId]);
            return $result;
        }

        $settings = $this->getZoneSettings($zoneId);
        $cooldown = (int)($settings['alarm_retrigger_cooldown_seconds'] ?? 300);

        foreach ($alarms as $alarm) {
            if ($this->recentTriggerExists($incidentId, (int)$alarm['id'], $cooldown)) {
                $result['skipped']++;
                $result['alarms'][] = ['alarm' => $alarm['alarm_name'] ?? '', 'status' => 'skipped_cooldown'];
                continue;
            }

            $ok = $this->triggerAlarmDevice($alarm);

            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO alarm_triggers (
                        alarm_id, incident_id, zone_id, triggered_by, trigger_reason, triggered_at
                    ) VALUES (?, ?, ?, 'unacknowledged_incident', ?, NOW())
                ");
                $stmt->execute([
                    $alarm['id'],
                    $incidentId,
                    $zoneId,
                    "Incident #{$incidentId} not acknowledged within " .
                        ((int)($alarm['max_acknowledge_time_seconds'] ?? 120)) . " seconds",
                ]);

                $this->pdo->prepare("
                    UPDATE alarm_systems
                    SET last_triggered = NOW(),
                        trigger_count = COALESCE(trigger_count, 0) + 1
                    WHERE id = ?
                ")->execute([$alarm['id']]);
            } catch (PDOException $e) {
                alarm_log('warning', 'trigger log write failed', ['alarm_id' => $alarm['id'], 'err' => $e->getMessage()]);
            }

            if ($ok) {
                $result['triggered']++;
                $result['alarms'][] = ['alarm' => $alarm['alarm_name'] ?? '', 'status' => 'triggered'];

                // Only notify if the global alarm-notification gate is on
                if ($this->globalEnabled('notify_on_alarm', true)) {
                    if (!empty($settings['sms_enabled']) && $this->globalEnabled('sms_enabled', true)) {
                        $this->sendAlarmNotification($incidentId, $zoneId, $alarm);
                    }
                    $this->broadcastAlarmEvent('alarm-triggered', $incidentId, $zoneId, $alarm);
                }
            } else {
                $result['failed']++;
                $result['alarms'][] = ['alarm' => $alarm['alarm_name'] ?? '', 'status' => 'failed'];
            }
        }

        try {
            logAudit(0, 'alarm_trigger_batch', [
                'incident_id' => $incidentId,
                'zone_id'     => $zoneId,
                'triggered'   => $result['triggered'],
                'failed'      => $result['failed'],
                'skipped'     => $result['skipped'],
            ]);
        } catch (Throwable $e) { /* non-fatal */ }

        return $result;
    }

    // ============================================================
    // DUPLICATE SUPPRESSION
    // ============================================================
    private function recentTriggerExists(int $incidentId, int $alarmId, int $cooldown): bool
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) AS c
                FROM alarm_triggers
                WHERE incident_id = ? AND alarm_id = ?
                  AND triggered_at >= (NOW() - (?) * INTERVAL \'1 second\')
            ');
            $stmt->execute([$incidentId, $alarmId, $cooldown]);
            return ((int)($stmt->fetch()['c'] ?? 0)) > 0;
        } catch (PDOException $e) {
            return false; // fail open
        }
    }

    // ============================================================
    // DEVICE TRIGGER / STOP
    // ============================================================
    private function triggerAlarmDevice(array $alarm): bool
    {
        if ($this->dryRun) {
            alarm_log('info', 'DRY RUN trigger', ['alarm' => $alarm['alarm_name'] ?? '']);
            return true;
        }
        if (empty($alarm['api_endpoint'])) {
            alarm_log('info', 'Trigger simulated (no API endpoint)', ['alarm' => $alarm['alarm_name'] ?? '']);
            return true;
        }
        $payload = json_encode([
            'action'     => 'trigger',
            'duration'   => (int)($alarm['trigger_duration'] ?? 60),
            'alarm_code' => $alarm['alarm_code'] ?? null,
            'timestamp'  => time(),
        ]);
        return $this->postToDevice($alarm['api_endpoint'], $payload, $alarm['api_key'] ?? '', 'trigger');
    }

    private function stopAlarmDevice(array $alarm): bool
    {
        if ($this->dryRun) {
            alarm_log('info', 'DRY RUN stop', ['alarm' => $alarm['alarm_name'] ?? '']);
            return true;
        }
        if (empty($alarm['api_endpoint'])) return true;

        $payload = json_encode([
            'action'     => 'stop',
            'alarm_code' => $alarm['alarm_code'] ?? null,
            'timestamp'  => time(),
        ]);
        return $this->postToDevice($alarm['api_endpoint'], $payload, $alarm['api_key'] ?? '', 'stop');
    }

    private function postToDevice(string $url, string $jsonPayload, string $apiKey, string $action): bool
    {
        $attempts    = 0;
        $maxAttempts = 2;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $headers = ['Content-Type: application/json', 'Accept: application/json'];
            if ($apiKey !== '') $headers[] = 'Authorization: Bearer ' . $apiKey;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                alarm_log('warning', "device {$action} curl error (attempt {$attempts})", ['url' => $url, 'err' => $curlErr]);
            } elseif ($httpCode >= 200 && $httpCode < 300) {
                alarm_log('info', "device {$action} OK", ['url' => $url, 'code' => $httpCode]);
                return true;
            } elseif ($httpCode >= 500 && $attempts < $maxAttempts) {
                alarm_log('warning', "device {$action} server error (attempt {$attempts})", ['url' => $url, 'code' => $httpCode]);
                usleep(300000);
            } else {
                alarm_log('warning', "device {$action} failed", ['url' => $url, 'code' => $httpCode]);
                return false;
            }
        }
        return false;
    }

    // ============================================================
    // STOP ALARM
    // ============================================================
    public function stopAlarm(int $incidentId, int $acknowledgedBy): int
    {
        $stopped = 0;

        try {
            $stmt = $this->pdo->prepare("
                SELECT at.id AS trigger_id, at.alarm_id
                FROM alarm_triggers at
                WHERE at.incident_id = ? AND at.stopped_at IS NULL
            ");
            $stmt->execute([$incidentId]);
            $triggers = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            alarm_log('error', 'stopAlarm lookup failed', ['incident_id' => $incidentId, 'err' => $e->getMessage()]);
            return 0;
        }

        foreach ($triggers as $t) {
            $alarm = null;
            try {
                $s = $this->pdo->prepare("SELECT * FROM alarm_systems WHERE id = ? LIMIT 1");
                $s->execute([$t['alarm_id']]);
                $alarm = $s->fetch();
            } catch (PDOException $e) { /* ignore */ }

            if ($alarm) {
                $this->stopAlarmDevice($alarm);
                $this->broadcastAlarmEvent('alarm-stopped', $incidentId, (int)($alarm['zone_id'] ?? 0), $alarm);
            }

            try {
                $this->pdo->prepare('
                    UPDATE alarm_triggers
                    SET stopped_at = NOW(),
                        duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1),
                        was_acknowledged = 1,
                        acknowledged_by = ?,
                        acknowledged_at = NOW()
                    WHERE id = ?
                ')->execute([$acknowledgedBy, $t['trigger_id']]);
                $stopped++;
            } catch (PDOException $e) {
                alarm_log('warning', 'stopAlarm update failed', ['trigger_id' => $t['trigger_id'], 'err' => $e->getMessage()]);
            }
        }

        if ($stopped > 0) {
            try {
                logAudit($acknowledgedBy, 'alarm_stop_batch', [
                    'incident_id' => $incidentId, 'stopped' => $stopped,
                ]);
            } catch (Throwable $e) { /* non-fatal */ }
        }

        return $stopped;
    }

    // ============================================================
    // NOTIFICATIONS
    // ============================================================
    private function sendAlarmNotification(int $incidentId, int $zoneId, array $alarm): void
    {
        $message = "🔔 ALARM TRIGGERED\n"
                 . "Incident #{$incidentId} not acknowledged!\n"
                 . "Alarm: " . ($alarm['alarm_name'] ?? 'Zone alarm') . "\n"
                 . "Immediate response required!";

        try {
            $pdo = $this->pdo;
            $stmt = $pdo->prepare("
                SELECT id, phone FROM users
                WHERE zone_id = ?
                  AND role IN ('ranger','zone_supervisor')
                  AND is_active = 1
                  AND phone IS NOT NULL AND phone <> ''
            ");
            $stmt->execute([$zoneId]);
            $users = $stmt->fetchAll() ?: [];

            foreach ($users as $user) {
                $phone = normalizeZambianPhone($user['phone']);
                if (!$phone) continue;

                $res = sendSMS($phone, $message, 'incident', (int)$user['id'], $incidentId);
                ws_log_sms((int)$user['id'], $phone, $message, 'incident', $res, $incidentId);
            }
        } catch (Throwable $e) {
            alarm_log('warning', 'SMS notification failed', ['err' => $e->getMessage()]);
        }
    }

    private function broadcastAlarmEvent(string $event, int $incidentId, int $zoneId, array $alarm): void
    {
        try {
            if (function_exists('broadcastToWS')) {
                broadcastToWS($event, [
                    'zone_id'     => $zoneId,
                    'incident_id' => $incidentId,
                    'alarm_id'    => (int)($alarm['id'] ?? 0),
                    'alarm_name'  => $alarm['alarm_name'] ?? null,
                    'ts'          => time(),
                ]);
            }
        } catch (Throwable $e) { /* non-fatal */ }
    }

    // ============================================================
    // TEST ALARM
    // ============================================================
    public function testAlarm(int $zoneId, int $userId): array
    {
        $results = [];

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM alarm_systems WHERE zone_id = ? AND is_active = 1");
            $stmt->execute([$zoneId]);
            $alarms = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            alarm_log('error', 'testAlarm lookup failed', ['zone_id' => $zoneId, 'err' => $e->getMessage()]);
            return $results;
        }

        foreach ($alarms as $alarm) {
            $success = $this->triggerAlarmDevice($alarm);
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO alarm_triggers (alarm_id, zone_id, triggered_by, trigger_reason, triggered_at)
                    VALUES (?, ?, 'scheduled_test', ?, NOW())
                ");
                $stmt->execute([$alarm['id'], $zoneId, "Manual test by user #{$userId}"]);
            } catch (PDOException $e) {
                alarm_log('warning', 'testAlarm log failed', ['err' => $e->getMessage()]);
            }
            if ($success) $this->broadcastAlarmEvent('alarm-triggered', 0, $zoneId, $alarm);
            $results[] = ['alarm' => $alarm['alarm_name'] ?? '(unnamed)', 'success' => $success];
        }

        try { logAudit($userId, 'alarm_test', ['zone_id' => $zoneId, 'count' => count($results)]); }
        catch (Throwable $e) { /* non-fatal */ }

        return $results;
    }

    // ============================================================
    // RESOLVE-TIME STOP
    // ============================================================
    public function handleIncidentResolved(int $incidentId, int $zoneId, int $resolvedBy): int
    {
        $settings = $this->getZoneSettings($zoneId);
        if (empty($settings['auto_stop_on_resolve'])) return 0;
        return $this->stopAlarm($incidentId, $resolvedBy);
    }
}

} // end class_exists

// ============================================================
// CLI / CRON ENTRY POINT
// ============================================================
if (php_sapi_name() === 'cli' && basename($_SERVER['PHP_SELF']) === 'alarm_control.php') {

    $started = microtime(true);

    $lockFile   = sys_get_temp_dir() . '/ws_alarm_control.lock';
    $lockHandle = fopen($lockFile, 'c');
    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another alarm_control run is in progress — exiting.\n");
        exit(0);
    }

    try {
        $alarmControl = new AlarmControl();
        $triggered = $alarmControl->checkPendingAlarms();

        $elapsed = round((microtime(true) - $started) * 1000);

        if (getenv('WS_ALARM_JSON') === '1') {
            echo json_encode([
                'success'    => true,
                'triggered'  => $triggered,
                'elapsed_ms' => $elapsed,
                'ts'         => date('c'),
            ]) . PHP_EOL;
        } else {
            echo "Alarm check completed: {$triggered} incident(s) triggered ({$elapsed}ms)\n";
        }
    } catch (Throwable $e) {
        alarm_log('error', 'cron run crashed', ['err' => $e->getMessage()]);
        fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
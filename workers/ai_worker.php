<?php
// ============================================================
// workers/ai_worker.php
// Processes AI queue jobs: alarms, recalibrations, batch
// inference, feedback-driven threshold adjustments.
// ------------------------------------------------------------
// CLI:
//   * * * * * php /path/to/workers/ai_worker.php
//
// Single run:   php ai_worker.php --once
// Dry run:      WS_AI_DRY_RUN=1 php ai_worker.php --once
// ============================================================

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../ai-service/ai_engine.php';

$once   = in_array('--once', $argv ?? [], true);
$dryRun = getenv('WS_AI_DRY_RUN') === '1';

$pdo = getDB();

// ------------------------------------------------------------
// Recalibration cycle: adjust thresholds based on feedback
// ------------------------------------------------------------
if (!$dryRun) {
    try {
        $pdo->exec('
            UPDATE ai_thresholds t
            SET min_confidence = LEAST(0.95, GREATEST(0.50, t.min_confidence + (
                SELECT CASE
                    WHEN SUM((f.verdict = \'false_positive\')::integer) > SUM((f.verdict = \'true_positive\')::integer) THEN 0.05
                    WHEN SUM((f.verdict = \'true_positive\')::integer) > SUM((f.verdict = \'false_positive\')::integer) THEN -0.03
                    ELSE 0
                END
                FROM ai_feedback f
                WHERE f.created_at >= (NOW() - (7) * INTERVAL \'1 day\')
            )))
            WHERE t.zone_id IS NOT NULL
        ');
    } catch (PDOException $e) {
        error_log('[WS-AI-WORKER] recalibration: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// Process queued jobs
// ------------------------------------------------------------
function processJobs(PDO $pdo, bool $dryRun): int
{
    $processed = 0;

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM ai_queue
            WHERE status = 'pending'
              AND scheduled_for <= NOW()
            ORDER BY priority ASC, scheduled_for ASC
            LIMIT 20
        ");
        $stmt->execute();
        $jobs = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        return 0;
    }

    foreach ($jobs as $job) {
        // Claim
        $pdo->prepare("
            UPDATE ai_queue
            SET status='processing', started_at=NOW(), attempts=attempts+1
            WHERE id = ? AND status = 'pending'
        ")->execute([$job['id']]);

        $payload = is_string($job['payload']) ? json_decode($job['payload'], true) : $job['payload'];
        $ok = false;
        $err = null;

        try {
            if ($job['job_type'] === 'alert' && ($payload['action'] ?? '') === 'trigger_alarm') {
                $ok = handleTriggerAlarm($pdo, $payload, $dryRun);
            } elseif ($job['job_type'] === 'recalibrate') {
                $ok = handleRecalibrate($pdo, $payload);
            } else {
                $ok = true; // no-op for unknown jobs
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }

        $pdo->prepare("
            UPDATE ai_queue
            SET status = ?, finished_at = NOW(), error = ?
            WHERE id = ?
        ")->execute([$ok ? 'done' : 'failed', $err, $job['id']]);

        if ($ok) $processed++;
    }

    return $processed;
}

function handleTriggerAlarm(PDO $pdo, array $payload, bool $dryRun): bool
{
    if ($dryRun) return true;
    $zoneId = (int)($payload['zone_id'] ?? 0);
    if ($zoneId <= 0) return false;

    // Fire hardware alarm if the hook is available
    if (function_exists('ws_alarm_trigger_hardware')) {
        try { ws_alarm_trigger_hardware(0, (int)($payload['alert_id'] ?? 0)); } catch (Throwable $e) {}
    }

    // Fire zone alarms
    if (function_exists('triggerZoneAlarms')) {
        triggerZoneAlarms($zoneId, (int)($payload['alert_id'] ?? 0), 'AI auto-trigger');
    }

    return true;
}

function handleRecalibrate(PDO $pdo, array $payload): bool
{
    $zoneId = (int)($payload['zone_id'] ?? 0);
    if ($zoneId <= 0) return false;
    // Placeholder for on-demand recalibration of a specific zone
    return true;
}

// ------------------------------------------------------------
// Run
// ------------------------------------------------------------
$processed = processJobs($pdo, $dryRun);

if ($once) {
    echo "Processed {$processed} AI jobs.\n";
    exit(0);
}

// Long-running mode — loop every 30 seconds
while (true) {
    $processed = processJobs($pdo, $dryRun);
    sleep(30);
}
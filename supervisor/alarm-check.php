<?php
// ============================================================
// supervisor/alarm-check.php
// Returns active alarms for the supervisor's zone (JSON)
// Polled every 15s by alarm-systems.php so browser can autoplay
// ============================================================

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!hasRole('zone_supervisor')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$user         = getCurrentUser();
$pdo          = getDB();
$activeZoneId = (int)$user['zone_id'];

$triggers = [];
try {
    $stmt = $pdo->prepare("
        SELECT at.id, at.alarm_id, at.triggered_at,
               a.alarm_name, a.sound_url, a.sound_volume, a.siren_duration
        FROM alarm_triggers at
        JOIN alarm_systems a ON at.alarm_id = a.id
        WHERE at.zone_id = ? AND at.stopped_at IS NULL
          AND a.is_active = 1
        ORDER BY at.triggered_at DESC
        LIMIT 10
    ");
    $stmt->execute([$activeZoneId]);
    $triggers = $stmt->fetchAll();
} catch (PDOException $e) {
    // ignore — table might not exist yet
}

// Auto-stop triggers whose siren_duration has passed
foreach ($triggers as &$t) {
    $elapsed = time() - strtotime($t['triggered_at']);
    if ($t['siren_duration'] && $elapsed > (int)$t['siren_duration']) {
        try {
            $pdo->prepare('
                UPDATE alarm_triggers SET
                    stopped_at = NOW(),
                    duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1)
                WHERE id = ?
            ')->execute([$t['id']]);
            $t['stopped'] = true;
        } catch (PDOException $e) {}
    }
}
unset($t);

// Only return ones that are still active
$triggers = array_values(array_filter($triggers, fn($t) => empty($t['stopped'])));

header('Content-Type: application/json');
echo json_encode(['triggers' => $triggers]);
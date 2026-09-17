<?php
require_once __DIR__ . '/../../config/database.php';
$user = getCurrentUser();

if (!in_array($user['role'], ['zone_supervisor', 'admin'])) {
    jsonResponse(['error' => 'Access denied'], 403);
}

$zoneId = $_GET['zone_id'] ?? $user['zone_id'];
if (!$zoneId) jsonResponse(['error' => 'Zone ID required'], 400);

$stmt = $pdo->prepare('
    SELECT a.*, u.full_name AS subject_name, u.role AS subject_role
    FROM ai_anomalies a
    LEFT JOIN users u ON (a.ranger_id = u.id OR a.scout_id = u.id)
    WHERE a.zone_id = ? AND a.detected_at >= (NOW() - (24) * INTERVAL \'1 hour\')
    ORDER BY a.detected_at DESC
    LIMIT 50
');
$stmt->execute([$zoneId]);
$anomalies = $stmt->fetchAll();

foreach ($anomalies as &$a) {
    $a['location'] = ['lat' => (float)$a['location_lat'], 'lng' => (float)$a['location_lng']];
}

jsonResponse(['success' => true, 'anomalies' => $anomalies]);
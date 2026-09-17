<?php
// ============================================================
// ranger/update_location.php
// Receives GPS updates from the ranger map (every ~15s)
// ============================================================

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user = getCurrentUser();
if (!$user || $user['role'] !== 'ranger') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$lat     = isset($input['lat'])     ? (float)$input['lat']     : null;
$lng     = isset($input['lng'])     ? (float)$input['lng']     : null;
$heading = isset($input['heading']) ? (float)$input['heading'] : 0;
$speed   = isset($input['speed'])   ? (float)$input['speed']   : 0;

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing coordinates']);
    exit;
}

if (function_exists('updateRangerLocation')) {
    updateRangerLocation($user['id'], $lat, $lng, $heading, $speed, null);
} else {
    // Fallback: direct insert
    try {
        $pdo = getDB();

        $pdo->prepare('
            INSERT INTO ranger_live_tracking
                (ranger_id, current_lat, current_lng, heading, speed, last_update, is_offline)
            VALUES (?, ?, ?, ?, ?, NOW(), 0)
             ON CONFLICT (ranger_id) DO UPDATE SET 
                current_lat = EXCLUDED.current_lat,
                current_lng = EXCLUDED.current_lng,
                heading     = EXCLUDED.heading,
                speed       = EXCLUDED.speed,
                last_update = NOW(),
                is_offline  = 0
        ')->execute([$user['id'], $lat, $lng, $heading, $speed]);

        $pdo->prepare("
            INSERT INTO ranger_location_history
                (ranger_id, lat, lng, heading, speed, timestamp)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$user['id'], $lat, $lng, $heading, $speed]);

        // Broadcast to supervisor/admin dashboards
        if (function_exists('broadcastToWS')) {
            broadcastToWS('ranger-location', [
                'zone_id'   => $user['zone_id'],
                'ranger_id' => $user['id'],
                'location'  => [
                    'lat'     => $lat,
                    'lng'     => $lng,
                    'heading' => $heading,
                    'speed'   => $speed,
                ],
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => true, 'lat' => $lat, 'lng' => $lng]);
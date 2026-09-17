<?php
header('Content-Type: application/json');
require_once '../includes/functions.php';
requireLogin();

$pdo = getDB();
$user = getCurrentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'update':
            // Only rangers can update their location
            if ($user['role'] !== 'ranger') {
                throw new Exception('Unauthorized');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['lat']) || !isset($data['lng'])) {
                throw new Exception('Location data required');
            }
            
            $lat = $data['lat'];
            $lng = $data['lng'];
            $heading = $data['heading'] ?? null;
            $speed = $data['speed'] ?? null;
            
            $stmt = $pdo->prepare('
                INSERT INTO ranger_live_tracking (ranger_id, current_lat, current_lng, heading, speed, last_update, is_offline)
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
                 ON CONFLICT (ranger_id) DO UPDATE SET  
                    current_lat = ?, 
                    current_lng = ?, 
                    heading = ?, 
                    speed = ?, 
                    last_update = NOW(),
                    is_offline = ?
            ');
            
            $isOffline = isset($data['is_offline']) ? 1 : 0;
            $stmt->execute([
                $user['id'], $lat, $lng, $heading, $speed, $isOffline,
                $lat, $lng, $heading, $speed, $isOffline
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Location updated successfully'
            ]);
            break;
            
        case 'get':
            $rangerId = $_GET['ranger_id'] ?? null;
            
            if (!$rangerId) {
                // Get all rangers in user's zone
                $sql = "
                    SELECT u.id, u.full_name, rlt.current_lat, rlt.current_lng, 
                           rlt.heading, rlt.speed, rlt.last_update, rlt.is_offline,
                           ra.is_available
                    FROM users u
                    LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
                    LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
                    WHERE u.role = 'ranger' AND u.is_active = 1
                ";
                
                if ($user['role'] !== 'admin') {
                    $sql .= " AND u.zone_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$user['zone_id']]);
                } else {
                    $stmt = $pdo->query($sql);
                }
                
                $rangers = $stmt->fetchAll();
                
                echo json_encode([
                    'success' => true,
                    'rangers' => $rangers,
                    'count' => count($rangers)
                ]);
            } else {
                // Get specific ranger
                $stmt = $pdo->prepare("
                    SELECT u.id, u.full_name, rlt.current_lat, rlt.current_lng, 
                           rlt.heading, rlt.speed, rlt.last_update, rlt.is_offline,
                           ra.is_available
                    FROM users u
                    LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
                    LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id
                    WHERE u.id = ? AND u.role = 'ranger'
                ");
                $stmt->execute([$rangerId]);
                $ranger = $stmt->fetch();
                
                if (!$ranger) {
                    throw new Exception('Ranger not found');
                }
                
                echo json_encode([
                    'success' => true,
                    'ranger' => $ranger
                ]);
            }
            break;
            
        case 'nearby':
            // Get nearby rangers for manpower requests
            if ($user['role'] !== 'ranger') {
                throw new Exception('Unauthorized');
            }
            
            $lat = $_GET['lat'] ?? null;
            $lng = $_GET['lng'] ?? null;
            $radius = $_GET['radius'] ?? 10;
            
            if (!$lat || !$lng) {
                throw new Exception('Location required');
            }
            
            $stmt = $pdo->prepare('
                SELECT * FROM (SELECT u.id, u.full_name, rlt.current_lat, rlt.current_lng,
                       (6371 * acos(cos(radians(?)) * cos(radians(rlt.current_lat)) * 
                       cos(radians(rlt.current_lng) - radians(?)) + sin(radians(?)) * 
                       sin(radians(rlt.current_lat)))) AS distance
                FROM users u
                JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
                JOIN ranger_availability ra ON u.id = ra.ranger_id
                WHERE u.role = \'ranger\' 
                  AND u.is_active = 1 
                  AND u.id != ?
                  AND ra.is_available = 1
                  AND rlt.current_lat IS NOT NULL) AS nearby WHERE distance < ? ORDER BY distance ASC
            ');
            $stmt->execute([$lat, $lng, $lat, $user['id'], $radius]);
            $rangers = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'rangers' => $rangers,
                'count' => count($rangers)
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
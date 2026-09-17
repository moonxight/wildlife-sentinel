<?php
header('Content-Type: application/json');
require_once '../includes/functions.php';
requireLogin();

$pdo = getDB();
$user = getCurrentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $sql = "SELECT * FROM zones WHERE is_active = 1 ORDER BY name";
            $stmt = $pdo->query($sql);
            $zones = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'zones' => $zones,
                'count' => count($zones)
            ]);
            break;
            
        case 'create':
            if ($user['role'] !== 'admin') {
                throw new Exception('Unauthorized');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['name']) || empty($data['center_lat']) || empty($data['center_lng'])) {
                throw new Exception('Missing required fields');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO zones (name, description, center_lat, center_lng, boundary_geojson, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['center_lat'],
                $data['center_lng'],
                $data['boundary_geojson'] ?? null,
                $user['id']
            ]);
            
            $zoneId = $pdo->query('SELECT lastval()')->fetchColumn();
            logAudit($user['id'], 'create_zone', ['zone_name' => $data['name']]);
            
            echo json_encode([
                'success' => true,
                'zone_id' => $zoneId,
                'message' => 'Zone created successfully'
            ]);
            break;
            
        case 'update':
            if ($user['role'] !== 'admin') {
                throw new Exception('Unauthorized');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['zone_id']) || empty($data['name'])) {
                throw new Exception('Missing required fields');
            }
            
            $stmt = $pdo->prepare("
                UPDATE zones 
                SET name = ?, description = ?, center_lat = ?, center_lng = ?, 
                    boundary_geojson = ?, is_active = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['center_lat'],
                $data['center_lng'],
                $data['boundary_geojson'] ?? null,
                isset($data['is_active']) ? 1 : 0,
                $data['zone_id']
            ]);
            
            logAudit($user['id'], 'update_zone', ['zone_id' => $data['zone_id']]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Zone updated successfully'
            ]);
            break;
            
        case 'delete':
            if ($user['role'] !== 'admin') {
                throw new Exception('Unauthorized');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $zoneId = $data['zone_id'] ?? null;
            
            if (!$zoneId) {
                throw new Exception('Zone ID required');
            }
            
            $stmt = $pdo->prepare("DELETE FROM zones WHERE id = ?");
            $stmt->execute([$zoneId]);
            
            logAudit($user['id'], 'delete_zone', ['zone_id' => $zoneId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Zone deleted successfully'
            ]);
            break;
            
        case 'get':
            $zoneId = $_GET['id'] ?? null;
            
            if (!$zoneId) {
                throw new Exception('Zone ID required');
            }
            
            $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
            $stmt->execute([$zoneId]);
            $zone = $stmt->fetch();
            
            if (!$zone) {
                throw new Exception('Zone not found');
            }
            
            echo json_encode([
                'success' => true,
                'zone' => $zone
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
<?php
header('Content-Type: application/json');
require_once '../includes/functions.php';
requireLogin();

$pdo = getDB();
$user = getCurrentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================
// HELPER: Check if a phone number already exists
// Returns the matching row (id, full_name) or false
// ============================================================
function phoneExists(PDO $pdo, $phone, $excludeUserId = 0) {
    if (empty($phone)) return false;
    $sql = "SELECT id, full_name FROM users WHERE phone = ?";
    $params = [$phone];
    if ($excludeUserId > 0) {
        $sql .= " AND id != ?";
        $params[] = $excludeUserId;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: false;
}

try {
    switch ($action) {
        case 'list':
            $sql = "SELECT u.*, z.name as zone_name FROM users u LEFT JOIN zones z ON u.zone_id = z.id WHERE 1=1";
            $params = [];
            
            if ($user['role'] === 'zone_supervisor') {
                $sql .= " AND u.zone_id = ? AND u.role != 'admin'";
                $params[] = $user['zone_id'];
            }
            
            $sql .= " ORDER BY u.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            // Remove sensitive data
            foreach ($users as &$u) {
                unset($u['password_hash']);
            }
            
            echo json_encode([
                'success' => true,
                'users' => $users,
                'count' => count($users)
            ]);
            break;
            
        case 'create':
            if (!in_array($user['role'], ['admin', 'zone_supervisor'])) {
                throw new Exception('Unauthorized');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['email']) || empty($data['full_name']) || empty($data['password']) || empty($data['role'])) {
                throw new Exception('Missing required fields');
            }
            
            if (!validateEmail($data['email'])) {
                throw new Exception('Invalid email address');
            }
            
            if (strlen($data['password']) < 6) {
                throw new Exception('Password must be at least 6 characters');
            }
            
            // Check if email exists
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $check->execute([$data['email']]);
            if ($check->fetch()) {
                throw new Exception('Email already exists');
            }
            
            $hash = hashPassword($data['password']);
            $zoneId = $data['zone_id'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT INTO users (email, phone, password_hash, full_name, role, zone_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['email'],
                $data['phone'] ?? null,
                $hash,
                $data['full_name'],
                $data['role'],
                $zoneId,
                $user['id']
            ]);
            
            $userId = $pdo->query('SELECT lastval()')->fetchColumn();
            logAudit($user['id'], 'create_user', ['email' => $data['email'], 'role' => $data['role']]);
            
            echo json_encode([
                'success' => true,
                'user_id' => $userId,
                'message' => 'User created successfully'
            ]);
            break;
            
        case 'update':
            if (!in_array($user['role'], ['admin', 'zone_supervisor'])) {
                throw new Exception('Unauthorized');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['user_id'])) {
                throw new Exception('User ID required');
            }
            
            $userId = $data['user_id'];
            $fullName = sanitize($data['full_name'] ?? '');
            $phone = sanitize($data['phone'] ?? '');
            $role = $data['role'] ?? null;
            $zoneId = $data['zone_id'] ?? null;
            $isActive = isset($data['is_active']) ? 1 : 0;
            
            if (empty($fullName)) {
                throw new Exception('Full name required');
            }
            
            $sql = "UPDATE users SET full_name = ?, phone = ?, is_active = ?";
            $params = [$fullName, $phone, $isActive];
            
            if ($role && ($user['role'] === 'admin' || $role !== 'admin')) {
                $sql .= ", role = ?";
                $params[] = $role;
            }
            
            if ($zoneId !== null) {
                $sql .= ", zone_id = ?";
                $params[] = $zoneId;
            }
            
            $sql .= " WHERE id = ? AND (role != 'admin' OR ? = 'admin')";
            $params[] = $userId;
            $params[] = $user['role'];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            logAudit($user['id'], 'update_user', ['user_id' => $userId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully'
            ]);
            break;
            
        case 'delete':
            if (!in_array($user['role'], ['admin', 'zone_supervisor'])) {
                throw new Exception('Unauthorized');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $userId = $data['user_id'] ?? null;
            
            if (!$userId) {
                throw new Exception('User ID required');
            }
            
            if ($userId == $user['id']) {
                throw new Exception('Cannot delete yourself');
            }
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            $stmt->execute([$userId]);
            
            logAudit($user['id'], 'delete_user', ['user_id' => $userId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
            break;

        // ============================================================
        // NEW: Check phone availability (used by users.php page)
        // ============================================================
        case 'check_phone':
            $phone = $_GET['phone'] ?? '';
            $excludeId = (int)($_GET['exclude_id'] ?? 0);

            // Normalize (Zambian format)
            $normalized = preg_replace('/[^0-9]/', '', $phone);
            if (strpos($normalized, '260') === 0) {
                $normalized = substr($normalized, 3);
            }

            if (!preg_match('/^(09|07)[0-9]{8}$/', $normalized)) {
                echo json_encode([
                    'success' => false,
                    'exists'  => false,
                    'error'   => 'invalid_format'
                ]);
                break;
            }

            $existing = phoneExists($pdo, $normalized, $excludeId);

            echo json_encode([
                'success'   => true,
                'exists'    => (bool)$existing,
                'full_name' => $existing['full_name'] ?? null,
                'user_id'   => $existing['id'] ?? null,
            ]);
            break;

        // ============================================================
        // NEW: Get single user by id (used by users.php edit modal)
        // ============================================================
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('User ID required');

            $stmt = $pdo->prepare("
                SELECT u.*, z.name AS zone_name
                FROM users u
                LEFT JOIN zones z ON u.zone_id = z.id
                WHERE u.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) throw new Exception('User not found');

            // Zone supervisor can only view users in their zone
            if ($user['role'] === 'zone_supervisor' && $row['zone_id'] != $user['zone_id']) {
                throw new Exception('Unauthorized');
            }

            unset($row['password_hash']);

            echo json_encode([
                'success' => true,
                'user'    => $row,
            ]);
            break;

        // ============================================================
        // NEW: Count incidents for a user (used by delete modal)
        // ============================================================
        case 'check_incidents':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) throw new Exception('User ID required');

            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS count
                FROM incidents
                WHERE reporter_id = ? OR acknowledged_by = ?
            ");
            $stmt->execute([$id, $id]);
            $count = (int)$stmt->fetch()['count'];

            echo json_encode([
                'success'        => true,
                'incident_count' => $count,
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
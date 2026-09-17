<?php
header('Content-Type: application/json');
require_once '../includes/functions.php';
requireLogin();

$pdo = getDB();
$user = getCurrentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'send':
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['content'])) {
                throw new Exception('Message content required');
            }
            
            $recipientId = $data['recipient_id'] ?? null;
            $incidentId = $data['incident_id'] ?? null;
            $messageType = $data['message_type'] ?? 'general';
            $isBroadcast = isset($data['is_broadcast']) ? 1 : 0;
            $subject = $data['subject'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT INTO messages (
                    sender_id, recipient_id, incident_id, message_type,
                    subject, content, is_broadcast, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $user['id'],
                $recipientId,
                $incidentId,
                $messageType,
                $subject,
                $data['content'],
                $isBroadcast
            ]);
            
            $messageId = $pdo->query('SELECT lastval()')->fetchColumn();
            
            // Notify recipient
            if ($recipientId) {
                createNotification(
                    $recipientId,
                    'new_message',
                    '💬 New Message',
                    "Message from {$user['full_name']}: " . substr($data['content'], 0, 50),
                    $incidentId,
                    $messageId
                );
            }
            
            // If broadcast, notify all rangers in zone
            if ($isBroadcast && $user['zone_id']) {
                $rangers = $pdo->prepare("
                    SELECT id FROM users 
                    WHERE zone_id = ? AND role = 'ranger' AND is_active = 1 AND id != ?
                ");
                $rangers->execute([$user['zone_id'], $user['id']]);
                
                while ($ranger = $rangers->fetch()) {
                    createNotification(
                        $ranger['id'],
                        'new_message',
                        '📢 Broadcast Message',
                        "Broadcast from {$user['full_name']}: " . substr($data['content'], 0, 50),
                        $incidentId,
                        $messageId
                    );
                }
            }
            
            logAudit($user['id'], 'send_message', ['message_id' => $messageId]);
            
            echo json_encode([
                'success' => true,
                'message_id' => $messageId,
                'message' => 'Message sent successfully'
            ]);
            break;
            
        case 'list':
            $limit = $_GET['limit'] ?? 50;
            
            $stmt = $pdo->prepare("
                SELECT m.*, 
                       u1.full_name as sender_name,
                       u2.full_name as recipient_name,
                       i.category as incident_category
                FROM messages m
                LEFT JOIN users u1 ON m.sender_id = u1.id
                LEFT JOIN users u2 ON m.recipient_id = u2.id
                LEFT JOIN incidents i ON m.incident_id = i.id
                WHERE m.recipient_id = ? 
                   OR m.sender_id = ?
                   OR (m.is_broadcast = 1 AND m.sender_id != ? AND ? IN (
                       SELECT id FROM users WHERE zone_id = (SELECT zone_id FROM users WHERE id = ?) AND role = 'ranger'
                   ))
                ORDER BY m.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id'], $limit]);
            $messages = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages)
            ]);
            break;
            
        case 'mark_read':
            $data = json_decode(file_get_contents('php://input'), true);
            $messageId = $data['message_id'] ?? null;
            
            if (!$messageId) {
                throw new Exception('Message ID required');
            }
            
            $stmt = $pdo->prepare("
                UPDATE messages 
                SET is_read = 1, read_at = NOW() 
                WHERE id = ? AND recipient_id = ?
            ");
            $stmt->execute([$messageId, $user['id']]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Message marked as read'
            ]);
            break;
            
        case 'unread_count':
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM messages 
                WHERE (recipient_id = ? OR is_broadcast = 1) AND is_read = 0
            ");
            $stmt->execute([$user['id']]);
            $count = $stmt->fetch()['count'];
            
            echo json_encode([
                'success' => true,
                'count' => $count
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
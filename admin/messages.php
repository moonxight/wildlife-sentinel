<?php
require_once '../includes/functions.php';
requireLogin();

$user = getCurrentUser();
$pdo = getDB();
$error = '';
$success = '';

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'send_message') {
        $recipientId = !empty($_POST['recipient_id']) ? $_POST['recipient_id'] : null;
        $content = sanitize($_POST['content']);
        $messageType = sanitize($_POST['message_type'] ?? 'general');
        $isBroadcast = isset($_POST['is_broadcast']) ? 1 : 0;
        $incidentId = !empty($_POST['incident_id']) ? $_POST['incident_id'] : null;
        $subject = sanitize($_POST['subject'] ?? '');
        $parentMessageId = !empty($_POST['parent_message_id']) ? $_POST['parent_message_id'] : null;
        
        if (empty($content)) {
            $error = 'Message content is required';
        } else {
            try {
                if ($incidentId) {
                    $check = $pdo->prepare("SELECT id FROM incidents WHERE id = ?");
                    $check->execute([$incidentId]);
                    if (!$check->fetch()) $incidentId = null;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO messages (
                        sender_id, recipient_id, incident_id, message_type, 
                        subject, content, is_broadcast, parent_message_id, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                if ($stmt->execute([$user['id'], $recipientId, $incidentId, $messageType, $subject, $content, $isBroadcast, $parentMessageId])) {
                    $messageId = $pdo->query('SELECT lastval()')->fetchColumn();
                    
                    if ($recipientId) {
                        createNotification(
                            $recipientId, 'new_message', '💬 New Message',
                            "Message from {$user['full_name']}: " . substr($content, 0, 50),
                            $incidentId, $messageId
                        );
                    }
                    
                    if ($isBroadcast && $user['zone_id']) {
                        $users = $pdo->prepare("
                            SELECT id FROM users 
                            WHERE zone_id = ? AND role IN ('ranger', 'zone_supervisor') AND is_active = 1 AND id != ?
                        ");
                        $users->execute([$user['zone_id'], $user['id']]);
                        while ($u = $users->fetch()) {
                            createNotification($u['id'], 'new_message', '📢 Broadcast Message',
                                "Broadcast from {$user['full_name']}: " . substr($content, 0, 50),
                                $incidentId, $messageId);
                        }
                    }
                    
                    logAudit($user['id'], 'send_message', ['message_id' => $messageId]);
                    $success = 'Message sent successfully';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // REPLY TO MESSAGE
    if ($action === 'reply_message') {
        $parentMessageId = $_POST['parent_message_id'];
        $recipientId = $_POST['recipient_id'];
        $content = sanitize($_POST['reply_content']);
        
        if (empty($content)) {
            $error = 'Reply content is required';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO messages (
                        sender_id, recipient_id, message_type, 
                        content, parent_message_id, created_at
                    ) VALUES (?, ?, 'general', ?, ?, NOW())
                ");
                
                if ($stmt->execute([$user['id'], $recipientId, $content, $parentMessageId])) {
                    $messageId = $pdo->query('SELECT lastval()')->fetchColumn();
                    
                    createNotification(
                        $recipientId, 'new_message', '💬 Reply Received',
                        "Reply from {$user['full_name']}: " . substr($content, 0, 50),
                        null, $messageId
                    );
                    
                    logAudit($user['id'], 'reply_message', ['message_id' => $messageId]);
                    $success = 'Reply sent successfully';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Mark message as read
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW() WHERE id = ? AND recipient_id = ?")
        ->execute([$_GET['id'], $user['id']]);
    header('Location: messages.php');
    exit();
}

// Delete message
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $stmt = $pdo->prepare("DELETE FROM messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?)");
    $stmt->execute([$_GET['id'], $user['id'], $user['id']]);
    header('Location: messages.php');
    exit();
}

// Get messages
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM messages WHERE recipient_id = ? OR sender_id = ? OR (is_broadcast = 1 AND sender_id != ?)");
$stmt->execute([$user['id'], $user['id'], $user['id']]);
$total = $stmt->fetch()['total'];
$totalPages = ceil($total / $limit);

$stmt = $pdo->prepare("
    SELECT m.*, 
           u1.full_name as sender_name, u1.role as sender_role,
           u2.full_name as recipient_name, u2.role as recipient_role,
           i.category as incident_category, i.status as incident_status,
           (SELECT COUNT(*) FROM messages WHERE parent_message_id = m.id) as reply_count
    FROM messages m
    LEFT JOIN users u1 ON m.sender_id = u1.id
    LEFT JOIN users u2 ON m.recipient_id = u2.id
    LEFT JOIN incidents i ON m.incident_id = i.id
    WHERE m.recipient_id = ? OR m.sender_id = ? OR (m.is_broadcast = 1 AND m.sender_id != ?)
    ORDER BY m.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$user['id'], $user['id'], $user['id'], $limit, $offset]);
$messages = $stmt->fetchAll();

// Get users for messaging
$usersList = $pdo->prepare("
    SELECT id, full_name, role, zone_id, (SELECT name FROM zones WHERE id = zone_id) as zone_name
    FROM users WHERE id != ? AND is_active = 1 ORDER BY full_name
");
$usersList->execute([$user['id']]);
$users = $usersList->fetchAll();

// Get incidents
$incidentsList = $pdo->prepare("
    SELECT id, category, status, description 
    FROM incidents WHERE status NOT IN ('resolved', 'closed')
    ORDER BY reported_at DESC LIMIT 20
");
$incidentsList->execute();
$incidents = $incidentsList->fetchAll();

$unreadCount = getUnreadMessageCount($user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Messages - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        .messages-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .compose-section, .messages-list-section { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .compose-section h2, .messages-list-section h2 { font-size: 18px; color: #0d3b22; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; display: flex; align-items: center; gap: 10px; }
        .messages-list-section .badge { font-size: 12px; padding: 2px 10px; border-radius: 12px; background: #dc3545; color: white; }
        
        .message-item { background: white; border: 1px solid #e9ecef; border-radius: 10px; padding: 14px 18px; margin-bottom: 10px; transition: all 0.2s; border-left: 4px solid transparent; }
        .message-item:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .message-item.unread { border-left-color: #17a2b8; background: #f0f9ff; }
        .message-item.is-reply { margin-left: 30px; border-left: 4px solid #6f42c1; background: #f8f5ff; }
        
        .message-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
        .sender { font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .role-badge { font-size: 10px; padding: 1px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase; }
        .role-badge.admin { background: #dc3545; color: white; }
        .role-badge.ranger { background: #007bff; color: white; }
        .role-badge.scout { background: #17a2b8; color: white; }
        .role-badge.tourism { background: #ffc107; color: #333; }
        .role-badge.zone_supervisor { background: #6f42c1; color: white; }
        
        .message-type-badge { font-size: 10px; padding: 2px 10px; border-radius: 12px; font-weight: 600; text-transform: uppercase; }
        .message-type-badge.general { background: #e9ecef; color: #495057; }
        .message-type-badge.manpower_request { background: #f8d7da; color: #721c24; }
        .message-type-badge.emergency { background: #dc3545; color: white; }
        .message-type-badge.status_update { background: #cce5ff; color: #004085; }
        
        .message-time { font-size: 11px; color: #adb5bd; }
        .message-subject { font-weight: 600; font-size: 14px; margin: 4px 0 2px; }
        .message-body { font-size: 13px; color: #495057; line-height: 1.5; margin: 4px 0; }
        .message-meta { display: flex; gap: 12px; flex-wrap: wrap; font-size: 11px; color: #6c757d; margin-top: 6px; }
        .message-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        
        .reply-indicator { font-size: 11px; color: #6f42c1; display: flex; align-items: center; gap: 4px; margin-top: 4px; }
        .reply-count-badge { background: #6f42c1; color: white; font-size: 10px; padding: 1px 8px; border-radius: 10px; margin-left: 6px; }
        
        /* Reply Form Inline */
        .reply-form { background: #f8f5ff; border-radius: 8px; padding: 12px 16px; margin-top: 10px; border-left: 3px solid #6f42c1; display: none; }
        .reply-form.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .reply-form textarea { width: 100%; padding: 10px 14px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; resize: vertical; min-height: 60px; }
        .reply-form textarea:focus { border-color: #6f42c1; outline: none; }
        .reply-form .reply-actions { display: flex; gap: 8px; margin-top: 8px; }
        
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 16px; flex-wrap: wrap; }
        .pagination .page-link { padding: 6px 14px; border: 1px solid #dee2e6; border-radius: 6px; text-decoration: none; color: #495057; font-size: 13px; }
        .pagination .page-link:hover { background: #1a5c3a; color: white; }
        .pagination .page-link.active { background: #1a5c3a; color: white; }
        
        .empty-state { text-align: center; padding: 40px 20px; color: #6c757d; }
        .empty-state .icon { font-size: 48px; margin-bottom: 10px; }
        
        @media (max-width: 1024px) { .messages-container { grid-template-columns: 1fr; gap: 16px; } }
        @media (max-width: 768px) {
            .compose-section, .messages-list-section { padding: 16px; }
            .message-item { padding: 12px 14px; }
            .message-item.is-reply { margin-left: 15px; }
            .message-header { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 480px) {
            .compose-section, .messages-list-section { padding: 12px; }
            .message-item { padding: 10px 12px; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Messages</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="user-name"><?= $user['full_name'] ?></span>
                </div>
            </header>
            
            <div class="content">
                <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger">❌ <?= $error ?></div><?php endif; ?>
                
                <div class="messages-container">
                    <!-- Compose Message -->
                    <div class="compose-section">
                        <h2>✏️ Compose Message</h2>
                        <form method="POST">
                            <input type="hidden" name="action" value="send_message">
                            
                            <div class="form-group">
                                <label>Recipient</label>
                                <select name="recipient_id" class="form-control">
                                    <option value="">Select Recipient</option>
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>">
                                        <?= $u['full_name'] ?> (<?= ucfirst(str_replace('_', ' ', $u['role'])) ?>)
                                        <?= $u['zone_name'] ? ' - ' . $u['zone_name'] : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="broadcast-option" style="margin-top:8px;">
                                    <label><input type="checkbox" name="is_broadcast" value="1"> 📢 Broadcast to all rangers in my zone</label>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Subject</label>
                                <input type="text" name="subject" class="form-control" placeholder="Enter subject">
                            </div>
                            
                            <div class="form-group">
                                <label>Related Incident</label>
                                <select name="incident_id" class="form-control">
                                    <option value="">None</option>
                                    <?php foreach ($incidents as $inc): ?>
                                    <option value="<?= $inc['id'] ?>">#<?= $inc['id'] ?> - <?= ucfirst($inc['category']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Message Type</label>
                                <select name="message_type" class="form-control">
                                    <option value="general">General</option>
                                    <option value="status_update">Status Update</option>
                                    <option value="manpower_request">🆘 Manpower Request</option>
                                    <option value="emergency">🚨 Emergency</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Message *</label>
                                <textarea name="content" class="form-control" rows="4" required placeholder="Type your message..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-block">Send Message</button>
                        </form>
                    </div>
                    
                    <!-- Messages List -->
                    <div class="messages-list-section">
                        <h2>
                            💬 Messages
                            <?php if ($unreadCount > 0): ?><span class="badge"><?= $unreadCount ?> new</span><?php endif; ?>
                        </h2>
                        
                        <?php if (count($messages) > 0): ?>
                            <?php foreach ($messages as $msg): ?>
                            <div class="message-item <?= !$msg['is_read'] && $msg['recipient_id'] == $user['id'] ? 'unread' : '' ?> <?= $msg['parent_message_id'] ? 'is-reply' : '' ?>">
                                <div class="message-header">
                                    <div class="sender">
                                        <span><?= $msg['sender_name'] ?></span>
                                        <?php if ($msg['sender_role']): ?>
                                        <span class="role-badge <?= $msg['sender_role'] ?>"><?= ucfirst(str_replace('_', ' ', $msg['sender_role'])) ?></span>
                                        <?php endif; ?>
                                        <?php if ($msg['is_broadcast']): ?><span class="reply-indicator">📢 Broadcast</span><?php endif; ?>
                                        <?php if ($msg['parent_message_id']): ?><span class="reply-indicator">↩️ Reply</span><?php endif; ?>
                                        <?php if ($msg['reply_count'] > 0): ?>
                                        <span class="reply-count-badge"><?= $msg['reply_count'] ?> replies</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="message-type-badge <?= $msg['message_type'] ?>"><?= str_replace('_', ' ', $msg['message_type']) ?></span>
                                        <span class="message-time"><?= timeAgo($msg['created_at']) ?></span>
                                    </div>
                                </div>
                                
                                <?php if ($msg['subject']): ?><div class="message-subject"><?= $msg['subject'] ?></div><?php endif; ?>
                                <div class="message-body"><?= nl2br($msg['content']) ?></div>
                                
                                <?php if ($msg['incident_category']): ?>
                                <div class="message-meta">📌 Related: #<?= $msg['incident_id'] ?> - <?= ucfirst($msg['incident_category']) ?></div>
                                <?php endif; ?>
                                
                                <div class="message-actions">
                                    <?php if ($msg['sender_id'] != $user['id']): ?>
                                    <button class="btn-small btn-primary" onclick="toggleReply(<?= $msg['id'] ?>)">↩️ Reply</button>
                                    <?php endif; ?>
                                    <?php if (!$msg['is_read'] && $msg['recipient_id'] == $user['id']): ?>
                                    <a href="?mark_read=1&id=<?= $msg['id'] ?>" class="btn-small btn-info">Mark Read</a>
                                    <?php endif; ?>
                                    <a href="?delete=1&id=<?= $msg['id'] ?>" class="btn-small btn-danger" onclick="return confirm('Delete?')">🗑️</a>
                                </div>
                                
                                <!-- Reply Form -->
                                <?php if ($msg['sender_id'] != $user['id']): ?>
                                <div class="reply-form" id="reply-<?= $msg['id'] ?>">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="reply_message">
                                        <input type="hidden" name="parent_message_id" value="<?= $msg['id'] ?>">
                                        <input type="hidden" name="recipient_id" value="<?= $msg['sender_id'] ?>">
                                        <textarea name="reply_content" placeholder="Type your reply to <?= $msg['sender_name'] ?>..." required></textarea>
                                        <div class="reply-actions">
                                            <button type="submit" class="btn btn-primary btn-small">📤 Send Reply</button>
                                            <button type="button" class="btn btn-secondary btn-small" onclick="toggleReply(<?= $msg['id'] ?>)">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="?page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="icon">📭</div>
                                <h3>No Messages</h3>
                                <p>You don't have any messages yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script src="../assets/js/app.js"></script>
    <script>
        function toggleReply(id) {
            const form = document.getElementById('reply-' + id);
            form.classList.toggle('show');
            if (form.classList.contains('show')) {
                form.querySelector('textarea').focus();
            }
        }
    </script>
</body>
</html>
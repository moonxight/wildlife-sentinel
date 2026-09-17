<?php
require_once '../includes/functions.php';
requireLogin();

if (!hasRole('tourism')) {
    header('Location: ../index.php');
    exit();
}

$user = getCurrentUser();
$pdo = getDB();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'send_message') {
        $recipientId = !empty($_POST['recipient_id']) ? $_POST['recipient_id'] : null;
        $content = sanitize($_POST['content']);
        $incidentId = !empty($_POST['incident_id']) ? $_POST['incident_id'] : null;
        $subject = sanitize($_POST['subject'] ?? '');
        
        if (empty($content)) {
            $error = 'Message content is required';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, incident_id, message_type, subject, content, created_at)
                VALUES (?, ?, ?, 'general', ?, ?, NOW())
            ");
            
            if ($stmt->execute([$user['id'], $recipientId, $incidentId, $subject, $content])) {
                $messageId = $pdo->query('SELECT lastval()')->fetchColumn();
                
                if ($recipientId) {
                    createNotification($recipientId, 'new_message', '💬 New Message from Tourism',
                        "Message from {$user['full_name']}: " . substr($content, 0, 50), $incidentId, $messageId);
                }
                
                logAudit($user['id'], 'send_message', ['message_id' => $messageId]);
                $success = 'Message sent successfully';
            }
        }
    }
    
    if ($action === 'reply_message') {
        $parentMessageId = $_POST['parent_message_id'];
        $recipientId = $_POST['recipient_id'];
        $content = sanitize($_POST['reply_content']);
        
        if (empty($content)) {
            $error = 'Reply content is required';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, message_type, content, parent_message_id, created_at)
                VALUES (?, ?, 'general', ?, ?, NOW())
            ");
            
            if ($stmt->execute([$user['id'], $recipientId, $content, $parentMessageId])) {
                $messageId = $pdo->query('SELECT lastval()')->fetchColumn();
                createNotification($recipientId, 'new_message', '💬 Reply Received',
                    "Reply from {$user['full_name']}: " . substr($content, 0, 50), null, $messageId);
                $success = 'Reply sent successfully';
            }
        }
    }
}

if (isset($_GET['mark_read'])) {
    $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW() WHERE id = ? AND recipient_id = ?")->execute([$_GET['id'], $user['id']]);
    header('Location: messages.php'); exit();
}

if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?)")->execute([$_GET['id'], $user['id'], $user['id']]);
    header('Location: messages.php'); exit();
}

$stmt = $pdo->prepare("
    SELECT m.*, u1.full_name as sender_name, u1.role as sender_role,
           u2.full_name as recipient_name, u2.role as recipient_role,
           i.category as incident_category, i.location_lat, i.location_lng,
           (SELECT COUNT(*) FROM messages WHERE parent_message_id = m.id) as reply_count
    FROM messages m
    LEFT JOIN users u1 ON m.sender_id = u1.id
    LEFT JOIN users u2 ON m.recipient_id = u2.id
    LEFT JOIN incidents i ON m.incident_id = i.id
    WHERE m.recipient_id = ? OR m.sender_id = ?
    ORDER BY m.created_at DESC LIMIT 50
");
$stmt->execute([$user['id'], $user['id']]);
$messages = $stmt->fetchAll();

$usersList = $pdo->prepare("
    SELECT id, full_name, role, zone_id, (SELECT name FROM zones WHERE id = zone_id) as zone_name
    FROM users 
    WHERE id != ? AND is_active = 1 
    AND role IN ('ranger', 'zone_supervisor', 'admin')
    AND (zone_id = ? OR role = 'admin')
    ORDER BY role, full_name
");
$usersList->execute([$user['id'], $user['zone_id']]);
$users = $usersList->fetchAll();

$incidentsList = $pdo->prepare("
    SELECT id, category, status FROM incidents WHERE reporter_id = ? AND status NOT IN ('resolved', 'closed')
    ORDER BY reported_at DESC LIMIT 20
");
$incidentsList->execute([$user['id']]);
$incidents = $incidentsList->fetchAll();

$unreadCount = getUnreadMessageCount($user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Tourism - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        .messages-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .compose-section, .messages-list-section { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .compose-section h2, .messages-list-section h2 { font-size: 18px; color: #0d3b22; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f0; }
        .messages-list-section .badge { font-size: 12px; padding: 2px 10px; border-radius: 12px; background: #dc3545; color: white; }
        
        .zone-info { background: #fffdf5; padding: 8px 14px; border-radius: 8px; font-size: 12px; color: #6c757d; margin-bottom: 12px; border-left: 3px solid #ffc107; }
        
        .message-item { background: white; border: 1px solid #e9ecef; border-radius: 10px; padding: 14px 18px; margin-bottom: 10px; border-left: 4px solid transparent; }
        .message-item.unread { border-left-color: #ffc107; background: #fffdf5; }
        .message-item.is-reply { margin-left: 30px; border-left: 4px solid #6f42c1; background: #f8f5ff; }
        
        .message-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
        .sender { font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .role-badge { font-size: 10px; padding: 1px 8px; border-radius: 10px; font-weight: 600; }
        .role-badge.admin { background: #dc3545; color: white; }
        .role-badge.ranger { background: #007bff; color: white; }
        .role-badge.zone_supervisor { background: #6f42c1; color: white; }
        .message-time { font-size: 11px; color: #adb5bd; }
        .message-body { font-size: 13px; color: #495057; margin: 4px 0; }
        .message-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        
        .reply-form { background: #f8f5ff; border-radius: 8px; padding: 12px 16px; margin-top: 10px; border-left: 3px solid #6f42c1; display: none; }
        .reply-form.show { display: block; }
        .reply-form textarea { width: 100%; padding: 10px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; min-height: 60px; resize: vertical; }
        .reply-form textarea:focus { border-color: #6f42c1; outline: none; }
        
        @media (max-width: 1024px) { .messages-container { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .message-item.is-reply { margin-left: 15px; } }
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
                    <div class="compose-section">
                        <h2>✏️ Compose Message</h2>
                        <div class="zone-info">
                            📍 Zone: <strong><?= getZoneName($user['zone_id']) ?></strong>
                            <br><span style="font-size:11px;">✅ Message rangers and supervisors in your zone</span>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="send_message">
                            
                            <div class="form-group">
                                <label>Recipient</label>
                                <select name="recipient_id" class="form-control" required>
                                    <option value="">Select Recipient</option>
                                    <?php foreach ($users as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= $u['full_name'] ?> (<?= ucfirst($u['role']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Subject</label>
                                <input type="text" name="subject" class="form-control" placeholder="Subject">
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
                                <label>Message *</label>
                                <textarea name="content" class="form-control" rows="4" required placeholder="Type your message..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-block" style="background:#ffc107;color:#333;">Send</button>
                        </form>
                    </div>
                    
                    <div class="messages-list-section">
                        <h2>💬 Messages <?php if ($unreadCount > 0): ?><span class="badge"><?= $unreadCount ?></span><?php endif; ?></h2>
                        
                        <?php if (count($messages) > 0): ?>
                            <?php foreach ($messages as $msg): ?>
                            <div class="message-item <?= !$msg['is_read'] && $msg['recipient_id'] == $user['id'] ? 'unread' : '' ?> <?= $msg['parent_message_id'] ? 'is-reply' : '' ?>">
                                <div class="message-header">
                                    <div class="sender">
                                        <span><?= $msg['sender_name'] ?></span>
                                        <span class="role-badge <?= $msg['sender_role'] ?>"><?= ucfirst($msg['sender_role']) ?></span>
                                        <?php if ($msg['parent_message_id']): ?><span style="color:#6f42c1;font-size:11px;">↩️ Reply</span><?php endif; ?>
                                    </div>
                                    <span class="message-time"><?= timeAgo($msg['created_at']) ?></span>
                                </div>
                                
                                <?php if ($msg['subject']): ?><div style="font-weight:600;margin:4px 0;"><?= $msg['subject'] ?></div><?php endif; ?>
                                <div class="message-body"><?= nl2br($msg['content']) ?></div>
                                
                                <div class="message-actions">
                                    <?php if ($msg['sender_id'] != $user['id']): ?>
                                    <button class="btn-small btn-primary" onclick="toggleReply(<?= $msg['id'] ?>)" style="background:#ffc107;color:#333;">↩️ Reply</button>
                                    <?php endif; ?>
                                    <?php if (!$msg['is_read'] && $msg['recipient_id'] == $user['id']): ?>
                                    <a href="?mark_read=1&id=<?= $msg['id'] ?>" class="btn-small btn-info">Mark Read</a>
                                    <?php endif; ?>
                                    <a href="?delete=1&id=<?= $msg['id'] ?>" class="btn-small btn-danger" onclick="return confirm('Delete?')">🗑️</a>
                                </div>
                                
                                <?php if ($msg['sender_id'] != $user['id']): ?>
                                <div class="reply-form" id="reply-<?= $msg['id'] ?>">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="reply_message">
                                        <input type="hidden" name="parent_message_id" value="<?= $msg['id'] ?>">
                                        <input type="hidden" name="recipient_id" value="<?= $msg['sender_id'] ?>">
                                        <textarea name="reply_content" placeholder="Reply to <?= $msg['sender_name'] ?>..." required></textarea>
                                        <div style="display:flex;gap:8px;margin-top:8px;">
                                            <button type="submit" class="btn btn-primary btn-small" style="background:#ffc107;color:#333;">Send Reply</button>
                                            <button type="button" class="btn btn-secondary btn-small" onclick="toggleReply(<?= $msg['id'] ?>)">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><p>No messages</p></div>
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
            if (form.classList.contains('show')) form.querySelector('textarea').focus();
        }
    </script>
</body>
</html>
<?php
// ============================================================
// includes/functions.php
// Wildlife Sentinel — Shared helper functions (v3.2)
// ------------------------------------------------------------
// All functions are guarded with function_exists() to prevent
// "Cannot redeclare" fatal errors when included from multiple
// entry points. All DB-touching functions are wrapped in
// try/catch so missing optional tables never cause fatals.
//
// SMS POLICY (v3.2):
//   - sendSMS() is the AUTHORITATIVE implementation. It returns
//     an ARRAY: ['success'=>bool, 'ref'=>?string, 'error'=>?string,
//                'provider'=>string, 'segments'=>int]
//   - Respects the global sms_enabled setting.
//   - Real provider loaded from includes/sms.php (optional).
//     If absent, calls are QUEUED (status='pending').
//   - Reporter-facing helpers return VOID.
//   - Admin/supervisor helpers return full stats array.
//   - Every attempt is logged to sms_logs via ws_log_sms().
// ============================================================

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// PROJECT ROOT DETECTION
// ============================================================
if (!function_exists('ws_project_root_prefix')) {
    function ws_project_root_prefix(): string {
        $self = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
        if (preg_match('#^(.*?/wildlife-sentinel)(/.*)?$#i', $self, $m)) {
            $rest  = $m[2] ?? '';
            $depth = substr_count(trim($rest, '/'), '/');
            return str_repeat('../', $depth);
        }
        $dir   = rtrim(dirname($self), '/');
        $parts = array_values(array_filter(explode('/', $dir), fn($p) => $p !== '' && $p !== '.'));
        if (!empty($parts)) array_pop($parts);
        return str_repeat('../', count($parts));
    }
}

// ============================================================
// USER AUTHENTICATION
// ============================================================
if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) return null;
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([$_SESSION['user_id']]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }
}
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() { return isset($_SESSION['user_id']); }
}
if (!function_exists('hasRole')) {
    function hasRole($role) {
        $user = getCurrentUser();
        if (!$user) return false;
        if ($user['role'] === 'admin') return true;
        return $user['role'] === $role;
    }
}
if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) {
            $root = ws_project_root_prefix();
            header('Location: ' . $root . 'login.php');
            exit();
        }
    }
}
if (!function_exists('requireAdmin')) {
    function requireAdmin() {
        requireLogin();
        if (!hasRole('admin')) {
            $root = ws_project_root_prefix();
            header('Location: ' . $root . 'index.php');
            exit();
        }
    }
}
if (!function_exists('requireSupervisor')) {
    function requireSupervisor() {
        requireLogin();
        if (!hasRole('admin') && !hasRole('zone_supervisor')) {
            $root = ws_project_root_prefix();
            header('Location: ' . $root . 'index.php');
            exit();
        }
    }
}
if (!function_exists('getUserZone')) {
    function getUserZone() {
        $user = getCurrentUser();
        return $user ? $user['zone_id'] : null;
    }
}

// ============================================================
// SETTINGS
// ============================================================
if (!function_exists('ws_load_settings')) {
    function ws_load_settings(bool $force = false): array {
        static $cache = null;
        if ($cache !== null && !$force) return $cache;

        if (!$force && isset($_SESSION['ws_settings']) && is_array($_SESSION['ws_settings'])) {
            $cache = $_SESSION['ws_settings'];
            return $cache;
        }

        $out = [];
        try {
            $pdo  = getDB();
            $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e) { /* settings table may not exist yet */ }

        $_SESSION['ws_settings'] = $out;
        $cache = $out;
        return $out;
    }
}
if (!function_exists('getSetting')) {
    function getSetting(string $key, $default = null) {
        $s = ws_load_settings();
        return array_key_exists($key, $s) ? $s[$key] : $default;
    }
}
if (!function_exists('settingEnabled')) {
    function settingEnabled(string $key, bool $default = false): bool {
        $v = getSetting($key, null);
        if ($v === null) return $default;
        return (string)$v === '1';
    }
}
if (!function_exists('ws_clear_settings_cache')) {
    function ws_clear_settings_cache(): void { unset($_SESSION['ws_settings']); }
}

// ============================================================
// SESSION TIMEOUT
// ============================================================
if (!function_exists('applySessionTimeout')) {
    function applySessionTimeout(): void {
        $mins = (int)getSetting('session_timeout_min', 60);
        if ($mins <= 0) return;
        $limit = $mins * 60;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $limit) {
            session_unset();
            session_destroy();
            $root = ws_project_root_prefix();
            header('Location: ' . $root . 'login.php?timeout=1');
            exit();
        }
        $_SESSION['last_activity'] = time();
    }
}

// ============================================================
// MAINTENANCE MODE
// ============================================================
if (!function_exists('enforceMaintenanceMode')) {
    function enforceMaintenanceMode(): void {
        $script = basename($_SERVER['PHP_SELF'] ?? '');
        if (in_array($script, ['maintenance.php','login.php','index.php','logout.php'], true)) return;

        $isAjax = isset($_GET['ajax']);

        $mode = false;
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();
            $mode = $row && (string)$row['setting_value'] === '1';
        } catch (Throwable $e) { return; }

        if (!$mode) return;

        $u = function_exists('getCurrentUser') ? getCurrentUser() : null;
        if ($u && ($u['role'] ?? '') === 'admin') {
            $GLOBALS['WS_MAINTENANCE_BANNER'] = true;
            return;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(503);
            echo json_encode(['success' => false, 'error' => 'maintenance_mode']);
            exit;
        }
        $root = ws_project_root_prefix();
        header('Location: ' . $root . 'maintenance.php');
        exit();
    }
}

// ============================================================
// SANITIZATION
// ============================================================
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if (is_array($input)) return array_map('sanitize', $input);
        return htmlspecialchars(strip_tags(trim((string)$input)), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('validateEmail')) {
    function validateEmail($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('hashPassword')) {
    function hashPassword($password) { return password_hash($password, PASSWORD_DEFAULT); }
}
if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) { return password_verify($password, $hash); }
}
if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 10) { return bin2hex(random_bytes($length)); }
}

// ============================================================
// GENERIC SAFE DB HELPERS
// ============================================================
if (!function_exists('safeCount')) {
    function safeCount(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row) return 0;
            return (int)($row['count'] ?? $row['c'] ?? 0);
        } catch (PDOException $e) {
            error_log('[WS] safeCount: ' . $e->getMessage());
            return 0;
        }
    }
}
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[WS] safeFetchAll: ' . $e->getMessage());
            return [];
        }
    }
}
if (!function_exists('safeFetchOne')) {
    function safeFetchOne(PDO $pdo, string $sql, array $params = []) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) { return null; }
    }
}
if (!function_exists('safeExec')) {
    function safeExec(PDO $pdo, string $sql, array $params = []): bool {
        try {
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('[WS] safeExec: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================
// DATABASE HELPERS
// ============================================================
if (!function_exists('getZoneName')) {
    function getZoneName($zoneId) {
        if (!$zoneId) return 'N/A';
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT name FROM zones WHERE id = ?");
            $stmt->execute([$zoneId]);
            $r = $stmt->fetch();
            return $r ? $r['name'] : 'N/A';
        } catch (PDOException $e) { return 'N/A'; }
    }
}
if (!function_exists('getZone')) {
    function getZone($zoneId) {
        if (!$zoneId) return null;
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
            $stmt->execute([$zoneId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return null; }
    }
}
if (!function_exists('getUser')) {
    function getUser($userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return null; }
    }
}

// ============================================================
// NOTIFICATIONS
// ============================================================
if (!function_exists('createNotification')) {
    function createNotification($userId, $type, $title, $body, $incidentId = null, $messageId = null) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, body, incident_id, message_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $type, $title, $body, $incidentId, $messageId]);
            return $pdo->query('SELECT lastval()')->fetchColumn();
        } catch (PDOException $e) {
            error_log('[WS] createNotification: ' . $e->getMessage());
            return false;
        }
    }
}
if (!function_exists('getUserNotifications')) {
    function getUserNotifications($userId, $limit = 20) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getUnreadNotificationCount')) {
    function getUnreadNotificationCount($userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            return (int)($stmt->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}
if (!function_exists('markNotificationRead')) {
    function markNotificationRead($notificationId, $userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
            return $stmt->execute([$notificationId, $userId]);
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('markAllNotificationsRead')) {
    function markAllNotificationsRead($userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
            return $stmt->execute([$userId]);
        } catch (PDOException $e) { return false; }
    }
}

// ============================================================
// INCIDENT HELPERS
// ============================================================
if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status) {
        $badges = [
            'reported'     => '<span class="badge badge-info">📋 Reported</span>',
            'acknowledged' => '<span class="badge badge-warning">⏳ Acknowledged</span>',
            'in_progress'  => '<span class="badge badge-primary">🔄 In Progress</span>',
            'resolved'     => '<span class="badge badge-success">✅ Resolved</span>',
            'closed'       => '<span class="badge badge-secondary">📌 Closed</span>',
        ];
        return $badges[$status] ?? $badges['reported'];
    }
}
if (!function_exists('getSeverityBadge')) {
    function getSeverityBadge($severity) {
        $badges = [
            'low'      => '<span class="badge badge-success">Low</span>',
            'medium'   => '<span class="badge badge-warning">Medium</span>',
            'high'     => '<span class="badge badge-danger">High</span>',
            'critical' => '<span class="badge badge-critical">⚠️ Critical</span>',
        ];
        return $badges[$severity] ?? $badges['medium'];
    }
}
if (!function_exists('getCategoryIcon')) {
    function getCategoryIcon($category) {
        $icons = [
            'poaching'                => '🦏',
            'distressed_animal'       => '🐘',
            'human_wildlife_conflict' => '🐆',
            'environmental_risk'      => '🔥',
            'other'                   => '📌',
        ];
        return $icons[$category] ?? '📌';
    }
}
if (!function_exists('getCategoryLabel')) {
    function getCategoryLabel($category) {
        $labels = [
            'poaching'                => 'Poaching',
            'distressed_animal'       => 'Distressed Animal',
            'human_wildlife_conflict' => 'Human-Wildlife Conflict',
            'environmental_risk'      => 'Environmental Risk',
            'other'                   => 'Other',
        ];
        return $labels[$category] ?? $category;
    }
}
if (!function_exists('getIncident')) {
    function getIncident($incidentId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT i.*, u.full_name as reporter_name, u.phone as reporter_phone,
                       z.name as zone_name, u.role as reporter_role
                FROM incidents i
                LEFT JOIN users u ON i.reporter_id = u.id
                LEFT JOIN zones z ON i.zone_id = z.id
                WHERE i.id = ?
            ");
            $stmt->execute([$incidentId]);
            $incident = $stmt->fetch();
            if ($incident && $incident['media_urls']) {
                $incident['media_urls'] = json_decode($incident['media_urls'], true);
            }
            return $incident;
        } catch (PDOException $e) { return null; }
    }
}
if (!function_exists('getIncidentsByZone')) {
    function getIncidentsByZone($zoneId, $limit = 50, $status = null) {
        try {
            $pdo = getDB();
            $sql = "
                SELECT i.*, u.full_name as reporter_name
                FROM incidents i JOIN users u ON i.reporter_id = u.id
                WHERE i.zone_id = ?
            ";
            $params = [$zoneId];
            if ($status) { $sql .= " AND i.status = ?"; $params[] = $status; }
            $sql .= " ORDER BY i.reported_at DESC LIMIT ?";
            $params[] = $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $incidents = $stmt->fetchAll();
            foreach ($incidents as &$incident) {
                if ($incident['media_urls']) $incident['media_urls'] = json_decode($incident['media_urls'], true);
            }
            return $incidents;
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('countIncidentsByStatus')) {
    function countIncidentsByStatus($zoneId = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT status, COUNT(*) as count FROM incidents";
            $params = [];
            if ($zoneId) { $sql .= " WHERE zone_id = ?"; $params[] = $zoneId; }
            $sql .= " GROUP BY status";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = [];
            while ($row = $stmt->fetch()) $result[$row['status']] = $row['count'];
            return $result;
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// TIME HELPERS
// ============================================================
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        if (empty($datetime)) return 'Never';
        $time = strtotime($datetime);
        if (!$time) return 'Never';
        $now  = time();
        $diff = $now - $time;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff/60) . 'm ago';
        if ($diff < 86400) return floor($diff/3600) . 'h ago';
        if ($diff < 604800) return floor($diff/86400) . 'd ago';
        if ($diff < 2592000) return floor($diff/604800) . 'w ago';
        if ($diff < 31536000) return floor($diff/2592000) . 'mo ago';
        return date('M j, Y', $time);
    }
}
if (!function_exists('formatDate')) { function formatDate($d) { return date('M j, Y H:i', strtotime($d)); } }
if (!function_exists('formatTime')) { function formatTime($d) { return date('H:i', strtotime($d)); } }

// ============================================================
// AUDIT
// ============================================================
if (!function_exists('logAudit')) {
    function logAudit($userId, $action, $details = null) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $detailsJson = $details ? json_encode($details) : null;
            $userId = ($userId > 0) ? $userId : null;
            $stmt->execute([$userId, $action, $detailsJson, $ip, $ua]);
            return $pdo->query('SELECT lastval()')->fetchColumn();
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('getAuditLogs')) {
    function getAuditLogs($limit = 50, $userId = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT a.*, u.full_name as user_name FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id WHERE 1=1";
            $params = [];
            if ($userId) { $sql .= " AND a.user_id = ?"; $params[] = $userId; }
            $sql .= " ORDER BY a.created_at DESC LIMIT ?";
            $params[] = $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// MESSAGING
// ============================================================
if (!function_exists('sendMessage')) {
    function sendMessage($senderId, $recipientId, $content, $incidentId = null, $type = 'general', $isBroadcast = false) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, incident_id, message_type, content, is_broadcast, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$senderId, $recipientId, $incidentId, $type, $content, $isBroadcast ? 1 : 0]);
            return $pdo->query('SELECT lastval()')->fetchColumn();
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('getMessages')) {
    function getMessages($userId, $limit = 50) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT m.*, u1.full_name as sender_name, u1.role as sender_role,
                       u2.full_name as recipient_name, u2.role as recipient_role,
                       i.category as incident_category, i.status as incident_status,
                       i.location_lat, i.location_lng
                FROM messages m
                LEFT JOIN users u1 ON m.sender_id = u1.id
                LEFT JOIN users u2 ON m.recipient_id = u2.id
                LEFT JOIN incidents i ON m.incident_id = i.id
                WHERE m.recipient_id = ? OR m.sender_id = ?
                   OR (m.is_broadcast = 1 AND m.sender_id != ?)
                ORDER BY m.created_at DESC LIMIT ?
            ");
            $stmt->execute([$userId, $userId, $userId, $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getUnreadMessageCount')) {
    function getUnreadMessageCount($userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM messages WHERE (recipient_id = ? OR is_broadcast = 1) AND is_read = 0");
            $stmt->execute([$userId]);
            return (int)($stmt->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}
if (!function_exists('markMessageRead')) {
    function markMessageRead($messageId, $userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW() WHERE id = ? AND (recipient_id = ? OR sender_id = ?)");
            return $stmt->execute([$messageId, $userId, $userId]);
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('markAllMessagesRead')) {
    function markAllMessagesRead($userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW() WHERE (recipient_id = ? OR is_broadcast = 1) AND is_read = 0");
            return $stmt->execute([$userId]);
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('sendUserMessage')) {
    function sendUserMessage($senderId, $recipientId, $content, $incidentId = null, $type = 'general', $severity = 'medium') {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, incident_id, message_type, severity, content, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$senderId, $recipientId, $incidentId, $type, $severity, $content]);
            $messageId = $pdo->query('SELECT lastval()')->fetchColumn();
            if ($recipientId) {
                createNotification($recipientId, 'new_message', '📩 New Message', substr($content, 0, 100), $incidentId, $messageId);
            }
            return $messageId;
        } catch (PDOException $e) { return false; }
    }
}

// ============================================================
// RANGER HELPERS
// ============================================================
if (!function_exists('getRangerAvailability')) {
    function getRangerAvailability($rangerId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT is_available, current_incident_id, last_status_update, shift_start, shift_end, days_available FROM ranger_availability WHERE ranger_id = ?");
            $stmt->execute([$rangerId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return null; }
    }
}
if (!function_exists('updateRangerAvailability')) {
    function updateRangerAvailability($rangerId, $isAvailable, $incidentId = null) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                INSERT INTO ranger_availability (ranger_id, is_available, current_incident_id, last_status_update)
                VALUES (?, ?, ?, NOW())
                 ON CONFLICT (ranger_id) DO UPDATE SET 
                    is_available = EXCLUDED.is_available,
                    current_incident_id = EXCLUDED.current_incident_id,
                    last_status_update = NOW()
            ');
            return $stmt->execute([$rangerId, $isAvailable, $incidentId]);
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('getActiveRangers')) {
    function getActiveRangers($zoneId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.email, u.phone, ra.is_available,
                       rlt.current_lat, rlt.current_lng, rlt.last_update
                FROM users u
                JOIN ranger_availability ra ON u.id = ra.ranger_id
                LEFT JOIN ranger_live_tracking rlt ON u.id = rlt.ranger_id
                WHERE u.zone_id = ? AND u.role = 'ranger' AND u.is_active = 1 AND ra.is_available = 1
            ");
            $stmt->execute([$zoneId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// ZONE HELPERS
// ============================================================
if (!function_exists('getAllZones')) {
    function getAllZones($activeOnly = true) {
        try {
            $pdo = getDB();
            $sql = "SELECT * FROM zones";
            if ($activeOnly) $sql .= " WHERE is_active = 1";
            $sql .= " ORDER BY name";
            return $pdo->query($sql)->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getZoneStatistics')) {
    function getZoneStatistics($zoneId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                SELECT
                    (SELECT COUNT(*) FROM incidents WHERE zone_id = ? AND status NOT IN (\'resolved\',\'closed\')) as active_incidents,
                    (SELECT COUNT(*) FROM incidents WHERE zone_id = ? AND status = \'reported\') as unacknowledged,
                    (SELECT COUNT(*) FROM incidents WHERE zone_id = ? AND DATE(reported_at) = CURRENT_DATE) as today_reports,
                    (SELECT COUNT(*) FROM ranger_availability ra JOIN users u ON ra.ranger_id = u.id WHERE u.zone_id = ? AND ra.is_available = 1) as available_rangers,
                    (SELECT COUNT(*) FROM users WHERE zone_id = ? AND role = \'ranger\' AND is_active = 1) as total_rangers,
                    (SELECT COUNT(*) FROM incidents WHERE zone_id = ? AND media_urls IS NOT NULL) as incidents_with_photos
            ');
            $stmt->execute([$zoneId, $zoneId, $zoneId, $zoneId, $zoneId, $zoneId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getRegisteredParksCount')) {
    function getRegisteredParksCount() {
        try {
            $pdo = getDB();
            return (int)($pdo->query("SELECT COUNT(*) as count FROM zones WHERE park_type IN ('national_park','gma') AND is_registered = 1")->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}
if (!function_exists('getAvailableParksCount')) {
    function getAvailableParksCount() {
        try {
            $pdo = getDB();
            return (int)($pdo->query("SELECT COUNT(*) as count FROM zones WHERE park_type IN ('national_park','gma') AND is_registered = 0")->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}
if (!function_exists('getZambianParks')) {
    function getZambianParks($registeredOnly = false) {
        try {
            $pdo = getDB();
            $sql = "SELECT * FROM zones WHERE park_type IN ('national_park','gma')";
            if ($registeredOnly) $sql .= " AND is_registered = 1";
            $sql .= " ORDER BY park_type, name";
            return $pdo->query($sql)->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// SYSTEM STATISTICS
// ============================================================
if (!function_exists('getSystemStatistics')) {
    function getSystemStatistics() {
        $stats = [
            'total_users'=>0,'total_rangers'=>0,'total_incidents'=>0,
            'active_incidents'=>0,'unacknowledged'=>0,'total_zones'=>0,
            'total_messages'=>0,'unread_notifications'=>0,
            'incidents_with_photos'=>0,'registered_parks'=>0,
        ];
        try {
            $pdo = getDB();
            $stats['total_users']           = (int)$pdo->query("SELECT COUNT(*) as c FROM users")->fetch()['c'];
            $stats['total_rangers']         = (int)$pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'ranger'")->fetch()['c'];
            $stats['total_incidents']       = (int)$pdo->query("SELECT COUNT(*) as c FROM incidents")->fetch()['c'];
            $stats['active_incidents']      = (int)$pdo->query("SELECT COUNT(*) as c FROM incidents WHERE status NOT IN ('resolved','closed')")->fetch()['c'];
            $stats['unacknowledged']        = (int)$pdo->query("SELECT COUNT(*) as c FROM incidents WHERE status = 'reported'")->fetch()['c'];
            $stats['total_zones']           = (int)$pdo->query("SELECT COUNT(*) as c FROM zones WHERE is_active = 1")->fetch()['c'];
            $stats['total_messages']        = (int)$pdo->query("SELECT COUNT(*) as c FROM messages")->fetch()['c'];
            $stats['unread_notifications']  = (int)$pdo->query("SELECT COUNT(*) as c FROM notifications WHERE is_read = 0")->fetch()['c'];
            $stats['incidents_with_photos'] = (int)$pdo->query("SELECT COUNT(*) as c FROM incidents WHERE media_urls IS NOT NULL")->fetch()['c'];
            $stats['registered_parks']      = (int)$pdo->query("SELECT COUNT(*) as c FROM zones WHERE park_type IN ('national_park','gma') AND is_registered = 1")->fetch()['c'];
        } catch (PDOException $e) {
            error_log('[WS] getSystemStatistics: ' . $e->getMessage());
        }
        return $stats;
    }
}

// ============================================================
// FILE UPLOADS
// ============================================================
if (!function_exists('uploadFile')) {
    function uploadFile($file, $targetDir = 'uploads/', $allowedTypes = ['jpg','jpeg','png','gif','webp']) {
        if ($file['error'] !== UPLOAD_ERR_OK) return ['success'=>false,'error'=>'Upload failed: '.$file['error']];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedTypes)) return ['success'=>false,'error'=>'Invalid file type.'];
        if ($file['size'] > 5 * 1024 * 1024) return ['success'=>false,'error'=>'File too large (max 5MB).'];
        if (!file_exists($targetDir)) mkdir($targetDir, 0755, true);
        $newName    = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $targetPath = $targetDir . $newName;
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success'=>true,'filename'=>$newName,'path'=>$targetPath];
        }
        return ['success'=>false,'error'=>'Failed to save file.'];
    }
}
if (!function_exists('uploadPhotos')) {
    function uploadPhotos($files, $maxFiles = 5) {
        $uploaded = []; $errors = [];
        if (empty($files['tmp_name'][0])) return ['success'=>true,'files'=>[],'errors'=>[]];
        if (count(array_filter($files['tmp_name'])) > $maxFiles) return ['success'=>false,'error'=>"Max {$maxFiles} photos."];
        $uploadDir = 'uploads/incidents/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/heic','image/heif'];
        foreach ($files['tmp_name'] as $k => $tmp) {
            if (empty($tmp)) continue;
            if (!in_array($files['type'][$k], $allowed)) { $errors[] = "Invalid type: {$files['name'][$k]}"; continue; }
            if ($files['size'][$k] > 5 * 1024 * 1024) { $errors[] = "Too large: {$files['name'][$k]}"; continue; }
            $ext = strtolower(pathinfo($files['name'][$k], PATHINFO_EXTENSION));
            $new = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmp, $uploadDir . $new)) $uploaded[] = $uploadDir . $new;
            else $errors[] = "Failed: {$files['name'][$k]}";
        }
        return ['success'=>empty($errors),'files'=>$uploaded,'errors'=>$errors];
    }
}

// ============================================================
// AI & CCTV HELPERS
// ============================================================
if (!function_exists('getActiveCameras')) {
    function getActiveCameras($zoneId = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT c.*, z.name as zone_name FROM cctv_cameras c JOIN zones z ON c.zone_id = z.id WHERE c.is_active = 1";
            $params = [];
            if ($zoneId) { $sql .= " AND c.zone_id = ?"; $params[] = $zoneId; }
            $sql .= " ORDER BY c.camera_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getCamera')) {
    function getCamera($cameraId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT c.*, z.name as zone_name FROM cctv_cameras c JOIN zones z ON c.zone_id = z.id WHERE c.id = ?");
            $stmt->execute([$cameraId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return null; }
    }
}
if (!function_exists('getRecentAIDetections')) {
    function getRecentAIDetections($zoneId = null, $limit = 50, $threatsOnly = false) {
        try {
            $pdo = getDB();
            $table = 'ai_detections_v2';
            try {
                $pdo->query("SELECT 1 FROM ai_detections_v2 LIMIT 1");
            } catch (PDOException $e) {
                $table = 'ai_detections';
            }
            $sql = "SELECT d.*, c.camera_name, z.name as zone_name
                    FROM {$table} d
                    LEFT JOIN cctv_cameras c ON d.camera_id = c.id
                    LEFT JOIN zones z ON d.zone_id = z.id
                    WHERE 1=1";
            $params = [];
            if ($zoneId) { $sql .= " AND d.zone_id = ?"; $params[] = $zoneId; }
            if ($threatsOnly) $sql .= " AND d.is_threat = 1";
            $sql .= " ORDER BY d.detected_at DESC LIMIT ?";
            $params[] = $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getAIDetectionStats')) {
    function getAIDetectionStats($zoneId = null) {
        $default = ['total_detections'=>0,'threats'=>0,'humans'=>0,'animals'=>0,'vehicles'=>0,'today'=>0];
        try {
            $pdo = getDB();
            $table = 'ai_detections_v2';
            try { $pdo->query("SELECT 1 FROM ai_detections_v2 LIMIT 1"); }
            catch (PDOException $e) { $table = 'ai_detections'; }

            $sql = "
                SELECT COUNT(*) as total_detections,
                       SUM(CASE WHEN is_threat = 1 THEN 1 ELSE 0 END) as threats,
                       SUM(CASE WHEN detection_type = 'human' THEN 1 ELSE 0 END) as humans,
                       SUM(CASE WHEN detection_type = 'animal' THEN 1 ELSE 0 END) as animals,
                       SUM(CASE WHEN detection_type = 'vehicle' THEN 1 ELSE 0 END) as vehicles,
                       SUM(CASE WHEN DATE(detected_at) = CURRENT_DATE THEN 1 ELSE 0 END) as today
                FROM {$table} WHERE 1=1
            ";
            $params = [];
            if ($zoneId) { $sql .= " AND zone_id = ?"; $params[] = $zoneId; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch() ?: $default;
        } catch (PDOException $e) { return $default; }
    }
}
if (!function_exists('createAIAlert')) {
    function createAIAlert($detectionId, $zoneId, $alertType, $severity, $title, $description, $lat, $lng) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO ai_alerts (detection_id, zone_id, alert_type, severity, title, description, location_lat, location_lng, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$detectionId, $zoneId, $alertType, $severity, $title, $description, $lat, $lng]);
            return $pdo->query('SELECT lastval()')->fetchColumn();
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('getUnacknowledgedAIAlerts')) {
    function getUnacknowledgedAIAlerts($zoneId = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT a.*, z.name as zone_name FROM ai_alerts a JOIN zones z ON a.zone_id = z.id WHERE a.is_acknowledged = 0";
            $params = [];
            if ($zoneId) { $sql .= " AND a.zone_id = ?"; $params[] = $zoneId; }
            $sql .= " ORDER BY a.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('acknowledgeAIAlert')) {
    function acknowledgeAIAlert($alertId, $userId) {
        try {
            $pdo = getDB();
            $pdo->prepare("UPDATE ai_alerts SET is_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ?")
                ->execute([$userId, $alertId]);
            $pdo->prepare('
                UPDATE alarm_triggers SET stopped_at = NOW(),
                    duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1)
                WHERE alert_id = ? AND stopped_at IS NULL
            ')->execute([$alertId]);
            return true;
        } catch (PDOException $e) { return false; }
    }
}

// ============================================================
// ALARM HELPERS
// ============================================================
if (!function_exists('getZoneAlarms')) {
    function getZoneAlarms($zoneId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM alarm_systems WHERE zone_id = ? AND is_active = 1 ORDER BY alarm_name");
            $stmt->execute([$zoneId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('triggerZoneAlarms')) {
    function triggerZoneAlarms($zoneId, $alertId, $reason) {
        try {
            $pdo = getDB();
            $alarms = getZoneAlarms($zoneId);
            $count = 0;
            foreach ($alarms as $alarm) {
                $pdo->prepare("
                    INSERT INTO alarm_triggers (alarm_id, alert_id, zone_id, triggered_by, trigger_reason, triggered_at)
                    VALUES (?, ?, ?, 'ai_detection', ?, NOW())
                ")->execute([$alarm['id'], $alertId, $zoneId, $reason]);
                $pdo->prepare("UPDATE alarm_systems SET last_triggered = NOW(), trigger_count = trigger_count + 1 WHERE id = ?")->execute([$alarm['id']]);
                $count++;
            }
            return $count;
        } catch (PDOException $e) { return 0; }
    }
}
if (!function_exists('getActiveAlarms')) {
    function getActiveAlarms($zoneId = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT at.*, a.alarm_name, z.name as zone_name
                    FROM alarm_triggers at
                    JOIN alarm_systems a ON at.alarm_id = a.id
                    JOIN zones z ON at.zone_id = z.id
                    WHERE at.stopped_at IS NULL";
            $params = [];
            if ($zoneId) { $sql .= " AND at.zone_id = ?"; $params[] = $zoneId; }
            $sql .= " ORDER BY at.triggered_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('stopAlarm')) {
    function stopAlarm($alarmTriggerId, $userId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                UPDATE alarm_triggers SET stopped_at = NOW(),
                    duration_seconds = TRUNC(EXTRACT(EPOCH FROM ((NOW()) - (triggered_at))) / 1),
                    was_acknowledged = 1, acknowledged_by = ?, acknowledged_at = NOW()
                WHERE id = ?
            ');
            return $stmt->execute([$userId, $alarmTriggerId]);
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('ws_alarm_trigger_hardware')) {
    function ws_alarm_trigger_hardware(int $alarmId, int $triggerId): bool {
        error_log("[WS-ALARM] trigger alarm_id={$alarmId} trigger_id={$triggerId}");
        return true;
    }
}
if (!function_exists('ws_alarm_stop_hardware')) {
    function ws_alarm_stop_hardware(int $alarmId, int $triggerId): bool {
        error_log("[WS-ALARM] stop alarm_id={$alarmId} trigger_id={$triggerId}");
        return true;
    }
}

// ============================================================
// SMS HELPERS — UNIFIED (v3.2)
// ============================================================
if (!function_exists('ws_load_sms_provider')) {
    function ws_load_sms_provider() {
        static $loaded = false, $has = false;
        if ($loaded) return $has;
        foreach ([__DIR__.'/sms.php', __DIR__.'/../config/sms.php'] as $file) {
            if (is_file($file)) { require_once $file; $has = true; break; }
        }
        $loaded = true;
        return $has;
    }
}
if (!function_exists('ws_sms_is_enabled')) {
    function ws_sms_is_enabled(): bool {
        if (function_exists('getSetting')) {
            return (string)getSetting('sms_enabled', '1') === '1';
        }
        return true;
    }
}
if (!function_exists('normalizeZambianPhone')) {
    function normalizeZambianPhone(?string $raw): ?string {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if (strpos($digits, '260') === 0) $digits = substr($digits, 3);
        if (!preg_match('/^(09|07)[0-9]{8}$/', $digits)) return null;
        return $digits;
    }
}
if (!function_exists('networkNameFromPhone')) {
    function networkNameFromPhone(string $p): string {
        $x = substr($p, 0, 3);
        if (in_array($x, ['096','076'], true)) return 'MTN Zambia';
        if (in_array($x, ['097','077'], true)) return 'Airtel Zambia';
        if (in_array($x, ['095','075'], true)) return 'Zamtel';
        return 'Unknown';
    }
}
if (!function_exists('estimateSegments')) {
    function estimateSegments(string $m): int {
        $l = mb_strlen($m);
        return $l <= 160 ? 1 : (int)ceil($l / 153);
    }
}
if (!function_exists('routeProviderByPhone')) {
    function routeProviderByPhone(string $p): string {
        $x = substr($p, 0, 3);
        if (in_array($x, ['096','076'], true)) return 'mtn';
        if (in_array($x, ['097','077'], true)) return 'airtel';
        return 'esms';
    }
}
if (!function_exists('sendSMS')) {
    function sendSMS(
        string $to,
        string $message,
        string $type = 'general',
        ?int $userId = null,
        ?int $incidentId = null,
        ?int $alertId = null
    ): array {
        $segments = estimateSegments($message);

        if (!ws_sms_is_enabled()) {
            return ['success'=>false,'ref'=>null,'error'=>'SMS is globally disabled','provider'=>'disabled','segments'=>$segments];
        }
        $norm = normalizeZambianPhone($to);
        if (!$norm) {
            return ['success'=>false,'ref'=>null,'error'=>'Invalid phone number','provider'=>'invalid','segments'=>$segments];
        }

        ws_load_sms_provider();
        if (function_exists('ws_send_sms_real')) {
            try {
                $res = ws_send_sms_real($norm, $message, $type, $userId, $incidentId, $alertId);
                if (is_array($res)) {
                    if (!isset($res['segments'])) $res['segments'] = $segments;
                    if (!isset($res['success']))  $res['success']  = false;
                    if (!isset($res['provider'])) $res['provider'] = 'unknown';
                    return $res;
                }
            } catch (Throwable $e) {
                error_log('[WS-SMS] provider exception: ' . $e->getMessage());
            }
        }
        return ['success'=>false,'ref'=>null,'error'=>'SMS provider not configured (queued for later)','provider'=>'queued','segments'=>$segments];
    }
}
if (!function_exists('ws_log_sms')) {
    function ws_log_sms(
        int $userId, string $phone, string $message, string $type,
        array $res, ?int $incidentId = null, ?int $alertId = null
    ): void {
        try {
            $pdo = getDB();
            $status = !empty($res['success']) ? 'sent'
                    : (($res['provider'] ?? '') === 'queued' ? 'pending' : 'failed');
            $sentAtSql = $status === 'sent' ? 'NOW()' : 'NULL';
            $stmt = $pdo->prepare("
                INSERT INTO sms_logs
                    (user_id, phone, message, message_type, incident_id, alert_id, status, gateway_response, sent_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, {$sentAtSql}, NOW())
            ");
            $stmt->execute([$userId > 0 ? $userId : null, $phone, $message, $type, $incidentId, $alertId, $status, $res['error'] ?? null]);
        } catch (Throwable $e) {
            error_log('[WS-SMS] ws_log_sms: ' . $e->getMessage());
        }
    }
}
if (!function_exists('silentSMSDispatchToZone')) {
    function silentSMSDispatchToZone(
        int $zoneId, int $incidentId, string $severity,
        string $category, string $reporterName, string $zoneName
    ): void {
        try {
            if (!ws_sms_is_enabled()) return;
            if ($zoneId <= 0) return;
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT id, full_name, role, phone FROM users
                WHERE zone_id = ? AND role IN ('ranger','zone_supervisor')
                  AND is_active = 1 AND phone IS NOT NULL AND phone <> ''
            ");
            $stmt->execute([$zoneId]);
            $recipients = $stmt->fetchAll() ?: [];
            if (empty($recipients)) return;

            $sevUpper = strtoupper($severity);
            $catLabel = ucwords(str_replace('_', ' ', $category));

            foreach ($recipients as $r) {
                $phone = normalizeZambianPhone($r['phone'] ?? null);
                if (!$phone) continue;
                $msg = $r['role'] === 'zone_supervisor'
                    ? "WS OVERSEER: {$sevUpper} {$catLabel} reported in {$zoneName} by {$reporterName}. Incident #{$incidentId}. Check the dashboard."
                    : "WS ALERT: {$sevUpper} {$catLabel} reported in {$zoneName} by {$reporterName}. Incident #{$incidentId}. Open the app NOW.";
                $res = sendSMS($phone, $msg, 'incident', (int)$r['id'], $incidentId);
                ws_log_sms((int)$r['id'], $phone, $msg, 'incident', $res, $incidentId);
            }
        } catch (Throwable $e) {
            error_log('[WS-SMS] silentSMSDispatchToZone: ' . $e->getMessage());
        }
    }
}
if (!function_exists('sendIncidentSMS')) {
    function sendIncidentSMS(int $incidentId, int $zoneId): array {
        $result = ['sent'=>0,'failed'=>0,'recipients'=>0];
        if (!ws_sms_is_enabled() || $zoneId <= 0 || $incidentId <= 0) return $result;
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT id, full_name, role, phone FROM users
                WHERE zone_id = ? AND role IN ('ranger','zone_supervisor') AND is_active = 1
                  AND phone IS NOT NULL AND phone <> ''
            ");
            $stmt->execute([$zoneId]);
            $recipients = $stmt->fetchAll() ?: [];
            $result['recipients'] = count($recipients);
            foreach ($recipients as $r) {
                $phone = normalizeZambianPhone($r['phone']);
                if (!$phone) { $result['failed']++; continue; }
                $msg = "Incident #{$incidentId} - please check the dashboard.";
                $res = sendSMS($phone, $msg, 'incident', (int)$r['id'], $incidentId);
                ws_log_sms((int)$r['id'], $phone, $msg, 'incident', $res, $incidentId);
                if (!empty($res['success'])) $result['sent']++; else $result['failed']++;
            }
        } catch (Throwable $e) { error_log('[WS-SMS] sendIncidentSMS: ' . $e->getMessage()); }
        return $result;
    }
}
if (!function_exists('sendAIAlertSMS')) {
    function sendAIAlertSMS(int $alertId, int $zoneId): array {
        $result = ['sent'=>0,'failed'=>0,'recipients'=>0];
        if (!ws_sms_is_enabled() || $zoneId <= 0 || $alertId <= 0) return $result;
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT title FROM ai_alerts WHERE id = ? LIMIT 1");
            $stmt->execute([$alertId]);
            $title = $stmt->fetch()['title'] ?? "AI Alert #{$alertId}";

            $stmt = $pdo->prepare("
                SELECT id, phone FROM users
                WHERE zone_id = ? AND role IN ('ranger','zone_supervisor') AND is_active = 1
                  AND phone IS NOT NULL AND phone <> ''
            ");
            $stmt->execute([$zoneId]);
            $recipients = $stmt->fetchAll() ?: [];
            $result['recipients'] = count($recipients);
            foreach ($recipients as $r) {
                $phone = normalizeZambianPhone($r['phone']);
                if (!$phone) { $result['failed']++; continue; }
                $msg = "AI ALERT: {$title}";
                $res = sendSMS($phone, $msg, 'ai_alert', (int)$r['id'], null, $alertId);
                ws_log_sms((int)$r['id'], $phone, $msg, 'ai_alert', $res, null, $alertId);
                if (!empty($res['success'])) $result['sent']++; else $result['failed']++;
            }
        } catch (Throwable $e) { error_log('[WS-SMS] sendAIAlertSMS: ' . $e->getMessage()); }
        return $result;
    }
}
if (!function_exists('getSMSLogs')) {
    function getSMSLogs($limit = 50, $status = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT s.*, u.full_name as user_name FROM sms_logs s LEFT JOIN users u ON s.user_id = u.id WHERE 1=1";
            $params = [];
            if ($status) { $sql .= " AND s.status = ?"; $params[] = $status; }
            $sql .= " ORDER BY s.created_at DESC LIMIT ?";
            $params[] = $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// SIMULATION HELPERS
// ============================================================
if (!function_exists('getSimulationControls')) {
    function getSimulationControls($zoneId = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT * FROM simulation_controls WHERE 1=1";
            $params = [];
            if ($zoneId) { $sql .= " AND zone_id = ?"; $params[] = $zoneId; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('toggleSimulation')) {
    function toggleSimulation($simulationId, $enabled) {
        try {
            $pdo = getDB();
            if ($enabled) {
                $stmt = $pdo->prepare("UPDATE simulation_controls SET is_enabled = 1, started_at = NOW() WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE simulation_controls SET is_enabled = 0, stopped_at = NOW() WHERE id = ?");
            }
            return $stmt->execute([$simulationId]);
        } catch (PDOException $e) { return false; }
    }
}

// ============================================================
// FORMATTING
// ============================================================
if (!function_exists('formatPhone')) {
    function formatPhone($phone) {
        if (empty($phone)) return 'N/A';
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 10) {
            return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6, 4);
        }
        return $phone;
    }
}
if (!function_exists('truncateText')) {
    function truncateText($text, $length = 100, $suffix = '...') {
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . $suffix;
    }
}

// ============================================================
// ZAMBIAN HERITAGE
// ============================================================
if (!function_exists('getZambianHeritageSites')) {
    function getZambianHeritageSites() {
        return [
            ['icon'=>'🌊','name'=>'Victoria Falls','location'=>'Livingstone, Zambia','description'=>'One of the Seven Natural Wonders of the World','tag'=>'UNESCO World Heritage Site'],
            ['icon'=>'🏞️','name'=>'South Luangwa National Park','location'=>'Eastern Province, Zambia','description'=>'One of Africa\'s greatest wildlife sanctuaries','tag'=>'Premier Wildlife Destination'],
            ['icon'=>'🦁','name'=>'Kafue National Park','location'=>'Central Zambia','description'=>'Zambia\'s largest national park','tag'=>'Largest Park in Zambia'],
            ['icon'=>'🌿','name'=>'Lower Zambezi National Park','location'=>'Zambezi Valley, Zambia','description'=>'Pristine wilderness along the Zambezi River','tag'=>'Zambezi Valley Wilderness'],
            ['icon'=>'🦏','name'=>'North Luangwa National Park','location'=>'Northern Province, Zambia','description'=>'Remote wilderness known for rhino conservation','tag'=>'Rhino Conservation Area'],
            ['icon'=>'🦒','name'=>'Liuwa Plain National Park','location'=>'Western Province, Zambia','description'=>'Home to the famous Liuwa wildebeest migration','tag'=>'Wildebeest Migration'],
            ['icon'=>'🐘','name'=>'Mosi-oa-Tunya National Park','location'=>'Livingstone, Zambia','description'=>'Home to white rhino and Victoria Falls views','tag'=>'White Rhino Sanctuary'],
            ['icon'=>'🏝️','name'=>'Bangweulu Wetlands','location'=>'Northern Zambia','description'=>'Wetland of international importance','tag'=>'Wetland of International Importance'],
            ['icon'=>'🌅','name'=>'Lake Tanganyika','location'=>'Northern Zambia','description'=>'The second oldest and deepest lake in the world','tag'=>'Ancient Lake'],
            ['icon'=>'🦅','name'=>'Zambezi River','location'=>'Across Zambia','description'=>'The lifeblood of Zambia','tag'=>'Africa\'s Fourth Longest River'],
            ['icon'=>'⛰️','name'=>'Muchinga Escarpment','location'=>'Northern Zambia','description'=>'Dramatic mountain range with breathtaking views','tag'=>'Scenic Mountain Range'],
            ['icon'=>'🦇','name'=>'Kasanka National Park','location'=>'Central Province, Zambia','description'=>'Famous for the annual bat migration','tag'=>'Bat Migration Phenomenon'],
        ];
    }
}

// ============================================================
// RESPONSE HELPERS
// ============================================================
if (!function_exists('jsonResponse')) {
    function jsonResponse($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
if (!function_exists('successResponse')) {
    function successResponse($data = null, $message = 'Success') {
        return jsonResponse(['success'=>true,'message'=>$message,'data'=>$data]);
    }
}
if (!function_exists('errorResponse')) {
    function errorResponse($message, $code = 400) {
        return jsonResponse(['success'=>false,'error'=>$message], $code);
    }
}

// ============================================================
// VALIDATION
// ============================================================
if (!function_exists('validateCoordinates')) {
    function validateCoordinates($lat, $lng) {
        return is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }
}
if (!function_exists('validatePasswordStrength')) {
    function validatePasswordStrength($password) {
        $errors = [];
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
        if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain at least one uppercase letter';
        if (!preg_match('/[a-z]/', $password)) $errors[] = 'Password must contain at least one lowercase letter';
        if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain at least one number';
        return $errors;
    }
}
if (!function_exists('validateZambianPhone')) {
    function validateZambianPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        return (bool)preg_match('/^(09|07)[0-9]{8}$/', $phone);
    }
}

// ============================================================
// FLASH
// ============================================================
if (!function_exists('setFlash')) { function setFlash($key, $value) { $_SESSION['flash'][$key] = $value; } }
if (!function_exists('getFlash')) {
    function getFlash($key) {
        if (isset($_SESSION['flash'][$key])) { $v = $_SESSION['flash'][$key]; unset($_SESSION['flash'][$key]); return $v; }
        return null;
    }
}
if (!function_exists('hasFlash')) { function hasFlash($key) { return isset($_SESSION['flash'][$key]); } }

// ============================================================
// CSRF
// ============================================================
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}
if (!function_exists('csrfField')) {
    function csrfField(): string {
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars(generateCSRFToken(), ENT_QUOTES) . '">';
    }
}

// ============================================================
// RATE LIMITING
// ============================================================
if (!function_exists('checkRateLimit')) {
    function checkRateLimit($key, $maxAttempts = 5, $timeWindow = 300) {
        try {
            $pdo = getDB();
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    key_name VARCHAR(100) NOT NULL,
                    attempt_count INTEGER DEFAULT 1,
                    first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ');
            $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $stmt = $pdo->prepare("SELECT attempt_count, first_attempt FROM rate_limits WHERE ip_address = ? AND key_name = ?");
            $stmt->execute([$ip, $key]);
            $r = $stmt->fetch();
            if ($r) {
                $timeDiff = time() - strtotime($r['first_attempt']);
                if ($timeDiff > $timeWindow) {
                    $pdo->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND key_name = ?")->execute([$ip, $key]);
                    return true;
                }
                if ($r['attempt_count'] >= $maxAttempts) return false;
                $pdo->prepare("UPDATE rate_limits SET attempt_count = attempt_count + 1 WHERE ip_address = ? AND key_name = ?")->execute([$ip, $key]);
                return true;
            }
            $pdo->prepare("INSERT INTO rate_limits (ip_address, key_name, attempt_count) VALUES (?, ?, 1)")->execute([$ip, $key]);
            return true;
        } catch (PDOException $e) { return true; }
    }
}

// ============================================================
// LOCATION HELPERS
// ============================================================
if (!function_exists('getDistance')) {
    function getDistance($lat1, $lng1, $lat2, $lng2) {
        $R    = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
        $c    = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }
}
if (!function_exists('getNearbyIncidents')) {
    function getNearbyIncidents($lat, $lng, $radius = 10, $limit = 50) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                SELECT * FROM (SELECT *,
                (6371 * acos(cos(radians(?)) * cos(radians(location_lat)) *
                cos(radians(location_lng) - radians(?)) + sin(radians(?)) *
                sin(radians(location_lat)))) AS distance
                FROM incidents
                WHERE status != \'resolved\' AND status != \'closed\') AS nearby WHERE distance < ? ORDER BY distance ASC,
                CASE severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END
                LIMIT ?
            ');
            $stmt->execute([$lat, $lng, $lat, $radius, $limit]);
            $incidents = $stmt->fetchAll();
            foreach ($incidents as &$i) {
                if ($i['media_urls']) $i['media_urls'] = json_decode($i['media_urls'], true);
            }
            return $incidents;
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// PARK MANAGEMENT
// ============================================================
if (!function_exists('registerPark')) {
    function registerPark($zoneId, $supervisorData) {
        try {
            $pdo = getDB();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE zones SET is_registered = 1, is_active = 1
                WHERE id = ? AND park_type IN ('national_park','gma') AND is_registered = 0
            ");
            $stmt->execute([$zoneId]);
            if ($stmt->rowCount() === 0) throw new Exception('Zone not found or already registered');
            $hash = hashPassword($supervisorData['password']);
            $stmt = $pdo->prepare("
                INSERT INTO users (email, phone, password_hash, full_name, role, zone_id, created_by, is_active)
                VALUES (?, ?, ?, ?, 'zone_supervisor', ?, ?, 1)
            ");
            $stmt->execute([
                $supervisorData['email'],
                $supervisorData['phone'] ?? null,
                $hash,
                $supervisorData['full_name'],
                $zoneId,
                $supervisorData['created_by'],
            ]);
            $supervisorId = $pdo->query('SELECT lastval()')->fetchColumn();
            $pdo->commit();
            return ['success'=>true,'supervisor_id'=>$supervisorId];
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            return ['success'=>false,'error'=>$e->getMessage()];
        }
    }
}
if (!function_exists('getParkRegistrationStatus')) {
    function getParkRegistrationStatus($zoneId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT is_registered, park_type, park_code, buffer_radius FROM zones WHERE id = ? AND park_type IN ('national_park','gma')");
            $stmt->execute([$zoneId]);
            return $stmt->fetch();
        } catch (PDOException $e) { return null; }
    }
}

// ============================================================
// ZONE NOTIFICATION SETTINGS
// ============================================================
if (!function_exists('getZoneNotificationSettings')) {
    function getZoneNotificationSettings($zoneId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM zone_notification_settings WHERE zone_id = ?");
            $stmt->execute([$zoneId]);
            $s = $stmt->fetch();
            if (!$s) {
                $pdo->prepare("INSERT INTO zone_notification_settings (zone_id, sms_enabled, alarm_enabled, ai_detection_enabled) VALUES (?, 1, 1, 1)")->execute([$zoneId]);
                $stmt->execute([$zoneId]);
                $s = $stmt->fetch();
            }
            return $s;
        } catch (PDOException $e) { return null; }
    }
}
if (!function_exists('updateZoneNotificationSettings')) {
    function updateZoneNotificationSettings($zoneId, $data) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                INSERT INTO zone_notification_settings
                    (zone_id, sms_enabled, alarm_enabled, ai_detection_enabled, alarm_delay_seconds, ai_confidence_threshold, auto_create_incidents)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (zone_id) DO UPDATE SET 
                    sms_enabled = EXCLUDED.sms_enabled,
                    alarm_enabled = EXCLUDED.alarm_enabled,
                    ai_detection_enabled = EXCLUDED.ai_detection_enabled,
                    alarm_delay_seconds = EXCLUDED.alarm_delay_seconds,
                    ai_confidence_threshold = EXCLUDED.ai_confidence_threshold,
                    auto_create_incidents = EXCLUDED.auto_create_incidents
            ');
            return $stmt->execute([
                $zoneId,
                $data['sms_enabled'] ?? 1,
                $data['alarm_enabled'] ?? 1,
                $data['ai_detection_enabled'] ?? 1,
                $data['alarm_delay_seconds'] ?? 120,
                $data['ai_confidence_threshold'] ?? 70,
                $data['auto_create_incidents'] ?? 1,
            ]);
        } catch (PDOException $e) { return false; }
    }
}

// ============================================================
// LIVE TRACKING
// ============================================================
if (!function_exists('getScoutLiveLocations')) {
    function getScoutLiveLocations($zoneId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.phone, u.email, u.is_online, u.last_seen,
                       s.current_lat, s.current_lng, s.last_update AS location_updated, s.is_offline,
                       (SELECT i.id FROM incidents i WHERE i.reporter_id = u.id AND i.status IN ('reported','acknowledged','in_progress') AND i.zone_id = ? LIMIT 1) AS active_incident_id
                FROM users u
                LEFT JOIN scout_live_tracking s ON u.id = s.scout_id
                WHERE u.role = 'scout' AND u.zone_id = ? AND u.is_active = 1
                ORDER BY u.is_online DESC, u.last_seen DESC
            ");
            $stmt->execute([$zoneId, $zoneId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getRangerLiveLocations')) {
    function getRangerLiveLocations($zoneId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.phone, u.badge_number, u.is_on_duty,
                       r.current_lat, r.current_lng, r.heading, r.speed,
                       r.last_update AS location_updated, r.is_offline,
                       (SELECT i.id FROM incidents i WHERE i.acknowledged_by = u.id AND i.status IN ('acknowledged','in_progress') LIMIT 1) AS current_incident_id
                FROM users u
                LEFT JOIN ranger_live_tracking r ON u.id = r.ranger_id
                WHERE u.role = 'ranger' AND u.zone_id = ? AND u.is_active = 1
                ORDER BY u.is_on_duty DESC, u.full_name ASC
            ");
            $stmt->execute([$zoneId]);
            $rangers = $stmt->fetchAll();
            foreach ($rangers as &$r) {
                $rs = $pdo->prepare('SELECT lat, lng, heading, speed, timestamp FROM ranger_location_history WHERE ranger_id = ? AND timestamp >= (NOW() - (2) * INTERVAL \'1 hour\') ORDER BY timestamp ASC');
                $rs->execute([$r['id']]);
                $r['patrol_route'] = $rs->fetchAll();
            }
            return $rangers;
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getAIAnomalies')) {
    function getAIAnomalies($zoneId, $limit = 50) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                SELECT a.*, u.full_name AS subject_name, u.role AS subject_role
                FROM ai_anomalies a
                LEFT JOIN users u ON (a.ranger_id = u.id OR a.scout_id = u.id)
                WHERE a.zone_id = ? AND a.detected_at >= (NOW() - (24) * INTERVAL \'1 hour\')
                ORDER BY a.detected_at DESC LIMIT ?
            ');
            $stmt->execute([$zoneId, $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('computeBufferZone')) {
    function computeBufferZone($geojson, $bufferMeters = 500) {
        if (!$geojson || !isset($geojson['coordinates'])) return null;
        $latDegPerMeter = 1 / 111320;
        $coords = $geojson['coordinates'][0];
        $n = count($coords);
        if ($n === 0) return null;
        $cx = 0; $cy = 0;
        foreach ($coords as $c) { $cx += $c[0]; $cy += $c[1]; }
        $cx /= $n; $cy /= $n;
        $buffered = [];
        foreach ($coords as $c) {
            $dx = $c[0] - $cx;
            $dy = $c[1] - $cy;
            $dist = sqrt($dx * $dx + $dy * $dy);
            if ($dist == 0) { $buffered[] = $c; continue; }
            $lngPerMeter = 1 / (111320 * cos(deg2rad($cy)));
            $buffered[] = [
                $c[0] + ($dx / $dist) * ($bufferMeters * $lngPerMeter),
                $c[1] + ($dy / $dist) * ($bufferMeters * $latDegPerMeter),
            ];
        }
        $buffered[] = $buffered[0];
        return ['type'=>'Polygon','coordinates'=>[$buffered]];
    }
}
if (!function_exists('updateScoutLocation')) {
    function updateScoutLocation($scoutId, $lat, $lng) {
        try {
            $pdo = getDB();
            $pdo->prepare('
                INSERT INTO scout_live_tracking (scout_id, current_lat, current_lng, last_update, is_offline)
                VALUES (?, ?, ?, NOW(), 0)
                 ON CONFLICT (scout_id) DO UPDATE SET  current_lat = EXCLUDED.current_lat, current_lng = EXCLUDED.current_lng, last_update = NOW(), is_offline = 0
            ')->execute([$scoutId, $lat, $lng]);
            $pdo->prepare("INSERT INTO scout_location_history (scout_id, lat, lng, timestamp) VALUES (?, ?, ?, NOW())")->execute([$scoutId, $lat, $lng]);
            $pdo->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?")->execute([$scoutId]);
            return true;
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('updateRangerLocation')) {
    function updateRangerLocation($rangerId, $lat, $lng, $heading = 0, $speed = 0, $incidentId = null) {
        try {
            $pdo = getDB();
            $pdo->prepare('
                INSERT INTO ranger_live_tracking (ranger_id, current_lat, current_lng, heading, speed, last_update, is_offline)
                VALUES (?, ?, ?, ?, ?, NOW(), 0)
                 ON CONFLICT (ranger_id) DO UPDATE SET  current_lat = EXCLUDED.current_lat, current_lng = EXCLUDED.current_lng, heading = EXCLUDED.heading, speed = EXCLUDED.speed, last_update = NOW(), is_offline = 0
            ')->execute([$rangerId, $lat, $lng, $heading, $speed]);
            $pdo->prepare("INSERT INTO ranger_location_history (ranger_id, lat, lng, heading, speed, incident_id, timestamp) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                ->execute([$rangerId, $lat, $lng, $heading, $speed, $incidentId]);
            return true;
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('broadcastToWS')) {
    function broadcastToWS($event, $payload) {
        if (!function_exists('curl_init')) return false;
        try {
            $url = defined('WS_URL') ? WS_URL : 'http://localhost:3001';
            $ch = curl_init(rtrim($url, '/') . '/broadcast');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['event'=>$event, 'payload'=>$payload, 'zone_id'=>$payload['zone_id'] ?? null]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            @curl_exec($ch);
            @curl_close($ch);
            return true;
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('sendManpowerRequest')) {
    function sendManpowerRequest($rangerId, $incidentId, $urgency, $description, $requiredCount = 2, $equipment = 'Standard') {
        try {
            $pdo = getDB();
            $incident = getIncident($incidentId);
            if (!$incident) return ['success'=>false,'error'=>'Incident not found'];
            $ranger = getUser($rangerId);
            $subject = "🚨 MANPOWER REQUEST: " . strtoupper($urgency) . " - Incident #{$incidentId}";
            $content = "Incident: {$incident['category']} - {$incident['description']}\n"
                     . "Location: {$incident['location_lat']}, {$incident['location_lng']}\n"
                     . "Severity: {$incident['severity']}\n"
                     . "Requesting Ranger: {$ranger['full_name']}\n\n"
                     . "Details: {$description}\n"
                     . "Required Personnel: {$requiredCount}\n"
                     . "Equipment Needed: {$equipment}";
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, recipient_id, incident_id, message_type, subject, content, severity, is_broadcast, requires_acknowledgment, created_at)
                VALUES (?, NULL, ?, 'manpower_request', ?, ?, ?, 1, 1, NOW())
            ");
            $stmt->execute([$rangerId, $incidentId, $subject, $content, $urgency === 'critical' ? 'critical' : 'high']);
            $messageId = $pdo->query('SELECT lastval()')->fetchColumn();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE zone_id = ? AND role IN ('zone_supervisor','admin') AND is_active = 1");
            $stmt->execute([$incident['zone_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                createNotification($uid, 'manpower_request', $subject, $content, $incidentId, $messageId);
            }
            $stmt = $pdo->prepare("SELECT u.id FROM users u LEFT JOIN ranger_availability ra ON u.id = ra.ranger_id WHERE u.zone_id = ? AND u.role = 'ranger' AND u.is_active = 1 AND u.id != ?");
            $stmt->execute([$incident['zone_id'], $rangerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                createNotification($rid, 'manpower_request', $subject, $content, $incidentId, $messageId);
            }
            broadcastToWS('manpower-request', [
                'zone_id'     => $incident['zone_id'],
                'message_id'  => $messageId,
                'incident_id' => $incidentId,
                'sender_name' => $ranger['full_name'],
                'urgency'     => $urgency,
            ]);
            return ['success'=>true,'message_id'=>$messageId];
        } catch (PDOException $e) {
            return ['success'=>false,'error'=>$e->getMessage()];
        }
    }
}
if (!function_exists('assignRangerToIncident')) {
    function assignRangerToIncident($incidentId, $rangerId, $assignedBy, $notes = null) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO incident_assignments (incident_id, ranger_id, assigned_by, assigned_at, status, notes)
                VALUES (?, ?, ?, NOW(), 'pending', ?)
            ");
            $stmt->execute([$incidentId, $rangerId, $assignedBy, $notes]);
            return $pdo->query('SELECT lastval()')->fetchColumn();
        } catch (PDOException $e) { return false; }
    }
}
if (!function_exists('getIncidentAssignments')) {
    function getIncidentAssignments($incidentId) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                SELECT ia.*, u.full_name AS ranger_name, u.phone AS ranger_phone
                FROM incident_assignments ia JOIN users u ON ia.ranger_id = u.id
                WHERE ia.incident_id = ? ORDER BY ia.assigned_at DESC
            ");
            $stmt->execute([$incidentId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getZoneUsers')) {
    function getZoneUsers($zoneId, $role = null) {
        try {
            $pdo = getDB();
            $sql = "SELECT id, email, phone, full_name, role, zone_id, is_active, is_online, last_seen, created_at FROM users WHERE zone_id = ?";
            $params = [$zoneId];
            if ($role) { $sql .= " AND role = ?"; $params[] = $role; }
            $sql .= " ORDER BY role, full_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('createUser')) {
    function createUser($data, $createdBy) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) return ['success'=>false,'error'=>'Email already exists'];
            $hash = hashPassword($data['password']);
            $stmt = $pdo->prepare("
                INSERT INTO users (email, phone, password_hash, full_name, role, zone_id, badge_number, created_by, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $data['email'],
                $data['phone'] ?? null,
                $hash,
                $data['full_name'],
                $data['role'],
                $data['zone_id'] ?? null,
                $data['badge_number'] ?? null,
                $createdBy,
            ]);
            return ['success'=>true,'user_id'=>$pdo->query('SELECT lastval()')->fetchColumn()];
        } catch (PDOException $e) {
            return ['success'=>false,'error'=>$e->getMessage()];
        }
    }
}
if (!function_exists('updateUser')) {
    function updateUser($userId, $data) {
        try {
            $pdo    = getDB();
            $fields = []; $params = [];
            foreach (['full_name','phone','role','zone_id','is_active','badge_number'] as $f) {
                if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $params[] = $data[$f]; }
            }
            if (isset($data['password']) && !empty($data['password'])) {
                $fields[] = "password_hash = ?"; $params[] = hashPassword($data['password']);
            }
            if (empty($fields)) return ['success'=>false,'error'=>'No fields to update'];
            $params[] = $userId;
            $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
            return ['success'=>true];
        } catch (PDOException $e) {
            return ['success'=>false,'error'=>$e->getMessage()];
        }
    }
}
if (!function_exists('deleteUser')) {
    function deleteUser($userId) {
        try {
            $pdo = getDB();
            $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$userId]);
            return ['success'=>true];
        } catch (PDOException $e) {
            return ['success'=>false,'error'=>$e->getMessage()];
        }
    }
}
if (!function_exists('getZoneMessages')) {
    function getZoneMessages($zoneId, $type = null, $limit = 100) {
        try {
            $pdo = getDB();
            $sql = "SELECT m.*, s.full_name AS sender_name, s.role AS sender_role, r.full_name AS recipient_name
                    FROM messages m
                    JOIN users s ON m.sender_id = s.id
                    LEFT JOIN users r ON m.recipient_id = r.id
                    WHERE (s.zone_id = ? OR m.is_broadcast = 1)";
            $params = [$zoneId];
            if ($type) { $sql .= " AND m.message_type = ?"; $params[] = $type; }
            $sql .= " ORDER BY m.created_at DESC LIMIT ?";
            $params[] = $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getZonePatrolStats')) {
    function getZonePatrolStats($zoneId, $hours = 24) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                SELECT u.id AS ranger_id, u.full_name,
                       COUNT(rlh.id) AS gps_points,
                       MIN(rlh.timestamp) AS first_seen,
                       MAX(rlh.timestamp) AS last_seen
                FROM users u
                LEFT JOIN ranger_location_history rlh ON u.id = rlh.ranger_id AND rlh.timestamp >= (NOW() - (?) * INTERVAL \'1 hour\')
                WHERE u.zone_id = ? AND u.role = \'ranger\' AND u.is_active = 1
                GROUP BY u.id ORDER BY gps_points DESC
            ');
            $stmt->execute([$hours, $zoneId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('getAIAnomalyStats')) {
    function getAIAnomalyStats($zoneId, $hours = 24) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare('
                SELECT type, severity, COUNT(*) AS count
                FROM ai_anomalies WHERE zone_id = ? AND detected_at >= (NOW() - (?) * INTERVAL \'1 hour\')
                GROUP BY type, severity ORDER BY count DESC
            ');
            $stmt->execute([$zoneId, $hours]);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}
if (!function_exists('logAIAnomaly')) {
    function logAIAnomaly($data) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("
                INSERT INTO ai_anomalies
                    (zone_id, type, severity, description, confidence, location_lat, location_lng, radius_meters, ranger_id, scout_id, detected_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $data['zone_id'],
                $data['type'],
                $data['severity'] ?? 'medium',
                $data['description'] ?? null,
                $data['confidence'] ?? 0.7,
                $data['location_lat'],
                $data['location_lng'],
                $data['radius_meters'] ?? 200,
                $data['ranger_id'] ?? null,
                $data['scout_id'] ?? null,
            ]);
            $anomalyId = $pdo->query('SELECT lastval()')->fetchColumn();
            broadcastToWS('ai-anomaly', array_merge($data, ['id' => $anomalyId]));
            return $anomalyId;
        } catch (PDOException $e) {
            error_log('[WS] logAIAnomaly: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================
// END OF FILE
// ============================================================
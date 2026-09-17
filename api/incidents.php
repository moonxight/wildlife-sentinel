<?php
// ============================================================
// api/incidents.php
// Wildlife Sentinel — Incidents API
// ------------------------------------------------------------
// Endpoints (all require login):
//   GET  ?action=list           list incidents (role-scoped)
//   GET  ?action=get&id=…       single incident + responses
//   POST ?action=acknowledge    ranger/sup/admin acknowledge
//   POST ?action=update_status  change status
//   POST ?action=add_response   add a note / response
//   GET  ?action=nearby         incidents near a point
//   GET  ?action=recent         recent incidents in my zone
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/functions.php';
requireLogin();

// Offline / maintenance / timeout guards (all no-op for admins)
if (function_exists('enforceMaintenanceMode')) enforceMaintenanceMode();
if (function_exists('applySessionTimeout'))   applySessionTimeout();

$pdo  = getDB();
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================
// SMALL HELPERS
// ============================================================
function api_json($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function readJson(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function requireRole(array $allowed): void {
    global $user;
    if (!in_array($user['role'], $allowed, true)) {
        api_json(['success' => false, 'error' => 'Forbidden'], 403);
    }
}

function isIncidentInScope(int $incidentId, array $user): ?array {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM incidents WHERE id = ? LIMIT 1");
        $stmt->execute([$incidentId]);
        $inc = $stmt->fetch();
        if (!$inc) return null;

        // Admin sees everything
        if ($user['role'] === 'admin') return $inc;

        // Supervisor & ranger — must match their zone
        if (in_array($user['role'], ['ranger', 'zone_supervisor'], true)) {
            return ((int)$inc['zone_id'] === (int)$user['zone_id']) ? $inc : null;
        }

        // Scout / tourism — must be their own report
        if (in_array($user['role'], ['scout', 'tourism'], true)) {
            return ((int)$inc['reporter_id'] === (int)$user['id']) ? $inc : null;
        }
        return null;
    } catch (PDOException $e) {
        return null;
    }
}

function fireWS(string $event, array $payload): void {
    if (function_exists('broadcastToWS')) {
        try { broadcastToWS($event, $payload); } catch (Throwable $e) { /* non-fatal */ }
    }
}

// ============================================================
// ROUTER
// ============================================================
try {
    switch ($action) {

        // ----------------------------------------------------
        // LIST
        // ----------------------------------------------------
        case 'list': {
            $limit    = max(1, min(200, (int)($_GET['limit'] ?? 50)));
            $offset   = max(0, (int)($_GET['offset'] ?? 0));
            $status   = $_GET['status']   ?? '';
            $severity = $_GET['severity'] ?? '';
            $zoneId   = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;
            $from     = $_GET['from'] ?? '';
            $to       = $_GET['to']   ?? '';

            $where  = ["1=1"];
            $params = [];

            // Role scope
            if (in_array($user['role'], ['ranger', 'zone_supervisor'], true)) {
                $where[]  = "i.zone_id = ?";
                $params[] = $user['zone_id'];
            } elseif (in_array($user['role'], ['scout', 'tourism'], true)) {
                $where[]  = "i.reporter_id = ?";
                $params[] = $user['id'];
            }

            // Admin/supervisor extra zone filter
            if ($zoneId > 0 && in_array($user['role'], ['admin', 'zone_supervisor'], true)) {
                $where[]  = "i.zone_id = ?";
                $params[] = $zoneId;
            }

            if ($status !== '') {
                $where[]  = "i.status = ?";
                $params[] = $status;
            }
            if ($severity !== '') {
                $where[]  = "i.severity = ?";
                $params[] = $severity;
            }
            if ($from !== '') {
                $where[]  = "i.reported_at >= ?";
                $params[] = $from;
            }
            if ($to !== '') {
                $where[]  = "i.reported_at <= ?";
                $params[] = $to;
            }

            $sql = "
                SELECT i.*, u.full_name AS reporter_name, u.phone AS reporter_phone, z.name AS zone_name
                FROM incidents i
                JOIN users u ON i.reporter_id = u.id
                LEFT JOIN zones z ON i.zone_id = z.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY i.reported_at DESC
                LIMIT ? OFFSET ?
            ";

            $countSql = "
                SELECT COUNT(*) AS c
                FROM incidents i
                WHERE " . implode(' AND ', $where);

            $countParams = $params;

            $params[] = $limit;
            $params[] = $offset;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $incidents = $stmt->fetchAll() ?: [];

            foreach ($incidents as &$inc) {
                if (!empty($inc['media_urls'])) {
                    $decoded = json_decode($inc['media_urls'], true);
                    $inc['media_urls'] = is_array($decoded) ? $decoded : [];
                } else {
                    $inc['media_urls'] = [];
                }
            }
            unset($inc);

            $stmt = $pdo->prepare($countSql);
            $stmt->execute($countParams);
            $total = (int)($stmt->fetch()['c'] ?? 0);

            api_json([
                'success'   => true,
                'incidents' => $incidents,
                'count'     => count($incidents),
                'total'     => $total,
                'limit'     => $limit,
                'offset'    => $offset,
            ]);
        }

        // ----------------------------------------------------
        // GET ONE
        // ----------------------------------------------------
        case 'get': {
            $incidentId = (int)($_GET['id'] ?? 0);
            if ($incidentId <= 0) {
                api_json(['success' => false, 'error' => 'Incident ID required'], 400);
            }

            $inc = isIncidentInScope($incidentId, $user);
            if (!$inc) {
                api_json(['success' => false, 'error' => 'Incident not found or unauthorized'], 404);
            }

            // Enrich with reporter, zone
            $stmt = $pdo->prepare("
                SELECT i.*, u.full_name AS reporter_name, u.phone AS reporter_phone,
                       z.name AS zone_name
                FROM incidents i
                JOIN users u ON i.reporter_id = u.id
                LEFT JOIN zones z ON i.zone_id = z.id
                WHERE i.id = ?
            ");
            $stmt->execute([$incidentId]);
            $incident = $stmt->fetch();

            if (!empty($incident['media_urls'])) {
                $decoded = json_decode($incident['media_urls'], true);
                $incident['media_urls'] = is_array($decoded) ? $decoded : [];
            } else {
                $incident['media_urls'] = [];
            }

            // Responses (if table exists)
            $responses = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT ir.*, u.full_name AS ranger_name
                    FROM incident_responses ir
                    JOIN users u ON ir.ranger_id = u.id
                    WHERE ir.incident_id = ?
                    ORDER BY ir.created_at ASC
                ");
                $stmt->execute([$incidentId]);
                $responses = $stmt->fetchAll() ?: [];
            } catch (PDOException $e) { /* optional table */ }

            // Assignments (if table exists)
            $assignments = [];
            try {
                $stmt = $pdo->prepare("
                    SELECT ia.*, u.full_name AS ranger_name, u.phone AS ranger_phone
                    FROM incident_assignments ia
                    JOIN users u ON ia.ranger_id = u.id
                    WHERE ia.incident_id = ?
                    ORDER BY ia.assigned_at DESC
                ");
                $stmt->execute([$incidentId]);
                $assignments = $stmt->fetchAll() ?: [];
            } catch (PDOException $e) { /* optional table */ }

            api_json([
                'success'     => true,
                'incident'    => $incident,
                'responses'   => $responses,
                'assignments' => $assignments,
            ]);
        }

        // ----------------------------------------------------
        // ACKNOWLEDGE
        // ----------------------------------------------------
        case 'acknowledge': {
            requireRole(['ranger', 'zone_supervisor', 'admin']);

            $data = readJson();
            $incidentId = (int)($data['incident_id'] ?? 0);
            if ($incidentId <= 0) {
                api_json(['success' => false, 'error' => 'Incident ID required'], 400);
            }

            $inc = isIncidentInScope($incidentId, $user);
            if (!$inc) {
                api_json(['success' => false, 'error' => 'Incident not found or unauthorized'], 404);
            }

            // Idempotent — if already acknowledged, keep the first acknowledgement
            if ($inc['acknowledged_by']) {
                api_json([
                    'success' => true,
                    'message' => 'Already acknowledged',
                    'already' => true,
                ]);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE incidents
                    SET status = 'acknowledged',
                        acknowledged_by = ?,
                        acknowledged_at = NOW()
                    WHERE id = ? AND acknowledged_by IS NULL
                ");
                $stmt->execute([$user['id'], $incidentId]);
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                api_json(['success' => false, 'error' => 'Update failed'], 500);
            }

            // Notify reporter
            if (function_exists('createNotification')) {
                createNotification(
                    (int)$inc['reporter_id'],
                    'acknowledged',
                    '✅ Incident Acknowledged',
                    "Ranger {$user['full_name']} has acknowledged your report",
                    $incidentId
                );
            }

            // Stop any pending alarm for this incident
            try {
                require_once __DIR__ . '/../includes/alarm_control.php';
                if (class_exists('AlarmControl')) {
                    (new AlarmControl())->stopAlarm($incidentId, (int)$user['id']);
                }
            } catch (Throwable $e) { /* non-fatal */ }

            fireWS('incident-acknowledged', [
                'zone_id'     => (int)$inc['zone_id'],
                'incident_id' => $incidentId,
                'by'          => (int)$user['id'],
                'by_name'     => $user['full_name'],
            ]);

            logAudit($user['id'], 'acknowledge_incident', ['incident_id' => $incidentId]);

            api_json(['success' => true, 'message' => 'Incident acknowledged successfully']);
        }

        // ----------------------------------------------------
        // UPDATE STATUS
        // ----------------------------------------------------
        case 'update_status': {
            requireRole(['ranger', 'zone_supervisor', 'admin']);

            $data = readJson();
            $incidentId = (int)($data['incident_id'] ?? 0);
            $newStatus  = $data['status'] ?? '';
            $notes      = trim((string)($data['notes'] ?? ''));

            if ($incidentId <= 0 || $newStatus === '') {
                api_json(['success' => false, 'error' => 'Incident ID and status required'], 400);
            }

            $inc = isIncidentInScope($incidentId, $user);
            if (!$inc) {
                api_json(['success' => false, 'error' => 'Incident not found or unauthorized'], 404);
            }

            // Allowed transitions
            $allowed = [
                'reported'     => ['acknowledged', 'in_progress', 'closed'],
                'acknowledged' => ['in_progress', 'resolved', 'closed'],
                'in_progress'  => ['resolved', 'closed'],
                'resolved'     => ['closed'],
                'closed'       => [],
            ];
            $currentStatus = $inc['status'] ?? 'reported';
            $validNext = $allowed[$currentStatus] ?? [];
            if (!in_array($newStatus, $validNext, true)) {
                api_json([
                    'success' => false,
                    'error'   => "Cannot move from '{$currentStatus}' to '{$newStatus}'",
                ], 400);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    UPDATE incidents
                    SET status = ?,
                        resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE resolved_at END
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $newStatus, $incidentId]);

                if ($notes !== '') {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO incident_responses (incident_id, ranger_id, status_update, notes, created_at)
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$incidentId, $user['id'], $newStatus, $notes]);
                    } catch (PDOException $e) { /* optional table */ }
                }

                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                api_json(['success' => false, 'error' => 'Update failed'], 500);
            }

            // Notify reporter on meaningful transitions
            if (function_exists('createNotification')) {
                $titles = [
                    'in_progress' => '🔄 Response In Progress',
                    'resolved'    => '✅ Incident Resolved',
                    'closed'      => '📌 Incident Closed',
                ];
                if (isset($titles[$newStatus])) {
                    createNotification(
                        (int)$inc['reporter_id'],
                        $newStatus,
                        $titles[$newStatus],
                        "Your incident #{$incidentId} is now {$newStatus}",
                        $incidentId
                    );
                }
            }

            // If resolved/closed, ensure alarms are stopped
            if (in_array($newStatus, ['resolved', 'closed'], true)) {
                try {
                    require_once __DIR__ . '/../includes/alarm_control.php';
                    if (class_exists('AlarmControl')) {
                        (new AlarmControl())->stopAlarm($incidentId, (int)$user['id']);
                    }
                } catch (Throwable $e) { /* non-fatal */ }
            }

            fireWS('incident-status-changed', [
                'zone_id'     => (int)$inc['zone_id'],
                'incident_id' => $incidentId,
                'status'      => $newStatus,
                'by'          => (int)$user['id'],
            ]);

            logAudit($user['id'], 'update_incident_status', [
                'incident_id' => $incidentId,
                'from'        => $currentStatus,
                'to'          => $newStatus,
            ]);

            api_json(['success' => true, 'message' => 'Incident status updated successfully']);
        }

        // ----------------------------------------------------
        // ADD RESPONSE
        // ----------------------------------------------------
        case 'add_response': {
            requireRole(['ranger', 'zone_supervisor', 'admin']);

            $data = readJson();
            $incidentId = (int)($data['incident_id'] ?? 0);
            $notes      = trim((string)($data['notes'] ?? ''));
            $statusUpdate = $data['status_update'] ?? 'investigating';

            if ($incidentId <= 0 || $notes === '') {
                api_json(['success' => false, 'error' => 'Incident ID and notes required'], 400);
            }

            $allowedUpdates = ['arrived', 'investigating', 'resolved', 'escalated'];
            if (!in_array($statusUpdate, $allowedUpdates, true)) {
                api_json(['success' => false, 'error' => 'Invalid status update'], 400);
            }

            $inc = isIncidentInScope($incidentId, $user);
            if (!$inc) {
                api_json(['success' => false, 'error' => 'Incident not found or unauthorized'], 404);
            }

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO incident_responses (incident_id, ranger_id, status_update, notes, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$incidentId, $user['id'], $statusUpdate, $notes]);
            } catch (PDOException $e) {
                api_json(['success' => false, 'error' => 'Could not save response'], 500);
            }

            if ($statusUpdate === 'resolved') {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE incidents
                        SET status = 'resolved', resolved_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$incidentId]);

                    if (function_exists('createNotification')) {
                        createNotification(
                            (int)$inc['reporter_id'],
                            'resolved',
                            '✅ Incident Resolved',
                            "Your incident #{$incidentId} has been resolved",
                            $incidentId
                        );
                    }

                    require_once __DIR__ . '/../includes/alarm_control.php';
                    if (class_exists('AlarmControl')) {
                        (new AlarmControl())->stopAlarm($incidentId, (int)$user['id']);
                    }

                    fireWS('incident-status-changed', [
                        'zone_id'     => (int)$inc['zone_id'],
                        'incident_id' => $incidentId,
                        'status'      => 'resolved',
                        'by'          => (int)$user['id'],
                    ]);
                } catch (Throwable $e) { /* non-fatal */ }
            }

            logAudit($user['id'], 'add_incident_response', [
                'incident_id'   => $incidentId,
                'status_update' => $statusUpdate,
            ]);

            api_json(['success' => true, 'message' => 'Response added successfully']);
        }

        // ----------------------------------------------------
        // NEARBY
        // ----------------------------------------------------
        case 'nearby': {
            $lat = $_GET['lat'] ?? null;
            $lng = $_GET['lng'] ?? null;
            // Accept either radius_km or radius (both treated as km)
            $radius = (float)($_GET['radius_km'] ?? $_GET['radius'] ?? 10);
            $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));

            if ($lat === null || $lng === null) {
                api_json(['success' => false, 'error' => 'Location required'], 400);
            }
            $lat = (float)$lat; $lng = (float)$lng;
            if (!validateCoordinates($lat, $lng)) {
                api_json(['success' => false, 'error' => 'Invalid coordinates'], 400);
            }

            $where  = ["i.status NOT IN ('resolved','closed')"];
            $params = [$lat, $lng, $lat];

            // Role scope
            if (in_array($user['role'], ['ranger', 'zone_supervisor'], true)) {
                $where[]  = "i.zone_id = ?";
                $params[] = $user['zone_id'];
            } elseif (in_array($user['role'], ['scout', 'tourism'], true)) {
                $where[]  = "i.reporter_id = ?";
                $params[] = $user['id'];
            }

            $params[] = $radius;
            $params[] = $limit;

            $sql = "
                SELECT * FROM (SELECT i.*,
                       u.full_name AS reporter_name,
                       z.name AS zone_name,
                       (6371 * acos(
                           cos(radians(?)) * cos(radians(i.location_lat)) *
                           cos(radians(i.location_lng) - radians(?)) +
                           sin(radians(?)) * sin(radians(i.location_lat))
                       )) AS distance
                FROM incidents i
                JOIN users u ON i.reporter_id = u.id
                LEFT JOIN zones z ON i.zone_id = z.id
                WHERE " . implode(' AND ', $where) . '
                ) AS nearby WHERE distance < ?
                ORDER BY distance ASC, CASE severity WHEN \'critical\' THEN 1 WHEN \'high\' THEN 2 WHEN \'medium\' THEN 3 WHEN \'low\' THEN 4 ELSE 0 END
                LIMIT ?
            ';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $incidents = $stmt->fetchAll() ?: [];

            foreach ($incidents as &$inc) {
                if (!empty($inc['media_urls'])) {
                    $decoded = json_decode($inc['media_urls'], true);
                    $inc['media_urls'] = is_array($decoded) ? $decoded : [];
                } else {
                    $inc['media_urls'] = [];
                }
            }
            unset($inc);

            api_json([
                'success'   => true,
                'incidents' => $incidents,
                'count'     => count($incidents),
            ]);
        }

        // ----------------------------------------------------
        // RECENT
        // ----------------------------------------------------
        case 'recent': {
            $limit = max(1, min(50, (int)($_GET['limit'] ?? 5)));

            // Scope: supervisors/rangers get their zone; admins get all; scouts/tourism get their own
            $where  = ['1=1'];
            $params = [];
            if (in_array($user['role'], ['ranger', 'zone_supervisor'], true)) {
                $where[]  = "i.zone_id = ?";
                $params[] = $user['zone_id'];
            } elseif (in_array($user['role'], ['scout', 'tourism'], true)) {
                $where[]  = "i.reporter_id = ?";
                $params[] = $user['id'];
            }
            $params[] = $limit;

            $stmt = $pdo->prepare("
                SELECT i.*, u.full_name AS reporter_name, z.name AS zone_name
                FROM incidents i
                JOIN users u ON i.reporter_id = u.id
                LEFT JOIN zones z ON i.zone_id = z.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY i.reported_at DESC
                LIMIT ?
            ");
            $stmt->execute($params);
            $incidents = $stmt->fetchAll() ?: [];

            foreach ($incidents as &$inc) {
                if (!empty($inc['media_urls'])) {
                    $decoded = json_decode($inc['media_urls'], true);
                    $inc['media_urls'] = is_array($decoded) ? $decoded : [];
                } else {
                    $inc['media_urls'] = [];
                }
            }
            unset($inc);

            api_json([
                'success'   => true,
                'incidents' => $incidents,
                'count'     => count($incidents),
            ]);
        }

        // ----------------------------------------------------
        // UNKNOWN
        // ----------------------------------------------------
        default:
            api_json(['success' => false, 'error' => 'Invalid action'], 400);
    }
} catch (PDOException $e) {
    error_log('[WS-API-INCIDENTS] PDO: ' . $e->getMessage());
    api_json(['success' => false, 'error' => 'Database error'], 500);
} catch (Throwable $e) {
    error_log('[WS-API-INCIDENTS] ' . $e->getMessage());
    api_json(['success' => false, 'error' => 'Server error'], 500);
}
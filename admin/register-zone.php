<?php
// ============================================================
// admin/register-zone.php
// Wildlife Sentinel — Zone Registration + Supervisor Management
// ============================================================
// Features:
//   - List available (unregistered) parks/GMAs
//   - Register a zone and create its supervisor in one flow
//   - Edit / deactivate / delete supervisors
//   - Interactive map showing registered + available zones
//   - Honors global settings:
//       password_min_length, password_require_upper/lower/num/sym,
//       sms_enabled, notify_on_incident, notify_on_ai_alert,
//       notify_on_alarm, notify_on_manpower, items_per_page
// ============================================================

require_once '../includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$pdo  = getDB();
$error = '';
$success = '';

// ============================================================
// GLOBAL SETTINGS (from admin/settings.php)
// ============================================================
if (!function_exists('ws_zone_setting')) {
    function ws_zone_setting(string $key, $default = null) {
        if (function_exists('getSetting')) {
            $v = getSetting($key);
            return $v !== null ? $v : $default;
        }
        try {
            $stmt = getDB()->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['setting_value'] : $default;
        } catch (PDOException $e) {
            return $default;
        }
    }
}

$setPwdMinLength    = (int)    ws_zone_setting('password_min_length', 8);
$setPwdReqUpper     = (string) ws_zone_setting('password_require_upper', '1') === '1';
$setPwdReqLower     = (string) ws_zone_setting('password_require_lower', '1') === '1';
$setPwdReqNum       = (string) ws_zone_setting('password_require_num', '1')   === '1';
$setPwdReqSym       = (string) ws_zone_setting('password_require_sym', '0')   === '1';

$setSmsEnabled      = (string) ws_zone_setting('sms_enabled', '1')            === '1';
$setNotifyIncident  = (string) ws_zone_setting('notify_on_incident', '1')     === '1';
$setNotifyAiAlert   = (string) ws_zone_setting('notify_on_ai_alert', '1')     === '1';
$setNotifyAlarm     = (string) ws_zone_setting('notify_on_alarm', '1')        === '1';

$itemsPerPage = (int) ws_zone_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;

// ============================================================
// PASSWORD VALIDATION (uses settings)
// ============================================================
if (!function_exists('ws_zone_validate_password')) {
    function ws_zone_validate_password(string $pwd): array {
        $errors = [];
        $min    = (int) ws_zone_setting('password_min_length', 8);
        $upper  = (string) ws_zone_setting('password_require_upper', '1') === '1';
        $lower  = (string) ws_zone_setting('password_require_lower', '1') === '1';
        $num    = (string) ws_zone_setting('password_require_num', '1')   === '1';
        $sym    = (string) ws_zone_setting('password_require_sym', '0')   === '1';

        if (strlen($pwd) < $min)                 $errors[] = "Password must be at least {$min} characters";
        if ($upper && !preg_match('/[A-Z]/', $pwd)) $errors[] = 'Password must contain an uppercase letter';
        if ($lower && !preg_match('/[a-z]/', $pwd)) $errors[] = 'Password must contain a lowercase letter';
        if ($num   && !preg_match('/[0-9]/', $pwd)) $errors[] = 'Password must contain a number';
        if ($sym   && !preg_match('/[^A-Za-z0-9]/', $pwd)) $errors[] = 'Password must contain a symbol';
        return $errors;
    }
}

// ============================================================
// NOTIFICATION GATE
// ============================================================
if (!function_exists('ws_zone_notify')) {
    function ws_zone_notify(int $userId, string $title, string $body): bool {
        if (!function_exists('createNotification')) return false;
        try {
            createNotification($userId, 'system_alert', $title, $body, null);
            return true;
        } catch (Throwable $e) {
            error_log('[WS-ZONE] notify failed: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================
// HELPER: circle GeoJSON
// ============================================================
if (!function_exists('circleGeoJSON')) {
    function circleGeoJSON(float $lat, float $lng, int $radiusMeters, int $points = 48): array {
        $coords = [];
        $earthRadius = 6371000;
        $latRad = deg2rad($lat);
        $lngRad = deg2rad($lng);
        $angular = $radiusMeters / $earthRadius;

        for ($i = 0; $i < $points; $i++) {
            $bearing = (2 * M_PI * $i) / $points;
            $latPoint = asin(
                sin($latRad) * cos($angular) +
                cos($latRad) * sin($angular) * cos($bearing)
            );
            $lngPoint = $lngRad + atan2(
                sin($bearing) * sin($angular) * cos($latRad),
                cos($angular) - sin($latRad) * sin($latPoint)
            );
            $coords[] = [rad2deg($lngPoint), rad2deg($latPoint)];
        }
        $coords[] = $coords[0];
        return ['type' => 'Polygon', 'coordinates' => [$coords]];
    }
}

// ============================================================
// DETECT COLUMNS ON zones
// ============================================================
$zoneCols = [];
try {
    $colStmt = $pdo->query('SELECT column_name AS "Field", data_type AS "Type", is_nullable AS "Null", column_default AS "Default" FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=\'zones\' ORDER BY ordinal_position');
    while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $zoneCols[$c['Field']] = true;
    }
} catch (PDOException $e) {}

$hasBoundaryCenter = isset($zoneCols['boundary_center_lat'], $zoneCols['boundary_center_lng']);
$hasCenterLat      = isset($zoneCols['center_lat']);
$hasCenterLng      = isset($zoneCols['center_lng']);
$hasBoundaryGeo    = isset($zoneCols['boundary_geojson']);
$hasBufferRadius   = isset($zoneCols['buffer_radius']);
$hasParkType       = isset($zoneCols['park_type']);
$hasParkCode       = isset($zoneCols['park_code']);
$hasIsRegistered   = isset($zoneCols['is_registered']);
$hasDescription    = isset($zoneCols['description']);

// ============================================================
// HANDLE POST ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- REGISTER ZONE + SUPERVISOR --------
    if ($action === 'register_zone') {
        $zoneId         = (int)($_POST['zone_id'] ?? 0);
        $supEmail       = trim((string)($_POST['supervisor_email'] ?? ''));
        $supFullName    = trim((string)($_POST['supervisor_full_name'] ?? ''));
        $supPhone       = trim((string)($_POST['supervisor_phone'] ?? ''));
        $supPassword    = (string)($_POST['supervisor_password'] ?? '');
        $supConfirm     = (string)($_POST['supervisor_confirm_password'] ?? '');
        $bufferRadius   = max(100, min(5000, (int)($_POST['buffer_radius'] ?? 500)));

        $errors = [];
        if ($zoneId <= 0)                    $errors[] = 'Please select a park';
        if ($supEmail === '')                $errors[] = 'Supervisor email required';
        elseif (!filter_var($supEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid supervisor email';
        if ($supFullName === '')             $errors[] = 'Supervisor name required';
        if ($supPassword === '')             $errors[] = 'Password required';
        elseif ($supPassword !== $supConfirm) $errors[] = 'Passwords do not match';
        else $errors = array_merge($errors, ws_zone_validate_password($supPassword));

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Case-insensitive email uniqueness
                $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                $check->execute([$supEmail]);
                if ($check->fetch()) {
                    throw new Exception('Email already exists');
                }

                // Register the zone (only if unregistered)
                $stmt = $pdo->prepare("
                    UPDATE zones
                    SET is_registered = 1, is_active = 1, buffer_radius = ?
                    WHERE id = ? AND is_registered = 0
                ");
                $stmt->execute([$bufferRadius, $zoneId]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Zone not found or already registered');
                }

                $hash = hashPassword($supPassword);
                $stmt = $pdo->prepare("
                    INSERT INTO users (email, phone, password_hash, full_name, role, zone_id, created_by, is_active)
                    VALUES (?, ?, ?, ?, 'zone_supervisor', ?, ?, 1)
                ");
                $stmt->execute([
                    $supEmail,
                    $supPhone ?: null,
                    $hash,
                    $supFullName,
                    $zoneId,
                    $user['id']
                ]);

                $supervisorId = (int)$pdo->query('SELECT lastval()')->fetchColumn();

                // Zone notification settings — respects global toggles
                try {
                    $pdo->prepare('
                        INSERT INTO zone_notification_settings (zone_id, sms_enabled, alarm_enabled, ai_detection_enabled)
                        VALUES (?, ?, ?, ?)
                         ON CONFLICT (zone_id) DO UPDATE SET  zone_id = EXCLUDED.zone_id
                    ')->execute([
                        $zoneId,
                        $setSmsEnabled ? 1 : 0,
                        $setNotifyAlarm ? 1 : 0,
                        $setNotifyAiAlert ? 1 : 0,
                    ]);
                } catch (PDOException $e) { /* optional table */ }

                ws_zone_notify(
                    $supervisorId,
                    '🏛️ Zone Supervisor Account Created',
                    'You have been registered as supervisor for your zone. Please log in to manage it.'
                );

                logAudit($user['id'], 'register_zone_with_supervisor', [
                    'zone_id'       => $zoneId,
                    'supervisor_id' => $supervisorId,
                    'buffer_radius' => $bufferRadius,
                ]);

                $pdo->commit();
                $success = "✅ Zone registered with supervisor. Supervisor can log in with: " . htmlspecialchars($supEmail);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = '❌ Error: ' . htmlspecialchars($e->getMessage());
            }
        } else {
            $error = '❌ ' . implode('<br>❌ ', array_map('htmlspecialchars', $errors));
        }
    }

    // -------- UPDATE SUPERVISOR --------
    if ($action === 'update_supervisor') {
        $supervisorId = (int)($_POST['supervisor_id'] ?? 0);
        $email        = trim((string)($_POST['email'] ?? ''));
        $fullName     = trim((string)($_POST['full_name'] ?? ''));
        $phone        = trim((string)($_POST['phone'] ?? ''));
        $zoneId       = (int)($_POST['zone_id'] ?? 0);
        $isActive     = isset($_POST['is_active']) ? 1 : 0;
        $newPassword  = (string)($_POST['new_password'] ?? '');

        $errors = [];
        if ($supervisorId <= 0) $errors[] = 'Invalid supervisor';
        if ($email === '')      $errors[] = 'Email required';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
        if ($fullName === '')   $errors[] = 'Full name required';

        if (!empty($newPassword)) {
            $errors = array_merge($errors, ws_zone_validate_password($newPassword));
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1");
                $check->execute([$email, $supervisorId]);
                if ($check->fetch()) {
                    throw new Exception('Email already exists for another user');
                }

                $sql = "UPDATE users SET email = ?, full_name = ?, phone = ?, zone_id = ?, is_active = ?";
                $params = [$email, $fullName, $phone ?: null, $zoneId ?: null, $isActive];

                if ($newPassword !== '') {
                    $sql .= ", password_hash = ?";
                    $params[] = hashPassword($newPassword);
                }
                $sql .= " WHERE id = ? AND role = 'zone_supervisor'";
                $params[] = $supervisorId;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Supervisor not found or no changes made');
                }

                ws_zone_notify(
                    $supervisorId,
                    '📝 Account Updated',
                    'Your account details have been updated by the administrator.'
                );

                logAudit($user['id'], 'update_supervisor', [
                    'supervisor_id' => $supervisorId,
                    'email'         => $email,
                    'password_changed' => $newPassword !== '',
                ]);

                $pdo->commit();
                $success = '✅ Supervisor details updated. Changes take effect immediately.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = '❌ Error: ' . htmlspecialchars($e->getMessage());
            }
        } else {
            $error = '❌ ' . implode('<br>❌ ', array_map('htmlspecialchars', $errors));
        }
    }

    // -------- DELETE SUPERVISOR --------
    if ($action === 'delete_supervisor') {
        $supervisorId = (int)($_POST['supervisor_id'] ?? 0);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT full_name, zone_id FROM users WHERE id = ? AND role = 'zone_supervisor'");
            $stmt->execute([$supervisorId]);
            $supervisor = $stmt->fetch();

            if (!$supervisor) {
                throw new Exception('Supervisor not found');
            }

            // Check if they have any open incidents reported/assigned
            $openIncidents = 0;
            try {
                $q = $pdo->prepare("SELECT COUNT(*) AS c FROM incidents WHERE reporter_id = ? AND status NOT IN ('resolved','closed')");
                $q->execute([$supervisorId]);
                $openIncidents = (int)($q->fetch()['c'] ?? 0);
            } catch (PDOException $e) { /* non-fatal */ }

            if ($openIncidents > 0) {
                throw new Exception("Cannot delete — this supervisor has {$openIncidents} open incident(s). Reassign or close them first.");
            }

            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$supervisorId]);

            logAudit($user['id'], 'delete_supervisor', [
                'supervisor_id' => $supervisorId,
                'zone_id'       => $supervisor['zone_id'],
                'name'          => $supervisor['full_name'],
            ]);

            $pdo->commit();
            $success = '✅ Supervisor deleted. Zone is now unassigned.';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = '❌ Error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ============================================================
// GET AVAILABLE PARKS
// ============================================================
$availSelect = ['id', 'name'];
$availSelect[] = $hasParkType     ? 'park_type'         : "'other' AS park_type";
$availSelect[] = $hasParkCode     ? 'park_code'         : "NULL AS park_code";
$availSelect[] = $hasDescription  ? 'description'       : "NULL AS description";
$availSelect[] = $hasCenterLat    ? 'center_lat'        : "NULL AS center_lat";
$availSelect[] = $hasCenterLng    ? 'center_lng'        : "NULL AS center_lng";
$availSelect[] = $hasBoundaryGeo  ? 'boundary_geojson'  : "NULL AS boundary_geojson";
$availSelect[] = $hasBufferRadius ? 'buffer_radius'     : "500 AS buffer_radius";
if ($hasBoundaryCenter) {
    $availSelect[] = 'boundary_center_lat AS boundary_center_lat';
    $availSelect[] = 'boundary_center_lng AS boundary_center_lng';
} else {
    $availSelect[] = 'NULL AS boundary_center_lat';
    $availSelect[] = 'NULL AS boundary_center_lng';
}

$availableParks = [];
try {
    $availableParks = $pdo->query("
        SELECT " . implode(', ', $availSelect) . "
        FROM zones
        WHERE " . ($hasIsRegistered ? "is_registered = 0" : "1=1") . "
          AND is_active = 1
        ORDER BY " . ($hasParkType ? 'CASE park_type WHEN \'national_park\' THEN 1 WHEN \'gma\' THEN 2 WHEN \'other\' THEN 3 ELSE 0 END, ' : "") . "name
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('[WS-ZONE] availableParks: ' . $e->getMessage());
}

// ============================================================
// GET REGISTERED ZONES WITH SUPERVISOR COUNTS
// ============================================================
$registeredZones = [];
try {
    $registeredZones = $pdo->query("
        SELECT z.*,
               (SELECT COUNT(*) FROM users WHERE zone_id = z.id AND role = 'zone_supervisor') AS supervisor_count
        FROM zones z
        WHERE " . ($hasIsRegistered ? "z.is_registered = 1" : "1=1") . "
        ORDER BY z.name
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('[WS-ZONE] registeredZones: ' . $e->getMessage());
}

// ============================================================
// GET ALL SUPERVISORS (capped by items_per_page)
// ============================================================
$supervisors = [];
try {
    $supervisors = $pdo->query("
        SELECT u.*, z.name as zone_name, z.park_type
        FROM users u
        LEFT JOIN zones z ON u.zone_id = z.id
        WHERE u.role = 'zone_supervisor'
        ORDER BY z.name, u.full_name
        LIMIT " . (int)$itemsPerPage . "
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('[WS-ZONE] supervisors: ' . $e->getMessage());
}

$totalRegistered = count($registeredZones);
$totalAvailable  = count($availableParks);

// ============================================================
// PREPARE MAP GEOMETRY
// ============================================================
if (!function_exists('prepareZoneGeometry')) {
    function prepareZoneGeometry(array $zone, bool $hasBoundaryGeo, bool $hasBoundaryCenter, bool $hasCenterLat, bool $hasCenterLng): array {
        $out = $zone;

        if ($hasBoundaryGeo && !empty($zone['boundary_geojson'])) {
            $boundary = is_string($zone['boundary_geojson'])
                ? json_decode($zone['boundary_geojson'], true)
                : $zone['boundary_geojson'];
            if (is_array($boundary) && isset($boundary['type']) && $boundary['type'] === 'Polygon') {
                $out['boundary_geojson']  = $boundary;
                $out['is_fallback_shape'] = false;
                return $out;
            }
        }

        $lat = $zone['center_lat'] ?? $zone['boundary_center_lat'] ?? null;
        $lng = $zone['center_lng'] ?? $zone['boundary_center_lng'] ?? null;

        if (is_string($lat) && $lat !== '') $lat = (float)$lat;
        if (is_string($lng) && $lng !== '') $lng = (float)$lng;

        if (is_numeric($lat) && $lat >= -90 && $lat <= 90 && is_numeric($lng) && $lng >= -180 && $lng <= 180) {
            $radius = 2000;
            $out['boundary_geojson']  = circleGeoJSON((float)$lat, (float)$lng, $radius, 64);
            $out['is_fallback_shape'] = true;
            $out['center_lat']        = (float)$lat;
            $out['center_lng']        = (float)$lng;
        } else {
            $out['boundary_geojson']  = null;
            $out['is_fallback_shape'] = false;
        }

        return $out;
    }
}

$availableParksPrepared = array_map(
    fn($z) => prepareZoneGeometry($z, $hasBoundaryGeo, $hasBoundaryCenter, $hasCenterLat, $hasCenterLng),
    $availableParks
);

$registeredZonesPrepared = array_map(
    fn($z) => prepareZoneGeometry($z, $hasBoundaryGeo, $hasBoundaryCenter, $hasCenterLat, $hasCenterLng),
    $registeredZones
);

$showDebug = isset($_GET['debug']) && $_GET['debug'] === '1';
$zoneDebug = [
    'columns'          => array_keys($zoneCols),
    'has_center_lat'   => $hasCenterLat,
    'has_center_lng'   => $hasCenterLng,
    'has_boundary_geo' => $hasBoundaryGeo,
    'total_avail'      => count($availableParksPrepared),
    'total_reg'        => count($registeredZonesPrepared),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Zone - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }
        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .settings-echo {
            background: #eef7f1; border: 1px solid #c3e6cb;
            border-radius: 10px; padding: 12px 16px;
            margin-bottom: 16px; font-size: 12.5px;
            color: #155724;
        }
        .settings-echo strong { color: #0d3b22; }
        .settings-echo code { font-size: 11.5px; background: rgba(255,255,255,.6); padding: 1px 6px; border-radius: 4px; }
        .settings-echo .off { color: #721c24; font-weight: 700; }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-box { background: white; padding: 16px 20px; border-radius: 10px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .stat-box .number { font-size: 24px; font-weight: 700; color: #0d3b22; }
        .stat-box .label { font-size: 12px; color: #6c757d; }
        .stat-box.green .number { color: #28a745; }
        .stat-box.red .number { color: #dc3545; }
        .stat-box.blue .number { color: #007bff; }

        .register-container { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px; }
        .park-selector, .registration-form, .supervisor-section { background: white; border-radius: 12px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .park-list { max-height: 400px; overflow-y: auto; margin-top: 15px; }
        .park-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; }
        .park-item:hover { background: #f8f9fa; border-color: #1a5c3a; }
        .park-item.selected { background: #d4edda; border-color: #28a745; }
        .park-item .name { font-weight: 600; font-size: 14px; }
        .park-item .type { font-size: 12px; color: #6c757d; }
        .park-badge { padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .park-badge.national_park { background: #cce5ff; color: #004085; }
        .park-badge.gma { background: #d4edda; color: #155724; }
        .park-badge.other { background: #e8d5f5; color: #6f42c1; }
        .park-badge.new { background: #fff3cd; color: #856404; margin-left: 6px; }

        .map-container { height: 420px; border-radius: 8px; overflow: hidden; margin-bottom: 20px; }

        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 20px; }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 16px; max-width: 600px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid #e9ecef; }
        .modal-header h3 { margin: 0; }
        .modal-header .close { font-size: 28px; background: none; border: none; cursor: pointer; }

        .supervisor-card { background: white; border: 1px solid #e9ecef; border-radius: 12px; padding: 18px 22px; margin-bottom: 12px; display: flex; align-items: center; gap: 16px; transition: all 0.3s; }
        .supervisor-card:hover { box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-color: #6f42c1; }
        .supervisor-avatar { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #6f42c1, #8b5cf6); color: white; display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 600; flex-shrink: 0; }
        .supervisor-info { flex: 1; }
        .supervisor-info h4 { margin: 0; font-size: 16px; }
        .supervisor-info .meta { font-size: 12px; color: #6c757d; margin-top: 4px; }
        .supervisor-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .status-pill { padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .status-pill.active   { background: #d4edda; color: #155724; }
        .status-pill.inactive { background: #f8d7da; color: #721c24; }

        .debug-info { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 10px 14px; margin-top: 14px; font-size: 12px; color: #856404; font-family: monospace; }

        @media (max-width: 1024px) {
            .register-container { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .supervisor-card { flex-wrap: wrap; }
            .supervisor-actions { width: 100%; }
            .supervisor-actions .btn { flex: 1; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Register Zone</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <!-- Quick nav -->
                <div class="quick-nav">
                    <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
                    <a href="zones.php" class="btn btn-secondary">🗺️ Zones</a>
                    <a href="users.php" class="btn btn-secondary">👥 Users</a>
                    <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                    <a href="settings.php" class="btn btn-secondary">⚙️ Settings</a>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success"><?= $success ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <!-- Settings echo -->
                <div class="settings-echo">
                    <strong>🧾 Registration governed by System Settings:</strong>
                    Password min length: <strong><?= (int)$setPwdMinLength ?></strong>
                    • Requires:
                    <?php
                    $reqs = [];
                    if ($setPwdReqUpper) $reqs[] = 'uppercase';
                    if ($setPwdReqLower) $reqs[] = 'lowercase';
                    if ($setPwdReqNum)   $reqs[] = 'number';
                    if ($setPwdReqSym)   $reqs[] = 'symbol';
                    echo $reqs ? htmlspecialchars(implode(', ', $reqs)) : 'nothing extra';
                    ?>
                    • SMS: <strong><?= $setSmsEnabled ? 'ON' : '<span class="off">OFF</span>' ?></strong>
                    • Incident notifications: <strong><?= $setNotifyIncident ? 'ON' : '<span class="off">OFF</span>' ?></strong>
                    • AI alert notifications: <strong><?= $setNotifyAiAlert ? 'ON' : '<span class="off">OFF</span>' ?></strong>
                    • Alarm notifications: <strong><?= $setNotifyAlarm ? 'ON' : '<span class="off">OFF</span>' ?></strong>
                    <a href="settings.php" style="color:inherit;text-decoration:underline;">Change</a>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-box green">
                        <div class="number"><?= $totalRegistered ?></div>
                        <div class="label">✅ Registered Zones</div>
                    </div>
                    <div class="stat-box red">
                        <div class="number"><?= $totalAvailable ?></div>
                        <div class="label">🔴 Available Zones</div>
                    </div>
                    <div class="stat-box blue">
                        <div class="number"><?= count($supervisors) ?></div>
                        <div class="label">👤 Supervisors</div>
                    </div>
                </div>

                <!-- Map -->
                <div class="section">
                    <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <h2>🗺️ Zambia Zones Map</h2>
                        <span style="font-size:12px;color:#6c757d;">All zones — registered, available, and newly added</span>
                    </div>
                    <div class="map-container" id="zambiaMap"></div>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:10px;font-size:13px;">
                        <span>🟢 Registered (solid)</span>
                        <span>🔴 Available (dashed)</span>
                        <span>🆕 Newly added — no boundary yet (dashed circle)</span>
                    </div>
                </div>

                <!-- Register Zone Form -->
                <?php if (count($availableParksPrepared) > 0): ?>
                <div class="register-container">
                    <div class="park-selector">
                        <h3>📍 Select Park or GMA</h3>
                        <p style="color:#6c757d;font-size:13px;">Choose a park to register with supervisor</p>

                        <div class="park-list" id="parkList">
                            <?php foreach ($availableParksPrepared as $park): ?>
                            <div class="park-item"
                                 data-id="<?= (int)$park['id'] ?>"
                                 data-name="<?= htmlspecialchars($park['name'], ENT_QUOTES) ?>">
                                <div>
                                    <div class="name">
                                        📍 <?= htmlspecialchars($park['name']) ?>
                                        <?php if (!empty($park['is_fallback_shape'])): ?>
                                            <span class="park-badge new">🆕 New</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="type">
                                        <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $park['park_type']))) ?>
                                        <?= $park['park_code'] ? ' • ' . htmlspecialchars($park['park_code']) : '' ?>
                                    </div>
                                </div>
                                <span class="park-badge <?= htmlspecialchars($park['park_type']) ?>">
                                    <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $park['park_type']))) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="registration-form">
                        <h3>👤 Zone Supervisor Details</h3>
                        <p style="color:#6c757d;font-size:13px;">Create the supervisor account for this zone</p>

                        <form method="POST" id="registerForm">
                            <input type="hidden" name="action" value="register_zone">
                            <input type="hidden" name="zone_id" id="selectedZoneId">

                            <div class="form-group">
                                <label>Selected Zone</label>
                                <input type="text" id="selectedZoneName" class="form-control" readonly style="background:#f8f9fa;" placeholder="Select a park first">
                            </div>

                            <div class="form-group">
                                <label>Buffer Radius (m)</label>
                                <input type="number" name="buffer_radius" class="form-control" value="500" min="100" max="5000">
                            </div>

                            <hr style="margin:15px 0;">

                            <div class="form-group">
                                <label>Supervisor Full Name *</label>
                                <input type="text" name="supervisor_full_name" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label>Supervisor Email *</label>
                                <input type="email" name="supervisor_email" class="form-control" required>
                            </div>

                            <div class="form-group">
                                <label>Supervisor Phone</label>
                                <input type="tel" name="supervisor_phone" class="form-control" placeholder="0971234567">
                            </div>

                            <div class="form-group">
                                <label>Password *</label>
                                <input type="password" name="supervisor_password" class="form-control" required
                                       minlength="<?= (int)$setPwdMinLength ?>">
                                <small style="color:#6c757d;font-size:12px;">
                                    Min <?= (int)$setPwdMinLength ?> characters
                                    <?php if ($setPwdReqUpper): ?> • uppercase<?php endif; ?>
                                    <?php if ($setPwdReqLower): ?> • lowercase<?php endif; ?>
                                    <?php if ($setPwdReqNum): ?> • number<?php endif; ?>
                                    <?php if ($setPwdReqSym): ?> • symbol<?php endif; ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label>Confirm Password *</label>
                                <input type="password" name="supervisor_confirm_password" class="form-control" required>
                            </div>

                            <button type="submit" class="btn btn-primary btn-block" id="registerBtn" disabled>
                                🏛️ Register Zone & Create Supervisor
                            </button>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                    <div class="section">
                        <div class="empty-state" style="text-align:center;padding:40px 20px;color:#6c757d;">
                            <p>🎉 All zones are already registered. Add a new zone from <a href="zones.php">Zone Management</a> to register one here.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Manage Supervisors -->
                <div class="supervisor-section" style="margin-top:30px;">
                    <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                        <h2>👥 Zone Supervisors Management</h2>
                        <span style="font-size:13px;color:#6c757d;">Showing <?= count($supervisors) ?> of <?= count($supervisors) ?></span>
                    </div>

                    <?php if (count($supervisors) > 0): ?>
                        <?php foreach ($supervisors as $sup): ?>
                        <?php
                        $initial = mb_substr(trim((string)$sup['full_name']), 0, 1);
                        if ($initial === '') $initial = '?';
                        ?>
                        <div class="supervisor-card">
                            <div class="supervisor-avatar"><?= htmlspecialchars(mb_strtoupper($initial)) ?></div>
                            <div class="supervisor-info">
                                <h4><?= htmlspecialchars($sup['full_name']) ?></h4>
                                <div class="meta">
                                    📧 <?= htmlspecialchars($sup['email']) ?>
                                    <?php if (!empty($sup['phone'])): ?> • 📞 <?= htmlspecialchars($sup['phone']) ?><?php endif; ?>
                                    • 🏛️ <?= htmlspecialchars($sup['zone_name'] ?? 'No Zone') ?>
                                    • <span class="status-pill <?= $sup['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $sup['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                                <div style="font-size:11px;color:#adb5bd;margin-top:4px;">
                                    Last online: <?= !empty($sup['last_online']) ? timeAgo($sup['last_online']) : 'Never' ?>
                                </div>
                            </div>
                            <div class="supervisor-actions">
                                <button class="btn btn-sm btn-primary"
                                        data-supervisor="<?= htmlspecialchars(json_encode([
                                            'id'        => (int)$sup['id'],
                                            'full_name' => $sup['full_name'],
                                            'email'     => $sup['email'],
                                            'phone'     => $sup['phone'] ?? '',
                                            'zone_id'   => (int)($sup['zone_id'] ?? 0),
                                            'is_active' => (int)$sup['is_active'],
                                        ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>"
                                        onclick="editSupervisorFromBtn(this)">
                                    ✏️ Edit
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this supervisor? This cannot be undone.')">
                                    <input type="hidden" name="action" value="delete_supervisor">
                                    <input type="hidden" name="supervisor_id" value="<?= (int)$sup['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">🗑️ Delete</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><p>No supervisors registered yet</p></div>
                    <?php endif; ?>
                </div>

                <!-- Diagnostic (opt-in) -->
                <?php if ($showDebug): ?>
                <div class="debug-info">
                    🐛 Available: <?= (int)$zoneDebug['total_avail'] ?> |
                    Registered: <?= (int)$zoneDebug['total_reg'] ?> |
                    Columns: center_lat=<?= $zoneDebug['has_center_lat'] ? '✅' : '❌' ?>,
                    center_lng=<?= $zoneDebug['has_center_lng'] ? '✅' : '❌' ?>,
                    boundary_geojson=<?= $zoneDebug['has_boundary_geo'] ? '✅' : '❌' ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Edit Supervisor Modal -->
    <div class="modal" id="editSupervisorModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✏️ Edit Supervisor</h3>
                <button class="close" onclick="closeModal('editSupervisorModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_supervisor">
                <input type="hidden" name="supervisor_id" id="edit_supervisor_id">

                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" id="edit_email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" id="edit_phone" class="form-control">
                </div>

                <div class="form-group">
                    <label>Zone *</label>
                    <select name="zone_id" id="edit_zone_id" class="form-control" required>
                        <?php foreach ($registeredZones as $z): ?>
                            <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>New Password (leave empty to keep current)</label>
                    <input type="password" name="new_password" class="form-control" minlength="<?= (int)$setPwdMinLength ?>">
                    <small style="color:#6c757d;font-size:12px;">
                        Only fill to change.
                        Must be <?= (int)$setPwdMinLength ?>+ chars
                        <?php if ($setPwdReqUpper): ?> • uppercase<?php endif; ?>
                        <?php if ($setPwdReqLower): ?> • lowercase<?php endif; ?>
                        <?php if ($setPwdReqNum): ?> • number<?php endif; ?>
                        <?php if ($setPwdReqSym): ?> • symbol<?php endif; ?>
                    </small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" id="edit_is_active" value="1">
                        Active Account
                    </label>
                </div>

                <div class="alert alert-info" style="background:#d1ecf1;border:1px solid #bee5eb;color:#0c5460;padding:10px 14px;border-radius:8px;font-size:13px;">
                    ✅ Updates are applied <strong>immediately</strong> to the system.
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="margin-top:15px;">
                    💾 Update Supervisor
                </button>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // ============================================================
        // PARK SELECTION
        // ============================================================
        function selectPark(id, name) {
            document.getElementById('selectedZoneId').value = id;
            document.getElementById('selectedZoneName').value = name;
            document.getElementById('registerBtn').disabled = false;

            document.querySelectorAll('.park-item').forEach(el => {
                el.classList.remove('selected');
                if (el.dataset.id == id) el.classList.add('selected');
            });

            if (window.zoneLayers && window.zoneLayers[id]) {
                window.zoneLayers[id].eachLayer(function (l) {
                    if (l.setStyle) l.setStyle({ weight: 5, color: '#0d3b22' });
                    if (l.bringToFront) l.bringToFront();
                });
                try {
                    const bounds = window.zoneLayers[id].getBounds();
                    if (bounds.isValid()) map.fitBounds(bounds, { padding: [40, 40] });
                } catch (e) {}
            }
        }

        // Attach click handlers via data attributes (no addslashes fragility)
        document.querySelectorAll('.park-item').forEach(el => {
            el.addEventListener('click', function () {
                selectPark(this.dataset.id, this.dataset.name);
            });
        });

        // ============================================================
        // MAP SETUP
        // ============================================================
        const map = L.map('zambiaMap').setView([-14.5, 27.0], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        window.zoneLayers = {};
        const allZoneLayers = [];

        const registeredZonesData = <?= json_encode($registeredZonesPrepared, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        registeredZonesData.forEach(function (park) {
            const isFallback = !!park.is_fallback_shape;
            const geojson = park.boundary_geojson;
            if (!geojson) return;
            const layer = L.geoJSON(geojson, {
                style: {
                    color: '#28a745',
                    weight: isFallback ? 2.5 : 3,
                    fillColor: '#28a745',
                    fillOpacity: 0.20,
                    dashArray: isFallback ? '5,5' : null,
                }
            }).addTo(map);
            layer.bindPopup(
                '<strong>✅ ' + park.name + '</strong><br>Registered' +
                (isFallback ? '<br><span style="color:#856404;">🆕 Approximate area (no boundary drawn yet)</span>' : '')
            );
            window.zoneLayers[park.id] = layer;
            allZoneLayers.push(layer);
        });

        const availableZonesData = <?= json_encode($availableParksPrepared, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        availableZonesData.forEach(function (park) {
            const isFallback = !!park.is_fallback_shape;
            const geojson = park.boundary_geojson;
            if (!geojson) return;
            const layer = L.geoJSON(geojson, {
                style: {
                    color: '#dc3545',
                    weight: isFallback ? 2.5 : 3,
                    fillColor: '#dc3545',
                    fillOpacity: isFallback ? 0.20 : 0.10,
                    dashArray: '5,5',
                }
            }).addTo(map);
            layer.bindPopup(
                '<strong>🔴 ' + park.name + '</strong><br>Available for registration' +
                (isFallback ? '<br><span style="color:#856404;">🆕 Approximate area (no boundary drawn yet)</span>' : '')
            );
            window.zoneLayers[park.id] = layer;
            allZoneLayers.push(layer);
        });

        if (allZoneLayers.length > 0) {
            try {
                const group = L.featureGroup(allZoneLayers);
                const bounds = group.getBounds();
                if (bounds.isValid()) map.fitBounds(bounds, { padding: [30, 30] });
            } catch (e) { console.warn('Could not fit bounds', e); }
        }

        // ============================================================
        // SUPERVISOR MODAL
        // ============================================================
        function editSupervisorFromBtn(btn) {
            try {
                const raw = btn.getAttribute('data-supervisor');
                const sup = JSON.parse(raw);
                openEditSupervisor(sup);
            } catch (e) {
                console.error('Bad supervisor JSON', e);
                alert('Could not open editor. Please reload the page.');
            }
        }

        function openEditSupervisor(sup) {
            document.getElementById('edit_supervisor_id').value = sup.id;
            document.getElementById('edit_full_name').value = sup.full_name;
            document.getElementById('edit_email').value = sup.email;
            document.getElementById('edit_phone').value = sup.phone || '';
            document.getElementById('edit_zone_id').value = sup.zone_id;
            document.getElementById('edit_is_active').checked = sup.is_active == 1;
            document.getElementById('editSupervisorModal').classList.add('show');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        console.log('✅ Zone Registration loaded');
        console.log('📍 Registered zones on map:', registeredZonesData.length);
        console.log('📍 Available zones on map:', availableZonesData.length);
    </script>
</body>
</html>
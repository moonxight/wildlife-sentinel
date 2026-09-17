<?php
// ============================================================
// admin/zones.php
// Wildlife Sentinel — Zone Management (Admin)
// ============================================================
// Features:
//   - List all zones (filter by type, status, registration)
//   - View zone details (boundary, buffer, supervisor)
//   - Edit zone (name, description, buffer_radius, center coords)
//   - Activate / deactivate zone
//   - Delete zone (only if empty)
//   - Register a zone (links to register-zone.php)
//   - Add a NEW zone (not yet in the system) with duplicate check
//   - View zone statistics (incidents, rangers, scouts)
//   - Auto-seed Zambia's parks, GMAs and private reserves
// ============================================================

require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$pdo  = getDB();

// ============================================================
// SAFE HELPERS
// ============================================================
if (!function_exists('safeCount')) {
    function safeCount(PDO $pdo, string $sql, array $params = []): int {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)($stmt->fetch()['count'] ?? 0);
        } catch (PDOException $e) { return 0; }
    }
}
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// HELPER: Check if a zone already exists by name OR park_code
// Returns the existing zone row (id, name, park_code, park_type,
// is_registered, is_active) or false.
// ============================================================
if (!function_exists('findDuplicateZone')) {
    function findDuplicateZone(PDO $pdo, string $name, string $parkCode, int $excludeId = 0) {
        $name = trim($name);
        $parkCode = trim($parkCode);

        $clauses = [];
        $params  = [];

        if ($name !== '') {
            $clauses[] = "LOWER(TRIM(z.name)) = LOWER(?)";
            $params[]  = $name;
        }
        if ($parkCode !== '') {
            $clauses[] = "LOWER(TRIM(z.park_code)) = LOWER(?)";
            $params[]  = $parkCode;
        }

        if (empty($clauses)) return false;

        $sql = "SELECT z.id, z.name, z.park_code, z.park_type, z.is_registered, z.is_active
                FROM zones z
                WHERE (" . implode(' OR ', $clauses) . ")";
        if ($excludeId > 0) {
            $sql .= " AND z.id != ?";
            $params[] = $excludeId;
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: false;
    }
}

// ============================================================
// SEED DATA — Zambia's Parks, GMAs and Private Reserves
// (Coordinates are approximate centres so each zone is
// immediately placeable on the map via the fallback circle.)
// ============================================================
$SEED_ZONES = [
    // ---------------- NATIONAL PARKS ----------------
    ['Kafue National Park',              'national_park', 'KAF', 'Zambia\'s largest and oldest park',                      -15.000000,  25.900000],
    ['South Luangwa National Park',      'national_park', 'SLNP','Famous for walking safaris and high leopard population', -13.000000,  31.500000],
    ['Lower Zambezi National Park',      'national_park', 'LZNP','Popular for canoeing and river safaris',               -15.800000,  29.600000],
    ['Mosi-oa-Tunya National Park',      'national_park', 'MOT', 'Surrounding Victoria Falls in Livingstone',              -17.925000,  25.855000],
    ['Lusaka National Park',             'national_park', 'LNP', 'Urban getaway close to the capital',                     -15.500000,  28.383000],
    ['Liuwa Plain National Park',        'national_park', 'LPNP','Home of the massive wildebeest migration',               -14.700000,  22.700000],
    ['Kasanka National Park',            'national_park', 'KNP', 'Famous for the annual fruit bat migration',              -12.550000,  30.200000],
    ['Nsumbu National Park',             'national_park', 'NSNP','Borders the shores of Lake Tanganyika',                  -8.700000,   30.450000],
    ['North Luangwa National Park',      'national_park', 'NLNP','Remote, wilderness-focused park',                        -12.000000,  32.000000],
    ['Lochinvar National Park',          'national_park', 'LOCH','Renowned for birdwatching and Kafue lechwe',            -15.900000,  27.250000],
    ['Blue Lagoon National Park',        'national_park', 'BLNP','Floodplains and heavy bird concentrations',              -15.450000,  27.300000],
    ['Bangweulu Wetlands',               'national_park', 'BGW', 'Community-protected reserve famous for the Shoebill stork', -11.800000, 30.250000],
    ['Sioma Ngwezi National Park',       'national_park', 'SNNP','Teak forests and elephant corridors in the southwest',   -17.000000,  23.500000],
    ['West Lunga National Park',         'national_park', 'WLNP','Dense forest ecosystem in the North-Western province',   -13.000000,  24.500000],
    ['Nyika National Park',              'national_park', 'NYK', 'High-altitude montane grasslands on the Malawi border',  -10.500000,  33.500000],
    ['Isangano National Park',           'national_park', 'ISN', 'Swamp and floodplain ecosystem',                         -11.900000,  30.900000],
    ['Lavushimanda National Park',       'national_park', 'LVN', 'Scenic rocky landscapes and escarpments',                -11.500000,  31.500000],
    ['Luambe National Park',             'national_park', 'LBN', 'Small, intimate park nestled between North and South Luangwa', -12.400000, 32.200000],
    ['Lukusuzi National Park',           'national_park', 'LKS', 'Located on the eastern escarpment of the Luangwa Valley', -13.000000, 32.500000],
    ['Mweru Wantipa National Park',      'national_park', 'MWP', 'Remote park surrounding Lake Mweru Wantipa',             -8.700000,   29.500000],

    // ---------------- GAME MANAGEMENT AREAS ----------------
    ['Chiawa GMA',                       'gma', 'CHIA','Borders Lower Zambezi National Park',                     -15.900000,  29.200000],
    ['Lupande GMA',                      'gma', 'LUP', 'Borders South Luangwa National Park',                     -13.200000,  31.700000],
    ['Mumbwa GMA',                       'gma', 'MUM', 'Borders Kafue National Park',                             -15.000000,  26.500000],
    ['Kasonso-Busanga GMA',              'gma', 'KBG', 'Borders northern Kafue National Park',                    -14.000000,  25.500000],
    ['Sichifulo GMA',                    'gma', 'SIC', 'Borders southern Kafue National Park',                    -16.300000,  26.300000],
    ['Bilili GMA',                       'gma', 'BIL', 'Borders southern Kafue National Park',                    -16.100000,  26.900000],
    ['Sandwe GMA',                       'gma', 'SAN', 'Borders South Luangwa National Park',                     -13.500000,  31.200000],
    ['Chanjuzi GMA',                     'gma', 'CHJ', 'Luangwa Valley ecosystem',                                -12.300000,  32.400000],
    ['Malama GMA',                       'gma', 'MAL', 'Luangwa Valley ecosystem',                                -12.900000,  31.900000],
    ['Musonda Falls GMA',                'gma', 'MFG', 'Luapula province',                                        -10.500000,  28.800000],
    ['Chambeshi GMA',                    'gma', 'CHB', 'Northern province wetlands',                              -11.000000,  31.500000],
    ['Tondwa GMA',                       'gma', 'TON', 'Borders Nsumbu National Park',                            -8.900000,   30.300000],
    ['Kaputa GMA',                       'gma', 'KAP', 'Northern wetlands',                                       -8.500000,   29.700000],
    ['Luano GMA',                        'gma', 'LUA', 'Luano Valley escarpment',                                 -14.500000,  29.900000],

    // ---------------- PRIVATE RESERVES / RANCHES / SANCTUARIES ----------------
    ['Chaminuka Nature Reserve',         'other', 'CHA', 'Chongwe / Lusaka',                                        -15.250000,  28.700000],
    ['Lolelunga Private Reserve',        'other', 'LOL', 'Borders Kafue National Park',                             -15.300000,  26.100000],
    ['Sukulu Reserve',                   'other', 'SUK', 'Livingstone',                                             -17.850000,  25.900000],
    ['Mukalya Private Game Reserve',     'other', 'MUK', 'Lower Zambezi region',                                    -15.700000,  29.500000],
    ['Munda Wanga Environmental Park',   'other', 'MWP', 'Chilanga / Lusaka',                                       -15.500000,  28.250000],
    ['Protea Hotel Safari Lodge Game Farm', 'other', 'PRO','Chisamba',                                              -14.900000,  28.400000],
    ['Lilayi Game Ranch',                'other', 'LIL', 'Lusaka',                                                  -15.550000,  28.300000],
    ['Kanyemba Island Lodge Reserve',    'other', 'KAN', 'Zambezi River',                                           -15.800000,  30.100000],
    ['GRI Wildlife Discovery Centre',    'other', 'GRI', 'Located inside Lusaka National Park',                     -15.500000,  28.383000],
];

// ============================================================
// SEED MISSING ZONES (idempotent — skips anything that exists)
// ============================================================
$seededCount = 0;
try {
    // Only seed if the zones table exists and is queryable
    $pdo->query("SELECT 1 FROM zones LIMIT 1");

    foreach ($SEED_ZONES as $seed) {
        list($name, $type, $code, $desc, $lat, $lng) = $seed;

        // Skip if a matching zone (by name or code) already exists
        if (findDuplicateZone($pdo, $name, $code)) {
            continue;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO zones
                    (name, park_type, park_code, description, buffer_radius,
                     center_lat, center_lng, is_active, is_registered, created_at)
                VALUES
                    (?, ?, ?, ?, 500, ?, ?, 1, 0, NOW())
            ");
            $stmt->execute([$name, $type, $code, $desc, $lat, $lng]);
            $seededCount++;
        } catch (PDOException $e) {
            // Ignore individual insert failures (e.g. a unique constraint
            // on a slightly different name) so seeding never blocks the page
        }
    }

    if ($seededCount > 0) {
        try {
            logAudit($user['id'], 'seed_zones', ['count' => $seededCount]);
        } catch (Throwable $e) { /* non-fatal */ }
    }
} catch (PDOException $e) {
    // zones table missing or unreadable — page will show empty list
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($seededCount > 0) {
    $message = "🌱 Seeded {$seededCount} new zone" . ($seededCount === 1 ? '' : 's') . " into the system (marked as Available).";
    $messageType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- UPDATE ZONE --------
    if ($action === 'update') {
        $id           = (int)$_POST['zone_id'];
        $name         = trim($_POST['name'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $bufferRadius = (int)($_POST['buffer_radius'] ?? 500);
        $centerLat    = $_POST['center_lat'] !== '' ? (float)$_POST['center_lat'] : null;
        $centerLng    = $_POST['center_lng'] !== '' ? (float)$_POST['center_lng'] : null;

        if (!$name) {
            $message = 'Zone name is required.';
            $messageType = 'danger';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE zones SET
                        name = ?, description = ?, buffer_radius = ?,
                        center_lat = ?, center_lng = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description, $bufferRadius, $centerLat, $centerLng, $id]);
                logAudit($user['id'], 'update_zone', ['zone_id' => $id, 'name' => $name]);
                $message = "Zone '{$name}' updated successfully.";
            } catch (PDOException $e) {
                $message = 'Database error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // -------- TOGGLE ACTIVE --------
    if ($action === 'toggle') {
        $id = (int)$_POST['zone_id'];
        try {
            $pdo->prepare("UPDATE zones SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            logAudit($user['id'], 'toggle_zone', ['zone_id' => $id]);
            $message = 'Zone status toggled.';
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // -------- DELETE ZONE --------
    if ($action === 'delete') {
        $id = (int)$_POST['zone_id'];

        // Safety: don't allow delete if zone has users or incidents
        $userCount     = safeCount($pdo, "SELECT COUNT(*) as count FROM users WHERE zone_id = ?", [$id]);
        $incidentCount = safeCount($pdo, "SELECT COUNT(*) as count FROM incidents WHERE zone_id = ?", [$id]);

        if ($userCount > 0 || $incidentCount > 0) {
            $message = "Cannot delete: zone has {$userCount} user(s) and {$incidentCount} incident(s). Deactivate instead.";
            $messageType = 'danger';
        } else {
            try {
                $pdo->prepare("DELETE FROM zones WHERE id = ?")->execute([$id]);
                logAudit($user['id'], 'delete_zone', ['zone_id' => $id]);
                $message = 'Zone deleted.';
            } catch (PDOException $e) {
                $message = 'Delete error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // -------- ADD NEW ZONE --------
    if ($action === 'create') {
        $name         = trim($_POST['name'] ?? '');
        $parkType     = $_POST['park_type'] ?? 'other';
        $parkCode     = trim($_POST['park_code'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $bufferRadius = (int)($_POST['buffer_radius'] ?? 500);
        $centerLat    = $_POST['center_lat'] !== '' ? (float)$_POST['center_lat'] : null;
        $centerLng    = $_POST['center_lng'] !== '' ? (float)$_POST['center_lng'] : null;

        $errors = [];

        // ---- Validate name ----
        if ($name === '') {
            $errors[] = 'Zone name is required.';
        } elseif (mb_strlen($name) < 2) {
            $errors[] = 'Zone name must be at least 2 characters.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Zone name must not exceed 150 characters.';
        }

        // ---- Validate type ----
        if (!in_array($parkType, ['national_park', 'gma', 'other'], true)) {
            $errors[] = 'Please select a valid zone type.';
        }

        // ---- Validate park code (optional but must be sane if given) ----
        if ($parkCode !== '' && !preg_match('/^[A-Za-z0-9\-\_]{2,20}$/', $parkCode)) {
            $errors[] = 'Zone code must be 2–20 letters, numbers, hyphens or underscores.';
        }

        // ---- Validate buffer ----
        if ($bufferRadius < 100 || $bufferRadius > 5000) {
            $errors[] = 'Buffer radius must be between 100 and 5000 metres.';
        }

        // ---- Validate center coordinates (optional, but must be valid if given) ----
        if ($centerLat !== null && ($centerLat < -90  || $centerLat > 90)) {
            $errors[] = 'Center latitude must be between -90 and 90.';
        }
        if ($centerLng !== null && ($centerLng < -180 || $centerLng > 180)) {
            $errors[] = 'Center longitude must be between -180 and 180.';
        }

        // ---- DUPLICATE CHECK ----
        if (empty($errors)) {
            $dup = findDuplicateZone($pdo, $name, $parkCode);
            if ($dup) {
                $dupType = [
                    'national_park' => 'National Park',
                    'gma'           => 'Game Management Area',
                    'other'         => 'Zone',
                ][$dup['park_type']] ?? 'Zone';

                $reason = [];
                if (strcasecmp(trim($dup['name']), $name) === 0) {
                    $reason[] = "name \"{$dup['name']}\"";
                }
                if ($parkCode !== '' && !empty($dup['park_code']) && strcasecmp(trim($dup['park_code']), $parkCode) === 0) {
                    $reason[] = "code \"{$dup['park_code']}\"";
                }

                $regText = ((int)$dup['is_registered'] === 1) ? 'already registered' : 'available (not yet registered)';
                $message = "⚠️ A {$dupType} with the same " . implode(' and ', $reason) . " already exists in the system (ID #{$dup['id']}, status: {$regText}). "
                         . "You can register it from the list below instead of adding it again.";
                $messageType = 'danger';
            }
        }

        // ---- Insert if all good ----
        if (empty($errors) && $messageType !== 'danger') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO zones
                        (name, park_type, park_code, description, buffer_radius,
                         center_lat, center_lng, is_active, is_registered, created_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())
                ");
                $stmt->execute([
                    $name,
                    $parkType,
                    $parkCode !== '' ? $parkCode : null,
                    $description !== '' ? $description : null,
                    $bufferRadius,
                    $centerLat,
                    $centerLng,
                ]);
                $newId = (int)$pdo->query('SELECT lastval()')->fetchColumn();
                logAudit($user['id'], 'create_zone', [
                    'zone_id'   => $newId,
                    'name'      => $name,
                    'park_type' => $parkType,
                    'park_code' => $parkCode,
                ]);
                $message = "✅ Zone '{$name}' added successfully (ID #{$newId}). It is now available for registration.";
                $messageType = 'success';
            } catch (PDOException $e) {
                // Unique constraint violation is a strong hint of duplicate
                if ($e->getCode() == 23000) {
                    $message = '⚠️ A zone with the same name or code already exists in the system.';
                } else {
                    $message = 'Database error while creating zone: ' . $e->getMessage();
                }
                $messageType = 'danger';
            }
        } elseif (!empty($errors)) {
            $message = implode('<br>', $errors);
            $messageType = 'danger';
        }
    }
}

// ============================================================
// FILTERS
// ============================================================
$filterType    = $_GET['type']   ?? '';
$filterStatus  = $_GET['status'] ?? '';
$filterReg     = $_GET['reg']    ?? '';
$search        = trim($_GET['search'] ?? '');

$where  = " WHERE 1=1 ";
$params = [];

if ($filterType !== '' && in_array($filterType, ['national_park','gma','other'])) {
    $where .= " AND z.park_type = ? ";
    $params[] = $filterType;
}
if ($filterStatus === 'active')   $where .= " AND z.is_active = 1 ";
if ($filterStatus === 'inactive') $where .= " AND z.is_active = 0 ";
if ($filterReg === 'registered')  $where .= " AND z.is_registered = 1 ";
if ($filterReg === 'available')   $where .= " AND z.is_registered = 0 ";
if ($search !== '') {
    $where .= " AND (z.name LIKE ? OR z.park_code LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
}

// ============================================================
// FETCH ZONES WITH SUPERVISOR + STATS
// ============================================================
$zones = safeFetchAll($pdo, "
    SELECT z.*,
           (SELECT COUNT(*) FROM users u WHERE u.zone_id = z.id AND u.role = 'ranger' AND u.is_active = 1) AS total_rangers,
           (SELECT COUNT(*) FROM users u WHERE u.zone_id = z.id AND u.role = 'scout'  AND u.is_active = 1) AS total_scouts,
           (SELECT COUNT(*) FROM incidents i WHERE i.zone_id = z.id AND i.status NOT IN ('resolved','closed')) AS active_incidents,
           (SELECT u2.full_name FROM users u2
              WHERE u2.zone_id = z.id AND u2.role = 'zone_supervisor' AND u2.is_active = 1
              ORDER BY u2.created_at ASC LIMIT 1) AS supervisor_name,
           (SELECT u2.id FROM users u2
              WHERE u2.zone_id = z.id AND u2.role = 'zone_supervisor' AND u2.is_active = 1
              ORDER BY u2.created_at ASC LIMIT 1) AS supervisor_id
    FROM zones z
    $where
    ORDER BY CASE z.park_type WHEN 'national_park' THEN 1 WHEN 'gma' THEN 2 WHEN 'other' THEN 3 ELSE 0 END, z.name
", $params);

// ============================================================
// STATS
// ============================================================
$stats = [
    'total'        => safeCount($pdo, "SELECT COUNT(*) as count FROM zones"),
    'active'       => safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE is_active = 1"),
    'inactive'     => safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE is_active = 0"),
    'parks'        => safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE park_type = 'national_park'"),
    'gmas'         => safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE park_type = 'gma'"),
    'registered'   => safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE is_registered = 1"),
    'unregistered' => safeCount($pdo, "SELECT COUNT(*) as count FROM zones WHERE is_registered = 0 AND park_type IN ('national_park','gma')"),
];

// ============================================================
// ICONS
// ============================================================
$typeIcons = [
    'national_park' => '🏞️',
    'gma'           => '🦁',
    'other'         => '🏛️',
];

$typeLabels = [
    'national_park' => 'National Park',
    'gma'           => 'Game Management Area',
    'other'         => 'Other',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Zone Management - Admin - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 24px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 16px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }

        /* Filters */
        .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .filter-group input, .filter-group select {
            padding: 9px 12px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        /* Table */
        .zone-table { width: 100%; border-collapse: collapse; }
        .zone-table thead th {
            text-align: left; font-size: 11px; color: #6c757d;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 10px 12px; border-bottom: 2px solid #f0f0f0;
            background: #fafafa; font-weight: 700;
        }
        .zone-table tbody tr { border-bottom: 1px solid #f5f5f5; transition: background 0.15s; }
        .zone-table tbody tr:hover { background: #fafafa; }
        .zone-table td { padding: 12px; font-size: 13px; vertical-align: middle; }

        .zone-icon {
            width: 42px; height: 42px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 20px; background: #f0f7f4; flex-shrink: 0;
        }

        .status-pill {
            padding: 3px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; display: inline-block;
        }
        .status-pill.active      { background: #d4edda; color: #155724; }
        .status-pill.inactive    { background: #e9ecef; color: #495057; }
        .status-pill.registered  { background: #cce5ff; color: #004085; }
        .status-pill.available   { background: #fff3cd; color: #856404; }

        .type-pill {
            padding: 3px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; display: inline-block;
            background: #f0f7f4; color: #0d3b22;
        }

        .mini-stat {
            display: inline-block;
            padding: 2px 8px; border-radius: 8px;
            font-size: 11px; font-weight: 600;
            background: #f0f7f4; color: #0d3b22;
            margin-right: 4px;
        }
        .mini-stat.red    { background: #f8d7da; color: #721c24; }
        .mini-stat.green  { background: #d4edda; color: #155724; }
        .mini-stat.blue   { background: #cce5ff; color: #004085; }
        .mini-stat.orange { background: #fff3cd; color: #856404; }

        .supervisor-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 11px; background: #e8d5f5; color: #6f42c1;
            font-weight: 600;
        }
        .no-supervisor {
            font-size: 11px; color: #adb5bd;
            font-style: italic;
        }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }
        .empty-state h3 { font-size: 16px; color: #495057; margin-bottom: 6px; }

        /* Modal */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-backdrop.show { display: flex; }
        .modal { background: white; border-radius: 14px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-header { padding: 18px 22px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 18px; color: #0d3b22; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #6c757d; }
        .modal-body { padding: 22px; }
        .modal-footer { padding: 16px 22px; border-top: 1px solid #f0f0f0; display: flex; justify-content: flex-end; gap: 10px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; color: #495057; font-weight: 600; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
            transition: all 0.2s; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #1a5c3a; background: white; }

        /* Inline validation states */
        .form-group .field-wrap { position: relative; }
        .form-group .form-control.is-valid   { border-color: #28a745 !important; background: #f0fff4 !important; }
        .form-group .form-control.is-invalid { border-color: #dc3545 !important; background: #fff5f5 !important; }
        .field-status {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            font-size: 15px; pointer-events: none; opacity: 0; transition: opacity 0.2s;
        }
        .field-status.show { opacity: 1; }
        .field-status.valid   { color: #28a745; }
        .field-status.invalid { color: #dc3545; }
        .field-hint { font-size: 11.5px; color: #6c757d; margin-top: 4px; line-height: 1.4; }
        .field-hint.ok  { color: #28a745; }
        .field-hint.err { color: #dc3545; }
        .field-hint.checking { color: #6c757d; font-style: italic; }

        /* Duplicate warning inside the add modal */
        .dup-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            color: #856404;
            margin-bottom: 14px;
            line-height: 1.55;
        }
        .dup-warning a { color: #0d3b22; font-weight: 600; }

        @media (max-width: 1024px) {
            .zone-table thead { display: none; }
            .zone-table, .zone-table tbody, .zone-table tr, .zone-table td { display: block; width: 100%; }
            .zone-table tr { margin-bottom: 12px; padding: 12px; border-radius: 10px; background: #fafafa; border: 1px solid #f0f0f0; }
            .zone-table td { padding: 4px 0; border: none; }
            .zone-table td::before { content: attr(data-label); font-size: 10px; text-transform: uppercase; color: #adb5bd; display: block; margin-bottom: 2px; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .dashboard-greeting h1 { font-size: 22px; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Zone Management</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>🗺️ Zone Management</h1>
                    <p>Configure all registered and available zones — national parks, GMAs, and other areas.</p>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= $messageType ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon blue">🗺️</div><div class="info"><div class="number"><?= $stats['total'] ?></div><div class="label">Total Zones</div></div></div>
                    <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= $stats['active'] ?></div><div class="label">Active</div></div></div>
                    <div class="stat-card"><div class="icon orange">⚪</div><div class="info"><div class="number"><?= $stats['inactive'] ?></div><div class="label">Inactive</div></div></div>
                    <div class="stat-card"><div class="icon blue">🏞️</div><div class="info"><div class="number"><?= $stats['parks'] ?></div><div class="label">National Parks</div></div></div>
                    <div class="stat-card"><div class="icon purple">🦁</div><div class="info"><div class="number"><?= $stats['gmas'] ?></div><div class="label">GMAs</div></div></div>
                    <div class="stat-card"><div class="icon green">🛡️</div><div class="info"><div class="number"><?= $stats['registered'] ?></div><div class="label">Registered</div></div></div>
                    <div class="stat-card"><div class="icon red">🔴</div><div class="info"><div class="number"><?= $stats['unregistered'] ?></div><div class="label">Available</div></div></div>
                </div>

                <!-- Filters -->
                <div class="section">
                    <div class="section-header">
                        <h2>🔎 Filter Zones</h2>
                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <?php if ($filterType || $filterStatus || $filterReg || $search): ?>
                                <a href="zones.php" class="btn btn-secondary btn-sm">✕ Clear</a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="openAddModal()">
                                <i class="fas fa-plus"></i> Add New Zone
                            </button>
                            <a href="register-zone.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-map-marked-alt"></i> Register New Zone
                            </a>
                        </div>
                    </div>
                    <form method="GET" class="filter-bar">
                        <div class="filter-group">
                            <label>Type</label>
                            <select name="type">
                                <option value="">All Types</option>
                                <option value="national_park" <?= $filterType === 'national_park' ? 'selected' : '' ?>>National Park</option>
                                <option value="gma"           <?= $filterType === 'gma'           ? 'selected' : '' ?>>GMA</option>
                                <option value="other"         <?= $filterType === 'other'         ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All</option>
                                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Registration</label>
                            <select name="reg">
                                <option value="">All</option>
                                <option value="registered" <?= $filterReg === 'registered' ? 'selected' : '' ?>>Registered</option>
                                <option value="available"  <?= $filterReg === 'available'  ? 'selected' : '' ?>>Available</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Zone name or code">
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">🔎 Apply</button>
                        </div>
                    </form>
                </div>

                <!-- Zone List -->
                <div class="section">
                    <div class="section-header">
                        <h2>📋 Zones (<?= count($zones) ?>)</h2>
                    </div>

                    <?php if (count($zones) > 0): ?>
                        <table class="zone-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;"></th>
                                    <th>Zone</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Supervisor</th>
                                    <th>Activity</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($zones as $z): ?>
                                    <?php
                                    $icon = $typeIcons[$z['park_type']] ?? '🏛️';
                                    $isRegistered = (int)$z['is_registered'] === 1;
                                    $isActive = (int)$z['is_active'] === 1;
                                    ?>
                                    <tr>
                                        <td data-label="">
                                            <span class="zone-icon"><?= $icon ?></span>
                                        </td>
                                        <td data-label="Zone">
                                            <div style="font-weight:600;color:#0d3b22;font-size:13px;">
                                                <?= htmlspecialchars($z['name']) ?>
                                            </div>
                                            <?php if ($z['park_code']): ?>
                                                <div style="font-size:11px;color:#6c757d;">
                                                    Code: <?= htmlspecialchars($z['park_code']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($z['description']): ?>
                                                <div style="font-size:11px;color:#adb5bd;margin-top:2px;">
                                                    <?= htmlspecialchars(substr($z['description'], 0, 70)) ?>
                                                    <?= strlen($z['description']) > 70 ? '…' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Type">
                                            <span class="type-pill">
                                                <?= htmlspecialchars($typeLabels[$z['park_type']] ?? $z['park_type']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <?php if (!$isActive): ?>
                                                <span class="status-pill inactive">⚪ Inactive</span>
                                            <?php elseif ($isRegistered): ?>
                                                <span class="status-pill registered">✅ Registered</span>
                                            <?php else: ?>
                                                <span class="status-pill available">🟡 Available</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Supervisor">
                                            <?php if (!empty($z['supervisor_name'])): ?>
                                                <span class="supervisor-chip">
                                                    👤 <?= htmlspecialchars($z['supervisor_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="no-supervisor">— none —</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Activity">
                                            <span class="mini-stat green">🛡️ <?= (int)$z['total_rangers'] ?></span>
                                            <span class="mini-stat blue">👥 <?= (int)$z['total_scouts'] ?></span>
                                            <?php if ((int)$z['active_incidents'] > 0): ?>
                                                <span class="mini-stat red">🚨 <?= (int)$z['active_incidents'] ?></span>
                                            <?php endif; ?>
                                            <div style="font-size:10px;color:#adb5bd;margin-top:4px;">
                                                📏 <?= (int)($z['buffer_radius'] ?? 500) ?>m buffer
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <button class="btn btn-sm btn-secondary" onclick='openEditModal(<?= json_encode($z) ?>)'>✏️ Edit</button>
                                                <a href="map.php" class="btn btn-sm btn-info">📍 Map</a>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="zone_id" value="<?= $z['id'] ?>">
                                                    <button class="btn btn-sm <?= $isActive ? 'btn-warning' : 'btn-success' ?>">
                                                        <?= $isActive ? 'Disable' : 'Enable' ?>
                                                    </button>
                                                </form>
                                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $z['id'] ?>, '<?= htmlspecialchars($z['name']) ?>')">🗑️</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">🗺️</span>
                            <h3>No zones found</h3>
                            <p>Try changing the filters or add a new zone.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info notice -->
                <div class="section" style="border-left:4px solid #cce5ff;background:#f8fbff;">
                    <div style="font-size:13px;color:#495057;line-height:1.7;">
                        <strong>ℹ️ About Zone Management</strong><br>
                        • Zambia's national parks, GMAs and private reserves are seeded automatically on first load.<br>
                        • Use <b>Add New Zone</b> to create a zone that doesn't exist yet (e.g. a newly gazetted GMA).<br>
                        • The system checks for duplicate names and codes — if a matching zone is found you'll be prompted to register it instead.<br>
                        • A zone must be <b>Registered</b> (via <a href="register-zone.php">register-zone.php</a>) to have a zone supervisor assigned.<br>
                        • Deactivating a zone hides it from maps and dashboards but keeps all data.<br>
                        • Zones with active users or incidents cannot be deleted — deactivate instead.<br>
                        • Buffer radius defaults to 500m and is used on the live map.
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ============================================================
         EDIT MODAL
         ============================================================ -->
    <div class="modal-backdrop" id="editModal">
        <div class="modal">
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="zone_id" id="editZoneId">

                <div class="modal-header">
                    <h3>✏️ Edit Zone</h3>
                    <button type="button" class="modal-close" onclick="closeModal('editModal')">×</button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label>Zone Name *</label>
                        <input type="text" name="name" id="editName" required>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="editDescription" rows="3"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Buffer Radius (m)</label>
                            <input type="number" name="buffer_radius" id="editBufferRadius" min="100" max="5000" value="500">
                        </div>
                        <div class="form-group">
                            <label>Zone Code</label>
                            <input type="text" id="editCode" disabled style="background:#f0f0f0;">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Center Latitude</label>
                            <input type="number" step="0.000001" name="center_lat" id="editCenterLat" placeholder="-13.000000">
                        </div>
                        <div class="form-group">
                            <label>Center Longitude</label>
                            <input type="number" step="0.000001" name="center_lng" id="editCenterLng" placeholder="31.500000">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         ADD NEW ZONE MODAL
         ============================================================ -->
    <div class="modal-backdrop" id="addModal">
        <div class="modal">
            <form method="POST" id="addForm" novalidate autocomplete="off">
                <input type="hidden" name="action" value="create">

                <div class="modal-header">
                    <h3>➕ Add New Zone</h3>
                    <button type="button" class="modal-close" onclick="closeModal('addModal')">×</button>
                </div>

                <div class="modal-body">
                    <div class="dup-warning">
                        <strong>ℹ️ Note:</strong> This is for zones that <b>do not yet exist</b> in the system.
                        The system will check for duplicate names and codes before saving. If a matching
                        National Park or GMA already exists, you'll be asked to register it instead.
                    </div>

                    <div class="form-group">
                        <label for="addName">Zone Name *</label>
                        <div class="field-wrap">
                            <input type="text" name="name" id="addName" class="form-control"
                                   placeholder="e.g. Luangwa South Extension GMA" required
                                   minlength="2" maxlength="150" autocomplete="off">
                            <span class="field-status" id="addNameStatus"></span>
                        </div>
                        <div class="field-hint" id="addNameHint"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="addType">Zone Type *</label>
                            <select name="park_type" id="addType" required>
                                <option value="">Select type…</option>
                                <option value="national_park">🏞️ National Park</option>
                                <option value="gma">🦁 Game Management Area</option>
                                <option value="other">🏛️ Other</option>
                            </select>
                            <div class="field-hint" id="addTypeHint"></div>
                        </div>
                        <div class="form-group">
                            <label for="addCode">Zone Code</label>
                            <div class="field-wrap">
                                <input type="text" name="park_code" id="addCode" class="form-control"
                                       placeholder="e.g. LSE" maxlength="20" autocomplete="off">
                                <span class="field-status" id="addCodeStatus"></span>
                            </div>
                            <div class="field-hint" id="addCodeHint">
                                Optional, but recommended. 2–20 letters / numbers / - / _
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="addDescription">Description</label>
                        <textarea name="description" id="addDescription" rows="3" maxlength="500"
                                  placeholder="Short description (optional, max 500 chars)"></textarea>
                        <div class="field-hint" id="addDescHint"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="addLat">Center Latitude</label>
                            <input type="number" step="0.000001" name="center_lat" id="addLat"
                                   placeholder="-13.000000" min="-90" max="90">
                            <div class="field-hint" id="addLatHint"></div>
                        </div>
                        <div class="form-group">
                            <label for="addLng">Center Longitude</label>
                            <input type="number" step="0.000001" name="center_lng" id="addLng"
                                   placeholder="31.500000" min="-180" max="180">
                            <div class="field-hint" id="addLngHint"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="addBuffer">Buffer Radius (m)</label>
                        <input type="number" name="buffer_radius" id="addBuffer"
                               min="100" max="5000" value="500">
                        <div class="field-hint" id="addBufferHint">
                            Default 500m. Used as the monitoring buffer around the zone boundary.
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="addSubmit">➕ Add Zone</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE FORM -->
    <form method="POST" id="deleteForm" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="zone_id" id="deleteZoneId">
    </form>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // ============================================================
        // EXISTING ZONE OPEN/CLOSE (edit + delete)
        // ============================================================
        function openEditModal(zone) {
            document.getElementById('editZoneId').value    = zone.id;
            document.getElementById('editName').value      = zone.name || '';
            document.getElementById('editDescription').value = zone.description || '';
            document.getElementById('editBufferRadius').value = zone.buffer_radius || 500;
            document.getElementById('editCode').value      = zone.park_code || '—';
            document.getElementById('editCenterLat').value = zone.center_lat || '';
            document.getElementById('editCenterLng').value = zone.center_lng || '';
            document.getElementById('editModal').classList.add('show');
        }

        function closeModal(id) {
            if (typeof id === 'string') {
                document.getElementById(id).classList.remove('show');
            } else {
                // Backward compat: old code calls closeModal() with no arg
                document.getElementById('editModal').classList.remove('show');
            }
        }

        function confirmDelete(id, name) {
            if (confirm('Delete zone "' + name + '"?\n\nThis will fail if the zone has users or incidents — deactivate instead.')) {
                document.getElementById('deleteZoneId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // ============================================================
        // ADD NEW ZONE MODAL
        // ============================================================
        function openAddModal() {
            const form = document.getElementById('addForm');
            form.reset();

            // Reset validation state
            form.querySelectorAll('.form-control').forEach(el => el.classList.remove('is-valid', 'is-invalid'));
            form.querySelectorAll('.field-status').forEach(el => {
                el.classList.remove('show', 'valid', 'invalid');
                el.textContent = '';
            });
            form.querySelectorAll('.field-hint').forEach(el => {
                el.classList.remove('ok', 'err', 'checking');
                el.textContent = '';
            });

            document.getElementById('addModal').classList.add('show');
            setTimeout(() => {
                const first = document.getElementById('addName');
                if (first) first.focus();
            }, 150);
        }

        // ============================================================
        // CLIENT-SIDE VALIDATION FOR ADD FORM
        // ============================================================
        (function () {
            const form = document.getElementById('addForm');
            if (!form) return;

            const nameEl  = document.getElementById('addName');
            const typeEl  = document.getElementById('addType');
            const codeEl  = document.getElementById('addCode');
            const descEl  = document.getElementById('addDescription');
            const latEl   = document.getElementById('addLat');
            const lngEl   = document.getElementById('addLng');
            const bufEl   = document.getElementById('addBuffer');
            const submit  = document.getElementById('addSubmit');

            const s = {
                nameStatus: document.getElementById('addNameStatus'),
                nameHint:   document.getElementById('addNameHint'),
                codeStatus: document.getElementById('addCodeStatus'),
                codeHint:   document.getElementById('addCodeHint'),
                typeHint:   document.getElementById('addTypeHint'),
                latHint:    document.getElementById('addLatHint'),
                lngHint:    document.getElementById('addLngHint'),
                bufHint:    document.getElementById('addBufferHint'),
            };

            const NAME_OK = /^[\p{L}0-9\s'\-\.\(\)\/&,]+$/u;
            const CODE_OK = /^[A-Za-z0-9\-\_]{2,20}$/;

            function setState(el, statusEl, hintEl, state, msg) {
                el.classList.remove('is-valid', 'is-invalid');
                if (statusEl) statusEl.classList.remove('show', 'valid', 'invalid');
                if (hintEl) hintEl.classList.remove('ok', 'err');

                if (state === 'valid') {
                    el.classList.add('is-valid');
                    if (statusEl) { statusEl.textContent = '✓'; statusEl.classList.add('show', 'valid'); }
                    if (hintEl && msg) { hintEl.textContent = msg; hintEl.classList.add('ok'); }
                } else if (state === 'invalid') {
                    el.classList.add('is-invalid');
                    if (statusEl) { statusEl.textContent = '✕'; statusEl.classList.add('show', 'invalid'); }
                    if (hintEl && msg) { hintEl.textContent = msg; hintEl.classList.add('err'); }
                } else {
                    if (hintEl && msg) hintEl.textContent = msg;
                }
            }

            function runName() {
                const v = (nameEl.value || '').trim();
                if (!v)                { setState(nameEl, s.nameStatus, s.nameHint, 'neutral', ''); return false; }
                if (v.length < 2)      { setState(nameEl, s.nameStatus, s.nameHint, 'invalid', 'Must be at least 2 characters'); return false; }
                if (v.length > 150)    { setState(nameEl, s.nameStatus, s.nameHint, 'invalid', 'Must not exceed 150 characters'); return false; }
                if (!NAME_OK.test(v))  { setState(nameEl, s.nameStatus, s.nameHint, 'invalid', 'Only letters, numbers, spaces and basic punctuation'); return false; }
                setState(nameEl, s.nameStatus, s.nameHint, 'valid', 'Looks good');
                return true;
            }

            function runType() {
                const v = typeEl.value;
                if (!v) {
                    if (s.typeHint) { s.typeHint.textContent = 'Please choose a type'; s.typeHint.classList.add('err'); s.typeHint.classList.remove('ok'); }
                    return false;
                }
                if (s.typeHint) {
                    s.typeHint.textContent = 'Selected: ' + (typeEl.options[typeEl.selectedIndex]?.text || v);
                    s.typeHint.classList.remove('err');
                    s.typeHint.classList.add('ok');
                }
                return true;
            }

            function runCode() {
                const v = (codeEl.value || '').trim();
                if (v === '') {
                    setState(codeEl, s.codeStatus, s.codeHint, 'neutral', 'Optional, but recommended. 2–20 letters / numbers / - / _');
                    return true;
                }
                if (!CODE_OK.test(v)) {
                    setState(codeEl, s.codeStatus, s.codeHint, 'invalid', 'Use 2–20 letters, numbers, - or _ only');
                    return false;
                }
                setState(codeEl, s.codeStatus, s.codeHint, 'valid', 'Format OK');
                return true;
            }

            function runLat() {
                const v = latEl.value.trim();
                if (v === '') { if (s.latHint) { s.latHint.textContent = ''; s.latHint.classList.remove('err','ok'); } return true; }
                const n = parseFloat(v);
                if (isNaN(n) || n < -90 || n > 90) {
                    if (s.latHint) { s.latHint.textContent = 'Must be between -90 and 90'; s.latHint.classList.add('err'); s.latHint.classList.remove('ok'); }
                    return false;
                }
                if (s.latHint) { s.latHint.textContent = 'OK'; s.latHint.classList.add('ok'); s.latHint.classList.remove('err'); }
                return true;
            }

            function runLng() {
                const v = lngEl.value.trim();
                if (v === '') { if (s.lngHint) { s.lngHint.textContent = ''; s.lngHint.classList.remove('err','ok'); } return true; }
                const n = parseFloat(v);
                if (isNaN(n) || n < -180 || n > 180) {
                    if (s.lngHint) { s.lngHint.textContent = 'Must be between -180 and 180'; s.lngHint.classList.add('err'); s.lngHint.classList.remove('ok'); }
                    return false;
                }
                if (s.lngHint) { s.lngHint.textContent = 'OK'; s.lngHint.classList.add('ok'); s.lngHint.classList.remove('err'); }
                return true;
            }

            function runBuffer() {
                const n = parseInt(bufEl.value, 10);
                if (isNaN(n) || n < 100 || n > 5000) {
                    if (s.bufHint) { s.bufHint.textContent = 'Must be between 100 and 5000 metres'; s.bufHint.classList.add('err'); s.bufHint.classList.remove('ok'); }
                    return false;
                }
                if (s.bufHint) {
                    s.bufHint.textContent = 'Default 500m. Used as the monitoring buffer around the zone boundary.';
                    s.bufHint.classList.remove('err');
                    s.bufHint.classList.add('ok');
                }
                return true;
            }

            nameEl.addEventListener('input', runName);
            nameEl.addEventListener('blur',  runName);
            typeEl.addEventListener('change', runType);
            codeEl.addEventListener('input', runCode);
            codeEl.addEventListener('blur',  runCode);
            latEl.addEventListener('input', runLat);
            latEl.addEventListener('blur',  runLat);
            lngEl.addEventListener('input', runLng);
            lngEl.addEventListener('blur',  runLng);
            bufEl.addEventListener('input', runBuffer);
            bufEl.addEventListener('blur',  runBuffer);

            form.addEventListener('submit', function (e) {
                const ok =
                    runName() &
                    runType() &
                    runCode() &
                    runLat() &
                    runLng() &
                    runBuffer();

                if (!ok) {
                    e.preventDefault();
                    const firstInvalid = form.querySelector('.form-control.is-invalid') || typeEl;
                    if (firstInvalid) {
                        firstInvalid.focus();
                        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return false;
                }
                submit.disabled = true;
                submit.textContent = '⏳ Adding…';
            });
        })();

        // ============================================================
        // MODAL BACKDROP / ESC CLOSE
        // ============================================================
        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.addEventListener('click', function (e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-backdrop.show').forEach(function (m) {
                    m.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html>
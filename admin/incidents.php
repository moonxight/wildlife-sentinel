<?php
// ============================================================
// admin/incidents.php
// Wildlife Sentinel — Incidents (Admin + Supervisor + Ranger view)
// ------------------------------------------------------------
// Features:
//   - Role-scoped incident list (admin = all, supervisor = own zone, ranger = own zone)
//   - Zone summary panel (per-zone counts) — visible to admin/supervisor
//   - Zone report mode: ?report=zone (grouped) or ?report=all (all zones)
//   - Filters: status / severity / category / zone / date / search
//   - Reporter, responder(s), and zone shown on every row
//   - Inline detail drawer (description, media, timeline, assignments)
//   - Actions: acknowledge, in_progress, resolve, close
//   - CSV export of the current filter (incl. responder + zone)
// ============================================================

require_once '../includes/functions.php';
requireLogin();

if (function_exists('enforceMaintenanceMode')) enforceMaintenanceMode();
if (function_exists('applySessionTimeout'))   applySessionTimeout();

$user = getCurrentUser();
$pdo  = getDB();

// ------------------------------------------------------------
// Role scope
// ------------------------------------------------------------
$roleScope = 'all';
if (in_array($user['role'], ['zone_supervisor', 'ranger'], true)) {
    $roleScope = 'zone';
} elseif (in_array($user['role'], ['scout', 'tourism'], true)) {
    $roleScope = 'own';
}
$isAdminOrSupervisor = in_array($user['role'], ['admin', 'zone_supervisor'], true);

// ------------------------------------------------------------
// REPORT MODE
//   ''     = normal list
//   'zone' = grouped-by-zone report (respects zone filter)
//   'all'  = grouped-by-zone report across ALL zones (admin only)
// ------------------------------------------------------------
$reportMode = $_GET['report'] ?? '';
if (!in_array($reportMode, ['', 'zone', 'all'], true)) $reportMode = '';
if ($reportMode === 'all' && $user['role'] !== 'admin') $reportMode = 'zone';

// ------------------------------------------------------------
// FILTERS
// ------------------------------------------------------------
$statusFilter   = $_GET['status']   ?? '';
$severityFilter = $_GET['severity'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$zoneFilter     = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : 0;
$fromFilter     = $_GET['from'] ?? '';
$toFilter       = $_GET['to']   ?? '';
$search         = trim((string)($_GET['search'] ?? ''));

// Default limit from settings if available
$defaultLimit = 20;
if (function_exists('getSetting')) {
    $s = getSetting('items_per_page');
    if ($s !== null && (int)$s > 0) $defaultLimit = (int)$s;
}
$limit  = isset($_GET['limit']) ? max(5, min(100, (int)$_GET['limit'])) : $defaultLimit;
$page   = isset($_GET['page'])  ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// ------------------------------------------------------------
// BUILD WHERE
// ------------------------------------------------------------
$where  = ["1=1"];
$params = [];

if ($roleScope === 'zone') {
    $where[]  = "i.zone_id = ?";
    $params[] = $user['zone_id'];
} elseif ($roleScope === 'own') {
    $where[]  = "i.reporter_id = ?";
    $params[] = $user['id'];
}

if ($zoneFilter > 0 && $isAdminOrSupervisor) {
    $where[]  = "i.zone_id = ?";
    $params[] = $zoneFilter;
}
if ($statusFilter !== '')   { $where[] = "i.status = ?";   $params[] = $statusFilter; }
if ($severityFilter !== '') { $where[] = "i.severity = ?"; $params[] = $severityFilter; }
if ($categoryFilter !== '') { $where[] = "i.category = ?"; $params[] = $categoryFilter; }
if ($fromFilter !== '')     { $where[] = "i.reported_at >= ?"; $params[] = $fromFilter . ' 00:00:00'; }
if ($toFilter !== '')       { $where[] = "i.reported_at <= ?"; $params[] = $toFilter   . ' 23:59:59'; }
if ($search !== '') {
    $where[]  = "(i.description LIKE ? OR i.category LIKE ? OR i.id = ?)";
    $term     = '%' . $search . '%';
    $params[] = $term; $params[] = $term;
    $params[] = ctype_digit($search) ? (int)$search : -1;
}
$whereSql = implode(' AND ', $where);

// ------------------------------------------------------------
// CSV EXPORT (now includes responder + zone)
// ------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.category, i.severity, i.status, i.description,
                   i.location_lat, i.location_lng, i.reported_at,
                   i.acknowledged_at, i.resolved_at,
                   u.full_name AS reporter_name, u.phone AS reporter_phone,
                   z.name AS zone_name,
                   (SELECT string_agg(DISTINCT ru.full_name::text, '; ')
                      FROM incident_responses ir
                      JOIN users ru ON ir.ranger_id = ru.id
                     WHERE ir.incident_id = i.id) AS responders,
                   (SELECT string_agg(DISTINCT au.full_name::text, '; ')
                      FROM incident_assignments ia
                      JOIN users au ON ia.ranger_id = au.id
                     WHERE ia.incident_id = i.id) AS assignees
            FROM incidents i
            JOIN users u ON i.reporter_id = u.id
            LEFT JOIN zones z ON i.zone_id = z.id
            WHERE {$whereSql}
            ORDER BY i.reported_at DESC
            LIMIT 5000
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="incidents-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Zone','Category','Severity','Status','Description',
                       'Lat','Lng','Reported','Acknowledged','Resolved',
                       'Reporter','Phone','Responders','Assignees']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['zone_name'], $r['category'], $r['severity'], $r['status'],
                $r['description'],
                $r['location_lat'], $r['location_lng'],
                $r['reported_at'], $r['acknowledged_at'], $r['resolved_at'],
                $r['reporter_name'], $r['reporter_phone'],
                $r['responders'], $r['assignees'],
            ]);
        }
        fclose($out);
        exit;
    } catch (PDOException $e) {
        error_log('[WS-INCIDENTS-EXPORT] ' . $e->getMessage());
        exit;
    }
}

// ------------------------------------------------------------
// TOTAL COUNT
// ------------------------------------------------------------
$total = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM incidents i WHERE {$whereSql}");
    $stmt->execute($params);
    $total = (int)($stmt->fetch()['c'] ?? 0);
} catch (PDOException $e) {
    error_log('[WS-INCIDENTS] count: ' . $e->getMessage());
}
$totalPages = max(1, (int)ceil($total / $limit));

// ------------------------------------------------------------
// FETCH PAGE + responder/assignee aggregates
// ------------------------------------------------------------
$incidents = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, u.full_name AS reporter_name, u.phone AS reporter_phone,
               z.name AS zone_name,
               (SELECT string_agg(DISTINCT ru.full_name::text, ', ')
                  FROM incident_responses ir
                  JOIN users ru ON ir.ranger_id = ru.id
                 WHERE ir.incident_id = i.id) AS responders,
               (SELECT COUNT(*) FROM incident_responses ir WHERE ir.incident_id = i.id) AS response_count
        FROM incidents i
        JOIN users u ON i.reporter_id = u.id
        LEFT JOIN zones z ON i.zone_id = z.id
        WHERE {$whereSql}
        ORDER BY i.reported_at DESC
        LIMIT {$limit} OFFSET {$offset}
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
} catch (PDOException $e) {
    error_log('[WS-INCIDENTS] fetch: ' . $e->getMessage());
}

// ------------------------------------------------------------
// STATS (role-scoped)
// ------------------------------------------------------------
$stats = [];
$severityStats = [];
try {
    $scopeWhere  = ["1=1"];
    $scopeParams = [];
    if ($roleScope === 'zone') {
        $scopeWhere[]  = "i.zone_id = ?";
        $scopeParams[] = $user['zone_id'];
    } elseif ($roleScope === 'own') {
        $scopeWhere[]  = "i.reporter_id = ?";
        $scopeParams[] = $user['id'];
    }
    $scopeSql = implode(' AND ', $scopeWhere);

    $stmt = $pdo->prepare("SELECT i.status, COUNT(*) AS c FROM incidents i WHERE {$scopeSql} GROUP BY i.status");
    $stmt->execute($scopeParams);
    while ($row = $stmt->fetch()) $stats[$row['status']] = (int)$row['c'];

    $stmt = $pdo->prepare("SELECT i.severity, COUNT(*) AS c FROM incidents i WHERE {$scopeSql} GROUP BY i.severity");
    $stmt->execute($scopeParams);
    while ($row = $stmt->fetch()) $severityStats[$row['severity']] = (int)$row['c'];
} catch (PDOException $e) {
    error_log('[WS-INCIDENTS] stats: ' . $e->getMessage());
}

// ------------------------------------------------------------
// ZONE SUMMARY (per-zone counts) — admin/supervisor only
// ------------------------------------------------------------
$zoneSummary = [];
if ($isAdminOrSupervisor) {
    try {
        // Scope: admin sees all; supervisor sees only their zone
        $zScope  = "z.is_active = 1";
        $zParams = [];
        if ($user['role'] === 'zone_supervisor') {
            $zScope .= " AND z.id = ?";
            $zParams[] = $user['zone_id'];
        }
        $stmt = $pdo->prepare("
            SELECT z.id, z.name,
                   COUNT(i.id) AS total,
                   SUM(CASE WHEN i.status = 'reported'    THEN 1 ELSE 0 END) AS reported,
                   SUM(CASE WHEN i.status = 'acknowledged'THEN 1 ELSE 0 END) AS acknowledged,
                   SUM(CASE WHEN i.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                   SUM(CASE WHEN i.status = 'resolved'    THEN 1 ELSE 0 END) AS resolved,
                   SUM(CASE WHEN i.status = 'closed'      THEN 1 ELSE 0 END) AS closed,
                   SUM(CASE WHEN i.severity = 'critical'  THEN 1 ELSE 0 END) AS critical,
                   MAX(i.reported_at) AS last_incident_at
            FROM zones z
            LEFT JOIN incidents i ON i.zone_id = z.id
            WHERE {$zScope}
            GROUP BY z.id, z.name
            ORDER BY z.name
        ");
        $stmt->execute($zParams);
        $zoneSummary = $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('[WS-INCIDENTS] zone summary: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// ZONE REPORT (grouped incidents) — for ?report=zone or ?report=all
// ------------------------------------------------------------
$zoneReport = [];
if ($reportMode !== '') {
    try {
        // Build a report query that always includes zone even when
        // roleScope = 'zone' (supervisor's own zone).
        $rWhere  = ["1=1"];
        $rParams = [];
        if ($user['role'] === 'zone_supervisor') {
            $rWhere[]  = "i.zone_id = ?";
            $rParams[] = $user['zone_id'];
        } elseif ($roleScope === 'own') {
            $rWhere[]  = "i.reporter_id = ?";
            $rParams[] = $user['id'];
        }
        if ($reportMode === 'zone' && $zoneFilter > 0) {
            $rWhere[]  = "i.zone_id = ?";
            $rParams[] = $zoneFilter;
        }
        // Reuse the same filters as the list view
        if ($statusFilter !== '')   { $rWhere[] = "i.status = ?";   $rParams[] = $statusFilter; }
        if ($severityFilter !== '') { $rWhere[] = "i.severity = ?"; $rParams[] = $severityFilter; }
        if ($categoryFilter !== '') { $rWhere[] = "i.category = ?"; $rParams[] = $categoryFilter; }
        if ($fromFilter !== '')     { $rWhere[] = "i.reported_at >= ?"; $rParams[] = $fromFilter . ' 00:00:00'; }
        if ($toFilter !== '')       { $rWhere[] = "i.reported_at <= ?"; $rParams[] = $toFilter   . ' 23:59:59'; }
        $rWhereSql = implode(' AND ', $rWhere);

        $stmt = $pdo->prepare("
            SELECT i.id, i.category, i.severity, i.status, i.description,
                   i.reported_at, i.acknowledged_at, i.resolved_at,
                   i.zone_id, z.name AS zone_name,
                   u.full_name AS reporter_name, u.phone AS reporter_phone,
                   (SELECT string_agg(DISTINCT ru.full_name::text, ', ')
                      FROM incident_responses ir
                      JOIN users ru ON ir.ranger_id = ru.id
                     WHERE ir.incident_id = i.id) AS responders
            FROM incidents i
            JOIN users u ON i.reporter_id = u.id
            LEFT JOIN zones z ON i.zone_id = z.id
            WHERE {$rWhereSql}
            ORDER BY z.name ASC, i.reported_at DESC
            LIMIT 1000
        ");
        $stmt->execute($rParams);
        $rows = $stmt->fetchAll() ?: [];

        // Group by zone
        foreach ($rows as $r) {
            $zKey = $r['zone_id'] ?: 0;
            $zName = $r['zone_name'] ?: 'Unassigned Zone';
            if (!isset($zoneReport[$zKey])) {
                $zoneReport[$zKey] = [
                    'zone_id'   => $zKey,
                    'zone_name' => $zName,
                    'incidents' => [],
                    'totals'    => ['reported'=>0,'acknowledged'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0],
                    'critical'  => 0,
                ];
            }
            $zoneReport[$zKey]['incidents'][] = $r;
            if (isset($zoneReport[$zKey]['totals'][$r['status']])) {
                $zoneReport[$zKey]['totals'][$r['status']]++;
            }
            if ($r['severity'] === 'critical') $zoneReport[$zKey]['critical']++;
        }
    } catch (PDOException $e) {
        error_log('[WS-INCIDENTS] zone report: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// ZONES FOR FILTER
// ------------------------------------------------------------
$zones = [];
try {
    if ($user['role'] === 'zone_supervisor') {
        $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE is_active = 1 AND id = ? ORDER BY name");
        $stmt->execute([$user['zone_id']]);
        $zones = $stmt->fetchAll() ?: [];
    } else {
        $zones = $pdo->query("SELECT id, name FROM zones WHERE is_active = 1 ORDER BY name")->fetchAll() ?: [];
    }
} catch (PDOException $e) { /* non-fatal */ }

// ------------------------------------------------------------
// Query string for pagination / export / report links
// ------------------------------------------------------------
$qs = $_GET;
unset($qs['page'], $qs['export']);
$qsStr = http_build_query($qs);

// Helper for building URLs while keeping the filter state
function ws_url(array $overrides = []): string {
    $q = $_GET;
    unset($q['export']);
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
        else $q[$k] = $v;
    }
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0d3b22">
    <title>Incidents — Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        /* ... existing styles unchanged ... */
        .stats-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .stat-chip { background: white; padding: 8px 16px; border-radius: 20px; border: 1px solid var(--gray-300); font-size: 13px; display: flex; align-items: center; gap: 6px; flex-shrink: 0; transition: background 0.4s; }
        .stat-chip .count { font-weight: 700; color: var(--primary); }
        .stat-chip .count.danger  { color: var(--danger); }
        .stat-chip .count.warning { color: var(--warning); }
        .stat-chip .count.success { color: var(--success); }
        .stat-chip .count.info    { color: var(--info); }
        .stat-chip.flash { animation: statFlash 0.8s ease; }
        @keyframes statFlash { 0%,100% { background: white; } 50% { background: #e8f5ec; } }

        .filter-bar { background: white; padding: 15px 18px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; align-items: end; }
        .filter-grid .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-grid .filter-group label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-600); font-weight: 600; }
        .filter-grid select, .filter-grid input { padding: 8px 14px; border: 2px solid var(--gray-300); border-radius: 8px; font-size: 14px; background: white; min-height: 42px; width: 100%; }
        .filter-grid select:focus, .filter-grid input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px rgba(26, 92, 58, 0.1); }
        .filter-actions { display: flex; gap: 8px; grid-column: 1 / -1; justify-content: flex-end; flex-wrap: wrap; }

        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
        .toolbar .toolbar-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .toolbar .toolbar-title h2 { font-size: 18px; color: var(--gray-800); }
        .toolbar .toolbar-title .muted { font-size: 13px; color: var(--gray-500); }

        /* ---------- ZONE SUMMARY ---------- */
        .zone-summary { margin-bottom: 20px; }
        .zone-summary h3 { font-size: 15px; color: #0d3b22; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
        .zone-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
        .zone-card { background: white; border-radius: 12px; padding: 14px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; transition: transform 0.2s, box-shadow 0.2s; }
        .zone-card:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(0,0,0,0.1); }
        .zone-card .zone-name { font-size: 14px; font-weight: 700; color: #0d3b22; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .zone-card .zone-name .zone-total { font-size: 11px; background: #e8f5ec; color: #1a5c3a; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
        .zone-card .zone-stats { display: flex; flex-wrap: wrap; gap: 8px; font-size: 11.5px; }
        .zone-card .zone-stat { display: flex; align-items: center; gap: 4px; color: #495057; }
        .zone-card .zone-stat strong { color: #0d3b22; }
        .zone-card .zone-critical { color: #dc3545; font-weight: 700; }
        .zone-card .zone-last { font-size: 11px; color: #adb5bd; margin-top: 8px; }

        /* ---------- ZONE REPORT ---------- */
        .report-toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; }
        .report-toolbar .report-tab { padding: 8px 16px; border-radius: 20px; border: 1px solid var(--gray-300); background: white; color: var(--gray-700); text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.2s; }
        .report-toolbar .report-tab:hover { border-color: var(--primary); color: var(--primary); }
        .report-toolbar .report-tab.active { background: var(--primary); color: white; border-color: var(--primary); }
        .zone-report-block { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 18px; overflow: hidden; }
        .zone-report-block .zr-header { background: #f8faf9; padding: 12px 18px; border-bottom: 1px solid #eef2f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .zone-report-block .zr-header h4 { font-size: 15px; color: #0d3b22; display: flex; align-items: center; gap: 8px; }
        .zone-report-block .zr-header .zr-totals { display: flex; gap: 10px; flex-wrap: wrap; font-size: 11.5px; color: #495057; }
        .zone-report-block .zr-header .zr-totals span { background: #eef2f0; padding: 2px 10px; border-radius: 10px; }
        .zone-report-block .zr-header .zr-totals .crit { background: #f8d7da; color: #721c24; font-weight: 700; }
        .zone-report-block table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .zone-report-block table th { text-align: left; padding: 9px 14px; background: #fafbfa; color: #495057; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #eef2f0; }
        .zone-report-block table td { padding: 10px 14px; border-bottom: 1px solid #f5f7f6; vertical-align: top; }
        .zone-report-block table tr:last-child td { border-bottom: none; }
        .zone-report-block table tr:hover td { background: #fafbfa; }
        .zone-report-block .rep-cell { color: var(--gray-700); }
        .zone-report-block .muted-sm { font-size: 11.5px; color: var(--gray-500); }
        .no-report { text-align: center; padding: 40px 20px; color: var(--gray-500); }

        .incident-list { display: flex; flex-direction: column; }
        .incident-item { display: flex; align-items: flex-start; padding: 14px 4px; border-bottom: 1px solid var(--gray-200); gap: 14px; transition: background 0.2s; border-radius: 8px; }
        .incident-item:hover { background: var(--gray-100); }
        .incident-item:last-child { border-bottom: none; }
        .incident-item .incident-status { flex-shrink: 0; padding-top: 2px; }
        .incident-item .incident-details { flex: 1; min-width: 0; }
        .incident-item .incident-details h4 { font-size: 15px; font-weight: 600; margin-bottom: 2px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .incident-item .incident-details h4 .incident-id { font-weight: 400; font-size: 13px; color: var(--gray-500); }
        .incident-item .incident-details p { font-size: 13px; color: var(--gray-600); margin-bottom: 6px; line-height: 1.5; }
        .incident-item .incident-meta { display: flex; gap: 15px; font-size: 12px; color: var(--gray-500); flex-wrap: wrap; }
        .incident-item .incident-meta span { display: flex; align-items: center; gap: 4px; }
        .incident-item .badges { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; align-items: center; }

        .incident-detail { display: none; background: var(--gray-100); padding: 16px 18px; border-radius: 10px; margin-top: 12px; border-left: 3px solid var(--primary); }
        .incident-detail.show { display: block; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .incident-detail .detail-row { display: flex; gap: 20px; flex-wrap: wrap; margin: 6px 0; font-size: 14px; }
        .incident-detail .detail-row strong { min-width: 130px; color: var(--gray-700); }
        .incident-detail .detail-row .value { color: var(--gray-600); }

        .incident-detail .media-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .incident-detail .media-grid a { display: block; width: 80px; height: 80px; border-radius: 8px; overflow: hidden; border: 1px solid var(--gray-300); }
        .incident-detail .media-grid img { width: 100%; height: 100%; object-fit: cover; }

        .incident-detail .timeline { margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--gray-300); }
        .incident-detail .timeline-item { display: flex; gap: 10px; padding: 6px 0; font-size: 13px; color: var(--gray-700); }
        .incident-detail .timeline-item .tl-time { color: var(--gray-500); font-size: 11.5px; white-space: nowrap; min-width: 90px; }
        .incident-detail .timeline-empty { font-size: 12.5px; color: var(--gray-500); font-style: italic; padding: 6px 0; }

        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .action-buttons .btn-small { min-height: 34px; padding: 6px 14px; }

        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
        .pagination .page-link { padding: 8px 14px; border: 1px solid var(--gray-300); border-radius: 8px; text-decoration: none; color: var(--gray-700); transition: all 0.2s; min-height: 40px; min-width: 40px; display: flex; align-items: center; justify-content: center; background: white; }
        .pagination .page-link:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination .page-link.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination .page-link.disabled { opacity: 0.4; pointer-events: none; }

        .alert.dismissible { position: relative; padding-right: 40px; }
        .alert .alert-close { position: absolute; top: 8px; right: 10px; background: none; border: none; font-size: 18px; cursor: pointer; color: inherit; opacity: 0.6; }
        .alert .alert-close:hover { opacity: 1; }

        .toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 340px; width: 100%; }
        .toast { padding: 12px 18px; border-radius: 10px; color: white; font-size: 13px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; animation: slideInRight 0.3s ease; }
        .toast-success { background: var(--success); }
        .toast-danger  { background: var(--danger); }
        .toast-info    { background: var(--info); }
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

        @media (max-width: 768px) {
            .filter-grid { grid-template-columns: 1fr 1fr; }
            .filter-actions { justify-content: stretch; }
            .filter-actions .btn { flex: 1; justify-content: center; }
            .incident-item { flex-wrap: wrap; padding: 12px 4px; }
            .incident-item .badges { width: 100%; justify-content: flex-start; margin-top: 6px; }
            .action-buttons { width: 100%; }
            .action-buttons .btn-small { flex: 1; justify-content: center; }
            .incident-detail .detail-row { flex-direction: column; gap: 2px; }
            .incident-detail .detail-row strong { min-width: auto; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar .toolbar-actions { display: flex; gap: 8px; }
            .toolbar .toolbar-actions .btn { flex: 1; justify-content: center; }
            .zone-report-block table { font-size: 12px; }
            .zone-report-block table th, .zone-report-block table td { padding: 8px 8px; }
        }
        @media (max-width: 480px) {
            .filter-grid { grid-template-columns: 1fr; }
            .toast-container { left: 12px; right: 12px; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>Incidents</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">

                <!-- STATS -->
                <div class="stats-row" id="statsRow">
                    <span class="stat-chip">📊 Total: <span class="count" id="statTotal"><?= $total ?></span></span>
                    <span class="stat-chip">🚨 Reported: <span class="count danger" id="statReported"><?= $stats['reported'] ?? 0 ?></span></span>
                    <span class="stat-chip">⏳ Acknowledged: <span class="count warning" id="statAck"><?= $stats['acknowledged'] ?? 0 ?></span></span>
                    <span class="stat-chip">🔄 In Progress: <span class="count info" id="statProgress"><?= $stats['in_progress'] ?? 0 ?></span></span>
                    <span class="stat-chip">✅ Resolved: <span class="count success" id="statResolved"><?= $stats['resolved'] ?? 0 ?></span></span>
                    <span class="stat-chip">📌 Closed: <span class="count" id="statClosed"><?= $stats['closed'] ?? 0 ?></span></span>
                </div>

                <!-- ZONE SUMMARY (admin + supervisor) -->
                <?php if ($isAdminOrSupervisor && count($zoneSummary) > 0): ?>
                <div class="zone-summary">
                    <h3>🏛️ Zone Summary</h3>
                    <div class="zone-cards">
                        <?php foreach ($zoneSummary as $z): ?>
                        <div class="zone-card">
                            <div class="zone-name">
                                <span><?= htmlspecialchars($z['name']) ?></span>
                                <span class="zone-total"><?= (int)$z['total'] ?> total</span>
                            </div>
                            <div class="zone-stats">
                                <span class="zone-stat">🚨 <strong><?= (int)$z['reported'] ?></strong></span>
                                <span class="zone-stat">⏳ <strong><?= (int)$z['acknowledged'] ?></strong></span>
                                <span class="zone-stat">🔄 <strong><?= (int)$z['in_progress'] ?></strong></span>
                                <span class="zone-stat">✅ <strong><?= (int)$z['resolved'] ?></strong></span>
                                <span class="zone-stat">📌 <strong><?= (int)$z['closed'] ?></strong></span>
                                <span class="zone-stat zone-critical">⚠️ <?= (int)$z['critical'] ?> critical</span>
                            </div>
                            <div class="zone-last">
                                <?php if ($z['last_incident_at']): ?>
                                    Last incident: <?= timeAgo($z['last_incident_at']) ?>
                                <?php else: ?>
                                    No incidents yet
                                <?php endif; ?>
                            </div>
                            <div style="margin-top:8px;">
                                <a href="<?= htmlspecialchars(ws_url(['report'=>'zone','zone_id'=>$z['id'],'page'=>null])) ?>"
                                   class="btn btn-secondary btn-small" style="font-size:11.5px;padding:4px 10px;">
                                    📄 View zone report
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- FILTERS -->
                <div class="filter-bar">
                    <form method="GET" id="filterForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="">All</option>
                                    <?php foreach (['reported','acknowledged','in_progress','resolved','closed'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Severity</label>
                                <select name="severity">
                                    <option value="">All</option>
                                    <?php foreach (['low','medium','high','critical'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $severityFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Category</label>
                                <select name="category">
                                    <option value="">All</option>
                                    <?php foreach (['poaching','distressed_animal','human_wildlife_conflict','environmental_risk','other'] as $c): ?>
                                    <option value="<?= $c ?>" <?= $categoryFilter === $c ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$c)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($isAdminOrSupervisor): ?>
                            <div class="filter-group">
                                <label>Zone</label>
                                <select name="zone_id">
                                    <option value="0">All Zones</option>
                                    <?php foreach ($zones as $z): ?>
                                    <option value="<?= (int)$z['id'] ?>" <?= $zoneFilter === (int)$z['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($z['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="filter-group">
                                <label>From</label>
                                <input type="date" name="from" value="<?= htmlspecialchars($fromFilter) ?>">
                            </div>
                            <div class="filter-group">
                                <label>To</label>
                                <input type="date" name="to" value="<?= htmlspecialchars($toFilter) ?>">
                            </div>
                            <div class="filter-group" style="grid-column: span 2;">
                                <label>Search</label>
                                <input type="text" name="search" placeholder="Description, category or ID"
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <?php if ($reportMode !== ''): ?>
                                <input type="hidden" name="report" value="<?= htmlspecialchars($reportMode) ?>">
                            <?php endif; ?>
                            <div class="filter-actions">
                                <a href="incidents.php" class="btn btn-secondary">✕ Clear</a>
                                <button type="submit" class="btn btn-primary">🔎 Apply</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- REPORT TABS -->
                <?php if ($isAdminOrSupervisor): ?>
                <div class="report-toolbar">
                    <a href="<?= htmlspecialchars(ws_url(['report'=>null,'page'=>null])) ?>"
                       class="report-tab <?= $reportMode === '' ? 'active' : '' ?>">📋 List view</a>
                    <a href="<?= htmlspecialchars(ws_url(['report'=>'zone','page'=>null])) ?>"
                       class="report-tab <?= $reportMode === 'zone' ? 'active' : '' ?>">🏛️ Zone report</a>
                    <?php if ($user['role'] === 'admin'): ?>
                    <a href="<?= htmlspecialchars(ws_url(['report'=>'all','page'=>null])) ?>"
                       class="report-tab <?= $reportMode === 'all' ? 'active' : '' ?>">🌍 All zones report</a>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars(ws_url(['report'=>$reportMode ?: null, 'export'=>'csv'])) ?>"
                       class="report-tab" style="margin-left:auto;">⬇️ Export CSV</a>
                </div>
                <?php endif; ?>

                <?php if ($reportMode !== ''): ?>
                    <!-- ============================================================
                         ZONE REPORT VIEW
                         ============================================================ -->
                    <?php if (count($zoneReport) > 0): ?>
                        <?php foreach ($zoneReport as $block): ?>
                        <div class="zone-report-block">
                            <div class="zr-header">
                                <h4>🏛️ <?= htmlspecialchars($block['zone_name']) ?>
                                    <span class="muted-sm">(<?= count($block['incidents']) ?> incidents)</span>
                                </h4>
                                <div class="zr-totals">
                                    <span>🚨 <?= $block['totals']['reported'] ?></span>
                                    <span>⏳ <?= $block['totals']['acknowledged'] ?></span>
                                    <span>🔄 <?= $block['totals']['in_progress'] ?></span>
                                    <span>✅ <?= $block['totals']['resolved'] ?></span>
                                    <span>📌 <?= $block['totals']['closed'] ?></span>
                                    <?php if ($block['critical'] > 0): ?>
                                        <span class="crit">⚠️ <?= $block['critical'] ?> critical</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="overflow-x:auto;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Reported</th>
                                            <th>Category</th>
                                            <th>Severity</th>
                                            <th>Status</th>
                                            <th>Reporter</th>
                                            <th>Responder(s)</th>
                                            <th>Summary</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($block['incidents'] as $r): ?>
                                        <tr>
                                            <td>#<?= (int)$r['id'] ?></td>
                                            <td>
                                                <div class="rep-cell"><?= date('M j, Y', strtotime($r['reported_at'])) ?></div>
                                                <div class="muted-sm"><?= date('H:i', strtotime($r['reported_at'])) ?></div>
                                            </td>
                                            <td><?= getCategoryIcon($r['category']) ?> <?= ucfirst(str_replace('_',' ', $r['category'])) ?></td>
                                            <td><?= getSeverityBadge($r['severity']) ?></td>
                                            <td><?= getStatusBadge($r['status']) ?></td>
                                            <td>
                                                <div class="rep-cell"><?= htmlspecialchars($r['reporter_name'] ?? '—') ?></div>
                                                <?php if (!empty($r['reporter_phone'])): ?>
                                                    <div class="muted-sm">📞 <?= htmlspecialchars($r['reporter_phone']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($r['responders'])): ?>
                                                    <span class="rep-cell"><?= htmlspecialchars($r['responders']) ?></span>
                                                <?php else: ?>
                                                    <span class="muted-sm">— none yet —</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="muted-sm" style="max-width:280px;">
                                                <?= htmlspecialchars(mb_substr($r['description'] ?? '', 0, 100)) ?>
                                                <?= mb_strlen($r['description'] ?? '') > 100 ? '…' : '' ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-report">
                            <div style="font-size:48px;margin-bottom:10px;">📭</div>
                            <h3>No incidents to report</h3>
                            <p>No incidents match the current filters<?= $reportMode === 'zone' && $zoneFilter ? ' for the selected zone' : '' ?>.</p>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- ============================================================
                         NORMAL LIST VIEW
                         ============================================================ -->
                    <div class="toolbar">
                        <div class="toolbar-title">
                            <h2>📋 Incidents</h2>
                            <span class="muted">
                                Showing <?= count($incidents) ?> of <?= $total ?>
                                • Page <?= $page ?> of <?= $totalPages ?>
                            </span>
                        </div>
                        <div class="toolbar-actions">
                            <a href="<?= htmlspecialchars(ws_url(['export'=>'csv'])) ?>"
                               class="btn btn-secondary btn-small">⬇️ CSV</a>
                            <button class="btn btn-secondary btn-small" onclick="refreshList()">🔄 Refresh</button>
                        </div>
                    </div>

                    <div class="section">
                        <div class="incident-list" id="incidentList">
                            <?php if (count($incidents) > 0): ?>
                                <?php foreach ($incidents as $incident): ?>
                                <div class="incident-item" data-id="<?= (int)$incident['id'] ?>">
                                    <div class="incident-status"><?= getStatusBadge($incident['status']) ?></div>
                                    <div class="incident-details">
                                        <h4>
                                            <?= getCategoryIcon($incident['category']) ?>
                                            <?= ucfirst(str_replace('_', ' ', $incident['category'])) ?>
                                            <span class="incident-id">#<?= (int)$incident['id'] ?></span>
                                        </h4>
                                        <p>
                                            <?= htmlspecialchars(mb_substr($incident['description'] ?? '', 0, 140)) ?>
                                            <?= mb_strlen($incident['description'] ?? '') > 140 ? '…' : '' ?>
                                        </p>
                                        <div class="incident-meta">
                                            <span>📍 <?= round($incident['location_lat'], 4) ?>, <?= round($incident['location_lng'], 4) ?></span>
                                            <span>👤 Reporter: <?= htmlspecialchars($incident['reporter_name']) ?></span>
                                            <?php if (!empty($incident['reporter_phone'])): ?>
                                            <span>📞 <?= htmlspecialchars($incident['reporter_phone']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($isAdminOrSupervisor): ?>
                                            <span>🏛️ Zone: <?= htmlspecialchars($incident['zone_name'] ?? 'N/A') ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($incident['responders'])): ?>
                                            <span>🛡️ Responder(s): <?= htmlspecialchars($incident['responders']) ?></span>
                                            <?php elseif ((int)($incident['response_count'] ?? 0) === 0): ?>
                                            <span style="color:var(--warning);">⏳ No responder yet</span>
                                            <?php endif; ?>
                                            <span>🕐 <?= timeAgo($incident['reported_at']) ?></span>
                                        </div>

                                        <div class="action-buttons">
                                            <?php if (in_array($user['role'], ['ranger','zone_supervisor','admin'], true)): ?>
                                                <?php if ($incident['status'] === 'reported'): ?>
                                                <button class="btn-small btn-primary" onclick="acknowledgeIncident(<?= (int)$incident['id'] ?>)">✅ Acknowledge</button>
                                                <?php endif; ?>
                                                <?php if ($incident['status'] === 'acknowledged'): ?>
                                                <button class="btn-small btn-warning" onclick="updateStatus(<?= (int)$incident['id'] ?>, 'in_progress')">🔄 Start Response</button>
                                                <?php endif; ?>
                                                <?php if ($incident['status'] === 'in_progress'): ?>
                                                <button class="btn-small btn-success" onclick="updateStatus(<?= (int)$incident['id'] ?>, 'resolved')">✅ Resolve</button>
                                                <?php endif; ?>
                                                <?php if ($incident['status'] === 'resolved'): ?>
                                                <button class="btn-small btn-secondary" onclick="updateStatus(<?= (int)$incident['id'] ?>, 'closed')">📌 Close</button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <button class="btn-small" onclick="toggleDetails(<?= (int)$incident['id'] ?>)">📋 Details</button>
                                        </div>

                                        <div class="incident-detail" id="detail-<?= (int)$incident['id'] ?>"
                                             data-lat="<?= htmlspecialchars($incident['location_lat']) ?>"
                                             data-lng="<?= htmlspecialchars($incident['location_lng']) ?>"
                                             data-reporter="<?= htmlspecialchars($incident['reporter_name']) ?>"
                                             data-reported="<?= htmlspecialchars($incident['reported_at']) ?>"
                                             data-loaded="0">
                                            <div class="detail-row">
                                                <strong>Reported by:</strong>
                                                <span class="value">
                                                    <?= htmlspecialchars($incident['reporter_name']) ?>
                                                    <?php if (!empty($incident['reporter_phone'])): ?>
                                                        (<?= htmlspecialchars($incident['reporter_phone']) ?>)
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <?php if ($isAdminOrSupervisor): ?>
                                            <div class="detail-row">
                                                <strong>Zone:</strong>
                                                <span class="value"><?= htmlspecialchars($incident['zone_name'] ?? 'N/A') ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="detail-row">
                                                <strong>Responder(s):</strong>
                                                <span class="value">
                                                    <?= !empty($incident['responders'])
                                                        ? htmlspecialchars($incident['responders'])
                                                        : '<em style="color:var(--gray-500);">No responder assigned yet</em>' ?>
                                                </span>
                                            </div>
                                            <div class="detail-row">
                                                <strong>Description:</strong>
                                                <span class="value"><?= nl2br(htmlspecialchars($incident['description'] ?? '')) ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <strong>Severity:</strong>
                                                <span class="value"><?= getSeverityBadge($incident['severity']) ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <strong>Reported:</strong>
                                                <span class="value"><?= date('Y-m-d H:i:s', strtotime($incident['reported_at'])) ?></span>
                                            </div>
                                            <?php if (!empty($incident['acknowledged_at'])): ?>
                                            <div class="detail-row">
                                                <strong>Acknowledged:</strong>
                                                <span class="value"><?= date('Y-m-d H:i:s', strtotime($incident['acknowledged_at'])) ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($incident['resolved_at'])): ?>
                                            <div class="detail-row">
                                                <strong>Resolved:</strong>
                                                <span class="value"><?= date('Y-m-d H:i:s', strtotime($incident['resolved_at'])) ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="detail-row">
                                                <strong>Location:</strong>
                                                <span class="value">
                                                    <a href="https://www.google.com/maps?q=<?= $incident['location_lat'] ?>,<?= $incident['location_lng'] ?>"
                                                       target="_blank" rel="noopener" style="color:var(--primary);">
                                                        View on Google Maps →
                                                    </a>
                                                    &nbsp;|&nbsp;
                                                    <a href="map.php?focus_incident=<?= (int)$incident['id'] ?>" style="color:var(--primary);">
                                                        View on Live Map →
                                                    </a>
                                                </span>
                                            </div>

                                            <?php if (!empty($incident['media_urls'])): ?>
                                            <div class="detail-row">
                                                <strong>Media:</strong>
                                                <span class="value">
                                                    <div class="media-grid">
                                                        <?php foreach ($incident['media_urls'] as $url): ?>
                                                            <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">
                                                                <img src="<?= htmlspecialchars($url) ?>" alt="incident media">
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </span>
                                            </div>
                                            <?php endif; ?>

                                            <div class="timeline" id="timeline-<?= (int)$incident['id'] ?>">
                                                <div class="timeline-empty">Loading responses…</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="badges"><?= getSeverityBadge($incident['severity']) ?></div>
                                </div>
                                <?php endforeach; ?>

                                <?php if ($totalPages > 1): ?>
                                <div class="pagination">
                                    <?php if ($page > 1): ?>
                                        <a class="page-link" href="<?= htmlspecialchars(ws_url(['page'=>$page-1])) ?>">←</a>
                                    <?php else: ?>
                                        <span class="page-link disabled">←</span>
                                    <?php endif; ?>
                                    <?php $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
                                    for ($i = $start; $i <= $end; $i++): ?>
                                        <a class="page-link <?= $i === $page ? 'active' : '' ?>"
                                           href="<?= htmlspecialchars(ws_url(['page'=>$i])) ?>"><?= $i ?></a>
                                    <?php endfor; ?>
                                    <?php if ($page < $totalPages): ?>
                                        <a class="page-link" href="<?= htmlspecialchars(ws_url(['page'=>$page+1])) ?>">→</a>
                                    <?php else: ?>
                                        <span class="page-link disabled">→</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                            <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-icon">📭</div>
                                    <h3>No incidents found</h3>
                                    <p>
                                        <?= ($statusFilter || $severityFilter || $categoryFilter || $search || $fromFilter || $toFilter || $zoneFilter)
                                            ? 'Try adjusting your filters.'
                                            : 'No incidents have been reported yet.' ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        function showToast(text, type) {
            const c = document.getElementById('toastContainer');
            const el = document.createElement('div');
            el.className = 'toast toast-' + (type || 'info');
            el.textContent = text;
            c.appendChild(el);
            setTimeout(() => el.style.opacity = '0', 3500);
            setTimeout(() => el.remove(), 4000);
        }

        function toggleDetails(id) {
            const detail = document.getElementById('detail-' + id);
            if (!detail) return;
            detail.classList.toggle('show');

            if (detail.classList.contains('show') && detail.dataset.loaded === '0') {
                detail.dataset.loaded = '1';
                fetch('../api/incidents.php?action=get&id=' + id, { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        const tl = document.getElementById('timeline-' + id);
                        if (!data.success || !tl) return;
                        const responses = Array.isArray(data.responses) ? data.responses : [];
                        const assignments = Array.isArray(data.assignments) ? data.assignments : [];
                        let html = '';
                        if (responses.length > 0) {
                            html += '<div style="font-size:12px;font-weight:600;color:#495057;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Responses</div>';
                            responses.forEach(r => {
                                html += `<div class="timeline-item">
                                    <span class="tl-time">${new Date(r.created_at).toLocaleString()}</span>
                                    <span><strong>${escapeHtml(r.ranger_name)}</strong> — ${escapeHtml(r.status_update)}${r.notes ? ': ' + escapeHtml(r.notes) : ''}</span>
                                </div>`;
                            });
                        }
                        if (assignments.length > 0) {
                            html += '<div style="font-size:12px;font-weight:600;color:#495057;text-transform:uppercase;letter-spacing:.5px;margin:8px 0 4px;">Assignments</div>';
                            assignments.forEach(a => {
                                html += `<div class="timeline-item">
                                    <span class="tl-time">${new Date(a.assigned_at).toLocaleString()}</span>
                                    <span><strong>${escapeHtml(a.ranger_name)}</strong> — ${escapeHtml(a.status)}${a.notes ? ': ' + escapeHtml(a.notes) : ''}</span>
                                </div>`;
                            });
                        }
                        if (!html) html = '<div class="timeline-empty">No responses or assignments yet.</div>';
                        tl.innerHTML = html;
                    })
                    .catch(() => {});
            }
        }

        function escapeHtml(s) {
            return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
        }

        function apiPost(body) {
            return fetch('../api/incidents.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            }).then(r => r.json());
        }
        function acknowledgeIncident(id) {
            if (!confirm('✅ Acknowledge this incident?')) return;
            apiPost({ action: 'acknowledge', incident_id: id })
                .then(data => {
                    if (data.success) { showToast('Incident acknowledged', 'success'); setTimeout(() => location.reload(), 500); }
                    else showToast(data.error || 'Failed to acknowledge', 'danger');
                })
                .catch(() => showToast('Network error — try again', 'danger'));
        }
        function updateStatus(id, status) {
            const messages = { 'in_progress': '🔄 Start responding to this incident?', 'resolved': '✅ Mark this incident as resolved?', 'closed': '📌 Close this incident?' };
            if (!confirm(messages[status] || 'Update incident status?')) return;
            apiPost({ action: 'update_status', incident_id: id, status: status })
                .then(data => {
                    if (data.success) { showToast('Status updated', 'success'); setTimeout(() => location.reload(), 500); }
                    else showToast(data.error || 'Failed to update', 'danger');
                })
                .catch(() => showToast('Network error — try again', 'danger'));
        }
        function refreshList() { location.reload(); }

        document.addEventListener('keydown', function (e) {
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                const i = document.querySelector('input[name="search"]');
                if (i) { i.focus(); i.select(); }
            }
            if (e.key === 'Escape') {
                const i = document.querySelector('input[name="search"]');
                if (i && document.activeElement === i) { i.value = ''; i.blur(); }
            }
        });
    </script>
</body>
</html>
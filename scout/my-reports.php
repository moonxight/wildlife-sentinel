<?php
// ============================================================
// scout/my-reports.php
// Community Scout — My Reports with Responder Details
// ------------------------------------------------------------
// - List all incidents the scout has reported
// - Show the actual responder (name + role)
// - Show real timeline: reported → acknowledged → responded → resolved
// - Show response notes written by the responding ranger
// - Filter by status / search
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once '../includes/functions.php';
requireLogin();

if (!function_exists('hasRole') || !hasRole('scout')) {
    header('Location: ../index.php');
    exit();
}

$user = getCurrentUser();
$pdo  = getDB();

// ============================================================
// GLOBAL SETTINGS
// ============================================================
if (!function_exists('ws_scout_my_setting')) {
    function ws_scout_my_setting(string $key, $default = null) {
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

$itemsPerPage = (int) ws_scout_my_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;

// ============================================================
// HELPERS (guarded)
// ============================================================
if (!function_exists('ws_my_sev_badge')) {
    function ws_my_sev_badge(?string $sev): string {
        if (function_exists('getSeverityBadge')) return getSeverityBadge($sev);
        $sev = htmlspecialchars((string)$sev);
        return '<span class="sev-fallback ' . $sev . '">' . strtoupper($sev) . '</span>';
    }
}
if (!function_exists('ws_my_status_badge')) {
    function ws_my_status_badge(?string $status): string {
        if (function_exists('getStatusBadge')) return getStatusBadge($status);
        $status = htmlspecialchars((string)$status);
        return '<span class="status-fallback ' . $status . '">' . strtoupper(str_replace('_',' ',$status)) . '</span>';
    }
}
if (!function_exists('ws_my_cat_icon')) {
    function ws_my_cat_icon(?string $cat): string {
        if (function_exists('getCategoryIcon')) return getCategoryIcon($cat);
        $map = ['poaching'=>'🎯','distressed_animal'=>'🦌','human_wildlife_conflict'=>'⚠️','environmental_risk'=>'🌍','other'=>'📌'];
        return $map[$cat] ?? '📌';
    }
}
if (!function_exists('ws_my_role_label')) {
    function ws_my_role_label(?string $role): string {
        $map = [
            'ranger'          => '🛡️ Ranger',
            'zone_supervisor' => '👔 Zone Supervisor',
            'admin'           => '⚙️ Admin',
            'scout'           => '🥾 Scout',
            'tourism'         => '🏨 Tourism',
        ];
        return $map[$role] ?? '👤 ' . htmlspecialchars((string)$role);
    }
}
if (!function_exists('ws_my_safe_fetch_all')) {
    function ws_my_safe_fetch_all(PDO $pdo, string $sql, array $params = []): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { return []; }
    }
}

// ============================================================
// FILTERS (validated)
// ============================================================
$allowedStatuses = ['reported','acknowledged','in_progress','resolved','closed'];

$statusFilter = in_array($_GET['status'] ?? '', $allowedStatuses, true) ? (string)$_GET['status'] : '';
$search       = trim((string)($_GET['search'] ?? ''));

// ============================================================
// FETCH REPORTS
// ============================================================
$sql = '
    SELECT i.*,
           z.name AS zone_name,
           resp.full_name     AS responder_name,
           resp.role          AS responder_role,
           resp.phone         AS responder_phone,
           resp.zone_id       AS responder_zone_id,
           (SELECT COUNT(*) FROM incident_responses WHERE incident_id = i.id) AS response_count,
           (SELECT COUNT(*) FROM incident_assignments WHERE incident_id = i.id) AS assignment_count,
           (SELECT string_agg(DISTINCT au.full_name::text, \', \')
              FROM incident_assignments ia
              JOIN users au ON ia.ranger_id = au.id
             WHERE ia.incident_id = i.id) AS assigned_ranger_names
    FROM incidents i
    LEFT JOIN zones z ON i.zone_id = z.id
    LEFT JOIN users resp ON i.acknowledged_by = resp.id
    WHERE i.reporter_id = ?
';
$params = [$user['id']];

if ($statusFilter !== '') {
    $sql .= " AND i.status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $sql .= " AND (i.description LIKE ? OR i.category LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY i.reported_at DESC LIMIT " . (int)$itemsPerPage;

$reports = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    error_log('[WS-MY-REPORTS] fetch: ' . $e->getMessage());
}

// Decode media_urls
foreach ($reports as &$r) {
    $r['media_list'] = [];
    if (!empty($r['media_urls'])) {
        $decoded = is_string($r['media_urls']) ? json_decode($r['media_urls'], true) : $r['media_urls'];
        if (is_array($decoded)) $r['media_list'] = $decoded;
    }
}
unset($r);

// ============================================================
// FETCH RESPONSE TIMELINE FOR EACH REPORT
// ------------------------------------------------------------
// We batch-fetch all responses for the visible reports to avoid N+1.
// ============================================================
$responsesByIncident = [];
if (count($reports) > 0) {
    $ids = array_column($reports, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $rows = ws_my_safe_fetch_all($pdo, "
        SELECT ir.*, u.full_name AS ranger_name, u.role AS ranger_role
        FROM incident_responses ir
        LEFT JOIN users u ON ir.ranger_id = u.id
        WHERE ir.incident_id IN ($placeholders)
        ORDER BY ir.created_at ASC
    ", $ids);

    foreach ($rows as $row) {
        $responsesByIncident[(int)$row['incident_id']][] = $row;
    }
}

// ============================================================
// STATS
// ============================================================
$stats = ['total'=>0,'reported'=>0,'acknowledged'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0];
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'reported'     THEN 1 ELSE 0 END) AS reported,
            SUM(CASE WHEN status = 'acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
            SUM(CASE WHEN status = 'in_progress'  THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'resolved'     THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN status = 'closed'       THEN 1 ELSE 0 END) AS closed
        FROM incidents WHERE reporter_id = ?
    ");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if ($row) {
        foreach ($stats as $k => $_) $stats[$k] = (int)($row[$k] ?? 0);
    }
} catch (PDOException $e) {
    error_log('[WS-MY-REPORTS] stats: ' . $e->getMessage());
}

// Status order for the timeline visualization
$statusOrder = ['reported'=>0, 'acknowledged'=>1, 'in_progress'=>2, 'resolved'=>3, 'closed'=>4];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Reports - Wildlife Sentinel</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">
    <style>
        .stats-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .stat-chip { background: white; padding: 8px 16px; border-radius: 20px; border: 1px solid var(--gray-300, #dee2e6); font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .stat-chip .count { font-weight: 700; color: var(--primary, #1a5c3a); }
        .stat-chip .count.danger { color: #dc3545; }
        .stat-chip .count.warning { color: #ffc107; }
        .stat-chip .count.success { color: #28a745; }
        .stat-chip .count.info { color: #17a2b8; }

        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }

        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; align-items: center; background: white; padding: 12px 16px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .filter-bar select { padding: 8px 14px; border: 2px solid var(--gray-300, #dee2e6); border-radius: 8px; font-size: 14px; background: white; min-height: 40px; }
        .filter-bar input { padding: 8px 14px; border: 2px solid var(--gray-300, #dee2e6); border-radius: 8px; font-size: 14px; background: white; min-height: 40px; flex: 1; min-width: 150px; }
        .filter-bar .btn { padding: 8px 18px; min-height: 40px; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-xs { padding: 3px 8px; font-size: 11px; }

        .report-item {
            background: white; border-radius: 12px;
            padding: 18px 22px; margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #f0f0f0;
            border-left: 4px solid var(--gray-300, #dee2e6);
        }
        .report-item.status-reported     { border-left-color: #17a2b8; }
        .report-item.status-acknowledged { border-left-color: #ffc107; }
        .report-item.status-in_progress  { border-left-color: #007bff; }
        .report-item.status-resolved     { border-left-color: #28a745; }
        .report-item.status-closed       { border-left-color: #6c757d; }

        .report-item .report-header { display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 10px; }
        .report-item .report-header h4 { margin: 0; font-size: 17px; }
        .report-item .report-meta { font-size: 13px; color: #6c757d; margin-top: 4px; display: flex; gap: 15px; flex-wrap: wrap; }
        .report-item .report-description { margin: 10px 0; color: #495057; font-size: 14px; line-height: 1.5; }

        .report-item .report-timeline {
            display: flex; align-items: center; gap: 6px;
            flex-wrap: wrap; margin-top: 12px; padding-top: 12px;
            border-top: 1px solid #f0f0f0;
        }
        .report-item .report-timeline .step {
            padding: 3px 12px; border-radius: 12px;
            font-size: 10.5px; font-weight: 600;
            background: #e9ecef; color: #6c757d;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .report-item .report-timeline .step.done {
            background: #28a745; color: white;
        }
        .report-item .report-timeline .step.active {
            background: #17a2b8; color: white;
            animation: pulseActive 1.8s ease-in-out infinite;
        }
        @keyframes pulseActive {
            0%, 100% { box-shadow: 0 0 0 0 rgba(23,162,184,0.5); }
            50%      { box-shadow: 0 0 0 6px rgba(23,162,184,0); }
        }
        .report-item .report-timeline .arrow { color: #adb5bd; font-size: 12px; }

        /* Responder panel — the important addition */
        .responder-panel {
            background: linear-gradient(135deg, #eef7f1 0%, #f8fbf9 100%);
            border: 1px solid #c3e6cb;
            border-left: 4px solid #1a5c3a;
            border-radius: 10px;
            padding: 14px 18px;
            margin-top: 12px;
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .responder-avatar {
            width: 46px; height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1a5c3a, #2d7a4e);
            color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 700;
            flex-shrink: 0;
        }
        .responder-info { flex: 1; min-width: 0; }
        .responder-info .name {
            font-weight: 700; font-size: 14.5px;
            color: #0d3b22;
            display: flex; align-items: center; gap: 6px;
            flex-wrap: wrap;
        }
        .responder-info .role {
            font-size: 11px;
            background: #d4edda; color: #155724;
            padding: 2px 8px; border-radius: 10px;
            font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .responder-info .meta { font-size: 12px; color: #6c757d; margin-top: 4px; }
        .responder-info .meta a { color: #1a5c3a; text-decoration: none; font-weight: 600; }

        .response-note {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 13px;
            border-left: 3px solid #17a2b8;
            color: #495057;
        }
        .response-note .note-header {
            font-weight: 700; font-size: 12.5px;
            color: #0d3b22;
            margin-bottom: 4px;
            display: flex; align-items: center; gap: 8px;
            flex-wrap: wrap;
        }
        .response-note .note-time { font-size: 11px; color: #adb5bd; font-weight: 400; }

        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state .icon { font-size: 56px; margin-bottom: 12px; display: block; }

        .timestamps {
            display: flex; gap: 16px; flex-wrap: wrap;
            font-size: 11.5px; color: #6c757d;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #e9ecef;
        }
        .timestamps span { display: inline-flex; align-items: center; gap: 4px; }

        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar input { min-width: 100%; }
            .report-item { padding: 14px 16px; }
            .report-item .report-header h4 { font-size: 15px; }
            .report-item .report-meta { font-size: 12px; gap: 10px; }
            .stats-row { gap: 8px; }
            .stat-chip { font-size: 12px; padding: 6px 12px; }
            .responder-panel { padding: 12px 14px; gap: 10px; }
        }
        @media (max-width: 480px) {
            .report-item { padding: 12px 14px; }
            .report-item .report-header h4 { font-size: 14px; }
            .report-item .report-description { font-size: 13px; }
            .stat-chip { font-size: 10px; padding: 4px 10px; }
            .responder-avatar { width: 38px; height: 38px; font-size: 15px; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
                <h1>My Reports</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <!-- Quick nav -->
                <div class="quick-nav">
                    <a href="report.php" class="btn btn-primary">➕ Report New Incident</a>
                    <a href="dashboard.php" class="btn btn-secondary">🏠 Dashboard</a>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <span class="stat-chip">📊 Total: <span class="count"><?= $stats['total'] ?></span></span>
                    <span class="stat-chip">🚨 Reported: <span class="count danger"><?= $stats['reported'] ?></span></span>
                    <span class="stat-chip">⏳ Acknowledged: <span class="count warning"><?= $stats['acknowledged'] ?></span></span>
                    <span class="stat-chip">🔄 In Progress: <span class="count info"><?= $stats['in_progress'] ?></span></span>
                    <span class="stat-chip">✅ Resolved: <span class="count success"><?= $stats['resolved'] ?></span></span>
                </div>

                <!-- Filter -->
                <div class="filter-bar">
                    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;">
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="reported"     <?= $statusFilter === 'reported'     ? 'selected' : '' ?>>Reported</option>
                            <option value="acknowledged" <?= $statusFilter === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
                            <option value="in_progress"  <?= $statusFilter === 'in_progress'  ? 'selected' : '' ?>>In Progress</option>
                            <option value="resolved"     <?= $statusFilter === 'resolved'     ? 'selected' : '' ?>>Resolved</option>
                            <option value="closed"       <?= $statusFilter === 'closed'       ? 'selected' : '' ?>>Closed</option>
                        </select>
                        <input type="text" name="search" placeholder="Search reports..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                        <a href="my-reports.php" class="btn btn-secondary btn-sm">Clear</a>
                    </form>
                </div>

                <!-- Reports List -->
                <?php if (count($reports) > 0): ?>
                    <?php foreach ($reports as $report): ?>
                        <?php
                        $status    = (string)($report['status'] ?? 'reported');
                        $statusIdx = $statusOrder[$status] ?? 0;
                        $responses = $responsesByIncident[(int)$report['id']] ?? [];
                        $hasResponder = !empty($report['responder_name']);
                        $photoUrls = array_map(fn($p) => '../' . ltrim($p, '/'), $report['media_list']);
                        ?>
                        <div class="report-item status-<?= htmlspecialchars($status) ?>">
                            <div class="report-header">
                                <div>
                                    <h4>
                                        <?= ws_my_cat_icon($report['category'] ?? null) ?>
                                        <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $report['category'] ?? 'other'))) ?>
                                        <span style="font-weight:400;font-size:14px;color:#6c757d;">#<?= (int)$report['id'] ?></span>
                                    </h4>
                                    <div class="report-meta">
                                        <span>📍 <?= htmlspecialchars($report['zone_name'] ?? 'N/A') ?></span>
                                        <span>🕐 <?= timeAgo($report['reported_at'] ?? null) ?></span>
                                        <span>📅 <?= htmlspecialchars(date('M j, Y H:i', strtotime($report['reported_at'] ?? 'now'))) ?></span>
                                        <?php if ((int)$report['response_count'] > 0): ?>
                                            <span>💬 <?= (int)$report['response_count'] ?> response<?= $report['response_count'] > 1 ? 's' : '' ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?= ws_my_sev_badge($report['severity'] ?? null) ?>
                            </div>

                            <?php if (!empty($report['description'])): ?>
                                <div class="report-description"><?= nl2br(htmlspecialchars($report['description'])) ?></div>
                            <?php endif; ?>

                            <?php if (count($photoUrls) > 0): ?>
                                <div style="font-size:11.5px;color:#6c757d;margin-top:6px;">
                                    📷 <?= count($photoUrls) ?> photo<?= count($photoUrls) > 1 ? 's' : '' ?> attached
                                </div>
                            <?php endif; ?>

                            <!-- Status Timeline -->
                            <div class="report-timeline">
                                <span style="font-weight:600;font-size:12px;color:#495057;">Status:</span>
                                <?= ws_my_status_badge($status) ?>
                                <span class="arrow">→</span>
                                <span class="step <?= $statusIdx >= 0 ? 'done' : '' ?>">📋 Reported</span>
                                <span class="arrow">→</span>
                                <span class="step <?= $statusIdx >= 1 ? 'done' : '' ?>">✅ Acknowledged</span>
                                <span class="arrow">→</span>
                                <span class="step <?= $statusIdx >= 2 ? 'done' : '' ?>">🔄 Responding</span>
                                <span class="arrow">→</span>
                                <span class="step <?= $statusIdx >= 3 ? 'done' : '' ?>">🏁 Resolved</span>
                            </div>

                            <!-- ============================================================
                                 RESPONDER PANEL — the scout can now see WHO is responding
                                 ============================================================ -->
                            <?php if ($hasResponder): ?>
                                <div class="responder-panel">
                                    <div class="responder-avatar">
                                        <?= htmlspecialchars(mb_strtoupper(mb_substr($report['responder_name'], 0, 1))) ?>
                                    </div>
                                    <div class="responder-info">
                                        <div class="name">
                                            <?= htmlspecialchars($report['responder_name']) ?>
                                            <span class="role"><?= ws_my_role_label($report['responder_role'] ?? null) ?></span>
                                        </div>
                                        <div class="meta">
                                            <?php if (!empty($report['acknowledged_at'])): ?>
                                                🕐 Acknowledged <?= timeAgo($report['acknowledged_at']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($report['resolved_at'])): ?>
                                                • 🏁 Resolved <?= timeAgo($report['resolved_at']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="meta">
                                            <?php if (!empty($report['responder_phone'])): ?>
                                                📞 <a href="tel:<?= htmlspecialchars($report['responder_phone']) ?>"><?= htmlspecialchars($report['responder_phone']) ?></a>
                                            <?php else: ?>
                                                <span style="color:#adb5bd;">No phone on file</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ((int)$report['assignment_count'] > 0 && !empty($report['assigned_ranger_names'])): ?>
                                <div class="responder-panel" style="background:#f8f5ff;border-color:#e5d5f7;border-left-color:#6f42c1;">
                                    <div class="responder-avatar" style="background:linear-gradient(135deg,#6f42c1,#8b5cf6);">👥</div>
                                    <div class="responder-info">
                                        <div class="name">
                                            Awaiting response
                                            <span class="role" style="background:#e8d5f5;color:#6f42c1;">
                                                <?= (int)$report['assignment_count'] ?> ASSIGNED
                                            </span>
                                        </div>
                                        <div class="meta">
                                            🛡️ <?= htmlspecialchars($report['assigned_ranger_names']) ?> will respond
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="responder-panel" style="background:#fff8e1;border-color:#ffe082;border-left-color:#ffc107;">
                                    <div class="responder-avatar" style="background:linear-gradient(135deg,#ffc107,#ffb300);color:#212529;">⏳</div>
                                    <div class="responder-info">
                                        <div class="name">
                                            Waiting for a ranger
                                            <span class="role" style="background:#fff3cd;color:#856404;">PENDING</span>
                                        </div>
                                        <div class="meta">
                                            Your report has been received and will be picked up shortly.
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Real response notes from the ranger -->
                            <?php if (count($responses) > 0): ?>
                                <?php foreach ($responses as $resp): ?>
                                    <div class="response-note">
                                        <div class="note-header">
                                            🛡️ <?= htmlspecialchars($resp['ranger_name'] ?? 'Ranger') ?>
                                            <?php if ($resp['status_update']): ?>
                                                • <?= htmlspecialchars(str_replace('_', ' ', $resp['status_update'])) ?>
                                            <?php endif; ?>
                                            <span class="note-time">— <?= timeAgo($resp['created_at'] ?? null) ?></span>
                                        </div>
                                        <?php if (!empty($resp['notes'])): ?>
                                            <?= nl2br(htmlspecialchars($resp['notes'])) ?>
                                        <?php else: ?>
                                            <em style="color:#adb5bd;">No additional notes.</em>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Timestamps -->
                            <div class="timestamps">
                                <span>📝 Reported: <?= htmlspecialchars(date('M j, H:i', strtotime($report['reported_at'] ?? 'now'))) ?></span>
                                <?php if (!empty($report['acknowledged_at'])): ?>
                                    <span>✅ Acknowledged: <?= htmlspecialchars(date('M j, H:i', strtotime($report['acknowledged_at']))) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($report['resolved_at'])): ?>
                                    <span>🏁 Resolved: <?= htmlspecialchars(date('M j, H:i', strtotime($report['resolved_at']))) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="icon">📭</span>
                        <h3>No Reports Found</h3>
                        <p><?= $search || $statusFilter ? 'Try adjusting your filters.' : 'You haven\'t reported any incidents yet.' ?></p>
                        <a href="report.php" class="btn btn-primary" style="margin-top:10px;">📝 Report an Incident</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        console.log('✅ Scout My Reports page loaded');
        console.log('📋 Total reports visible: <?= (int)count($reports) ?>');
    </script>
</body>
</html>
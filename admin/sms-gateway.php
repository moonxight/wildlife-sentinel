<?php
// ============================================================
// admin/sms-gateway.php
// Wildlife Sentinel — SMS Gateway Management
// ============================================================
// Features:
//   - View SMS logs (status, type, recipient, provider)
//   - Send test SMS (routed via MTN / Airtel / eSMS by prefix)
//   - Broadcast SMS to a zone / role
//   - SMS statistics + network breakdown
//   - Provider status panel (MTN, Airtel, eSMS)
//   - Retry failed SMS · delete log · purge old logs
//   - Honors global settings:
//       sms_enabled, notify_on_incident, notify_on_ai_alert,
//       notify_on_alarm, notify_on_manpower, items_per_page
// ============================================================

require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$user = getCurrentUser();
$pdo  = getDB();

// ============================================================
// CONFIG LOADER  (../config.php)
// ------------------------------------------------------------
// Put your credentials in a file next to the project root:
//
// <?php
// return [
//     // MTN Zambia
//     'mtn_client_id'      => 'xxx',
//     'mtn_client_secret'  => 'xxx',
//     'mtn_sender_id'      => 'WILDLIFE',
//
//     // Airtel Zambia (Airtel IQ SMS)
//     'airtel_api_key'     => 'xxx',
//     'airtel_customer_id' => 'xxx',
//     'airtel_sender_id'   => 'WILDLIFE',
//     'airtel_template_id' => '',
//
//     // eSMS Africa aggregator (fallback + Zamtel)
//     'esms_api_key'       => 'xxx',
//     'esms_sender_id'     => 'WILDLIFE',
// ];
// ============================================================
function ws_load_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = __DIR__ . '/../config.php';
    if (is_file($path)) {
        $loaded = include $path;
        $cfg = is_array($loaded) ? $loaded : [];
    } else {
        $cfg = [];
    }
    return $cfg;
}

// ============================================================
// GLOBAL SETTINGS (from admin/settings.php)
// ============================================================
if (!function_exists('ws_sms_setting')) {
    function ws_sms_setting(string $key, $default = null) {
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

$setSmsEnabled      = (string) ws_sms_setting('sms_enabled', '1')           === '1';
$setNotifyIncident  = (string) ws_sms_setting('notify_on_incident', '1')    === '1';
$setNotifyAiAlert   = (string) ws_sms_setting('notify_on_ai_alert', '1')    === '1';
$setNotifyAlarm     = (string) ws_sms_setting('notify_on_alarm', '1')       === '1';
$setNotifyManpower  = (string) ws_sms_setting('notify_on_manpower', '1')    === '1';

$itemsPerPage = (int) ws_sms_setting('items_per_page', 25);
if ($itemsPerPage < 5 || $itemsPerPage > 100) $itemsPerPage = 25;

// ============================================================
// HELPERS — Normalisation
// ============================================================
function normalizeZambianPhone(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    $digits = preg_replace('/[^0-9]/', '', $raw);
    if (strpos($digits, '260') === 0) $digits = substr($digits, 3);
    if (!preg_match('/^(09|07)[0-9]{8}$/', $digits)) return null;
    return $digits;
}

function networkNameFromPhone(string $normalizedPhone): string {
    $p = substr($normalizedPhone, 0, 3);
    if (in_array($p, ['096','076'], true)) return 'MTN Zambia';
    if (in_array($p, ['097','077'], true)) return 'Airtel Zambia';
    if (in_array($p, ['095','075'], true)) return 'Zamtel';
    return 'Unknown';
}

function routeProviderByPhone(string $normalizedPhone): string {
    $p = substr($normalizedPhone, 0, 3);
    if (in_array($p, ['096','076'], true)) return 'mtn';
    if (in_array($p, ['097','077'], true)) return 'airtel';
    if (in_array($p, ['095','075'], true)) return 'esms';
    return 'esms';
}

function estimateSegments(string $message): int {
    $len = mb_strlen($message);
    if ($len <= 160) return 1;
    return (int)ceil($len / 153);
}

// ============================================================
// SMS PROVIDER ADAPTERS
// ============================================================

/** MTN Zambia — SMS v3 API (OAuth2 client_credentials). */
function sendViaMTN(string $to, string $message, array $cfg): array {
    $clientId     = $cfg['mtn_client_id']     ?? '';
    $clientSecret = $cfg['mtn_client_secret'] ?? '';
    $senderId     = $cfg['mtn_sender_id']     ?? 'WILDLIFE';

    if (!$clientId || !$clientSecret) {
        return ['success'=>false,'ref'=>null,'error'=>'MTN credentials not configured','provider'=>'mtn'];
    }

    $ch = curl_init('https://api.mtn.com/oauth/client_credential/accesstoken?grant_type=client_credentials');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr  = curl_error($ch);
    curl_close($ch);

    if ($cerr) return ['success'=>false,'ref'=>null,'error'=>'MTN network: '.$cerr,'provider'=>'mtn'];
    if ($code !== 200) return ['success'=>false,'ref'=>null,'error'=>'MTN auth failed (HTTP '.$code.')','provider'=>'mtn'];
    $data  = json_decode($resp, true);
    $token = $data['access_token'] ?? null;
    if (!$token) return ['success'=>false,'ref'=>null,'error'=>'MTN token missing','provider'=>'mtn'];

    $msisdn = '+260' . ltrim($to, '0');
    $payload = [
        'outboundSMSMessageRequest' => [
            'address'                => ['tel:' . $msisdn],
            'senderAddress'          => 'tel:' . $senderId,
            'outboundSMSTextMessage' => ['message' => $message],
            'clientCorrelatorId'     => 'ws-' . bin2hex(random_bytes(6)),
            'requestDeliveryReceipt' => true,
        ],
    ];

    $ch = curl_init('https://api.mtn.com/v3/sms/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($cerr) return ['success'=>false,'ref'=>null,'error'=>'MTN network: '.$cerr,'provider'=>'mtn'];
    if ($code >= 200 && $code < 300) {
        $res = json_decode($resp, true);
        $ref = $res['outboundSMSMessageRequest']['resourceReference']
             ?? $res['resourceReference']
             ?? ('MTN-' . bin2hex(random_bytes(4)));
        return ['success'=>true,'ref'=>$ref,'error'=>null,'provider'=>'mtn'];
    }
    $err = json_decode($resp, true);
    $errMsg = $err['requestError']['serviceException']['text']
            ?? $err['serviceException']['text']
            ?? $err['message']
            ?? ('HTTP '.$code);
    return ['success'=>false,'ref'=>null,'error'=>'MTN: '.$errMsg,'provider'=>'mtn'];
}

/** Airtel Zambia — Airtel IQ SMS API. */
function sendViaAirtel(string $to, string $message, array $cfg): array {
    $apiKey     = $cfg['airtel_api_key']     ?? '';
    $customerId = $cfg['airtel_customer_id'] ?? '';
    $senderId   = $cfg['airtel_sender_id']   ?? 'WILDLIFE';
    $templateId = $cfg['airtel_template_id'] ?? '';

    if (!$apiKey || !$customerId) {
        return ['success'=>false,'ref'=>null,'error'=>'Airtel credentials not configured','provider'=>'airtel'];
    }

    $msisdn = '260' . ltrim($to, '0');

    $payload = [
        'customerId' => $customerId,
        'senderId'   => $senderId,
        'message'    => $message,
        'mobileNo'   => $msisdn,
    ];
    if ($templateId !== '') $payload['templateId'] = $templateId;

    $ch = curl_init('https://iqsms.airtel.in/api/v1/send-sms');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($cerr) return ['success'=>false,'ref'=>null,'error'=>'Airtel network: '.$cerr,'provider'=>'airtel'];
    if ($code >= 200 && $code < 300) {
        $res = json_decode($resp, true);
        $ref = $res['messageId'] ?? $res['requestId'] ?? ('AIR-' . bin2hex(random_bytes(4)));
        return ['success'=>true,'ref'=>$ref,'error'=>null,'provider'=>'airtel'];
    }
    $err = json_decode($resp, true);
    $errMsg = $err['message'] ?? $err['error'] ?? ('HTTP '.$code);
    return ['success'=>false,'ref'=>null,'error'=>'Airtel: '.$errMsg,'provider'=>'airtel'];
}

/** eSMS Africa aggregator (Zamtel + fallback). */
function sendViaESMS(string $to, string $message, array $cfg): array {
    $apiKey   = $cfg['esms_api_key']   ?? '';
    $senderId = $cfg['esms_sender_id'] ?? 'WILDLIFE';

    if (!$apiKey) {
        return ['success'=>false,'ref'=>null,'error'=>'eSMS credentials not configured','provider'=>'esms'];
    }

    $payload = [
        'to'      => '260' . ltrim($to, '0'),
        'from'    => $senderId,
        'message' => $message,
    ];

    $ch = curl_init('https://api.esmsafrica.io/v1/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($cerr) return ['success'=>false,'ref'=>null,'error'=>'eSMS network: '.$cerr,'provider'=>'esms'];
    if ($code >= 200 && $code < 300) {
        $res = json_decode($resp, true);
        $ref = $res['messageId'] ?? $res['id'] ?? ('ESMS-' . bin2hex(random_bytes(4)));
        return ['success'=>true,'ref'=>$ref,'error'=>null,'provider'=>'esms'];
    }
    $err = json_decode($resp, true);
    $errMsg = $err['message'] ?? $err['error'] ?? ('HTTP '.$code);
    return ['success'=>false,'ref'=>null,'error'=>'eSMS: '.$errMsg,'provider'=>'esms'];
}

// ============================================================
// MAIN sendSMS() — routes by prefix, retries via aggregator
// ============================================================
function sendSMS(string $to, string $message): array {
    $cfg = ws_load_config();
    $provider = routeProviderByPhone($to);

    switch ($provider) {
        case 'mtn':    $res = sendViaMTN($to, $message, $cfg);    break;
        case 'airtel': $res = sendViaAirtel($to, $message, $cfg); break;
        case 'esms':
        default:       $res = sendViaESMS($to, $message, $cfg);   break;
    }

    if (!$res['success'] && $provider !== 'esms' && !empty($cfg['esms_api_key'])) {
        $fb = sendViaESMS($to, $message, $cfg);
        if ($fb['success']) {
            $fb['provider'] = $fb['provider'] . ' (fallback)';
            return $fb;
        }
    }
    return $res;
}

// ============================================================
// HANDLE ACTIONS
// ============================================================
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // -------- SEND TEST SMS --------
    if ($action === 'test_sms') {
        if (!$setSmsEnabled) {
            $message = '⛔ SMS is globally disabled (sms_enabled = 0). Enable it in System Settings to send.';
            $messageType = 'danger';
        } else {
            $phoneRaw = trim($_POST['phone'] ?? '');
            $content  = trim($_POST['content'] ?? '');

            if (!$phoneRaw || !$content) {
                $message = 'Phone and message are required.';
                $messageType = 'danger';
            } else {
                $phone = normalizeZambianPhone($phoneRaw);
                if (!$phone) {
                    $message = 'Invalid Zambian phone (must be 09xxxxxxxx or 07xxxxxxxx).';
                    $messageType = 'danger';
                } else {
                    $network = networkNameFromPhone($phone);
                    $res = sendSMS($phone, $content);
                    $status = $res['success'] ? 'sent' : 'failed';

                    try {
                        $pdo->prepare("
                            INSERT INTO sms_logs
                                (user_id, phone, message, message_type, status, created_at)
                            VALUES (?, ?, ?, 'test', ?, NOW())
                        ")->execute([$user['id'], $phone, $content, $status]);
                    } catch (Throwable $e) { /* non-fatal */ }

                    logAudit($user['id'], 'send_test_sms', [
                        'phone'    => $phone,
                        'network'  => $network,
                        'provider' => $res['provider'],
                        'ref'      => $res['ref'],
                        'status'   => $status,
                    ]);

                    if ($res['success']) {
                        $message = "✅ Test SMS sent to {$phone} via {$network} (ref {$res['ref']}).";
                    } else {
                        $message = "❌ Test failed ({$network}): " . ($res['error'] ?: 'unknown error');
                        $messageType = 'danger';
                    }
                }
            }
        }
    }

    // -------- BROADCAST SMS --------
    if ($action === 'broadcast') {
        if (!$setSmsEnabled) {
            $message = '⛔ SMS is globally disabled (sms_enabled = 0). Enable it in System Settings to broadcast.';
            $messageType = 'danger';
        } else {
            $zoneId  = (int)($_POST['zone_id'] ?? 0);
            $role    = $_POST['role']    ?? '';
            $content = trim($_POST['content'] ?? '');

            if (!$content) {
                $message = 'Message content is required.';
                $messageType = 'danger';
            } else {
                try {
                    $sql = "SELECT id, full_name, phone FROM users
                            WHERE is_active = 1 AND phone IS NOT NULL AND phone <> ''";
                    $params = [];

                    if ($zoneId > 0)  { $sql .= " AND zone_id = ?"; $params[] = $zoneId; }
                    if ($role !== '') { $sql .= " AND role = ?";    $params[] = $role; }

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $recipients = $stmt->fetchAll();

                    $sent = 0; $failed = 0; $skipped = 0;
                    $networkCounts = ['mtn'=>0,'airtel'=>0,'esms'=>0];

                    foreach ($recipients as $r) {
                        $phone = normalizeZambianPhone($r['phone'] ?? null);
                        if (!$phone) { $skipped++; continue; }

                        $res    = sendSMS($phone, $content);
                        $status = $res['success'] ? 'sent' : 'failed';
                        $prov   = $res['provider'];

                        if (strpos($prov, 'mtn') === 0)          $networkCounts['mtn']++;
                        elseif (strpos($prov, 'airtel') === 0)   $networkCounts['airtel']++;
                        else                                     $networkCounts['esms']++;

                        try {
                            $pdo->prepare("
                                INSERT INTO sms_logs
                                    (user_id, phone, message, message_type, status, created_at)
                                VALUES (?, ?, ?, 'broadcast', ?, NOW())
                            ")->execute([$r['id'], $phone, $content, $status]);
                        } catch (Throwable $e) { /* continue */ }

                        if ($res['success']) $sent++; else $failed++;
                    }

                    logAudit($user['id'], 'broadcast_sms', [
                        'zone_id'  => $zoneId,
                        'role'     => $role,
                        'sent'     => $sent,
                        'failed'   => $failed,
                        'skipped'  => $skipped,
                        'networks' => $networkCounts,
                    ]);

                    $message = "📢 Broadcast complete — Sent: {$sent}, Failed: {$failed}, Skipped: {$skipped} "
                             . "(MTN {$networkCounts['mtn']}, Airtel {$networkCounts['airtel']}, Aggregator {$networkCounts['esms']}).";
                    if ($failed > 0) $messageType = 'warning';
                } catch (Throwable $e) {
                    $message = "Broadcast error: " . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }

    // -------- RETRY FAILED SMS --------
    if ($action === 'retry_sms') {
        if (!$setSmsEnabled) {
            $message = '⛔ SMS is globally disabled. Retry skipped.';
            $messageType = 'danger';
        } else {
            $logId = (int)($_POST['log_id'] ?? 0);
            try {
                $row = $pdo->prepare("SELECT id, phone, message FROM sms_logs WHERE id = ? LIMIT 1");
                $row->execute([$logId]);
                $log = $row->fetch();

                if (!$log) {
                    $message = '❌ SMS log entry not found.';
                    $messageType = 'danger';
                } else {
                    $phone = normalizeZambianPhone($log['phone']);
                    if (!$phone) {
                        $message = '❌ Original phone number is no longer valid.';
                        $messageType = 'danger';
                    } else {
                        $res = sendSMS($phone, $log['message']);
                        $status = $res['success'] ? 'sent' : 'failed';

                        $pdo->prepare("
                            INSERT INTO sms_logs
                                (user_id, phone, message, message_type, status, created_at)
                            VALUES (?, ?, ?, 'test', ?, NOW())
                        ")->execute([$user['id'], $phone, $log['message'], $status]);

                        logAudit($user['id'], 'retry_sms', [
                            'original_id' => $logId,
                            'phone'       => $phone,
                            'status'      => $status,
                            'provider'    => $res['provider'],
                        ]);

                        if ($res['success']) {
                            $message = "✅ Retry sent to {$phone} (ref {$res['ref']}).";
                        } else {
                            $message = "❌ Retry failed: " . ($res['error'] ?: 'unknown error');
                            $messageType = 'danger';
                        }
                    }
                }
            } catch (Throwable $e) {
                $message = 'Retry error: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // -------- DELETE LOG ENTRY --------
    if ($action === 'delete_log') {
        $logId = (int)($_POST['log_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM sms_logs WHERE id = ?");
            $stmt->execute([$logId]);
            $message = $stmt->rowCount() > 0 ? '🗑️ SMS log entry deleted.' : 'Log entry not found.';
            if ($stmt->rowCount() > 0) {
                logAudit($user['id'], 'delete_sms_log', ['log_id' => $logId]);
            }
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }

    // -------- PURGE OLD LOGS --------
    if ($action === 'purge_old') {
        $days = max(1, min(365, (int)($_POST['purge_days'] ?? 30)));
        try {
            $stmt = $pdo->prepare('DELETE FROM sms_logs WHERE created_at < (NOW() - (?) * INTERVAL \'1 day\')');
            $stmt->execute([$days]);
            $n = $stmt->rowCount();
            $message = "🧹 Purged {$n} SMS log entr" . ($n === 1 ? 'y' : 'ies') . " older than {$days} days.";
            logAudit($user['id'], 'purge_sms_logs', ['days' => $days, 'deleted' => $n]);
        } catch (PDOException $e) {
            $message = 'Purge error: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ============================================================
// FETCH SMS LOGS (with filters)
// ============================================================
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type']   ?? '';
$search       = trim($_GET['search'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = $itemsPerPage;
$offset       = ($page - 1) * $perPage;

$where  = " WHERE 1=1 ";
$params = [];

if ($filterStatus !== '' && in_array($filterStatus, ['pending','sent','failed','delivered'])) {
    $where .= " AND s.status = ? ";
    $params[] = $filterStatus;
}
if ($filterType !== '' && in_array($filterType, ['general','incident','ai_alert','manpower','system','test','broadcast'])) {
    $where .= " AND s.message_type = ? ";
    $params[] = $filterType;
}
if ($search !== '') {
    $where .= " AND (s.phone LIKE ? OR s.message LIKE ? OR u.full_name LIKE ?) ";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$totalLogs = safeCount($pdo, "
    SELECT COUNT(*) AS count
    FROM sms_logs s
    LEFT JOIN users u ON s.user_id = u.id
    $where
", $params);

$totalPages = max(1, (int)ceil($totalLogs / $perPage));

$logs = safeFetchAll($pdo, "
    SELECT s.*, u.full_name AS user_name, u.role AS user_role
    FROM sms_logs s
    LEFT JOIN users u ON s.user_id = u.id
    $where
    ORDER BY s.created_at DESC
    LIMIT $perPage OFFSET $offset
", $params);

// ============================================================
// STATISTICS
// ============================================================
$stats = [
    'total'     => safeCount($pdo, "SELECT COUNT(*) AS count FROM sms_logs"),
    'sent'      => safeCount($pdo, "SELECT COUNT(*) AS count FROM sms_logs WHERE status = 'sent'"),
    'pending'   => safeCount($pdo, "SELECT COUNT(*) AS count FROM sms_logs WHERE status = 'pending'"),
    'failed'    => safeCount($pdo, "SELECT COUNT(*) AS count FROM sms_logs WHERE status = 'failed'"),
    'today'     => safeCount($pdo, 'SELECT COUNT(*) AS count FROM sms_logs WHERE DATE(created_at) = CURRENT_DATE'),
    'this_week' => safeCount($pdo, 'SELECT COUNT(*) AS count FROM sms_logs WHERE created_at >= (NOW() - (7) * INTERVAL \'1 day\')'),
];

// Network breakdown (approx via phone prefix)
$netBreakdown = ['mtn'=>0, 'airtel'=>0, 'zamtel'=>0, 'unknown'=>0];
try {
    $rows = $pdo->query('SELECT phone FROM sms_logs WHERE created_at >= (NOW() - (7) * INTERVAL \'1 day\')')->fetchAll();
    foreach ($rows as $r) {
        $n = normalizeZambianPhone($r['phone'] ?? '');
        if (!$n) { $netBreakdown['unknown']++; continue; }
        $p = substr($n, 0, 3);
        if (in_array($p, ['096','076'], true))      $netBreakdown['mtn']++;
        elseif (in_array($p, ['097','077'], true))  $netBreakdown['airtel']++;
        elseif (in_array($p, ['095','075'], true))  $netBreakdown['zamtel']++;
        else                                         $netBreakdown['unknown']++;
    }
} catch (PDOException $e) { /* non-fatal */ }

// ============================================================
// ZONES (for broadcast filter)
// ============================================================
$zones = safeFetchAll($pdo, "SELECT id, name FROM zones WHERE is_active = 1 ORDER BY name");

// ============================================================
// ICON MAP
// ============================================================
$typeIcons = [
    'general'   => '💬',
    'incident'  => '🚨',
    'ai_alert'  => '🤖',
    'manpower'  => '🆘',
    'system'    => '⚙️',
    'test'      => '🧪',
    'broadcast' => '📢',
];

$statusColors = [
    'pending'   => 'orange',
    'sent'      => 'blue',
    'delivered' => 'green',
    'failed'    => 'red',
];

// ============================================================
// PROVIDER STATUS
// ============================================================
$cfg = ws_load_config();
$providerStatus = [
    'mtn'    => !empty($cfg['mtn_client_id']) && !empty($cfg['mtn_client_secret']),
    'airtel' => !empty($cfg['airtel_api_key']) && !empty($cfg['airtel_customer_id']),
    'esms'   => !empty($cfg['esms_api_key']),
];
$anyProvider = $providerStatus['mtn'] || $providerStatus['airtel'] || $providerStatus['esms'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SMS Gateway - Admin - Wildlife Sentinel</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/transitions.css">

    <style>
        .dashboard-greeting { margin-bottom: 24px; }
        .dashboard-greeting h1 { font-size: 28px; color: #0d3b22; }
        .dashboard-greeting p  { color: #6c757d; font-size: 16px; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 12px; padding: 16px 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 12px; border: 1px solid #f0f0f0; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .stat-card .icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .stat-card .icon.blue   { background: #cce5ff; color: #004085; }
        .stat-card .icon.green  { background: #d4edda; color: #155724; }
        .stat-card .icon.orange { background: #fff3cd; color: #856404; }
        .stat-card .icon.red    { background: #f8d7da; color: #721c24; }
        .stat-card .icon.purple { background: #e8d5f5; color: #6f42c1; }
        .stat-card .info .number { font-size: 22px; font-weight: 700; color: #0d3b22; }
        .stat-card .info .label  { font-size: 11px; color: #6c757d; }

        .section { background: white; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #f0f0f0; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { font-size: 17px; color: #0d3b22; display: flex; align-items: center; gap: 10px; }
        .section-header .header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

        .btn { padding: 9px 18px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary { background: #1a5c3a; color: white; }
        .btn-primary:hover { background: #0d3b22; }
        .btn-secondary { background: #f0f0f0; color: #495057; }
        .btn-secondary:hover { background: #e0e0e0; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c62828; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-xs { padding: 3px 8px; font-size: 10.5px; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }

        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.danger  { background: #f8d7da; color: #721c24; }
        .alert.warning { background: #fff3cd; color: #856404; }
        .alert.info    { background: #d1ecf1; color: #0c5460; }
        .alert a { color: inherit; }

        /* SMS routing state banner */
        .routing-banner { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 12.5px; }
        .routing-banner.ok   { background: #eef7f1; border: 1px solid #c3e6cb; color: #155724; }
        .routing-banner.off  { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
        .routing-banner strong { color: #0d3b22; }
        .routing-banner code { font-size: 11px; background: rgba(255,255,255,.6); padding: 1px 6px; border-radius: 4px; }

        /* Provider status grid */
        .provider-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .provider-card { padding: 14px 16px; border-radius: 10px; border: 1px solid #e0e0e0; background: #fcfcfc; }
        .provider-card.ok  { border-left: 4px solid #28a745; background: #f0fff4; }
        .provider-card.bad { border-left: 4px solid #dc3545; background: #fff5f5; }
        .provider-card h4 { margin: 0 0 4px; font-size: 14px; color: #0d3b22; }
        .provider-card p  { margin: 0; font-size: 11.5px; color: #6c757d; line-height: 1.45; }
        .provider-card .badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; margin-left: 6px; }
        .provider-card .badge.ok  { background: #d4edda; color: #155724; }
        .provider-card .badge.bad { background: #f8d7da; color: #721c24; }

        /* Network breakdown pills */
        .net-breakdown { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
        .net-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .net-pill.mtn    { background: #fff3cd; color: #856404; }
        .net-pill.airtel { background: #f8d7da; color: #721c24; }
        .net-pill.zamtel { background: #d1ecf1; color: #0c5460; }
        .net-pill.other  { background: #e9ecef; color: #495057; }

        /* Filters */
        .filter-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .filter-group input, .filter-group select {
            padding: 9px 12px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
            transition: all 0.2s;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #1a5c3a; background: white; }

        /* Table */
        .sms-table { width: 100%; border-collapse: collapse; }
        .sms-table thead th {
            text-align: left; font-size: 11px; color: #6c757d;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 10px 12px; border-bottom: 2px solid #f0f0f0;
            background: #fafafa; font-weight: 700;
        }
        .sms-table tbody tr { border-bottom: 1px solid #f5f5f5; transition: background 0.15s; }
        .sms-table tbody tr:hover { background: #fafafa; }
        .sms-table td { padding: 12px; font-size: 13px; vertical-align: middle; }

        .type-icon {
            width: 32px; height: 32px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; background: #f0f7f4;
        }

        .status-pill {
            padding: 3px 10px; border-radius: 12px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; display: inline-block;
        }
        .status-pill.pending   { background: #fff3cd; color: #856404; }
        .status-pill.sent      { background: #cce5ff; color: #004085; }
        .status-pill.delivered { background: #d4edda; color: #155724; }
        .status-pill.failed    { background: #f8d7da; color: #721c24; }

        .network-pill {
            display: inline-block; padding: 1px 8px;
            font-size: 9px; border-radius: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px;
            background: #e8f4f8; color: #0c5460;
            margin-top: 4px;
        }

        .msg-preview { font-size: 12px; color: #495057; max-width: 340px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .empty-state { text-align:center; padding:40px 20px; color:#6c757d; }
        .empty-state .icon { font-size: 48px; display: block; margin-bottom: 10px; opacity: 0.4; }
        .empty-state h3 { font-size: 16px; color: #495057; margin-bottom: 6px; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 20px; flex-wrap: wrap; }
        .pagination a, .pagination span {
            padding: 8px 14px; border-radius: 8px;
            text-decoration: none; font-size: 13px;
            color: #495057; background: #f0f0f0;
            transition: all 0.2s;
        }
        .pagination a:hover { background: #1a5c3a; color: white; }
        .pagination .active { background: #1a5c3a; color: white; font-weight: 700; }
        .pagination .disabled { opacity: 0.4; pointer-events: none; }

        /* Forms */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; color: #495057; font-weight: 600; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0;
            border-radius: 8px; font-size: 13px; background: #fafafa;
            transition: all 0.2s; font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #1a5c3a; background: white;
        }
        .field-hint { font-size: 11.5px; color: #6c757d; margin-top: 4px; line-height: 1.4; }
        .field-hint.ok   { color: #28a745; }
        .field-hint.err  { color: #dc3545; }

        .char-counter { font-size: 11px; color: #6c757d; margin-top: 4px; text-align: right; }
        .char-counter.over { color: #856404; }

        /* Quick nav */
        .quick-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .quick-nav .btn { font-size: 12px; padding: 6px 12px; }

        /* Purge row */
        .purge-form { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
        .purge-form input[type="number"] { width: 90px; padding: 8px 10px; border: 1px solid #e0e0e0; border-radius: 6px; font-size: 13px; }

        @media (max-width: 1024px) {
            .sms-table thead { display: none; }
            .sms-table, .sms-table tbody, .sms-table tr, .sms-table td { display: block; width: 100%; }
            .sms-table tr { margin-bottom: 12px; padding: 12px; border-radius: 10px; background: #fafafa; border: 1px solid #f0f0f0; }
            .sms-table td { padding: 4px 0; border: none; }
            .sms-table td::before { content: attr(data-label); font-size: 10px; text-transform: uppercase; color: #adb5bd; display: block; margin-bottom: 2px; }
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
                <h1>SMS Gateway</h1>
                <div class="header-right">
                    <span class="online-status">● Online</span>
                    <span class="data-honesty-badge">🟢 Live Data</span>
                    <span class="user-name"><?= htmlspecialchars($user['full_name']) ?></span>
                </div>
            </header>

            <div class="content">
                <div class="dashboard-greeting">
                    <h1>📱 SMS Gateway</h1>
                    <p>Send, monitor and manage all SMS traffic across the system. Messages route automatically by recipient prefix: MTN (096/076), Airtel (097/077), Zamtel (095/075).</p>
                </div>

                <!-- Quick nav -->
                <div class="quick-nav">
                    <a href="ai-dashboard.php" class="btn btn-secondary">🤖 AI Dashboard</a>
                    <a href="incidents.php" class="btn btn-secondary">📋 Incidents</a>
                    <a href="incidents.php?report=zone" class="btn btn-secondary">🏛️ Zone Reports</a>
                    <a href="simulation.php" class="btn btn-secondary">🎮 Simulation</a>
                    <a href="settings.php" class="btn btn-secondary">⚙️ System Settings</a>
                </div>

                <?php if ($message): ?>
                    <div class="alert <?= $messageType ?>"><?= $message ?></div>
                <?php endif; ?>

                <!-- SMS routing state banner -->
                <div class="routing-banner <?= $setSmsEnabled ? 'ok' : 'off' ?>">
                    <?php if ($setSmsEnabled): ?>
                        <span style="font-size:20px;">✅</span>
                        <div>
                            <strong>SMS is ENABLED.</strong>
                            Notifications routing:
                            Incidents <strong><?= $setNotifyIncident ? 'ON' : 'OFF' ?></strong>
                            • AI alerts <strong><?= $setNotifyAiAlert ? 'ON' : 'OFF' ?></strong>
                            • Alarms <strong><?= $setNotifyAlarm ? 'ON' : 'OFF' ?></strong>
                            • Manpower <strong><?= $setNotifyManpower ? 'ON' : 'OFF' ?></strong>.
                            Manual sends below are always allowed.
                            <a href="settings.php" style="color:inherit;text-decoration:underline;">Change</a>
                        </div>
                    <?php else: ?>
                        <span style="font-size:20px;">⛔</span>
                        <div>
                            <strong>SMS is globally DISABLED.</strong>
                            Automated notifications and manual sends below are blocked.
                            Enable it in <a href="settings.php" style="color:inherit;text-decoration:underline;">System Settings → Notifications</a>.
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$anyProvider): ?>
                    <div class="alert warning" style="display:flex;align-items:center;gap:10px;">
                        <span style="font-size:18px;">⚠️</span>
                        <div>
                            <strong>No SMS provider is configured.</strong>
                            Create <code>config.php</code> in the project root with your MTN / Airtel / eSMS credentials. See the "How routing works" panel at the bottom for the template.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Provider status -->
                <div class="section">
                    <div class="section-header">
                        <h2>📡 Provider Status</h2>
                        <a href="settings.php" class="btn btn-secondary btn-sm">⚙️ Notification settings</a>
                    </div>
                    <div class="provider-grid">
                        <div class="provider-card <?= $providerStatus['mtn'] ? 'ok' : 'bad' ?>">
                            <h4>🟡 MTN Zambia <span class="badge <?= $providerStatus['mtn'] ? 'ok' : 'bad' ?>"><?= $providerStatus['mtn'] ? 'Configured' : 'Not set' ?></span></h4>
                            <p>Handles numbers starting with <b>096</b> or <b>076</b>. OAuth2 client-credentials + SMS v3 API.</p>
                        </div>
                        <div class="provider-card <?= $providerStatus['airtel'] ? 'ok' : 'bad' ?>">
                            <h4>🔴 Airtel Zambia <span class="badge <?= $providerStatus['airtel'] ? 'ok' : 'bad' ?>"><?= $providerStatus['airtel'] ? 'Configured' : 'Not set' ?></span></h4>
                            <p>Handles numbers starting with <b>097</b> or <b>077</b>. Airtel IQ SMS API.</p>
                        </div>
                        <div class="provider-card <?= $providerStatus['esms'] ? 'ok' : 'bad' ?>">
                            <h4>⚪ Aggregator (eSMS) <span class="badge <?= $providerStatus['esms'] ? 'ok' : 'bad' ?>"><?= $providerStatus['esms'] ? 'Configured' : 'Not set' ?></span></h4>
                            <p>Handles Zamtel (<b>095</b>/<b>075</b>) and acts as fallback if a direct route fails.</p>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-grid">
                    <div class="stat-card"><div class="icon blue">📱</div><div class="info"><div class="number"><?= $stats['total'] ?></div><div class="label">Total SMS</div></div></div>
                    <div class="stat-card"><div class="icon green">✅</div><div class="info"><div class="number"><?= $stats['sent'] ?></div><div class="label">Sent</div></div></div>
                    <div class="stat-card"><div class="icon orange">⏳</div><div class="info"><div class="number"><?= $stats['pending'] ?></div><div class="label">Pending</div></div></div>
                    <div class="stat-card"><div class="icon red">❌</div><div class="info"><div class="number"><?= $stats['failed'] ?></div><div class="label">Failed</div></div></div>
                    <div class="stat-card"><div class="icon purple">📅</div><div class="info"><div class="number"><?= $stats['today'] ?></div><div class="label">Today</div></div></div>
                    <div class="stat-card"><div class="icon blue">📊</div><div class="info"><div class="number"><?= $stats['this_week'] ?></div><div class="label">This Week</div></div></div>
                </div>

                <!-- Network breakdown (7d) -->
                <div class="section" style="padding:14px 18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                        <div style="font-size:13px;font-weight:600;color:#0d3b22;">📡 Network breakdown (last 7 days)</div>
                        <div class="net-breakdown">
                            <span class="net-pill mtn">MTN: <?= (int)$netBreakdown['mtn'] ?></span>
                            <span class="net-pill airtel">Airtel: <?= (int)$netBreakdown['airtel'] ?></span>
                            <span class="net-pill zamtel">Zamtel: <?= (int)$netBreakdown['zamtel'] ?></span>
                            <?php if ($netBreakdown['unknown'] > 0): ?>
                                <span class="net-pill other">Unknown: <?= (int)$netBreakdown['unknown'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Two-column: Test SMS + Broadcast -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                    <!-- TEST SMS -->
                    <div class="section">
                        <div class="section-header"><h2>🧪 Send Test SMS</h2></div>
                        <form method="POST" id="testForm" novalidate>
                            <input type="hidden" name="action" value="test_sms">
                            <div class="form-group">
                                <label>Phone Number *</label>
                                <input type="tel" name="phone" id="testPhone" required placeholder="0971234567 or +260971234567" <?= !$setSmsEnabled ? 'disabled' : '' ?>>
                                <div class="field-hint" id="testPhoneHint">Must be a Zambian mobile (09/07 prefix).</div>
                            </div>
                            <div class="form-group">
                                <label>Message *</label>
                                <textarea name="content" id="testContent" rows="3" required placeholder="Test SMS from Wildlife Sentinel..." <?= !$setSmsEnabled ? 'disabled' : '' ?>>Test SMS from Wildlife Sentinel — <?= date('Y-m-d H:i') ?></textarea>
                                <div class="char-counter" id="testCounter">0 chars · 1 SMS</div>
                            </div>
                            <button type="submit" class="btn btn-primary" <?= !$setSmsEnabled ? 'disabled' : '' ?>>
                                <?= $setSmsEnabled ? '📤 Send Test SMS' : '⛔ SMS disabled' ?>
                            </button>
                            <?php if (!$setSmsEnabled): ?>
                                <div class="field-hint err" style="margin-top:8px;">
                                    SMS is disabled globally. <a href="settings.php">Enable it</a> to send.
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- BROADCAST SMS -->
                    <div class="section">
                        <div class="section-header"><h2>📢 Broadcast SMS</h2></div>
                        <form method="POST" id="broadcastForm">
                            <input type="hidden" name="action" value="broadcast">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Zone</label>
                                    <select name="zone_id" <?= !$setSmsEnabled ? 'disabled' : '' ?>>
                                        <option value="0">All Zones</option>
                                        <?php foreach ($zones as $z): ?>
                                            <option value="<?= (int)$z['id'] ?>"><?= htmlspecialchars($z['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Role</label>
                                    <select name="role" <?= !$setSmsEnabled ? 'disabled' : '' ?>>
                                        <option value="">All Roles</option>
                                        <option value="ranger">Rangers</option>
                                        <option value="scout">Scouts</option>
                                        <option value="zone_supervisor">Supervisors</option>
                                        <option value="tourism">Tourism</option>
                                        <option value="admin">Admins</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Message *</label>
                                <textarea name="content" id="broadcastContent" rows="3" required placeholder="Broadcast message..." <?= !$setSmsEnabled ? 'disabled' : '' ?>></textarea>
                                <div class="char-counter" id="broadcastCounter">0 chars · 1 SMS</div>
                                <div class="field-hint">Messages are routed per recipient — MTN, Airtel, or Zamtel (aggregator).</div>
                            </div>
                            <button type="submit" class="btn btn-primary" <?= !$setSmsEnabled ? 'disabled' : '' ?>>
                                <?= $setSmsEnabled ? '📢 Send Broadcast' : '⛔ SMS disabled' ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- FILTERS -->
                <div class="section">
                    <div class="section-header">
                        <h2>🔎 Filter SMS Logs</h2>
                        <div class="header-actions">
                            <?php if ($filterStatus || $filterType || $search): ?>
                                <a href="sms-gateway.php" class="btn btn-secondary btn-sm">✕ Clear filters</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="GET" class="filter-bar">
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All statuses</option>
                                <option value="pending"   <?= $filterStatus === 'pending'   ? 'selected' : '' ?>>Pending</option>
                                <option value="sent"      <?= $filterStatus === 'sent'      ? 'selected' : '' ?>>Sent</option>
                                <option value="delivered" <?= $filterStatus === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                <option value="failed"    <?= $filterStatus === 'failed'    ? 'selected' : '' ?>>Failed</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Type</label>
                            <select name="type">
                                <option value="">All types</option>
                                <option value="general"   <?= $filterType === 'general'   ? 'selected' : '' ?>>General</option>
                                <option value="incident"  <?= $filterType === 'incident'  ? 'selected' : '' ?>>Incident</option>
                                <option value="ai_alert"  <?= $filterType === 'ai_alert'  ? 'selected' : '' ?>>AI Alert</option>
                                <option value="manpower"  <?= $filterType === 'manpower'  ? 'selected' : '' ?>>Manpower</option>
                                <option value="system"    <?= $filterType === 'system'    ? 'selected' : '' ?>>System</option>
                                <option value="test"      <?= $filterType === 'test'      ? 'selected' : '' ?>>Test</option>
                                <option value="broadcast" <?= $filterType === 'broadcast' ? 'selected' : '' ?>>Broadcast</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Phone, message, or name">
                        </div>
                        <div class="filter-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">🔎 Apply</button>
                        </div>
                    </form>
                </div>

                <!-- SMS LOG LIST -->
                <div class="section">
                    <div class="section-header">
                        <h2>📋 SMS Logs (<?= $totalLogs ?>)</h2>
                        <div class="header-actions">
                            <span style="font-size:12px;color:#6c757d;">Page <?= $page ?> of <?= $totalPages ?></span>
                            <form method="POST" class="purge-form" onsubmit="return confirm('Purge SMS logs older than the specified number of days? This cannot be undone.')">
                                <input type="hidden" name="action" value="purge_old">
                                <input type="number" name="purge_days" value="30" min="1" max="365">
                                <button class="btn btn-secondary btn-sm">🧹 Purge old</button>
                            </form>
                        </div>
                    </div>

                    <?php if (count($logs) > 0): ?>
                        <table class="sms-table">
                            <thead>
                                <tr>
                                    <th style="width:50px;"></th>
                                    <th>Recipient</th>
                                    <th>Message</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                    <th style="width:110px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <?php
                                    $icon        = $typeIcons[$log['message_type']] ?? '💬';
                                    $statusClass = $statusColors[$log['status']]   ?? 'orange';
                                    $normalized  = normalizeZambianPhone($log['phone'] ?? '');
                                    $network     = $normalized ? networkNameFromPhone($normalized) : '';
                                    ?>
                                    <tr>
                                        <td data-label="">
                                            <span class="type-icon"><?= $icon ?></span>
                                        </td>
                                        <td data-label="Recipient">
                                            <div style="font-weight:600;font-size:13px;color:#0d3b22;">
                                                📱 <?= htmlspecialchars($log['phone']) ?>
                                            </div>
                                            <?php if (!empty($log['user_name'])): ?>
                                                <div style="font-size:11px;color:#6c757d;">
                                                    <?= htmlspecialchars($log['user_name']) ?>
                                                    <?php if (!empty($log['user_role'])): ?>
                                                        (<?= htmlspecialchars($log['user_role']) ?>)
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($network): ?>
                                                <span class="network-pill"><?= htmlspecialchars($network) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Message">
                                            <div class="msg-preview" title="<?= htmlspecialchars($log['message']) ?>">
                                                <?= htmlspecialchars(substr($log['message'], 0, 90)) ?>
                                                <?= strlen($log['message']) > 90 ? '…' : '' ?>
                                            </div>
                                        </td>
                                        <td data-label="Type">
                                            <span style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">
                                                <?= htmlspecialchars($log['message_type']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status-pill <?= $statusClass ?>">
                                                <?= htmlspecialchars($log['status']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Time">
                                            <div style="font-size:12px;"><?= timeAgo($log['created_at']) ?></div>
                                            <div style="font-size:11px;color:#adb5bd;">
                                                <?= date('M j, Y H:i', strtotime($log['created_at'])) ?>
                                            </div>
                                        </td>
                                        <td data-label="Actions">
                                            <?php if ($log['status'] === 'failed' && $setSmsEnabled): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Retry sending this SMS?')">
                                                    <input type="hidden" name="action" value="retry_sms">
                                                    <input type="hidden" name="log_id" value="<?= (int)$log['id'] ?>">
                                                    <button class="btn btn-xs btn-primary" title="Retry">🔁</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this log entry?')">
                                                <input type="hidden" name="action" value="delete_log">
                                                <input type="hidden" name="log_id" value="<?= (int)$log['id'] ?>">
                                                <button class="btn btn-xs btn-danger" title="Delete">🗑️</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <?php
                            $qs = $_GET; unset($qs['page']);
                            $q  = http_build_query($qs);
                            $q  = $q ? '&' . $q : '';
                            ?>
                            <div class="pagination">
                                <a href="?page=1<?= $q ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">«</a>
                                <a href="?page=<?= max(1, $page-1) ?><?= $q ?>" class="<?= $page <= 1 ? 'disabled' : '' ?>">‹ Prev</a>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($totalPages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <a href="?page=<?= $i ?><?= $q ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
                                <?php endfor; ?>
                                <a href="?page=<?= min($totalPages, $page+1) ?><?= $q ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">Next ›</a>
                                <a href="?page=<?= $totalPages ?><?= $q ?>" class="<?= $page >= $totalPages ? 'disabled' : '' ?>">»</a>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="empty-state">
                            <span class="icon">📱</span>
                            <h3>No SMS logs found</h3>
                            <p>Send a test SMS or broadcast to populate the log.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- GATEWAY CONFIG NOTICE -->
                <div class="section" style="border-left:4px solid #cce5ff;background:#f8fbff;">
                    <div style="font-size:13px;color:#495057;line-height:1.7;">
                        <strong>ℹ️ How routing works</strong><br>
                        • The recipient's phone prefix chooses the network:
                          <b>096/076 → MTN Zambia</b>, <b>097/077 → Airtel Zambia</b>, <b>095/075 → Zamtel</b> (via aggregator).<br>
                        • If a direct MTN/Airtel send fails, the message is automatically retried through the aggregator (when configured).<br>
                        • Credentials live in <code>config.php</code> in the project root — never in this file.<br>
                        • Every send is written to <code>sms_logs</code> with the recipient, message type, and status.<br>
                        • Automated notifications respect <code>sms_enabled</code> and the per-event <code>notify_on_*</code> settings in <a href="settings.php">System Settings</a>.<br>
                        • Manual sends (test / broadcast / retry) are blocked when <code>sms_enabled = 0</code>.
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/app.js"></script>
    <script src="../assets/js/transitions.js"></script>
    <script>
        // ============================================================
        // PHONE → NETWORK HINT (Test SMS form)
        // ============================================================
        const testPhone     = document.getElementById('testPhone');
        const testPhoneHint = document.getElementById('testPhoneHint');

        function detectNetwork(phone) {
            if (!phone) return null;
            let n = phone.replace(/[^0-9]/g, '');
            if (n.indexOf('260') === 0) n = n.substring(3);
            if (!/^(09|07)[0-9]{8}$/.test(n)) return null;
            const p = n.substring(0, 3);
            if (p === '096' || p === '076') return 'MTN Zambia';
            if (p === '097' || p === '077') return 'Airtel Zambia';
            if (p === '095' || p === '075') return 'Zamtel';
            return 'Unknown';
        }

        function updateTestPhoneHint() {
            const phone = testPhone.value.trim();
            if (!phone) {
                testPhoneHint.textContent = 'Must be a Zambian mobile (09/07 prefix).';
                testPhoneHint.className = 'field-hint';
                return;
            }
            const net = detectNetwork(phone);
            if (net) {
                testPhoneHint.textContent = '📡 Will route via ' + net;
                testPhoneHint.className = 'field-hint ok';
            } else {
                testPhoneHint.textContent = 'Must be a Zambian mobile (09/07 prefix).';
                testPhoneHint.className = 'field-hint err';
            }
        }
        if (testPhone) {
            testPhone.addEventListener('input', updateTestPhoneHint);
            testPhone.addEventListener('blur',  updateTestPhoneHint);
        }

        // ============================================================
        // CHARACTER COUNTER
        // ============================================================
        function updateCounter(inputId, counterId) {
            const ta = document.getElementById(inputId);
            const co = document.getElementById(counterId);
            if (!ta || !co) return;
            const len = ta.value.length;
            const segs = len <= 160 ? 1 : Math.ceil(len / 153);
            co.textContent = len + ' chars · ' + segs + ' SMS';
            co.classList.toggle('over', len > 160);
        }
        ['testContent', 'broadcastContent'].forEach(function (id) {
            const ta = document.getElementById(id);
            if (!ta) return;
            const counterId = id === 'testContent' ? 'testCounter' : 'broadcastCounter';
            ta.addEventListener('input', function () { updateCounter(id, counterId); });
            updateCounter(id, counterId);
        });

        // ============================================================
        // SUBMIT GUARDS
        // ============================================================
        const testForm = document.getElementById('testForm');
        if (testForm) {
            testForm.addEventListener('submit', function (e) {
                const phone = testPhone.value.trim();
                const net   = detectNetwork(phone);
                if (!net) {
                    e.preventDefault();
                    alert('Please enter a valid Zambian phone number (09xxxxxxxx or 07xxxxxxxx).');
                    testPhone.focus();
                    return false;
                }
            });
        }

        const broadcastForm = document.getElementById('broadcastForm');
        if (broadcastForm) {
            broadcastForm.addEventListener('submit', function (e) {
                const zone = this.querySelector('[name="zone_id"]').value;
                const role = this.querySelector('[name="role"]').value;
                let scope = 'ALL users';
                if (zone !== '0' && role !== '') scope = role + ' in selected zone';
                else if (zone !== '0')           scope = 'all users in selected zone';
                else if (role !== '')            scope = 'all ' + role + 's system-wide';
                if (!confirm('Broadcast this SMS to ' + scope + '?\n\nThis cannot be undone.')) {
                    e.preventDefault();
                    return false;
                }
            });
        }

        console.log('✅ Admin SMS Gateway loaded');
    </script>
</body>
</html>
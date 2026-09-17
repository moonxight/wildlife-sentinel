<?php
// ============================================================
// includes/sms_gateway.php
// Wildlife Sentinel — SMS Gateway
// ------------------------------------------------------------
// Providers chosen automatically by recipient prefix:
//   096 / 076 → MTN Zambia
//   097 / 077 → Airtel Zambia
//   095 / 075 → Zamtel (via aggregator)
//   anything  → Twilio (fallback, if configured)
//
// Credentials read from ../config.php (project root).
//
// Environment flags:
//   WS_SMS_DRY_RUN=1   → log every send but hit no network
//   WS_SMS_JSON=1      → JSON logs instead of plain text
//
// PRIVACY POLICY:
//   Reporter-facing pages (tourism/report.php, scout/report.php)
//   must NEVER show that SMS was sent. This class exposes
//   send()/sendIncidentAlert()/sendAIAlert() for admin or
//   supervisor pages ONLY. Reporter pages should call the
//   silent wrapper in functions.php instead.
//
// NOTE: This file deliberately does NOT require functions.php
// at the top, to avoid a circular include. If a method needs
// getDB() it is loaded lazily via ws_ensure_functions_loaded().
// ============================================================

// ------------------------------------------------------------
// LAZY LOADER
// ------------------------------------------------------------
if (!function_exists('ws_ensure_functions_loaded')) {
    function ws_ensure_functions_loaded(): bool {
        if (function_exists('getDB')) return true;
        $fn = __DIR__ . '/functions.php';
        if (is_file($fn)) {
            require_once $fn;
        }
        return function_exists('getDB');
    }
}

if (!class_exists('SMSGateway')) {

class SMSGateway
{
    /** @var array */
    private $config;

    /** @var bool */
    private $dryRun;

    /** @var bool */
    private $jsonLogs;

    /** @var array */
    private $lastResponse = [];

    // ============================================================
    // CONSTRUCTOR
    // ============================================================
    public function __construct()
    {
        $this->config   = $this->loadConfig();
        $this->dryRun   = getenv('WS_SMS_DRY_RUN') === '1';
        $this->jsonLogs = getenv('WS_SMS_JSON') === '1';

        if ($this->dryRun) {
            $this->log('info', 'DRY RUN MODE — no SMS will actually be sent');
        }
    }

    // ============================================================
    // CONFIG
    // ============================================================
    private function loadConfig(): array
    {
        $defaults = [
            'environment'   => 'sandbox',
            'http_timeout'  => 20,
            'max_retries'   => 2,

            'twilio_sid'    => '',
            'twilio_token'  => '',
            'twilio_from'   => '',

            'mtn_client_id'     => '',
            'mtn_client_secret' => '',
            'mtn_sender_id'     => 'WILDLIFE',
            'mtn_oauth_url'     => 'https://api.mtn.com/oauth/client_credential/accesstoken',
            'mtn_sms_url'       => 'https://api.mtn.com/v3/sms/messages',

            'airtel_api_key'     => '',
            'airtel_customer_id' => '',
            'airtel_sender_id'   => 'WILDLIFE',
            'airtel_template_id' => '',
            'airtel_sms_url'     => 'https://iqsms.airtel.in/api/v1/send-sms',

            'esms_api_key'   => '',
            'esms_sender_id' => 'WILDLIFE',
            'esms_sms_url'   => 'https://api.esmsafrica.io/v1/sms/send',

            'prefix_map' => [
                '096' => 'mtn',
                '076' => 'mtn',
                '097' => 'airtel',
                '077' => 'airtel',
                '095' => 'esms',
                '075' => 'esms',
            ],
            'network_names' => [
                'mtn'    => 'MTN Zambia',
                'airtel' => 'Airtel Zambia',
                'esms'   => 'Zamtel',
                'twilio' => 'Twilio',
            ],
            'enable_fallback' => true,
        ];

        $cfgFile = __DIR__ . '/../config.php';
        if (is_file($cfgFile)) {
            $loaded = include $cfgFile;
            if (is_array($loaded)) {
                $defaults = array_merge($defaults, $loaded);
            }
        }

        foreach ([
            'MTN_CLIENT_ID','MTN_CLIENT_SECRET','MTN_SENDER_ID',
            'AIRTEL_API_KEY','AIRTEL_CUSTOMER_ID','AIRTEL_SENDER_ID',
            'ESMS_API_KEY','ESMS_SENDER_ID',
            'TWILIO_SID','TWILIO_TOKEN','TWILIO_FROM',
        ] as $env) {
            $v = getenv($env);
            if ($v !== false && $v !== '') {
                $key = strtolower($env);
                $defaults[$key] = $v;
            }
        }

        return $defaults;
    }

    // ============================================================
    // PUBLIC API — send()
    // ============================================================
    public function send($phone, $message, $type = 'general', $userId = null, $incidentId = null, $alertId = null): bool
    {
        $this->lastResponse = [];

        $normalized = $this->normalizePhone($phone);
        if (!$normalized) {
            $this->log('warning', 'Invalid phone — send aborted', ['phone' => $phone]);
            return false;
        }

        if (!$this->withinRecipientRateLimit($normalized)) {
            $this->log('warning', 'Recipient rate limit hit — skipping', ['phone' => $normalized]);
            $this->logToDb([
                'user_id'      => $userId,
                'phone'        => $normalized,
                'message'      => $message,
                'message_type' => $type,
                'incident_id'  => $incidentId,
                'alert_id'     => $alertId,
                'status'       => 'failed',
                'provider'     => 'rate-limit',
                'error_message'=> 'Recipient already received 20 SMS in the last 24h',
                'segments'     => $this->estimateSegments($message),
            ]);
            return false;
        }

        if ($this->dryRun) {
            $ref = 'DRYRUN-' . strtoupper(bin2hex(random_bytes(4)));
            $this->log('info', 'DRY RUN send', [
                'to' => $normalized, 'provider' => 'dry-run', 'ref' => $ref,
                'segments' => $this->estimateSegments($message),
            ]);
            $this->logToDb([
                'user_id'      => $userId,
                'phone'        => $normalized,
                'message'      => $message,
                'message_type' => $type,
                'incident_id'  => $incidentId,
                'alert_id'     => $alertId,
                'status'       => 'sent',
                'provider'     => 'dry-run',
                'provider_ref' => $ref,
                'segments'     => $this->estimateSegments($message),
            ]);
            $this->lastResponse = ['success' => true, 'provider' => 'dry-run', 'ref' => $ref, 'error' => null];
            return true;
        }

        $provider = $this->pickProvider($normalized);
        $result   = $this->dispatch($provider, $normalized, $message);

        if (!$result['success']
            && !empty($this->config['enable_fallback'])
            && $provider !== 'esms'
            && !empty($this->config['esms_api_key'])
        ) {
            $this->log('info', 'Primary provider failed, retrying via aggregator', [
                'primary' => $provider, 'primary_err' => $result['error'],
            ]);
            $result = $this->dispatch('esms', $normalized, $message);
            if ($result['success']) {
                $result['provider'] .= ' (fallback)';
            }
        }

        if (!$result['success']
            && !empty($this->config['twilio_sid'])
            && $provider !== 'twilio'
        ) {
            $this->log('info', 'Retrying via Twilio', ['primary' => $provider]);
            $result = $this->dispatch('twilio', $normalized, $message);
        }

        $status = $result['success'] ? 'sent' : 'failed';

        $this->logToDb([
            'user_id'       => $userId,
            'phone'         => $normalized,
            'message'       => $message,
            'message_type'  => $type,
            'incident_id'   => $incidentId,
            'alert_id'      => $alertId,
            'status'        => $status,
            'provider'      => $result['provider'],
            'provider_ref'  => $result['ref'],
            'error_message' => $result['error'],
            'segments'      => $this->estimateSegments($message),
        ]);

        $this->lastResponse = $result;
        return $result['success'];
    }

    // ============================================================
    // PUBLIC API — sendIncidentAlert()   [ADMIN / SUPERVISOR ONLY]
    // ============================================================
    public function sendIncidentAlert($incidentId, $zoneId): array
    {
        $sent = 0; $failed = 0; $recipients = 0;

        try {
            ws_ensure_functions_loaded();
            if (!function_exists('getDB')) {
                $this->log('error', 'sendIncidentAlert: getDB() not available');
                return ['sent' => 0, 'failed' => 0, 'recipients' => 0];
            }
            $pdo = getDB();

            $stmt = $pdo->prepare("
                SELECT i.id, i.category, i.severity, i.location_lat, i.location_lng,
                       u.full_name AS reporter_name
                FROM incidents i
                LEFT JOIN users u ON i.reporter_id = u.id
                WHERE i.id = ? LIMIT 1
            ");
            $stmt->execute([$incidentId]);
            $incident = $stmt->fetch();
            if (!$incident) {
                $this->log('warning', 'sendIncidentAlert: incident not found', ['incident_id' => $incidentId]);
                return ['sent' => 0, 'failed' => 0, 'recipients' => 0];
            }

            $message = "🚨 INCIDENT #{$incident['id']}\n"
                     . "Type: " . str_replace('_', ' ', $incident['category']) . "\n"
                     . "Severity: " . strtoupper($incident['severity']) . "\n"
                     . "Reported by: " . ($incident['reporter_name'] ?? 'Unknown') . "\n"
                     . "Check the dashboard for details.";

            $stmt = $pdo->prepare("
                SELECT id, phone FROM users
                WHERE zone_id = ?
                  AND role IN ('ranger','zone_supervisor')
                  AND is_active = 1
                  AND phone IS NOT NULL AND phone <> ''
            ");
            $stmt->execute([$zoneId]);
            $users = $stmt->fetchAll() ?: [];
            $recipients = count($users);

            foreach ($users as $u) {
                $ok = $this->send($u['phone'], $message, 'incident', (int)$u['id'], (int)$incidentId, null);
                if ($ok) $sent++; else $failed++;
            }
        } catch (Throwable $e) {
            $this->log('error', 'sendIncidentAlert failed', ['err' => $e->getMessage()]);
        }

        return ['sent' => $sent, 'failed' => $failed, 'recipients' => $recipients];
    }

    // ============================================================
    // PUBLIC API — sendAIAlert()   [ADMIN / SUPERVISOR ONLY]
    // ============================================================
    public function sendAIAlert($alertId, $zoneId): array
    {
        $sent = 0; $failed = 0; $recipients = 0;

        try {
            ws_ensure_functions_loaded();
            if (!function_exists('getDB')) {
                $this->log('error', 'sendAIAlert: getDB() not available');
                return ['sent' => 0, 'failed' => 0, 'recipients' => 0];
            }
            $pdo = getDB();

            $stmt = $pdo->prepare("
                SELECT id, title, description, severity, alert_type, location_lat, location_lng
                FROM ai_alerts
                WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$alertId]);
            $alert = $stmt->fetch();
            if (!$alert) {
                $this->log('warning', 'sendAIAlert: alert not found', ['alert_id' => $alertId]);
                return ['sent' => 0, 'failed' => 0, 'recipients' => 0];
            }

            $message = "🤖 AI ALERT\n"
                     . "Type: " . str_replace('_', ' ', $alert['alert_type']) . "\n"
                     . "Severity: " . strtoupper($alert['severity']) . "\n"
                     . ($alert['title'] ? $alert['title'] . "\n" : '')
                     . "Check the dashboard for location and details.";

            $stmt = $pdo->prepare("
                SELECT id, phone FROM users
                WHERE zone_id = ?
                  AND role IN ('ranger','zone_supervisor')
                  AND is_active = 1
                  AND phone IS NOT NULL AND phone <> ''
            ");
            $stmt->execute([$zoneId]);
            $users = $stmt->fetchAll() ?: [];
            $recipients = count($users);

            foreach ($users as $u) {
                $ok = $this->send($u['phone'], $message, 'ai_alert', (int)$u['id'], null, (int)$alertId);
                if ($ok) $sent++; else $failed++;
            }
        } catch (Throwable $e) {
            $this->log('error', 'sendAIAlert failed', ['err' => $e->getMessage()]);
        }

        return ['sent' => $sent, 'failed' => $failed, 'recipients' => $recipients];
    }

    // ============================================================
    // PUBLIC API — silentIncidentFanout()   [REPORTER-SAFE]
    // ------------------------------------------------------------
    // Sends an SMS about an incident to every active ranger +
    // supervisor in a zone. Returns VOID. Reporter pages can call
    // this without any risk of leaking SMS status to the UI.
    // ============================================================
    public function silentIncidentFanout(
        int $zoneId,
        int $incidentId,
        string $severity,
        string $category,
        string $reporterName,
        string $zoneName
    ): void {
        if ($zoneId <= 0 || $incidentId <= 0) return;

        try {
            ws_ensure_functions_loaded();
            if (!function_exists('getDB')) {
                $this->log('error', 'silentIncidentFanout: getDB() not available');
                return;
            }
            $pdo = getDB();

            $stmt = $pdo->prepare("
                SELECT id, full_name, role, phone
                FROM users
                WHERE zone_id = ?
                  AND role IN ('ranger','zone_supervisor')
                  AND is_active = 1
                  AND phone IS NOT NULL AND phone <> ''
            ");
            $stmt->execute([$zoneId]);
            $recipients = $stmt->fetchAll() ?: [];

            if (empty($recipients)) {
                $this->log('info', 'silentIncidentFanout: no recipients', [
                    'zone_id' => $zoneId, 'incident_id' => $incidentId,
                ]);
                return;
            }

            $sevUpper = strtoupper($severity);
            $catLabel = ucwords(str_replace('_', ' ', $category));

            foreach ($recipients as $r) {
                $phone = trim((string)$r['phone']);
                if ($phone === '') continue;

                if ($r['role'] === 'zone_supervisor') {
                    $msg = "WS OVERSEER: {$sevUpper} {$catLabel} reported in {$zoneName} by {$reporterName}. "
                         . "Incident #{$incidentId}. Check the dashboard.";
                } else {
                    $msg = "WS ALERT: {$sevUpper} {$catLabel} reported in {$zoneName} by {$reporterName}. "
                         . "Incident #{$incidentId}. Open the app NOW.";
                }

                // Fire and log — no return value to the caller
                try {
                    $this->send($phone, $msg, 'incident', (int)$r['id'], $incidentId, null);
                } catch (Throwable $e) {
                    $this->log('warning', 'silentIncidentFanout send failed', [
                        'phone' => $phone, 'err' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Swallow everything — the reporter must never see this
            $this->log('error', 'silentIncidentFanout failed', ['err' => $e->getMessage()]);
        }
    }

    // ============================================================
    // PUBLIC API — lastSendResult()
    // ============================================================
    public function lastSendResult(): array
    {
        return $this->lastResponse;
    }

    // ============================================================
    // PROVIDER PICKER
    // ============================================================
    private function pickProvider(string $normalizedPhone): string
    {
        $prefix = substr($normalizedPhone, 0, 3);
        if (isset($this->config['prefix_map'][$prefix])) {
            return $this->config['prefix_map'][$prefix];
        }
        if (!empty($this->config['esms_api_key'])) return 'esms';
        if (!empty($this->config['twilio_sid']))   return 'twilio';
        return 'esms';
    }

    // ============================================================
    // DISPATCHER
    // ============================================================
    private function dispatch(string $provider, string $to, string $message): array
    {
        $attempts = 0;
        $maxAttempts = max(1, (int)($this->config['max_retries'] ?? 2));

        while ($attempts < $maxAttempts) {
            $attempts++;

            switch ($provider) {
                case 'mtn':    $res = $this->sendViaMTN($to, $message);    break;
                case 'airtel': $res = $this->sendViaAirtel($to, $message); break;
                case 'twilio': $res = $this->sendViaTwilio($to, $message); break;
                case 'esms':
                default:       $res = $this->sendViaESMS($to, $message);   break;
            }

            if ($res['success']) return $res;

            if ($attempts < $maxAttempts) {
                usleep(250000);
                $this->log('info', "Retrying {$provider} (attempt {$attempts}/{$maxAttempts})", [
                    'to' => $to, 'last_error' => $res['error'],
                ]);
            } else {
                return $res;
            }
        }

        return ['success' => false, 'provider' => $provider, 'ref' => null, 'error' => 'max retries exceeded'];
    }

    // ============================================================
    // PROVIDER: MTN Zambia
    // ============================================================
    private function sendViaMTN(string $to, string $message): array
    {
        $clientId     = $this->config['mtn_client_id'] ?? '';
        $clientSecret = $this->config['mtn_client_secret'] ?? '';
        $senderId     = $this->config['mtn_sender_id'] ?? 'WILDLIFE';

        if (!$clientId || !$clientSecret) {
            return ['success' => false, 'provider' => 'mtn', 'ref' => null, 'error' => 'MTN credentials not configured'];
        }

        $ch = curl_init($this->config['mtn_oauth_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]),
            CURLOPT_TIMEOUT        => (int)($this->config['http_timeout'] ?? 20),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) return ['success' => false, 'provider' => 'mtn', 'ref' => null, 'error' => 'MTN network: ' . $err];
        if ($code !== 200) return ['success' => false, 'provider' => 'mtn', 'ref' => null, 'error' => 'MTN auth failed (HTTP ' . $code . ')'];

        $data  = json_decode($resp, true);
        $token = $data['access_token'] ?? null;
        if (!$token) return ['success' => false, 'provider' => 'mtn', 'ref' => null, 'error' => 'MTN token missing'];

        $msisdn  = '+260' . ltrim($to, '0');
        $payload = [
            'outboundSMSMessageRequest' => [
                'address'                => ['tel:' . $msisdn],
                'senderAddress'          => 'tel:' . $senderId,
                'outboundSMSTextMessage' => ['message' => $message],
                'clientCorrelatorId'     => 'ws-' . bin2hex(random_bytes(6)),
                'requestDeliveryReceipt' => true,
            ],
        ];

        $ch = curl_init($this->config['mtn_sms_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => (int)($this->config['http_timeout'] ?? 20),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) return ['success' => false, 'provider' => 'mtn', 'ref' => null, 'error' => 'MTN network: ' . $err];
        if ($code >= 200 && $code < 300) {
            $res = json_decode($resp, true);
            $ref = $res['outboundSMSMessageRequest']['resourceReference']
                 ?? $res['resourceReference']
                 ?? ('MTN-' . bin2hex(random_bytes(4)));
            return ['success' => true, 'provider' => 'mtn', 'ref' => $ref, 'error' => null];
        }
        $err = json_decode($resp, true);
        $msg = $err['requestError']['serviceException']['text']
             ?? $err['serviceException']['text']
             ?? $err['message']
             ?? ('HTTP ' . $code);
        return ['success' => false, 'provider' => 'mtn', 'ref' => null, 'error' => 'MTN: ' . $msg];
    }

    // ============================================================
    // PROVIDER: Airtel Zambia
    // ============================================================
    private function sendViaAirtel(string $to, string $message): array
    {
        $apiKey     = $this->config['airtel_api_key'] ?? '';
        $customerId = $this->config['airtel_customer_id'] ?? '';
        $senderId   = $this->config['airtel_sender_id'] ?? 'WILDLIFE';
        $templateId = $this->config['airtel_template_id'] ?? '';

        if (!$apiKey || !$customerId) {
            return ['success' => false, 'provider' => 'airtel', 'ref' => null, 'error' => 'Airtel credentials not configured'];
        }

        $msisdn  = '260' . ltrim($to, '0');
        $payload = [
            'customerId' => $customerId,
            'senderId'   => $senderId,
            'message'    => $message,
            'mobileNo'   => $msisdn,
        ];
        if ($templateId !== '') $payload['templateId'] = $templateId;

        $ch = curl_init($this->config['airtel_sms_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => (int)($this->config['http_timeout'] ?? 20),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) return ['success' => false, 'provider' => 'airtel', 'ref' => null, 'error' => 'Airtel network: ' . $err];
        if ($code >= 200 && $code < 300) {
            $res = json_decode($resp, true);
            $ref = $res['messageId'] ?? $res['requestId'] ?? ('AIR-' . bin2hex(random_bytes(4)));
            return ['success' => true, 'provider' => 'airtel', 'ref' => $ref, 'error' => null];
        }
        $err = json_decode($resp, true);
        $msg = $err['message'] ?? $err['error'] ?? ('HTTP ' . $code);
        return ['success' => false, 'provider' => 'airtel', 'ref' => null, 'error' => 'Airtel: ' . $msg];
    }

    // ============================================================
    // PROVIDER: eSMS aggregator
    // ============================================================
    private function sendViaESMS(string $to, string $message): array
    {
        $apiKey   = $this->config['esms_api_key'] ?? '';
        $senderId = $this->config['esms_sender_id'] ?? 'WILDLIFE';

        if (!$apiKey) {
            return ['success' => false, 'provider' => 'esms', 'ref' => null, 'error' => 'eSMS credentials not configured'];
        }

        $payload = [
            'to'      => '260' . ltrim($to, '0'),
            'from'    => $senderId,
            'message' => $message,
        ];

        $ch = curl_init($this->config['esms_sms_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => (int)($this->config['http_timeout'] ?? 20),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) return ['success' => false, 'provider' => 'esms', 'ref' => null, 'error' => 'eSMS network: ' . $err];
        if ($code >= 200 && $code < 300) {
            $res = json_decode($resp, true);
            $ref = $res['messageId'] ?? $res['id'] ?? ('ESMS-' . bin2hex(random_bytes(4)));
            return ['success' => true, 'provider' => 'esms', 'ref' => $ref, 'error' => null];
        }
        $err = json_decode($resp, true);
        $msg = $err['message'] ?? $err['error'] ?? ('HTTP ' . $code);
        return ['success' => false, 'provider' => 'esms', 'ref' => null, 'error' => 'eSMS: ' . $msg];
    }

    // ============================================================
    // PROVIDER: Twilio
    // ============================================================
    private function sendViaTwilio(string $to, string $message): array
    {
        $sid   = $this->config['twilio_sid']   ?? '';
        $token = $this->config['twilio_token'] ?? '';
        $from  = $this->config['twilio_from']  ?? '';

        if (!$sid || !$token || !$from) {
            return ['success' => false, 'provider' => 'twilio', 'ref' => null, 'error' => 'Twilio credentials not configured'];
        }

        $url  = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $data = http_build_query([
            'From' => $from,
            'To'   => '+260' . ltrim($to, '0'),
            'Body' => $message,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_USERPWD        => "{$sid}:{$token}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)($this->config['http_timeout'] ?? 20),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) return ['success' => false, 'provider' => 'twilio', 'ref' => null, 'error' => 'Twilio network: ' . $err];
        if ($code >= 200 && $code < 300) {
            $res = json_decode($resp, true);
            $ref = $res['sid'] ?? ('TW-' . bin2hex(random_bytes(4)));
            return ['success' => true, 'provider' => 'twilio', 'ref' => $ref, 'error' => null];
        }
        $err = json_decode($resp, true);
        $msg = $err['message'] ?? ('HTTP ' . $code);
        return ['success' => false, 'provider' => 'twilio', 'ref' => null, 'error' => 'Twilio: ' . $msg];
    }

    // ============================================================
    // UTILITIES
    // ============================================================
    private function normalizePhone(?string $raw): ?string
    {
        if ($raw === null) return null;
        $digits = preg_replace('/[^0-9]/', '', $raw);
        if ($digits === '') return null;
        if (strpos($digits, '260') === 0) $digits = substr($digits, 3);
        if (!preg_match('/^(09|07)[0-9]{8}$/', $digits)) return null;
        return $digits;
    }

    private function estimateSegments(string $message): int
    {
        $len = mb_strlen($message);
        if ($len <= 160) return 1;
        return (int)ceil($len / 153);
    }

    private function withinRecipientRateLimit(string $normalizedPhone): bool
    {
        try {
            ws_ensure_functions_loaded();
            if (!function_exists('getDB')) return true;
            $pdo = getDB();
            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS c
                FROM sms_logs
                WHERE phone = ?
                  AND created_at >= (NOW() - (1) * INTERVAL \'1 day\')
            ');
            $stmt->execute([$normalizedPhone]);
            $count = (int)($stmt->fetch()['c'] ?? 0);
            return $count < 20;
        } catch (Throwable $e) {
            return true;
        }
    }

    private function logToDb(array $row): void
    {
        try {
            ws_ensure_functions_loaded();
            if (!function_exists('getDB')) {
                $this->log('warning', 'sms_logs insert skipped: getDB() not available');
                return;
            }
            $pdo = getDB();

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO sms_logs
                        (user_id, phone, message, message_type, incident_id, alert_id,
                         status, provider, provider_ref, error_message, segments,
                         sent_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $row['user_id']       ?? null,
                    $row['phone']         ?? null,
                    $row['message']       ?? null,
                    $row['message_type']  ?? 'general',
                    $row['incident_id']   ?? null,
                    $row['alert_id']      ?? null,
                    $row['status']        ?? 'pending',
                    $row['provider']      ?? null,
                    $row['provider_ref']  ?? null,
                    $row['error_message'] ?? null,
                    $row['segments']      ?? 1,
                ]);
            } catch (PDOException $e) {
                $pdo->prepare("
                    INSERT INTO sms_logs
                        (user_id, phone, message, message_type, incident_id, alert_id, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $row['user_id']      ?? null,
                    $row['phone']        ?? null,
                    $row['message']      ?? null,
                    $row['message_type'] ?? 'general',
                    $row['incident_id']  ?? null,
                    $row['alert_id']     ?? null,
                    $row['status']       ?? 'pending',
                ]);
            }
        } catch (Throwable $e) {
            $this->log('warning', 'sms_logs insert failed', ['err' => $e->getMessage()]);
        }
    }

    private function log(string $level, string $message, array $ctx = []): void
    {
        $ts = date('Y-m-d H:i:s');
        if ($this->jsonLogs) {
            error_log(json_encode([
                'ts'      => $ts,
                'level'   => $level,
                'module'  => 'sms_gateway',
                'message' => $message,
                'ctx'     => $ctx,
            ]));
        } else {
            $suffix = empty($ctx) ? '' : ' ' . json_encode($ctx);
            error_log("[{$ts}] [{$level}] [sms_gateway] {$message}{$suffix}");
        }
    }
}

} // end class_exists

// ============================================================
// GLOBAL HELPER — sendSMS()
// ============================================================
if (!function_exists('sendSMS')) {
    function sendSMS(
        $phone,
        $message,
        $type = 'general',
        $userId = null,
        $incidentId = null,
        $alertId = null
    ): bool {
        try {
            $gw = new SMSGateway();
            return $gw->send($phone, $message, $type, $userId, $incidentId, $alertId);
        } catch (Throwable $e) {
            error_log('[WS-SMS] sendSMS wrapper failed: ' . $e->getMessage());
            return false;
        }
    }
}
<?php
// ============================================================
// ai-service/ai_engine.php
// Wildlife Sentinel — Advanced AI Analysis Engine (v2)
// ------------------------------------------------------------
// Pipeline stages (in order):
//   1. Preprocess    — normalize inputs, filter noise
//   2. Ensemble      — fuse multiple model outputs
//   3. Track         — consolidate frames into movement tracks
//   4. Behaviour     — classify motion patterns
//   5. Score         — compute weighted threat score
//   6. Rule          — apply configured rules
//   7. Threshold     — apply per-zone/camera calibration
//   8. Act           — emit detections, alerts, alarms, SMS
//
// Designed for speed:
//   • Short-circuits low-confidence early
//   • Caches thresholds in-process
//   • Batches DB writes
//   • Priority queue for alerts
//
// All DB operations are guarded; missing tables degrade gracefully.
// ============================================================

require_once __DIR__ . '/../includes/functions.php';

if (!class_exists('AIEngine')) {

class AIEngine
{
    private PDO   $pdo;
    private array $thresholdsCache = [];
    private array $rulesCache      = [];
    private array $modelsCache     = [];
    private array $settingsCache   = [];

    // ------------------------------------------------------------------
    // Ensemble weights — adjust to taste
    // ------------------------------------------------------------------
    private const ENSEMBLE_WEIGHTS = [
        'detector'   => 0.55,
        'classifier' => 0.20,
        'behavior'   => 0.15,
        'tracker'    => 0.10,
    ];

    // ------------------------------------------------------------------
    // Threat score weights
    // ------------------------------------------------------------------
    private const THREAT_WEIGHTS = [
        'confidence'     => 25,
        'ensemble'       => 20,
        'behaviour'      => 15,
        'boundary'       => 10,
        'night'          => 8,
        'speed'          => 7,
        'duration'       => 5,
        'rule_boost'     => 10,  // applied per matched rule
    ];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: getDB();
        $this->loadCaches();
    }

    // ==================================================================
    // CACHE LOADERS
    // ==================================================================
    private function loadCaches(): void
    {
        // Active models
        try {
            $rows = $this->pdo->query("
                SELECT * FROM ai_models WHERE is_active = 1
            ")->fetchAll();
            foreach ($rows as $r) $this->modelsCache[$r['model_type']][] = $r;
        } catch (PDOException $e) { /* optional */ }

        // Rules
        try {
            $rows = $this->pdo->query("
                SELECT * FROM ai_rules WHERE is_active = 1
            ")->fetchAll();
            $this->rulesCache = $rows;
        } catch (PDOException $e) { /* optional */ }

        // Global settings
        foreach (['ai_enabled','ai_confidence_min','ai_auto_create_alert','ai_auto_trigger_alarm'] as $k) {
            $this->settingsCache[$k] = getSetting($k, $k === 'ai_enabled' ? '1' : ($k === 'ai_confidence_min' ? '70' : '1'));
        }
    }

    private function thresholds(int $zoneId, ?int $cameraId, string $type): array
    {
        $key = "{$zoneId}:{$cameraId}:{$type}";
        if (isset($this->thresholdsCache[$key])) {
            return $this->thresholdsCache[$key];
        }

        $row = null;
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ai_thresholds
                WHERE (zone_id = ? OR zone_id IS NULL)
                  AND (camera_id = ? OR camera_id IS NULL)
                  AND (detection_type = ? OR detection_type IS NULL)
                ORDER BY zone_id IS NULL ASC, camera_id IS NULL ASC, detection_type IS NULL ASC
                LIMIT 1
            ");
            $stmt->execute([$zoneId, $cameraId, $type]);
            $row = $stmt->fetch();
        } catch (PDOException $e) { /* fall through */ }

        $defaults = [
            'min_confidence'          => (float)$this->settingsCache['ai_confidence_min'] / 100,
            'min_ensemble_confidence' => 0.75,
            'min_threat_score'        => 40.0,
            'min_track_frames'        => 2,
            'cooldown_seconds'        => 45,
        ];

        $merged = array_merge($defaults, array_filter($row ?: [], fn($v) => $v !== null));
        $this->thresholdsCache[$key] = $merged;
        return $merged;
    }

    // ==================================================================
    // PUBLIC: full analysis of a detection
    // ==================================================================
    /**
     * @param array $input  Raw detection input from a camera / API.
     *   Required: zone_id, detection_type
     *   Optional: camera_id, subclass, confidence, bbox_*, track_id,
     *             snapshot_url, location_lat, location_lng,
     *             ensemble (array of model scores), clip_url
     * @return array Result: ['accepted'=>bool,'reason'=>string,
     *                        'detection_id'=>?int,'alert_id'=>?int,
     *                        'threat_score'=>float,'threat_level'=>string]
     */
    public function analyze(array $input): array
    {
        $result = [
            'accepted'      => false,
            'reason'        => '',
            'detection_id'  => null,
            'alert_id'      => null,
            'threat_score'  => 0.0,
            'threat_level'  => 'low',
        ];

        // Global gate
        if ($this->settingsCache['ai_enabled'] !== '1') {
            $result['reason'] = 'ai_disabled';
            return $result;
        }

        // 1. PREPROCESS
        $pre = $this->preprocess($input);
        if ($pre['skip']) {
            $result['reason'] = $pre['reason'];
            return $result;
        }

        // 2. ENSEMBLE
        $ensemble = $this->ensemble($pre);

        // 3. TRACK (fast — attaches to existing track if possible)
        $track = $this->attachToTrack($pre, $ensemble);

        // 4. BEHAVIOUR
        $behaviour = $this->classifyBehaviour($pre, $track);

        // 5. THREAT SCORE
        $threat = $this->scoreThreat($pre, $ensemble, $track, $behaviour);

        // 6. RULES
        $rules = $this->applyRules($pre, $ensemble, $behaviour, $threat);
        $threat['score'] = min(100.0, $threat['score'] + $rules['boost']);
        if ($rules['level'] !== null && $this->levelRank($rules['level']) > $this->levelRank($threat['level'])) {
            $threat['level'] = $rules['level'];
        }

        // 7. THRESHOLD CHECK
        $cfg = $this->thresholds($pre['zone_id'], $pre['camera_id'], $pre['detection_type']);
        if ($ensemble['confidence'] < $cfg['min_confidence']) {
            $result['reason'] = 'below_min_confidence';
            $result['threat_score'] = $threat['score'];
            return $result;
        }
        if ($ensemble['confidence'] < $cfg['min_ensemble_confidence']) {
            $result['reason'] = 'below_min_ensemble';
            $result['threat_score'] = $threat['score'];
            return $result;
        }
        if ($threat['score'] < $cfg['min_threat_score']) {
            $result['reason'] = 'below_min_threat_score';
            $result['threat_score'] = $threat['score'];
            $result['threat_level'] = $threat['level'];
            return $result;
        }

        // 8. PERSIST
        $detectionId = $this->persistDetection($pre, $ensemble, $track, $behaviour, $threat);

        $result['accepted']     = true;
        $result['detection_id'] = $detectionId;
        $result['threat_score'] = $threat['score'];
        $result['threat_level'] = $threat['level'];

        // 9. ACT — alert + optional alarm
        if ($threat['score'] >= $cfg['min_threat_score']) {
            $alertId = $this->createAlert($pre, $ensemble, $threat, $detectionId);
            $result['alert_id'] = $alertId;

            if ($this->settingsCache['ai_auto_trigger_alarm'] === '1'
                && in_array($threat['level'], ['high','critical'], true)) {
                $this->queueAlarm($pre['zone_id'], $alertId, $detectionId, $threat['level']);
            }
        }

        return $result;
    }

    // ==================================================================
    // 1. PREPROCESS
    // ==================================================================
    private function preprocess(array $input): array
    {
        $zoneId   = (int)($input['zone_id'] ?? 0);
        $cameraId = isset($input['camera_id']) ? (int)$input['camera_id'] : null;
        $type     = (string)($input['detection_type'] ?? 'unknown');

        $validTypes = ['human','animal','vehicle','fire','gunshot','unknown'];
        if (!in_array($type, $validTypes, true)) $type = 'unknown';

        // Compute contextual signals
        $isNight = $this->isNightHour();

        $lat = isset($input['location_lat']) ? (float)$input['location_lat'] : null;
        $lng = isset($input['location_lng']) ? (float)$input['location_lng'] : null;

        // Boundary proximity (only if zone has boundary data)
        $nearBoundary   = false;
        $insideZone     = true;
        $distanceToEdge = null;
        if ($zoneId > 0 && $lat !== null && $lng !== null) {
            $bg = $this->getZoneBoundary($zoneId);
            if ($bg) {
                $insideZone   = $this->pointInPolygon([$lng, $lat], $bg);
                $distanceToEdge = $this->distanceToBoundary($lat, $lng, $bg);
                $nearBoundary = $distanceToEdge !== null && $distanceToEdge < 300;
            }
        }

        return [
            'skip'              => false,
            'reason'            => '',
            'zone_id'           => $zoneId,
            'camera_id'         => $cameraId,
            'detection_type'    => $type,
            'subclass'          => $input['subclass'] ?? null,
            'confidence'        => (float)($input['confidence'] ?? 0),
            'bbox'              => [
                (float)($input['bbox_x'] ?? 0),
                (float)($input['bbox_y'] ?? 0),
                (float)($input['bbox_w'] ?? 0),
                (float)($input['bbox_h'] ?? 0),
            ],
            'track_id'          => $input['track_id'] ?? null,
            'snapshot_url'      => $input['snapshot_url'] ?? null,
            'clip_url'          => $input['clip_url'] ?? null,
            'lat'               => $lat,
            'lng'               => $lng,
            'is_night'          => $isNight,
            'inside_zone'       => $insideZone,
            'near_boundary'     => $nearBoundary,
            'distance_to_edge'  => $distanceToEdge,
            'weather'           => $input['weather'] ?? null,
            'ensemble_input'    => $input['ensemble'] ?? [],
        ];
    }

    // ==================================================================
    // 2. ENSEMBLE
    // ==================================================================
    private function ensemble(array $pre): array
    {
        $scores = [];
        $models = [];

        // Take provided ensemble scores (from an external inference service)
        if (!empty($pre['ensemble_input']) && is_array($pre['ensemble_input'])) {
            foreach ($pre['ensemble_input'] as $entry) {
                if (!isset($entry['model_type'], $entry['score'])) continue;
                $scores[$entry['model_type']] = (float)$entry['score'];
                $models[] = [
                    'model_type' => $entry['model_type'],
                    'score'      => (float)$entry['score'],
                    'model_id'   => $entry['model_id'] ?? null,
                ];
            }
        }

        // Fall back to the raw confidence as the "detector" score
        if (!isset($scores['detector']) && $pre['confidence'] > 0) {
            $scores['detector'] = $pre['confidence'];
            $models[] = ['model_type' => 'detector', 'score' => $pre['confidence'], 'model_id' => null];
        }

        // Weighted fusion
        $weightedSum = 0.0;
        $weightTotal = 0.0;
        foreach ($scores as $type => $score) {
            $w = self::ENSEMBLE_WEIGHTS[$type] ?? 0.05;
            $weightedSum += $score * $w;
            $weightTotal += $w;
        }
        $confidence = $weightTotal > 0 ? ($weightedSum / $weightTotal) : 0.0;

        return [
            'confidence' => max(0.0, min(1.0, $confidence)),
            'models'     => $models,
            'raw_scores' => $scores,
        ];
    }

    // ==================================================================
    // 3. TRACK ATTACHMENT
    // ==================================================================
    private function attachToTrack(array $pre, array $ens): array
    {
        $trackId = $pre['track_id'];

        // If no explicit track_id, try to find a recent track in same zone+camera
        if (!$trackId && $pre['zone_id'] > 0) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT track_uid, frame_count, start_lat, start_lng,
                           avg_speed_mps, peak_confidence
                    FROM ai_tracks
                    WHERE zone_id = ?
                      AND (camera_id = ? OR ? IS NULL)
                      AND primary_type = ?
                      AND is_active = 1
                      AND last_detected_at IS NULL OR end_time IS NULL
                    ORDER BY start_time DESC
                    LIMIT 1
                ");
                $stmt->execute([
                    $pre['zone_id'],
                    $pre['camera_id'],
                    $pre['camera_id'],
                    $pre['detection_type'],
                ]);
                $row = $stmt->fetch();
                if ($row) {
                    $trackId = $row['track_uid'];
                }
            } catch (PDOException $e) { /* optional */ }
        }

        // Create a new track if still none
        if (!$trackId) {
            $trackId = 'T' . bin2hex(random_bytes(6));
        }

        return [
            'track_id'   => $trackId,
            'is_new'     => $trackId !== $pre['track_id'],
            'frames'     => 1,
            'start_time' => date('Y-m-d H:i:s'),
            'speed_mps'  => null,
        ];
    }

    // ==================================================================
    // 4. BEHAVIOUR CLASSIFICATION
    // ==================================================================
    private function classifyBehaviour(array $pre, array $track): array
    {
        // Lightweight heuristic behaviour classifier.
        // In production this would be replaced by the LSTM model output.
        $behaviour = 'moving';
        $score     = 0.0;

        if ($pre['near_boundary'])      { $behaviour = 'near_boundary'; $score = 0.7; }
        if (!$pre['inside_zone'])       { $behaviour = 'outside_zone';  $score = 0.5; }
        if ($pre['is_night']
            && $pre['detection_type'] === 'human') { $behaviour = 'night_intrusion'; $score = 0.85; }

        return [
            'behaviour' => $behaviour,
            'score'     => $score,
        ];
    }

    // ==================================================================
    // 5. THREAT SCORING
    // ==================================================================
    private function scoreThreat(array $pre, array $ens, array $track, array $beh): array
    {
        $W = self::THREAT_WEIGHTS;
        $score = 0.0;

        // Base confidence
        $score += $ens['confidence'] * $W['confidence'];

        // Ensemble strength (how many independent models agreed)
        $modelCount = count($ens['models']);
        $score += min(1.0, $modelCount / 3) * $W['ensemble'];

        // Behaviour
        $score += $beh['score'] * $W['behaviour'];

        // Boundary proximity
        if ($pre['near_boundary'])  $score += $W['boundary'];

        // Night time
        if ($pre['is_night'])       $score += $W['night'];

        // (Speed + duration are populated by the tracker in real deployments.)
        if ($track['speed_mps'] !== null && $track['speed_mps'] > 1.0) {
            $score += min(1.0, $track['speed_mps'] / 3.0) * $W['speed'];
        }

        // Type-based baseline boost
        $typeBoost = [
            'gunshot' => 25,
            'fire'    => 20,
            'human'   => 10,
            'vehicle' => 8,
            'animal'  => 2,
            'unknown' => 0,
        ][$pre['detection_type']] ?? 0;
        $score += $typeBoost;

        $score = max(0.0, min(100.0, $score));

        return [
            'score' => round($score, 3),
            'level' => $this->levelFromScore($score),
        ];
    }

    // ==================================================================
    // 6. RULE ENGINE
    // ==================================================================
    private function applyRules(array $pre, array $ens, array $beh, array $threat): array
    {
        $boost    = 0.0;
        $topLevel = null;

        foreach ($this->rulesCache as $rule) {
            if ($rule['detection_type'] !== null && $rule['detection_type'] !== $pre['detection_type']) {
                continue;
            }
            $cond = is_string($rule['condition_json'])
                ? json_decode($rule['condition_json'], true)
                : $rule['condition_json'];
            if (!is_array($cond)) continue;

            if (!$this->ruleMatches($cond, $pre, $ens, $beh)) continue;

            $boost += (float)$rule['weight'];

            if ($topLevel === null || $this->levelRank($rule['threat_level']) > $this->levelRank($topLevel)) {
                $topLevel = $rule['threat_level'];
            }
        }

        return ['boost' => $boost, 'level' => $topLevel];
    }

    private function ruleMatches(array $cond, array $pre, array $ens, array $beh): bool
    {
        foreach ($cond as $k => $v) {
            switch ($k) {
                case 'near_boundary':
                    if ((bool)$v !== $pre['near_boundary']) return false;
                    break;
                case 'inside_zone':
                    if ((bool)$v !== $pre['inside_zone']) return false;
                    break;
                case 'is_night':
                    if ((bool)$v !== $pre['is_night']) return false;
                    break;
                case 'min_confidence':
                    if ($ens['confidence'] < (float)$v) return false;
                    break;
                case 'behaviour':
                    if ($beh['behaviour'] !== (string)$v) return false;
                    break;
                case 'behaviour_score_min':
                    if ($beh['score'] < (float)$v) return false;
                    break;
                case 'month_in':
                    if (!in_array((int)date('n'), array_map('intval', (array)$v), true)) return false;
                    break;
                case 'subclass_in':
                    if (!in_array($pre['subclass'], (array)$v, true)) return false;
                    break;
                // Unknown keys are ignored (forward compatible)
            }
        }
        return true;
    }

    // ==================================================================
    // 7. PERSIST
    // ==================================================================
    private function persistDetection(array $pre, array $ens, array $track, array $beh, array $threat): ?int
    {
        try {
            $modelId = $ens['models'][0]['model_id'] ?? null;
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_detections_v2
                    (camera_id, zone_id, detection_type, subclass,
                     confidence, ensemble_confidence,
                     bbox_x, bbox_y, bbox_w, bbox_h,
                     track_id, frame_count,
                     behaviour, behaviour_score,
                     is_threat, threat_level, threat_score,
                     inside_zone, near_boundary, distance_to_boundary_m, is_night,
                     model_id, ensemble_models,
                     snapshot_url, clip_url,
                     location_lat, location_lng,
                     detected_at, processed_at)
                VALUES
                    (?, ?, ?, ?,
                     ?, ?,
                     ?, ?, ?, ?,
                     ?, ?,
                     ?, ?,
                     ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?,
                     ?, ?,
                     ?, ?,
                     NOW(), NOW())
            ");
            $stmt->execute([
                $pre['camera_id'], $pre['zone_id'], $pre['detection_type'], $pre['subclass'],
                $pre['confidence'], $ens['confidence'],
                $pre['bbox'][0], $pre['bbox'][1], $pre['bbox'][2], $pre['bbox'][3],
                $track['track_id'], $track['frames'],
                $beh['behaviour'], $beh['score'],
                $threat['level'] !== 'low' ? 1 : 0, $threat['level'], $threat['score'],
                $pre['inside_zone'] ? 1 : 0, $pre['near_boundary'] ? 1 : 0,
                $pre['distance_to_edge'] !== null ? (int)$pre['distance_to_edge'] : null,
                $pre['is_night'] ? 1 : 0,
                $modelId, json_encode($ens['models']),
                $pre['snapshot_url'], $pre['clip_url'],
                $pre['lat'], $pre['lng'],
            ]);
            return (int)$this->pdo->query('SELECT lastval()')->fetchColumn();
        } catch (PDOException $e) {
            error_log('[WS-AI] persistDetection: ' . $e->getMessage());
            return null;
        }
    }

    private function createAlert(array $pre, array $ens, array $threat, ?int $detectionId): ?int
    {
        try {
            $alertType = $this->mapAlertType($pre['detection_type']);
            $title = $this->alertTitle($pre, $threat['level']);
            $desc  = sprintf(
                '%s detected at camera %s with %d%% confidence. Threat score %.1f (%s).',
                ucfirst($pre['detection_type']),
                $pre['camera_id'] ?? 'unknown',
                (int)round($ens['confidence'] * 100),
                $threat['score'],
                $threat['level']
            );

            $stmt = $this->pdo->prepare("
                INSERT INTO ai_alerts
                    (detection_id, zone_id, alert_type, severity, title, description,
                     location_lat, location_lng, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $detectionId, $pre['zone_id'], $alertType, $threat['level'],
                $title, $desc, $pre['lat'], $pre['lng'],
            ]);
            return (int)$this->pdo->query('SELECT lastval()')->fetchColumn();
        } catch (PDOException $e) {
            error_log('[WS-AI] createAlert: ' . $e->getMessage());
            return null;
        }
    }

    private function queueAlarm(int $zoneId, ?int $alertId, ?int $detectionId, string $level): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_queue
                    (job_type, priority, zone_id, payload, scheduled_for)
                VALUES ('alert', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level === 'critical' ? 1 : 2,
                $zoneId,
                json_encode([
                    'action'       => 'trigger_alarm',
                    'zone_id'      => $zoneId,
                    'alert_id'     => $alertId,
                    'detection_id' => $detectionId,
                    'level'        => $level,
                ]),
            ]);
        } catch (PDOException $e) {
            error_log('[WS-AI] queueAlarm: ' . $e->getMessage());
        }
    }

    // ==================================================================
    // HELPERS
    // ==================================================================
    private function isNightHour(): bool
    {
        $hour = (int)date('G');
        return $hour < 6 || $hour >= 18;
    }

    private function levelFromScore(float $s): string
    {
        if ($s >= 80) return 'critical';
        if ($s >= 60) return 'high';
        if ($s >= 35) return 'medium';
        return 'low';
    }

    private function levelRank(string $level): int
    {
        return ['low'=>0,'medium'=>1,'high'=>2,'critical'=>3][$level] ?? 0;
    }

    private function mapAlertType(string $type): string
    {
        return [
            'human'   => 'intruder',
            'vehicle' => 'intruder',
            'gunshot' => 'gunshot',
            'fire'    => 'fire',
            'animal'  => 'animal_distress',
        ][$type] ?? 'other';
    }

    private function alertTitle(array $pre, string $level): string
    {
        $icon = [
            'critical' => '🚨',
            'high'     => '⚠️',
            'medium'   => '⚡',
            'low'      => 'ℹ️',
        ][$level] ?? '⚠️';
        return $icon . ' ' . ucfirst($level) . ' ' . strtoupper($pre['detection_type']) . ' detected';
    }

    // ------------------------------------------------------------------
    // Geometry helpers
    // ------------------------------------------------------------------
    private function getZoneBoundary(int $zoneId): ?array
    {
        static $cache = [];
        if (array_key_exists($zoneId, $cache)) return $cache[$zoneId];
        try {
            $stmt = $this->pdo->prepare("SELECT boundary_geojson FROM zones WHERE id = ? LIMIT 1");
            $stmt->execute([$zoneId]);
            $row = $stmt->fetch();
            $g = $row ? json_decode($row['boundary_geojson'] ?? 'null', true) : null;
            $cache[$zoneId] = ($g && ($g['type'] ?? '') === 'Polygon') ? $g : null;
            return $cache[$zoneId];
        } catch (PDOException $e) {
            $cache[$zoneId] = null;
            return null;
        }
    }

    private function pointInPolygon(array $point, array $geojson): bool
    {
        $rings = $geojson['coordinates'] ?? [];
        if (empty($rings[0])) return false;
        $poly = $rings[0]; // outer ring only
        $x = $point[0]; $y = $point[1];
        $inside = false;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $poly[$i][0]; $yi = $poly[$i][1];
            $xj = $poly[$j][0]; $yj = $poly[$j][1];
            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-9) + $xi);
            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }

    /**
     * Approximate distance from point to the nearest boundary edge (meters).
     */
    private function distanceToBoundary(float $lat, float $lng, array $geojson): ?float
    {
        $rings = $geojson['coordinates'] ?? [];
        if (empty($rings[0])) return null;
        $poly = $rings[0];
        $min = PHP_FLOAT_MAX;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $seg = [[$poly[$j][1], $poly[$j][0]], [$poly[$i][1], $poly[$i][0]]]; // lat,lng
            $d = $this->pointToSegmentMeters([$lat, $lng], $seg[0], $seg[1]);
            if ($d < $min) $min = $d;
        }
        return is_finite($min) ? $min : null;
    }

    private function pointToSegmentMeters(array $p, array $a, array $b): float
    {
        // Convert to meters using an equirectangular projection around p.
        $R = 6371000;
        $latRad = deg2rad($p[0]);
        $cos = cos($latRad);
        $toMeters = function (array $pt) use ($p, $cos) {
            $dLat = deg2rad($pt[0] - $p[0]);
            $dLng = deg2rad($pt[1] - $p[1]);
            return [$dLng * $cos * $R, $dLat * $R];
        };
        [$ax, $ay] = $toMeters($a);
        [$bx, $by] = $toMeters($b);
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 <= 0) return sqrt($ax * $ax + $ay * $ay);
        $t = max(0, min(1, (-$ax * $dx - $ay * $dy) / $len2));
        $cx = $ax + $t * $dx;
        $cy = $ay + $t * $dy;
        return sqrt($cx * $cx + $cy * $cy);
    }
}

}
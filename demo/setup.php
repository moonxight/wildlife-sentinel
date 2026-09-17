<?php
// Local-only setup. Never exposed as a web installer.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('DB_HOST') && !in_array(getenv('DB_HOST'), ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Refusing demo setup against a hosted database.\n"); exit(1);
}
$root = dirname(__DIR__);
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (!$pdo->query("SELECT GET_LOCK('wildlife_sentinel_demo_setup', 0)")->fetchColumn()) throw new RuntimeException('Another setup is running.');
    $count = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wildlife_sentinel'")->fetchColumn();
    if ($count === 0) {
        // Import the existing schema verbatim only into an empty database.
        // Understand its DELIMITER blocks (triggers and stored procedures).
        $sql = ''; $delimiter = ';';
        foreach (file($root . '/wildlife_sentinel.sql') as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)/i', $line, $m)) { $delimiter = $m[1]; continue; }
            if (preg_match('/^\s*--/', $line) || trim($line) === '') continue;
            $sql .= $line;
            if (str_ends_with(rtrim($sql), $delimiter)) {
                $statement = substr(rtrim($sql), 0, -strlen($delimiter));
                $result = $pdo->query($statement);
                $result->closeCursor();
                $sql = '';
            }
        }
        if (trim($sql) !== '') throw new RuntimeException('Unparsed schema SQL; setup stopped.');
        echo "Imported existing schema and reference data.\n";
    } else {
        echo "Existing database preserved; schema import skipped.\n";
    }
    $pdo->exec('USE wildlife_sentinel');
    // Fail closed on partial imports or incompatible existing databases.
    foreach (['users','zones','settings','ranger_availability','audit_logs','rate_limits'] as $table) {
        $pdo->query("SELECT * FROM `$table` LIMIT 0")->closeCursor();
    }
    if (in_array('--schema-only', $argv, true)) exit(0);
    $accounts = require __DIR__ . '/accounts.php';
    $pdo->beginTransaction();
    $zone = $pdo->query('SELECT id FROM zones WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
    if (!$zone) throw new RuntimeException('No active zone exists; no accounts changed.');
    foreach ($accounts as $a) {
        $q = $pdo->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$a['email']]); $existing = $q->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['role'] !== $a['role'] || $existing['full_name'] !== $a['name'] || !$existing['is_active'] || !password_verify($a['password'], $existing['password_hash'])) {
                throw new RuntimeException('Existing account conflict: ' . $a['email'] . '. No existing account overwritten.');
            }
            echo "Already present: {$a['email']}\n"; continue;
        }
        $q = $pdo->prepare('INSERT INTO users (email,password_hash,full_name,role,zone_id,is_active) VALUES (?,?,?,?,?,1)');
        $q->execute([$a['email'],password_hash($a['password'], PASSWORD_DEFAULT),$a['name'],$a['role'],$zone]);
        if ($a['role'] === 'ranger') {
            $pdo->prepare('INSERT INTO ranger_availability (ranger_id,is_available) VALUES (?,1)')->execute([$pdo->lastInsertId()]);
        }
        echo "Added: {$a['email']}\n";
    }
    $pdo->commit();
    echo "Demo accounts ready. See DEMO_SETUP.md for credentials.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Setup stopped: " . $e->getMessage() . "\nExisting data is never automatically reset. A partial import requires manual inspection.\n"); exit(1);
}

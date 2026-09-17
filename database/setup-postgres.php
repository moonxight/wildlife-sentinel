<?php
// CLI only: php database/setup-postgres.php [--demo]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!getenv('DATABASE_URL')) {
    fwrite(STDERR, "DATABASE_URL is required.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getDB();
try {
    // Serialize initial setup across concurrent deployment instances.
    $pdo->beginTransaction();
    $pdo->query('SELECT pg_advisory_xact_lock(741039210)');
    $marker = $pdo->query("SELECT to_regclass('ws_schema_versions')")->fetchColumn();
    if ($marker) {
        $version = $pdo->query("SELECT version FROM ws_schema_versions WHERE version='wildlife-postgres-v2'")->fetchColumn();
        if (!$version) throw new RuntimeException('Unrecognized schema version; import stopped.');
        echo "Schema already initialized; no import needed.\n";
    } else {
        $hasObjects = (bool)$pdo->query("
            SELECT EXISTS (
                SELECT 1
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                  AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')
            )
        ")->fetchColumn();
        if ($hasObjects) {
            throw new RuntimeException('The target schema is populated or unrecognized; import stopped.');
        }
        $sql = file_get_contents(__DIR__ . '/wildlife_sentinel.postgresql.sql');
        if ($sql === false) {
            throw new RuntimeException('PostgreSQL schema file could not be read.');
        }
        $pdo->exec(preg_replace('/^\s*(BEGIN|COMMIT);\s*$/m', '', $sql));
        echo "PostgreSQL schema initialized.\n";
    }
    if (in_array('--demo', $argv, true)) {
        $sql = file_get_contents(__DIR__ . '/demo_accounts.postgresql.sql');
        if ($sql === false) {
            throw new RuntimeException('Demo account seed file could not be read.');
        }
        $pdo->exec(preg_replace('/^\s*(BEGIN|COMMIT);\s*$/m', '', $sql));
        echo "Missing demo accounts added. Existing accounts/passwords were not overwritten.\n";
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Setup failed (code " . $e->getCode() . "). Check that the target schema is empty and the role can create tables. No existing data was reset.\n");
    exit(1);
}

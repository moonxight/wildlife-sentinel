<?php
// CLI only: php database/setup-postgres.php [--demo]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!getenv('DATABASE_URL')) {
    fwrite(STDERR, "DATABASE_URL is required. This command never imports into XAMPP.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getDB();
try {
    $marker = $pdo->query("SELECT to_regclass('ws_schema_versions')")->fetchColumn();
    if ($marker) {
        $version = $pdo->query("SELECT version FROM ws_schema_versions WHERE version='wildlife-postgres-v1'")->fetchColumn();
        if (!$version) throw new RuntimeException('Unrecognized schema version; import stopped.');
        echo "Schema already initialized; no import needed.\n";
    } else {
        $pdo->exec(file_get_contents(__DIR__ . '/wildlife_sentinel.postgresql.sql'));
        echo "PostgreSQL schema initialized.\n";
    }
    if (in_array('--demo', $argv, true)) {
        $pdo->exec(file_get_contents(__DIR__ . '/demo_accounts.postgresql.sql'));
        echo "Missing demo accounts added. Existing accounts/passwords were not overwritten.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Setup failed (code " . $e->getCode() . "). Check that the target schema is empty and the role can create tables. No existing data was reset.\n");
    exit(1);
}

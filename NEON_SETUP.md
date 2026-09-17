# Deploy Wildlife Sentinel with Neon

Local repository: `C:\Users\chilu\OneDrive\Desktop\wildlife-sentinel`.

The repository now supports both databases. A non-empty `DATABASE_URL` selects
PostgreSQL; without it the existing XAMPP/MySQL setup is used. `DB_DRIVER` is not
needed. The Dockerfile installs both PDO drivers and the PostgreSQL build library.

## Finish the hosted deployment

1. Review and commit the local changes, then push them to the `main` branch of
   `moonxight/wildlife-sentinel`, which Render deploys. No commit or push was made
   by this repair.
2. In Neon's SQL Editor, select the intended `neondb` database and run the entire
   `database/wildlife_sentinel.postgresql.sql` file. It runs as a transaction and
   refuses any schema that already contains tables. Do not delete existing tables
   to work around that safeguard. If an earlier import populated this database,
   inspect it or use a separate empty database before proceeding.
3. For the seven demonstration accounts, run
   `database/demo_accounts.postgresql.sql`. This is optional and contains password
   hashes only. It adds missing accounts and ranger availability; it never resets
   an existing account's password. Existing credentials are listed in DEMO_SETUP.md.
4. In Render service `wildlife-sentinel-9tui`, keep `DATABASE_URL` set to the full
   Neon connection URL copied from Neon, including the actual password and query
   parameters. Do not paste the `psql` command, surrounding quotes, Markdown
   backslashes, or the literal `{password}` placeholder. Never commit this URL.
5. Deploy the new commit. Its build must show both `pdo_mysql` and `pdo_pgsql`.
6. Open https://wildlife-sentinel-9tui.onrender.com/login.php and sign in.

Alternatively, from a trusted CLI with `DATABASE_URL` already set and PDO pgsql
installed, run `php database/setup-postgres.php --demo`. The command skips an
already-initialized version and refuses to overwrite an unrecognized database.
Omit `--demo` when demonstration accounts are not wanted. The web installer and
the original MySQL SQL file are not PostgreSQL installers.

## Implementation

- `config/database.php`: validates the PostgreSQL URL, uses hosted TLS and libpq
  channel binding, retains the MySQL fallback, and logs no credentials.
- `config/PostgresPDO.php`: PostgreSQL-only handling of the specific legacy SQL
  forms in this application (upserts, intervals, duration arithmetic, concatenation,
  schema inspection, nearby-distance queries, joined updates/deletes, runtime DDL,
  inserted IDs, and first-admin registration locking). MySQL queries pass through
  native PDO unchanged. New MySQL-specific queries require extending this adapter.
- `database/wildlife_sentinel.postgresql.sql`: 41 application tables, nine views,
  indexes, reference data, trigger behavior, and a schema-version marker. Location
  points use PostgreSQL's native point type; compatibility functions cover the
  point operations this application uses. This is not a general PostGIS replacement.
- Six original result-set procedures are exposed as PostgreSQL table-returning
  functions, called using `SELECT * FROM getzonestatistics(1)`, for example. The
  application's PHP code does not call the original MySQL procedures.
- The optional demo seed creates the requested administrator, two rangers, and
  four scouts. It invents no poacher role.

## Verification and limits

The corrected repository passed PHP syntax checks (92 files), all seven demo HTTP
logins and role-dashboard responses on an isolated PostgreSQL 18 database, and
13 focused checks covering zone defaults, ranger upserts, incident point updates,
assignment/resolution triggers, scout tracking, time arithmetic, report aggregation,
schema inspection, audit deletion syntax, and alarm auto-stop syntax. Write checks
were rolled back. Repeated setup preserved accounts and skipped schema reimport.
The six result-set functions compiled successfully.
The local XAMPP MySQL connection and existing admin credential also passed.

Changed files: `Dockerfile`, `config/database.php`, `.gitignore`, `DEMO_SETUP.md`.
Added files: `config/PostgresPDO.php`,
`database/wildlife_sentinel.postgresql.sql`,
`database/demo_accounts.postgresql.sql`, `database/setup-postgres.php`,
`.env.example`, and `NEON_SETUP.md`.

Docker is unavailable on this PC, so an image build was not tested. No live Neon
schema import, Render deployment, external SMS/camera/alarm integration, or complete
UI regression test is claimed. These checks establish the tested local database
paths; they do not certify every possible application action.

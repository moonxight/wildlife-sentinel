# Wildlife Sentinel on Neon

The application uses PostgreSQL only. XAMPP is no longer needed for the hosted site.

## Render

Set **DATABASE_URL** in the existing Render service's Environment page to the real
connection URL copied from Neon (including `sslmode=require`). Keep it private;
do not paste it into source code or commit it. No other database variables are needed.

Deploy this repository using its Dockerfile. Startup initializes an **empty**
database automatically, then starts Apache. Subsequent starts preserve the schema
and data. Setup refuses an unknown or populated schema rather than resetting it.
Concurrent startup is serialized using a PostgreSQL transaction lock.

Open the website and use its existing first-administrator registration form.
Existing user passwords and roles are unchanged by the application conversion.

## Optional demonstration accounts

For a demonstration database only, run `php database/setup-postgres.php --demo`
with DATABASE_URL set. This adds the seven documented demo accounts from
`database/demo_accounts.postgresql.sql`; it never replaces an existing email or password. There is no
poacher login role in the application. Do not use these public passwords for real accounts.

## Local development

Use PHP 8.2 with `pdo_pgsql`, `mbstring`, and `curl`, and set DATABASE_URL to a
PostgreSQL database. Run `php database/setup-postgres.php` before starting Apache.
The Docker image includes these extensions and runs setup for you.

The schema is `database/wildlife_sentinel.postgresql.sql`. SQL queries are native
PostgreSQL; no runtime query translator is used. Location points use PostgreSQL's
native point type and the `ws_point_from_wkt` / `ws_point_to_wkt` helpers.

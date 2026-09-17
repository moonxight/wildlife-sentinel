# Wildlife Sentinel local demo

Repository: C:\Users\chilu\OneDrive\Desktop\wildlife-sentinel

Double-click RUN_DEMO.bat. It uses C:\xampp (or XAMPP_HOME), starts MySQL only if port 3306 is closed, initializes the original schema only when the database is empty, adds missing demo accounts, and opens http://localhost:8088/wildlife-sentinel/login.php.

The demo Apache instance uses port 8088 on this PC only. Existing XAMPP sites on port 80 are untouched. Its generated configuration and log live in %LOCALAPPDATA%\WildlifeSentinelDemo. STOP_DEMO.bat stops only the demo Apache; MySQL is left running for other applications.

## Demo credentials

These public, demo-only passwords must not be used for real users. Email is the login identifier; there is no separate username.

| Role | Email | Password |
|---|---|---|
| Ranger | demo.ranger1@example.test | DemoOnly!Ranger1 |
| Ranger | demo.ranger2@example.test | DemoOnly!Ranger2 |
| Scout | demo.scout1@example.test | DemoOnly!Scout1 |
| Scout | demo.scout2@example.test | DemoOnly!Scout2 |
| Scout | demo.scout3@example.test | DemoOnly!Scout3 |
| Scout | demo.scout4@example.test | DemoOnly!Scout4 |

Six accounts currently exist. The requested two poacher accounts are pending clarification: the original role enum permits scout, tourism, ranger, zone_supervisor, and admin only. No poacher role or substitute has been invented. “Scotts” was interpreted as scouts. No admin account was added; the application's existing first-admin registration remains available.

## Database safety

The original wildlife_sentinel.sql contains DROP statements. Do not manually import it over a populated database. The launcher checks for existing tables/views and skips that import when any exist. It never resets passwords or overwrites existing accounts: an email/name/role/password conflict stops setup and rolls back account additions. Repeated runs preserve existing data and do not duplicate these accounts. A partial schema import stops for manual inspection; the launcher never resets it automatically. Local connection matches the app's defaults: 127.0.0.1:3306, database wildlife_sentinel, root, empty password. Custom local database credentials require separate configuration; the launcher does not change them.

## Render

Choose a Docker web service with this repository and the root Dockerfile. Apache listens on port 80, which Render can detect. The container supports both the site root and /wildlife-sentinel/ paths to preserve existing links. Set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD for a separately hosted MySQL/MariaDB database. Render cannot use this PC's localhost database. Provision/import the existing schema into an EMPTY hosted database separately after reviewing it. Local demo scripts and credentials are excluded from the image and never automatically seeded in production. For DB_TLS=1 provide the provider's CA certificate at config/ca.pem. Uploads need persistent storage if they must survive redeployment.

Docker is not installed here, so the image has not been built or deployed. External AI, WebSocket, SMS, camera and alarm integrations were not started or verified. The existing WS_URL and AI_SERVICE_URL settings still need real service endpoints where those features are required.

## Verification performed

- Original schema imported successfully into an empty local database (50 tables/views).
- Six demo logins submitted through the real HTTP login form with session/CSRF handling; all reached their role dashboards with HTTP 200.
- Setup rerun: existing schema skipped, no duplicate accounts, 2 rangers and 4 scouts retained.
- Original tracked application files unchanged.

## Added files

Dockerfile, .dockerignore, RUN_DEMO.bat, STOP_DEMO.bat, DEMO_SETUP.md, demo/.htaccess, demo/accounts.php, demo/setup.php, demo/run.ps1, demo/stop.ps1.

No existing application files were edited. No commit or push was made.

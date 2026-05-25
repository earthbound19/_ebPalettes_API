<?php
// DESCRIPTION
// Retrieves a random palette taken from the /palettes subdirectory tree in a GitHub palettes repository;
// here hardcoded with variables / values for https://github.com/earthbound19/_ebPalettes but could be adapted
// for any repository that uses the same data formats. Caches the palettes in a database for faster retrieval,
// and syncs that cache with the palette repository. A palette request can filter by color count. Database
// stores palettes as CSV strings for simplicity and performance.

// USAGE
// - create a GitHub API token with read-only access to the palette repo
// - create a MySQL database for caching
// - upload this and the companion .htaccess file to a host in a dedicated public location,
//   with the companion .env configuration, and set file permissions:
//   - chmod 600 .env (readable/writable only by owner/web server user)
//   - chmod 644 index.php (or you may be able to run it with a more restrictive chmod 600)
//   - chmod 644 .htaccess (if using clean URLs)
//   - you may need to chmod 755 the parent directories
// - first run: point a web browser to this index at the hosted URL. It will present a setup form to
//   configure the database connection
// - after setup, the script will sync palettes from the repository, and be able to serve random
//   palettes directly from the database
// - the script checks for repository changes by comparing the /palettes directory SHA hash on any
//   call to retrieve a palette:
//     * checks the SHA of the once every SHA_CHECK_INTERVAL_SECONDS (default 300 seconds / 5 minutes)
//     * if the SHA has changed, triggers a full sync to update the database cache
//     * the SHA and last check timestamp are stored in .env to set up a future time elapsed sync check

// API ENDPOINTS:
//   GET  /random                          - Random palette (any color count)
//   GET  /random?min=N                    - Random palette with N or MORE colors
//   GET  /random?max=N                    - Random palette with N or LESS colors
//   GET  /random?exact=N                  - Random palette with EXACTLY N colors
//   GET  /status                          - Sync status and cache statistics
//   POST /sync?password=YOUR_PASSWORD     - Force sync (requires password from .env)
//   GET  /setup                           - Re-run setup (if database not configured)

// EXAMPLES
// if hosted at https://earthbound.io/api/palettes, which at this writing indeed this is:
//
// - retrieve a random palette with minimum five colors:
//   https://earthbound.io/api/palettes/random?min=5
//
// - retrieve a random palette with maximum eight colors:
//   https://earthbound.io/api/palettes/random?max=8
//
// - retrieve a random palette with exactly twelve colors:
//   https://earthbound.io/api/palettes/random?exact=12
//
// - retrieve a random palette with between 5 and 10 colors:
//   https://earthbound.io/api/palettes/random?min=5&max=10
//
// - retrieve sync status and cache statistics:
//   https://earthbound.io/api/palettes/status
//
// - force manual sync (requires password from .env):
//   https://earthbound.io/api/palettes/sync?password=YOUR_PASSWORD
//  - or via curl:
//    curl -X POST "https://earthbound.io/api/palettes/sync?password=YOUR_PASSWORD"

// NOTES
// Values in .env may be enclosed in double (or single?) quote marks, supposedly. Not tested at this writing.
// This is to allow for example more unusual characters in strings. Double quotes within strings
// may break.

// SECURITY NOTES
// - .env file protected via .htaccess (Require all denied)
// - CSP headers prevent XSS
// - GitHub token redacted from error logs
// - Prepared statements prevent SQL injection
// - Path traversal sanitization on file paths from GitHub
// - Error details hidden from clients in production
// - HTTPS enforcement optional but recommended

// CODE
// ============================================================================
// CONFIGURATION & INITIALIZATION
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 for debugging

// Security headers
header("Content-Security-Policy: default-src 'none'; script-src 'none'; style-src 'unsafe-inline'");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: no-referrer");

// Optional: Force HTTPS (uncomment if HTTPS available)
// if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
//     header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
//     exit;
// }

// GLOBAL palette repo variables; change these for different repos / hosts:
$repoOwner = 'earthbound19';
$repoName = '_ebPalettes';
$repoAPIbase = "https://api.github.com/repos/$repoOwner/$repoName";
$repoWebBase = "https://github.com/$repoOwner/$repoName";

// Get script base path for location-agnostic redirects
$scriptBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$fullBaseUrl = 'https://' . $_SERVER['HTTP_HOST'] . $scriptBasePath;

// Load .env manually
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $envVars = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remove surrounding double quotes if present
        if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value)-1) ||
            (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value)-1)) {
            $value = substr($value, 1, -1);
        }

        $envVars[$key] = $value;
    }
    return $envVars;
}

// Save a single environment variable back to .env file
function saveEnvVar($filePath, $key, $value) {
    $envVars = loadEnv($filePath);
    $envVars[$key] = $value;

    $lines = [];
    foreach ($envVars as $k => $v) {
        // Add quotes if value contains special characters
        if (preg_match('/[^A-Za-z0-9_\-]/', $v)) {
            $v = "'" . str_replace("'", "\\'", $v) . "'";
        }
        $lines[] = "$k=$v";
    }

    $result = file_put_contents($filePath, implode("\n", $lines) . "\n", LOCK_EX);
    return $result !== false;
}

$envFile = __DIR__ . '/.env';
$env = loadEnv($envFile);
$githubApiKey = $env['GITHUB_API_KEY'] ?? null;

// Database configuration
$dbConfig = [
    'host' => $env['DB_HOST'] ?? null,
    'name' => $env['DB_NAME'] ?? null,
    'user' => $env['DB_USER'] ?? null,
    'password' => $env['DB_PASSWORD'] ?? null
];

$syncPassword = $env['SYNC_PASSWORD'] ?? null;
$shaCheckInterval = $env['SHA_CHECK_INTERVAL_SECONDS'] ?? 300;
$fallbackToGitHub = ($env['FALLBACK_TO_GITHUB_API'] ?? 'true') === 'true';

// Check if database is configured
$dbConfigured = $dbConfig['host'] && $dbConfig['name'] && $dbConfig['user'] && $dbConfig['password'];

// Handle request routing - support both PATH_INFO and mod_rewrite
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] !== '') {
    $pathParts = explode('/', trim($_SERVER['PATH_INFO'], '/'));
} else {
    // Try to extract path after script name
    $scriptName = $_SERVER['SCRIPT_NAME'];
    if (strpos($requestPath, $scriptName) === 0) {
        $pathPart = substr($requestPath, strlen($scriptName));
        $pathPart = ltrim($pathPart, '/');
        $pathParts = $pathPart === '' ? [] : explode('/', $pathPart);
    } else {
        $pathParts = explode('/', trim($requestPath, '/'));
        // Remove script name if present as first part
        if (basename($scriptName) === ($pathParts[0] ?? '')) {
            array_shift($pathParts);
        }
    }
}

$endpoint = $pathParts[0] ?? '';
$requestMethod = $_SERVER['REQUEST_METHOD'];

// ============================================================================
// SETUP ENDPOINT (First run or manual reconfiguration)
// ============================================================================

if (!$dbConfigured || $endpoint === 'setup') {
    if ($requestMethod === 'POST' && isset($_POST['action']) && $_POST['action'] === 'setup') {
        // Validate required fields
        $errors = [];

        if (empty($_POST['DB_HOST'])) $errors[] = 'Database host is required';
        if (empty($_POST['DB_NAME'])) $errors[] = 'Database name is required';
        if (empty($_POST['DB_USER'])) $errors[] = 'Database user is required';
        if (empty($_POST['GITHUB_API_KEY'])) $errors[] = 'GitHub API key is required';

        if (empty($errors)) {
            // Save configuration
            saveEnvVar($envFile, 'DB_HOST', $_POST['DB_HOST']);
            saveEnvVar($envFile, 'DB_NAME', $_POST['DB_NAME']);
            saveEnvVar($envFile, 'DB_USER', $_POST['DB_USER']);
            saveEnvVar($envFile, 'DB_PASSWORD', $_POST['DB_PASSWORD'] ?? '');
            saveEnvVar($envFile, 'GITHUB_API_KEY', $_POST['GITHUB_API_KEY']);
            saveEnvVar($envFile, 'SYNC_PASSWORD', $_POST['SYNC_PASSWORD'] ?? '');

            // Reload environment
            $env = loadEnv($envFile);
            $githubApiKey = $env['GITHUB_API_KEY'] ?? null;
            $dbConfig = [
                'host' => $env['DB_HOST'] ?? null,
                'name' => $env['DB_NAME'] ?? null,
                'user' => $env['DB_USER'] ?? null,
                'password' => $env['DB_PASSWORD'] ?? null
            ];

            // Test database connection
            $dbTestPassed = false;
            $githubTestPassed = false;
            $setupError = null;

            try {
                // Test database connection
                $testPdo = new PDO(
                    "mysql:host={$dbConfig['host']};charset=utf8mb4",
                    $dbConfig['user'],
                    $dbConfig['password']
                );
                $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Create database if it doesn't exist
                $testPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbConfig['name']}`");
                $testPdo->exec("USE `{$dbConfig['name']}`");
                $dbTestPassed = true;

                // Test GitHub API - use dynamic repo variable
                $testUrl = $repoAPIbase;
                $options = [
                    'http' => [
                        'header' => [
                            "Accept: application/vnd.github+json",
                            "Authorization: Bearer $githubApiKey",
                            "User-Agent: PHP-Script"
                        ],
                        'timeout' => 10
                    ]
                ];
                $context = stream_context_create($options);
                $testResponse = @file_get_contents($testUrl, false, $context);
                if ($testResponse !== false) {
                    $githubTestPassed = true;
                } else {
                    $errors[] = 'GitHub API test failed - invalid token or rate limit exceeded';
                }

                if ($dbTestPassed && $githubTestPassed) {
                    // Initialize database tables
                    initDatabase();

                    // Show progress page during initial sync
                    ?>
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Palette Cache - Initial Sync in Progress</title>
                        <style>
                            body { font-family: monospace; max-width: 800px; margin: 50px auto; padding: 20px; }
                            .progress { background: #f0f0f0; padding: 20px; border-radius: 5px; margin: 20px 0; }
                            .status { color: #0066cc; font-weight: bold; }
                            .success { color: green; }
                            .error { color: red; }
                            .spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid #f3f3f3; border-top: 3px solid #0066cc; border-radius: 50%; animation: spin 1s linear infinite; }
                            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                        </style>
                    </head>
                    <body>
                        <h1>Palette Cache - Initial Sync</h1>
                        <div class="progress">
                            <p><span class="spinner"></span> <span class="status">Syncing palettes from GitHub...</span></p>
                            <div id="output" style="margin-top: 20px;"></div>
                        </div>
                        <script>
                            const outputDiv = document.getElementById('output');

                            function log(message, isError = false) {
                                const p = document.createElement('p');
                                p.textContent = message;
                                p.style.color = isError ? 'red' : '#333';
                                p.style.fontFamily = 'monospace';
                                p.style.margin = '5px 0';
                                outputDiv.appendChild(p);
                                p.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }

                            async function performSync() {
                                try {
                                    log('[START] Starting sync process...');
                                    
                                    // Fire and forget - start the sync without waiting
                                    const syncUrl = window.location.pathname.replace(/\/setup.*$/, '/sync') + '?password=<?php echo urlencode($_POST['SYNC_PASSWORD'] ?? ''); ?>&force=true';
                                    
                                    // Start the sync in background
                                    fetch(syncUrl, { method: 'POST' }).catch(e => log('[WARN] Sync start error: ' + e.message));
                                    
                                    log('[INFO] Sync started in background. Monitoring progress...');
                                    
                                    // Immediately start polling status
                                    const statusUrl = window.location.pathname.replace(/\/setup.*$/, '/status');
                                    let lastCount = -1;
                                    let attempts = 0;
                                    const maxAttempts = 120; // 10 minutes at 5 second intervals
                                    
                                    const interval = setInterval(async () => {
                                        attempts++;
                                        try {
                                            const response = await fetch(statusUrl);
                                            const status = await response.json();
                                            
                                            // Show progress when count increases
                                            if (status.total_palettes_cached > lastCount) {
                                                lastCount = status.total_palettes_cached;
                                                log('[PROGRESS] ' + lastCount + ' palettes cached so far...');
                                            } else if (status.sync_in_progress) {
                                                log('[STATUS] Sync in progress... (' + lastCount + ' palettes cached)');
                                            }
                                            
                                            // Check for completion
                                            if (!status.sync_in_progress && status.last_full_sync) {
                                                clearInterval(interval);
                                                log('[COMPLETE] Sync finished!');
                                                log('  - Total palettes: ' + status.total_palettes_cached);
                                                log('  - Color range: ' + status.color_count_stats.min + ' to ' + status.color_count_stats.max);
                                                log('[REDIRECT] Loading random palette...');
                                                setTimeout(() => {
                                                    window.location.href = window.location.pathname.replace(/\/setup.*$/, '/random');
                                                }, 2000);
                                            } else if (attempts >= maxAttempts) {
                                                clearInterval(interval);
                                                log('[TIMEOUT] Sync taking too long, redirecting anyway...');
                                                setTimeout(() => {
                                                    window.location.href = window.location.pathname.replace(/\/setup.*$/, '/random');
                                                }, 2000);
                                            }
                                        } catch (e) {
                                            log('[ERROR] ' + e.message);
                                        }
                                    }, 4200); // Check every this many milliseconds
                                    
                                } catch (error) {
                                    log('[ERROR] ' + error.message, true);
                                    setTimeout(() => {
                                        window.location.href = window.location.pathname.replace(/\/setup.*$/, '/random');
                                    }, 4200);
                                }
                            }
                            
                            // Start the sync process
                            performSync();
                        </script>
                    </body>
                    </html>
                    <?php
                    exit;
                }
            } catch (Exception $e) {
                $setupError = $e->getMessage();
            }
        }

        if (!empty($errors) || $setupError) {
            $errorMessage = !empty($errors) ? implode(', ', $errors) : $setupError;
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <title>Setup Failed</title>
                <style>
                    body { font-family: monospace; max-width: 600px; margin: 50px auto; padding: 20px; }
                    .error { background: #ffeeee; border-left: 4px solid red; padding: 15px; margin: 20px 0; }
                    button { background: #0066cc; color: white; border: none; padding: 10px 20px; cursor: pointer; }
                </style>
            </head>
            <body>
                <h1>Setup Failed</h1>
                <div class="error">
                    <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
                </div>
                <button onclick="history.back()">Go Back to Fix</button>
            </body>
            </html>
            <?php
            exit;
        }
    }

    // Check for .htaccess and permissions
    $htaccessPath = __DIR__ . '/.htaccess';
    $htaccessExists = file_exists($htaccessPath);

    // Serve setup form
    header('Content-Type: text/html; charset=utf-8');
    $existingEnv = loadEnv($envFile);

    // Dynamic RewriteBase for .htaccess instructions
    $currentDir = trim($scriptBasePath, '/');
    $rewriteBase = $currentDir ? '/' . $currentDir . '/' : '/';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Palette Cache - Setup</title>
        <style>
            body { font-family: monospace; max-width: 700px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
            .container { background: white; padding: 30px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
            input, button { display: block; width: 100%; margin: 10px 0; padding: 10px; font-size: 14px; box-sizing: border-box; }
            label { font-weight: bold; margin-top: 15px; display: block; }
            .error { color: red; border: 1px solid red; padding: 10px; margin: 10px 0; background: #ffeeee; }
            .note { font-size: 12px; color: #666; margin-top: 5px; }
            .info-box { background: #e7f3ff; border-left: 4px solid #0066cc; padding: 10px; margin: 15px 0; font-size: 13px; }
            button { background: #0066cc; color: white; border: none; cursor: pointer; border-radius: 3px; }
            button:hover { background: #0052a3; }
            h1 { margin-top: 0; }
            hr { margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Palette Cache - Setup</h1>
            <p>This script caches palettes from <a href="<?php echo $repoWebBase; ?>" target="_blank">GitHub</a> to a MySQL database.</p>

            <div class="info-box">
                <strong>Before starting:</strong>
                <ul style="margin: 5px 0 0 20px;">
                    <li>Create a MySQL database and user</li>
                    <li>Create a GitHub Personal Access Token (repo:read scope) at <a href="https://github.com/settings/tokens" target="_blank">GitHub Tokens</a></li>
                </ul>
            </div>

            <?php if (!$htaccessExists): ?>
                <div class="info-box">
                    <strong>Optional: Clean URLs</strong><br>
                    For clean URLs (e.g., <code>/random</code> instead of <code>/index.php/random</code>), use the <code>.htaccess</code> file included in the repository. Copy it to this directory and ensure <code>RewriteBase</code> matches your path (currently <code><?php echo $rewriteBase; ?></code>).<br><br>
                    Then set permissions: <code>chmod 644 .htaccess</code> and <code>chmod 755 ../ &amp; chmod 755 ./</code>
                </div>
            <?php endif; ?>

            <?php if (isset($setupError)): ?>
                <div class="error">
                    <strong>Setup failed:</strong> <?php echo htmlspecialchars($setupError); ?>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="setup">

                <label>MySQL Host</label>
                <input type="text" name="DB_HOST" value="<?php echo htmlspecialchars($_POST['DB_HOST'] ?? 'localhost'); ?>" required autocomplete="off">

                <label>Database Name</label>
                <input type="text" name="DB_NAME" value="<?php echo htmlspecialchars($_POST['DB_NAME'] ?? ''); ?>" required autocomplete="off">

                <label>Database User</label>
                <input type="text" name="DB_USER" value="<?php echo htmlspecialchars($_POST['DB_USER'] ?? ''); ?>" required autocomplete="off">

                <label>Database Password</label>
                <input type="password" name="DB_PASSWORD" value="" autocomplete="new-password">
                <div class="note">Leave blank if no password</div>

                <label>GitHub API Key</label>
                <input type="password" name="GITHUB_API_KEY" value="<?php echo htmlspecialchars($_POST['GITHUB_API_KEY'] ?? ''); ?>" required autocomplete="new-password">
                <div class="note">Create at: <a href="https://github.com/settings/tokens" target="_blank">GitHub Tokens</a> (repo:read scope)</div>

                <label>Sync Password (optional, for POST /sync endpoint)</label>
                <input type="password" name="SYNC_PASSWORD" value="<?php echo htmlspecialchars($_POST['SYNC_PASSWORD'] ?? ''); ?>" autocomplete="new-password">
                <div class="note">Use only alphanumeric + underscore/hyphen. Send via <code>?password=</code> parameter. Leave blank to disable manual sync endpoint.</div>

                <button type="submit">Initialize Database and Sync</button>
            </form>

            <hr>

            <h3>Post-Setup Instructions</h3>
            <p>After successful setup, set these file permissions:</p>
            <pre style="background:#f0f0f0; padding:10px; overflow-x:auto;">
chmod 600 .env
chmod 644 index.php
chmod 644 .htaccess  (if created)
chmod 755 .          (current directory)
chmod 755 ..         (parent directory)</pre>

            <p><strong>Test your API:</strong></p>
            <pre style="background:#f0f0f0; padding:10px; overflow-x:auto;">
curl <?php echo $fullBaseUrl; ?>/status
curl "<?php echo $fullBaseUrl; ?>/random?min=5"</pre>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// DATABASE FUNCTIONS
// ============================================================================

function getDbConnection() {
    global $dbConfig;
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password']);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

function initDatabase() {
    $pdo = getDbConnection();

    // Create palettes table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `palettes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `filename` VARCHAR(255) NOT NULL UNIQUE,
            `palette_name` VARCHAR(255) NOT NULL,
            `colors_csv` TEXT NOT NULL,
            `color_count` INT NOT NULL,
            `github_blob_sha` VARCHAR(64) NOT NULL,
            `file_path` VARCHAR(512) NOT NULL,
            `last_synced` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_color_count (`color_count`),
            INDEX idx_palette_name (`palette_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Create sync_metadata table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sync_metadata` (
            `id` INT DEFAULT 1 PRIMARY KEY,
            `last_full_sync` TIMESTAMP NULL,
            `last_sync_attempt` TIMESTAMP NULL,
            `sync_in_progress` BOOLEAN DEFAULT FALSE,
            `total_palettes` INT DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Insert default metadata row if not exists
    $stmt = $pdo->prepare("INSERT IGNORE INTO sync_metadata (id) VALUES (1)");
    $stmt->execute();
}

function isSyncInProgress() {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT sync_in_progress FROM sync_metadata WHERE id = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result && $result['sync_in_progress'] == 1;
}

function acquireSyncLock() {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        UPDATE sync_metadata
        SET sync_in_progress = TRUE, last_sync_attempt = NOW()
        WHERE id = 1 AND sync_in_progress = FALSE
    ");
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function releaseSyncLock() {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("UPDATE sync_metadata SET sync_in_progress = FALSE WHERE id = 1");
    $stmt->execute();
}

function tablesExist() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SHOW TABLES LIKE 'palettes'");
        $palettesExists = $stmt->rowCount() > 0;
        $stmt = $pdo->query("SHOW TABLES LIKE 'sync_metadata'");
        $metadataExists = $stmt->rowCount() > 0;
        return $palettesExists && $metadataExists;
    } catch (Exception $e) {
        return false;
    }
}

function shouldSync() {
    if (!tablesExist()) {
        return true;  // Force sync to recreate tables
    }
    global $shaCheckInterval, $envFile;
    $pdo = getDbConnection();

    // Get last sync time from database
    $stmt = $pdo->prepare("SELECT last_full_sync FROM sync_metadata WHERE id = 1");
    $stmt->execute();
    $meta = $stmt->fetch();

    // If never synced, need sync
    if (!$meta || !$meta['last_full_sync']) {
        return true;
    }

    // Get cache data from .env
    $env = loadEnv($envFile);
    $lastShaCheck = $env['LAST_SHA_CHECK_TIMESTAMP'] ?? 0;
    $cachedSha = $env['PALETTES_TREE_SHA'] ?? null;

    // Only check SHA if interval has elapsed
    if (time() - $lastShaCheck >= $shaCheckInterval) {
        try {
            // Update timestamp in .env immediately
            saveEnvVar($envFile, 'LAST_SHA_CHECK_TIMESTAMP', time());

            // Get current SHA from GitHub API
            $currentSha = getPalettesDirectorySha();

            // If SHA changed, trigger sync
            if ($cachedSha && $currentSha !== $cachedSha) {
                saveEnvVar($envFile, 'PALETTES_TREE_SHA', $currentSha);
                return true;
            }
        } catch (Exception $e) {
            error_log("GitHub API SHA check failed: " . $e->getMessage());
        }
    }

    return false;
}

function getLastSyncTime() {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT last_full_sync FROM sync_metadata WHERE id = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    return $result ? $result['last_full_sync'] : null;
}

// ============================================================================
// GITHUB API FUNCTIONS
// ============================================================================

function githubApiRequest($url) {
    global $githubApiKey;

    $options = [
        'http' => [
            'header' => [
                "Accept: application/vnd.github+json",
                "Authorization: Bearer $githubApiKey",
                "User-Agent: PHP-Script"
            ]
        ]
    ];
    $context = stream_context_create($options);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        // Redact token from error logs
        $error = error_get_last();
        $safeMessage = str_replace($githubApiKey, '[REDACTED]', $error['message'] ?? 'Unknown error');
        error_log("GitHub API request failed: $url - $safeMessage");
        throw new Exception("GitHub API request failed: $url");
    }

    return json_decode($response, true);
}

function getPalettesDirectorySha() {
    global $repoAPIbase;

    $repoInfo = githubApiRequest($repoAPIbase);
    $defaultBranch = $repoInfo['default_branch'] ?? 'master';

    $treeData = githubApiRequest("$repoAPIbase/git/trees/$defaultBranch?recursive=1");

    foreach ($treeData['tree'] as $item) {
        if ($item['path'] === 'palettes') {
            return $item['sha'];
        }
    }

    throw new Exception("/palettes directory not found in repository");
}

function getAllHexpltFiles() {
    global $repoAPIbase;

    $palettesSha = getPalettesDirectorySha();
    $treeData = githubApiRequest("$repoAPIbase/git/trees/$palettesSha?recursive=1");

    $files = [];
    foreach ($treeData['tree'] as $item) {
        if (str_ends_with($item['path'], '.hexplt')) {
            // Sanitize file path to prevent path traversal
            $sanitizedPath = str_replace(['..', '//', '\\'], '', $item['path']);
            
            $files[] = [
                'path' => $sanitizedPath,
                'sha' => $item['sha'],
                'filename' => basename($sanitizedPath),
                'palette_name' => str_replace('_', ' ', pathinfo($sanitizedPath, PATHINFO_FILENAME))
            ];
        }
    }

    return $files;
}

function getHexpltColors($sha) {
    global $repoAPIbase;

    $blobData = githubApiRequest("$repoAPIbase/git/blobs/$sha");
    $decodedContent = base64_decode($blobData['content']);

    preg_match_all('/#[0-9a-fA-F]{6}/', $decodedContent, $matches);
    return $matches[0];
}

// ============================================================================
// SYNC FUNCTIONS
// ============================================================================

function triggerSync($force = false) {
    global $fallbackToGitHub, $envFile;

    if (!$force && isSyncInProgress()) {
        return ['status' => 'sync_already_in_progress'];
    }

    if (!$force && !shouldSync()) {
        return ['status' => 'sync_not_needed'];
    }

    if (!acquireSyncLock()) {
        return ['status' => 'could_not_acquire_lock'];
    }

    try {
        $result = performFullSync();
        releaseSyncLock();
        return $result;
    } catch (Exception $e) {
        releaseSyncLock();
        throw $e;
    }
}

function performFullSync() {
    global $repoWebBase, $envFile;
    
    $currentPalettesSha = getPalettesDirectorySha();
    $githubFiles = getAllHexpltFiles();
    
    $pdo = getDbConnection();
    // No beginTransaction() here, because later syncs would heal
    // any missed palettes and wrapping this all in a transaction makes
    // progress polls via /status invisible
    
    try {
        $syncedFilenames = [];
        $added = 0;
        $updated = 0;
        $processedCount = 0;
        
        foreach ($githubFiles as $file) {
            $syncedFilenames[] = $file['filename'];
            
            $stmt = $pdo->prepare("SELECT id, github_blob_sha FROM palettes WHERE filename = ?");
            $stmt->execute([$file['filename']]);
            $existing = $stmt->fetch();
            
            $colors = getHexpltColors($file['sha']);
            $colorsCsv = implode(',', $colors);
            $colorCount = count($colors);
            
            if (!$existing) {
                $stmt = $pdo->prepare("
                    INSERT INTO palettes (filename, palette_name, colors_csv, color_count, github_blob_sha, file_path)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $file['filename'],
                    $file['palette_name'],
                    $colorsCsv,
                    $colorCount,
                    $file['sha'],
                    $file['path']
                ]);
                $added++;
                $processedCount = $added + $updated;
                $updateStmt = $pdo->prepare("UPDATE sync_metadata SET total_palettes = ? WHERE id = 1");
                $updateStmt->execute([$processedCount]);
            } elseif ($existing['github_blob_sha'] !== $file['sha']) {
                $stmt = $pdo->prepare("
                    UPDATE palettes
                    SET palette_name = ?, colors_csv = ?, color_count = ?, github_blob_sha = ?, file_path = ?, last_synced = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $file['palette_name'],
                    $colorsCsv,
                    $colorCount,
                    $file['sha'],
                    $file['path'],
                    $existing['id']
                ]);
                $updated++;
                $processedCount = $added + $updated;
                $updateStmt = $pdo->prepare("UPDATE sync_metadata SET total_palettes = ? WHERE id = 1");
                $updateStmt->execute([$processedCount]);
            }
        }
        
        // Delete palettes no longer in GitHub
        if (!empty($syncedFilenames)) {
            $placeholders = implode(',', array_fill(0, count($syncedFilenames), '?'));
            $stmt = $pdo->prepare("DELETE FROM palettes WHERE filename NOT IN ($placeholders)");
            $stmt->execute($syncedFilenames);
            $deleted = $stmt->rowCount();
        } else {
            $deleted = 0;
        }
        
        saveEnvVar($envFile, 'PALETTES_TREE_SHA', $currentPalettesSha);
        
        // Final metadata update with total count
        $stmt = $pdo->prepare("
            UPDATE sync_metadata
            SET last_full_sync = NOW(),
                sync_in_progress = FALSE,
                total_palettes = ?
            WHERE id = 1
        ");
        $stmt->execute([count($githubFiles)]);
        
        return [
            'status' => 'success',
            'added' => $added,
            'updated' => $updated,
            'deleted' => $deleted,
            'total_palettes' => count($githubFiles),
            'sync_time' => date('c')
        ];
        
    } catch (Exception $e) {
        throw $e;
    }
}

// ============================================================================
// PALETTE RETRIEVAL FUNCTIONS
// ============================================================================

class NoPaletteFoundException extends Exception {}

function getRandomPalette($minColors = null, $maxColors = null, $exactColors = null) {
    $pdo = getDbConnection();

    $sql = "SELECT * FROM palettes WHERE 1=1";
    $params = [];

    if ($exactColors !== null) {
        $sql .= " AND color_count = ?";
        $params[] = $exactColors;
    } else {
        if ($minColors !== null) {
            $sql .= " AND color_count >= ?";
            $params[] = $minColors;
        }
        if ($maxColors !== null) {
            $sql .= " AND color_count <= ?";
            $params[] = $maxColors;
        }
    }

    $sql .= " ORDER BY RAND() LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();

    if (!$result) {
        throw new NoPaletteFoundException("No palette matches the criteria");
    }

    $colors = explode(',', $result['colors_csv']);

    global $repoWebBase;
    $textSourceURL = "$repoWebBase/blob/master/palettes/{$result['file_path']}";
    $imageSourceURL = str_replace('.hexplt', '.png', $textSourceURL);

    return [
        'colors' => $colors,
        'paletteName' => $result['palette_name'],
        'fileName' => $result['filename'],
        'colorCount' => $result['color_count'],
        'textSourceURL' => $textSourceURL,
        'imageSourceURL' => $imageSourceURL
    ];
}

function getSyncStatus() {
    global $shaCheckInterval, $envFile;
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT * FROM sync_metadata WHERE id = 1");
    $stmt->execute();
    $syncMeta = $stmt->fetch();

    // During sync, palettes table is empty until commit, so use sync_metadata total
    if ($syncMeta && $syncMeta['sync_in_progress']) {
        $total = (int)$syncMeta['total_palettes'];
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM palettes");
        $stmt->execute();
        $total = $stmt->fetch()['total'];
    }

    $stmt = $pdo->prepare("SELECT MIN(color_count) as min, MAX(color_count) as max, AVG(color_count) as avg FROM palettes");
    $stmt->execute();
    $stats = $stmt->fetch();

    $env = loadEnv($envFile);
    $cachedSha = $env['PALETTES_TREE_SHA'] ?? null;
    $lastShaCheck = $env['LAST_SHA_CHECK_TIMESTAMP'] ?? null;

    return [
        'sync_in_progress' => $syncMeta && $syncMeta['sync_in_progress'] == 1,
        'last_full_sync' => $syncMeta['last_full_sync'] ?? null,
        'last_sync_attempt' => $syncMeta['last_sync_attempt'] ?? null,
        'total_palettes_cached' => (int)$total,
        'color_count_stats' => [
            'min' => (int)$stats['min'],
            'max' => (int)$stats['max'],
            'avg' => round($stats['avg'], 1)
        ],
        'sha_check_interval_seconds' => $shaCheckInterval,
        'cached_palettes_sha' => $cachedSha,
        'last_sha_check_timestamp' => $lastShaCheck ? date('c', $lastShaCheck) : null
    ];
}

// ============================================================================
// FALLBACK TO ORIGINAL GITHUB API
// ============================================================================

function fetchRandomPaletteFromGitHub() {
    global $githubApiKey, $repoAPIbase, $repoWebBase;

    $options = [
        'http' => [
            'header' => [
                "Accept: application/vnd.github+json",
                "Authorization: Bearer $githubApiKey",
                "User-Agent: PHP-Script"
            ]
        ]
    ];
    $context = stream_context_create($options);

    $repoInfo = file_get_contents($repoAPIbase, false, $context);
    if ($repoInfo === false) {
        throw new Exception("Failed to fetch repository info from GitHub");
    }
    $repoInfo = json_decode($repoInfo, true);
    $defaultBranchSha = $repoInfo['default_branch'] ?? 'master';

    $branchTreeUrl = "$repoAPIbase/git/trees/$defaultBranchSha?recursive=1";
    $branchTreeResponse = file_get_contents($branchTreeUrl, false, $context);
    if ($branchTreeResponse === false) {
        throw new Exception("Failed to fetch branch tree from GitHub");
    }
    $branchTree = json_decode($branchTreeResponse, true);

    $palettesTreeSha = null;
    foreach ($branchTree['tree'] as $item) {
        if ($item['path'] === 'palettes') {
            $palettesTreeSha = $item['sha'];
            break;
        }
    }

    if (!$palettesTreeSha) {
        throw new Exception("/palettes directory not found in repository");
    }

    $palettesTreeUrl = "$repoAPIbase/git/trees/$palettesTreeSha?recursive=1";
    $response = file_get_contents($palettesTreeUrl, false, $context);
    if ($response === false) {
        throw new Exception("Failed to fetch palettes tree from GitHub");
    }
    $data = json_decode($response, true);

    $hexpltFiles = array_filter($data['tree'], function ($item) {
        return isset($item['path']) && str_ends_with($item['path'], '.hexplt');
    });

    if (empty($hexpltFiles)) {
        throw new Exception("No .hexplt files found in repository");
    }

    $randomFile = $hexpltFiles[array_rand($hexpltFiles)];
    $blobUrl = "$repoAPIbase/git/blobs/{$randomFile['sha']}";
    $blobResponse = file_get_contents($blobUrl, false, $context);
    if ($blobResponse === false) {
        throw new Exception("Failed to fetch blob from GitHub");
    }
    $blobData = json_decode($blobResponse, true);
    $decodedContent = base64_decode($blobData['content']);
    preg_match_all('/#[0-9a-fA-F]{6}/', $decodedContent, $matches);
    $colors = $matches[0];

    $paletteName = str_replace('_', ' ', basename($randomFile['path'], '.hexplt'));
    $textSourceURL = "$repoWebBase/blob/master/palettes/{$randomFile['path']}";
    $textSourceURL = str_replace('\\', '', $textSourceURL);
    $imageSourceURL = str_replace('.hexplt', '.png', $textSourceURL);

    return [
        'colors' => $colors,
        'paletteName' => $paletteName,
        'fileName' => basename($randomFile['path']),
        'colorCount' => count($colors),
        'textSourceURL' => $textSourceURL,
        'imageSourceURL' => $imageSourceURL,
        'warning' => 'Using fallback GitHub API (database unavailable or sync in progress)'
    ];
}

// ============================================================================
// MAIN REQUEST HANDLER
// ============================================================================

header('Content-Type: application/json');

try {
    if ($endpoint !== 'sync' && $endpoint !== 'status' && shouldSync() && !isSyncInProgress()) {
        triggerSync();
    }

    if (isSyncInProgress() && $endpoint !== 'sync' && $endpoint !== 'status') {
        http_response_code(503);
        echo json_encode([
            'error' => 'Sync in progress, please retry',
            'retry_after' => 5,
            'status_endpoint' => 'status'
        ]);
        exit;
    }

    switch ($endpoint) {
        case 'random':
            $min = isset($_GET['min']) ? (int)$_GET['min'] : null;
            $max = isset($_GET['max']) ? (int)$_GET['max'] : null;
            $exact = isset($_GET['exact']) ? (int)$_GET['exact'] : null;

            if (($min !== null && $min < 1) || ($max !== null && $max < 1) || ($exact !== null && $exact < 1)) {
                http_response_code(400);
                echo json_encode(['error' => 'Color count must be at least 1']);
                exit;
            }

            if ($exact !== null && ($min !== null || $max !== null)) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot combine exact with min or max']);
                exit;
            }

            try {
                $palette = getRandomPalette($min, $max, $exact);
                echo json_encode($palette, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } catch (NoPaletteFoundException $e) {
                http_response_code(404);
                echo json_encode(['error' => $e->getMessage()]);
            }
            break;

        case 'status':
            $status = getSyncStatus();
            echo json_encode($status, JSON_PRETTY_PRINT);
            break;

        case 'sync':
            if ($requestMethod !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed, use POST']);
                exit;
            }

            // Require sync password via URL parameter
            $providedPassword = $_GET['password'] ?? null;

            if (!$syncPassword || !$providedPassword || $providedPassword !== $syncPassword) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid or missing sync password. Use ?password=YOUR_PASSWORD']);
                exit;
            }

            $force = isset($_GET['force']) && $_GET['force'] === 'true';

            try {
                $result = triggerSync($force);
                echo json_encode($result, JSON_PRETTY_PRINT);
            } catch (Exception $e) {
                http_response_code(500);
                error_log("Sync error: " . $e->getMessage());
                echo json_encode(['error' => 'Sync failed, check server logs']);
            }
            break;

        case '':
        case 'index.php':
            header('Location: ' . $scriptBasePath . '/random');
            exit;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }

} catch (PDOException $e) {
    if ($fallbackToGitHub && $endpoint === 'random') {
        try {
            $palette = fetchRandomPaletteFromGitHub();
            echo json_encode($palette, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (Exception $fallbackError) {
            http_response_code(500);
            error_log("Fallback error: " . $fallbackError->getMessage());
            echo json_encode([
                'error' => 'Database error and fallback failed',
                'database_error' => 'Internal error (details logged)',
                'fallback_error' => 'Internal error (details logged)'
            ]);
        }
    } else {
        http_response_code(500);
        error_log("Database error: " . $e->getMessage());
        echo json_encode(['error' => 'Database error']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("General error: " . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}
?>
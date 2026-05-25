# DESCRIPTION
This is a **palette caching API** that fetches color palettes (`.hexplt` files) from a GitHub repository, caches them in MySQL, and serves random palettes with filtering options from that cache, with fallback to the live GitHub API when database errors occur (returns 503 during active sync instead).

# SETUP
Deploy to a public web folder on a host that runs PHP. Create a mySQL database as guided by the setup prompt when you navigate to the page at the deployed URL

# USAGE
See the API Endpoints section.

## Database Schema

### Table: `palettes`
Stores individual color palettes from GitHub.

| Field             | Type                | Description                                                        |
| ----------------- | ------------------- | ------------------------------------------------------------------ |
| `id`              | INT AUTO_INCREMENT  | Primary key                                                        |
| `filename`        | VARCHAR(255) UNIQUE | Name of `.hexplt` file (e.g., `sunset.hexplt`)                     |
| `palette_name`    | VARCHAR(255)        | Human-readable name (filename with underscores replaced by spaces) |
| `colors_csv`      | TEXT                | Comma-separated hex colors (e.g., `#FF0000,#00FF00,#0000FF`)       |
| `color_count`     | INT                 | Number of colors in the palette (for filtering)                    |
| `github_blob_sha` | VARCHAR(64)         | GitHub blob SHA for change detection                               |
| `file_path`       | VARCHAR(512)        | Relative path within `/palettes` directory                         |
| `last_synced`     | TIMESTAMP           | Auto-updated when record changes                                   |

**Indexes:** `color_count`, `palette_name`

### Table: `sync_metadata`

Tracks sync operations (history only - SHA cache is in `.env`).

|Field|Type|Description|
|---|---|---|
|`id`|INT DEFAULT 1|Single-row table pattern (always has only one row)|
|`last_full_sync`|TIMESTAMP|When the last successful sync completed|
|`last_sync_attempt`|TIMESTAMP|When the last sync attempt started|
|`sync_in_progress`|BOOLEAN|Prevents concurrent syncs|
|`total_palettes`|INT|Current count of palettes in cache|
|`updated_at`|TIMESTAMP|Auto-updated on any change|

---

## Core Functions

### Database Layer

|Function|Purpose|
|---|---|
|`getDbConnection()`|Returns PDO connection singleton from `.env` config|
|`initDatabase()`|Creates `palettes` and `sync_metadata` tables if they don't exist|
|`isSyncInProgress()`|Checks if `sync_in_progress = 1`|
|`acquireSyncLock()`|Atomic UPDATE to claim sync lock (returns true if acquired)|
|`releaseSyncLock()`|Releases the sync lock|
|`shouldSync()`Returns true if: no previous sync, OR (interval elapsed AND SHA changed). Timestamp updates at check start (not on success) to throttle retries.|

### GitHub API Layer

|Function|Purpose|
|---|---|
|`githubApiRequest($url)`|Authenticated GitHub API request wrapper for any API $url (other functions use this)|
|`getPalettesDirectorySha()`|Gets SHA hash of `/palettes` directory from GitHub|
|`getAllHexpltFiles()`|Returns array of all `.hexplt` files with metadata (path, sha, filename, name)|
|`getHexpltColors($sha)`|Downloads a blob by SHA and extracts all `#RRGGBB` hex colors|

### Sync Layer

|Function|Purpose|
|---|---|
|`triggerSync($force)`|Entry point for sync. Checks lock, calls `performFullSync()`|
|`performFullSync()`|**Transaction wrapper that:**|

- Fetches current directory SHA
- Gets all `.hexplt` files from GitHub
- Inserts new palettes
- Updates changed palettes based on SHA mismatch
- Deletes palettes no longer in GitHub
- Updates `.env` with new directory SHA
- Updates `sync_metadata` with sync time and total count |

### Palette Retrieval

|Function|Purpose|
|---|---|
|`getRandomPalette($min, $max, $exact)`|Queries database with color count filters, orders by `RAND()`, returns one palette with URLs for palette file and image file|
|`fetchRandomPaletteFromGitHub()`|**Fallback** - calls GitHub API directly when database unavailable, with no filter considerations: strictly a random palette|

**Return structure; real examples; JSON:**

```
{
    "colors": [
        "#f800fc",
        "#01edfd"
    ],
    "paletteName": "16 Max Chroma Med Light 2-combo 010",
    "fileName": "16_Max_Chroma_Med_Light_2-combo_010.hexplt",
    "textSourceURL": "https://github.com/earthbound19/_ebPalettes/blob/master/palettes/_combos/16_Max_Chroma_Medium_Light_2-combos/16_Max_Chroma_Med_Light_2-combo_010.hexplt",
    "imageSourceURL": "https://github.com/earthbound19/_ebPalettes/blob/master/palettes/_combos/16_Max_Chroma_Medium_Light_2-combos/16_Max_Chroma_Med_Light_2-combo_010.png"
}
```

```
{
    "colors": [
        "#a4a19f",
        "#b59f80",
        "#a48456",
        "#926849",
        "#836b55",
        "#746f5e",
        "#6f6f6f",
        "#565656",
        "#6a523d",
        "#553a2e",
        "#623326",
        "#402122",
        "#3b231a",
        "#322622",
        "#0e1323",
        "#111124"
    ],
    "paletteName": "Soil Pigments Darker and Dark Backgrounds Tweak",
    "fileName": "Soil_Pigments_Darker_and_Dark_Backgrounds_Tweak.hexplt",
    "textSourceURL": "https://github.com/earthbound19/_ebPalettes/blob/master/palettes/Soil_Pigments_Darker_and_Dark_Backgrounds_Tweak.hexplt",
    "imageSourceURL": "https://github.com/earthbound19/_ebPalettes/blob/master/palettes/Soil_Pigments_Darker_and_Dark_Backgrounds_Tweak.png"
}
```

### Status & Utilities

|Function|Purpose|
|---|---|
|`getSyncStatus()`|Returns sync metadata, palette stats (min/max/avg colors), SHA check status|
|`loadEnv($filePath)`|Parses `.env` file into associative array|
|`saveEnvVar($filePath, $key, $value)`|Updates a single `.env` variable while preserving others|

---

## Environment Variables (`.env`)

|Key|Purpose|
|---|---|
|`GITHUB_API_KEY`|GitHub personal access token (read-only)|
|`DB_HOST`|MySQL host|
|`DB_NAME`|Database name|
|`DB_USER`|Database username|
|`DB_PASSWORD`|Database password|
|`SYNC_PASSWORD`|Password for `/sync` endpoint (optional)|
|`SHA_CHECK_INTERVAL_SECONDS`|For an API request, if this many seconds have passed since the last GitHub change check, re-check. (default 300)|
|`FALLBACK_TO_GITHUB_API`|Use live GitHub API on database error (default true)|
|`PALETTES_TREE_SHA`|**Auto-managed** - Cached SHA of `/palettes` directory|
|`LAST_SHA_CHECK_TIMESTAMP`|**Auto-managed** - Unix timestamp of last SHA check. Compared against current time with SHA_CHECK_INTERVAL_SECONDS to determine whether to check for changes.|

---

## API Endpoints

In the following examples, N is not to be used with the API as a literal letter; it represents any integer:

|Endpoint|Method|Behavior|
|---|---|---|
|`/random`|GET|Random palette (any color count)|
|`/random?min=N`|GET|Palette with N or more colors|
|`/random?max=N`|GET|Palette with N or less colors|
|`/random?exact=N`|GET|Palette with exactly N colors|
|`/status`|GET|Sync status + cache statistics|
|`/sync?password=X`|POST|Force manual sync (requires password)|
|`/setup`|GET/POST|First-run configuration form|

Note that min and max can be combined to specify a range, for example to request a palette that has between 5 to 10 colors, use:

Concrete examples given hosting at a specific folder:
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

    /random?min=5&max=10

---

## API Behavior

- **Auto-sync trigger** - On any request (except `/sync` and `/status`), checks if sync needed
- **503 during sync** - Returns `503 Service Unavailable` with retry hint
- **Locking** - Atomic database lock prevents concurrent syncs
- **SHA check interval** - Limits GitHub API calls (default 5 minutes)
- **Fallback mode** - If database fails and `/random` request, falls back to direct GitHub API
- **404 on no match** - When no palette matches filter criteria
- **400 Bad Request on illogical query** - If `exact` is combined with `min` and / or `max`

---

## Data Flow

- Request
- Load .env
- Check if DB configured
  - If not configured, display setup form
- shouldSync() checks SHA interval
- Auto-sync if SHA changed
- Route to endpoint (/random, /status, /sync)
- Return JSON response (or 503 during sync)
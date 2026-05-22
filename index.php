<?php
// DESCRIPTION
// Retrieves a random palette from the /palettes subdirectory tree in the /_ebPalettes GitHub repository: https://github.com/earthbound19/_ebPalettes

// USAGE
// - Create a GitHub API token with read-only access to the _ebPalettes repo. Then create a .env file in the same directory as this script, with these contents:
//     GITHUB_API_KEY=your_actual_read_only_GitHub_API_key
// - RECOMMENDED, as it will bypass time traversing the whole repository tree to find the /palettes directory: also have a line with the current main branch git hash for the /palettes directory in that file:
//     PALETTES_TREE_SHA=the_hash
// - Place this PHP file and the .env accompanying file in a private or public web server endpoint, and load it in a browser _or_ curl/CLI/whatever context.

// Created by collaboration with a large language model, porting the printContentsOfRandomPalette_GitHubAPI.sh script from the _ebDev repository.
// Source: https://chatgpt.com/share/673f7664-0214-8010-99e5-3f0554937be7

// CODE
// Load .env manually
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    $envVars = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $envVars[trim($key)] = trim($value);
    }
    return $envVars;
}

$env = loadEnv(__DIR__ . '/.env');
$githubApiKey = $env['GITHUB_API_KEY'] ?? null;
$palettesTreeSha = $env['PALETTES_TREE_SHA'] ?? null; // Optional value from .env
$repoOwner = 'earthbound19';
$repoName = '_ebPalettes';

if (!$githubApiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'GitHub API key not found']);
    exit;
}

// Set up HTTP context globally
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

// If $palettesTreeSha is not set, retrieve it dynamically
if (!$palettesTreeSha) {
    // Fetch repository info to get default branch
    $repoInfoUrl = "https://api.github.com/repos/$repoOwner/$repoName";
    $repoInfoResponse = file_get_contents($repoInfoUrl, false, $context);
    $repoInfo = json_decode($repoInfoResponse, true);
    $defaultBranchSha = $repoInfo['default_branch'];

    // Fetch the tree for the default branch
    $branchTreeUrl = "https://api.github.com/repos/$repoOwner/$repoName/git/trees/$defaultBranchSha?recursive=1";
    $branchTreeResponse = file_get_contents($branchTreeUrl, false, $context);
    $branchTree = json_decode($branchTreeResponse, true);

    // Find SHA for /palettes directory
    foreach ($branchTree['tree'] as $item) {
        if ($item['path'] === 'palettes') {
            $palettesTreeSha = $item['sha'];
            break;
        }
    }

    if (!$palettesTreeSha) {
        http_response_code(500);
        echo json_encode(['error' => '/palettes directory not found']);
        exit;
    }
}

// Fetch palettes directory contents
$palettesTreeUrl = "https://api.github.com/repos/$repoOwner/$repoName/git/trees/$palettesTreeSha?recursive=1";
$response = file_get_contents($palettesTreeUrl, false, $context);

// for testing uncomment this to show hash found for /palettes directory; comment out for production:
// if ($palettesTreeSha) {
//     http_response_code(200);
//     echo "/palettes directory found, hash $palettesTreeSha";
//     exit;
// }

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch directory contents']);
    exit;
}


// Fetch palettes directory contents
$palettesTreeUrl = "https://api.github.com/repos/$repoOwner/$repoName/git/trees/$palettesTreeSha?recursive=1";
$response = file_get_contents($palettesTreeUrl, false, $context);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch directory contents']);
    exit;
}

$data = json_decode($response, true);
$hexpltFiles = array_filter($data['tree'], function ($item) {
    return isset($item['path']) && str_ends_with($item['path'], '.hexplt');
});

if (empty($hexpltFiles)) {
    http_response_code(404);
    echo json_encode(['error' => 'No .hexplt files found']);
    exit;
}

$randomFile = $hexpltFiles[array_rand($hexpltFiles)];
$blobUrl = "https://api.github.com/repos/$repoOwner/$repoName/git/blobs/{$randomFile['sha']}";
$blobResponse = file_get_contents($blobUrl, false, $context);

if ($blobResponse === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch file content']);
    exit;
}

$blobData = json_decode($blobResponse, true);
$decodedContent = base64_decode($blobData['content']);
preg_match_all('/#[0-9a-fA-F]{6}/', $decodedContent, $matches);
$colors = $matches[0];

// Generate paletteName
$paletteName = str_replace('_', ' ', basename($randomFile['path'], '.hexplt'));

// Construct the full public repository URL
$textSourceURL = "https://github.com/$repoOwner/$repoName/blob/master/palettes/$randomFile[path]";
// Replace any escaped slashes (\/) with normal slashes (/)
$textSourceURL = str_replace('\\', '', $textSourceURL);
$imageSourceURL = str_replace('.hexplt', '.png', $textSourceURL);

header('Content-Type: application/json');
echo json_encode([
    'colors' => $colors,
    'paletteName' => $paletteName,
    'fileName' => basename($randomFile['path']),
    'textSourceURL' => $textSourceURL,
    'imageSourceURL' => $imageSourceURL
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

#!/usr/bin/env php
<?php
/**
 * Glasshouse NOC Portal — Database Initialisation Script
 *
 * Run from the command line by the Windows installer (or manually):
 *   php init-db.php [--force]
 *
 * Reads admin credentials from {TEMP}\portal-init.ini (written by Inno Setup).
 * Falls back to interactive prompts when run manually.
 *
 * What it does:
 *   1. Creates the provisioning_portal database if not exists
 *   2. Imports database/schema.sql
 *   3. Creates the first admin user
 *   4. Writes config/db.php with connection details
 *   5. Writes config/portal.cfg with port setting
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('This script may only be run from the command line.');
}

// ---- Configuration ----
$dbHost = '127.0.0.1';
$dbPort = 3306;
$dbName = 'provisioning_portal';
$dbRoot = 'root';
$dbRootPass = 'glasshouse'; // set by MariaDB installer step in setup.iss
$forceReinit = in_array('--force', $argv ?? [], true);

// ---- Read credentials from installer temp file ----
$adminEmail    = '';
$adminPassword = '';
$webPort       = '8080';

$iniFile = getenv('TEMP') . DIRECTORY_SEPARATOR . 'portal-init.ini';
if (file_exists($iniFile)) {
    $iniData = parse_ini_file($iniFile);
    $adminEmail    = (string)($iniData['admin_email']    ?? '');
    $adminPassword = (string)($iniData['admin_password'] ?? '');
    $webPort       = (string)($iniData['port']           ?? '8080');
    unlink($iniFile); // remove immediately after reading
}

// ---- Fallback: interactive prompts ----
if ($adminEmail === '') {
    echo "Glasshouse NOC Portal — Database Initialisation\n";
    echo "================================================\n\n";
    echo "Admin email: ";
    $adminEmail = trim((string)fgets(STDIN));
}

if ($adminPassword === '') {
    echo "Admin password (min 10 chars): ";
    // Disable echo on *nix; Windows doesn't support stty but password is set by installer
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
        $adminPassword = trim((string)fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $adminPassword = trim((string)fgets(STDIN));
    }
}

if ($webPort === '' || (int)$webPort < 1024) {
    $webPort = '8080';
}

// Validate inputs
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ERROR: Invalid admin email address.\n");
    exit(1);
}
if (strlen($adminPassword) < 10) {
    fwrite(STDERR, "ERROR: Password must be at least 10 characters.\n");
    exit(1);
}

// ---- Connect to MariaDB as root ----
echo "Connecting to database...\n";
try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbRoot, $dbRootPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: Could not connect to MariaDB: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Make sure the database service is running.\n");
    exit(1);
}

// ---- Check if already initialised ----
$dbExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$dbName'")->fetchColumn();
if ($dbExists && !$forceReinit) {
    // Check if users table has rows
    try {
        $pdo->exec("USE `$dbName`");
        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($userCount > 0) {
            echo "Database already initialised ($userCount user(s) exist).\n";
            echo "Use --force to re-initialise (WARNING: drops all data).\n";
            exit(0);
        }
    } catch (PDOException) {
        // Tables might not exist — proceed with schema import
    }
}

// ---- Drop and recreate database if --force ----
if ($dbExists && $forceReinit) {
    echo "WARNING: Dropping existing database '$dbName'...\n";
    $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
}

// ---- Create database ----
echo "Creating database '$dbName'...\n";
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbName`");

// ---- Import schema.sql ----
$schemaFile = __DIR__ . '/database/schema.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "ERROR: Schema file not found: $schemaFile\n");
    exit(1);
}

echo "Importing schema...\n";
$schemaSql = file_get_contents($schemaFile);

// Split on semicolons to execute statement by statement
$statements = array_filter(
    array_map('trim', explode(';', $schemaSql)),
    fn($s) => $s !== '' && !str_starts_with(ltrim($s), '--')
);

$imported = 0;
foreach ($statements as $stmt) {
    if (trim($stmt) === '') continue;
    try {
        $pdo->exec($stmt);
        $imported++;
    } catch (PDOException $e) {
        // Ignore "already exists" errors (safe for re-runs)
        if (!str_contains($e->getMessage(), 'already exists') &&
            !str_contains($e->getMessage(), 'Duplicate') &&
            !str_contains($e->getMessage(), 'duplicate')) {
            echo "  WARN: " . $e->getMessage() . "\n";
        }
    }
}
echo "  $imported statements executed.\n";

// ---- Create first admin user ----
echo "Creating admin user '$adminEmail'...\n";
$passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]);

// Check if user exists
$existing = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$existing->execute([$adminEmail]);
if ($existing->fetch()) {
    $pdo->prepare("UPDATE users SET password_hash = ?, role = 'owner', is_active = 1 WHERE email = ?")
        ->execute([$passwordHash, $adminEmail]);
    echo "  Admin user updated.\n";
} else {
    $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (?, ?, 'owner', 1, NOW())")
        ->execute([$adminEmail, $passwordHash]);
    echo "  Admin user created.\n";
}

// ---- Write database connection config ----
$installDir  = realpath(__DIR__);
$dbConfigFile = $installDir . '/database/db.php';
$dbAppPass   = bin2hex(random_bytes(16)); // random app DB password

// Create a restricted app database user
try {
    $pdo->exec("CREATE USER IF NOT EXISTS 'portal_app'@'127.0.0.1' IDENTIFIED BY '$dbAppPass'");
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$dbName`.* TO 'portal_app'@'127.0.0.1'");
    $pdo->exec("FLUSH PRIVILEGES");
    $dbUser = 'portal_app';
    $dbPass = $dbAppPass;
    echo "  Database user 'portal_app' created with least-privilege access.\n";
} catch (PDOException) {
    // Fallback to root if user creation fails
    $dbUser = $dbRoot;
    $dbPass = $dbRootPass;
    echo "  WARN: Could not create app DB user; using root credentials.\n";
}

$dbConfigContent = <<<PHP
<?php
// Auto-generated by init-db.php — do not edit manually.
// Re-run init-db.php --force to regenerate.
return [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'dbname'   => '$dbName',
    'user'     => '$dbUser',
    'password' => '$dbPass',
    'charset'  => 'utf8mb4',
];
PHP;

file_put_contents($dbConfigFile, $dbConfigContent);
echo "  Database config written to database/db.php\n";

// ---- Write portal config ----
$portalCfg = realpath(dirname($installDir)) . '/config/portal.cfg';
if ($portalCfg && is_dir(dirname($portalCfg))) {
    file_put_contents($portalCfg, "port=$webPort\n");
    echo "  Portal config written.\n";
}

// ---- Check connection.php is using db.php config ----
$connFile = $installDir . '/database/connection.php';
if (!file_exists($connFile)) {
    // Create a connection.php that reads from db.php
    $connContent = <<<'PHP'
<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/db.php';

try {
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset={$cfg['charset']}",
        $cfg['user'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    error_log('DB connection failed: ' . $e->getMessage());
    die('Database connection failed. Please contact your administrator.');
}
PHP;
    file_put_contents($connFile, $connContent);
    echo "  database/connection.php created.\n";
}

echo "\n";
echo "============================================================\n";
echo "  Glasshouse NOC Portal — Database initialised successfully!\n";
echo "============================================================\n";
echo "  Admin:  $adminEmail\n";
echo "  URL:    http://localhost:$webPort/dashboard.php\n";
echo "============================================================\n";
exit(0);

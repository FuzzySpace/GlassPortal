<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    $code = $e->getCode();
    $msg  = $e->getMessage();

    // Helpful error page instead of a raw PHP crash
    http_response_code(500);
    $isTableMissing = str_contains($msg, '42S02') || str_contains($msg, "doesn't exist");
    $isAccessDenied = str_contains($msg, 'Access denied') || $code == 1045;
    $isNoServer     = str_contains($msg, 'Connection refused') || str_contains($msg, 'No such file') || $code == 2002;

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">
    <title>Database Error • Glasshouse Portal</title>
    <style>
      body{margin:0;font-family:system-ui,sans-serif;background:#07090f;color:#fff;display:grid;place-items:center;min-height:100vh;}
      .box{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.10);border-radius:16px;padding:32px 36px;max-width:560px;width:90%;}
      h1{margin:0 0 8px;font-size:20px;color:#f97316;}
      p{margin:8px 0;color:rgba(255,255,255,0.75);font-size:14px;line-height:1.6;}
      ol{padding-left:20px;margin:12px 0;}
      li{margin:6px 0;color:rgba(255,255,255,0.75);font-size:13px;}
      code{background:rgba(255,255,255,0.08);padding:2px 6px;border-radius:6px;font-size:12px;font-family:monospace;}
      .err{margin-top:16px;padding:10px 12px;background:rgba(255,90,90,0.10);border:1px solid rgba(255,90,90,0.25);border-radius:10px;font-size:12px;color:rgba(255,255,255,0.55);font-family:monospace;word-break:break-all;}
    </style></head><body><div class="box">';

    if ($isNoServer) {
        echo '<h1>MySQL / MariaDB is not running</h1>
        <p>The database server is not reachable. Start it first:</p>
        <ol>
          <li>Open the <strong>XAMPP Control Panel</strong></li>
          <li>Click <strong>Start</strong> next to <em>MySQL</em></li>
          <li>Refresh this page</li>
        </ol>';
    } elseif ($isTableMissing) {
        echo '<h1>Database tables not found</h1>
        <p>The database exists but the tables have not been created yet. Import the schema:</p>
        <ol>
          <li>Open <strong>phpMyAdmin</strong> → <a href="http://localhost/phpmyadmin" style="color:#f97316;">localhost/phpmyadmin</a></li>
          <li>Click <strong>Import</strong> in the top menu</li>
          <li>Choose file: <code>provisioning portal/database/schema.sql</code></li>
          <li>Click <strong>Go</strong></li>
          <li>Refresh this page</li>
        </ol>
        <p>Or via command line: <code>mysql -u root &lt; "provisioning portal/database/schema.sql"</code></p>';
    } elseif ($isAccessDenied) {
        echo '<h1>Database login failed</h1>
        <p>The credentials in <code>database/config.php</code> were rejected.</p>
        <ol>
          <li>Either run <code>schema.sql</code> first (it creates the <code>portal_user</code> account)</li>
          <li>Or edit <code>database/config.php</code> and set <code>username =&gt; \'root\'</code> and <code>password =&gt; \'\'</code> for a local XAMPP install</li>
        </ol>';
    } else {
        echo '<h1>Database connection failed</h1>
        <p>An unexpected error occurred connecting to the database.</p>';
    }

    echo '<div class="err">' . htmlspecialchars($msg) . '</div></div></body></html>';
    exit;
}

<?php
// TEMPORARY diagnostic script — delete after use.
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

echo "PHP version: " . PHP_VERSION . "\n";
echo "mysqli loaded: " . (extension_loaded('mysqli') ? 'yes' : 'NO') . "\n";
echo "openssl loaded: " . (extension_loaded('openssl') ? 'yes' : 'NO') . "\n\n";

require_once 'config/database.php';

echo "Connecting to " . DB_HOST . " / " . DB_NAME . " as " . DB_USER . " ...\n";
$start = microtime(true);
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$elapsed = round((microtime(true) - $start) * 1000);

if ($conn->connect_errno) {
    echo "CONNECT FAILED ({$elapsed}ms)\n";
    echo "Errno: " . $conn->connect_errno . "\n";
    echo "Error: " . $conn->connect_error . "\n";
    exit;
}

echo "CONNECTED OK ({$elapsed}ms)\n\n";

$res = $conn->query("SHOW TABLES");
if (!$res) {
    echo "SHOW TABLES failed: " . $conn->error . "\n";
    exit;
}

echo "Tables found:\n";
$count = 0;
while ($row = $res->fetch_array()) {
    echo "  - " . $row[0] . "\n";
    $count++;
}
if ($count === 0) echo "  (none — database is empty, schema not imported)\n";

echo "\nChecking tbl_users...\n";
$check = $conn->query("SELECT COUNT(*) AS c FROM tbl_users");
if (!$check) {
    echo "tbl_users query failed: " . $conn->error . "\n";
} else {
    $row = $check->fetch_assoc();
    echo "tbl_users row count: " . $row['c'] . "\n";
}

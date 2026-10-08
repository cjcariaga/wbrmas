<?php
// Quick test — simulates a generate_api call with dummy data
// Visit: http://localhost/documents/test_api.php
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/database.php';
secure_session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'System Administrator') {
    http_response_code(403);
    exit('Access denied.');
}
if (($_SESSION['role_name'] ?? '') === 'Barangay Treasurer') {
    http_response_code(403);
    exit('Access denied.');
}

echo "<h2>generate_api.php Test</h2>";

// Check if file exists
echo file_exists(__DIR__.'/generate_api.php') ? "✅ generate_api.php found<br>" : "❌ generate_api.php NOT found<br>";
echo file_exists(__DIR__.'/search_resident.php') ? "✅ search_resident.php found<br>" : "❌ search_resident.php NOT found<br>";
echo file_exists(__DIR__.'/../config/security.php') ? "✅ security.php found<br>" : "❌ security.php NOT found<br>";

// Check DB
$conn = getDBConnection();
$res = $conn->query("SELECT resident_id, resident_code FROM tbl_residents WHERE is_archived=0 LIMIT 3");
echo "<br><strong>Sample Residents:</strong><br>";
if ($res && $res->num_rows > 0) {
    while ($r = $res->fetch_assoc()) {
        echo "ID: {$r['resident_id']} | Code: {$r['resident_code']}<br>";
    }
} else {
    echo "No residents found.<br>";
}

// Check doc types
$dt = $conn->query("SELECT type_id, type_name, fee FROM tbl_document_types");
echo "<br><strong>Document Types:</strong><br>";
if ($dt) while ($r = $dt->fetch_assoc()) echo "ID:{$r['type_id']} | {$r['type_name']} | ₱{$r['fee']}<br>";

// Check session
echo "<br><strong>Session:</strong><br>";
echo "user_id: " . ($_SESSION['user_id'] ?? 'NOT SET') . "<br>";
echo "role: " . ($_SESSION['role_name'] ?? 'NOT SET') . "<br>";
echo "csrf_token: " . (isset($_SESSION['csrf_token']) ? 'SET' : 'NOT SET') . "<br>";

echo "<br><a href='/documents/issue.php'>← Back to Issue Document</a>";
?>

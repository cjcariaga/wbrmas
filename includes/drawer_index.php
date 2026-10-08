<?php
function ensure_default_drawers($conn) {
    $stmt = $conn->prepare("INSERT IGNORE INTO tbl_storage_drawers (drawer_name) VALUES (?), (?), (?), (?)");
    if (!$stmt) return false;
    $drawer_one = 'Drawer 1';
    $drawer_two = 'Drawer 2';
    $drawer_three = 'Drawer 3';
    $drawer_four = 'Drawer 4';
    $stmt->bind_param('ssss', $drawer_one, $drawer_two, $drawer_three, $drawer_four);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
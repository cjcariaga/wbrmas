<?php
require_once '../config/security.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !check_session_timeout()) {
    echo json_encode(['success'=>false,'message'=>'Session expired.']); exit;
}
if ($_SESSION['role_name'] !== 'System Administrator') {
    echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success'=>false,'message'=>'Invalid CSRF token.']); exit;
}

$action = $_GET['action'] ?? '';
$conn   = getDBConnection();
$uid    = (int)$_SESSION['user_id'];

if ($action === 'add') {
    $last_name  = sanitize_input($_POST['last_name']   ?? '');
    $first_name = sanitize_input($_POST['first_name']  ?? '');
    $middle_name= sanitize_input($_POST['middle_name'] ?? '');
    $username   = sanitize_input($_POST['username']    ?? '');
    $password   = $_POST['password'] ?? '';
    $role_id    = (int)($_POST['role_id'] ?? 0);
    $role_stmt = $conn->prepare("SELECT role_id, role_name FROM tbl_roles WHERE role_id=?");
    $role_stmt->bind_param("i", $role_id);
    $role_stmt->execute();
    $selected_role = $role_stmt->get_result()->fetch_assoc();
    $role_stmt->close();
    if (!$selected_role) { echo json_encode(['success'=>false,'message'=>'Select a valid role.']); exit; }

    // Build full_name from parts
    $full_name  = trim("$last_name, $first_name" . ($middle_name ? " $middle_name" : ''));

    if (!$first_name || !$last_name || !$username || !$password)
        { echo json_encode(['success'=>false,'message'=>'First name, last name, username, and password are required.']); exit; }
    if (strlen($password) < 8)
        { echo json_encode(['success'=>false,'message'=>'Password must be at least 8 characters.']); exit; }
    if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $username))
        { echo json_encode(['success'=>false,'message'=>'Username: letters, numbers, underscores, dots only.']); exit; }

    $chk = $conn->prepare("SELECT user_id FROM tbl_users WHERE username=?");
    $chk->bind_param("s",$username); $chk->execute();
    if ($chk->get_result()->fetch_assoc())
        { echo json_encode(['success'=>false,'message'=>'Username already exists.']); exit; }
    $chk->close();

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>10]);
    $stmt = $conn->prepare("INSERT INTO tbl_users (username,password_hash,full_name,first_name,middle_name,last_name,role_id,is_active) VALUES (?,?,?,?,?,?,?,1)");
    $stmt->bind_param("ssssssi",$username,$hash,$full_name,$first_name,$middle_name,$last_name,$role_id);
    if ($stmt->execute()) {
        $new_uid = $conn->insert_id;

        // Auto-assign default permissions based on role
        $default_perms = [];
        if ($selected_role['role_name'] === 'System Administrator') {
            // Admin gets all permissions
            $all = $conn->query("SELECT perm_key FROM tbl_permissions");
            while ($r = $all->fetch_assoc()) $default_perms[] = $r['perm_key'];
        } elseif ($selected_role['role_name'] === 'Barangay Treasurer') {
            $default_perms = ['view_financial_reports', 'manage_financial_reports'];
        } else {
            // Barangay Staff default permissions
            // NOTE: add_resident, edit_resident, archive_resident are ADMIN-ONLY
            $default_perms = [
                'view_dashboard',
                'view_stats',
                'view_residents',
                'view_resident_sensitive',
                'view_documents',
                'create_document',
                'approve_document',
                'print_document',
                'view_blotter',
                'add_blotter',
                'update_blotter',
                'view_blotter_details',
                'view_drawer_index',
                'manage_drawer_index',
                'view_health_records',
                'manage_health_records'
            ];
        }

        if (!empty($default_perms)) {
            $ps = $conn->prepare("INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted) VALUES (?,?,1)");
            foreach ($default_perms as $pk) {
                $ps->bind_param("is", $new_uid, $pk);
                $ps->execute();
            }
            $ps->close();
        }

        write_audit_log($uid,'CREATE_USER',"username:$username","Role:$role_id Name:$full_name Perms:".count($default_perms));
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Database error: '.$stmt->error]);
    }
    $stmt->close();
}

elseif ($action === 'update') {
    $target_id   = (int)($_POST['user_id']     ?? 0);
    $last_name   = sanitize_input($_POST['last_name']   ?? '');
    $first_name  = sanitize_input($_POST['first_name']  ?? '');
    $middle_name = sanitize_input($_POST['middle_name'] ?? '');
    $username    = sanitize_input($_POST['username']    ?? '');
    $role_id     = (int)($_POST['role_id']     ?? 0);
    $is_active   = (int)($_POST['is_active']   ?? 1);
    $full_name   = trim("$last_name, $first_name" . ($middle_name ? " $middle_name" : ''));

    if (!$target_id || !$first_name || !$last_name || !$username)
        { echo json_encode(['success'=>false,'message'=>'Required fields missing.']); exit; }
    $role_stmt = $conn->prepare("SELECT role_id, role_name FROM tbl_roles WHERE role_id=?");
    $role_stmt->bind_param("i", $role_id);
    $role_stmt->execute();
    $selected_role = $role_stmt->get_result()->fetch_assoc();
    $role_stmt->close();
    if (!$selected_role) { echo json_encode(['success'=>false,'message'=>'Select a valid role.']); exit; }
    if ($target_id === $uid && $selected_role['role_name'] !== 'System Administrator')
        { echo json_encode(['success'=>false,'message'=>'Cannot change your own role.']); exit; }

    $old_role_stmt = $conn->prepare("SELECT r.role_name FROM tbl_users u JOIN tbl_roles r ON r.role_id=u.role_id WHERE u.user_id=?");
    $old_role_stmt->bind_param("i", $target_id);
    $old_role_stmt->execute();
    $old_role = $old_role_stmt->get_result()->fetch_assoc();
    $old_role_stmt->close();
    if (!$old_role) { echo json_encode(['success'=>false,'message'=>'User not found.']); exit; }

    $chk = $conn->prepare("SELECT user_id FROM tbl_users WHERE username=? AND user_id!=?");
    $chk->bind_param("si",$username,$target_id); $chk->execute();
    if ($chk->get_result()->fetch_assoc())
        { echo json_encode(['success'=>false,'message'=>'Username already taken.']); exit; }
    $chk->close();

    $stmt = $conn->prepare("UPDATE tbl_users SET full_name=?,first_name=?,middle_name=?,last_name=?,username=?,role_id=?,is_active=? WHERE user_id=?");
    $stmt->bind_param("sssssiii",$full_name,$first_name,$middle_name,$last_name,$username,$role_id,$is_active,$target_id);
    if ($stmt->execute()) {
        if ($selected_role['role_name'] !== $old_role['role_name']) {
            $conn->query("DELETE FROM tbl_user_permissions WHERE user_id=$target_id");
            if ($selected_role['role_name'] === 'Barangay Treasurer') {
                $role_defaults = ['view_financial_reports', 'manage_financial_reports'];
            } elseif ($selected_role['role_name'] === 'Barangay Staff') {
                $role_defaults = [
                    'view_dashboard','view_stats','view_residents','view_resident_sensitive',
                    'view_documents','create_document','approve_document','print_document',
                    'view_blotter','add_blotter','update_blotter','view_blotter_details',
                    'view_drawer_index','manage_drawer_index','view_health_records','manage_health_records'
                ];
            } else {
                $role_defaults = [];
            }
            $permission_stmt = $conn->prepare("INSERT IGNORE INTO tbl_user_permissions (user_id, perm_key, granted) VALUES (?,?,1)");
            foreach ($role_defaults as $permission_key) {
                $permission_stmt->bind_param("is", $target_id, $permission_key);
                $permission_stmt->execute();
            }
            $permission_stmt->close();
        }
        write_audit_log($uid,'UPDATE_USER',"user_id:$target_id","Name:$full_name Role:$role_id");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Update failed.']);
    }
    $stmt->close();
}

elseif ($action === 'reset_password') {
    $target_id   = (int)($_POST['user_id']       ?? 0);
    $new_password = $_POST['new_password']         ?? '';

    if (!$target_id || strlen($new_password) < 8)
        { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }

    $hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost'=>10]);
    $stmt = $conn->prepare("UPDATE tbl_users SET password_hash=?,failed_attempts=0,locked_until=NULL WHERE user_id=?");
    $stmt->bind_param("si",$hash,$target_id);
    if ($stmt->execute()) {
        write_audit_log($uid,'RESET_PASSWORD',"user_id:$target_id","Password reset by admin");
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Reset failed.']);
    }
    $stmt->close();
}

elseif ($action === 'unlock') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    if (!$target_id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    $stmt = $conn->prepare("UPDATE tbl_users SET failed_attempts=0,locked_until=NULL WHERE user_id=?");
    $stmt->bind_param("i",$target_id);
    if ($stmt->execute()) {
        write_audit_log($uid,'UNLOCK_USER',"user_id:$target_id","Unlocked by admin");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false,'message'=>'Failed.']);
    $stmt->close();
}

elseif ($action === 'toggle') {
    $target_id = (int)($_POST['user_id']  ?? 0);
    $is_active = (int)($_POST['is_active'] ?? 0);
    if ($target_id === $uid)
        { echo json_encode(['success'=>false,'message'=>'Cannot deactivate your own account.']); exit; }
    $stmt = $conn->prepare("UPDATE tbl_users SET is_active=? WHERE user_id=?");
    $stmt->bind_param("ii",$is_active,$target_id);
    if ($stmt->execute()) {
        write_audit_log($uid,'TOGGLE_USER',"user_id:$target_id","Active:$is_active");
        echo json_encode(['success'=>true]);
    } else echo json_encode(['success'=>false,'message'=>'Failed.']);
    $stmt->close();
}

elseif ($action === 'get_perms') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $res2 = $conn->query("SELECT perm_key FROM tbl_user_permissions WHERE user_id=$target_id AND granted=1");
    $perms = [];
    while ($r = $res2->fetch_assoc()) $perms[] = $r['perm_key'];
    echo json_encode(['success'=>true,'perms'=>$perms]);
}

elseif ($action === 'save_perms') {
    $target_id = (int)($_POST['user_id'] ?? 0);
    $perms     = json_decode($_POST['perms'] ?? '[]', true);
    if (!$target_id) { echo json_encode(['success'=>false,'message'=>'Invalid user.']); exit; }
    $chk2 = $conn->query("SELECT r.role_name FROM tbl_users u JOIN tbl_roles r ON r.role_id=u.role_id WHERE u.user_id=$target_id")->fetch_assoc();
    if (!$chk2) { echo json_encode(['success'=>false,'message'=>'User not found.']); exit; }
    if ($chk2['role_name'] === 'System Administrator') {
        echo json_encode(['success'=>false,'message'=>'Cannot modify System Administrator permissions.']); exit;
    }
    $target_role = $chk2['role_name'];
    if ($target_role === 'Barangay Treasurer') {
        $perms = array_values(array_intersect((array)$perms, ['view_financial_reports', 'manage_financial_reports']));
    }
    $conn->query("DELETE FROM tbl_user_permissions WHERE user_id=$target_id");
    if (!empty($perms)) {
        $valid_keys = [];
        $vres = $conn->query("SELECT perm_key FROM tbl_permissions");
        while ($r = $vres->fetch_assoc()) $valid_keys[] = $r['perm_key'];
        $stmt = $conn->prepare("INSERT INTO tbl_user_permissions (user_id,perm_key,granted) VALUES (?,?,1)");
        foreach ($perms as $pk) {
            if (in_array($pk, $valid_keys)) {
                $stmt->bind_param("is", $target_id, $pk);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
    write_audit_log($uid,'SAVE_PERMISSIONS',"user_id:$target_id","Perms:".implode(',',(array)$perms));
    echo json_encode(['success'=>true]);
}

else { echo json_encode(['success'=>false,'message'=>'Unknown action.']); }
?>

<?php
require_once __DIR__ . '/database.php';

// ─── AES-256 Field-Level Encryption ───────────────────────────────────────────
function aes_encrypt($plaintext) {
    if ($plaintext === null || $plaintext === '') return '';
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', AES_SECRET_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

function aes_decrypt($ciphertext) {
    if ($ciphertext === null || $ciphertext === '') return '';
    $data = base64_decode($ciphertext);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    return openssl_decrypt($encrypted, 'AES-256-CBC', AES_SECRET_KEY, OPENSSL_RAW_DATA, $iv);
}

// ─── HMAC-SHA256 Audit Log Signing ────────────────────────────────────────────
function hmac_sign($data) {
    return hash_hmac('sha256', $data, HMAC_SECRET_KEY);
}

function hmac_verify($data, $signature) {
    return hash_equals(hmac_sign($data), $signature);
}

// ─── CSRF Token ───────────────────────────────────────────────────────────────
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ─── Input Sanitization (XSS Prevention) ─────────────────────────────────────
function sanitize_input($input) {
    if (is_array($input)) {
        return array_map('sanitize_input', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function sanitize_output($output) {
    return htmlspecialchars($output ?? '', ENT_QUOTES, 'UTF-8');
}

// ─── Rate Limiting ────────────────────────────────────────────────────────────
function check_rate_limit($identifier) {
    $conn = getDBConnection();
    $now = time();
    $window_start = $now - RATE_LIMIT_WINDOW;
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM tbl_rate_limits WHERE identifier = ? AND attempted_at > FROM_UNIXTIME(?)");
    $stmt->bind_param("si", $identifier, $window_start);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($result['cnt'] >= RATE_LIMIT_MAX) return false;
    $stmt2 = $conn->prepare("INSERT INTO tbl_rate_limits (identifier, attempted_at) VALUES (?, NOW())");
    $stmt2->bind_param("s", $identifier);
    $stmt2->execute();
    $stmt2->close();
    return true;
}

// ─── Session Security ─────────────────────────────────────────────────────────
function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', 1);
        session_start();
    }
}

function check_session_timeout() {
    if (isset($_SESSION['last_activity'])) {
        if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            return false;
        }
    }
    $_SESSION['last_activity'] = time();
    return true;
}

// ─── Audit Logging ────────────────────────────────────────────────────────────
function write_audit_log($user_id, $action_type, $affected_record, $details = '') {
    $conn = getDBConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $timestamp = date('Y-m-d H:i:s');
    $log_content = "$user_id|$action_type|$affected_record|$ip|$timestamp|$details";
    $hmac = hmac_sign($log_content);
    $stmt = $conn->prepare(
        "INSERT INTO tbl_audit_logs (user_id, action_type, affected_record, ip_address, timestamp, details, hmac_signature)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issssss", $user_id, $action_type, $affected_record, $ip, $timestamp, $details, $hmac);
    $stmt->execute();
    $stmt->close();
}
?>

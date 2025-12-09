<?php
// admin-verify-otp.php
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input'), true);
$email = trim($payload['email'] ?? '');
$otpInput = trim($payload['otp'] ?? '');

if (!$email || !$otpInput) {
    echo json_encode(['ok'=>false,'msg'=>'Missing fields.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT admin_id, reset_token_hash, reset_token_expires_at FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !$user['reset_token_hash']) {
        echo json_encode(['ok'=>false,'msg'=>'No OTP request found.']);
        exit;
    }

    if (strtotime($user['reset_token_expires_at']) < time()) {
        echo json_encode(['ok'=>false,'msg'=>'OTP expired. Please request a new code.']);
        exit;
    }

    if (!password_verify($otpInput, $user['reset_token_hash'])) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid OTP.']);
        exit;
    }

    // OTP correct
    echo json_encode(['ok'=>true,'msg'=>'OTP verified. Proceed to reset password.']);

} catch (Exception $e) {
    error_log("[Admin OTP verify error] " . $e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Server error verifying OTP.']);
}

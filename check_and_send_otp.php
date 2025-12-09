<?php
// check_and_send_otp.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input'), true);
$email = trim($payload['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok'=>false, 'msg'=>'Invalid email address.']);
    exit;
}

try {
    // 1) Check if email exists
    $stmt = $pdo->prepare("SELECT janitor_id FROM janitors WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['ok'=>false, 'msg'=>'Email not found in our records.']);
        exit;
    }

    // OPTIONAL: rate limit (e.g., 1 request per 60s)
    $stmt2 = $pdo->prepare("SELECT reset_token_expires_at FROM janitors WHERE email = ?");
    $stmt2->execute([$email]);
    $row2 = $stmt2->fetch();
    if ($row2 && isset($row2['reset_token_expires_at'])) {
        // nothing strict here — you can add checks if needed
    }

    // 2) Generate OTP
    $otp = random_int(100000, 999999); // 6-digit numeric
    $otpHash = password_hash((string)$otp, PASSWORD_DEFAULT);
    $expiry = date('Y-m-d H:i:s', time() + 10 * 60); // 10 minutes

    // 3) Save hashed OTP and expiry
    $upd = $pdo->prepare("UPDATE janitors SET reset_token_hash = ?, reset_token_expires_at = ? WHERE email = ?");
    $upd->execute([$otpHash, $expiry, $email]);

    // 4) Send OTP email (PHPMailer)
    $mail = new PHPMailer(true);
    // SMTP config: use values from your includes/config.php or hardcode here
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username = 'smartrashbin.system@gmail.com';
    $mail->Password = 'svqjvkmdkdedjbia'; // dito mo ilagay ang app password
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

$mail->setFrom('no-reply@smarttrashbin.com', 'Smart Trashbin System');
$mail->addAddress($email);
$mail->isHTML(true);
$mail->Subject = 'One-Time Password (OTP) for Smart Trashbin System';

// HTML body (keep concise so it displays prominently in mail clients)
$mail->Body = "<html><body style='font-family: Arial, sans-serif; line-height:1.6;color:#333;'>"
    . "<div style='max-width:600px;margin:0 auto;padding:20px;border:1px solid #ddd;border-radius:8px;'>"
    . "<h2 style='color:#16a34a;margin:0 0 8px;'>Password Recovery - User Account</h2>"
    . "<p style='margin:0 0 8px;'>Hello,</p>"
    . "<p style='margin:0 0 12px;'>Use the following 6-digit code to reset your password:</p>"
    . "<div style='background:#f5f5f5;padding:20px;margin:12px 0;border-radius:5px;text-align:center;'>"
    . "<h1 style='color:#16a34a;letter-spacing:5px;margin:0;'>" . $otp . "</h1></div>"
    . "<p style='margin:12px 0 0;'><strong>This code expires in 10 minutes.</strong></p>"
    . "<hr style='border:none;border-top:1px solid #ddd;margin:16px 0;'>"
    . "<p style='font-size:12px;color:#999;margin:0;'>If you did not request this, contact support immediately.</p>"
    . "</div></body></html>";

// Plain-text alternative for email clients and previews (makes code visible immediately)
$mail->AltBody = "Your Smart Trashbin OTP is: " . $otp . " (expires in 10 minutes). If you didn't request this, ignore this message.";

// Send mail
$mail->send();

    echo json_encode(['ok'=>true, 'msg'=>'OTP sent to your email. Check your inbox.']);

} catch (Exception $e) {
    error_log("[OTP send error] " . $e->getMessage());
    echo json_encode(['ok'=>false, 'msg'=>'Failed to send OTP. Please try again later.']);
}

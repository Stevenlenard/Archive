<?php
// admin-check-and-send-otp.php
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
    // 1) Check if email exists in admin table
    $stmt = $pdo->prepare("SELECT admin_id FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['ok'=>false, 'msg'=>'Email not found in our records.']);
        exit;
    }

    // 2) Generate OTP
    $otp = random_int(100000, 999999); // 6-digit numeric
    $otpHash = password_hash((string)$otp, PASSWORD_DEFAULT);
    $expiry = date('Y-m-d H:i:s', time() + 10 * 60); // 10 minutes

    // 3) Save hashed OTP and expiry
    $upd = $pdo->prepare("UPDATE admins SET reset_token_hash = ?, reset_token_expires_at = ? WHERE email = ?");
    $upd->execute([$otpHash, $expiry, $email]);

    // 4) Send OTP email (PHPMailer)
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username = 'smartrashbin.system@gmail.com';
    $mail->Password = 'svqjvkmdkdedjbia';
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('no-reply@smarttrashbin.com', 'Smart Trashbin System');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'One-Time Password (OTP) for Smart Trashbin Admin System';
    $mail->Body = "
    <html>
      <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
          <h2 style='color: #16a34a;'>Password Recovery - Admin Account</h2>
          <p>Hello Admin,</p>
          <p>You requested a password reset for your admin account. Use the following 6-digit code to proceed:</p>
            <div style='background: #f5f5f5; padding: 20px; margin: 20px 0; border-radius: 5px; text-align: center;'>
            <h1 style='color: #16a34a; letter-spacing: 5px; margin: 0;'>{$otp}</h1>
          </div>
          <p><strong>This code will expire in 10 minutes.</strong></p>
          <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
          <p style='font-size: 12px; color: #999;'>If you did not request this, please contact support immediately.</p>
          <p style='font-size: 12px; color: #999;'>Smart Trashbin System &copy; 2025. All rights reserved.</p>
        </div>
      </body>
    </html>
    ";
    // Plain-text alternative for clients that show a preview (makes OTP immediately visible)
    $mail->AltBody = "Your Smart Trashbin Admin OTP is: " . $otp . " (expires in 10 minutes).";

    if ($mail->send()) {
        echo json_encode(['ok'=>true, 'msg'=>'OTP sent to your email. Check your inbox.']);
    } else {
        echo json_encode(['ok'=>false, 'msg'=>'Failed to send OTP. Please try again.']);
    }
} catch (Exception $e) {
    error_log("[Admin OTP error] " . $e->getMessage());
    echo json_encode(['ok'=>false, 'msg'=>'Server error. Please try again later.']);
}

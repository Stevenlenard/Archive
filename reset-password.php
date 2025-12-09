<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/vendor/autoload.php'; // PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$payload = json_decode(file_get_contents('php://input'), true);
$email = trim($payload['email'] ?? '');
$newPassword = trim($payload['new_password'] ?? '');

if (!$email || !$newPassword) {
    echo json_encode(['ok'=>false,'msg'=>'Missing fields.']);
    exit;
}

if (
    strlen($newPassword) < 6 ||
    !preg_match('/[A-Z]/', $newPassword) ||
    !preg_match('/[a-z]/', $newPassword) ||
    !preg_match('/\d/', $newPassword) ||
    !preg_match('/[\W_]/', $newPassword)
) {
    echo json_encode([
      'ok' => false,
      'msg' => 'Password must be at least 6 characters long and contain uppercase, lowercase, number, and special character.'
    ]);
    exit;
}

try {
    // Check current password to prevent reuse
    $check = $pdo->prepare("SELECT password FROM janitors WHERE email = ?");
    $check->execute([$email]);
    $row = $check->fetch();
    if (!$row) {
        echo json_encode(['ok'=>false,'msg'=>'Email not found.']);
        exit;
    }

    if (password_verify($newPassword, $row['password'])) {
        echo json_encode(['ok'=>false,'msg'=>'New password cannot be the same as your current password. Please choose a different password.']);
        exit;
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE janitors 
        SET password = ?, reset_token_hash = NULL, reset_token_expires_at = NULL
        WHERE email = ?");
    $stmt->execute([$hashed, $email]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok'=>false,'msg'=>'Email not found or password not updated.']);
        exit;
    }

    // --- SEND CONFIRMATION EMAIL ---
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'smartrashbin.system@gmail.com';
        $mail->Password = 'svqjvkmdkdedjbia'; // dito mo ilagay ang app password
        $mail->SMTPSecure = 'tls'; 
        $mail->Port = 587;

        // Recipients
        $mail->setFrom('smartrashbin.system@gmail.com', 'Smart Trashbin');
        $mail->addAddress($email);

                // Content
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Confirmation - User Account';
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $userLoginUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/user-login.php';
                $mail->Body = "
                <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px;'>
                            <h2 style='color: #16a34a;'>Password Reset Successful</h2>
                            <p>Hello User,</p>
                            <p>Your password has been successfully reset. You can now log in with your new password.</p>
                            <p style='margin-top: 30px;'>
                                <a href='{$userLoginUrl}' style='background: #16a34a; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Go to User Login</a>
                            </p>
                            <hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>
                            <p style='font-size: 12px; color: #999;'>If you did not request this, please contact support immediately.</p>
                            <p style='font-size: 12px; color: #999;'>Smart Trashbin System &copy; 2025. All rights reserved.</p>
                        </div>
                    </body>
                </html>
                ";

                // Plain-text alternative to ensure the confirmation text is visible in previews
                $mail->AltBody = "Your Smart Trashbin password has been reset. You can login here: " . $userLoginUrl;

        $mail->send();
    } catch (Exception $e) {
        // You can log the error but still return success to user
        error_log('Mail error: ' . $mail->ErrorInfo);
    }

    echo json_encode(['ok'=>true,'msg'=>'Password reset successfully! A confirmation email has been sent to your inbox.']);

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'msg'=>'Error: '.$e->getMessage()]);
}

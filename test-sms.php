<?php
require_once 'includes/config.php';
require_once 'includes/sms_send.php';

// Test SMS sending
$phone = '09123456789'; // Replace with actual test number
$message = 'Test SMS from Smart Trashbin System - This is a test message!';

echo "<h2>Testing SMS Gateway</h2>";
echo "<p>Sending to: {$phone}</p>";
echo "<p>Message: {$message}</p>";
echo "<hr>";

$result = sms_send($phone, $message);

echo "<h3>Response:</h3>";
echo "<pre>";
print_r($result);
echo "</pre>";

if ($result['success']) {
    echo "<p style='color: green;'><strong>✓ SMS sent successfully!</strong></p>";
} else {
    echo "<p style='color: red;'><strong>✗ SMS failed to send!</strong></p>";
    if (isset($result['error'])) {
        echo "<p>Error: " . htmlspecialchars($result['error']) . "</p>";
    }
}

// Check error logs
echo "<h3>Recent Error Logs:</h3>";
echo "<pre>";
$log_file = ini_get('error_log');
if ($log_file && file_exists($log_file)) {
    $lines = array_slice(file($log_file), -20); // Last 20 lines
    foreach ($lines as $line) {
        if (strpos($line, 'sms_send') !== false) {
            echo htmlspecialchars($line);
        }
    }
} else {
    echo "Error log file not found or not configured.";
}
echo "</pre>";
?>

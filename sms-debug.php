<?php
require_once 'includes/config.php';
require_once 'includes/sms_send.php';

// Get all janitors with assigned bins that are full
$query = "
    SELECT 
        j.janitor_id,
        CONCAT(j.first_name, ' ', j.last_name) AS janitor_name,
        j.phone,
        COUNT(b.bin_id) AS full_bins_count,
        GROUP_CONCAT(b.bin_code) AS bin_codes
    FROM janitors j
    LEFT JOIN bins b ON j.janitor_id = b.assigned_to AND b.status = 'full'
    WHERE j.status = 'active'
    GROUP BY j.janitor_id
    ORDER BY full_bins_count DESC
";

$result = $conn->query($query);
$janitors = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $janitors[] = $row;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SMS Debug & Test</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #f5f5f5; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        .test-section { 
            border: 1px solid #ddd; 
            padding: 15px; 
            margin: 20px 0; 
            background-color: #f9f9f9;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>

<h1>SMS Gateway Debug & Testing</h1>

<div class="test-section">
    <h2>System Configuration</h2>
    <table>
        <tr>
            <td><strong>API Key Defined:</strong></td>
            <td><?php echo defined('IPROGSMS_API_KEY') ? '<span class="success">✓ Yes</span>' : '<span class="error">✗ No</span>'; ?></td>
        </tr>
        <tr>
            <td><strong>API Key Value:</strong></td>
            <td><code><?php echo defined('IPROGSMS_API_KEY') ? substr(IPROGSMS_API_KEY, 0, 10) . '...' : 'Not defined'; ?></code></td>
        </tr>
        <tr>
            <td><strong>Sender Name:</strong></td>
            <td><code><?php echo defined('IPROGSMS_SENDER_NAME') ? IPROGSMS_SENDER_NAME : 'Not defined (will use default)'; ?></code></td>
        </tr>
        <tr>
            <td><strong>sms_send() Function:</strong></td>
            <td><?php echo function_exists('sms_send') ? '<span class="success">✓ Available</span>' : '<span class="error">✗ Not available</span>'; ?></td>
        </tr>
    </table>
</div>

<div class="test-section">
    <h2>Janitors with Assigned Bins</h2>
    <?php if (empty($janitors)): ?>
        <p class="warning">No janitors found in database.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Janitor</th>
                    <th>Phone</th>
                    <th>Full Bins</th>
                    <th>Bin Codes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($janitors as $j): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($j['janitor_name']); ?></td>
                        <td><code><?php echo htmlspecialchars($j['phone']); ?></code></td>
                        <td><?php echo intval($j['full_bins_count']); ?></td>
                        <td><?php echo $j['bin_codes'] ? htmlspecialchars($j['bin_codes']) : '-'; ?></td>
                        <td>
                            <button onclick="testSMS(<?php echo $j['janitor_id']; ?>, '<?php echo htmlspecialchars($j['phone']); ?>', '<?php echo htmlspecialchars($j['janitor_name']); ?>')">
                                Test SMS
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="test-section">
    <h2>Quick Test</h2>
    <form method="POST" action="">
        <div>
            <label>Test Phone Number (with +63):</label><br>
            <input type="text" name="test_phone" value="+639123456789" style="width: 300px; padding: 8px;">
        </div>
        <div style="margin-top: 10px;">
            <label>Test Message:</label><br>
            <textarea name="test_message" style="width: 300px; height: 80px; padding: 8px;">Hi there, please clean Bin A01 at Main Hall immediately!</textarea>
        </div>
        <div style="margin-top: 10px;">
            <button type="submit" name="send_test">Send Test SMS</button>
        </div>
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
        $test_phone = trim($_POST['test_phone'] ?? '');
        $test_message = trim($_POST['test_message'] ?? '');
        
        if (empty($test_phone) || empty($test_message)) {
            echo '<p class="error">Phone and message cannot be empty!</p>';
        } else {
            echo '<h3>Sending SMS...</h3>';
            echo '<p>To: <code>' . htmlspecialchars($test_phone) . '</code></p>';
            echo '<p>Message: <code>' . htmlspecialchars($test_message) . '</code></p>';
            
            $result = sms_send($test_phone, $test_message);
            
            echo '<h3>Response:</h3>';
            echo '<pre>';
            print_r($result);
            echo '</pre>';
            
            if ($result['success']) {
                echo '<p class="success">✓ SMS sent successfully!</p>';
            } else {
                echo '<p class="error">✗ SMS failed!</p>';
                if (isset($result['error'])) {
                    echo '<p>Error: <code>' . htmlspecialchars($result['error']) . '</code></p>';
                }
            }
        }
    }
    ?>
</div>

<div class="test-section">
    <h2>Error Log</h2>
    <p><small>Last 30 lines containing 'sms_send':</small></p>
    <pre style="background-color: #f0f0f0; padding: 10px; overflow-x: auto; max-height: 400px;">
<?php
$log_file = ini_get('error_log');
if ($log_file && file_exists($log_file)) {
    $all_lines = file($log_file);
    $sms_lines = array_filter($all_lines, function($line) {
        return strpos($line, 'sms_send') !== false;
    });
    $sms_lines = array_slice(array_values($sms_lines), -30);
    if (empty($sms_lines)) {
        echo "No SMS-related log entries found.";
    } else {
        foreach ($sms_lines as $line) {
            echo htmlspecialchars($line);
        }
    }
} else {
    echo "Error log file not found: " . ($log_file ?: 'not configured');
}
?>
    </pre>
</div>

<script>
function testSMS(janitorId, phone, name) {
    if (confirm(`Send test SMS to ${name} at ${phone}?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const phoneInput = document.createElement('input');
        phoneInput.type = 'hidden';
        phoneInput.name = 'test_phone';
        phoneInput.value = phone;
        
        const msgInput = document.createElement('input');
        msgInput.type = 'hidden';
        msgInput.name = 'test_message';
        msgInput.value = `Hi ${name}, this is a test SMS from Smart Trashbin System. Please confirm receipt.`;
        
        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'send_test';
        submitInput.value = '1';
        
        form.appendChild(phoneInput);
        form.appendChild(msgInput);
        form.appendChild(submitInput);
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

</body>
</html>

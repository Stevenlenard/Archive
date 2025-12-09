<?php
require_once 'includes/config.php';
// SMS helper
require_once __DIR__ . '/includes/sms_send.php';

if (isset($_GET['bin_id'], $_GET['status'], $_GET['capacity'])) {

    $bin_id = intval($_GET['bin_id']);
    $status = $_GET['status'];
    $capacity = floatval($_GET['capacity']);

    $allowed = ['empty','half_full','full'];

    if (!in_array($status, $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Invalid status']);
        exit;
    }

    // 1️⃣ Update the bin table
    $stmt = $conn->prepare("UPDATE bins SET status=?, capacity=?, updated_at=NOW() WHERE bin_id=?");
    $stmt->bind_param("sdi", $status, $capacity, $bin_id);
    $success = $stmt->execute();
    $stmt->close();

    // 2️⃣ If status is FULL, insert a notification
    if ($status === 'full') {
        // Prevent duplicate notifications
        $check = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE bin_id=? AND notification_type='critical' AND is_read=0");
        $check->bind_param("i", $bin_id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();

        if (intval($row['c'] ?? 0) === 0) {
            $title = "Bin Full Alert";
            $message = "Bin ID $bin_id is full. Immediate attention required!";

            $notif = $conn->prepare("INSERT INTO notifications (bin_id, notification_type, title, message, created_at) VALUES (?, 'critical', ?, ?, NOW())");
            $notif->bind_param("iss", $bin_id, $title, $message);
            $notif->execute();
            $notif->close();
        }
    }

    // 3️⃣ Send SMS to assigned janitor when status is 'full' or 'empty'
    try {
        if (in_array($status, ['full', 'empty'], true)) {
            $phone = null; $bin_code = null; $location = null; $janitor_name = null;
            if ($stmt2 = $conn->prepare("SELECT j.phone, j.first_name, j.last_name, b.bin_code, b.location FROM bins b LEFT JOIN janitors j ON b.assigned_to = j.janitor_id WHERE b.bin_id = ? LIMIT 1")) {
                $stmt2->bind_param('i', $bin_id);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($res2 && $r2 = $res2->fetch_assoc()) {
                    $phone = trim($r2['phone'] ?? '');
                    $bin_code = $r2['bin_code'] ?? null;
                    $location = $r2['location'] ?? null;
                    $janitor_name = trim(($r2['first_name'] ?? '') . ' ' . ($r2['last_name'] ?? ''));
                }
                $stmt2->close();
            }

            if (!empty($phone)) {
                $binDisplay = $bin_code ?? "#{$bin_id}";
                $locDisplay = $location ?? 'unknown location';
                $nameDisplay = !empty($janitor_name) ? $janitor_name : 'there';
                if ($status === 'full') {
                    $smsMessage = "Hi {$nameDisplay}, please clean {$binDisplay} at {$locDisplay} immediately!";
                } else {
                    $smsMessage = "Hi {$nameDisplay}, thank you for emptying the bin {$binDisplay} at {$locDisplay}";
                }
                $smsRes = sms_send($phone, $smsMessage);
                if (empty($smsRes['success'])) {
                    error_log('[sms_send] hardware.php failed for bin ' . intval($bin_id) . ': ' . json_encode($smsRes));
                }
            }
        }
    } catch (Exception $e) {
        error_log('[sms_send] hardware.php exception: ' . $e->getMessage());
    }

    echo json_encode(['success'=>$success]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Missing parameters']);

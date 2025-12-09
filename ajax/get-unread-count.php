<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Count unread notifications. This is a simple global count of notifications
    // that are not marked as read. If you need role-specific counts, adjust
    // the WHERE clause to filter by admin_id/janitor_id as appropriate.
    $count = 0;
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['c'] ?? 0);
    } else {
        $res = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE is_read = 0");
        if ($res) {
            $r = $res->fetch_assoc();
            $count = (int)($r['c'] ?? 0);
        }
    }

    echo json_encode(['success' => true, 'unread_count' => $count]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

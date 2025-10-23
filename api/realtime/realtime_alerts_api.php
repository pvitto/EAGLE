<?php
// api/realtime/realtime_alerts_api.php

require dirname(__DIR__, 2) . '/config.php';
require dirname(__DIR__, 2) . '/db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];

// Use a 5-second buffer to prevent race conditions with server time
$since_timestamp = isset($_GET['since']) ? max(0, intval($_GET['since']) - 5) : (time() - 60);
$since_datetime_string = date('Y-m-d H:i:s', $since_timestamp);

$new_alerts = [];

// This API should return ALL high-priority alerts for relevant roles,
// the frontend will handle filtering what's "new" vs. "already seen".
// For now, we only care about Digitador and Admin seeing discrepancy alerts.
$user_filter_sql = '';
if (!in_array($current_user_role, ['Admin', 'Digitador'])) {
    // If user is not Admin or Digitador, we can assume for now they don't see these toasts.
    // A more complex role-based filtering could be added here if needed.
    $user_filter_sql = " AND 1=0"; // Effectively returns no alerts for other roles
}

$sql = "
    SELECT
        a.id,
        a.title,
        a.description,
        a.priority,
        a.created_at
    FROM alerts a
    WHERE
        a.priority IN ('Critica', 'Alta')
        AND a.created_at >= ?
        {$user_filter_sql}
    ORDER BY a.created_at DESC
    LIMIT 10
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("s", $since_datetime_string);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Build the alert array in the format the frontend expects
            $new_alerts[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'priority' => $row['priority']
            ];
        }
    } else {
        // Log error but don't output to user
        error_log("Error executing realtime alerts query: " . $stmt->error);
    }
    $stmt->close();
} else {
    error_log("Error preparing realtime alerts query: " . $conn->error);
}
$conn->close();

// Always return a valid JSON structure
echo json_encode([
    'success' => true,
    'alerts' => $new_alerts,
    'timestamp' => time()
]);
?>

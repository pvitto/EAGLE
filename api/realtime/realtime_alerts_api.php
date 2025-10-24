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

$since_timestamp = isset($_GET['since']) ? max(0, intval($_GET['since']) - 5) : (time() - 60);
$since_datetime_string = date('Y-m-d H:i:s', $since_timestamp);

$new_alerts = [];
$params = [$since_datetime_string];
$param_types = "s";

$alert_filter_sql = " AND 1=0 ";
if (in_array($current_user_role, ['Admin', 'Digitador'])) {
    $alert_filter_sql = " AND a.priority IN ('Critica', 'Alta') ";
} else {
    $alert_filter_sql = "
        AND (
            t.assigned_to_user_id = ?
            OR (t.assigned_to_group = ? AND t.assigned_to_user_id IS NULL)
        )
    ";
    $params[] = $current_user_id;
    $params[] = $current_user_role;
    $param_types .= "is";
}

$sql = "
    SELECT DISTINCT a.id, a.title, a.description, a.priority, a.created_at
    FROM alerts a
    LEFT JOIN tasks t ON t.alert_id = a.id AND t.status = 'Pendiente'
    WHERE a.created_at >= ? {$alert_filter_sql}
    ORDER BY a.created_at DESC
    LIMIT 10
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $bind_params = [];
    $bind_params[] = & $param_types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_params[] = & $params[$i];
    }
    call_user_func_array(array($stmt, 'bind_param'), $bind_params);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $new_alerts[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'priority' => $row['priority']
            ];
        }
    } else {
        error_log("Error executing realtime alerts query: " . $stmt->error);
    }
    $stmt->close();
} else {
    error_log("Error preparing realtime alerts query: " . $conn->error);
}
$conn->close();

echo json_encode([
    'success' => true,
    'alerts' => $new_alerts,
    'timestamp' => time()
]);
?>

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

// Usar un búfer de 5 segundos para evitar condiciones de carrera con la hora del servidor
$since_timestamp = isset($_GET['since']) ? max(0, intval($_GET['since']) - 5) : (time() - 60);
$since_datetime_string = date('Y-m-d H:i:s', $since_timestamp);

$new_alerts = [];
$params = [$since_datetime_string];
$param_types = "s";

// --- LÓGICA DE FILTRADO MEJORADA ---
// Por defecto, nadie ve nada a menos que una condición se cumpla.
$alert_filter_sql = " AND 1=0 ";

// Los Admins y Digitadores deben ver las alertas de discrepancia de alta prioridad.
if (in_array($current_user_role, ['Admin', 'Digitador'])) {
    $alert_filter_sql = " AND a.priority IN ('Critica', 'Alta') ";
} else {
    // Otros roles podrían tener sus propias alertas aquí.
    // Por ejemplo, un Operador podría ver alertas asignadas a su grupo.
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

// --- CONSULTA SQL CORREGIDA ---
$sql = "
    SELECT DISTINCT
        a.id,
        a.title,
        a.description,
        a.priority,
        a.created_at
    FROM alerts a
    LEFT JOIN tasks t ON t.alert_id = a.id AND t.status = 'Pendiente'
    WHERE
        a.created_at >= ?
        {$alert_filter_sql}
    ORDER BY a.created_at DESC
    LIMIT 10
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    // Usar el operador 'splat' (...) para pasar el array de parámetros
    $stmt->bind_param($param_types, ...$params);

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

// Devolver siempre una estructura JSON válida
echo json_encode([
    'success' => true,
    'alerts' => $new_alerts,
    'timestamp' => time()
]);
?>

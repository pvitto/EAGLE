<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Bogota'); // Zona horaria

session_start();
require '../../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];

// Filtro de tiempo: toma 'since' o resta 60 segundos
$since_param = isset($_GET['since']) ? intval($_GET['since']) : (time() - 60);
$since_datetime_string = date('Y-m-d H:i:s', $since_param);

$new_alerts = [];
$user_filter_sql = ''; // Filtro de usuario

// Si NO es Admin, aplica el filtro de usuario/grupo
if ($current_user_role !== 'Admin') {
    $escaped_role = $conn->real_escape_string($current_user_role);
    // Muestra tareas asignadas a MI ID o a MI GRUPO
    $user_filter_sql = " AND (
        t.assigned_to_user_id = {$current_user_id}
        OR t.assigned_to_group = '{$escaped_role}'
    )";
}

// CONSULTA SQL FINAL
$sql = "
    SELECT
        a.id as alert_id, a.title as alert_title, a.description as alert_description, a.priority as alert_priority,
        t.id as task_id, t.title as task_title, t.instruction as task_instruction, t.priority as task_priority, t.created_at as task_created_at,
        t.assigned_to_group, t.assigned_to_user_id, t.type as task_type
    FROM tasks t
    LEFT JOIN alerts a ON t.alert_id = a.id
    WHERE
        t.status = 'Pendiente'
        AND (t.priority = 'Critica' OR a.priority = 'Critica') -- Solo Criticas
        AND t.created_at >= ?                                  -- Solo Nuevas
        {$user_filter_sql}                                     -- Solo para mí (o todos si soy Admin)
    ORDER BY t.created_at DESC
    LIMIT 10
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    // Vincula el parámetro de tiempo (el único '?')
    $stmt->bind_param("s", $since_datetime_string);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $is_manual = empty($row['alert_id']);
        $priority = $row['task_priority'] ?? $row['alert_priority'] ?? 'Media';

        // Doble chequeo (aunque la consulta ya filtra)
        if ($priority === 'Critica') {
            $new_alerts[] = [
                'id' => $row['task_id'],
                'title' => $is_manual ? ($row['task_title'] ?? 'Alerta Crítica') : ($row['alert_title'] ?? 'Alerta Crítica'),
                'description' => $is_manual ? ($row['task_instruction'] ?? '') : ($row['alert_description'] ?? 'Revise sus tareas.'),
                'priority' => $priority,
                'created_at' => $row['task_created_at'],
                'type' => $is_manual ? 'manual_task' : 'alert_task'
            ];
        }
    }
    $stmt->close();
} else {
    // Si la consulta falla, lo veremos en el log Y en la consola
    error_log("Error preparando consulta final realtime_alerts_api: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error interno al buscar alertas críticas: ' . $conn->error]);
    exit;
}

$conn->close();

echo json_encode([
    'success' => true,
    'alerts' => $new_alerts,
    'timestamp' => time()
]);
?>
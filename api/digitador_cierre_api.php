<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['user_id'];

if ($method === 'GET' && $action === 'list_funds') {
    $query = "
        SELECT DISTINCT f.id, f.name, c.name as client_name
        FROM funds f
        JOIN clients c ON f.client_id = c.id
        JOIN check_ins ci ON ci.fund_id = f.id
        WHERE ci.status IN ('Procesado', 'Discrepancia') AND ci.digitador_status IS NULL
        ORDER BY f.name ASC
    ";
    $result = $conn->query($query);
    $funds = [];
    if ($result) { while ($row = $result->fetch_assoc()) { $funds[] = $row; } }
    echo json_encode($funds);

} elseif ($method === 'GET' && $action === 'get_services' && isset($_GET['fund_id'])) {
    $fund_id = intval($_GET['fund_id']);
    $stmt = $conn->prepare("
        SELECT ci.id, ci.invoice_number, ci.declared_value, ci.created_at, c.name as client_name
        FROM check_ins ci
        JOIN clients c ON ci.client_id = c.id
        WHERE ci.fund_id = ? AND ci.status IN ('Procesado', 'Discrepancia') AND ci.digitador_status IS NULL
        ORDER BY ci.created_at DESC
    ");
    $stmt->bind_param("i", $fund_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $services = [];
    while ($row = $result->fetch_assoc()) { $services[] = $row; }
    echo json_encode($services);
    $stmt->close();
    
} elseif ($method === 'POST' && $action === 'close_service') {
    $data = json_decode(file_get_contents('php://input'), true);
    $service_id = $data['service_id'] ?? null;
    if ($service_id) {
        // CORRECCIÓN: Ahora también guardamos el ID del digitador que cierra.
        $stmt = $conn->prepare("UPDATE check_ins SET digitador_status = 'Cerrado', closed_by_digitador_at = NOW(), closed_by_digitador_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $user_id, $service_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al cerrar el servicio.']);
        }
        $stmt->close();
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de servicio no proporcionado.']);
    }
}

$conn->close();
?>
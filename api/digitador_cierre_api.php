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

if ($method === 'GET' && $action === 'list_funds_to_close') {
    // Lista fondos que tienen al menos una planilla 'Conforme' Y NINGUNA planilla 'Cerrado'
    $query = "
        SELECT DISTINCT f.id, f.name, c.name as client_name
        FROM funds f
        JOIN clients c ON f.client_id = c.id
        JOIN check_ins ci ON ci.fund_id = f.id
        WHERE ci.digitador_status = 'Conforme'
        AND f.id NOT IN (SELECT fund_id FROM check_ins WHERE digitador_status = 'Cerrado' AND fund_id IS NOT NULL)
        ORDER BY f.name ASC
    ";
    $result = $conn->query($query);
    $funds = [];
    if ($result) { while ($row = $result->fetch_assoc()) { $funds[] = $row; } }
    echo json_encode($funds);

} elseif ($method === 'GET' && $action === 'get_services_for_closing' && isset($_GET['fund_id'])) {
    // Lista todas las planillas 'Conforme' de un fondo que están listas para ser cerradas
    $fund_id = intval($_GET['fund_id']);
    $stmt = $conn->prepare("
        SELECT ci.id, ci.invoice_number, ci.declared_value, oc.total_counted, oc.discrepancy
        FROM check_ins ci
        JOIN operator_counts oc ON ci.id = oc.check_in_id
        WHERE ci.fund_id = ? AND ci.digitador_status = 'Conforme'
        ORDER BY ci.created_at DESC
    ");
    $stmt->bind_param("i", $fund_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $services = [];
    while ($row = $result->fetch_assoc()) { $services[] = $row; }
    echo json_encode($services);
    $stmt->close();
    
} elseif ($method === 'POST' && $action === 'close_fund') {
    // Cierra TODAS las planillas 'Conforme' de un fondo específico
    $data = json_decode(file_get_contents('php://input'), true);
    $fund_id = $data['fund_id'] ?? null;
    if ($fund_id) {
        $stmt = $conn->prepare("
            UPDATE check_ins 
            SET digitador_status = 'Cerrado', 
                closed_by_digitador_at = NOW(), 
                closed_by_digitador_id = ? 
            WHERE fund_id = ? AND digitador_status = 'Conforme'
        ");
        $stmt->bind_param("ii", $user_id, $fund_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true, 'message' => 'Fondo cerrado exitosamente.']);
            } else {
                echo json_encode(['success' => false, 'error' => 'No se encontraron planillas conformes para cerrar.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al cerrar el fondo.']);
        }
        $stmt->close();
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ID de fondo no proporcionado.']);
    }
}

$conn->close();
?>
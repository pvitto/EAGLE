<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'Checkinero')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $query = "
            SELECT 
                ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, ci.fund_name,
                ci.created_at, c.name as client_name, r.name as route_name, u.name as checkinero_name
            FROM check_ins ci
            JOIN clients c ON ci.client_id = c.id
            JOIN routes r ON ci.route_id = r.id
            JOIN users u ON ci.checkinero_id = u.id
            ORDER BY ci.created_at DESC
        ";
        $result = $conn->query($query);
        $checkins = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $checkins[] = $row;
            }
        }
        echo json_encode($checkins);
        break;
    
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Asignar variables y validar
        $invoice_number = $data['invoice_number'] ?? '';
        $seal_number = $data['seal_number'] ?? '';
        $declared_value = $data['declared_value'] ?? 0;
        $fund_name = $data['fund_name'] ?? '';
        $client_id = $data['client_id'] ?? null;
        $route_id = $data['route_id'] ?? null;
        $checkinero_id = $_SESSION['user_id'];

        if (empty($invoice_number) || empty($seal_number) || empty($declared_value) || empty($client_id) || empty($route_id)) {
            echo json_encode(['success' => false, 'error' => 'Todos los campos son requeridos.']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO check_ins (invoice_number, seal_number, declared_value, fund_name, client_id, route_id, checkinero_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdsiii", $invoice_number, $seal_number, $declared_value, $fund_name, $client_id, $route_id, $checkinero_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al registrar el check-in: ' . $stmt->error]);
        }
        $stmt->close();
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
        break;
}

$conn->close();
?>
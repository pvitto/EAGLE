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
                ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, f.name as fund_name,
                ci.created_at, c.name as client_name, r.name as route_name, u.name as checkinero_name
            FROM check_ins ci
            JOIN clients c ON ci.client_id = c.id
            JOIN routes r ON ci.route_id = r.id
            JOIN users u ON ci.checkinero_id = u.id
            LEFT JOIN funds f ON ci.fund_id = f.id
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
        
        $invoice_number = $data['invoice_number'] ?? '';
        $seal_number = $data['seal_number'] ?? '';
        $declared_value = $data['declared_value'] ?? 0;
        $fund_id = $data['fund_id'] ?? null;
        $client_id = $data['client_id'] ?? null;
        $route_id = $data['route_id'] ?? null;
        $checkinero_id = $_SESSION['user_id'];

        if (empty($invoice_number) || empty($seal_number) || empty($declared_value) || empty($client_id) || empty($route_id) || empty($fund_id)) {
            echo json_encode(['success' => false, 'error' => 'Todos los campos son requeridos.']);
            exit;
        }

        // <<< NUEVA VALIDACIÓN DE DUPLICADOS >>>
        $stmt_check = $conn->prepare("SELECT id FROM check_ins WHERE invoice_number = ? OR seal_number = ?");
        $stmt_check->bind_param("ss", $invoice_number, $seal_number);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        if ($result_check->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'El número de Planilla o Sello ya existe. Verifique los datos.']);
            $stmt_check->close();
            exit;
        }
        $stmt_check->close();
        // <<< FIN DE LA VALIDACIÓN >>>

        $stmt = $conn->prepare("INSERT INTO check_ins (invoice_number, seal_number, declared_value, fund_id, client_id, route_id, checkinero_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdiisi", $invoice_number, $seal_number, $declared_value, $fund_id, $client_id, $route_id, $checkinero_id);
        
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
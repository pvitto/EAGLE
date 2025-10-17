<?php
// api/checkin_api.php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'Checkinero'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $check_in_id = $data['check_in_id'] ?? null; // ID para saber si estamos editando
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

    // --- LÓGICA DE VALIDACIÓN DE DUPLICADOS (MEJORADA) ---
    if ($check_in_id) { // Al actualizar, no te compares contigo mismo
        $stmt_check = $conn->prepare("SELECT id FROM check_ins WHERE (invoice_number = ? OR seal_number = ?) AND id != ?");
        $stmt_check->bind_param("ssi", $invoice_number, $seal_number, $check_in_id);
    } else { // Al crear, busca en todos
        $stmt_check = $conn->prepare("SELECT id FROM check_ins WHERE invoice_number = ? OR seal_number = ?");
        $stmt_check->bind_param("ss", $invoice_number, $seal_number);
    }
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'El número de Planilla o Sello ya existe. Verifique los datos.']);
        $stmt_check->close();
        exit;
    }
    $stmt_check->close();
    // --- FIN DE LA VALIDACIÓN ---

    if ($check_in_id) {
        // --- LÓGICA DE ACTUALIZACIÓN ---
        $stmt = $conn->prepare(
            "UPDATE check_ins SET invoice_number=?, seal_number=?, declared_value=?, fund_id=?, client_id=?, route_id=?, 
             status='Pendiente' 
             WHERE id=?"
        );
        // Reseteamos el estado a 'Pendiente' para que vuelva al flujo del Operador
        $stmt->bind_param("ssdiisi", $invoice_number, $seal_number, $declared_value, $fund_id, $client_id, $route_id, $check_in_id);
        $message = 'Planilla actualizada correctamente.';

    } else {
        // --- LÓGICA DE CREACIÓN (EXISTENTE) ---
        $stmt = $conn->prepare("INSERT INTO check_ins (invoice_number, seal_number, declared_value, fund_id, client_id, route_id, checkinero_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdiisi", $invoice_number, $seal_number, $declared_value, $fund_id, $client_id, $route_id, $checkinero_id);
        $message = 'Check-in registrado con éxito.';
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al procesar la solicitud: ' . $stmt->error]);
    }
    $stmt->close();

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
}

$conn->close();
?>
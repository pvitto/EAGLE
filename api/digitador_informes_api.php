<?php
session_start();
require '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit;
}

$action = $_GET['action'] ?? 'list_closed_funds';

if ($action === 'list_closed_funds') {
    // Lista los fondos que tienen al menos una planilla 'Cerrado'
    $query = "
        SELECT DISTINCT f.id, f.name as fund_name, c.name as client_name,
                       MAX(ci.closed_by_digitador_at) as last_close_date
        FROM check_ins ci
        JOIN funds f ON ci.fund_id = f.id
        JOIN clients c ON f.client_id = c.id
        WHERE ci.digitador_status = 'Cerrado'
        GROUP BY f.id, f.name, c.name
        ORDER BY last_close_date DESC
    ";
    $result = $conn->query($query);
    $funds = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $funds[] = $row;
        }
    }
    echo json_encode($funds);
    
} elseif ($action === 'get_report_details' && isset($_GET['fund_id'])) {
    $fund_id = intval($_GET['fund_id']);
    
    // Obtiene todas las planillas cerradas para ese fondo, asegurándose de tomar el último conteo
    $query = "
        SELECT 
            ci.invoice_number as planilla, 
            ci.seal_number as sello, 
            ci.declared_value,
            oc.total_counted as total, 
            oc.discrepancy,
            c.name as cliente,
            u_op.name as operador,
            u_dig.name as digitador
        FROM check_ins ci
        LEFT JOIN (
            SELECT a.*
            FROM operator_counts a
            INNER JOIN (
                SELECT check_in_id, MAX(id) as max_id
                FROM operator_counts
                GROUP BY check_in_id
            ) b ON a.id = b.max_id
        ) oc ON ci.id = oc.check_in_id
        LEFT JOIN clients c ON ci.client_id = c.id
        LEFT JOIN users u_op ON oc.operator_id = u_op.id
        LEFT JOIN users u_dig ON ci.closed_by_digitador_id = u_dig.id
        WHERE ci.fund_id = ? AND ci.digitador_status = 'Cerrado'
        ORDER BY ci.invoice_number ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $fund_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $planillas = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $planillas[] = $row;
        }
    }
    echo json_encode($planillas);
    $stmt->close();
}

$conn->close();
?>
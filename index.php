<?php
session_start();
require 'check_session.php';
require 'db_connection.php'; // This should define $conn and connection details

// Establecer la zona horaria correcta para Colombia
date_default_timezone_set('America/Bogota');

// --- Cargar datos iniciales ---
$all_users = [];
$users_result = $conn->query("SELECT id, name, role, email, gender FROM users ORDER BY name ASC");
if ($users_result) { while ($row = $users_result->fetch_assoc()) { $all_users[] = $row; } }
$admin_users_list = ($_SESSION['user_role'] === 'Admin') ? $all_users : [];

// --- Lógica de Usuario y Rol ---
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$current_user_gender = $_SESSION['user_gender'] ?? null;

function getRoleDisplayName($role, $gender) {
    if ($gender === 'F') {
        switch ($role) {
            case 'Digitador': return 'Digitadora';
            case 'Operador': return 'Operadora';
            case 'Checkinero': return 'Checkinera';
            default: return $role;
        }
    }
    return $role;
}
$displayRole = getRoleDisplayName($current_user_role, $current_user_gender);

// Clases CSS para roles
$role_color_class = 'bg-gray-200 text-gray-800'; $role_nav_class = 'nav-admin';
switch ($current_user_role) {
    case 'Admin': $role_color_class = 'bg-red-200 text-red-800'; $role_nav_class = 'nav-admin'; break;
    case 'Digitador': $role_color_class = 'bg-blue-200 text-blue-800'; $role_nav_class = 'nav-digitador'; break;
    case 'Operador': $role_color_class = 'bg-yellow-200 text-yellow-800'; $role_nav_class = 'nav-operador'; break;
    case 'Checkinero': $role_color_class = 'bg-green-200 text-green-800'; $role_nav_class = 'nav-checkinero'; break;
}

// --- LÓGICA DE ALERTAS Y TAREAS PENDIENTES ---
$all_pending_items = [];
$user_filter = '';
 if ($current_user_role !== 'Admin') {
    // Asegura que el usuario vea tareas asignadas a él o a su grupo si no están asignadas individualmente
    $user_filter = $conn->real_escape_string(" AND (t.assigned_to_user_id = {$current_user_id} OR (t.assigned_to_group = '{$current_user_role}' AND t.assigned_to_user_id IS NULL))");
 }

// Cargar Alertas Pendientes (agrupadas por alerta)
// (Esta es la *nueva* consulta de alertas, centrada en asignaciones)
$alerts_sql = "
SELECT a.*,
       MIN(t.id) AS task_id,
       MIN(t.status) AS task_status,
       t.assigned_to_group,
       MAX(CASE WHEN t.assigned_to_user_id = {$current_user_id} THEN t.id ELSE NULL END) as user_task_id,
       GROUP_CONCAT(DISTINCT CASE WHEN t.assigned_to_group IS NULL THEN u_assigned.name ELSE NULL END SEPARATOR ', ') as assigned_names,
       MIN(t.type) AS task_type,
       MIN(t.instruction) as instruction,
       MIN(t.start_datetime) as start_datetime,
       MIN(t.end_datetime) as end_datetime,
       ci.invoice_number
FROM alerts a
LEFT JOIN tasks t ON t.alert_id = a.id AND t.status = 'Pendiente'
LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
LEFT JOIN check_ins ci ON a.check_in_id = ci.id
WHERE a.status = 'Asignada'
  {$user_filter}
GROUP BY a.id
ORDER BY FIELD(a.priority, 'Critica', 'Alta', 'Media', 'Baja'), a.created_at DESC
";
$alerts_result = $conn->query($alerts_sql);
if ($alerts_result) {
    while ($row = $alerts_result->fetch_assoc()) {
        $row['item_type'] = 'alert';
        $all_pending_items[] = $row;
    }
} else { error_log("Error loading alerts: " . $conn->error); }

// 2. Cargar Tareas Manuales Pendientes
// *** CAMBIO: GROUP BY RESTAURADO ***
$manual_tasks_sql = "
    SELECT
        t.id, MIN(t.id) as task_id, t.title, t.instruction, t.priority, MIN(t.status) as task_status,
        t.assigned_to_user_id, t.assigned_to_group,
        -- Obtener nombres solo si NO es de grupo
        GROUP_CONCAT(DISTINCT CASE WHEN t.assigned_to_group IS NULL THEN u.name ELSE NULL END SEPARATOR ', ') as assigned_names,
        t.start_datetime, t.end_datetime,
        -- Marcar si alguna de las tareas agrupadas pertenece a este usuario
        MAX(CASE WHEN t.assigned_to_user_id = {$current_user_id} THEN t.id ELSE NULL END) as user_task_id
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to_user_id = u.id
    WHERE t.alert_id IS NULL AND t.type = 'Manual' AND t.status = 'Pendiente'
      {$user_filter}
    -- *** GROUP BY RESTAURADO ***
    GROUP BY IF(t.assigned_to_group IS NOT NULL, CONCAT(t.title, t.assigned_to_group, t.created_at), t.id)
    ORDER BY FIELD(t.priority, 'Critica', 'Alta', 'Media', 'Baja'), t.created_at DESC
";
$manual_tasks_result = $conn->query($manual_tasks_sql);
if ($manual_tasks_result) {
    while($row = $manual_tasks_result->fetch_assoc()) {
        $row['item_type'] = 'manual_task';
        $all_pending_items[] = $row;
    }
} else { error_log("Error loading manual tasks: " . $conn->error); }

// 3. Procesar y ordenar items
$main_priority_items = []; $main_non_priority_items = [];
$panel_high_priority_items = []; $panel_medium_priority_items = [];
$now = new DateTime();
foreach ($all_pending_items as $item) {
    $original_priority = $item['priority'] ?? 'Media';
    $current_priority = $original_priority;
    if (!empty($item['end_datetime'])) {
        try {
           $end_time = new DateTime($item['end_datetime']);
           $diff_minutes = ($now->getTimestamp() - $end_time->getTimestamp()) / 60;
            if ($diff_minutes >= 0) { $current_priority = 'Alta'; }
            elseif ($diff_minutes > -15 && ($original_priority === 'Baja' || $original_priority === 'Media')) { $current_priority = 'Media'; }
        } catch (Exception $e) { /* Log error */ }
    }
    $item['current_priority'] = $current_priority;
    // Si es tarea de grupo y el usuario pertenece, marcarla como accionable por él
    if (empty($item['user_task_id']) && !empty($item['assigned_to_group']) && $item['assigned_to_group'] == $current_user_role) {
         $item['user_task_id'] = $item['task_id'] ?? $item['id'];
    }

    if ($current_priority === 'Critica' || $current_priority === 'Alta') {
        $main_priority_items[] = $item; $panel_high_priority_items[] = $item;
    } else {
        $main_non_priority_items[] = $item;
        if ($current_priority === 'Media') $panel_medium_priority_items[] = $item;
    }
}
$priority_order = ['Critica' => 4, 'Alta' => 3, 'Media' => 2, 'Baja' => 1];
$sortFunction = function($a, $b) use ($priority_order) {
    $priorityA = $priority_order[$a['current_priority']] ?? 0;
    $priorityB = $priority_order[$b['current_priority']] ?? 0;
    if ($priorityB !== $priorityA) return $priorityB <=> $priorityA;
    $dateA = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
    $dateB = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
    return $dateB <=> $dateA;
};
usort($main_priority_items, $sortFunction);
usort($main_non_priority_items, $sortFunction);
usort($panel_high_priority_items, $sortFunction);
usort($panel_medium_priority_items, $sortFunction);
$total_alerts_count_for_user = count($all_pending_items);
$priority_summary_count = count($main_priority_items);
$high_priority_badge_count = count($panel_high_priority_items);
$medium_priority_badge_count = count($panel_medium_priority_items);

// --- OTRAS CONSULTAS DE DATOS ---
$completed_tasks = []; // Admin's full history
if ($_SESSION['user_role'] === 'Admin') {
    $completed_result = $conn->query(
        "SELECT t.id, COALESCE(a.title, t.title) as title, t.instruction, t.priority, t.start_datetime, t.end_datetime, u_assigned.name as assigned_to, u_completed.name as completed_by, t.created_at, t.completed_at, TIMEDIFF(t.completed_at, t.created_at) as response_time, t.assigned_to_group, u_creator.name as created_by_name, t.resolution_note FROM tasks t LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id LEFT JOIN users u_completed ON t.completed_by_user_id = u_completed.id LEFT JOIN users u_creator ON t.created_by_user_id = u_creator.id LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.status = 'Completada' ORDER BY t.completed_at DESC" );
    if ($completed_result) {
        while($row = $completed_result->fetch_assoc()){
            $original_priority = $row['priority']; $final_priority = $original_priority;
             if (!empty($row['end_datetime']) && !empty($row['completed_at'])) {
                  try { $end_time = new DateTime($row['end_datetime']); $completed_time = new DateTime($row['completed_at']); if ($completed_time > $end_time) { $final_priority = 'Alta'; } } catch (Exception $e) {}
             }
            $row['final_priority'] = $final_priority;
            $completed_tasks[] = $row;
        }
    } else { error_log("Error Admin completed tasks: " . $conn->error); }
}

// Historial individual
$user_completed_tasks = [];
$stmt_user_tasks = $conn->prepare( "SELECT t.id, COALESCE(a.title, t.title) as title, t.instruction, t.priority, t.start_datetime, t.end_datetime, u_assigned.name as assigned_to, u_completed.name as completed_by, t.created_at, t.completed_at, TIMEDIFF(t.completed_at, t.created_at) as response_time, t.assigned_to_group, u_creator.name as created_by_name, t.resolution_note FROM tasks t LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id LEFT JOIN users u_completed ON t.completed_by_user_id = u_completed.id LEFT JOIN users u_creator ON t.created_by_user_id = u_creator.id LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.status = 'Completada' AND (t.completed_by_user_id = ?) ORDER BY t.completed_at DESC" );
$stmt_user_tasks->bind_param("i", $current_user_id);
$stmt_user_tasks->execute();
$user_tasks_result = $stmt_user_tasks->get_result();
if ($user_tasks_result) {
    while($row = $user_tasks_result->fetch_assoc()){
        $original_priority = $row['priority']; $final_priority = $original_priority;
         if (!empty($row['end_datetime']) && !empty($row['completed_at'])) {
             try { $end_time = new DateTime($row['end_datetime']); $completed_time = new DateTime($row['completed_at']); if ($completed_time > $end_time) { $final_priority = 'Alta'; } } catch (Exception $e) {}
        }
        $row['final_priority'] = $final_priority;
        $user_completed_tasks[] = $row;
    }
} else { error_log("Error User completed tasks: " . $conn->error); }
$stmt_user_tasks->close();

// Recordatorios
$user_reminders = [];
$reminders_result = $conn->query("SELECT id, message, created_at FROM reminders WHERE user_id = $current_user_id AND is_read = 0 ORDER BY created_at DESC");
if($reminders_result) { while($row = $reminders_result->fetch_assoc()){ $user_reminders[] = $row; } }

// Recaudos del Día
$today_collections = []; $total_recaudado_hoy = 0;
$today_collections_result = $conn->query(" SELECT oc.id, oc.total_counted, c.name as client_name, u_op.name as operator_name, oc.bills_100k, oc.bills_50k, oc.bills_20k, oc.bills_10k, oc.bills_5k, oc.bills_2k, oc.coins, oc.created_at, ci.invoice_number, f.name as fund_name, u_dig.name as digitador_name, CASE WHEN ci.digitador_status = 'Cerrado' THEN 'Cerrado' WHEN ci.digitador_status = 'Conforme' THEN 'Conforme' WHEN ci.status = 'Rechazado' THEN 'Rechazado' WHEN ci.status = 'Discrepancia' THEN 'En Revisión (Digitador)' WHEN ci.status = 'Procesado' THEN 'En Revisión (Digitador)' WHEN ci.status = 'Pendiente' THEN 'Pendiente (Operador)' ELSE ci.status END AS final_status FROM operator_counts oc INNER JOIN ( SELECT check_in_id, MAX(id) as max_oc_id FROM operator_counts WHERE DATE(created_at) = CURDATE() GROUP BY check_in_id ) latest_oc ON oc.id = latest_oc.max_oc_id JOIN check_ins ci ON oc.check_in_id = ci.id JOIN clients c ON ci.client_id = c.id JOIN users u_op ON oc.operator_id = u_op.id LEFT JOIN funds f ON ci.fund_id = f.id LEFT JOIN users u_dig ON ci.closed_by_digitador_id = u_dig.id ORDER BY oc.created_at DESC ");
if ($today_collections_result) { while ($row = $today_collections_result->fetch_assoc()) { $today_collections[] = $row; $total_recaudado_hoy += $row['total_counted']; } }
else { error_log("Error Today collections: " . $conn->error); }

// Cierres Pendientes
$cierres_pendientes_result = $conn->query("SELECT COUNT(DISTINCT ci.id) as total FROM check_ins ci WHERE ci.status IN ('Procesado', 'Discrepancia') AND ci.digitador_status IS NULL");
$cierres_pendientes_count = $cierres_pendientes_result->fetch_assoc()['total'] ?? 0;

// Clientes y Rutas
$all_clients = [];
$clients_result = $conn->query("SELECT id, name, nit FROM clients ORDER BY name ASC");
if ($clients_result) { while ($row = $clients_result->fetch_assoc()) { $all_clients[] = $row; } }
$all_routes = [];
$routes_result = $conn->query("SELECT id, name FROM routes ORDER BY name ASC");
if ($routes_result) { while ($row = $routes_result->fetch_assoc()) { $all_routes[] = $row; } }

// Check-ins (Lógica Restaurada)
$initial_checkins = [];
$checkins_result = $conn->query("
    SELECT ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, f.id as fund_id, f.name as fund_name,
           ci.created_at, c.name as client_name, c.id as client_id, r.name as route_name, r.id as route_id, u.name as checkinero_name,
           ci.status, ci.correction_count, ci.digitador_status
    FROM check_ins ci
    JOIN clients c ON ci.client_id = c.id
    JOIN routes r ON ci.route_id = r.id
    JOIN users u ON ci.checkinero_id = u.id
    LEFT JOIN funds f ON ci.fund_id = f.id
    WHERE ci.status IN ('Pendiente', 'Rechazado') -- <-- LÓGICA RESTAURADA
    ORDER BY ci.correction_count DESC, ci.created_at DESC
");
if ($checkins_result) { while ($row = $checkins_result->fetch_assoc()) { $initial_checkins[] = $row; } }
else { error_log("Error initial checkins: " . $conn->error); }

// Historial Operador
$operator_history = [];
if (in_array($_SESSION['user_role'], ['Operador', 'Admin', 'Digitador'])) {
    $operator_id_filter = ($_SESSION['user_role'] === 'Operador') ? "WHERE op.operator_id = " . intval($_SESSION['user_id']) : "";
    $history_query = " SELECT op.id, op.check_in_id, op.total_counted, op.discrepancy, op.observations, op.created_at as count_date, ci.invoice_number, ci.declared_value, c.name as client_name, u.name as operator_name FROM operator_counts op INNER JOIN ( SELECT check_in_id, MAX(id) as max_id FROM operator_counts GROUP BY check_in_id ) as latest_oc ON op.id = latest_oc.max_id JOIN check_ins ci ON op.check_in_id = ci.id JOIN clients c ON ci.client_id = c.id JOIN users u ON op.operator_id = u.id {$operator_id_filter} ORDER BY op.created_at DESC ";
    $history_result = $conn->query($history_query);
    if ($history_result) { while($row = $history_result->fetch_assoc()) { $operator_history[] = $row; } }
    else { error_log("Error operator history: " . $conn->error); }
}

// Historial Digitador
$digitador_closed_history = [];
if (in_array($current_user_role, ['Digitador', 'Admin'])) {
    $digitador_filter = "WHERE ci.digitador_status IN ('Conforme', 'Cerrado', 'Rechazado')";
    // Si quieres la lógica antigua (solo ver lo propio), descomenta la siguiente línea:
    // if ($current_user_role === 'Digitador') $digitador_filter .= " AND ci.closed_by_digitador_id = " . $current_user_id;

    $closed_history_result = $conn->query(" SELECT ci.id, ci.invoice_number, c.name as client_name, u_check.name as checkinero_name, oc.total_counted, oc.discrepancy, oc.bills_100k, oc.bills_50k, oc.bills_20k, oc.bills_10k, oc.bills_5k, oc.bills_2k, oc.coins, u_op.name as operator_name, ci.closed_by_digitador_at, u_digitador.name as digitador_name, ci.digitador_status, ci.digitador_observations, f.name as fund_name FROM check_ins ci LEFT JOIN clients c ON ci.client_id = c.id LEFT JOIN users u_check ON ci.checkinero_id = u_check.id LEFT JOIN ( SELECT a.* FROM operator_counts a INNER JOIN ( SELECT check_in_id, MAX(id) as max_id FROM operator_counts GROUP BY check_in_id ) b ON a.id = b.max_id ) oc ON ci.id = oc.check_in_id LEFT JOIN users u_op ON oc.operator_id = u_op.id LEFT JOIN users u_digitador ON ci.closed_by_digitador_id = u_digitador.id LEFT JOIN funds f ON ci.fund_id = f.id {$digitador_filter} ORDER BY ci.closed_by_digitador_at DESC, ci.id DESC" );
    if($closed_history_result){ while($row = $closed_history_result->fetch_assoc()){ $digitador_closed_history[] = $row; } }
    else { error_log("Error digitador history: " . $conn->error); }
}


// 1) Clases de la pastilla por rol (lo que pones en $role_color_class)


// 2) Clases del marco/lineas por rol (deben coincidir en color)
$ROLE_PILL = [
  'Admin'       => 'bg-rose-100 text-rose-700',
  'Digitador'   => 'bg-blue-100 text-blue-700',
  'Digitadora'  => 'bg-blue-100 text-blue-700',
  'Supervisor'  => 'bg-amber-100 text-amber-800',
  'Supervisora' => 'bg-amber-100 text-amber-800',
  'Operador'    => 'bg-yellow-100 text-yellow-800',
  'Operadora'   => 'bg-yellow-100 text-yellow-800',
  'Check-in'    => 'bg-green-100 text-green-700',
  'Checkin'     => 'bg-green-100 text-green-700',
];

$ROLE_FRAME = [
  'Admin'       => 'border-rose-400 ring-rose-200 bg-rose-50',
  'Digitador'   => 'border-blue-400 ring-blue-200 bg-blue-50',
  'Digitadora'  => 'border-blue-400 ring-blue-200 bg-blue-50',
  'Supervisor'  => 'border-amber-400 ring-amber-200 bg-amber-50',
  'Supervisora' => 'border-amber-400 ring-amber-200 bg-amber-50',
  'Operador'    => 'border-yellow-400 ring-yellow-200 bg-yellow-50',
  'Operadora'   => 'border-yellow-400 ring-yellow-200 bg-yellow-50',
  'Check-in'    => 'border-green-400 ring-green-200 bg-green-50',
  'Checkin'     => 'border-green-400 ring-green-200 bg-green-50',
];


$role = $displayRole ?? 'Usuario';
$role_color_class = $ROLE_PILL[$role] ?? 'bg-gray-100 text-gray-700';
$frame_class      = $ROLE_FRAME[$role] ?? 'border-gray-300 ring-gray-200 bg-white';


$conn->close(); // Cerrar DB connection
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EAGLE 3.0 - Sistema de Alertas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/xlsx/dist/xlsx.full.min.js"></script>
    <script src="js/jspdf.umd.min.js"></script>
    <script src="js/jspdf-autotable.min.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
html {
  font-size: 12px; /* Prueba con 10px, ajusta si es necesario (original es 16px) */
}

body {
  font-family: 'Inter', sans-serif;
  /* El tamaño de fuente del body ahora heredará o puedes ajustarlo si quieres */
  /* font-size: 1rem; /* Esto sería 10px ahora */
}

       /* body { font-family: 'Inter', sans-serif; }*/

      .nav-tab { cursor: pointer; padding: 0.25rem 1rem; /* Reducido aún más el padding vertical */ font-weight: 600; border-bottom: 3px solid transparent; transition: all 0.2s; white-space: nowrap; }
        .nav-admin:hover { color: #dc2626; }
        .nav-admin.active { color: #dc2626; border-bottom-color: #dc2626; background-color: #fee2e2; }

        .nav-digitador { color: #1e40af; } /* text-blue-800 */
        .nav-digitador:hover { color: #2563eb; }
        .nav-digitador.active { color: #2563eb; border-bottom-color: #2563eb; background-color: #dbeafe; }

        .nav-operador { color: #a16207; } /* text-yellow-800 */
        .nav-operador:hover { color: #ca8a04; }
        .nav-operador.active { color: #ca8a04; border-bottom-color: #ca8a04; background-color: #fefce8; }

        .nav-checkinero { color: #15803d; } /* text-green-800 */
        .nav-checkinero:hover { color: #16a34a; }
        .nav-checkinero.active { color: #16a34a; border-bottom-color: #16a34a; background-color: #f0fdf4; }

        #user-modal-overlay, #reminders-panel, #task-notifications-panel, #medium-priority-panel { transition: opacity 0.3s ease; }
        .task-form, .cash-breakdown { transition: all 0.4s ease-in-out; max-height: 0; overflow: hidden; padding-top: 0; padding-bottom: 0; opacity: 0;}
        .task-form.active, .cash-breakdown.active { max-height: 800px; padding-top: 1rem; padding-bottom: 1rem; opacity: 1;}
        .details-row { border-top: 1px solid #e5e7eb; }
        .sortable { transition: background-color 0.2s; }
        .sortable:hover { background-color: #f3f4f6; }

        /* --- ESTILOS MEJORADOS PARA EL DROPDOWN DE NAVEGACIÓN --- */
        .nav-dropdown {
            position: relative; /* Contenedor relativo para el contenido absoluto */
            display: inline-block; /* Para que se alinee con los otros botones */
        }

        .nav-dropdown-content {
            display: none; /* Oculto por defecto */
            position: absolute; /* Posicionado relativo al .nav-dropdown */
            background-color: white; /* Fondo blanco como en el ejemplo */
            min-width: 200px; /* Ancho mínimo, ajusta según necesidad */
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2); /* Sombra como en el ejemplo */
            z-index: 50; /* Asegura que esté por encima de otros elementos */
            border-radius: 0.375rem; /* Esquinas redondeadas (md en Tailwind) */
            overflow: hidden; /* Para que el contenido respete el borde redondeado */
            top: 100%; /* Posiciona justo debajo del botón */
            left: 0; /* Alinea a la izquierda del botón */
            margin-top: 0.25rem; /* Pequeño espacio entre el botón y el dropdown (mt-1) */
        }

        /* Estilos para los enlaces dentro del dropdown */
        .nav-dropdown-content a {
            color: #374151; /* Color de texto gris oscuro (text-gray-700) */
            padding: 0.75rem 1rem; /* Padding (py-3 px-4) */
            text-decoration: none;
            display: block;
            font-weight: 500; /* semi-bold */
            font-size: 0.875rem; /* text-sm */
            white-space: nowrap; /* Evita que el texto se divida en líneas */
            transition: background-color 0.15s ease-in-out; /* Transición suave */
            cursor: pointer;
            /* Aplicar los mismos estilos base que nav-tab para consistencia */
            border-bottom: 3px solid transparent;
        }

        /* Efecto hover para los enlaces */
        .nav-dropdown-content a:hover {
            background-color: #f3f4f6; /* Fondo gris claro al pasar el mouse (bg-gray-100) */
        }

        /* Muestra el dropdown al hacer hover sobre el contenedor .nav-dropdown */
        .nav-dropdown:hover .nav-dropdown-content {
            display: block;
        }

        /* Opcional: Estilos específicos de rol al pasar el mouse sobre los enlaces */
        .nav-dropdown-content a.nav-admin:hover { background-color: #fee2e2; }
        .nav-dropdown-content a.nav-digitador:hover { background-color: #dbeafe; }
        .nav-dropdown-content a.nav-operador:hover { background-color: #fefce8; }
        .nav-dropdown-content a.nav-checkinero:hover { background-color: #f0fdf4; }

        /* Ajuste para el botón principal del dropdown para que no cambie de fondo al hacer hover */
        .nav-dropdown > button.nav-tab:hover {
             /* Puedes resetear el color de fondo o mantener el que tiene por defecto */
             /* background-color: transparent; /* O el color base si no es transparente */
             /* Mantenemos el cambio de color de texto y borde si es necesario */
        }
        /* --- FIN DE ESTILOS MEJORADOS --- */

        /* === INICIO DE NUEVOS ESTILOS FUSIONADOS === */
        .sortable { cursor: pointer; } .sortable span { color: #9ca3af; }
        /* Header Panel Button Styles */
        /* Header Panel Button Styles - Modificado para apariencia de caja */
.header-panel-button {
  display: inline-block;
  padding: 0.3rem 0.8rem;
  margin-left: 0.5rem; /* Mantiene el espacio a la izquierda */
  font-size: 0.8rem;
  font-weight: 500;
  border-radius: 0.375rem; /* Esquinas redondeadas */
  border: 1px solid; /* Borde sólido por defecto, el color vendrá de la clase de rol */
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* Sombra sutil para efecto caja */
  line-height: 1.5; /* Ajuste vertical del texto si es necesario */
}
.header-panel-button:hover {
  opacity: 0.9; /* Hover más sutil */
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); /* Sombra ligeramente mayor en hover */
}
.header-panel-button.active {
  box-shadow: inset 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* Sombra interior cuando está activo */
  /* Puedes agregar otros estilos para el estado activo si lo deseas, como un borde más grueso */
  /* border-width: 2px; */
}

/* Colores específicos de rol (borde más contrastante) */
.header-panel-button.nav-checkinero { background-color: #dcfce7; border-color: #4ade80; color: #166534; } /* Borde verde más visible */
.header-panel-button.nav-checkinero:hover { background-color: #bbf7d0; }
.header-panel-button.nav-checkinero.active { background-color: #a7f3d0; border-color: #22c55e; } /* Borde más oscuro activo */

.header-panel-button.nav-operador { background-color: #fef9c3; border-color: #facc15; color: #854d0e; } /* Borde amarillo más visible */
.header-panel-button.nav-operador:hover { background-color: #fde68a; }
.header-panel-button.nav-operador.active { background-color: #fde047; border-color: #eab308; } /* Borde más oscuro activo */

.header-panel-button.nav-digitador { background-color: #dbeafe; border-color: #60a5fa; color: #1e40af; } /* Borde azul más visible */
.header-panel-button.nav-digitador:hover { background-color: #bfdbfe; }
.header-panel-button.nav-digitador.active { background-color: #93c5fd; border-color: #3b82f6; } /* Borde más oscuro activo */

/* --- Fin de estilos de Header Panel Button --- */
/* Alert Pop-up Styles */
        #alert-popup-overlay { transition: opacity 0.3s ease; }
        #alert-popup { transition: transform 0.3s ease, opacity 0.3s ease; }
        /* Spinner for AJAX loading */
        .loader { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        /* === FIN DE NUEVOS ESTILOS FUSIONADOS === */

        /* Estilos de Notificación del Archivo 2 (para compatibilidad con JS) */
        .notification-panel { position: absolute; right: 0; margin-top: 0.5rem; width: 20rem; background-color: white; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); padding: 1rem; z-index: 50; }
        .notification-list { max-height: 16rem; overflow-y: auto; }
        .badge-count { display: flex; align-items: center; justify-content: center; height: 1.25rem; width: 1.25rem; border-radius: 9999px; color: white; font-size: 0.75rem; font-weight: 700; position: absolute; top: -0.5rem; right: -0.5rem; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div id="user-modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
        <div id="user-modal" class="bg-white rounded-xl shadow-2xl w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center pb-3 border-b"><h3 id="modal-title" class="text-xl font-bold text-gray-900"></h3><button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-3xl leading-none">&times;</button></div>
                <form id="user-form" class="mt-6 space-y-4">
                    <input type="hidden" id="user-id" name="id">
                    <div><label for="user-name" class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label><input type="text" id="user-name" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-md" required></div>
                    <div><label for="user-email" class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" id="user-email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md" required></div>
                    <div><label for="user-role" class="block text-sm font-medium text-gray-700 mb-1">Rol</label><select id="user-role" name="role" class="w-full px-3 py-2 border border-gray-300 rounded-md" required><option value="Operador">Operador</option><option value="Checkinero">Checkinero</option><option value="Digitador">Digitador</option><option value="Admin">Admin</option></select></div>
                    <div>
                        <label for="user-gender" class="block text-sm font-medium text-gray-700 mb-1">Sexo</label>
                        <select id="user-gender" name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
                            <option value="">Seleccionar...</option>
                            <option value="M">Masculino</option>
                            <option value="F">Femenino</option>
                        </select>
                    </div>
                    <div><label for="user-password" class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label><input type="password" id="user-password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md"><p id="password-hint" class="text-xs text-gray-500 mt-1"></p></div>
                    <div class="pt-4 flex justify-end space-x-3"><button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancelar</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700">Guardar</button></div>
                </form>
            </div>
        </div>
    </div>

    <div id="alert-popup-overlay" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 hidden z-[100]">
        <div id="alert-popup" class="bg-white rounded-lg shadow-xl w-full max-w-lg transform scale-95 opacity-0">
             <div id="alert-popup-header" class="p-4 border-b rounded-t-lg bg-red-100 border-red-200">
                <div class="flex justify-between items-center">
                    <h3 id="alert-popup-title" class="text-xl font-bold text-red-800">¡Nueva Alerta Prioritaria!</h3>
                    <button onclick="closeAlertPopup()" class="text-red-400 hover:text-red-600 text-3xl leading-none">&times;</button>
                </div>
             </div>
             <div class="p-6 space-y-3">
                <p id="alert-popup-description" class="text-gray-700"></p>
                <p class="text-sm text-gray-500">Por favor, revisa el panel de alertas para más detalles y acciones.</p>
             </div>
             <div class="p-4 bg-gray-50 border-t rounded-b-lg flex justify-end">
                <button onclick="closeAlertPopup()" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700">Entendido</button>
             </div>
        </div>
    </div>
    <div id="app" class="p-4 sm:p-6 lg:p-8 max-w-full mx-auto">
        <header class="flex flex-col sm:flex-row justify-between sm:items-start mb-2 border-b pb-1">
    <div>
        <h1 class="text-2xl md:text-3xl font-bold text-gray-900">EAGLE 3.0</h1>
        <p class="text-sm text-gray-500 mb-2">Sistema Integrado de Operaciones y Alertas</p>


        <div class="inline-flex items-center gap-2 mt-2 px-4 py-2 rounded-xl border ring-1 shadow-sm <?= $frame_class ?>">
  <span class="font-semibold text-gray-700">Hola</span>

  <span class="font-bold px-3 py-1 rounded-full <?= $role_color_class ?>">
    <?= htmlspecialchars($displayRole) ?>
  </span>

  <span class="font-bold text-gray-900">
    : <?= htmlspecialchars($_SESSION['user_name']) ?>
  </span>
</div>



    </div>

    <div class="mt-4 sm:mt-0 flex flex-col sm:items-end space-y-2">
        <div class="flex items-center space-x-4">
            <a href="logout.php" class="text-blue-600 hover:underline">Cerrar Sesión</a>
            <div class="relative">
                <button id="task-notification-button" onclick="togglePanel('task-notifications-panel')" class="relative text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6H5a2 2 0 00-2 2zm0 0h7"></path></svg>
                    <?php if ($high_priority_badge_count > 0): ?>
                    <span id="task-notification-badge" class="absolute -top-2 -right-2 badge-count bg-red-500"><?php echo $high_priority_badge_count; ?></span>
                    <?php endif; ?>
                </button>
                <div id="task-notifications-panel" class="notification-panel hidden">
                    <h4 class="font-bold text-gray-800 mb-2">Alertas de Tareas Prioritarias</h4>
                     <div id="task-notifications-list" class="space-y-2 max-h-64 overflow-y-auto notification-list">
                        <?php if(empty($panel_high_priority_items)): ?>
                             <p class="text-sm text-gray-500">No hay alertas prioritarias.</p>
                        <?php else: foreach($panel_high_priority_items as $item): $color_class = $item['current_priority'] === 'Critica' ? 'red' : 'orange'; ?>
                         <div class="p-2 bg-<?php echo $color_class; ?>-50 rounded-md border border-<?php echo $color_class; ?>-200 text-sm">
                             <p class="font-semibold text-<?php echo $color_class; ?>-800"><?php echo htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?><?php if (!empty($item['invoice_number'])): ?> <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span><?php endif; ?></p>
                             <p class="text-gray-700 text-xs mt-1"><?php echo htmlspecialchars($item['item_type'] === 'manual_task' ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                             <?php if ($_SESSION['user_role'] === 'Admin'): if (!empty($item['assigned_to_group'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-<?php echo $color_class; ?>-200">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></p><?php elseif (!empty($item['assigned_names'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-<?php echo $color_class; ?>-200">Asignada a: <?php echo htmlspecialchars($item['assigned_names']); ?></p><?php endif; endif; ?>
                             <?php if (!empty($item['end_datetime'])): ?><div class="countdown-timer text-xs font-bold mt-1" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div><?php endif; ?>
                         </div>
                        <?php endforeach; endif; ?>
                     </div>
                </div>
            </div>
            <div class="relative">
                 <button id="medium-priority-button" onclick="togglePanel('medium-priority-panel')" class="relative text-gray-500 hover:text-gray-700">
                     <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                     <?php if ($medium_priority_badge_count > 0): ?>
                     <span id="medium-priority-badge" class="absolute -top-2 -right-2 badge-count bg-yellow-500"><?php echo $medium_priority_badge_count; ?></span>
                     <?php endif; ?>
                 </button>
                 <div id="medium-priority-panel" class="notification-panel hidden">
                    <h4 class="font-bold text-gray-800 mb-2">Alertas de Prioridad Media</h4>
                     <div id="medium-priority-list" class="space-y-2 max-h-64 overflow-y-auto notification-list">
                        <?php if(empty($panel_medium_priority_items)): ?>
                             <p class="text-sm text-gray-500">No hay alertas de prioridad media.</p>
                        <?php else: foreach($panel_medium_priority_items as $item): ?>
                             <div class="p-2 bg-yellow-50 rounded-md border border-yellow-200 text-sm">
                                 <p class="font-semibold text-yellow-800"><?php echo htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?><?php if (!empty($item['invoice_number'])): ?> <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span><?php endif; ?></p>
                                 <p class="text-gray-700 text-xs mt-1"><?php echo htmlspecialchars($item['item_type'] === 'manual_task' ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                 <?php if ($_SESSION['user_role'] === 'Admin'): if (!empty($item['assigned_to_group'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-yellow-200">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></p><?php elseif (!empty($item['assigned_names'])): ?><p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-yellow-200">Asignada a: <?php echo htmlspecialchars($item['assigned_names']); ?></p><?php endif; endif; ?>
                                 <?php if (!empty($item['end_datetime'])): ?><div class="countdown-timer text-xs font-bold mt-1" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div><?php endif; ?>
                             </div>
                        <?php endforeach; endif; ?>
                     </div>
                 </div>
            </div>
            <div class="relative">
                 <button id="reminders-button" onclick="togglePanel('reminders-panel')" class="relative text-gray-500 hover:text-gray-700">
                     <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                     <span id="reminders-badge" class="absolute -top-2 -right-2 badge-count bg-blue-500 hidden"></span>
                 </button>
                 <div id="reminders-panel" class="notification-panel hidden">
                    <h4 class="font-bold text-gray-800 mb-2">Tus Recordatorios</h4>
                     <div id="reminders-list" class="space-y-2 max-h-64 overflow-y-auto notification-list">
                        <?php if(empty($user_reminders)): ?>
                             <p class="text-sm text-gray-500">No tienes recordatorios pendientes.</p>
                        <?php else: foreach($user_reminders as $reminder): ?>
                             <div class="reminder-item p-2 bg-blue-50 rounded-md border border-blue-200 text-sm">
                                 <div class="flex justify-between items-start">
                                     <div><p class="text-gray-700"><?php echo htmlspecialchars($reminder['message']); ?></p><p class="text-xs text-gray-400 mt-1"><?php echo date('d M, h:i a', strtotime($reminder['created_at'])); ?></p></div>
                                     <button onclick="deleteReminder(<?php echo $reminder['id']; ?>, this)" class="text-red-400 hover:text-red-600 font-bold text-lg">&times;</button>
                                 </div>
                             </div>
                        <?php endforeach; endif; ?>
                     </div>
                 </div>
            </div>
        </div>
    <div class="mt-2 flex flex-wrap justify-start sm:justify-end space-x-2 sm:space-x-4">
                    <?php if (in_array($_SESSION['user_role'], ['Checkinero', 'Admin'])): ?>
                        <button id="tab-checkinero" class="header-panel-button nav-checkinero shadow-sm" onclick="switchTab('checkinero')">Panel Check-in</button>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['user_role'], ['Operador', 'Admin'])): ?>
                        <button id="tab-operador" class="header-panel-button nav-operador shadow-sm" onclick="switchTab('operador')">Panel Operador</button>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['user_role'], ['Digitador', 'Admin'])): ?>
                        <button id="tab-digitador" class="header-panel-button nav-digitador shadow-sm" onclick="switchTab('digitador')">Panel Digitador</button>
                    <?php endif; ?>
                </div>
    </div>
</header>
        <nav class="mb-4">
            <div class="border-b border-gray-200">
                <div class="-mb-px flex space-x-4 overflow-x-auto">
                    <button id="tab-operaciones" class="nav-tab active <?php echo $role_nav_class; ?>" onclick="switchTab('operaciones')">Panel General</button>
                    <button id="tab-mi-historial" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('mi-historial')">Mi Historial de Tareas</button>
                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                        <button id="tab-roles" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('roles')">Gestión de Roles</button>
                        <button id="tab-manage-clients" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('manage-clients')">Gestionar Clientes</button>
                        <button id="tab-manage-routes" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('manage-routes')">Gestionar Rutas</button>
                        <button id="tab-manage-funds" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('manage-funds')">Gestionar Fondos</button>
                        <button id="tab-trazabilidad" class="nav-tab <?php echo $role_nav_class; ?>" onclick="switchTab('trazabilidad')">Trazabilidad</button>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <main>
            <div id="content-operaciones">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Recaudos de Hoy</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">$</div></div><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo '$' . number_format($total_recaudado_hoy, 0, ',', '.'); ?></p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Cierres Pendientes</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">🕔</div></div><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo $cierres_pendientes_count; ?></p><p class="text-sm text-gray-500 mt-2">Para revisión de Digitador</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Alertas Activas</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">❗</div></div><p class="text-3xl font-bold text-gray-900 mt-2"><?php echo $total_alerts_count_for_user; ?></p><p class="text-sm text-gray-500 mt-2"><?php echo $priority_summary_count; ?> Prioritarias</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Tasa de Cumplimiento</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">📈</div></div><p class="text-3xl font-bold text-gray-900 mt-2">94%</p><p class="text-sm text-green-600 mt-2">▲ 3% vs semana pasada</p></div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <div class="lg:col-span-2 space-y-4">
                        <h2 class="text-xl font-bold text-gray-900">Alertas y Tareas Prioritarias</h2>
                        <?php if (empty($main_priority_items)): ?>
                            <p class="text-sm text-gray-500 bg-white p-4 rounded-lg shadow-sm">No hay items prioritarios pendientes.</p>
                        <?php else: foreach ($main_priority_items as $item): ?>
                            <?php
                                $is_manual = $item['item_type'] === 'manual_task';
                                $is_group_task = !empty($item['assigned_to_group']);
                                $assigned_names = $item['assigned_names'] ?? null; // Usar null para chequear con empty()

                                // task_id es el ID de la *tarea* (o el MIN(t.id) para alertas).
                                // user_task_id solo existe si esta tarea específica está asignada a ESTE usuario
                                $task_id_to_use = $item['task_id'] ?? $item['id'];
                                $alert_id_or_null = $is_manual ? 'null' : ($item['id'] ?? 'null');
                                $form_id_prefix = $task_id_to_use; // ID único para el formulario

                                $can_complete = isset($item['task_status']) && $item['task_status'] === 'Pendiente' && !empty($item['user_task_id']);

                                $priority_to_use = $item['current_priority'];
                                $color_map = ['Critica' => ['bg' => 'bg-red-100', 'border' => 'border-red-500', 'text' => 'text-red-800', 'badge' => 'bg-red-200'],'Alta' => ['bg' => 'bg-orange-100', 'border' => 'border-orange-500', 'text' => 'text-orange-800', 'badge' => 'bg-orange-200']];
                                $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                            ?>
                            <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $task_id_to_use; ?>">
                                <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                    <div class="flex justify-between items-start">
                                        <p class="font-semibold <?php echo $color['text']; ?> text-lg">
                                            <?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?>
                                            <?php if (!empty($item['invoice_number'])): ?>
                                                <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span>
                                            <?php endif; ?>
                                            <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full"><?php echo strtoupper($priority_to_use); ?></span>
                                        </p>
                                        </div>
                                    <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                    <?php if (!empty($item['end_datetime'])): ?>
                                        <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                    <?php endif; ?>
                                    <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                        <?php if ($can_complete): ?>
                                            <button onclick="toggleForm('complete-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-green-600 hover:text-green-800">Completar</button>
                                        <?php endif; ?>
                                        <button onclick="toggleForm('assign-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?></button>
                                        <button onclick="toggleForm('reminder-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                        <div class="flex-grow text-right text-sm">
                                            <?php if($is_group_task): ?>
                                                <span class="font-semibold text-purple-700">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></span>
                                            <?php elseif (!empty($assigned_names)): ?>
                                                <span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($assigned_names); ?></span>
                                            <?php else: ?>
                                                <span class="font-semibold text-gray-500">Pendiente de Asignación</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div id="complete-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                    <h4 class="text-sm font-semibold mb-2">Completar Tarea</h4>
                                    <textarea id="resolution-note-<?php echo $form_id_prefix; ?>" rows="3" class="w-full p-2 text-sm border rounded-md" placeholder="Añadir observación de cierre (obligatorio)..."></textarea>
                                    <button type="button" onclick="completeTask(<?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar Cierre</button>
                                </div>
                                <div id="assign-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                    <h4 class="text-sm font-semibold mb-2"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                    <select id="assign-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md">
                                        <optgroup label="Grupos">
                                            <option value="group-todos">Todos los Usuarios</option>
                                            <option value="group-Operador">Todos los Operadores</option>
                                            <option value="group-Checkinero">Todos los Checkineros</option>
                                            <option value="group-Digitador">Todos los Digitadores</option>
                                        </optgroup>
                                        <optgroup label="Usuarios Individuales">
                                            <?php foreach ($all_users as $user): ?>
                                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']) . " ({$user['role']})"; ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                    <textarea id="task-instruction-<?php echo $form_id_prefix; ?>" rows="2" class="w-full p-2 text-sm border rounded-md mt-2" placeholder="Instrucción"><?php echo htmlspecialchars($item['instruction'] ?? ''); ?></textarea>
                                    <button type="button" onclick="submitAssignment(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-blue-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar</button>
                                </div>
                                <div id="reminder-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                    <h4 class="text-sm font-semibold mb-2">Crear Recordatorio</h4>
                                    <select id="reminder-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md">
                                         <option value="">Seleccione usuario...</option> <?php foreach ($all_users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" onclick="setReminder(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Crear</button>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>

                       <div class="bg-white p-6 rounded-xl shadow-sm mt-8">
                         <h2 class="text-lg font-semibold mb-4 text-gray-900">Recaudos del Día</h2>
                         <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="text-xs text-gray-500 uppercase bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3">Planilla</th>
                                        <th class="px-6 py-3">Fondo</th>
                                        <th class="px-6 py-3">Cliente / Tienda</th>
                                        <th class="px-6 py-3">Operador</th>
                                        <th class="px-6 py-3">Digitador</th>
                                        <th class="px-6 py-3">Hora Conteo</th>
                                        <th class="px-6 py-3">Monto Contado</th>
                                        <th class="px-6 py-3">Estado</th>
                                        <th class="px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody id="recaudos-tbody">
                                    <?php if (empty($today_collections)): ?>
                                        <tr><td colspan="9" class="px-6 py-4 text-center text-gray-500">No hay recaudos registrados hoy.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($today_collections as $recaudo): ?>
                                            <?php
                                                $status_badge = '';
                                                switch ($recaudo['final_status']) {
                                                    case 'Conforme': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-green-100 text-green-800">Conforme</span>'; break;
                                                    case 'Cerrado': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-blue-100 text-blue-800">Cerrado</span>'; break;
                                                    case 'Rechazado': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-red-100 text-red-800">Rechazado</span>'; break;
                                                    case 'En Revisión (Digitador)': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-yellow-100 text-yellow-800">En Revisión</span>'; break;
                                                    case 'Pendiente (Operador)': $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 text-gray-800">Pendiente</span>'; break;
                                                    default: $status_badge = '<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-gray-100 text-gray-800">' . htmlspecialchars($recaudo['final_status']) . '</span>'; break;
                                                }
                                            ?>
                                            <tr class="border-b">
                                                <td class="px-6 py-4 font-mono"><?php echo htmlspecialchars($recaudo['invoice_number']); ?></td>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['fund_name'] ?? 'N/A'); ?></td>
                                                <td class="px-6 py-4 font-medium"><?php echo htmlspecialchars($recaudo['client_name']); ?></td>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['operator_name']); ?></td>
                                                <td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['digitador_name'] ?? 'N/A'); ?></td>
                                                <td class="px-6 py-4 text-xs"><?php echo date('h:i a', strtotime($recaudo['created_at'])); ?></td>
                                                <td class="px-6 py-4 font-mono"><?php echo '$' . number_format($recaudo['total_counted'], 0, ',', '.'); ?></td>
                                                <td class="px-6 py-4"><?php echo $status_badge; ?></td>
                                                <td class="px-6 py-4 text-right">
                                                    <button onclick="toggleBreakdown(<?php echo $recaudo['id']; ?>)" class="text-blue-600 text-xs font-semibold">Desglose</button>
                                                </td>
                                            </tr>
                                            <tr class="details-row hidden" id="breakdown-row-<?php echo $recaudo['id']; ?>">
                                                <td colspan="9" class="p-0">
                                                    <div id="breakdown-content-<?php echo $recaudo['id']; ?>" class="cash-breakdown bg-gray-50">
                                                        <div class="p-4 grid grid-cols-3 sm:grid-cols-4 md:grid-cols-7 gap-x-8 gap-y-2 text-xs">
                                                            <span><strong>$100.000:</strong> <?php echo number_format($recaudo['bills_100k'] ?? 0); ?></span>
                                                            <span><strong>$50.000:</strong> <?php echo number_format($recaudo['bills_50k'] ?? 0); ?></span>
                                                            <span><strong>$20.000:</strong> <?php echo number_format($recaudo['bills_20k'] ?? 0); ?></span>
                                                            <span><strong>$10.000:</strong> <?php echo number_format($recaudo['bills_10k'] ?? 0); ?></span>
                                                            <span><strong>$5.000:</strong> <?php echo number_format($recaudo['bills_5k'] ?? 0); ?></span>
                                                            <span><strong>$2.000:</strong> <?php echo number_format($recaudo['bills_2k'] ?? 0); ?></span>
                                                            <span class="font-bold">Monedas: <?php echo '$' . number_format($recaudo['coins'] ?? 0, 0, ',', '.'); ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    </div>

                    <div class="space-y-8">
                        <div class="bg-white p-6 rounded-xl shadow-sm">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Crear Tarea Manual</h2>
                            <form id="manual-task-form" class="space-y-3">
                                <div><label for="manual-task-title" class="text-sm font-medium">Título</label><input type="text" id="manual-task-title" required class="w-full p-2 text-sm border rounded-md mt-1"></div>
                                <div><label for="manual-task-desc" class="text-sm font-medium">Descripción</label><textarea id="manual-task-desc" rows="3" class="w-full p-2 text-sm border rounded-md mt-1"></textarea></div>
                                <div><label for="manual-task-priority" class="text-sm font-medium">Prioridad</label><select id="manual-task-priority" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="Alta">Alta</option><option value="Media" selected>Media</option><option value="Baja">Baja</option></select></div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="manual-task-start" class="text-sm font-medium">Fecha/Hora Inicio</label>
                                        <input type="datetime-local" id="manual-task-start" class="w-full p-2 text-sm border rounded-md mt-1">
                                    </div>
                                    <div>
                                        <label for="manual-task-end" class="text-sm font-medium">Fecha/Hora Fin</label>
                                        <input type="datetime-local" id="manual-task-end" class="w-full p-2 text-sm border rounded-md mt-1">
                                    </div>
                                </div>
                                <div>
                                    <label for="manual-task-user" class="text-sm font-medium">Asignar a</label>
                                    <select id="manual-task-user" required class="w-full p-2 text-sm border rounded-md mt-1">
                                        <option value="">Seleccionar...</option>
                                        <optgroup label="Grupos">
                                            <option value="group-todos">Todos los Usuarios</option>
                                            <option value="group-Operador">Todos los Operadores</option>
                                            <option value="group-Checkinero">Todos los Checkineros</option>
                                            <option value="group-Digitador">Todos los Digitadores</option>
                                        </optgroup>
                                        <optgroup label="Usuarios Individuales">
                                            <?php foreach ($all_users as $user):?>
                                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?> (<?php echo $user['role']; ?>)</option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                </div>
                                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 rounded-md">Crear Tarea</button>
                            </form>
                        </div>

                        <div class="bg-white p-6 rounded-xl shadow-sm">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Tareas y Alertas no Prioritarias</h2>
                            <div class="space-y-4">
                               <?php if (empty($main_non_priority_items)): ?>
                                    <p class="text-sm text-gray-500">No hay items no prioritarios pendientes.</p>
                                <?php else: foreach ($main_non_priority_items as $item): ?>
                                    <?php
                                        $is_manual = $item['item_type'] === 'manual_task';
                                        $is_group_task = !empty($item['assigned_to_group']);
                                        $assigned_names = $item['assigned_names'] ?? null;

                                        $task_id_to_use = $item['task_id'] ?? $item['id'];
                                        $alert_id_or_null = $is_manual ? 'null' : ($item['id'] ?? 'null');
                                        $form_id_prefix = "np-" . $task_id_to_use; // Prefijo "np" para no prioritarios

                                        $can_complete = isset($item['task_status']) && $item['task_status'] === 'Pendiente' && !empty($item['user_task_id']);

                                        $priority_to_use = $item['current_priority'];
                                        $color_map = ['Media' => ['bg' => 'bg-yellow-100', 'border' => 'border-yellow-400', 'text' => 'text-yellow-800', 'badge' => 'bg-yellow-200'],'Baja'  => ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200']];
                                        $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                                    ?>
                                    <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $task_id_to_use; ?>">
                                        <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                            <div class="flex justify-between items-start">
                                                <p class="font-semibold <?php echo $color['text']; ?> text-md">
                                                    <?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title'] ?? 'Alerta/Tarea'); ?>
                                                    <?php if (!empty($item['invoice_number'])): ?>
                                                        <span class="font-normal text-blue-600">(Planilla: <?php echo htmlspecialchars($item['invoice_number']); ?>)</span>
                                                    <?php endif; ?>
                                                    <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full"><?php echo strtoupper($priority_to_use); ?></span>
                                                </p>
                                                </div>
                                            <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? ($item['instruction'] ?? '') : ($item['description'] ?? '')); ?></p>
                                            <?php if (!empty($item['end_datetime'])): ?>
                                                <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                            <?php endif; ?>
                                            <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                                <?php if ($can_complete): ?>
                                                    <button onclick="toggleForm('complete-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-green-600 hover:text-green-800">Completar</button>
                                                <?php endif; ?>
                                                <button onclick="toggleForm('assign-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?></button>
                                                <button onclick="toggleForm('reminder-form-<?php echo $form_id_prefix; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                                <div class="flex-grow text-right text-sm">
                                                     <?php if($is_group_task): ?>
                                                        <span class="font-semibold text-purple-700">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></span>
                                                    <?php elseif (!empty($assigned_names)): ?>
                                                        <span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($assigned_names); ?></span>
                                                    <?php else: ?>
                                                        <span class="font-semibold text-gray-500">Pendiente de Asignación</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                         <div id="complete-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2">Completar Tarea</h4>
                                            <textarea id="resolution-note-<?php echo $form_id_prefix; ?>" rows="3" class="w-full p-2 text-sm border rounded-md" placeholder="Añadir observación de cierre (obligatorio)..."></textarea>
                                            <button type="button" onclick="completeTask(<?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar Cierre</button>
                                        </div>
                                        <div id="assign-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2"><?php echo ($is_group_task || !empty($assigned_names)) ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                            <select id="assign-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md">
                                                <optgroup label="Grupos">
                                                    <option value="group-todos">Todos los Usuarios</option>
                                                    <option value="group-Operador">Todos los Operadores</option>
                                                    <option value="group-Checkinero">Todos los Checkineros</option>
                                                    <option value="group-Digitador">Todos los Digitadores</option>
                                                </optgroup>
                                                <optgroup label="Usuarios Individuales">
                                                    <?php foreach ($all_users as $user): ?>
                                                        <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']) . " ({$user['role']})"; ?></option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            </select>
                                            <textarea id="task-instruction-<?php echo $form_id_prefix; ?>" rows="2" class="w-full p-2 text-sm border rounded-md mt-2" placeholder="Instrucción"><?php echo htmlspecialchars($item['instruction'] ?? ''); ?></textarea>
                                            <button type="button" onclick="submitAssignment(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-blue-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar</button>
                                        </div>
                                        <div id="reminder-form-<?php echo $form_id_prefix; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2">Crear Recordatorio</h4>
                                            <select id="reminder-user-<?php echo $form_id_prefix; ?>" class="w-full p-2 text-sm border rounded-md">
                                                 <option value="">Seleccione usuario...</option> <?php foreach ($all_users as $user): ?>
                                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" onclick="setReminder(<?php echo $alert_id_or_null; ?>, <?php echo $task_id_to_use; ?>, '<?php echo $form_id_prefix; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Crear</button>
                                        </div>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-checkinero" class="hidden">
                <h2 class="text-2xl font-bold text-gray-900 mb-6">Módulo de Check-in</h2>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 id="checkin-form-title" class="text-xl font-semibold mb-4">Registrar Nuevo Check-in</h3>
                        <form id="checkin-form" class="space-y-4">
                             <input type="hidden" id="check_in_id_field">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="invoice_number" class="block text-sm font-medium">Número de Factura/Planilla</label>
                                    <input type="text" id="invoice_number" required class="mt-1 w-full p-2 border rounded-md">
                                </div>
                                <div>
                                    <label for="seal_number" class="block text-sm font-medium">Número de Sello</label>
                                    <input type="text" id="seal_number" required class="mt-1 w-full p-2 border rounded-md">
                                </div>
                            </div>
                            <div>
                                <label for="client_id" class="block text-sm font-medium">Cliente</label>
                                <select id="client_id" required class="mt-1 w-full p-2 border rounded-md">
                                    <option value="">Seleccione un cliente...</option>
                                    <?php foreach($all_clients as $client): ?>
                                        <option value="<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']) . ' (NIT: ' . htmlspecialchars($client['nit'] ?? 'N/A') . ')'; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="route_id" class="block text-sm font-medium">Ruta</label>
                                    <select id="route_id" required class="mt-1 w-full p-2 border rounded-md">
                                        <option value="">Seleccione una ruta...</option>
                                        <?php foreach($all_routes as $route): ?>
                                            <option value="<?php echo $route['id']; ?>"><?php echo htmlspecialchars($route['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="fund_id" class="block text-sm font-medium">Fondo</label>
                                    <select id="fund_id" required class="mt-1 w-full p-2 border rounded-md bg-gray-200" disabled>
                                        <option value="">Seleccione un cliente primero...</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label for="declared_value" class="block text-sm font-medium">Valor Declarado</label>
                                <input type="number" step="0.01" id="declared_value" required class="mt-1 w-full p-2 border rounded-md">
                            </div>
                             <div id="checkin-form-buttons" class="flex space-x-4 pt-4">
                                <button type="submit" id="checkin-submit-button" class="w-full bg-green-600 text-white font-bold py-3 rounded-md hover:bg-green-700">Agregar Check-in</button>
                            </div>
                        </form>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 class="text-xl font-semibold mb-4">Últimos Check-ins Registrados</h3>
                        <div class="overflow-auto max-h-[600px]">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 sticky top-0">
                                     </thead>
                                <tbody id="checkins-table-body">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div id="content-operador" class="hidden">
                <h2 class="text-2xl font-bold text-gray-900 mb-6">Módulo de Operador</h2>

                <div id="consultation-section" class="bg-white p-6 rounded-xl shadow-lg mb-8">
                     <h3 class="text-xl font-semibold mb-4">Buscar Planilla para Detallar</h3>
                     <form id="consultation-form" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                         <div>
                             <label for="consult-invoice" class="block text-sm font-medium">Número de Planilla</label>
                             <input type="text" id="consult-invoice" required class="mt-1 w-full p-2 border rounded-md">
                         </div>
                         <div class="pt-6">
                             <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Consultar</button>
                         </div>
                     </form>
                </div>

                <div id="operator-panel" class="hidden">
                    <div class="bg-white p-6 rounded-xl shadow-lg mb-8">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 pb-4 border-b">
                            <div><span class="block text-sm text-gray-500">Número de Planilla</span><strong id="display-invoice" class="text-lg"></strong></div>
                            <div><span class="block text-sm text-gray-500">Sello de la Factura</span><strong id="display-seal" class="text-lg"></strong></div>
                            <div><span class="block text-sm text-gray-500">Nombre del Cliente</span><strong id="display-client" class="text-lg"></strong></div>
                            <div><span class="block text-sm text-gray-500">Valor Declarado</span><strong id="display-declared" class="text-lg text-blue-600"></strong></div>
                        </div>

                        <h3 class="text-xl font-semibold mb-4">Detalle de Denominación</h3>
                        <form id="denomination-form">
                            <input type="hidden" id="op-checkin-id">
                            <div class="space-y-2">
                                <?php
                                    $denominations = [100000, 50000, 20000, 10000, 5000, 2000];
                                    foreach($denominations as $value):
                                ?>
                                <div class="grid grid-cols-5 gap-4 items-center denomination-row" data-value="<?php echo $value; ?>">
                                    <div class="col-span-2 font-medium text-gray-700"><?php echo '$' . number_format($value, 0, ',', '.'); ?></div>
                                    <div class="col-span-2 flex items-center">
                                        <button type="button" class="px-3 py-1 bg-gray-200 rounded-l-md font-bold text-lg" onclick="updateQty(this, -1)">-</button>
                                        <input type="number" value="0" min="0" class="w-full text-center border-t border-b p-1 denomination-qty" oninput="calculateTotals()">
                                        <button type="button" class="px-3 py-1 bg-gray-200 rounded-r-md font-bold text-lg" onclick="updateQty(this, 1)">+</button>
                                    </div>
                                    <div class="text-right font-mono subtotal">$ 0</div>
                                </div>
                                <?php endforeach; ?>

                                <div class="grid grid-cols-5 gap-4 items-center pt-2 border-t">
                                    <div class="col-span-2 font-medium text-gray-700">Monedas</div>
                                    <div class="col-span-2">
                                        <input type="number" id="coins-value" value="0" min="0" step="50" class="w-full border p-1" oninput="calculateTotals()" placeholder="Valor total en monedas">
                                    </div>
                                    <div class="text-right font-mono" id="coins-subtotal">$ 0</div>
                                </div>

                                <div class="grid grid-cols-5 gap-4 items-center pt-4 mt-4 border-t-2">
                                    <div class="col-span-2 font-bold text-xl">Total</div>
                                    <div class="col-span-3 text-right font-mono text-xl" id="total-counted">$ 0</div>
                                </div>
                                <div class="grid grid-cols-5 gap-4 items-center">
                                    <div class="col-span-2 font-bold text-xl">Diferencia</div>
                                    <div class="col-span-3 text-right font-mono text-xl" id="discrepancy">$ 0</div>
                                </div>
                            </div>

                            <div class="mt-6">
                                <label for="observations" class="block text-sm font-medium">Observación</label>
                                <textarea id="observations" rows="3" class="mt-1 w-full border rounded-md p-2"></textarea>
                            </div>

                            <div class="mt-6 flex justify-end">
                                <button type="submit" class="bg-green-600 text-white font-bold py-3 px-6 rounded-md hover:bg-green-700">Guardar y Cerrar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                <div class="bg-white p-6 rounded-xl shadow-lg mt-8">
                    <h3 class="text-xl font-semibold mb-4">Planillas Pendientes de Detallar (Visible solo para Admin)</h3>
                    <div class="overflow-auto max-h-[600px]">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 sticky top-0">
                                </thead>
                            <tbody id="operator-checkins-table-body">
                                </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div class="bg-white p-6 rounded-xl shadow-lg mt-8">
                    <h3 class="text-xl font-semibold mb-4">Historial de Conteos Realizados</h3>
                    <div class="overflow-auto max-h-[600px]">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="p-3">Planilla</th>
                                    <th class="p-3">Cliente</th>
                                    <th class="p-3">V. Declarado</th>
                                    <th class="p-3">V. Contado</th>
                                    <th class="p-3">Discrepancia</th>
                                    <?php if (in_array($_SESSION['user_role'], ['Admin', 'Digitador'])): /* Modificado */ ?>
                                        <th class="p-3">Operador</th>
                                    <?php endif; ?>
                                    <th class="p-3">Fecha Conteo</th>
                                    <th class="p-3">Observaciones</th>
                                    <?php if ($_SESSION['user_role'] === 'Admin'): ?><th class="p-3">Acciones</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="operator-history-table-body">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if (in_array($_SESSION['user_role'], ['Digitador', 'Admin'])): ?>
            <div id="content-digitador" class="hidden">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-900">Módulo de Digitador: Gestión y Supervisión</h2>
                </div>

                 <div class="mb-8 flex space-x-2">

                    <button id="btn-supervision" class="px-4 py-2 text-sm font-semibold rounded-md bg-gray-200 text-gray-700">Supervisión de Conteos</button>
                    <button id="btn-cierre" class="px-4 py-2 text-sm font-semibold rounded-md bg-blue-600 text-white">Gestión de Cierre</button>
                    <button id="btn-historial-cierre" class="px-4 py-2 text-sm font-semibold rounded-md bg-gray-200 text-gray-700">Historial de Cierres</button>
                    <button id="btn-informes" class="px-4 py-2 text-sm font-semibold rounded-md bg-gray-200 text-gray-700">Generar Informes</button>
                </div>

                <div id="panel-supervision" class="hidden">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">Supervisión de Operaciones</h2>
                    <div id="operator-panel-digitador" class="hidden bg-blue-50 border border-blue-200 p-6 rounded-xl shadow-lg mb-8">
                        </div>
                    <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 class="text-xl font-semibold mb-4">Conteos Pendientes de Supervisión</h3>
                        <p class="text-sm text-gray-500 mb-4">Planillas procesadas por el operador que requieren aprobación o rechazo.</p>
                        <div class="overflow-auto max-h-[600px]">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="p-3">Revisar</th>
                                        <th class="p-3">Planilla</th>
                                        <th class="p-3">Cliente</th>
                                        <th class="p-3">V. Declarado</th>
                                        <th class="p-3">V. Contado</th>
                                        <th class="p-3">Discrepancia</th>
                                        <th class="p-3">Operador</th>
                                        <th class="p-3">Fecha Conteo</th>
                                    </tr>
                                </thead>
                                <tbody id="operator-history-table-body-digitador"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="panel-cierre">
                     <h2 class="text-2xl font-bold text-gray-900 mb-6">Gestión de Cierre por Fondo</h2>
                     <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 class="text-xl font-semibold mb-4 text-gray-900">Proceso de Cierre</h3>
                        <p class="text-sm text-gray-500 mb-4">Seleccione un fondo para ver las planillas aprobadas ('Conforme') y proceder con el cierre.</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold mb-2">1. Fondos listos para cerrar</h4>
                                <div id="funds-list-container" class="space-y-2 max-h-96 overflow-y-auto">
                                    </div>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-2">2. Planillas a incluir en el cierre</h4>
                                <div id="services-list-container" class="space-y-3">
                                    <p class="text-gray-500 text-sm">Seleccione un fondo de la lista.</p>
                                </div>
                                <button id="close-fund-button" onclick="closeFund()" class="w-full bg-teal-500 text-white font-semibold py-2 px-4 rounded-md hover:bg-teal-600 mt-4 hidden">
                                    Cerrar Fondo Seleccionado
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                 <div id="panel-historial-cierre" class="hidden">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">Historial de Planillas Revisadas/Cerradas</h2>
                    <div class="bg-white p-6 rounded-xl shadow-lg mt-8">
                        <h3 class="text-xl font-semibold mb-4">Historial</h3>
                        <div class="overflow-auto max-h-[700px]">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="p-3">Planilla</th>
                                        <th class="p-3">Fondo</th>
                                        <th class="p-3">Cierre</th>
                                        <th class="p-3">Total Recaudado</th>
                                        <th class="p-3">Discrepancia</th>
                                        <th class="p-3">Estado Final</th>
                                        <th class="p-3">Observaciones</th>
                                        <?php if (in_array($_SESSION['user_role'], ['Admin', 'Digitador'])): ?>
                                            <th class="p-3">Cerrada por</th>
                                        <?php endif; ?>
                                        <th class="p-3">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="digitador-closed-history-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="panel-informes" class="hidden">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">Generar Informes</h2>
                    <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 class="text-xl font-semibold mb-4 text-gray-900">Informes por Fondo (PDF)</h3>
                        <p class="text-sm text-gray-500 mb-4">Seleccione un fondo cerrado para generar el informe PDF consolidado.</p>
                        <div class="overflow-auto max-h-[500px]">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="p-3 text-left">Fondo</th>
                                        <th class="p-3 text-left">Cliente</th>
                                        <th class="p-3 text-left">Fecha de Cierre</th>
                                        <th class="p-3 text-center">Acción</th>
                                    </tr>
                                </thead>
                                <tbody id="informes-table-body">
                                    </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="content-mi-historial" class="hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Mi Historial de Tareas Completadas</h2>
                <p class="text-sm text-gray-500 mb-6">Este es un registro de todas las tareas (manuales y alertas) que tú has completado.</p>

                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table id="historial-individual-table" class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left">
                                    <th class="px-6 py-3">Tarea</th>
                                    <th class="px-6 py-3">Descripción</th>
                                    <th class="px-6 py-3">Prioridad Inicial</th>
                                    <th class="px-6 py-3">Prioridad Final</th>
                                    <th class="px-6 py-3">Asignado por</th>
                                    <th class="px-6 py-3">Check por</th>
                                    <th class="px-6 py-3">Fecha Inicio</th>
                                    <th class="px-6 py-3">Fecha Fin</th>
                                    <th class="px-6 py-3">Tiempo Resp.</th>
                                    <th class="px-6 py-3">Observaciones de Cierre</th>
                                </tr>
                            </thead>
                            <tbody id="historial-individual-tbody">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>


            <?php if ($_SESSION['user_role'] === 'Admin'): ?>
            <div id="content-roles" class="hidden">
                 <div class="flex justify-between items-center mb-4"><h2 class="text-xl font-bold">Gestionar Usuarios</h2><button onclick="openModal()" class="bg-green-600 text-white font-semibold px-4 py-2 rounded-lg">Agregar Usuario</button></div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left">
                                <th class="px-6 py-3">Nombre</th>
                                <th class="px-6 py-3">Email</th>
                                <th class="px-6 py-3">Rol</th>
                                <th class="px-6 py-3 text-center">Sexo</th>
                                <th class="px-6 py-3 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="user-table-body"></tbody>
                    </table>
                </div>
            </div>
            <div id="content-trazabilidad" class="hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Trazabilidad de Tareas Completadas (Admin)</h2>

                <div class="bg-white p-4 rounded-xl shadow-sm mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div>
                            <label for="filter-start-date" class="text-sm font-medium text-gray-700">Fecha Inicio</label>
                            <input type="date" id="filter-start-date" class="mt-1 w-full p-2 border rounded-md text-sm">
                        </div>
                        <div>
                            <label for="filter-end-date" class="text-sm font-medium text-gray-700">Fecha Fin</label>
                            <input type="date" id="filter-end-date" class="mt-1 w-full p-2 border rounded-md text-sm">
                        </div>
                        <div>
                            <label for="filter-user" class="text-sm font-medium text-gray-700">Asignado a</label>
                            <select id="filter-user" class="mt-1 w-full p-2 border rounded-md text-sm">
                                <option value="">Todos</option>
                                <?php foreach($all_users as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['name']); ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter-checker" class="text-sm font-medium text-gray-700">Check por</label>
                            <select id="filter-checker" class="mt-1 w-full p-2 border rounded-md text-sm">
                                <option value="">Todos</option>
                                <?php foreach($all_users as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['name']); ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="filter-priority" class="text-sm font-medium text-gray-700">Prioridad Final</label>
                            <select id="filter-priority" class="mt-1 w-full p-2 border rounded-md text-sm">
                                <option value="">Todas</option>
                                <option value="Alta">Alta</option>
                                <option value="Media">Media</option>
                                <option value="Baja">Baja</option>
                            </select>
                        </div>
                        <div class="flex items-end space-x-2">
                            <button onclick="applyTrazabilidadFilters()" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md">Filtrar</button>
                            <button onclick="exportToExcel()" class="w-full bg-green-600 text-white font-semibold py-2 px-4 rounded-md">Excel</button>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table id="trazabilidad-table" class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left">
                                    <th class="px-6 py-3">Tarea</th>
                                    <th class="px-6 py-3">Descripción</th>
                                    <th class="px-6 py-3">Prioridad Inicial</th>
                                    <th class="px-6 py-3">Prioridad Final</th>
                                    <th class="px-6 py-3 sortable cursor-pointer" data-column-name="created_at" onclick="sortTableByDate('created_at')">Hora Inicio <span class="text-gray-400"></span></th>
                                    <th class="px-6 py-3 sortable cursor-pointer" data-column-name="completed_at" onclick="sortTableByDate('completed_at')">Hora Fin <span class="text-gray-400"></span></th>
                                    <th class="px-6 py-3">Tiempo Resp.</th>
                                    <th class="px-6 py-3">Asignado a</th>
                                    <th class="px-6 py-3">Asignado por</th>
                                    <th class="px-6 py-3">Check por</th>
                                    <th class="px-6 py-3">Observaciones de Cierre</th>
                                    <th class="px-6 py-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="trazabilidad-tbody">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="content-manage-clients" class="hidden">
                <div class="loader"></div> <p class="text-center text-gray-500">Cargando...</p>
            </div>
            <div id="content-manage-routes" class="hidden">
                 <div class="loader"></div> <p class="text-center text-gray-500">Cargando...</p>
            </div>
            <div id="content-manage-funds" class="hidden">
                 <div class="loader"></div> <p class="text-center text-gray-500">Cargando...</p>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
    // --- Global Variables ---
    const allUsers = <?php echo json_encode($all_users); ?>;
    const adminUsersData = <?php echo json_encode($admin_users_list); ?>;
    const currentUserId = <?php echo $current_user_id; ?>;
    const currentUserRole = '<?php echo $current_user_role; ?>';
    const apiUrlBase = 'api';
    const initialCheckins = <?php echo json_encode($initial_checkins); ?>;
    const operatorHistoryData = <?php echo json_encode($operator_history); ?>;
    const digitadorClosedHistory = <?php echo json_encode($digitador_closed_history); ?>;
    const completedTasksData = <?php echo json_encode($completed_tasks); ?>;
    const userCompletedTasksData = <?php echo json_encode($user_completed_tasks); ?>;
    let selectedFundForClosure = null;
    let alertPollingInterval = null;
    let checkinPollingInterval = null; // Separate interval for checkins
    let lastCheckedAlertTime = Math.floor(Date.now() / 1000);
    let currentCheckinData = JSON.parse(JSON.stringify(initialCheckins)); // Store initial checkins
    let currentFilteredTrazabilidadData = [];
    let loadedContent = {}; // Cache for AJAX

    // --- UI Element References ---
    const remindersPanel = document.getElementById('reminders-panel');
    const taskNotificationsPanel = document.getElementById('task-notifications-panel');
    const mediumPriorityPanel = document.getElementById('medium-priority-panel');
    const modalOverlay = document.getElementById('user-modal-overlay');
    const modalTitle = document.getElementById('modal-title');
    const userForm = document.getElementById('user-form');
    const userIdInput = document.getElementById('user-id');
    const passwordHint = document.getElementById('password-hint');
    const userPasswordInput = document.getElementById('user-password');
    const alertPopupOverlay = document.getElementById('alert-popup-overlay');
    const alertPopup = document.getElementById('alert-popup');
    const alertPopupTitle = document.getElementById('alert-popup-title');
    const alertPopupDescription = document.getElementById('alert-popup-description');
    const alertPopupHeader = document.getElementById('alert-popup-header');

    // --- UI Interaction Functions ---
     // Function to toggle notification panels
    function togglePanel(panelId) {
         const panel = document.getElementById(panelId);
         if (!panel) return;

         // Usar classList.toggle() es más simple, pero para asegurar que solo uno esté abierto:
         const isHidden = panel.classList.contains('hidden');

         // Close all panels
         document.getElementById('reminders-panel')?.classList.add('hidden');
         document.getElementById('task-notifications-panel')?.classList.add('hidden');
         document.getElementById('medium-priority-panel')?.classList.add('hidden');

         // Toggle the clicked one
         if (isHidden) {
             panel.classList.remove('hidden'); // Use classList
         }
         // Si ya estaba abierto (isHidden=false), el bucle anterior ya lo cerró.
     }
     function closeModal() { modalOverlay?.classList.add('hidden'); }
     function closeAlertPopup() {
        if (!alertPopupOverlay || !alertPopup) return;
        alertPopup.classList.add('scale-95', 'opacity-0');
        alertPopup.classList.remove('scale-100', 'opacity-100');
        setTimeout(() => { alertPopupOverlay.classList.add('hidden'); }, 300);
     }
     function toggleForm(formId, button) {
         const form = document.getElementById(formId);
         if (!form) {
              console.error(`Formulario con ID ${formId} no encontrado.`);
              return;
         }
        const parentItem = button.closest('.task-card');
        parentItem.querySelectorAll('.task-form').forEach(f => {
            if (f.id !== formId && f.classList.contains('active')) {
                f.classList.remove('active');
            }
        });
        form.classList.toggle('active');
     }
     function toggleBreakdown(id) {
        const row = document.getElementById(`breakdown-row-${id}`);
        const content = document.getElementById(`breakdown-content-${id}`);
        row?.classList.toggle('hidden');
        if (content) setTimeout(() => content.classList.toggle('active'), 10);
     }

    // --- Data Formatting ---
    function formatCurrency(value) {
       const numberValue = Number(value);
       if (isNaN(numberValue)) return '$ 0';
       return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(numberValue);
    }
    function formatDate(dateString) {
       if (!dateString) return '';
       try {
           const date = new Date(dateString); if (isNaN(date)) return '';
           return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' }) + ' ' + date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
       } catch (e) { return ''; }
    }
    function getPriorityClass(priority) {
       if (priority === 'Alta' || priority === 'Critica') return 'bg-red-100 text-red-800';
       if (priority === 'Media') return 'bg-yellow-100 text-yellow-800';
       return 'bg-gray-100 text-gray-800';
    }
    function getRoleDisplayNameJS(role, gender) {
       if (gender === 'F') {
           switch (role) {
               case 'Digitador': return 'Digitadora';
               case 'Operador': return 'Operadora';
               case 'Checkinero': return 'Checkinera';
               default: return role;
           }
       }
       return role;
    }

    // --- API Call Functions ---
    async function deleteReminder(reminderId, button) {
        try {
            const response = await fetch(`${apiUrlBase}/alerts_api.php?reminder_id=${reminderId}`, { method: 'DELETE' });
            const result = await response.json();
            if (result.success) {
                button.closest('.reminder-item').remove();
                updateReminderCount();
            } else { alert('Error: ' + result.error); }
        } catch (error) { console.error('Error deleting reminder:', error); alert('Error de conexión.'); }
    }

    async function completeTask(taskId, formIdPrefix) {
        if (!taskId) {
            alert('Error: No se pudo identificar la tarea a completar.');
            return;
        }

        const noteTextarea = document.getElementById(`resolution-note-${formIdPrefix}`);
        const resolution_note = noteTextarea ? noteTextarea.value : ''; // Manejar caso donde no existe el textarea

        if (!resolution_note.trim()) {
            alert('Por favor, ingrese una observación de cierre.');
            if (noteTextarea) noteTextarea.focus();
            return;
        }

        if (!confirm('¿Estás seguro de que quieres marcar esta tarea como completada?')) return;

        try {
            const response = await fetch(`${apiUrlBase}/task_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    task_id: taskId,
                    resolution_note: resolution_note
                })
            });
            const result = await response.json();
            if (result.success) {
                alert('Tarea completada con éxito.');
                location.reload();
            } else { alert('Error al completar la tarea: ' + result.error); }
        } catch (error) { console.error('Error completing task:', error); alert('Error de conexión.'); }
    }

    async function submitAssignment(alertId, taskId, formIdPrefix) {
        const assignSelect = document.getElementById(`assign-user-${formIdPrefix}`);
        const instructionTextarea = document.getElementById(`task-instruction-${formIdPrefix}`);

         if (!assignSelect || !instructionTextarea) {
              console.error("Elementos de formulario no encontrados para asignar tarea.");
              return;
         }

        const selectedValue = assignSelect.value;
        const instruction = instructionTextarea.value;

        // Validar que se seleccionó algo
        if (!selectedValue) {
             alert('Por favor, selecciona un usuario o grupo para asignar.');
             return;
        }

        let payload = {
            instruction: instruction,
            type: alertId ? 'Asignacion' : 'Manual', // Determinar tipo basado en si hay alertId
            task_id: taskId,
            alert_id: alertId
        };
        if (selectedValue.startsWith('group-')) {
             payload.assign_to_group = selectedValue.replace('group-', '');
             delete payload.assign_to; // Asegurar que no se envíe asignación individual
        } else {
             payload.assign_to = selectedValue;
             delete payload.assign_to_group; // Asegurar que no se envíe asignación de grupo
        }
        await sendTaskRequest(payload);
    }

    async function setReminder(alertId, taskId, formIdPrefix) {
        const reminderSelect = document.getElementById(`reminder-user-${formIdPrefix}`);
         if (!reminderSelect) {
              console.error("Selector de usuario para recordatorio no encontrado.");
              return;
         }
        const userId = reminderSelect.value;
        if (!userId) {
             alert('Por favor, selecciona un usuario para el recordatorio.');
             return;
        }
        await sendTaskRequest({ assign_to: userId, type: 'Recordatorio', task_id: taskId, alert_id: alertId });
    }

    async function sendTaskRequest(payload) {
        try {
            const response = await fetch(`${apiUrlBase}/alerts_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (!response.ok) {
                const errorResult = await response.json().catch(() => ({ error: `Error HTTP ${response.status}` }));
                 throw new Error(errorResult.error || `Error HTTP ${response.status}`);
            }
            const result = await response.json();
            if (result.success) {
                alert('Acción completada con éxito.');
                location.reload();
            } else {
                alert('Error desde la API: ' + result.error);
            }
        } catch (error) { console.error('Error en sendTaskRequest:', error); alert(`Error de conexión: ${error.message}`); }
    }

    document.getElementById('manual-task-form')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const title = document.getElementById('manual-task-title').value;
        const instruction = document.getElementById('manual-task-desc').value;
        const selectedValue = document.getElementById('manual-task-user').value;
        const priority = document.getElementById('manual-task-priority').value;
        const start_datetime = document.getElementById('manual-task-start').value;
        const end_datetime = document.getElementById('manual-task-end').value;

        if (!selectedValue) { alert('Selecciona un usuario o grupo.'); return; }
        if (start_datetime && end_datetime && start_datetime >= end_datetime) { alert('La fecha de fin debe ser posterior a la fecha de inicio.'); return; }

        let payload = { title, instruction, type: 'Manual', priority, start_datetime: start_datetime || null, end_datetime: end_datetime || null };
        if (selectedValue.startsWith('group-')) {
             payload.assign_to_group = selectedValue.replace('group-', '');
        } else {
             payload.assign_to = selectedValue;
        }

        await sendTaskRequest(payload); // Reutilizar la función sendTaskRequest
    });

    userForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        try {
            const response = await fetch(`${apiUrlBase}/users_api.php`, { method: 'POST', body: new FormData(userForm) });
             const result = await response.json(); // Leer la respuesta independientemente del status

             if (!response.ok) {
                 throw new Error(result.error || `Error HTTP ${response.status}`);
             }

            if (result.success) {
                 closeModal();
                 setTimeout(() => location.reload(), 100);
            } else {
                 alert('Error al guardar: ' + result.error);
            }
        } catch (error) {
             console.error("Error en submit de formulario de usuario:", error);
             alert(`Error de conexión o del servidor: ${error.message}`);
        }
    });

    async function deleteUser(id) {
        if (!confirm('¿Eliminar usuario? Esta acción también eliminará sus tareas y recordatorios asociados.')) return;
        try {
            const response = await fetch(`${apiUrlBase}/users_api.php`, {
                 method: 'DELETE',
                 headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                 body: `id=${id}`
            });
            const result = await response.json();
            if (result.success) {
                 const row = document.getElementById(`user-row-${id}`);
                 if (row) row.remove();
                 alert(result.message || 'Usuario eliminado.');
                 setTimeout(() => location.reload(), 500);
            } else {
                 alert('Error al eliminar: ' + result.error);
            }
        } catch (error) {
             console.error("Error en deleteUser:", error);
             alert(`Error de conexión al eliminar: ${error.message}`);
        }
    }
    async function handleCheckinSubmit(event) {
        event.preventDefault();
        const checkInIdField = document.getElementById('check_in_id_field');
        const payload = {
            invoice_number: document.getElementById('invoice_number').value,
            seal_number: document.getElementById('seal_number').value,
            client_id: document.getElementById('client_id').value,
            route_id: document.getElementById('route_id').value,
            fund_id: document.getElementById('fund_id').value,
            declared_value: document.getElementById('declared_value').value,
        };

        if (checkInIdField.value) {
            payload.check_in_id = checkInIdField.value;
        }

        try {
            const response = await fetch('api/checkin_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload(); // Recargar para obtener la lista más reciente (incluyendo el polling)
            } else {
                alert('Error: ' + result.error);
            }
        } catch (error) {
            console.error('Error en el check-in:', error);
            alert('Error de conexión al procesar.');
        }
    }
    async function handleConsultation(event) {
        event.preventDefault();
        const invoiceInput = document.getElementById('consult-invoice');
        const operatorPanel = document.getElementById('operator-panel');
        if (!invoiceInput.value) { alert('Por favor, ingrese un número de planilla.'); return; }
        try {
            const response = await fetch(`api/operator_api.php?planilla=${invoiceInput.value}`);
            const result = await response.json();
            if (result.success) {
                const data = result.data;
                document.getElementById('display-invoice').textContent = data.invoice_number;
                document.getElementById('display-seal').textContent = data.seal_number;
                document.getElementById('display-client').textContent = data.client_name;
                document.getElementById('display-declared').textContent = formatCurrency(data.declared_value);
                document.getElementById('display-declared').dataset.value = data.declared_value;
                document.getElementById('op-checkin-id').value = data.id;
                document.getElementById('denomination-form').reset();
                calculateTotals();
                operatorPanel.classList.remove('hidden');
            } else { alert('Error: ' + result.error); operatorPanel.classList.add('hidden'); }
        } catch (error) { console.error('Error en la consulta:', error); alert('Error de conexión.'); }
    }
    async function handleDenominationSave(event) {
        event.preventDefault();
        const payload = {
            check_in_id: document.getElementById('op-checkin-id').value,
            bills_100k: parseInt(document.querySelector('#denomination-form [data-value="100000"] .denomination-qty').value) || 0,
            bills_50k: parseInt(document.querySelector('#denomination-form [data-value="50000"] .denomination-qty').value) || 0,
            bills_20k: parseInt(document.querySelector('#denomination-form [data-value="20000"] .denomination-qty').value) || 0,
            bills_10k: parseInt(document.querySelector('#denomination-form [data-value="10000"] .denomination-qty').value) || 0,
            bills_5k: parseInt(document.querySelector('#denomination-form [data-value="5000"] .denomination-qty').value) || 0,
            bills_2k: parseInt(document.querySelector('#denomination-form [data-value="2000"] .denomination-qty').value) || 0,
            coins: parseFloat(document.getElementById('coins-value').value) || 0,
            total_counted: 0, discrepancy: 0, observations: document.getElementById('observations').value
        };
        let total = (payload.bills_100k * 100000) + (payload.bills_50k * 50000) + (payload.bills_20k * 20000) + (payload.bills_10k * 10000) + (payload.bills_5k * 5000) + (payload.bills_2k * 2000) + payload.coins;
        payload.total_counted = total;
        payload.discrepancy = total - (parseFloat(document.getElementById('display-declared').dataset.value) || 0);

        try {
            const response = await fetch('api/operator_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const result = await response.json();
            if (result.success) { alert(result.message); location.reload(); }
            else { alert('Error al guardar: ' + result.error); }
        } catch (error) { console.error('Error al guardar conteo:', error); alert('Error de conexión.'); }
    }
    async function submitDigitadorReview(checkInId, status) {
        const observations = document.getElementById('digitador-observations').value;
        if (!observations.trim()) {
            alert('Las observaciones finales son requeridas para aceptar o rechazar.');
            return;
        }
        const confirmationText = status === 'Conforme' ? '¿Está seguro de que desea APROBAR este conteo?' : '¿Está seguro de que desea RECHAZAR este conteo? La planilla volverá al Checkinero para corrección.';
        if (!confirm(confirmationText)) return;

        try {
            const response = await fetch(`${apiUrlBase}/digitador_review_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ check_in_id: checkInId, status: status, observations: observations })
            });

            const result = await response.json();

            if (response.ok && result.success) {
                alert(result.message);
            } else {
                 alert('Error: ' + (result.error || `Error ${response.status} - ${response.statusText}`));
            }
        } catch (error) {
            console.error('Error al enviar la revisión:', error);
            alert('Error de conexión.');
        } finally {
            location.reload(); // Recargar siempre para reflejar cambios
        }
    }
    async function deleteCheckIn(checkInId) {
        if (!confirm('¿Está seguro de que desea eliminar permanentemente esta planilla y todos sus registros asociados (conteo, alertas, etc.)? Esta acción no se puede deshacer.')) {
            return;
        }
        try {
            const response = await fetch(`${apiUrlBase}/delete_checkin_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ check_in_id: checkInId })
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert('Error al eliminar: ' + result.error);
            }
        } catch (error) {
            console.error('Error de conexión al eliminar la planilla:', error);
            alert('Error de conexión. No se pudo completar la solicitud.');
        }
    }
    async function deleteTask(taskId) {
        if (!confirm('¿Está seguro de que desea eliminar este registro de tarea del historial de trazabilidad?')) {
            return;
        }
        try {
            const response = await fetch(`${apiUrlBase}/delete_task_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: taskId })
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert('Error al eliminar la tarea: ' + result.error);
            }
        } catch (error) {
            console.error('Error de conexión al eliminar la tarea:', error);
            alert('Error de conexión.');
        }
    }
    async function closeFund() {
        if (!selectedFundForClosure) {
            alert('Por favor, seleccione un fondo primero.');
            return;
        }
        if (!confirm('¿Está seguro de que desea cerrar este fondo? Todas las planillas aprobadas se marcarán como cerradas y pasarán a informes.')) return;

        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=close_fund`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fund_id: selectedFundForClosure })
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'No se pudo cerrar el fondo.'));
            }
        } catch (error) {
            console.error('Error al cerrar fondo:', error);
            alert('Error de conexión.');
        }
    }
    async function loadFundsForCierre() {
        const container = document.getElementById('funds-list-container');
        if (!container) return;
        container.innerHTML = '<p class="text-center text-sm text-gray-500">Cargando fondos...</p>';
        document.getElementById('services-list-container').innerHTML = '<p class="text-gray-500 text-sm">Seleccione un fondo de la lista.</p>';
        document.getElementById('close-fund-button').classList.add('hidden');
        selectedFundForClosure = null;

        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=list_funds_to_close`);
            const funds = await response.json();
            container.innerHTML = '';
            if (funds.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm text-center">No hay fondos listos para cerrar.</p>';
                return;
            }
            funds.forEach(fund => {
                container.innerHTML += `<div id="fund-to-close-${fund.id}" class="p-3 border rounded-lg cursor-pointer hover:bg-gray-100" onclick="loadServicesForFund(${fund.id}, this)">
                                            <p class="font-semibold">${fund.name}</p>
                                            <span class="text-xs text-gray-500">${fund.client_name}</span>
                                        </div>`;
            });
        } catch (error) {
            console.error('Error cargando fondos para cierre:', error);
            container.innerHTML = '<p class="text-center text-red-500 text-sm">Error al cargar fondos.</p>';
        }
    }
    async function loadServicesForFund(fundId, element) {
        selectedFundForClosure = fundId;
        document.querySelectorAll('#funds-list-container > div').forEach(el => el.classList.remove('bg-blue-100', 'border-blue-400'));
        element.classList.add('bg-blue-100', 'border-blue-400');

        const container = document.getElementById('services-list-container');
        container.innerHTML = '<p class="text-center text-sm text-gray-500">Cargando planillas...</p>';

        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=get_services_for_closing&fund_id=${fundId}`);
            const services = await response.json();
            container.innerHTML = '';
            if (services.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-sm">Este fondo no tiene planillas aprobadas.</p>';
                document.getElementById('close-fund-button').classList.add('hidden');
                return;
            }

            let total = 0;
            services.forEach(service => {
                total += parseFloat(service.total_counted);
                container.innerHTML += `<div class="p-2 border-b text-sm">
                                            <div class="flex justify-between">
                                                <span class="font-mono">#${service.invoice_number}</span>
                                                <span class="font-medium">${formatCurrency(service.total_counted)}</span>
                                            </div>
                                        </div>`;
            });
            container.innerHTML += `<div class="p-2 text-sm font-bold border-t-2 border-gray-500">
                                        <div class="flex justify-between">
                                            <span>Total Fondo:</span>
                                            <span>${formatCurrency(total)}</span>
                                        </div>
                                    </div>`;
            document.getElementById('close-fund-button').classList.remove('hidden');
        } catch (error) {
            console.error('Error cargando servicios:', error);
            container.innerHTML = '<p class="text-center text-red-500 text-sm">Error al cargar servicios.</p>';
        }
    }
    async function loadInformes() {
        const tbody = document.getElementById('informes-table-body');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-sm text-gray-500">Cargando informes...</td></tr>';

        try {
            const response = await fetch(`${apiUrlBase}/digitador_informes_api.php?action=list_closed_funds`);
            const funds = await response.json();
            tbody.innerHTML = '';
            if (funds.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-sm text-gray-500">No hay fondos cerrados para informar.</td></tr>';
                return;
            }
            funds.forEach(fund => {
                tbody.innerHTML += `<tr class="border-b">
                                        <td class="p-3 font-semibold">${fund.fund_name}</td>
                                        <td class="p-3">${fund.client_name}</td>
                                        <td class="p-3 text-xs">${new Date(fund.last_close_date).toLocaleString('es-CO')}</td>
                                        <td class="p-3 text-center">
                                            <button onclick="generatePDF(${fund.id}, '${fund.fund_name.replace(/'/g, "\\'")}', '${fund.client_name.replace(/'/g, "\\'")}')" class="bg-green-600 text-white font-bold py-1 px-3 rounded-md hover:bg-green-700 text-xs">
                                                Generar PDF
                                            </button>
                                        </td>
                                    </tr>`;
            });
        } catch (error) {
            console.error('Error cargando informes:', error);
            tbody.innerHTML = '<tr><td colspan="4" class="p-4 text-center text-red-500 text-sm">Error al cargar informes.</td></tr>';
        }
    }
    async function generatePDF(fundId, fundName, clientName) {
        if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
            alert('Error: La librería jsPDF no se cargó.');
            return;
        }
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();

        if (typeof doc.autoTable === 'undefined') {
            alert('Error: La extensión autoTable para PDF no se cargó.');
            return;
        }

        try {
            const response = await fetch(`${apiUrlBase}/digitador_informes_api.php?action=get_report_details&fund_id=${fundId}`);
            const planillas = await response.json();

            if (planillas.length === 0) {
                alert('No se encontraron datos para este informe.');
                return;
            }

            const head = [['Planilla', 'V. Contado', 'Discrepancia', 'Operador', 'Desglose de Billetes/Monedas']];
            const body = [];
            let totalContado = 0;
            let totalDiscrepancia = 0;

            planillas.forEach(p => {
                let desgloseText = [
                    `$100.000: ${p.bills_100k || 0}`,
                    `$50.000: ${p.bills_50k || 0}`,
                    `$20.000: ${p.bills_20k || 0}`,
                    `$10.000: ${p.bills_10k || 0}`,
                    `$5.000: ${p.bills_5k || 0}`,
                    `$2.000: ${p.bills_2k || 0}`,
                    `Monedas: ${formatCurrency(p.coins)}`
                ].join('\n');

                body.push([
                    p.planilla,
                    formatCurrency(p.total),
                    formatCurrency(p.discrepancy),
                    p.operador,
                    desgloseText
                ]);
                totalContado += parseFloat(p.total);
                totalDiscrepancia += parseFloat(p.discrepancy);
            });

            body.push([
                { content: 'TOTALES', styles: { fontStyle: 'bold', halign: 'right' } },
                { content: formatCurrency(totalContado), styles: { fontStyle: 'bold' } },
                { content: formatCurrency(totalDiscrepancia), styles: { fontStyle: 'bold', textColor: totalDiscrepancia != 0 ? [220, 38, 38] : [22, 163, 74] } },
                { content: '', colSpan: 2 }
            ]);

            doc.setFontSize(18);
            doc.text(`Informe de Cierre de Fondo: ${fundName}`, 14, 22);
            doc.setFontSize(11);
            doc.text(`Cliente: ${clientName}`, 14, 30);
            doc.text(`Fecha de Generación: ${new Date().toLocaleDateString('es-CO')}`, 14, 36);

            doc.autoTable({
                head: head,
                body: body,
                startY: 42,
                headStyles: { fillColor: [29, 78, 216] },
                columnStyles: {
                    0: { cellWidth: 20 },
                    1: { cellWidth: 30, halign: 'right' },
                    2: { cellWidth: 30, halign: 'right' },
                    3: { cellWidth: 30 },
                    4: { fontSize: 8, cellWidth: 'auto' }
                }
            });

            const pageCount = doc.internal.getNumberOfPages();
            for(let i = 1; i <= pageCount; i++) {
                doc.setPage(i);
                doc.setFontSize(9);
                doc.text(`Generado por EAGLE 3.0 - Página ${i} de ${pageCount}`, 14, doc.internal.pageSize.height - 10);
            }

            doc.save(`Informe_Fondo_${fundName.replace(/ /g, '_')}.pdf`);

        } catch (error) {
            console.error('Error generando PDF:', error);
            alert('No se pudo generar el informe en PDF.');
        }
    }


    // --- Table Population & UI Update Functions ---
    function updateReminderCount() {
        const list = document.getElementById('reminders-list');
        const badge = document.getElementById('reminders-badge');
        if (!list || !badge) return; // Salir si los elementos no existen
        const count = list.getElementsByClassName('reminder-item').length;

        if (count > 0) {
            badge.textContent = count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
            list.innerHTML = '<p class="text-sm text-gray-500">No tienes recordatorios pendientes.</p>';
        }
    }
    function openModal(user = null) {
        userForm.reset();
        if (user) {
            modalTitle.textContent = 'Editar Usuario';
            userIdInput.value = user.id;
            document.getElementById('user-name').value = user.name;
            document.getElementById('user-email').value = user.email;
            document.getElementById('user-role').value = user.role;
            document.getElementById('user-gender').value = user.gender || ''; // Usar '' si es null
            userPasswordInput.required = false;
            passwordHint.textContent = 'Dejar en blanco para no cambiar.';
        } else {
            modalTitle.textContent = 'Agregar Nuevo Usuario';
            userIdInput.value = '';
            document.getElementById('user-gender').value = '';
            userPasswordInput.required = true;
            passwordHint.textContent = 'La contraseña es requerida.';
        }
        modalOverlay.classList.remove('hidden');
    }
    function populateUserTable(users) {
        const tbody = document.getElementById('user-table-body');
        if (!tbody) {
            console.error("Elemento tbody 'user-table-body' no encontrado.");
            return;
        }
        tbody.innerHTML = '';
        if (!users || users.length === 0) {
             tbody.innerHTML = '<tr><td colspan="5" class="p-6 text-center text-gray-500">No hay usuarios registrados.</td></tr>'; // Colspan a 5
             return;
        }
        users.forEach(user => {
             if (!user || typeof user !== 'object') {
                  console.warn("Dato de usuario inválido:", user);
                  return; // Saltar este usuario si es inválido
             }
            const safeUser = {
                id: user.id,
                name: user.name || '',
                email: user.email || '',
                role: user.role || '',
                gender: user.gender || ''
            };
            const userJson = JSON.stringify(safeUser).replace(/'/g, "&apos;");
            const displayRole = getRoleDisplayNameJS(safeUser.role, safeUser.gender);
            tbody.innerHTML += `
                <tr id="user-row-${safeUser.id}">
                    <td class="px-6 py-4">${safeUser.name}</td>
                    <td class="px-6 py-4">${safeUser.email}</td>
                    <td class="px-6 py-4"><span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">${displayRole}</span></td>
                    <td class="px-6 py-4 text-center">${safeUser.gender || 'N/A'}</td> <td class="px-6 py-4 text-center">
                        <button onclick='openModal(${userJson})' class="font-medium text-blue-600 hover:text-blue-800">Editar</button>
                        <button onclick="deleteUser(${safeUser.id})" class="font-medium text-red-600 hover:text-red-800 ml-4">Eliminar</button>
                    </td>
                </tr>`;
        });
    }
    function populateCheckinsTable(checkins) {
        // *** Esta función usa la LÓGICA RESTAURADA (solo 'Pendiente'/'Rechazado') ***
        const tbody = document.getElementById('checkins-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        const colspan = currentUserRole === 'Admin' ? 12 : 11;
        if (!checkins || checkins.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay registros de check-in pendientes o rechazados.</td></tr>`;
            return;
        }

        const thead = tbody.previousElementSibling;
        thead.innerHTML = `
            <tr>
                <th class="p-2 w-28">Corrección</th>
                <th class="p-2 w-28">Estado</th>
                <th class="p-2">Planilla</th>
                <th class="p-2">Sello</th>
                <th class="p-2">Declarado</th>
                <th class="p-2">Ruta</th>
                <th class="p-2">Fecha de Registro</th>
                <th class="p-2">Checkinero</th>
                <th class="p-2">Cliente</th>
                <th class="p-2">Fondo</th>
                <th class="p-2 w-20">Acciones</th>
                ${currentUserRole === 'Admin' ? '<th class="p-2 w-20">Admin</th>' : ''}
            </tr>
        `;

        checkins.forEach(ci => {
            let correctionBadge = (ci.correction_count > 0) ? `<span class="bg-red-100 text-red-800 text-xs font-bold px-2.5 py-1 rounded-full"> ${ci.correction_count}</span>` : '';
            let statusBadge = (ci.status === 'Rechazado') ? `<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-1 rounded-full">Rechazado</span>` : `<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-1 rounded-full">Pendiente</span>`;

            let actionButton = '';
             // Botón Editar: Solo para 'Rechazado', o 'Pendiente' si es Admin
            if (ci.status === 'Rechazado' || (currentUserRole === 'Admin' && ci.status === 'Pendiente')) {
                const checkinData = JSON.stringify(ci).replace(/"/g, '&quot;');
                actionButton = `<button onclick='editCheckIn(${checkinData})' class="bg-blue-500 text-white px-3 py-1 text-xs font-semibold rounded-md hover:bg-blue-600">Editar</button>`;
            }

            const adminDeleteButton = currentUserRole === 'Admin' ? `<td class="p-2"><button onclick="deleteCheckIn(${ci.id})" class="text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button></td>` : '';

            tbody.innerHTML += `
                <tr class="border-b hover:bg-gray-50">
                    <td class="p-2">${correctionBadge}</td>
                    <td class="p-2">${statusBadge}</td>
                    <td class="p-2 font-mono">${ci.invoice_number}</td>
                    <td class="p-2 font-mono">${ci.seal_number}</td>
                    <td class="p-2 text-right">${formatCurrency(ci.declared_value)}</td>
                    <td class="p-2">${ci.route_name}</td>
                    <td class="p-2 text-xs whitespace-nowrap">${new Date(ci.created_at).toLocaleString('es-CO')}</td>
                    <td class="p-2">${ci.checkinero_name}</td>
                    <td class="p-2">${ci.client_name}</td>
                    <td class="p-2">${ci.fund_name || 'N/A'}</td>
                    <td class="p-2">${actionButton}</td>
                    ${adminDeleteButton}
                </tr>`;
        });
    }
    function populateOperatorCheckinsTable(checkins) {
        // Esta función ahora filtra desde los checkins generales
        const tbody = document.getElementById('operator-checkins-table-body');
        if (!tbody) return;

        tbody.innerHTML = '';
        const pendingCheckins = checkins.filter(ci => ci.status === 'Pendiente'); // Solo pendientes para el operador

        if (pendingCheckins.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay planillas pendientes por detallar.</td></tr>';
            return;
        }

        const thead = tbody.previousElementSibling;
        thead.innerHTML = `
            <tr>
                <th class="p-3">Planilla</th><th class="p-3">Sello</th><th class="p-3">Declarado</th>
                <th class="p-3">Cliente</th><th class="p-3">Checkinero</th><th class="p-3">Fecha de Registro</th>
                <th class="p-3">Estado</th><th class="p-3">Acción</th>
            </tr>
        `;

        pendingCheckins.forEach(ci => {
            const row = `
                <tr class="border-b">
                    <td class="p-3 font-mono">${ci.invoice_number}</td>
                    <td class="p-3 font-mono">${ci.seal_number}</td>
                    <td class="p-3 text-right">${formatCurrency(ci.declared_value)}</td>
                    <td class="p-3">${ci.client_name}</td>
                    <td class="p-3">${ci.checkinero_name}</td>
                    <td class="p-3 text-xs whitespace-nowrap">${new Date(ci.created_at).toLocaleString('es-CO')}</td>
                    <td class="p-3"><span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-1 rounded-full">${ci.status}</span></td>
                    <td class="p-3">
                        <button onclick="selectPlanilla('${ci.invoice_number}')" class="bg-blue-500 text-white px-3 py-1 text-xs font-semibold rounded-md hover:bg-blue-600">Seleccionar</button>
                    </td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }
    function populateOperatorHistoryTable(historyData) {
        const tbody = document.getElementById('operator-history-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';

        const showOperatorColumn = (currentUserRole === 'Admin' || currentUserRole === 'Digitador');
        const colspan = showOperatorColumn ? (currentUserRole === 'Admin' ? 9 : 8) : 7;

        if (!historyData || historyData.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay conteos registrados.</td></tr>`; return;
        }

        historyData.forEach(item => {
            const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            const operatorColumn = showOperatorColumn ? `<td class="p-3">${item.operator_name}</td>` : '';
            const adminDeleteButton = currentUserRole === 'Admin' ? `<td class="p-3 text-center"><button onclick="deleteCheckIn(${item.check_in_id})" class="text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button></td>` : '';

            tbody.innerHTML += `<tr class="border-b">
                                    <td class="p-3 font-mono">${item.invoice_number}</td>
                                    <td class="p-3">${item.client_name}</td>
                                    <td class="p-3 text-right">${formatCurrency(item.declared_value)}</td>
                                    <td class="p-3 text-right">${formatCurrency(item.total_counted)}</td>
                                    <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(item.discrepancy)}</td>
                                    ${operatorColumn}
                                    <td class="p-3 text-xs whitespace-nowrap">${new Date(item.count_date).toLocaleString('es-CO')}</td>
                                    <td class="p-3 text-xs max-w-xs truncate" title="${item.observations || ''}">${item.observations || 'N/A'}</td>
                                    ${adminDeleteButton}
                               </tr>`;
        });
    }
    function populateUserHistoryTable(tasks) {
        const tbody = document.getElementById('historial-individual-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!tasks || tasks.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="p-6 text-center text-gray-500">No has completado ninguna tarea todavía.</td></tr>'; // Colspan a 10
            return;
        }

        tasks.forEach(task => {
             const completedBy = task.completed_by || 'N/A';
             const createdBy = task.created_by_name || 'Sistema';

            tbody.innerHTML += `<tr class="border-b">
                                    <td class="px-6 py-4 font-medium">${task.title || ''}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.instruction || ''}">${task.instruction || ''}</td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.priority)}">${task.priority || ''}</span></td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.final_priority)}">${task.final_priority || ''}</span></td>
                                    <td class="px-6 py-4">${createdBy}</td>
                                    <td class="px-6 py-4 font-semibold">${completedBy}</td> <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.created_at)}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.completed_at)}</td>
                                    <td class="px-6 py-4 font-mono">${task.response_time || ''}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.resolution_note || ''}">${task.resolution_note || 'N/A'}</td>
                               </tr>`;
        });
    }
    function populateOperatorHistoryForDigitador(history) {
        // Esta función ahora debe filtrar desde $operator_history
        const tbody = document.getElementById('operator-history-table-body-digitador');
        if (!tbody) return;
        tbody.innerHTML = '';

        // CORRECCIÓN: Usar $digitadorClosedHistory para saber qué *no* mostrar.
        // Un item está 'pending review' si está en operatorHistory PERO no está en digitadorClosedHistory.
        const closedCheckInIds = new Set(digitadorClosedHistory.map(dh => parseInt(dh.id))); // Asegurarse que sean enteros

        const pendingReview = history.filter(item => {
            // Si el check_in_id NO está en el set de cerrados, está pendiente de revisión.
            // Asegurarse de comparar enteros
            return !closedCheckInIds.has(parseInt(item.check_in_id));
        });

        if (!pendingReview || pendingReview.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay conteos pendientes de supervisión.</td></tr>`;
            return;
        }

        pendingReview.forEach(item => {
            const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            const itemInfo = JSON.stringify(item).replace(/"/g, '&quot;');
            tbody.innerHTML += `<tr class="border-b hover:bg-gray-50">
                                    <td class="p-3 text-center"><input type="checkbox" class="review-checkbox h-5 w-5 rounded-md" data-info="${itemInfo}" onchange="flagForReview(this)"></td>
                                    <td class="p-3 font-mono">${item.invoice_number}</td>
                                    <td class="p-3">${item.client_name}</td>
                                    <td class="p-3 text-right">${formatCurrency(item.declared_value)}</td>
                                    <td class="p-3 text-right">${formatCurrency(item.total_counted)}</td>
                                    <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(item.discrepancy)}</td>
                                    <td class="p-3">${item.operator_name}</td>
                                    <td class="p-3 text-xs">${new Date(item.count_date).toLocaleString('es-CO')}</td>
                                </tr>`;
        });
    }
    function populateDigitadorClosedHistory(history) {
        const tbody = document.getElementById('digitador-closed-history-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        const showCerradaPor = (currentUserRole === 'Admin' || currentUserRole === 'Digitador');
        const colspan = showCerradaPor ? 9 : 8; // Adjust colspan
        if (!history || history.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay historial de planillas cerradas.</td></tr>`;
            return;
        }
        history.forEach(item => {
            const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            let statusBadge = '';
            if (item.digitador_status === 'Conforme') statusBadge = `<span class="text-xs font-medium px-2.5 py-1 rounded-full bg-green-100 text-green-800">Conforme</span>`;
            else if (item.digitador_status === 'Cerrado') statusBadge = `<span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">Cerrado</span>`;
            else if (item.digitador_status === 'Rechazado') statusBadge = `<span class="bg-orange-100 text-orange-800 text-xs font-medium px-2.5 py-1 rounded-full">Rechazado</span>`; // Added Rechazado
            else statusBadge = `<span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-1 rounded-full">${item.digitador_status || 'N/A'}</span>`;

            let actionButtons = `<button onclick="toggleBreakdown('hist-${item.id}')" class="text-blue-600 text-xs font-semibold">Desglose</button>`;
            if (currentUserRole === 'Admin') actionButtons += `<button onclick="deleteCheckIn(${item.id})" class="text-red-500 hover:text-red-700 font-semibold text-xs ml-2">Eliminar</button>`;

            const cerradaPorCol = showCerradaPor ? `<td class="p-3">${item.digitador_name || 'N/A'}</td>` : '';

            // Corregir el ID del desglose para que sea único
            tbody.innerHTML += `
                <tr class="border-b hover:bg-gray-50">
                    <td class="p-3 font-mono">${item.invoice_number}</td>
                    <td class="p-3">${item.fund_name || 'N/A'}</td>
                    <td class="p-3 text-xs">${item.closed_by_digitador_at ? formatDate(item.closed_by_digitador_at) : 'N/A'}</td>
                    <td class="p-3 text-right font-mono">${formatCurrency(item.total_counted)}</td>
                    <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(item.discrepancy)}</td>
                    <td class="p-3">${statusBadge}</td>
                    <td class="p-3 text-xs max-w-xs truncate" title="${item.digitador_observations || ''}">${item.digitador_observations || 'N/A'}</td>
                    ${cerradaPorCol}
                    <td class="p-3 text-center">${actionButtons}</td>
                </tr>
                 <tr class="details-row hidden" id="breakdown-row-hist-${item.id}">
                    <td colspan="${colspan}" class="p-0"><div id="breakdown-content-hist-${item.id}" class="cash-breakdown bg-gray-50"><div class="p-4 grid grid-cols-3 sm:grid-cols-7 gap-x-8 gap-y-2 text-xs"><span><strong>$100.000:</strong> ${item.bills_100k||0}</span><span><strong>$50.000:</strong> ${item.bills_50k||0}</span><span><strong>$20.000:</strong> ${item.bills_20k||0}</span><span><strong>$10.000:</strong> ${item.bills_10k||0}</span><span><strong>$5.000:</strong> ${item.bills_5k||0}</span><span><strong>$2.000:</strong> ${item.bills_2k||0}</span><span class="font-bold">Monedas: ${formatCurrency(item.coins)}</span></div></div></td>
                </tr>
           `;
        });
    }

    function flagForReview(checkbox) {
        const panel = document.getElementById('operator-panel-digitador');
        document.querySelectorAll('.review-checkbox').forEach(cb => { if (cb !== checkbox) cb.checked = false; });
        if (checkbox.checked) {
            const data = JSON.parse(checkbox.dataset.info);
            const discrepancyClass = data.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            panel.innerHTML = `<div class="flex justify-between items-start"><h3 class="text-xl font-semibold mb-4 text-blue-800">Planilla en Revisión: ${data.invoice_number}</h3><button onclick="closeReviewPanel()" class="text-gray-500 hover:text-red-600 font-bold text-2xl">&times;</button></div><div class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm border-t pt-4 mt-2"><p><strong>Cliente:</strong><br>${data.client_name}</p><p><strong>Operador:</strong><br>${data.operator_name}</p><p><strong>V. Declarado:</strong><br>${formatCurrency(data.declared_value)}</p><p><strong>V. Contado:</strong><br>${formatCurrency(data.total_counted)}</p><p><strong class="${discrepancyClass}">Discrepancia:</strong><br><span class="${discrepancyClass}">${formatCurrency(data.discrepancy)}</span></p><p class="col-span-2 lg:col-span-3"><strong>Obs. del Operador:</strong><br>${data.observations || 'Sin observaciones'}</p></div><div class="mt-6 border-t pt-4"><h4 class="text-md font-semibold mb-2">Decisión de Cierre</h4><div><label for="digitador-observations" class="block text-sm font-medium text-gray-700">Observaciones Finales (Requerido)</label><textarea id="digitador-observations" rows="3" class="mt-1 w-full border rounded-md p-2 shadow-sm" placeholder="Escriba aquí el motivo de la aprobación o rechazo..."></textarea></div><div class="mt-4 flex space-x-4"><button onclick="submitDigitadorReview(${data.check_in_id}, 'Rechazado')" class="w-full bg-red-600 text-white font-bold py-2 px-4 rounded-md hover:bg-red-700">Rechazar Conteo</button><button onclick="submitDigitadorReview(${data.check_in_id}, 'Conforme')" class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded-md hover:bg-green-700">Aprobar (Conforme)</button></div></div>`;
            panel.classList.remove('hidden');
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else { panel.classList.add('hidden'); }
    }
    function closeReviewPanel() { document.getElementById('operator-panel-digitador').classList.add('hidden'); document.querySelectorAll('.review-checkbox').forEach(cb => cb.checked = false); }
    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
    function populateTrazabilidadTable(tasks) {
        const tbody = document.getElementById('trazabilidad-tbody');
         if (!tbody) return;
        tbody.innerHTML = '';
        if (!tasks || tasks.length === 0) { tbody.innerHTML = '<tr><td colspan="12" class="p-6 text-center text-gray-500">No hay tareas que coincidan con los filtros.</td></tr>'; return; }

        tasks.forEach(task => {
            let assignedTo = '';
            if (task.assigned_to_group) {
                assignedTo = `<span class="font-medium text-purple-700">Grupo ${task.assigned_to_group}</span>`;
            } else if (task.assigned_to) {
                assignedTo = task.assigned_to;
            }

            tbody.innerHTML += `<tr class="border-b">
                                    <td class="px-6 py-4 font-medium">${task.title || ''}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.instruction || ''}">${task.instruction || ''}</td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.priority)}">${task.priority || ''}</span></td>
                                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.final_priority)}">${task.final_priority || ''}</span></td>
                                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.created_at)}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.completed_at)}</td>
                                    <td class="px-6 py-4 font-mono">${task.response_time || ''}</td>
                                    <td class="px-6 py-4">${assignedTo || 'N/A'}</td>
                                    <td class="px-6 py-4">${task.created_by_name || 'Sistema'}</td>
                                    <td class="px-6 py-4 font-semibold">${task.completed_by || 'N/A'}</td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.resolution_note || ''}">${task.resolution_note || 'N/A'}</td>
                                    <td class="px-6 py-4 text-center"><button onclick="deleteTask(${task.id})" class="text-red-500 hover:text-red-700 font-semibold text-xs">Eliminar</button></td>
                               </tr>`;
        });
    }
    function applyTrazabilidadFilters() {
         const startDate = document.getElementById('filter-start-date').value, endDate = document.getElementById('filter-end-date').value, user = document.getElementById('filter-user').value, checker = document.getElementById('filter-checker').value, priority = document.getElementById('filter-priority').value;
         currentFilteredTrazabilidadData = completedTasksData.filter(task => {
             let isValid = true;
             const taskStartDate = task.created_at ? task.created_at.split(' ')[0] : null;
             const taskEndDate = task.completed_at ? task.completed_at.split(' ')[0] : null;

             if (startDate && taskEndDate && taskEndDate < startDate) isValid = false;
             if (endDate && taskEndDate && taskEndDate > endDate) isValid = false;
             if (startDate && !endDate && taskEndDate && taskEndDate < startDate) isValid = false;
             if (!startDate && endDate && taskEndDate && taskEndDate > endDate) isValid = false;

             if (user && task.assigned_to !== user) isValid = false;
             if (checker && task.completed_by !== checker) isValid = false;
             if (priority && task.final_priority !== priority) isValid = false;
             return isValid;
         });
          document.querySelectorAll('th.sortable').forEach(th => {
              delete th.dataset.sortDir;
              const iconSpan = th.querySelector('span');
              if (iconSpan) iconSpan.textContent = '';
          });
         populateTrazabilidadTable(currentFilteredTrazabilidadData);
     }
    function sortTableByDate(column) {
        const header = document.querySelector(`th[data-column-name="${column}"]`);
         if (!header) return;
        const currentDirection = header.dataset.sortDir || 'none';
        const nextDirection = (currentDirection === 'desc') ? 'asc' : 'desc';

         const dataToSort = (currentFilteredTrazabilidadData.length > 0) ? [...currentFilteredTrazabilidadData] : [...completedTasksData];

        dataToSort.sort((a, b) => {
            const timeA = a[column] ? new Date(a[column]).getTime() : 0;
            const timeB = b[column] ? new Date(b[column]).getTime() : 0;

            if (isNaN(timeA) && isNaN(timeB)) return 0;
            if (isNaN(timeA)) return 1;
            if (isNaN(timeB)) return -1;

            return nextDirection === 'asc' ? timeA - timeB : timeB - timeA;
        });

        document.querySelectorAll('th.sortable').forEach(th => {
            const iconSpan = th.querySelector('span');
             if (!iconSpan) return;
            if (th === header) {
                th.dataset.sortDir = nextDirection;
                iconSpan.textContent = nextDirection === 'asc' ? ' ▲' : ' ▼';
                 iconSpan.classList.remove('text-gray-400');
            } else {
                delete th.dataset.sortDir;
                iconSpan.textContent = '';
                 iconSpan.classList.add('text-gray-400');
            }
        });

         populateTrazabilidadTable(dataToSort);
         currentFilteredTrazabilidadData = dataToSort;
    }
    function exportToExcel() { const table = document.getElementById("trazabilidad-table"), wb = XLSX.utils.table_to_book(table, { sheet: "Trazabilidad" }); XLSX.writeFile(wb, "Trazabilidad_EAGLE.xlsx"); }
    <?php endif; ?>

    // --- Operator Panel Specific ---
    function selectPlanilla(invoiceNumber) {
        document.getElementById('consult-invoice').value = invoiceNumber;
        document.getElementById('consultation-form').dispatchEvent(new Event('submit'));
        window.scrollTo(0, 0);
    }
    async function editCheckIn(checkinData) {
        document.getElementById('invoice_number').value = checkinData.invoice_number;
        document.getElementById('seal_number').value = checkinData.seal_number;
        document.getElementById('declared_value').value = checkinData.declared_value;
        document.getElementById('check_in_id_field').value = checkinData.id;

        const clientSelect = document.getElementById('client_id');
        clientSelect.value = checkinData.client_id;

        // Trigger change and wait for funds to load
        await new Promise(resolve => {
            clientSelect.dispatchEvent(new Event('change'));
            // Wait a bit for the async fund loading to potentially finish
            setTimeout(resolve, 300); // Adjust timeout if needed
        });

        // Now set the fund and route
        document.getElementById('fund_id').value = checkinData.fund_id;
        document.getElementById('route_id').value = checkinData.route_id;

        // Update form title and buttons
        document.getElementById('checkin-form-title').textContent = 'Corregir Check-in';
        const buttonsContainer = document.getElementById('checkin-form-buttons');
        buttonsContainer.innerHTML = `
            <button type="submit" id="checkin-submit-button" class="w-full bg-orange-500 text-white font-bold py-3 rounded-md hover:bg-orange-600">Guardar Corrección</button>
            <button type="button" onclick="cancelEdit()" class="w-full bg-gray-300 text-gray-800 font-bold py-3 rounded-md hover:bg-gray-400">Cancelar</button>
        `;

        // Scroll to the form
        document.getElementById('checkin-form-title').scrollIntoView({ behavior: 'smooth' });
    }
    function cancelEdit() {
        document.getElementById('checkin-form').reset();
        document.getElementById('check_in_id_field').value = '';
        document.getElementById('checkin-form-title').textContent = 'Registrar Nuevo Check-in';
        const buttonsContainer = document.getElementById('checkin-form-buttons');
        buttonsContainer.innerHTML = `
            <button type="submit" id="checkin-submit-button" class="w-full bg-green-600 text-white font-bold py-3 rounded-md hover:bg-green-700">Agregar Check-in</button>
        `;
        // Reset fund dropdown
        const fundSelect = document.getElementById('fund_id');
        fundSelect.innerHTML = '<option value="">Seleccione un cliente primero...</option>';
        fundSelect.disabled = true;
        fundSelect.classList.add('bg-gray-200');
    }
    function updateQty(button, amount) {
        const input = button.parentElement.querySelector('input');
        let currentValue = parseInt(input.value) || 0;
        currentValue += amount;
        if (currentValue < 0) currentValue = 0;
        input.value = currentValue;
        calculateTotals();
    }
    function calculateTotals() {
        const form = document.getElementById('denomination-form');
        if (!form) return;
        let totalCounted = 0;
        form.querySelectorAll('.denomination-row').forEach(row => {
            totalCounted += (parseInt(row.querySelector('.denomination-qty').value) || 0) * parseFloat(row.dataset.value);
            row.querySelector('.subtotal').textContent = formatCurrency((parseInt(row.querySelector('.denomination-qty').value) || 0) * parseFloat(row.dataset.value));
        });
        const coinsValue = parseFloat(document.getElementById('coins-value').value) || 0;
        document.getElementById('coins-subtotal').textContent = formatCurrency(coinsValue);
        totalCounted += coinsValue;
        document.getElementById('total-counted').textContent = formatCurrency(totalCounted);
        const declaredValue = parseFloat(document.getElementById('display-declared').dataset.value) || 0;
        const discrepancy = totalCounted - declaredValue;
        const discrepancyEl = document.getElementById('discrepancy');
        discrepancyEl.textContent = formatCurrency(discrepancy);
        discrepancyEl.classList.toggle('text-red-500', discrepancy !== 0);
        discrepancyEl.classList.toggle('text-green-500', discrepancy === 0);
    }

    // --- Alert Pop-up & Polling ---
    function showAlertPopup(alertData) {
        if (!alertPopupOverlay || !alertPopup || !alertPopupTitle || !alertPopupDescription || !alertPopupHeader) return;
        alertPopupTitle.textContent = `¡${alertData.priority}! ${alertData.title || 'Nueva Alerta'}`;
        alertPopupDescription.textContent = alertData.description || alertData.instruction || 'Revisa tus tareas pendientes.';
        alertPopupHeader.className = 'p-4 border-b rounded-t-lg'; // Reset classes
        alertPopupTitle.className = 'text-xl font-bold'; // Reset classes
        if (alertData.priority === 'Critica') {
            alertPopupHeader.classList.add('bg-red-100', 'border-red-200');
            alertPopupTitle.classList.add('text-red-800');
        } else { // Alta
            alertPopupHeader.classList.add('bg-orange-100', 'border-orange-200');
            alertPopupTitle.classList.add('text-orange-800');
         }
        alertPopupOverlay.classList.remove('hidden');
        setTimeout(() => {
            alertPopup.classList.remove('scale-95', 'opacity-0');
            alertPopup.classList.add('scale-100', 'opacity-100');
        }, 50);
    }

    async function checkForNewAlerts() {
        try {
            const response = await fetch(`api/realtime_alerts_api.php?since=${lastCheckedAlertTime}`);
            if (!response.ok && response.status !== 304) { console.error("Error checking alerts:", response.statusText); return; }
            if (response.status === 304) return; // Not Modified

            const result = await response.json();
            if (result.success && result.alerts && result.alerts.length > 0) {
                showAlertPopup(result.alerts[0]);
                // Podríamos recargar o actualizar los badges aquí si es necesario
                // location.reload(); // Opcional: forzar recarga
            }
            lastCheckedAlertTime = result.timestamp || Math.floor(Date.now() / 1000);
        } catch (error) { console.error("Network error checking alerts:", error); }
    }

    function startAlertPolling(intervalSeconds = 20) {
        if (alertPollingInterval) clearInterval(alertPollingInterval);
        checkForNewAlerts();
        alertPollingInterval = setInterval(checkForNewAlerts, intervalSeconds * 1000);
        console.log(`Alert polling started every ${intervalSeconds} seconds.`);
    }

    function stopAlertPolling() {
        if (alertPollingInterval) { clearInterval(alertPollingInterval); alertPollingInterval = null; console.log("Alert polling stopped."); }
    }

     // --- Check-in Polling Functions ---
     async function fetchAndUpdateCheckins() {
         try {
             const response = await fetch('api/realtime_checkins_api.php'); // Llama a la nueva API
             if (!response.ok) {
                 if (response.status === 401) { console.warn("Polling detenido: No autenticado."); stopCheckinPolling(); }
                 return;
             }
             const result = await response.json();
             if (result.success && result.checkins) {
                 // Comparar si la data cambió
                 if (JSON.stringify(result.checkins) !== JSON.stringify(currentCheckinData)) {
                     console.log("Checkin data changed, updating table.");
                     currentCheckinData = result.checkins; // Actualizar la data global

                     // Solo redibujar si la pestaña 'checkinero' está activa
                     if (!document.getElementById('content-checkinero').classList.contains('hidden')) {
                         populateCheckinsTable(currentCheckinData);
                     }
                     // Actualizar la vista del Admin en el panel de Operador también
                     if (currentUserRole === 'Admin' && document.getElementById('operator-checkins-table-body')) {
                         populateOperatorCheckinsTable(currentCheckinData);
                     }
                 }
             } else if (!result.success) { console.error("API error fetching checkins:", result.error); }
         } catch (error) { console.error("Network error fetching checkins:", error); }
     }
     function startCheckinPolling(intervalSeconds = 10) {
         if (checkinPollingInterval) clearInterval(checkinPollingInterval);
         fetchAndUpdateCheckins(); // Fetch immediately
         checkinPollingInterval = setInterval(fetchAndUpdateCheckins, intervalSeconds * 1000);
         console.log(`Checkin polling started every ${intervalSeconds} seconds.`);
     }
     function stopCheckinPolling() {
         if (checkinPollingInterval) { clearInterval(checkinPollingInterval); checkinPollingInterval = null; console.log("Checkin polling stopped."); }
     }
     // Stop polling on page unload
     window.addEventListener('beforeunload', () => {
         stopAlertPolling();
         stopCheckinPolling();
     });

    // --- Tab Switching & Dynamic Content Loading (NUEVA FUNCIÓN) ---
    async function switchTab(tabName) {
        sessionStorage.setItem('activeTab', tabName);

        const staticContentPanels = ['operaciones', 'checkinero', 'operador', 'digitador', 'mi-historial', 'roles', 'trazabilidad'];
        const dynamicContentPanels = ['manage-clients', 'manage-routes', 'manage-funds'];
        const allContentPanels = staticContentPanels.concat(dynamicContentPanels);

        document.querySelectorAll('.nav-tab, .header-panel-button').forEach(t => t.classList.remove('active'));
        allContentPanels.forEach(panel => document.getElementById(`content-${panel}`)?.classList.add('hidden'));

        const activeContent = document.getElementById(`content-${tabName}`);
        const activeTabElement = document.getElementById(`tab-${tabName}`);

        if (activeContent) {
            activeContent.classList.remove('hidden');
            activeTabElement?.classList.add('active'); // Activate the button/tab

            // --- INICIO DE LÓGICA DE POLLING ---
            // Detener polling de checkin por defecto
            stopCheckinPolling();
            // (El polling de alertas sigue corriendo, es global)

            // Iniciar polling específico si estamos en la pestaña correcta
            if (tabName === 'checkinero' || (tabName === 'operador' && currentUserRole === 'Admin')) {
                 startCheckinPolling(10); // Inicia en checkinero Y en operador (si es admin)
            }
            // --- FIN DE LÓGICA DE POLLING ---


            if (dynamicContentPanels.includes(tabName) && !loadedContent[tabName]) {
                activeContent.innerHTML = '<div class="loader"></div><p class="text-center text-gray-500">Cargando...</p>'; // Loading indicator
                try {
                    let phpFile = '';
                    if (tabName === 'manage-clients') phpFile = 'manage_clients.php';
                    else if (tabName === 'manage-routes') phpFile = 'manage_routes.php';
                    else if (tabName === 'manage-funds') phpFile = 'manage_funds.php';

                    if (phpFile) {
                        const response = await fetch(`${phpFile}?content_only=1`);
                        if (!response.ok) throw new Error(`Failed to load ${phpFile}: ${response.statusText}`);
                        const htmlContent = await response.text();
                        activeContent.innerHTML = htmlContent;
                        loadedContent[tabName] = true;

                        // Re-execute scripts
                        activeContent.querySelectorAll('script').forEach(script => {
                            try {
                                const newScript = document.createElement('script');
                                newScript.textContent = script.textContent;
                                script.parentNode.replaceChild(newScript, script);
                            } catch (e) { console.error("Error executing dynamic script:", e); }
                        });
                   }
                } catch (error) {
                    console.error("Error loading dynamic content:", error);
                    activeContent.innerHTML = `<p class="text-center p-8 text-red-500">Error: ${error.message}</p>`;
                 }
            }
        } else { console.error(`Content container 'content-${tabName}' not found.`); }
    }

    // --- Initialization ---
    document.addEventListener('DOMContentLoaded', () => {
        const savedTab = sessionStorage.getItem('activeTab') || 'operaciones'; // Default to operations

        // Initial population of static tables based on role and existence
        if (document.getElementById('user-table-body') && currentUserRole === 'Admin') populateUserTable(adminUsersData);
        if (document.getElementById('historial-individual-tbody')) populateUserHistoryTable(userCompletedTasksData);
        if (currentUserRole === 'Admin' && document.getElementById('trazabilidad-tbody')) {
            currentFilteredTrazabilidadData = [...completedTasksData];
            populateTrazabilidadTable(currentFilteredTrazabilidadData);
        }
        if (document.getElementById('content-checkinero')) {
             populateCheckinsTable(initialCheckins); // Poblar con los datos iniciales
             document.getElementById('checkin-form')?.addEventListener('submit', handleCheckinSubmit);
             const clientSelect = document.getElementById('client_id');
             const fundSelect = document.getElementById('fund_id');
             clientSelect?.addEventListener('change', async () => {
                const clientId = clientSelect.value; fundSelect.innerHTML = '<option value="">Cargando...</option>'; fundSelect.disabled = true; fundSelect.classList.add('bg-gray-200');
                if (!clientId) { fundSelect.innerHTML = '<option value="">Seleccione un cliente primero...</option>'; return; }
                try {
                    const response = await fetch(`api/funds_api.php?client_id=${clientId}`); const funds = await response.json(); fundSelect.innerHTML = '<option value="">Seleccione fondo...</option>'; // Added default option
                    if (funds.length > 0) { funds.forEach(fund => { fundSelect.add(new Option(fund.name, fund.id)); }); fundSelect.disabled = false; fundSelect.classList.remove('bg-gray-200'); }
                    else { fundSelect.innerHTML = '<option value="">Este cliente no tiene fondos</option>'; }
                } catch (error) { console.error('Error fetching funds:', error); fundSelect.innerHTML = '<option value="">Error al cargar fondos</option>'; }
            });
        }
        if (document.getElementById('content-operador')) {
            if (currentUserRole === 'Admin') populateOperatorCheckinsTable(initialCheckins); // Poblar con datos iniciales
            populateOperatorHistoryTable(operatorHistoryData);
            document.getElementById('consultation-form')?.addEventListener('submit', handleConsultation);
            document.getElementById('denomination-form')?.addEventListener('submit', handleDenominationSave);
        }
        if (document.getElementById('content-digitador')) {
            // --- LÓGICA PARA SUB-PESTAÑAS DEL DIGITADOR ---
            const btnSupervision = document.getElementById('btn-supervision');
            const btnCierre = document.getElementById('btn-cierre');
            const btnHistorialCierre = document.getElementById('btn-historial-cierre');
            const btnInformes = document.getElementById('btn-informes');
            const panelSupervision = document.getElementById('panel-supervision');
            const panelCierre = document.getElementById('panel-cierre');
            const panelHistorialCierre = document.getElementById('panel-historial-cierre');
            const panelInformes = document.getElementById('panel-informes');
            const digitadorSubPanels = [panelSupervision, panelCierre, panelHistorialCierre, panelInformes];
            const digitadorSubButtons = [btnSupervision, btnCierre, btnHistorialCierre, btnInformes];

            const setActiveDigitadorButton = (activeBtn) => {
                digitadorSubButtons.forEach(btn => { if(btn) { btn.classList.remove('bg-blue-600', 'text-white'); btn.classList.add('bg-gray-200', 'text-gray-700'); } });
                if(activeBtn) { activeBtn.classList.add('bg-blue-600', 'text-white'); activeBtn.classList.remove('bg-gray-200', 'text-gray-700'); }
            };
            const showDigitadorPanel = (activePanel) => {
                digitadorSubPanels.forEach(panel => { if(panel) panel.classList.add('hidden'); });
                if (activePanel) activePanel.classList.remove('hidden');
            };

            btnSupervision.addEventListener('click', () => { setActiveDigitadorButton(btnSupervision); showDigitadorPanel(panelSupervision); sessionStorage.setItem('activeDigitadorSubTab', 'supervision'); });
            btnCierre.addEventListener('click', () => { setActiveDigitadorButton(btnCierre); showDigitadorPanel(panelCierre); sessionStorage.setItem('activeDigitadorSubTab', 'cierre'); loadFundsForCierre(); });
            btnHistorialCierre.addEventListener('click', () => { setActiveDigitadorButton(btnHistorialCierre); showDigitadorPanel(panelHistorialCierre); sessionStorage.setItem('activeDigitadorSubTab', 'historial'); /* Podrías recargar aquí si es necesario */ });
            btnInformes.addEventListener('click', () => { setActiveDigitadorButton(btnInformes); showDigitadorPanel(panelInformes); sessionStorage.setItem('activeDigitadorSubTab', 'informes'); loadInformes(); });

            const savedSubTab = sessionStorage.getItem('activeDigitadorSubTab');
            if (savedSubTab === 'supervision' && btnSupervision) { btnSupervision.click(); }
            else if (savedSubTab === 'historial' && btnHistorialCierre) { btnHistorialCierre.click(); }
            else if (savedSubTab === 'informes' && btnInformes) { btnInformes.click(); }
            else if (btnCierre) { btnCierre.click(); } // Default to Cierre
            else if (btnSupervision) { btnSupervision.click(); } // Fallback

            populateOperatorHistoryForDigitador(operatorHistoryData);
            populateDigitadorClosedHistory(digitadorClosedHistory);
        }

        switchTab(savedTab); // Activa la pestaña inicial (y el polling si corresponde)

        updateReminderCount();
        startAlertPolling(20); // Start checking for new alerts (Global)

        // Countdown timer interval
        setInterval(() => {
            document.querySelectorAll('.countdown-timer').forEach(timerEl => {
                const endTimeStr = timerEl.dataset.endTime;
                 if (!endTimeStr) return;
                try {
                     const endTime = new Date(endTimeStr.replace(' ', 'T')).getTime();
                     if (isNaN(endTime)) {
                          timerEl.textContent = 'Fecha inválida';
                          return;
                     }
                     const now = new Date().getTime();
                     const distance = endTime - now;

                     if (distance < 0) {
                          const elapsed = now - endTime;
                          const days = Math.floor(elapsed / 864e5);
                          const hours = Math.floor((elapsed % 864e5) / 36e5);
                          const minutes = Math.floor((elapsed % 36e5) / 6e4);
                          const seconds = Math.floor((elapsed % 6e4) / 1e3);
                          let elapsedTime = '';
                          if (days > 0) elapsedTime += `${days}d `;
                          if (hours > 0 || days > 0) elapsedTime += `${hours}h `;
                          elapsedTime += `${minutes}m ${seconds}s`;
                          timerEl.innerHTML = `Retraso: <span class="text-red-600 font-bold">${elapsedTime}</span>`;
                     } else {
                          const days = Math.floor(distance / 864e5);
                          const hours = Math.floor((distance % 864e5) / 36e5);
                          const minutes = Math.floor((distance % 36e5) / 6e4);
                          const seconds = Math.floor((distance % 6e4) / 1e3);
                          let timeLeft = '';
                          if (days > 0) timeLeft += `${days}d `;
                          if (hours > 0 || days > 0) timeLeft += `${hours}h `;
                          timeLeft += `${minutes}m ${seconds}s`;
                          let textColor = 'text-green-600';
                          if (days === 0 && hours < 1) textColor = 'text-red-600';
                          else if (days === 0 && hours < 24) textColor = 'text-yellow-700';
                          timerEl.innerHTML = `Vence en: <span class="${textColor}">${timeLeft}</span>`;
                     }
                } catch (e) {
                     console.error("Error parsing date for countdown:", endTimeStr, e);
                     timerEl.textContent = 'Error fecha';
                }
            });
        }, 1000);
        // Manual task date input logic
        const startDateInput = document.getElementById('manual-task-start'), endDateInput = document.getElementById('manual-task-end');
        if(startDateInput && endDateInput) {
            const getLocalISOString = (date) => {
                 if (!(date instanceof Date) || isNaN(date)) return '';
                 const pad = (num) => num.toString().padStart(2, '0');
                 const localDate = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
                 try {
                      return localDate.toISOString().slice(0, 16);
                 } catch (e) {
                      console.error("Error formatting date:", e);
                      return '';
                 }
             };
            const now = new Date();
             const nowString = getLocalISOString(now);

             startDateInput.min = nowString;
             endDateInput.min = nowString;

            startDateInput.addEventListener('input', () => {
                 if (startDateInput.value) {
                      endDateInput.min = startDateInput.value;
                      if (endDateInput.value && endDateInput.value < startDateInput.value) {
                           endDateInput.value = startDateInput.value;
                      }
                 } else {
                     endDateInput.min = nowString;
                 }
            });
             endDateInput.addEventListener('input', () => {
                  if (startDateInput.value && endDateInput.value && endDateInput.value < startDateInput.value) {
                       endDateInput.value = startDateInput.value;
                  }
             });
        }
    });
    </script>
    </body>
</html>
<?php
session_start();
require 'check_session.php';
require 'db_connection.php';

// Establecer la zona horaria correcta para Colombia
date_default_timezone_set('America/Bogota');

// Cargar todos los usuarios
$all_users = [];
$users_result = $conn->query("SELECT id, name, role, email FROM users ORDER BY name ASC");
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $all_users[] = $row;
    }
}
$admin_users_list = ($_SESSION['user_role'] === 'Admin') ? $all_users : [];

// --- FILTRO POR USUARIO Y LÓGICA DE PRIORIDAD ---
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$all_pending_items = [];

$user_filter = '';
if ($current_user_role !== 'Admin') {
    $user_filter = " AND t.assigned_to_user_id = {$current_user_id}";
}

// 1. Cargar Alertas Pendientes (Agrupadas si son asignaciones a grupos)
$base_query_fields = "
    a.*, t.id as task_id, t.status as task_status, t.assigned_to_user_id, t.assigned_to_group,
    u_assigned.name as assigned_to_name, t.type as task_type, t.instruction as task_instruction,
    t.start_datetime, t.end_datetime, GROUP_CONCAT(DISTINCT u_assigned.name SEPARATOR ', ') as group_members
";
$base_query_joins = "
    FROM alerts a
    LEFT JOIN tasks t ON t.alert_id = a.id AND t.status != 'Cancelada'
    LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
";
$grouping = " GROUP BY a.id, IF(t.assigned_to_group IS NOT NULL, t.assigned_to_group, t.id)";

$alerts_sql = "SELECT {$base_query_fields} {$base_query_joins} WHERE a.status NOT IN ('Resuelta', 'Cancelada') {$user_filter} {$grouping}";

if ($current_user_role === 'Digitador') {
    $alerts_sql = "
        SELECT {$base_query_fields} {$base_query_joins} 
        WHERE a.status NOT IN ('Resuelta', 'Cancelada') 
        AND (t.assigned_to_user_id = {$current_user_id} OR a.suggested_role = 'Digitador')
        {$grouping}
    ";
}

$alerts_result = $conn->query($alerts_sql);
if ($alerts_result) {
    while ($row = $alerts_result->fetch_assoc()) {
        $row['item_type'] = 'alert';
        $all_pending_items[] = $row;
    }
}

// 2. Cargar Tareas Manuales Pendientes
$manual_tasks_sql = "
    SELECT
        t.id, t.id as task_id, t.title, t.instruction, t.priority, t.status as task_status,
        t.assigned_to_user_id, t.assigned_to_group, u.name as assigned_to_name,
        t.start_datetime, t.end_datetime,
        GROUP_CONCAT(DISTINCT u.name SEPARATOR ', ') as group_members
    FROM tasks t
    LEFT JOIN users u ON t.assigned_to_user_id = u.id
    WHERE t.alert_id IS NULL AND t.type = 'Manual' AND t.status = 'Pendiente' {$user_filter}
    GROUP BY IF(t.assigned_to_group IS NOT NULL, CONCAT(t.title, t.assigned_to_group), t.id)
";
$manual_tasks_result = $conn->query($manual_tasks_sql);
if ($manual_tasks_result) {
    while($row = $manual_tasks_result->fetch_assoc()) {
        $row['item_type'] = 'manual_task';
        $all_pending_items[] = $row;
    }
}

// 3. Procesar y ordenar items
$main_priority_items = [];
$main_non_priority_items = [];
$panel_high_priority_items = [];
$panel_medium_priority_items = [];
$now = new DateTime();

foreach ($all_pending_items as $item) {
    $original_priority = $item['priority'];
    $current_priority = $original_priority;

    if (!empty($item['end_datetime'])) {
        $end_time = new DateTime($item['end_datetime']);
        $diff_minutes = ($now->getTimestamp() - $end_time->getTimestamp()) / 60;

        if ($diff_minutes >= 0) { $current_priority = 'Alta'; } 
        elseif ($diff_minutes > -15 && ($original_priority === 'Baja' || $original_priority === 'Media')) { $current_priority = 'Media'; }
    }
    $item['current_priority'] = $current_priority;

    if ($current_priority === 'Critica' || $current_priority === 'Alta') {
        $main_priority_items[] = $item;
        $panel_high_priority_items[] = $item;
    } else {
        $main_non_priority_items[] = $item;
        if ($current_priority === 'Media') {
            $panel_medium_priority_items[] = $item;
        }
    }
}

$priority_order = ['Critica' => 4, 'Alta' => 3, 'Media' => 2, 'Baja' => 1];
usort($main_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });
usort($main_non_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });
usort($panel_high_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });
usort($panel_medium_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });

// Resto de las consultas
$completed_tasks = [];
if ($_SESSION['user_role'] === 'Admin') {
    $completed_result = $conn->query(
        "SELECT t.id, COALESCE(a.title, t.title) as title, t.instruction, t.priority, t.start_datetime, t.end_datetime, u_assigned.name as assigned_to, u_completed.name as completed_by, t.created_at, t.completed_at, TIMEDIFF(t.completed_at, t.created_at) as response_time FROM tasks t LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id LEFT JOIN users u_completed ON t.completed_by_user_id = u_completed.id LEFT JOIN alerts a ON t.alert_id = a.id WHERE t.status = 'Completada' ORDER BY t.completed_at DESC"
    );
    if ($completed_result) { while($row = $completed_result->fetch_assoc()){ $completed_tasks[] = $row; } }
}
$recaudos = [];
$recaudos_result = $conn->query("SELECT 1 as id, 'Tienda Ejemplo' as store_name, 197030000 as expected_amount, 'Completado' as status, 100 as bills_100k, 50 as bills_50k, 20 as bills_20k, 10 as bills_10k, 5 as bills_5k, 2 as bills_2k, 10000 as coins FROM DUAL");
if ($recaudos_result) { while ($row = $recaudos_result->fetch_assoc()) { $recaudos[] = $row; } }
$user_reminders = [];
$reminders_result = $conn->query("SELECT id, message, created_at FROM reminders WHERE user_id = $current_user_id AND is_read = 0 ORDER BY created_at DESC");
if($reminders_result) { while($row = $reminders_result->fetch_assoc()){ $user_reminders[] = $row; } }
$total_alerts_count_for_user = count($all_pending_items);
$priority_summary_count = count($main_priority_items);
$high_priority_badge_count = count($panel_high_priority_items);
$medium_priority_badge_count = count($panel_medium_priority_items);
$all_clients = [];
$clients_result = $conn->query("SELECT id, name, nit FROM clients ORDER BY name ASC");
if ($clients_result) { while ($row = $clients_result->fetch_assoc()) { $all_clients[] = $row; } }
$all_routes = [];
$routes_result = $conn->query("SELECT id, name FROM routes ORDER BY name ASC");
if ($routes_result) { while ($row = $routes_result->fetch_assoc()) { $all_routes[] = $row; } }

// Cargar Check-ins iniciales (SOLO los que NO han sido cerrados por el digitador)
$initial_checkins = [];
$checkins_result = $conn->query("SELECT ci.id, ci.invoice_number, ci.seal_number, ci.declared_value, f.name as fund_name, ci.created_at, c.name as client_name, r.name as route_name, u.name as checkinero_name, ci.status FROM check_ins ci JOIN clients c ON ci.client_id = c.id JOIN routes r ON ci.route_id = r.id JOIN users u ON ci.checkinero_id = u.id LEFT JOIN funds f ON ci.fund_id = f.id WHERE ci.digitador_status IS NULL ORDER BY ci.created_at DESC");
if ($checkins_result) { while ($row = $checkins_result->fetch_assoc()) { $initial_checkins[] = $row; } }

// Cargar historial del operador
$operator_history = [];
// CORRECCIÓN: El digitador también debe poder ver el historial de todos
if (in_array($_SESSION['user_role'], ['Operador', 'Admin', 'Digitador'])) {
    // El filtro solo se aplica si el rol es Operador. Para Admin y Digitador se muestra todo.
    $operator_id_filter = ($_SESSION['user_role'] === 'Operador') ? "WHERE op.operator_id = " . $_SESSION['user_id'] : "";
    
    $history_query = "
        SELECT 
            op.id, op.total_counted, op.discrepancy, op.observations, op.created_at as count_date,
            ci.invoice_number, ci.declared_value, c.name as client_name, u.name as operator_name
        FROM operator_counts op
        JOIN check_ins ci ON op.check_in_id = ci.id
        JOIN clients c ON ci.client_id = c.id
        JOIN users u ON op.operator_id = u.id
        {$operator_id_filter}
        ORDER BY op.created_at DESC
    ";
    $history_result = $conn->query($history_query);
    if ($history_result) {
        while($row = $history_result->fetch_assoc()) {
            $operator_history[] = $row;
        }
    }
}

// Cargar casos de discrepancia para el panel del digitador
$discrepancy_cases = [];
if (in_array($_SESSION['user_role'], ['Digitador', 'Admin'])) {
    $discrepancy_query = "
        SELECT
            MIN(t.id) AS task_id, a.id AS alert_id,
            (SELECT status FROM tasks WHERE alert_id = a.id AND assigned_to_user_id = {$current_user_id} ORDER BY FIELD(status, 'Pendiente', 'Resuelta') LIMIT 1) AS task_status,
            (SELECT resolution_note FROM tasks WHERE alert_id = a.id AND resolution_note IS NOT NULL LIMIT 1) AS resolution_note,
            a.title AS alert_title, a.created_at AS alert_date,
            ci.invoice_number, ci.declared_value, oc.total_counted, oc.discrepancy, oc.created_at AS count_date,
            u_check.name AS checkinero_name, u_op.name AS operator_name
        FROM alerts a
        LEFT JOIN tasks t ON a.id = t.alert_id
        JOIN check_ins ci ON a.check_in_id = ci.id
        JOIN operator_counts oc ON oc.check_in_id = ci.id
        JOIN users u_check ON ci.checkinero_id = u_check.id
        JOIN users u_op ON oc.operator_id = u_op.id
        WHERE a.suggested_role = 'Digitador'
        GROUP BY a.id
        ORDER BY a.created_at DESC
    ";
    $discrepancy_result = $conn->query($discrepancy_query);
    if ($discrepancy_result) {
        while($row = $discrepancy_result->fetch_assoc()) {
            $discrepancy_cases[] = $row;
        }
    }
}

$conn->close();
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
        body { font-family: 'Inter', sans-serif; }
        .nav-tab { cursor: pointer; padding: 0.75rem 1.5rem; font-weight: 500; color: #4b5563; border-bottom: 2px solid transparent; transition: all 0.2s; white-space: nowrap; }
        .nav-tab:hover { color: #111827; }
        .nav-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        #user-modal-overlay, #reminders-panel, #task-notifications-panel, #medium-priority-panel { transition: opacity 0.3s ease; }
        .task-form, .cash-breakdown { transition: all 0.4s ease-in-out; max-height: 0; overflow: hidden; padding-top: 0; padding-bottom: 0; opacity: 0;}
        .task-form.active, .cash-breakdown.active { max-height: 800px; padding-top: 1rem; padding-bottom: 1rem; opacity: 1;}
        .details-row { border-top: 1px solid #e5e7eb; }
        .sortable { transition: background-color: 0.2s; }
        .sortable:hover { background-color: #f3f4f6; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div id="user-modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
        <div id="user-modal" class="bg-white rounded-xl shadow-2xl w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center pb-3 border-b"><h3 id="modal-title" class="text-xl font-bold text-gray-900"></h3><button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-3xl leading-none">&times;</button></div>
                <form id="user-form" class="mt-6 space-y-4"><input type="hidden" id="user-id" name="id"><div><label for="user-name" class="block text-sm font-medium text-gray-700 mb-1">Nombre Completo</label><input type="text" id="user-name" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-md" required></div><div><label for="user-email" class="block text-sm font-medium text-gray-700 mb-1">Email</label><input type="email" id="user-email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md" required></div><div><label for="user-role" class="block text-sm font-medium text-gray-700 mb-1">Rol</label><select id="user-role" name="role" class="w-full px-3 py-2 border border-gray-300 rounded-md" required><option value="Operador">Operador</option><option value="Checkinero">Checkinero</option><option value="Digitador">Digitador</option><option value="Admin">Admin</option></select></div><div><label for="user-password" class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label><input type="password" id="user-password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md"><p id="password-hint" class="text-xs text-gray-500 mt-1"></p></div><div class="pt-4 flex justify-end space-x-3"><button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancelar</button><button type="submit" class="px-4 py-2 bg-blue-600 text-white font-semibold rounded-md hover:bg-blue-700">Guardar</button></div></form>
            </div>
        </div>
    </div>

    <div id="app" class="p-4 sm:p-6 lg:p-8 max-w-full mx-auto">
        <header class="flex flex-col sm:flex-row justify-between sm:items-center mb-6 border-b pb-4">
             <div><h1 class="text-2xl md:text-3xl font-bold text-gray-900">EAGLE 3.0</h1><p class="text-sm text-gray-500">Sistema Integrado de Operaciones y Alertas</p></div>
            <div class="text-sm text-gray-600 mt-2 sm:mt-0 flex items-center space-x-4">
                <div class="text-right">
                    <p class="font-semibold">Bienvenido, <?php echo htmlspecialchars($_SESSION['user_name']); ?></p>
                    <a href="logout.php" class="text-blue-600 hover:underline">Cerrar Sesión</a>
                </div>
                <div class="relative">
                    <button id="task-notification-button" onclick="toggleTaskNotifications()" class="relative text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6H5a2 2 0 00-2 2zm0 0h7"></path></svg>
                        <?php if ($high_priority_badge_count > 0): ?>
                        <span id="task-notification-badge" class="absolute -top-2 -right-2 flex items-center justify-center h-5 w-5 rounded-full bg-red-500 text-white text-xs font-bold"><?php echo $high_priority_badge_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="task-notifications-panel" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl p-4 hidden z-20">
                        <h4 class="font-bold text-gray-800 mb-2">Alertas de Tareas Prioritarias</h4>
                        <div id="task-notifications-list" class="space-y-2 max-h-64 overflow-y-auto">
                           <?php if(empty($panel_high_priority_items)): ?>
                                <p class="text-sm text-gray-500">No hay alertas prioritarias.</p>
                            <?php else: foreach($panel_high_priority_items as $item): ?>
                                <?php $color_class = $item['current_priority'] === 'Critica' ? 'red' : 'orange'; ?>
                                <div class="p-2 bg-<?php echo $color_class; ?>-50 rounded-md border border-<?php echo $color_class; ?>-200 text-sm">
                                    <p class="font-semibold text-<?php echo $color_class; ?>-800"><?php echo htmlspecialchars($item['title']); ?></p>
                                    <p class="text-gray-700 text-xs mt-1"><?php echo htmlspecialchars($item['item_type'] === 'manual_task' ? $item['instruction'] : $item['description']); ?></p>

                                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                                        <?php if (!empty($item['assigned_to_group'])): ?>
                                            <p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-<?php echo $color_class; ?>-200">
                                                Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?>
                                            </p>
                                        <?php elseif (!empty($item['assigned_to_name'])): ?>
                                            <p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-<?php echo $color_class; ?>-200">
                                                Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($item['end_datetime'])): ?>
                                        <div class="countdown-timer text-xs font-bold mt-1" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <button id="medium-priority-button" onclick="toggleMediumPriority()" class="relative text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <?php if ($medium_priority_badge_count > 0): ?>
                            <span id="medium-priority-badge" class="absolute -top-2 -right-2 flex items-center justify-center h-5 w-5 rounded-full bg-yellow-500 text-white text-xs font-bold"><?php echo $medium_priority_badge_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="medium-priority-panel" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl p-4 hidden z-20">
                        <h4 class="font-bold text-gray-800 mb-2">Alertas de Prioridad Media</h4>
                        <div id="medium-priority-list" class="space-y-2 max-h-64 overflow-y-auto">
                            <?php if(empty($panel_medium_priority_items)): ?>
                                <p class="text-sm text-gray-500">No hay alertas de prioridad media.</p>
                            <?php else: foreach($panel_medium_priority_items as $item): ?>
                                <div class="p-2 bg-yellow-50 rounded-md border border-yellow-200 text-sm">
                                    <p class="font-semibold text-yellow-800"><?php echo htmlspecialchars($item['title']); ?></p>
                                    <p class="text-gray-700 text-xs mt-1"><?php echo htmlspecialchars($item['item_type'] === 'manual_task' ? $item['instruction'] : $item['description']); ?></p>

                                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                                        <?php if (!empty($item['assigned_to_group'])): ?>
                                            <p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-yellow-200">
                                                Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?>
                                            </p>
                                        <?php elseif (!empty($item['assigned_to_name'])): ?>
                                            <p class="text-xs text-blue-700 font-bold mt-1 pt-1 border-t border-yellow-200">
                                                Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?>
                                            </p>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!empty($item['end_datetime'])): ?>
                                        <div class="countdown-timer text-xs font-bold mt-1" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <button id="reminders-button" onclick="toggleReminders()" class="relative text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                        <span id="reminders-badge" class="absolute -top-2 -right-2 flex items-center justify-center h-5 w-5 rounded-full bg-blue-500 text-white text-xs font-bold hidden"></span>
                    </button>
                    <div id="reminders-panel" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl p-4 hidden z-20">
                        <h4 class="font-bold text-gray-800 mb-2">Tus Recordatorios</h4>
                        <div id="reminders-list" class="space-y-2 max-h-64 overflow-y-auto">
                            <?php if(empty($user_reminders)): ?>
                                <p class="text-sm text-gray-500">No tienes recordatorios pendientes.</p>
                            <?php else: foreach($user_reminders as $reminder): ?>
                                <div class="reminder-item p-2 bg-blue-50 rounded-md border border-blue-200 text-sm">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <p class="text-gray-700"><?php echo htmlspecialchars($reminder['message']); ?></p>
                                            <p class="text-xs text-gray-400 mt-1"><?php echo date('d M, h:i a', strtotime($reminder['created_at'])); ?></p>
                                        </div>
                                        <button onclick="deleteReminder(<?php echo $reminder['id']; ?>, this)" class="text-red-400 hover:text-red-600 font-bold text-lg">&times;</button>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <nav class="mb-8">
            <div class="border-b border-gray-200">
                <div class="-mb-px flex space-x-4 overflow-x-auto">
                    <button id="tab-operaciones" class="nav-tab active" onclick="switchTab('operaciones')">Panel General</button>
                    
                    <?php if (in_array($_SESSION['user_role'], ['Checkinero', 'Admin'])): ?>
                        <button id="tab-checkinero" class="nav-tab" onclick="switchTab('checkinero')">Panel Check-in</button>
                    <?php endif; ?>

                    <?php if (in_array($_SESSION['user_role'], ['Operador', 'Admin'])): ?>
                        <button id="tab-operador" class="nav-tab" onclick="switchTab('operador')">Panel Operador</button>
                    <?php endif; ?>

                    <?php if (in_array($_SESSION['user_role'], ['Digitador', 'Admin'])): ?>
                        <button id="tab-digitador" class="nav-tab" onclick="switchTab('digitador')">Panel Digitador</button>
                    <?php endif; ?>

                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                        <button id="tab-roles" class="nav-tab" onclick="switchTab('roles')">Gestión de Roles</button>
                        <a href="manage_clients.php" class="nav-tab">Gestionar Clientes</a>
                        <a href="manage_routes.php" class="nav-tab">Gestionar Rutas</a>
                        <a href="manage_funds.php" class="nav-tab">Gestionar Fondos</a>
                        <button id="tab-trazabilidad" class="nav-tab" onclick="switchTab('trazabilidad')">Trazabilidad</button>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <main>
            <div id="content-operaciones">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Recaudos de Hoy</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">$</div></div><p class="text-3xl font-bold text-gray-900 mt-2">$197.030.000</p><p class="text-sm text-green-600 mt-2">▲ 12% vs ayer</p></div>
                    <div class="bg-white p-6 rounded-xl shadow-sm"><div class="flex justify-between items-start"><p class="text-sm font-medium text-gray-500">Cierres Pendientes</p><div class="text-blue-500 p-2 bg-blue-100 rounded-full">🕔</div></div><p class="text-3xl font-bold text-gray-900 mt-2">3</p><p class="text-sm text-gray-500 mt-2">Programados para hoy</p></div>
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
                                $id = $is_manual ? $item['task_id'] : $item['id'];
                                $is_group_task = !empty($item['assigned_to_group']);
                                $is_assigned = $item['assigned_to_user_id'] !== null;
                                $priority_to_use = $item['current_priority'];
                                $color_map = ['Critica' => ['bg' => 'bg-red-100', 'border' => 'border-red-500', 'text' => 'text-red-800', 'badge' => 'bg-red-200'],'Alta' => ['bg' => 'bg-orange-100', 'border' => 'border-orange-500', 'text' => 'text-orange-800', 'badge' => 'bg-orange-200']];
                                $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                            ?>
                            <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $id; ?>">
                                <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                    <div class="flex justify-between items-start">
                                        <p class="font-semibold <?php echo $color['text']; ?> text-lg"><?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title']); ?> <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full"><?php echo strtoupper($priority_to_use); ?></span></p>
                                        <?php if ($is_assigned && !$is_group_task && isset($item['task_status']) && $item['task_status'] === 'Pendiente'): ?>
                                            <button onclick="completeTask(<?php echo $item['task_id']; ?>)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300" title="Marcar como completada"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? $item['instruction'] : $item['description']); ?></p>
                                    <?php if (!empty($item['end_datetime'])): ?>
                                        <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                    <?php endif; ?>
                                    <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                        <button onclick="toggleForm('assign-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo ($is_assigned || $is_group_task) ? 'Re-asignar' : 'Asignar'; ?></button>
                                        <button onclick="toggleForm('reminder-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                        <div class="flex-grow text-right text-sm">
                                            <?php if($is_group_task): ?>
                                                <span class="font-semibold text-purple-700">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></span>
                                            <?php elseif($is_assigned): ?>
                                                <span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div id="assign-form-<?php echo $id; ?>" class="task-form bg-gray-50 px-4">
                                    <h4 class="text-sm font-semibold mb-2"><?php echo ($is_assigned || $is_group_task) ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                    <select id="assign-user-<?php echo $id; ?>" class="w-full p-2 text-sm border rounded-md">
                                        <optgroup label="Grupos">
                                            <option value="group-todos">Todos los Usuarios</option>
                                            <option value="group-Operador">Todos los Operadores</option>
                                            <option value="group-Checkinero">Todos los Checkineros</option>
                                            <option value="group-Digitador">Todos los Digitadores</option>
                                        </optgroup>
                                        <optgroup label="Usuarios Individuales">
                                            <?php
                                            $suggested_role = !$is_manual ? ($item['suggested_role'] ?? null) : null;
                                            foreach ($all_users as $user) {
                                                if (!$suggested_role || $user['role'] === $suggested_role) {
                                                    $selected = ($user['id'] == $item['assigned_to_user_id'] && !$is_group_task) ? 'selected' : '';
                                                    echo "<option value='{$user['id']}' {$selected}>" . htmlspecialchars($user['name']) . " ({$user['role']})</option>";
                                                }
                                            }
                                            ?>
                                        </optgroup>
                                    </select>
                                    <textarea id="task-instruction-<?php echo $id; ?>" rows="2" class="w-full p-2 text-sm border rounded-md mt-2" placeholder="Instrucción"><?php echo htmlspecialchars($item['task_instruction'] ?? ''); ?></textarea>
                                    <button onclick="submitAssignment(<?php echo $is_manual ? 'null' : $item['id']; ?>, <?php echo $is_manual ? $id : ($item['task_id'] ?? 'null'); ?>, '<?php echo $id; ?>')" class="w-full bg-blue-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar</button>
                                </div>
                                <div id="reminder-form-<?php echo $id; ?>" class="task-form bg-gray-50 px-4">
                                    <h4 class="text-sm font-semibold mb-2">Crear Recordatorio</h4>
                                    <select id="reminder-user-<?php echo $id; ?>" class="w-full p-2 text-sm border rounded-md">
                                        <?php foreach ($all_users as $user): ?>
                                            <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button onclick="setReminder(<?php echo $is_manual ? 'null' : $item['id']; ?>, <?php echo $is_manual ? $id : ($item['task_id'] ?? 'null'); ?>, '<?php echo $id; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Crear</button>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>

                        <div class="bg-white p-6 rounded-xl shadow-sm mt-8">
                             <h2 class="text-lg font-semibold mb-4 text-gray-900">Recaudos del Día</h2>
                             <div class="overflow-x-auto">
                                <table class="w-full text-sm text-left">
                                    <thead class="text-xs text-gray-500 uppercase bg-gray-50"><tr><th class="px-6 py-3">Tienda</th><th class="px-6 py-3">Monto</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3"></th></tr></thead>
                                    <tbody id="recaudos-tbody">
                                        <?php foreach ($recaudos as $recaudo): ?>
                                        <tr class="border-b"><td class="px-6 py-4"><?php echo htmlspecialchars($recaudo['store_name']); ?></td><td class="px-6 py-4 font-mono">$<?php echo number_format($recaudo['expected_amount'], 0, ',', '.'); ?></td><td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full <?php echo $recaudo['status'] === 'Completado' ? 'bg-green-100 text-green-800' : ($recaudo['status'] === 'En Progreso' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'); ?>"><?php echo $recaudo['status']; ?></span></td><td class="px-6 py-4 text-right"><?php if ($recaudo['status'] === 'Completado'): ?><button onclick="toggleBreakdown(<?php echo $recaudo['id']; ?>)" class="text-blue-600 text-xs font-semibold">Desglose</button><?php endif; ?></td></tr>
                                        <tr class="details-row hidden" id="breakdown-row-<?php echo $recaudo['id']; ?>"><td colspan="4" class="p-0"><div id="breakdown-content-<?php echo $recaudo['id']; ?>" class="cash-breakdown bg-gray-50"><div class="p-4 grid grid-cols-3 gap-x-8 gap-y-2 text-xs"><span><strong>$100.000:</strong> <?php echo number_format($recaudo['bills_100k'] ?? 0); ?></span><span><strong>$50.000:</strong> <?php echo number_format($recaudo['bills_50k'] ?? 0); ?></span><span><strong>$20.000:</strong> <?php echo number_format($recaudo['bills_20k'] ?? 0); ?></span><span><strong>$10.000:</strong> <?php echo number_format($recaudo['bills_10k'] ?? 0); ?></span><span><strong>$5.000:</strong> <?php echo number_format($recaudo['bills_5k'] ?? 0); ?></span><span><strong>$2.000:</strong> <?php echo number_format($recaudo['bills_2k'] ?? 0); ?></span><span class="col-span-3"><strong>Monedas:</strong> $<?php echo number_format($recaudo['coins'] ?? 0, 0, ',', '.'); ?></span></div></div></td></tr>
                                        <?php endforeach; ?>
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
                                        $id = $is_manual ? $item['task_id'] : $item['id'];
                                        $is_group_task = !empty($item['assigned_to_group']);
                                        $is_assigned = $item['assigned_to_user_id'] !== null;
                                        $priority_to_use = $item['current_priority'];
                                        $color_map = ['Media' => ['bg' => 'bg-yellow-100', 'border' => 'border-yellow-400', 'text' => 'text-yellow-800', 'badge' => 'bg-yellow-200'],'Baja'  => ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200']];
                                        $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                                    ?>
                                    <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $id; ?>">
                                        <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                            <div class="flex justify-between items-start">
                                                <p class="font-semibold <?php echo $color['text']; ?> text-md"><?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title']); ?> <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full"><?php echo strtoupper($priority_to_use); ?></span></p>
                                                <?php if ($is_assigned && !$is_group_task && isset($item['task_status']) && $item['task_status'] === 'Pendiente'): ?>
                                                    <button onclick="completeTask(<?php echo $item['task_id']; ?>)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300" title="Marcar como completada"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></button>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? $item['instruction'] : $item['description']); ?></p>
                                            <?php if (!empty($item['end_datetime'])): ?>
                                                <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                            <?php endif; ?>
                                            <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                                <button onclick="toggleForm('assign-form-np-<?php echo $id; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo ($is_assigned || $is_group_task) ? 'Re-asignar' : 'Asignar'; ?></button>
                                                <button onclick="toggleForm('reminder-form-np-<?php echo $id; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                                <div class="flex-grow text-right text-sm">
                                                    <?php if($is_group_task): ?>
                                                        <span class="font-semibold text-purple-700">Asignada a: Grupo <?php echo htmlspecialchars(ucfirst($item['assigned_to_group'])); ?></span>
                                                    <?php elseif($is_assigned): ?>
                                                        <span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="assign-form-np-<?php echo $id; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2"><?php echo ($is_assigned || $is_group_task) ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                            <select id="assign-user-np-<?php echo $id; ?>" class="w-full p-2 text-sm border rounded-md">
                                                <optgroup label="Grupos">
                                                    <option value="group-todos">Todos los Usuarios</option>
                                                    <option value="group-Operador">Todos los Operadores</option>
                                                    <option value="group-Checkinero">Todos los Checkineros</option>
                                                    <option value="group-Digitador">Todos los Digitadores</option>
                                                </optgroup>
                                                <optgroup label="Usuarios Individuales">
                                                    <?php
                                                    $suggested_role = !$is_manual ? ($item['suggested_role'] ?? null) : null;
                                                    foreach ($all_users as $user) {
                                                        if (!$suggested_role || $user['role'] === $suggested_role) {
                                                            $selected = ($user['id'] == $item['assigned_to_user_id'] && !$is_group_task) ? 'selected' : '';
                                                            echo "<option value='{$user['id']}' {$selected}>" . htmlspecialchars($user['name']) . " ({$user['role']})</option>";
                                                        }
                                                    }
                                                    ?>
                                                </optgroup>
                                            </select>
                                            <textarea id="task-instruction-np-<?php echo $id; ?>" rows="2" class="w-full p-2 text-sm border rounded-md mt-2" placeholder="Instrucción"><?php echo htmlspecialchars($item['task_instruction'] ?? ''); ?></textarea>
                                            <button onclick="submitAssignment(<?php echo $is_manual ? 'null' : $item['id']; ?>, <?php echo $is_manual ? $id : ($item['task_id'] ?? 'null'); ?>, 'np-<?php echo $id; ?>')" class="w-full bg-blue-600 text-white font-semibold py-2 mt-2 rounded-md">Confirmar</button>
                                        </div>
                                        <div id="reminder-form-np-<?php echo $id; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2">Crear Recordatorio</h4>
                                            <select id="reminder-user-np-<?php echo $id; ?>" class="w-full p-2 text-sm border rounded-md">
                                                <?php foreach ($all_users as $user): ?>
                                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button onclick="setReminder(<?php echo $is_manual ? 'null' : $item['id']; ?>, <?php echo $is_manual ? $id : ($item['task_id'] ?? 'null'); ?>, 'np-<?php echo $id; ?>')" class="w-full bg-green-600 text-white font-semibold py-2 mt-2 rounded-md">Crear</button>
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
                        <h3 class="text-xl font-semibold mb-4">Registrar Nuevo Check-in</h3>
                        <form id="checkin-form" class="space-y-4">
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
                            <button type="submit" class="w-full bg-green-600 text-white font-bold py-3 rounded-md hover:bg-green-700 mt-4">Agregar Check-in</button>
                        </form>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-lg">
                        <h3 class="text-xl font-semibold mb-4">Últimos Check-ins Registrados</h3>
                        <div class="overflow-auto max-h-[600px]">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 sticky top-0">
                                    <tr>
                                        <th class="p-3">Planilla</th>
                                        <th class="p-3">Sello</th>
                                        <th class="p-3">Declarado</th>
                                        <th class="p-3">Ruta</th>
                                        <th class="p-3">Fecha de Registro</th>
                                        <th class="p-3">Checkinero</th>
                                        <th class="p-3">Cliente</th>
                                        <th class="p-3">Fondo</th>
                                    </tr>
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

                <div class="bg-white p-6 rounded-xl shadow-lg mt-8">
                    <h3 class="text-xl font-semibold mb-4">Planillas Pendientes de Detallar</h3>
                    <div class="overflow-auto max-h-[600px]">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="p-3">Planilla</th>
                                    <th class="p-3">Sello</th>
                                    <th class="p-3">Declarado</th>
                                    <th class="p-3">Cliente</th>
                                    <th class="p-3">Checkinero</th>
                                    <th class="p-3">Fecha de Registro</th>
                                    <th class="p-3">Estado</th>
                                    <th class="p-3">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="operator-checkins-table-body">
                                </tbody>
                        </table>
                    </div>
                </div>

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
                                    <?php if ($_SESSION['user_role'] === 'Admin'): ?><th class="p-3">Operador</th><?php endif; ?>
                                    <th class="p-3">Fecha Conteo</th>
                                    <th class="p-3">Observaciones</th>
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
                
                <hr class="my-8 border-gray-300">

                <h2 class="text-2xl font-bold text-gray-900 mb-6">Supervisión de Operaciones</h2>
                
                <div id="consultation-section-digitador" class="bg-white p-6 rounded-xl shadow-lg mb-8">
                     <h3 class="text-xl font-semibold mb-4">Buscar Planilla para Detallar y Supervisar</h3>
                     <form id="consultation-form-digitador" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                         <div>
                             <label for="consult-invoice-digitador" class="block text-sm font-medium">Número de Planilla</label>
                             <input type="text" id="consult-invoice-digitador" required class="mt-1 w-full p-2 border rounded-md">
                         </div>
                         <div class="pt-6">
                             <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-blue-700">Consultar</button>
                         </div>
                     </form>
                </div>

                <div id="operator-panel-digitador" class="hidden">
                    <div class="bg-white p-6 rounded-xl shadow-lg mb-8">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 pb-4 border-b">
                            <div><span class="block text-sm text-gray-500">Número de Planilla</span><strong id="display-invoice-digitador" class="text-lg"></strong></div>
                            <div><span class="block text-sm text-gray-500">Sello de la Factura</span><strong id="display-seal-digitador" class="text-lg"></strong></div>
                            <div><span class="block text-sm text-gray-500">Nombre del Cliente</span><strong id="display-client-digitador" class="text-lg"></strong></div>
                            <div><span class="block text-sm text-gray-500">Valor Declarado</span><strong id="display-declared-digitador" class="text-lg text-blue-600"></strong></div>
                        </div>
                        
                        <h3 class="text-xl font-semibold mb-4">Detalle de Denominación</h3>
                        <form id="denomination-form-digitador">
                            <input type="hidden" id="op-checkin-id-digitador">
                            <div class="space-y-2">
                                <?php 
                                    foreach($denominations as $value):
                                ?>
                                <div class="grid grid-cols-5 gap-4 items-center denomination-row" data-value="<?php echo $value; ?>">
                                    <div class="col-span-2 font-medium text-gray-700"><?php echo '$' . number_format($value, 0, ',', '.'); ?></div>
                                    <div class="col-span-2 flex items-center">
                                        <button type="button" class="px-3 py-1 bg-gray-200 rounded-l-md font-bold text-lg" onclick="updateQty(this, -1)">-</button>
                                        <input type="number" value="0" min="0" class="w-full text-center border-t border-b p-1 denomination-qty" oninput="calculateTotals('digitador')">
                                        <button type="button" class="px-3 py-1 bg-gray-200 rounded-r-md font-bold text-lg" onclick="updateQty(this, 1)">+</button>
                                    </div>
                                    <div class="text-right font-mono subtotal">$ 0</div>
                                </div>
                                <?php endforeach; ?>
                                
                                <div class="grid grid-cols-5 gap-4 items-center pt-2 border-t">
                                    <div class="col-span-2 font-medium text-gray-700">Monedas</div>
                                    <div class="col-span-2">
                                        <input type="number" id="coins-value-digitador" value="0" min="0" step="50" class="w-full border p-1" oninput="calculateTotals('digitador')" placeholder="Valor total en monedas">
                                    </div>
                                    <div class="text-right font-mono" id="coins-subtotal-digitador">$ 0</div>
                                </div>
                                
                                <div class="grid grid-cols-5 gap-4 items-center pt-4 mt-4 border-t-2">
                                    <div class="col-span-2 font-bold text-xl">Total</div>
                                    <div class="col-span-3 text-right font-mono text-xl" id="total-counted-digitador">$ 0</div>
                                </div>
                                <div class="grid grid-cols-5 gap-4 items-center">
                                    <div class="col-span-2 font-bold text-xl">Diferencia</div>
                                    <div class="col-span-3 text-right font-mono text-xl" id="discrepancy-digitador">$ 0</div>
                                </div>
                            </div>
                            
                            <div class="mt-6">
                                <label for="observations-digitador" class="block text-sm font-medium">Observación</label>
                                <textarea id="observations-digitador" rows="3" class="mt-1 w-full border rounded-md p-2"></textarea>
                            </div>
                            
                            <div class="mt-6 flex justify-end">
                                <button type="submit" class="bg-gray-400 text-white font-bold py-3 px-6 rounded-md cursor-not-allowed" disabled>Guardar Conteo (Solo Operador)</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg mt-8">
                    <h3 class="text-xl font-semibold mb-4">Planillas Pendientes de Detallar (Supervisión)</h3>
                    <div class="overflow-auto max-h-[600px]">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="p-3">Planilla</th>
                                    <th class="p-3">Sello</th>
                                    <th class="p-3">Declarado</th>
                                    <th class="p-3">Cliente</th>
                                    <th class="p-3">Checkinero</th>
                                    <th class="p-3">Fecha de Registro</th>
                                    <th class="p-3">Estado</th>
                                    <th class="p-3">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="operator-checkins-table-body-digitador">
                                </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-xl shadow-lg mt-8">
                    <h3 class="text-xl font-semibold mb-4">Historial de Conteos Realizados (Supervisión)</h3>
                    <div class="overflow-auto max-h-[600px]">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 sticky top-0">
                                <tr>
                                    <th class="p-3">Planilla</th>
                                    <th class="p-3">Cliente</th>
                                    <th class="p-3">V. Declarado</th>
                                    <th class="p-3">V. Contado</th>
                                    <th class="p-3">Discrepancia</th>
                                    <th class="p-3">Operador</th>
                                    <th class="p-3">Fecha Conteo</th>
                                    <th class="p-3">Observaciones</th>
                                </tr>
                            </thead>
                            <tbody id="operator-history-table-body-digitador">
                                </tbody>
                        </table>
                    </div>
                </div>
                
                <hr class="my-8 border-gray-300">
                
                <h2 class="text-2xl font-bold text-gray-900 mb-6">Gestión de Cierre e Informes</h2>

                <div class="bg-white p-2 rounded-lg shadow-md flex space-x-2 mb-6">
                    <button id="btn-llegadas" class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 rounded-md shadow-sm hover:bg-blue-700">
                        Ver Llegadas
                    </button>
                    <button id="btn-cierre" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                        Proceso de Cierre
                    </button>
                    <button id="btn-informes" class="px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                        Generar Informes (PDF)
                    </button>
                </div>

                <div id="panel-llegadas" class="bg-white p-6 rounded-xl shadow-lg">
                    <h3 class="text-xl font-semibold mb-4 text-gray-900">Check-ins (Llegadas)</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-3">Planilla</th> <th class="p-3">Sello</th> <th class="p-3">Declarado</th>
                                    <th class="p-3">Ruta</th> <th class="p-3">Fecha Registro</th> <th class="p-3">Checkinero</th>
                                    <th class="p-3">Cliente</th> <th class="p-3">Fondo</th>
                                </tr>
                            </thead>
                            <tbody id="llegadas-table-body">
                                </tbody>
                        </table>
                    </div>
                </div>

                <div id="panel-cierre" class="hidden bg-white p-6 rounded-xl shadow-lg">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <h3 class="text-xl font-semibold mb-4 text-gray-900">Listado de Fondos</h3>
                            <div id="funds-list-container" class="space-y-2 max-h-96 overflow-y-auto"></div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold mb-4 text-gray-900">Servicios Activos</h3>
                            <div id="services-list-container" class="space-y-3">
                                <p class="text-gray-500">Seleccione un fondo para ver sus servicios.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="panel-informes" class="hidden bg-white p-6 rounded-xl shadow-lg">
                    <h3 class="text-xl font-semibold mb-4 text-gray-900">Servicios para Informar</h3>
                    <div class="flex justify-end mb-4">
                        <button onclick="generatePDF()" class="bg-green-600 text-white font-bold py-2 px-4 rounded-md hover:bg-green-700">
                            Generar PDF (<span id="selected-informe-count">0</span> seleccionados)
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-3 w-10"><input type="checkbox" id="select-all-informes"></th>
                                    <th class="p-3">Planilla</th> <th class="p-3">Sello</th> <th class="p-3">Total</th>
                                    <th class="p-3">Fondo</th> <th class="p-3">Cliente</th>
                                </tr>
                            </thead>
                            <tbody id="informes-table-body"></tbody>
                        </table>
                    </div>
                </div>

                 <div class="mt-8 bg-white p-6 rounded-xl shadow-lg">
                    <h3 class="text-xl font-semibold mb-4 text-gray-900">Trazabilidad de Discrepancias</h3>
                     <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3">Planilla</th> <th class="px-4 py-3">Responsables</th> <th class="px-4 py-3">Valores</th>
                                    <th class="px-4 py-3">Fechas</th> <th class="px-4 py-3 w-1/3">Resolución del Caso</th> <th class="px-4 py-3">Estado</th>
                                </tr>
                            </thead>
                            <tbody id="discrepancy-traceability-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($_SESSION['user_role'] === 'Admin'): ?>
            <div id="content-roles" class="hidden">
                 <div class="flex justify-between items-center mb-4"><h2 class="text-xl font-bold">Gestionar Usuarios</h2><button onclick="openModal()" class="bg-green-600 text-white font-semibold px-4 py-2 rounded-lg">Agregar Usuario</button></div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm"><thead class="bg-gray-50"><tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">Email</th><th class="px-6 py-3">Rol</th><th class="px-6 py-3 text-center">Acciones</th></tr></thead><tbody id="user-table-body"></tbody></table>
                </div>
            </div>
            <div id="content-trazabilidad" class="hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Trazabilidad de Tareas Completadas</h2>

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
                            <label for="filter-priority" class="text-sm font-medium text-gray-700">P. Final</label>
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
                                    <th class="px-6 py-3">P. Inicial</th>
                                    <th class="px-6 py-3">P. Final</th>
                                    <th class="px-6 py-3 sortable cursor-pointer" data-column-name="created_at" onclick="sortTableByDate('created_at')">Hora Inicio <span class="text-gray-400"></span></th>
                                    <th class="px-6 py-3 sortable cursor-pointer" data-column-name="completed_at" onclick="sortTableByDate('completed_at')">Hora Fin <span class="text-gray-400"></span></th>
                                    <th class="px-6 py-3">Tiempo Resp.</th>
                                    <th class="px-6 py-3">Asignado a</th>
                                    <th class="px-6 py-3">Check por</th>
                                </tr>
                            </thead>
                            <tbody id="trazabilidad-tbody">
                                </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
    const allUsers = <?php echo json_encode($all_users); ?>;
    const adminUsersData = <?php echo json_encode($admin_users_list); ?>;
    const currentUserId = <?php echo $_SESSION['user_id']; ?>;
    const currentUserRole = '<?php echo $_SESSION['user_role']; ?>';
    const apiUrlBase = 'api';
    const initialCheckins = <?php echo json_encode($initial_checkins); ?>;
    const operatorHistoryData = <?php echo json_encode($operator_history); ?>;
    const discrepancyCases = <?php echo json_encode($discrepancy_cases); ?>;

    const remindersPanel = document.getElementById('reminders-panel');
    const taskNotificationsPanel = document.getElementById('task-notifications-panel');
    const mediumPriorityPanel = document.getElementById('medium-priority-panel');

    function toggleReminders() { remindersPanel.classList.toggle('hidden'); }
    function toggleTaskNotifications() { taskNotificationsPanel.classList.toggle('hidden'); }
    function toggleMediumPriority() { mediumPriorityPanel.classList.toggle('hidden'); }

    function toggleForm(formId, button) {
        const form = document.getElementById(formId);
        const parentItem = button.closest('.task-card');
        parentItem.querySelectorAll('.task-form').forEach(f => {
            if (f.id !== formId && f.classList.contains('active')) {
                f.classList.remove('active');
            }
        });
        form.classList.toggle('active');
    }

    function toggleBreakdown(id) { document.getElementById(`breakdown-row-${id}`).classList.toggle('hidden'); setTimeout(() => { document.getElementById(`breakdown-content-${id}`).classList.toggle('active'); }, 10); }

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

    function updateReminderCount() {
        const list = document.getElementById('reminders-list');
        const badge = document.getElementById('reminders-badge');
        const count = list.getElementsByClassName('reminder-item').length;

        if (count > 0) {
            badge.textContent = count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
            list.innerHTML = '<p class="text-sm text-gray-500">No tienes recordatorios pendientes.</p>';
        }
    }

    async function completeTask(taskId) {
        if (!confirm('¿Estás seguro de que quieres marcar esta tarea como completada?')) return;
        try {
            const response = await fetch(`${apiUrlBase}/task_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ task_id: taskId })
            });
            const result = await response.json();
            if (result.success) {
                alert('Tarea completada con éxito.');
                location.reload();
            } else { alert('Error al completar la tarea: ' + result.error); }
        } catch (error) { console.error('Error completing task:', error); alert('Error de conexión.'); }
    }

    async function submitAssignment(alertId, taskId, formIdPrefix) {
        const selectedValue = document.getElementById(`assign-user-${formIdPrefix}`).value;
        const instruction = document.getElementById(`task-instruction-${formIdPrefix}`).value;

        let payload = {
            instruction: instruction,
            type: 'Asignacion',
            task_id: taskId,
            alert_id: alertId
        };

        if (selectedValue.startsWith('group-')) {
            payload.assign_to_group = selectedValue.replace('group-', '');
        } else {
            payload.assign_to = selectedValue;
        }

        await sendTaskRequest(payload);
    }

    async function setReminder(alertId, taskId, formIdPrefix) {
        const userId = document.getElementById(`reminder-user-${formIdPrefix}`).value;
        await sendTaskRequest({
            assign_to: userId,
            instruction: 'Recordatorio para revisar',
            type: 'Recordatorio',
            task_id: taskId,
            alert_id: alertId
        });
    }

    async function sendTaskRequest(payload) {
        if (!payload.assign_to && !payload.assign_to_group) { alert('Por favor, selecciona un usuario o grupo.'); return; }
        try {
            const response = await fetch(`${apiUrlBase}/alerts_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (!response.ok) { throw new Error(`Error HTTP ${response.status}`); }
            const result = await response.json();
            if (result.success) {
                alert('Acción completada con éxito.');
                location.reload();
            } else {
                alert('Error desde la API: ' + result.error);
            }
        } catch (error) {
            console.error('Error en sendTaskRequest:', error);
            alert('Error de conexión. Revisa la consola (F12) para más detalles.');
        }
    }

    document.getElementById('manual-task-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const title = document.getElementById('manual-task-title').value;
        const instruction = document.getElementById('manual-task-desc').value;
        const selectedValue = document.getElementById('manual-task-user').value;
        const priority = document.getElementById('manual-task-priority').value;
        const start_datetime = document.getElementById('manual-task-start').value;
        const end_datetime = document.getElementById('manual-task-end').value;

        if (!selectedValue) { alert('Selecciona un usuario o grupo.'); return; }
        if (start_datetime && end_datetime && start_datetime >= end_datetime) {
            alert('La fecha de fin debe ser posterior a la fecha de inicio.');
            return;
        }

        let payload = {
            title: title,
            instruction: instruction,
            type: 'Manual',
            priority: priority,
            start_datetime: start_datetime || null,
            end_datetime: end_datetime || null
        };

        if (selectedValue.startsWith('group-')) {
            payload.assign_to_group = selectedValue.replace('group-', '');
        } else {
            payload.assign_to = selectedValue;
        }

        try {
            const response = await fetch(`${apiUrlBase}/alerts_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!response.ok) { throw new Error(`Error HTTP ${response.status}`); }
            const result = await response.json();
            if (result.success) { alert('Tarea(s) creada(s).'); location.reload(); }
            else { alert('Error desde la API: ' + result.error); }
        } catch (error) {
            console.error('Error creando tarea manual:', error);
            alert('Error de conexión. Revisa la consola (F12) para más detalles.');
        }
    });

    const modalOverlay = document.getElementById('user-modal-overlay');
    const modal = document.getElementById('user-modal');
    const modalTitle = document.getElementById('modal-title');
    const userForm = document.getElementById('user-form');
    const userIdInput = document.getElementById('user-id');
    const passwordHint = document.getElementById('password-hint');
    const userPasswordInput = document.getElementById('user-password');

    function openModal(user = null) {
        userForm.reset();
        if (user) {
            modalTitle.textContent = 'Editar Usuario';
            userIdInput.value = user.id;
            document.getElementById('user-name').value = user.name;
            document.getElementById('user-email').value = user.email;
            document.getElementById('user-role').value = user.role;
            userPasswordInput.required = false;
            passwordHint.textContent = 'Dejar en blanco para no cambiar.';
        } else {
            modalTitle.textContent = 'Agregar Nuevo Usuario';
            userIdInput.value = '';
            userPasswordInput.required = true;
            passwordHint.textContent = 'La contraseña es requerida.';
        }
        modalOverlay.classList.remove('hidden');
    }

    function closeModal() {
        modalOverlay.classList.add('hidden');
    }

    userForm.addEventListener('submit', async function(event) {
        event.preventDefault();
        const formData = new FormData(userForm);
        try {
            const response = await fetch(`${apiUrlBase}/users_api.php`, { method: 'POST', body: formData });
            const result = await response.json();
            if (result.success) { closeModal(); location.reload(); }
            else { alert('Error: ' + result.error); }
        } catch (error) { alert('Error de conexión.'); }
    });

    async function deleteUser(id) {
        if (!confirm('¿Eliminar usuario?')) return;
        try {
            const response = await fetch(`${apiUrlBase}/users_api.php?id=${id}`, { method: 'DELETE' });
            const result = await response.json();
            if (result.success) { location.reload(); }
            else { alert('Error: ' + result.error); }
        } catch (error) { alert('Error de conexión.'); }
    }

    function populateUserTable(users) {
        const tbody = document.getElementById('user-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!users || users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="p-6 text-center">No hay usuarios.</td></tr>';
            return;
        }
        users.forEach(user => {
            const userJson = JSON.stringify({id: user.id, name: user.name, email: user.email, role: user.role}).replace(/'/g, "&apos;");
            tbody.innerHTML += `<tr id="user-row-${user.id}"><td class="px-6 py-4">${user.name}</td><td class="px-6 py-4">${user.email}</td><td class="px-6 py-4"><span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-1 rounded-full">${user.role}</span></td><td class="px-6 py-4 text-center"><button onclick='openModal(${userJson})' class="font-medium text-blue-600">Editar</button><button onclick="deleteUser(${user.id})" class="font-medium text-red-600 ml-4">Eliminar</button></td></tr>`;
        });
    }

    function switchTab(tabName) {
        const contentPanels = ['operaciones', 'checkinero', 'operador', 'digitador', 'roles', 'trazabilidad'];
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
        
        contentPanels.forEach(panel => {
            const content = document.getElementById(`content-${panel}`);
            if (content) {
                content.classList.toggle('hidden', panel !== tabName);
            }
        });
        
        const activeTab = document.getElementById(`tab-${tabName}`);
        if(activeTab) activeTab.classList.add('active');
    }

    function updateCountdownTimers() {
        document.querySelectorAll('.countdown-timer').forEach(timerEl => {
            const endTime = new Date(timerEl.dataset.endTime).getTime();
            if (isNaN(endTime)) return;
            const now = new Date().getTime();
            const distance = endTime - now;

            if (distance < 0) {
                const elapsed = now - endTime;
                const days = Math.floor(elapsed / (1000 * 60 * 60 * 24));
                const hours = Math.floor((elapsed % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((elapsed % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((elapsed % (1000 * 60)) / 1000);
                let elapsedTime = '';
                if (days > 0) elapsedTime += `${days}d `;
                if (hours > 0 || days > 0) elapsedTime += `${hours}h `;
                elapsedTime += `${minutes}m ${seconds}s`;
                timerEl.innerHTML = `Retraso: <span class="text-red-600 font-bold">${elapsedTime}</span>`;
            } else {
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                let timeLeft = '';
                if (days > 0) timeLeft += `${days}d `;
                if (hours > 0 || days > 0) timeLeft += `${hours}h `;
                timeLeft += `${minutes}m ${seconds}s`;
                let textColor = 'text-green-600';
                if (days === 0 && hours < 1) { textColor = 'text-red-600'; }
                else if (days === 0 && hours < 24) { textColor = 'text-yellow-700'; }
                timerEl.innerHTML = `Vence en: <span class="${textColor}">${timeLeft}</span>`;
            }
        });
    }

    function formatCurrency(value) {
        if (value === null || value === undefined) return '$ 0';
        return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(value);
    }

    function populateCheckinsTable(checkins) {
        const tbody = document.getElementById('checkins-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!checkins || checkins.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay registros de check-in.</td></tr>';
            return;
        }
        checkins.forEach(ci => {
            const row = `
                <tr class="border-b">
                    <td class="p-3 font-mono">${ci.invoice_number}</td>
                    <td class="p-3 font-mono">${ci.seal_number}</td>
                    <td class="p-3 text-right">${formatCurrency(ci.declared_value)}</td>
                    <td class="p-3">${ci.route_name}</td>
                    <td class="p-3 text-xs whitespace-nowrap">${new Date(ci.created_at).toLocaleString('es-CO')}</td>
                    <td class="p-3">${ci.checkinero_name}</td>
                    <td class="p-3">${ci.client_name}</td>
                    <td class="p-3">${ci.fund_name || 'N/A'}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }
    
    // MODIFICADO: Ahora hay dos tablas de pendientes, una para cada rol
    function populateOperatorCheckinsTable(checkins, context = 'operador') {
        const tableId = (context === 'digitador') ? 'operator-checkins-table-body-digitador' : 'operator-checkins-table-body';
        const tbody = document.getElementById(tableId);
        if (!tbody) return;
        tbody.innerHTML = '';
        
        const pendingCheckins = checkins.filter(ci => ci.status === 'Pendiente');

        if (pendingCheckins.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay planillas pendientes por detallar.</td></tr>';
            return;
        }
        
        pendingCheckins.forEach(ci => {
            // MODIFICADO: La función llamada ahora sabe desde qué contexto se llama
            const buttonFunction = (context === 'digitador') ? `selectPlanillaForDigitador` : `selectPlanilla`;
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
                        <button onclick="${buttonFunction}('${ci.invoice_number}')" class="bg-blue-500 text-white px-3 py-1 text-xs font-semibold rounded-md hover:bg-blue-600">Seleccionar</button>
                    </td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }
    
    // MODIFICADO: Ahora hay dos tablas de historial, una para cada rol
    function populateOperatorHistoryTable(historyData, context = 'operador') {
        const tableId = (context === 'digitador') ? 'operator-history-table-body-digitador' : 'operator-history-table-body';
        const tbody = document.getElementById(tableId);
        if (!tbody) return;
        tbody.innerHTML = '';

        if (!historyData || historyData.length === 0) {
            const colspan = (currentUserRole === 'Admin' || context === 'digitador') ? 8 : 7;
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-4 text-center text-gray-500">No hay conteos registrados.</td></tr>`;
            return;
        }

        historyData.forEach(item => {
            const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            // Para el admin o el digitador, siempre mostramos la columna del operador
            const operatorColumn = (currentUserRole === 'Admin' || currentUserRole === 'Digitador') ? `<td class="p-3">${item.operator_name}</td>` : '';
            const row = `
                <tr class="border-b">
                    <td class="p-3 font-mono">${item.invoice_number}</td>
                    <td class="p-3">${item.client_name}</td>
                    <td class="p-3 text-right">${formatCurrency(item.declared_value)}</td>
                    <td class="p-3 text-right">${formatCurrency(item.total_counted)}</td>
                    <td class="p-3 text-right ${discrepancyClass}">${formatCurrency(item.discrepancy)}</td>
                    ${operatorColumn}
                    <td class="p-3 text-xs whitespace-nowrap">${new Date(item.count_date).toLocaleString('es-CO')}</td>
                    <td class="p-3 text-xs max-w-xs truncate" title="${item.observations || ''}">${item.observations || 'N/A'}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }
    
    function selectPlanilla(invoiceNumber) {
        document.getElementById('consult-invoice').value = invoiceNumber;
        document.getElementById('consultation-form').dispatchEvent(new Event('submit'));
        window.scrollTo(0, 0); 
    }
    
    // NUEVA FUNCIÓN: Específica para el panel de supervisión del digitador
    function selectPlanillaForDigitador(invoiceNumber) {
        document.getElementById('consult-invoice-digitador').value = invoiceNumber;
        document.getElementById('consultation-form-digitador').dispatchEvent(new Event('submit'));
        window.scrollTo(0, 0); 
    }

    async function handleCheckinSubmit(event) {
        event.preventDefault();
        const payload = {
            invoice_number: document.getElementById('invoice_number').value,
            seal_number: document.getElementById('seal_number').value,
            client_id: document.getElementById('client_id').value,
            route_id: document.getElementById('route_id').value,
            fund_id: document.getElementById('fund_id').value,
            declared_value: document.getElementById('declared_value').value,
        };

        try {
            const response = await fetch('api/checkin_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                alert('Check-in registrado con éxito.');
                location.reload(); 
            } else {
                alert('Error: ' + result.error);
            }
        } catch (error) {
            console.error('Error en el check-in:', error);
            alert('Error de conexión al registrar.');
        }
    }

    // MODIFICADO: Función genérica para manejar la consulta de ambos roles
    async function handleConsultation(event, context = 'operador') {
        event.preventDefault();
        const prefix = (context === 'digitador') ? '-digitador' : '';
        
        const invoiceInput = document.getElementById(`consult-invoice${prefix}`);
        const operatorPanel = document.getElementById(`operator-panel${prefix}`);
        const planilla = invoiceInput.value;

        if (!planilla) {
            alert('Por favor, ingrese un número de planilla.');
            return;
        }

        try {
            const response = await fetch(`api/operator_api.php?planilla=${planilla}`);
            const result = await response.json();

            if (result.success) {
                const data = result.data;
                document.getElementById(`display-invoice${prefix}`).textContent = data.invoice_number;
                document.getElementById(`display-seal${prefix}`).textContent = data.seal_number;
                document.getElementById(`display-client${prefix}`).textContent = data.client_name;
                document.getElementById(`display-declared${prefix}`).textContent = formatCurrency(data.declared_value);
                document.getElementById(`display-declared${prefix}`).dataset.value = data.declared_value;
                document.getElementById(`op-checkin-id${prefix}`).value = data.id;

                document.getElementById(`denomination-form${prefix}`).reset();
                calculateTotals(context);
                operatorPanel.classList.remove('hidden');
            } else {
                alert('Error: ' + result.error);
                operatorPanel.classList.add('hidden');
            }
        } catch (error) {
            console.error('Error en la consulta:', error);
            alert('Error de conexión al consultar la planilla.');
        }
    }

    function updateQty(button, amount) {
        const input = button.parentElement.querySelector('input');
        let currentValue = parseInt(input.value) || 0;
        currentValue += amount;
        if (currentValue < 0) currentValue = 0;
        input.value = currentValue;

        // Determinar el contexto (operador o digitador) para recalcular
        const context = button.closest('#content-digitador') ? 'digitador' : 'operador';
        calculateTotals(context);
    }

    // MODIFICADO: Función genérica para calcular totales en ambos paneles
    function calculateTotals(context = 'operador') {
        const prefix = (context === 'digitador') ? '-digitador' : '';
        const form = document.getElementById(`denomination-form${prefix}`);
        if (!form) return;

        let totalCounted = 0;
        form.querySelectorAll('.denomination-row').forEach(row => {
            const value = parseFloat(row.dataset.value);
            const qty = parseInt(row.querySelector('.denomination-qty').value) || 0;
            const subtotal = value * qty;
            row.querySelector('.subtotal').textContent = formatCurrency(subtotal);
            totalCounted += subtotal;
        });

        const coinsValue = parseFloat(document.getElementById(`coins-value${prefix}`).value) || 0;
        document.getElementById(`coins-subtotal${prefix}`).textContent = formatCurrency(coinsValue);
        totalCounted += coinsValue;

        document.getElementById(`total-counted${prefix}`).textContent = formatCurrency(totalCounted);
        
        const declaredValue = parseFloat(document.getElementById(`display-declared${prefix}`).dataset.value) || 0;
        const discrepancy = totalCounted - declaredValue;
        
        const discrepancyEl = document.getElementById(`discrepancy${prefix}`);
        discrepancyEl.textContent = formatCurrency(discrepancy);
        if (discrepancy !== 0) {
            discrepancyEl.classList.add('text-red-500');
            discrepancyEl.classList.remove('text-green-500');
        } else {
            discrepancyEl.classList.remove('text-red-500');
            discrepancyEl.classList.add('text-green-500');
        }
    }

    async function handleDenominationSave(event) {
        event.preventDefault();
        const payload = {
            check_in_id: document.getElementById('op-checkin-id').value,
            bills_100k: document.querySelector('#denomination-form [data-value="100000"] .denomination-qty').value,
            bills_50k: document.querySelector('#denomination-form [data-value="50000"] .denomination-qty').value,
            bills_20k: document.querySelector('#denomination-form [data-value="20000"] .denomination-qty').value,
            bills_10k: document.querySelector('#denomination-form [data-value="10000"] .denomination-qty').value,
            bills_5k: document.querySelector('#denomination-form [data-value="5000"] .denomination-qty').value,
            bills_2k: document.querySelector('#denomination-form [data-value="2000"] .denomination-qty').value,
            coins: parseFloat(document.getElementById('coins-value').value) || 0,
            total_counted: 0,
            discrepancy: 0,
            observations: document.getElementById('observations').value
        };

        let total = 0;
        total += payload.bills_100k * 100000;
        total += payload.bills_50k * 50000;
        total += payload.bills_20k * 20000;
        total += payload.bills_10k * 10000;
        total += payload.bills_5k * 5000;
        total += payload.bills_2k * 2000;
        total += payload.coins;
        payload.total_counted = total;
        
        const declaredValue = parseFloat(document.getElementById('display-declared').dataset.value) || 0;
        payload.discrepancy = total - declaredValue;

        try {
            const response = await fetch('api/operator_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                alert(result.message);
                location.reload(); 
            } else {
                alert('Error al guardar: ' + result.error);
            }
        } catch (error) {
            console.error('Error al guardar conteo:', error);
            alert('Error de conexión al guardar el conteo.');
        }
    }

    function populateDiscrepancyTraceability(cases) {
        const tbody = document.getElementById('discrepancy-traceability-body');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (!cases || cases.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-gray-500">No hay casos de discrepancia asignados.</td></tr>`;
            return;
        }

        cases.forEach(item => {
            const discrepancyClass = item.discrepancy != 0 ? 'text-red-600 font-bold' : 'text-green-600';
            
            let resolutionHTML = '';
            if (item.task_status === 'Resuelta') {
                resolutionHTML = `<div class="text-xs p-2 bg-gray-100 rounded-md border">${item.resolution_note || 'N/A'}</div>`;
            } else {
                resolutionHTML = `
                    <textarea id="resolution-note-${item.task_id}" class="w-full text-xs p-2 border rounded-md" rows="3" placeholder="Documente aquí la causa y la solución del caso..."></textarea>
                    <button onclick="resolveDiscrepancy(${item.task_id})" class="mt-2 w-full bg-green-600 text-white text-xs font-bold py-2 rounded-md hover:bg-green-700">Guardar y Resolver Caso</button>
                `;
            }

            const statusHTML = item.task_status === 'Resuelta' 
                ? `<span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-1 rounded-full">Resuelta</span>`
                : `<span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-1 rounded-full">Pendiente</span>`;

            const row = `
                <tr class="border-b align-top">
                    <td class="px-4 py-4 font-mono">${item.invoice_number}</td>
                    <td class="px-4 py-4 text-xs">
                        <p><strong>Check-in:</strong> ${item.checkinero_name || 'N/A'}</p>
                        <p><strong>Conteo:</strong> ${item.operator_name || 'N/A'}</p>
                    </td>
                    <td class="px-4 py-4 text-xs">
                        <p><strong>Declarado:</strong> ${formatCurrency(item.declared_value)}</p>
                        <p><strong>Contado:</strong> ${formatCurrency(item.total_counted)}</p>
                        <p class="${discrepancyClass}"><strong>Diferencia:</strong> ${formatCurrency(item.discrepancy)}</p>
                    </td>
                    <td class="px-4 py-4 text-xs">
                        <p><strong>Alerta:</strong> ${new Date(item.alert_date).toLocaleString('es-CO')}</p>
                        <p><strong>Conteo:</strong> ${new Date(item.count_date).toLocaleString('es-CO')}</p>
                    </td>
                    <td class="px-4 py-4">${resolutionHTML}</td>
                    <td class="px-4 py-4">${statusHTML}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }
    
    async function resolveDiscrepancy(taskId) {
        const noteTextarea = document.getElementById(`resolution-note-${taskId}`);
        const resolutionNote = noteTextarea.value;

        if (!resolutionNote.trim()) {
            alert('Por favor, escriba la nota de resolución antes de guardar.');
            return;
        }

        if (!confirm('¿Está seguro de que desea cerrar este caso? Esta acción no se puede deshacer.')) {
            return;
        }

        const payload = {
            task_id: taskId,
            resolution_note: resolutionNote
        };

        try {
            const response = await fetch(`${apiUrlBase}/discrepancy_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (!response.ok) throw new Error(result.error || 'Error del servidor');

            alert(result.message);
            location.reload();
        } catch (error) {
            console.error('Error resolviendo el caso:', error);
            alert('Error: ' + error.message);
        }
    }

    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
    const completedTasksData = <?php echo json_encode($completed_tasks); ?>;

    function populateTrazabilidadTable(tasks) {
        const tbody = document.getElementById('trazabilidad-tbody');
        tbody.innerHTML = '';
        if (!tasks || tasks.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-6 text-center text-gray-500">No hay tareas que coincidan con los filtros.</td></tr>';
            return;
        }
        tasks.forEach(task => {
            const row = `
                <tr class="border-b">
                    <td class="px-6 py-4 font-medium">${task.title || ''}</td>
                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="${task.instruction || ''}">${task.instruction || ''}</td>
                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.priority)}">${task.priority || ''}</span></td>
                    <td class="px-6 py-4"><span class="text-xs font-medium px-2.5 py-1 rounded-full ${getPriorityClass(task.final_priority)}">${task.final_priority || ''}</span></td>
                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.created_at)}</td>
                    <td class="px-6 py-4 whitespace-nowrap">${formatDate(task.completed_at)}</td>
                    <td class="px-6 py-4 font-mono">${task.response_time || ''}</td>
                    <td class="px-6 py-4">${task.assigned_to || ''}</td>
                    <td class="px-6 py-4 font-semibold">${task.completed_by || ''}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });
    }

    function getPriorityClass(priority) {
        if (priority === 'Alta' || priority === 'Critica') return 'bg-red-100 text-red-800';
        if (priority === 'Media') return 'bg-yellow-100 text-yellow-800';
        return 'bg-gray-100 text-gray-800';
    }

    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('es-CO', { day: '2-digit', month: 'short' }) + ' ' + date.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
    }

    function applyTrazabilidadFilters() {
        const startDate = document.getElementById('filter-start-date').value;
        const endDate = document.getElementById('filter-end-date').value;
        const user = document.getElementById('filter-user').value;
        const checker = document.getElementById('filter-checker').value;
        const priority = document.getElementById('filter-priority').value;

        let filteredTasks = completedTasksData.filter(task => {
            let isValid = true;
            const taskStartDate = task.created_at.split(' ')[0];

            if (startDate && taskStartDate < startDate) isValid = false;
            if (endDate && taskStartDate > endDate) isValid = false;
            if (user && task.assigned_to !== user) isValid = false;
            if (checker && task.completed_by !== checker) isValid = false;
            if (priority && task.final_priority !== priority) isValid = false;

            return isValid;
        });
        populateTrazabilidadTable(filteredTasks);
    }

    function sortTableByDate(column) {
        const header = document.querySelector(`th[data-column-name="${column}"]`);
        if (!header) return;

        const currentDirection = header.dataset.sortDir || 'none';
        const nextDirection = (currentDirection === 'desc') ? 'asc' : 'desc';

        completedTasksData.sort((a, b) => {
            const dateA = new Date(a[column]).getTime();
            const dateB = new Date(b[column]).getTime();
            if (isNaN(dateA)) return 1;
            if (isNaN(dateB)) return -1;
            return nextDirection === 'asc' ? dateA - dateB : dateB - a[column];
        });

        document.querySelectorAll('th.sortable').forEach(th => {
            const iconSpan = th.querySelector('span');
            if (th === header) {
                th.dataset.sortDir = nextDirection;
                iconSpan.textContent = nextDirection === 'asc' ? ' ▲' : ' ▼';
            } else {
                delete th.dataset.sortDir;
                iconSpan.textContent = '';
            }
        });

        applyTrazabilidadFilters();
    }

    function exportToExcel() {
        const table = document.getElementById("trazabilidad-table");
        const wb = XLSX.utils.table_to_book(table, { sheet: "Trazabilidad" });
        XLSX.writeFile(wb, "Trazabilidad_EAGLE.xlsx");
    }
    <?php endif; ?>

    // === SCRIPT PARA EL PANEL DEL DIGITADOR ===
    const btnLlegadas = document.getElementById('btn-llegadas');
    const btnCierre = document.getElementById('btn-cierre');
    const btnInformes = document.getElementById('btn-informes');
    
    const panelLlegadas = document.getElementById('panel-llegadas');
    const panelCierre = document.getElementById('panel-cierre');
    const panelInformes = document.getElementById('panel-informes');

    const setActiveButton = (activeBtn) => {
        if (!activeBtn) return;
        [btnLlegadas, btnCierre, btnInformes].forEach(btn => {
            if(btn) {
                btn.classList.remove('bg-blue-600', 'text-white');
                btn.classList.add('bg-gray-200', 'text-gray-700');
            }
        });
        activeBtn.classList.add('bg-blue-600', 'text-white');
        activeBtn.classList.remove('bg-gray-200', 'text-gray-700');
    };

    const showPanel = (activePanel) => {
        if (!activePanel) return;
        [panelLlegadas, panelCierre, panelInformes].forEach(panel => {
            if(panel) panel.classList.add('hidden');
        });
        activePanel.classList.remove('hidden');
    };

    if (btnLlegadas) {
        btnLlegadas.addEventListener('click', () => {
            setActiveButton(btnLlegadas);
            showPanel(panelLlegadas);
            loadLlegadas();
        });
    }

    if (btnCierre) {
        btnCierre.addEventListener('click', () => {
            setActiveButton(btnCierre);
            showPanel(panelCierre);
            loadFundsForCierre();
        });
    }

    if (btnInformes) {
        btnInformes.addEventListener('click', () => {
            setActiveButton(btnInformes);
            showPanel(panelInformes);
            loadInformes();
        });
    }

    async function loadLlegadas() {
        const tbody = document.getElementById('llegadas-table-body');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center">Cargando...</td></tr>';
        try {
            const response = await fetch(`${apiUrlBase}/digitador_llegadas_api.php`);
            const llegadas = await response.json();
            tbody.innerHTML = '';
            if (llegadas.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-gray-500">No hay llegadas pendientes.</td></tr>';
                return;
            }
            llegadas.forEach(item => {
                const row = `
                    <tr class="border-b">
                        <td class="p-3 font-mono">${item.invoice_number}</td> <td class="p-3 font-mono">${item.seal_number}</td>
                        <td class="p-3">${formatCurrency(item.declared_value)}</td> <td class="p-3">${item.route_name}</td>
                        <td class="p-3 text-xs">${new Date(item.created_at).toLocaleString('es-CO')}</td>
                        <td class="p-3">${item.checkinero_name}</td> <td class="p-3">${item.client_name}</td> <td class="p-3">${item.fund_name || 'N/A'}</td>
                    </tr>`;
                tbody.innerHTML += row;
            });
        } catch (error) {
            console.error('Error cargando llegadas:', error);
            tbody.innerHTML = '<tr><td colspan="8" class="p-4 text-center text-red-500">Error al cargar datos.</td></tr>';
        }
    }

    async function loadFundsForCierre() {
        const container = document.getElementById('funds-list-container');
        if (!container) return;
        container.innerHTML = '<p class="text-center">Cargando fondos...</p>';
        document.getElementById('services-list-container').innerHTML = '<p class="text-gray-500">Seleccione un fondo para ver sus servicios.</p>';
        
        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=list_funds`);
            const funds = await response.json();
            container.innerHTML = '';
            if (funds.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-center">No hay fondos con servicios activos.</p>';
                return;
            }
            funds.forEach(fund => {
                const fundElement = `
                    <div class="p-3 border rounded-lg cursor-pointer hover:bg-gray-100" onclick="loadServicesForFund(${fund.id}, this)">
                        <div class="flex justify-between items-center">
                            <p class="font-semibold">${fund.name}</p>
                            <span class="text-xs text-gray-500">${fund.client_name}</span>
                        </div>
                    </div>`;
                container.innerHTML += fundElement;
            });
        } catch (error) {
            console.error('Error cargando fondos:', error);
            container.innerHTML = '<p class="text-center text-red-500">Error al cargar fondos.</p>';
        }
    }
    
    async function loadServicesForFund(fundId, element) {
        document.querySelectorAll('#funds-list-container > div').forEach(el => el.classList.remove('bg-blue-100', 'border-blue-400'));
        element.classList.add('bg-blue-100', 'border-blue-400');
        
        const container = document.getElementById('services-list-container');
        if (!container) return;
        container.innerHTML = '<p class="text-center">Cargando servicios...</p>';
        
        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=get_services&fund_id=${fundId}`);
            const services = await response.json();
            container.innerHTML = '';
            if (services.length === 0) {
                container.innerHTML = '<p class="text-gray-500">Este fondo no tiene servicios activos.</p>';
                return;
            }
            services.forEach(service => {
                const serviceElement = `
                    <div class="p-3 border rounded-lg flex justify-between items-center">
                        <div>
                            <p class="font-mono"><strong>Planilla:</strong> ${service.invoice_number}</p>
                            <p class="text-xs text-gray-500">${service.client_name} - ${formatCurrency(service.declared_value)}</p>
                        </div>
                        <button onclick="closeService(${service.id})" class="bg-teal-500 text-white text-xs font-bold py-2 px-3 rounded-md hover:bg-teal-600">
                            Cerrar Servicio
                        </button>
                    </div>`;
                container.innerHTML += serviceElement;
            });
        } catch (error) {
            console.error('Error cargando servicios:', error);
            container.innerHTML = '<p class="text-center text-red-500">Error al cargar servicios.</p>';
        }
    }
    
    async function closeService(serviceId) {
        if (!confirm('¿Está seguro de que desea cerrar este servicio? Esta acción hará que desaparezca de las listas de pendientes del Checkinero y Operador.')) return;
        
        try {
            const response = await fetch(`${apiUrlBase}/digitador_cierre_api.php?action=close_service`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ service_id: serviceId })
            });
            const result = await response.json();
            if (result.success) {
                alert('Servicio cerrado exitosamente.');
                // Recargar toda la página para que los cambios se reflejen en todas las vistas
                location.reload(); 
            } else {
                alert('Error: ' + (result.error || 'No se pudo cerrar el servicio.'));
            }
        } catch (error) {
            console.error('Error al cerrar servicio:', error);
            alert('Error de conexión.');
        }
    }
    
    async function loadInformes() {
        const tbody = document.getElementById('informes-table-body');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="p-4 text-center">Cargando...</td></tr>';
        document.getElementById('select-all-informes').checked = false;
        updateInformeCount();
        
        try {
            const response = await fetch(`${apiUrlBase}/digitador_informes_api.php`);
            const servicios = await response.json();
            tbody.innerHTML = '';
            if (servicios.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="p-4 text-center text-gray-500">No hay servicios cerrados para informar.</td></tr>';
                return;
            }
            servicios.forEach(item => {
                const row = `
                    <tr class="border-b">
                        <td class="p-3"><input type="checkbox" class="informe-checkbox" data-planilla="${item.planilla}" data-sello="${item.sello}" data-total="${item.total}" data-cliente="${item.cliente}"></td>
                        <td class="p-3 font-mono">${item.planilla}</td> <td class="p-3 font-mono">${item.sello}</td>
                        <td class="p-3">${formatCurrency(item.total)}</td> <td class="p-3">${item.fondo || 'N/A'}</td> <td class="p-3">${item.cliente}</td>
                    </tr>`;
                tbody.innerHTML += row;
            });
            
            document.querySelectorAll('.informe-checkbox').forEach(cb => cb.addEventListener('change', updateInformeCount));
            document.getElementById('select-all-informes').addEventListener('change', (e) => {
                document.querySelectorAll('.informe-checkbox').forEach(cb => cb.checked = e.target.checked);
                updateInformeCount();
            });

        } catch (error) {
            console.error('Error cargando informes:', error);
            tbody.innerHTML = '<tr><td colspan="6" class="p-4 text-center text-red-500">Error al cargar datos.</td></tr>';
        }
    }

    function updateInformeCount() {
        const count = document.querySelectorAll('.informe-checkbox:checked').length;
        document.getElementById('selected-informe-count').textContent = count;
    }

    function generatePDF() {
        if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
            alert('Error: La librería jsPDF principal no se cargó. Revise su conexión a internet y la consola (F12).');
            return;
        }
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        if (typeof doc.autoTable === 'undefined') {
            alert('Error: La extensión autoTable para generar PDF no se cargó. Revise la conexión y la consola (F12).');
            return;
        }

        const selectedRows = [];
        document.querySelectorAll('.informe-checkbox:checked').forEach(checkbox => {
            const ds = checkbox.dataset;
            selectedRows.push([
                ds.planilla,
                ds.sello,
                formatCurrency(ds.total),
                ds.cliente
            ]);
        });

        if (selectedRows.length === 0) {
            alert('Por favor, seleccione al menos un servicio para generar el informe.');
            return;
        }

        doc.setFontSize(18);
        doc.text("Informe de Servicios Cerrados", 14, 22);
        
        doc.autoTable({
            head: [['Planilla', 'Sello', 'Total Contado', 'Cliente']],
            body: selectedRows,
            startY: 30,
        });

        const pageCount = doc.internal.getNumberOfPages();
        for(let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(10);
            doc.text(`Generado por EAGLE 3.0 - ${new Date().toLocaleDateString('es-CO')}`, 14, doc.internal.pageSize.height - 10);
        }

        doc.save("informe-servicios.pdf");
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('user-table-body')) {
            populateUserTable(adminUsersData);
        }
        <?php if ($_SESSION['user_role'] === 'Admin'): ?>
        if(document.getElementById('trazabilidad-tbody')){
            populateTrazabilidadTable(completedTasksData);
            const defaultSortHeader = document.querySelector(`th[data-column-name="completed_at"]`);
            if (defaultSortHeader) {
                defaultSortHeader.dataset.sortDir = 'desc';
                defaultSortHeader.querySelector('span').textContent = ' ▼';
            }
        }
        <?php endif; ?>

        if (document.getElementById('content-checkinero')) {
            populateCheckinsTable(initialCheckins);
            document.getElementById('checkin-form').addEventListener('submit', handleCheckinSubmit);

            const clientSelect = document.getElementById('client_id');
            const fundSelect = document.getElementById('fund_id');

            clientSelect.addEventListener('change', async () => {
                const clientId = clientSelect.value;
                fundSelect.innerHTML = '<option value="">Cargando...</option>';
                fundSelect.disabled = true;
                fundSelect.classList.add('bg-gray-200');

                if (!clientId) {
                    fundSelect.innerHTML = '<option value="">Seleccione un cliente primero...</option>';
                    return;
                }

                try {
                    const response = await fetch(`api/funds_api.php?client_id=${clientId}`);
                    const funds = await response.json();
                    
                    fundSelect.innerHTML = '';
                    if (funds.length > 0) {
                        funds.forEach(fund => {
                            const option = new Option(fund.name, fund.id);
                            fundSelect.add(option);
                        });
                        fundSelect.disabled = false;
                        fundSelect.classList.remove('bg-gray-200');
                    } else {
                        fundSelect.innerHTML = '<option value="">Este cliente no tiene fondos</option>';
                    }
                } catch (error) {
                    console.error('Error fetching funds:', error);
                    fundSelect.innerHTML = '<option value="">Error al cargar fondos</option>';
                }
            });
        }
        
        if (document.getElementById('content-operador')) {
            populateOperatorCheckinsTable(initialCheckins, 'operador');
            populateOperatorHistoryTable(operatorHistoryData, 'operador');
            document.getElementById('consultation-form').addEventListener('submit', handleConsultation);
            document.getElementById('denomination-form').addEventListener('submit', handleDenominationSave);
        }
        
        if (document.getElementById('content-digitador')) {
            // Cargar datos para el panel de supervisión
            populateOperatorCheckinsTable(initialCheckins, 'digitador');
            populateOperatorHistoryTable(operatorHistoryData, 'digitador');
            document.getElementById('consultation-form-digitador').addEventListener('submit', (e) => handleConsultation(e, 'digitador'));
            
            // Cargar datos para las herramientas propias del digitador
            loadLlegadas();
            populateDiscrepancyTraceability(discrepancyCases);
        }

        updateReminderCount();
        updateCountdownTimers();
        setInterval(updateCountdownTimers, 1000);

        const startDateInput = document.getElementById('manual-task-start');
        const endDateInput = document.getElementById('manual-task-end');
        if(startDateInput) {
            const getLocalISOString = (date) => {
                const pad = (num) => num.toString().padStart(2, '0');
                return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
            };
            const now = new Date();
            const nowString = getLocalISOString(now);
            startDateInput.min = nowString;
            endDateInput.min = nowString;
            if (!startDateInput.value) startDateInput.value = nowString;
            if (!endDateInput.value) endDateInput.value = nowString;
            startDateInput.addEventListener('input', () => {
                if (startDateInput.value) {
                    endDateInput.min = startDateInput.value;
                    if (endDateInput.value < startDateInput.value) {
                        endDateInput.value = startDateInput.value;
                    }
                }
            });
        }
    });
    </script>
</body>
</html>
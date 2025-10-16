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
    // Si no es admin, solo puede ver las tareas que tiene asignadas.
    $user_filter = " AND t.assigned_to_user_id = {$current_user_id}";
}

// 1. Cargar Alertas Pendientes (con filtro de usuario)
// Para usuarios no-admin, el LEFT JOIN se comportará como INNER JOIN si hay filtro,
// mostrando solo alertas que tienen una tarea y esa tarea está asignada al usuario.
$alerts_sql = "SELECT a.*, t.id as task_id, t.status as task_status, t.assigned_to_user_id, u_assigned.name as assigned_to_name, t.type as task_type, t.instruction as task_instruction, t.start_datetime, t.end_datetime
               FROM alerts a
               LEFT JOIN tasks t ON t.id = (SELECT MAX(id) FROM tasks WHERE alert_id = a.id AND status != 'Cancelada')
               LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
               WHERE a.status NOT IN ('Resuelta', 'Cancelada') {$user_filter}";
if($current_user_role !== 'Admin') {
    $alerts_sql = "SELECT a.*, t.id as task_id, t.status as task_status, t.assigned_to_user_id, u_assigned.name as assigned_to_name, t.type as task_type, t.instruction as task_instruction, t.start_datetime, t.end_datetime
                   FROM alerts a
                   INNER JOIN tasks t ON t.id = (SELECT MAX(id) FROM tasks WHERE alert_id = a.id AND status != 'Cancelada')
                   LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
                   WHERE a.status NOT IN ('Resuelta', 'Cancelada') AND t.assigned_to_user_id = {$current_user_id}";
} else {
    // Para el admin, queremos ver también las alertas que no han sido asignadas
    $alerts_sql = "SELECT a.*, t.id as task_id, t.status as task_status, t.assigned_to_user_id, u_assigned.name as assigned_to_name, t.type as task_type, t.instruction as task_instruction, t.start_datetime, t.end_datetime
               FROM alerts a
               LEFT JOIN tasks t ON t.id = (SELECT MAX(id) FROM tasks WHERE alert_id = a.id AND status != 'Cancelada')
               LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
               WHERE a.status NOT IN ('Resuelta', 'Cancelada')";
}


$alerts_result = $conn->query($alerts_sql);
if ($alerts_result) {
    while ($row = $alerts_result->fetch_assoc()) {
        $row['item_type'] = 'alert';
        $all_pending_items[] = $row;
    }
}

// 2. Cargar Tareas Manuales Pendientes
$manual_tasks_sql = "SELECT t.id, t.id as task_id, t.title, t.instruction, t.priority, t.status as task_status, t.assigned_to_user_id, u.name as assigned_to_name, t.start_datetime, t.end_datetime
                     FROM tasks t
                     LEFT JOIN users u ON t.assigned_to_user_id = u.id
                     WHERE t.alert_id IS NULL AND t.type = 'Manual' AND t.status = 'Pendiente' {$user_filter}";
$manual_tasks_result = $conn->query($manual_tasks_sql);
if ($manual_tasks_result) {
    while($row = $manual_tasks_result->fetch_assoc()) {
        $row['item_type'] = 'manual_task';
        $all_pending_items[] = $row;
    }
}

// 3. Procesar todos los items para prioridad dinámica y popular las diferentes listas
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

        if ($diff_minutes >= 0) { // Tarea vencida
            $current_priority = 'Alta';
        } elseif ($diff_minutes > -15 && ($original_priority === 'Baja' || $original_priority === 'Media')) { // A 15 mins o menos de vencer
            $current_priority = 'Media';
        }
    }
    $item['current_priority'] = $current_priority;

    // Popula las listas para la VISTA PRINCIPAL en la página (usa prioridad dinámica)
    if ($current_priority === 'Critica' || $current_priority === 'Alta') {
        $main_priority_items[] = $item;
    } else {
        $main_non_priority_items[] = $item;
    }

    // Popula las listas para los PANELES DE ICONOS
    // ***** LÍNEA CORREGIDA *****
    // Se cambia $original_priority por $current_priority para que las tareas vencidas también aparezcan aquí.
    if ($current_priority === 'Critica' || $current_priority === 'Alta') {
        $panel_high_priority_items[] = $item;
    }
    // Panel Reloj: Basado en la prioridad ACTUAL (para incluir tareas que están por vencer)
    if ($current_priority === 'Media') {
        $panel_medium_priority_items[] = $item;
    }
}


// 4. Ordenar las listas por prioridad
$priority_order = ['Critica' => 4, 'Alta' => 3, 'Media' => 2, 'Baja' => 1];
usort($main_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });
usort($main_non_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });
usort($panel_high_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });
usort($panel_medium_priority_items, function($a, $b) use ($priority_order) { return ($priority_order[$b['current_priority']] ?? 0) <=> ($priority_order[$a['current_priority']] ?? 0); });


// 5. Cargar Tareas Completadas (Admin)
$completed_tasks = [];
if ($_SESSION['user_role'] === 'Admin') {
    $completed_result = $conn->query(
        "SELECT
            t.id,
            COALESCE(a.title, t.title) as title,
            t.instruction,
            t.priority,
            t.start_datetime,
            t.end_datetime,
            u_assigned.name as assigned_to,
            u_completed.name as completed_by,
            t.created_at,
            t.completed_at,
            TIMEDIFF(t.completed_at, t.created_at) as response_time
         FROM tasks t
         LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.id
         LEFT JOIN users u_completed ON t.completed_by_user_id = u_completed.id
         LEFT JOIN alerts a ON t.alert_id = a.id
         WHERE t.status = 'Completada'
         ORDER BY t.completed_at DESC"
    );
    if ($completed_result) {
        while($row = $completed_result->fetch_assoc()){
            $final_priority = $row['priority'];
            if (!empty($row['end_datetime']) && !empty($row['completed_at'])) {
                $end_time = new DateTime($row['end_datetime']);
                $completed_time = new DateTime($row['completed_at']);
                if ($completed_time > $end_time) {
                    $final_priority = 'Alta';
                }
            }
            $row['final_priority'] = $final_priority;
            $completed_tasks[] = $row;
        }
    }
}

// Cargar recaudos y recordatorios
$recaudos = [];
$recaudos_result = $conn->query("SELECT * FROM recaudos ORDER BY close_time_scheduled ASC");
if ($recaudos_result) { while ($row = $recaudos_result->fetch_assoc()) { $recaudos[] = $row; } }

$user_reminders = [];
$reminders_result = $conn->query("SELECT id, message, created_at FROM reminders WHERE user_id = $current_user_id AND is_read = 0 ORDER BY created_at DESC");
if($reminders_result) { while($row = $reminders_result->fetch_assoc()){ $user_reminders[] = $row; } }

// Contadores para widgets y badges
$total_alerts_count_for_user = count($all_pending_items);
$priority_summary_count = count($main_priority_items);
$high_priority_badge_count = count($panel_high_priority_items);
$medium_priority_badge_count = count($panel_medium_priority_items);


$conn->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EAGLE 3.0 - Sistema de Alertas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .nav-tab { cursor: pointer; padding: 0.75rem 1.5rem; font-weight: 500; color: #4b5563; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .nav-tab:hover { color: #111827; }
        .nav-tab.active { color: #2563eb; border-bottom-color: #2563eb; }
        #user-modal-overlay, #reminders-panel, #task-notifications-panel, #medium-priority-panel { transition: opacity 0.3s ease; }
        .task-form, .cash-breakdown { transition: all 0.4s ease-in-out; max-height: 0; overflow: hidden; padding-top: 0; padding-bottom: 0; opacity: 0;}
        .task-form.active, .cash-breakdown.active { max-height: 600px; padding-top: 1rem; padding-bottom: 1rem; opacity: 1;}
        .details-row { border-top: 1px solid #e5e7eb; }
        @keyframes fadeInOut {
            0%, 100% { opacity: 0; transform: translateY(-20px); }
            10%, 90% { opacity: 1; transform: translateY(0); }
        }
        .notification-toast {
            animation: fadeInOut 5s ease-in-out forwards;
        }
        @keyframes pulse-red {
            0%, 100% { color: #ef4444; }
            50% { color: #7f1d1d; }
        }
        @keyframes pulse-yellow {
            0%, 100% { color: #f59e0b; }
            50% { color: #92400e; }
        }
        .animate-pulse-red { animation: pulse-red 1.5s infinite; }
        .animate-pulse-yellow { animation: pulse-yellow 1.5s infinite; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div id="toast-container" class="fixed top-5 right-5 z-50 space-y-2"></div>

    <div id="user-modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
        <div id="user-modal" class="bg-white rounded-xl shadow-2xl w-full max-w-md transform transition-all scale-95 opacity-0">
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
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6H5a2 2 0 00-2 2zm0 0h7"></path></svg>
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
                                <?php
                                    $color_class = $item['current_priority'] === 'Critica' ? 'red' : 'orange';
                                ?>
                                <div class="p-2 bg-<?php echo $color_class; ?>-50 rounded-md border border-<?php echo $color_class; ?>-200 text-sm">
                                    <p class="font-semibold text-<?php echo $color_class; ?>-800"><?php echo htmlspecialchars($item['title']); ?></p>
                                    <p class="text-gray-700 text-xs mt-1"><?php echo htmlspecialchars($item['item_type'] === 'manual_task' ? $item['instruction'] : $item['description']); ?></p>
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
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
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
                                        <button onclick="deleteReminder(<?php echo $reminder['id']; ?>, this)" class="text-red-400 hover:text-red-600 font-bold text-lg leading-none p-1 -mt-1 -mr-1">&times;</button>
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
                <div class="-mb-px flex space-x-4">
                    <button id="tab-operaciones" class="nav-tab active" onclick="switchTab('operaciones')">Panel de Operaciones</button>
                    <?php if ($_SESSION['user_role'] === 'Admin'): ?>
                        <button id="tab-roles" class="nav-tab" onclick="switchTab('roles')">Gestión de Roles</button>
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
                                $is_assigned = $item['assigned_to_user_id'] !== null;
                                $priority_to_use = $item['current_priority'];
                                $color_map = [
                                    'Critica' => ['bg' => 'bg-red-100', 'border' => 'border-red-500', 'text' => 'text-red-800', 'badge' => 'bg-red-200'],
                                    'Alta' => ['bg' => 'bg-orange-100', 'border' => 'border-orange-500', 'text' => 'text-orange-800', 'badge' => 'bg-orange-200']
                                ];
                                $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                            ?>
                            <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $id; ?>" data-end-time="<?php echo $item['end_datetime']; ?>" data-assigned-to="<?php echo $item['assigned_to_user_id']; ?>" data-original-priority="<?php echo $item['priority']; ?>">
                                <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                    <div class="flex justify-between items-start">
                                        <p class="font-semibold <?php echo $color['text']; ?> text-lg"><?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title']); ?> <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full priority-badge"><?php echo strtoupper($priority_to_use); ?></span></p>
                                        <?php if ($is_assigned && isset($item['task_status']) && $item['task_status'] === 'Pendiente'): ?>
                                            <button onclick="completeTask(<?php echo $item['task_id']; ?>)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300" title="Marcar como completada">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? $item['instruction'] : $item['description']); ?></p>
                                    
                                    <?php if (!empty($item['end_datetime'])): ?>
                                        <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                    <?php endif; ?>

                                    <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                        <button onclick="toggleForm('assign-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo $is_assigned ? 'Re-asignar' : 'Asignar'; ?></button>
                                        <button onclick="toggleForm('reminder-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                        <div class="flex-grow text-right text-sm"><?php if($is_assigned): ?><span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?></span><?php endif; ?></div>
                                    </div>
                                </div>
                                
                                <div id="assign-form-<?php echo $id; ?>" class="task-form bg-gray-50 px-4">
                                    <h4 class="text-sm font-semibold mb-2"><?php echo $is_assigned ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                    <select id="assign-user-<?php echo $id; ?>" class="w-full p-2 text-sm border rounded-md">
                                        <?php
                                        $suggested_role = !$is_manual ? ($item['suggested_role'] ?? null) : null;
                                        foreach ($all_users as $user) {
                                            if (!$suggested_role || $user['role'] === $suggested_role) {
                                                $selected = ($user['id'] == $item['assigned_to_user_id']) ? 'selected' : '';
                                                echo "<option value='{$user['id']}' {$selected}>" . htmlspecialchars($user['name']) . " ({$user['role']})</option>";
                                            }
                                        }
                                        ?>
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
                                
                                <div><label for="manual-task-user" class="text-sm font-medium">Asignar a</label><select id="manual-task-user" required class="w-full p-2 text-sm border rounded-md mt-1"><option value="">Seleccionar...</option><?php foreach ($all_users as $user):?><option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?> (<?php echo $user['role']; ?>)</option><?php endforeach; ?></select></div>
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
                                        $is_assigned = $item['assigned_to_user_id'] !== null;
                                        $priority_to_use = $item['current_priority'];
                                        $color_map = [
                                            'Media' => ['bg' => 'bg-yellow-100', 'border' => 'border-yellow-400', 'text' => 'text-yellow-800', 'badge' => 'bg-yellow-200'],
                                            'Baja'  => ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200']
                                        ];
                                        $color = $color_map[$priority_to_use] ?? ['bg' => 'bg-gray-100', 'border' => 'border-gray-400', 'text' => 'text-gray-800', 'badge' => 'bg-gray-200'];
                                    ?>
                                    <div class="bg-white rounded-lg shadow-md overflow-hidden task-card" data-task-id="<?php echo $id; ?>" data-end-time="<?php echo $item['end_datetime']; ?>" data-assigned-to="<?php echo $item['assigned_to_user_id']; ?>" data-original-priority="<?php echo $item['priority']; ?>">
                                        <div class="p-4 <?php echo $color['bg']; ?> border-l-8 <?php echo $color['border']; ?>">
                                            <div class="flex justify-between items-start">
                                                <p class="font-semibold <?php echo $color['text']; ?> text-md"><?php echo ($is_manual ? 'Tarea: ' : '') . htmlspecialchars($item['title']); ?> <span class="ml-2 <?php echo $color['badge'].' '.$color['text']; ?> text-xs font-bold px-2 py-0.5 rounded-full priority-badge"><?php echo strtoupper($priority_to_use); ?></span></p>
                                                <?php if ($is_assigned && isset($item['task_status']) && $item['task_status'] === 'Pendiente'): ?>
                                                    <button onclick="completeTask(<?php echo $item['task_id']; ?>)" class="p-1 bg-green-200 text-green-700 rounded-full hover:bg-green-300" title="Marcar como completada">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm mt-1"><?php echo htmlspecialchars($is_manual ? $item['instruction'] : $item['description']); ?></p>
                                            
                                            <?php if (!empty($item['end_datetime'])): ?>
                                                <div class="countdown-timer text-sm font-bold mt-2" data-end-time="<?php echo htmlspecialchars($item['end_datetime']); ?>"></div>
                                            <?php endif; ?>

                                            <div class="mt-4 flex items-center space-x-4 border-t pt-3">
                                                <button onclick="toggleForm('assign-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-blue-600 hover:text-blue-800"><?php echo $is_assigned ? 'Re-asignar' : 'Asignar'; ?></button>
                                                <button onclick="toggleForm('reminder-form-<?php echo $id; ?>', this)" class="text-sm font-medium text-gray-600 hover:text-gray-800">Recordatorio</button>
                                                <div class="flex-grow text-right text-sm"><?php if($is_assigned): ?><span class="font-semibold text-green-700">Asignada a: <?php echo htmlspecialchars($item['assigned_to_name']); ?></span><?php endif; ?></div>
                                            </div>
                                        </div>
                                        
                                        <div id="assign-form-<?php echo $id; ?>" class="task-form bg-gray-50 px-4">
                                            <h4 class="text-sm font-semibold mb-2"><?php echo $is_assigned ? 'Re-asignar' : 'Asignar'; ?> Tarea</h4>
                                            <select id="assign-user-<?php echo $id; ?>" class="w-full p-2 text-sm border rounded-md">
                                                <?php
                                                $suggested_role = !$is_manual ? ($item['suggested_role'] ?? null) : null;
                                                foreach ($all_users as $user) {
                                                    if (!$suggested_role || $user['role'] === $suggested_role) {
                                                        $selected = ($user['id'] == $item['assigned_to_user_id']) ? 'selected' : '';
                                                        echo "<option value='{$user['id']}' {$selected}>" . htmlspecialchars($user['name']) . " ({$user['role']})</option>";
                                                    }
                                                }
                                                ?>
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
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($_SESSION['user_role'] === 'Admin'): ?>
            <div id="content-roles" class="hidden">
                 <div class="flex justify-between items-center mb-4"><h2 class="text-xl font-bold">Gestionar Usuarios</h2><button onclick="openModal()" class="bg-green-600 text-white font-semibold px-4 py-2 rounded-lg">Agregar Usuario</button></div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm"><thead class="bg-gray-50"><tr class="text-left"><th class="px-6 py-3">Nombre</th><th class="px-6 py-3">Email</th><th class="px-6 py-3">Rol</th><th class="px-6 py-3 text-center">Acciones</th></tr></thead><tbody id="user-table-body"></tbody></table>
                </div>
            </div>
            <div id="content-trazabilidad" class="hidden">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Trazabilidad de Tareas Completadas</h2>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left">
                                    <th class="px-6 py-3">Tarea</th>
                                    <th class="px-6 py-3">Descripción</th>
                                    <th class="px-6 py-3">P. Inicial</th>
                                    <th class="px-6 py-3">P. Final</th>
                                    <th class="px-6 py-3">Hora Inicio</th>
                                    <th class="px-6 py-3">Hora Fin</th>
                                    <th class="px-6 py-3">Tiempo Resp.</th>
                                    <th class="px-6 py-3">Asignado a</th>
                                    <th class="px-6 py-3">Check por</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($completed_tasks)): ?>
                                    <tr><td colspan="9" class="p-6 text-center text-gray-500">Aún no hay tareas completadas.</td></tr>
                                <?php else: foreach($completed_tasks as $task): ?>
                                <tr class="border-b">
                                    <td class="px-6 py-4 font-medium"><?php echo htmlspecialchars($task['title']); ?></td>
                                    <td class="px-6 py-4 text-xs max-w-xs truncate" title="<?php echo htmlspecialchars($task['instruction']); ?>"><?php echo htmlspecialchars($task['instruction']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="text-xs font-medium px-2.5 py-1 rounded-full <?php
                                            if ($task['priority'] === 'Alta' || $task['priority'] === 'Critica') echo 'bg-red-100 text-red-800';
                                            elseif ($task['priority'] === 'Media') echo 'bg-yellow-100 text-yellow-800';
                                            else echo 'bg-gray-100 text-gray-800';
                                        ?>"><?php echo htmlspecialchars($task['priority']); ?></span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="text-xs font-medium px-2.5 py-1 rounded-full <?php
                                            if ($task['final_priority'] === 'Alta' || $task['final_priority'] === 'Critica') echo 'bg-red-100 text-red-800';
                                            elseif ($task['final_priority'] === 'Media') echo 'bg-yellow-100 text-yellow-800';
                                            else echo 'bg-gray-100 text-gray-800';
                                        ?>"><?php echo htmlspecialchars($task['final_priority']); ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo date('d M, h:i a', strtotime($task['created_at'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo date('d M, h:i a', strtotime($task['completed_at'])); ?></td>
                                    <td class="px-6 py-4 font-mono"><?php echo htmlspecialchars($task['response_time']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($task['assigned_to']); ?></td>
                                    <td class="px-6 py-4 font-semibold"><?php echo htmlspecialchars($task['completed_by']); ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
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
    
    const apiUrlBase = 'api';

    const remindersPanel = document.getElementById('reminders-panel');
    const taskNotificationsPanel = document.getElementById('task-notifications-panel');
    const mediumPriorityPanel = document.getElementById('medium-priority-panel');

    function toggleReminders() { remindersPanel.classList.toggle('hidden'); }
    function toggleTaskNotifications() { taskNotificationsPanel.classList.toggle('hidden'); }
    function toggleMediumPriority() { mediumPriorityPanel.classList.toggle('hidden'); }
    
    function toggleForm(formId, button) {
        const form = document.getElementById(formId);
        const parentItem = button.closest('.bg-white.rounded-lg.shadow-md.overflow-hidden');
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
    
    async function submitAssignment(alertId, taskId, formUniqueId) {
        const userId = document.getElementById(`assign-user-${formUniqueId}`).value;
        const instruction = document.getElementById(`task-instruction-${formUniqueId}`).value;
        await sendTaskRequest(alertId, userId, instruction, 'Asignacion', taskId);
    }
    
    async function setReminder(alertId, taskId, formUniqueId) {
        const userId = document.getElementById(`reminder-user-${formUniqueId}`).value;
        await sendTaskRequest(alertId, userId, 'Recordatorio para revisar', 'Recordatorio', taskId);
    }

    async function sendTaskRequest(alertId, userId, instruction, type, taskId = null) {
        if (!userId) { alert('Por favor, selecciona un usuario.'); return; }
        try {
            const payload = {
                assign_to: userId,
                instruction: instruction,
                type: type,
                task_id: taskId,
                alert_id: alertId
            };
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
        const userId = document.getElementById('manual-task-user').value;
        const priority = document.getElementById('manual-task-priority').value;
        const start_datetime = document.getElementById('manual-task-start').value;
        const end_datetime = document.getElementById('manual-task-end').value;
        
        if (!userId) { alert('Selecciona un usuario.'); return; }
        if (start_datetime && end_datetime && start_datetime >= end_datetime) {
            alert('La fecha de fin debe ser posterior a la fecha de inicio.');
            return;
        }

        try {
            const response = await fetch(`${apiUrlBase}/alerts_api.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    title: title,
                    assign_to: userId,
                    instruction: instruction,
                    type: 'Manual',
                    priority: priority,
                    start_datetime: start_datetime || null,
                    end_datetime: end_datetime || null
                })
            });

            if (!response.ok) { throw new Error(`Error HTTP ${response.status}`); }
            const result = await response.json();

            if (result.success) { alert('Tarea creada.'); location.reload(); }
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
        setTimeout(() => { modal.classList.remove('scale-95', 'opacity-0'); modal.classList.add('scale-100', 'opacity-100'); }, 10);
    }

    function closeModal() {
        modal.classList.remove('scale-100', 'opacity-100');
        modal.classList.add('scale-95', 'opacity-0');
        setTimeout(() => modalOverlay.classList.add('hidden'), 300);
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
        const contentPanels = ['operaciones', 'roles', 'trazabilidad'];
        contentPanels.forEach(panel => {
            const content = document.getElementById(`content-${panel}`);
            const tab = document.getElementById(`tab-${panel}`);
            if (content) {
                content.classList.toggle('hidden', panel !== tabName);
            }
            if (tab) {
                tab.classList.toggle('active', panel !== tabName);
            }
        });
    }

    function updateCountdownTimers() {
        document.querySelectorAll('.countdown-timer').forEach(timerEl => {
            const endTime = new Date(timerEl.dataset.endTime).getTime();
            if (isNaN(endTime)) return;

            const now = new Date().getTime();
            const distance = endTime - now;

            if (distance < 0) {
                // --- Contador Progresivo (Retraso) ---
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
                // --- Contador Regresivo (Vence en) ---
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);

                let timeLeft = '';
                if (days > 0) timeLeft += `${days}d `;
                if (hours > 0 || days > 0) timeLeft += `${hours}h `;
                timeLeft += `${minutes}m ${seconds}s`;
                
                let textColor = 'text-green-600';
                if (days === 0 && hours < 1) {
                    textColor = 'text-red-600';
                } else if (days === 0 && hours < 24) {
                    textColor = 'text-yellow-700';
                }

                timerEl.innerHTML = `Vence en: <span class="${textColor}">${timeLeft}</span>`;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('user-table-body')) {
            populateUserTable(adminUsersData);
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
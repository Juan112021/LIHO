<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');

$userName  = $_SESSION['user_name'] ?? 'Usuario';
$userRole  = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId    = (int)($_SESSION['user_id'] ?? 0);
$userEmail = $_SESSION['user_email'] ?? '';

$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

// Control estricto de acceso a Maestro de Parafiscales
if ($userRole !== 'ADMINISTRADOR' && $userRoleIdVal !== 1 && !tienePermisoModulo($userId, $userRoleIdVal, 'maestro_parafiscales')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// Permisos para editar (Administradores [rol_id 1] y Financiera [rol_id 2])
$canEditTarifa = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

// 1. AJAX: CREAR NUEVO CONCEPTO PARAFISCAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_parafiscal') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para registrar reglas de parafiscales.']);
        exit;
    }

    $codigo      = strtoupper(trim($_POST['codigo'] ?? ''));
    $nombre      = trim($_POST['nombre'] ?? '');
    $aplicaSobre = trim($_POST['aplica_sobre'] ?? 'Valor IBC');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $porcentaje  = (float)($_POST['porcentaje'] ?? 0.00);

    if (empty($codigo) || empty($nombre)) {
        echo json_encode(['success' => false, 'message' => 'El código y el nombre del concepto son obligatorios.']);
        exit;
    }

    if ($porcentaje < 0 || $porcentaje > 100) {
        echo json_encode(['success' => false, 'message' => 'El porcentaje debe estar entre 0% y 100%.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos SQL Server.']);
        exit;
    }

    // Verificar si ya existe este código
    $stmtChk = sqlsrv_query($con, "SELECT id FROM maestro_parafiscales WHERE codigo = ?", array($codigo));
    if ($stmtChk !== false && sqlsrv_has_rows($stmtChk)) {
        echo json_encode(['success' => false, 'message' => "El código de concepto '$codigo' ya se encuentra registrado."]);
        exit;
    }

    $sqlIns = "INSERT INTO maestro_parafiscales 
               (codigo, nombre, aplica_sobre, porcentaje, descripcion, estado, fecha_creacion, fecha_actualizacion, usuario_id) 
               VALUES (?, ?, ?, ?, ?, 1, GETDATE(), GETDATE(), ?); 
               SELECT SCOPE_IDENTITY() AS new_id;";
    
    $params = array($codigo, $nombre, $aplicaSobre, $porcentaje, $descripcion, $userId);
    $stmtIns = sqlsrv_query($con, $sqlIns, $params);

    if ($stmtIns === false) {
        echo json_encode(['success' => false, 'message' => 'Error al registrar el concepto parafiscal: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    sqlsrv_next_result($stmtIns);
    $rowId = sqlsrv_fetch_array($stmtIns, SQLSRV_FETCH_ASSOC);
    $newId = $rowId['new_id'] ?? 0;

    // Registrar en Historial de Auditoría si la función existe
    if (file_exists(__DIR__ . '/includes/audit_logger.php')) {
        require_once(__DIR__ . '/includes/audit_logger.php');
        if (function_exists('registrarAuditoriaTarifario')) {
            registrarAuditoriaTarifario(
                $con,
                $newId,
                $codigo,
                $nombre,
                $userId,
                $userName,
                $userEmail,
                $userRole,
                'NUEVO_PARAFISCAL',
                'N/A',
                "Porcentaje: {$porcentaje}% (Aplica sobre: {$aplicaSobre})",
                'Creación de concepto en maestro de parafiscales',
                'CREACION',
                'MAESTRO_PARAFISCALES'
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Concepto parafiscal registrado exitosamente.',
        'data' => [
            'id' => $newId,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'aplica_sobre' => $aplicaSobre,
            'porcentaje' => $porcentaje,
            'estado' => 1
        ]
    ]);
    exit;
}

// 2. AJAX: ACTUALIZAR CONCEPTO PARAFISCAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar_parafiscal') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar reglas de parafiscales.']);
        exit;
    }

    $id          = (int)($_POST['id'] ?? 0);
    $nombre      = trim($_POST['nombre'] ?? '');
    $aplicaSobre = trim($_POST['aplica_sobre'] ?? 'Valor IBC');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $porcentaje  = (float)($_POST['porcentaje'] ?? 0.00);
    $estado      = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Identificador no válido.']);
        exit;
    }

    if (empty($nombre)) {
        echo json_encode(['success' => false, 'message' => 'El nombre del concepto es obligatorio.']);
        exit;
    }

    if ($porcentaje < 0 || $porcentaje > 100) {
        echo json_encode(['success' => false, 'message' => 'El porcentaje debe estar entre 0% y 100%.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos SQL Server.']);
        exit;
    }

    // Consultar valores actuales para auditoría
    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, nombre, aplica_sobre, descripcion, porcentaje, estado FROM maestro_parafiscales WHERE id = ?", array($id));
    $currData = ($stmtCurr && $r = sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC)) ? $r : null;

    $sqlUpd = "UPDATE maestro_parafiscales 
               SET nombre = ?, aplica_sobre = ?, descripcion = ?, porcentaje = ?, estado = ?, fecha_actualizacion = GETDATE(), usuario_id = ? 
               WHERE id = ?";
    $params = array($nombre, $aplicaSobre, $descripcion, $porcentaje, $estado, $userId, $id);
    $stmtUpd = sqlsrv_query($con, $sqlUpd, $params);

    if ($stmtUpd === false) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar el concepto: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    if ($currData && file_exists(__DIR__ . '/includes/audit_logger.php')) {
        require_once(__DIR__ . '/includes/audit_logger.php');
        if (function_exists('registrarAuditoriaTarifario')) {
            $oldPct = (float)($currData['porcentaje'] ?? 0);
            if (abs($oldPct - $porcentaje) > 0.0001) {
                registrarAuditoriaTarifario(
                    $con, $id, $currData['codigo'], $nombre, $userId, $userName, $userEmail, $userRole,
                    'PORCENTAJE', "{$oldPct}%", "{$porcentaje}%", 'Modificación de porcentaje parafiscal', 'EDICION', 'MAESTRO_PARAFISCALES'
                );
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Concepto parafiscal actualizado exitosamente.',
        'data' => [
            'id' => $id,
            'nombre' => $nombre,
            'aplica_sobre' => $aplicaSobre,
            'porcentaje' => $porcentaje,
            'estado' => $estado
        ]
    ]);
    exit;
}

// 3. AJAX: ACTIVAR / DESACTIVAR CONCEPTO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_estado') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar el estado.']);
        exit;
    }

    $id          = (int)($_POST['id'] ?? 0);
    $nuevoEstado = (int)($_POST['estado'] ?? 1);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID no válido.']);
        exit;
    }

    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, nombre, porcentaje, estado FROM maestro_parafiscales WHERE id = ?", array($id));
    $currData = ($stmtCurr && $r = sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC)) ? $r : null;

    $stmt = sqlsrv_query($con, "UPDATE maestro_parafiscales SET estado = ?, fecha_actualizacion = GETDATE(), usuario_id = ? WHERE id = ?", array($nuevoEstado, $userId, $id));
    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Error al cambiar estado.']);
        exit;
    }

    if ($currData && file_exists(__DIR__ . '/includes/audit_logger.php')) {
        require_once(__DIR__ . '/includes/audit_logger.php');
        if (function_exists('registrarAuditoriaTarifario')) {
            $act = ($nuevoEstado === 1) ? 'ACTIVACION' : 'DESACTIVACION';
            registrarAuditoriaTarifario(
                $con, $id, $currData['codigo'], $currData['nombre'], $userId, $userName, $userEmail, $userRole,
                'ESTADO', ($currData['estado'] == 1 ? 'ACTIVO' : 'INACTIVO'), ($nuevoEstado == 1 ? 'ACTIVO' : 'INACTIVO'),
                'Cambio de estado de concepto parafiscal', $act, 'MAESTRO_PARAFISCALES'
            );
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Estado actualizado exitosamente.',
        'nuevo_estado' => $nuevoEstado
    ]);
    exit;
}

// 4. EXPORTAR A CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=maestro_parafiscales_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    fputcsv($output, ['CODIGO', 'CONCEPTO', 'APLICA_SOBRE', 'PORCENTAJE (%)', 'DESCRIPCION', 'ESTADO'], ';');

    if (isset($con) && $con !== false) {
        $stmtEx = sqlsrv_query($con, "SELECT codigo, nombre, aplica_sobre, porcentaje, descripcion, estado FROM maestro_parafiscales ORDER BY id ASC");
        if ($stmtEx !== false) {
            while ($r = sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC)) {
                fputcsv($output, [
                    $r['codigo'],
                    $r['nombre'],
                    $r['aplica_sobre'],
                    number_format((float)$r['porcentaje'], 4, ',', '') . '%',
                    $r['descripcion'],
                    ($r['estado'] == 1 ? 'ACTIVO' : 'INACTIVO')
                ], ';');
            }
        }
    }
    fclose($output);
    exit;
}

// 5. CONSULTAR LISTADO DE PARAFISCALES
$parafiscalesList = [];
$totalActivas     = 0;
$totalInactivas   = 0;
$ibcPctVal        = 40.0;
$saludPctVal      = 12.5;
$pensionPctVal    = 16.0;
$arlPctVal        = 2.436;

if (isset($con) && $con !== false) {
    $sqlSel = "SELECT id, codigo, nombre, aplica_sobre, descripcion, porcentaje, ISNULL(estado, 1) AS estado FROM maestro_parafiscales ORDER BY id ASC";
    $stmt = sqlsrv_query($con, $sqlSel);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['id'] = (int)$row['id'];
            $row['estado'] = (int)$row['estado'];
            $row['porcentaje'] = (float)$row['porcentaje'];

            $parafiscalesList[] = $row;

            if ($row['estado'] === 1) {
                $totalActivas++;
            } else {
                $totalInactivas++;
            }

            $cCode = strtoupper(trim($row['codigo'] ?? ''));
            if ($cCode === 'IBC') {
                $ibcPctVal = $row['porcentaje'];
            } elseif ($cCode === 'SALUD') {
                $saludPctVal = $row['porcentaje'];
            } elseif ($cCode === 'PENSION') {
                $pensionPctVal = $row['porcentaje'];
            } elseif ($cCode === 'ARL') {
                $arlPctVal = $row['porcentaje'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Maestro de Parafiscales | LIHO</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <!-- Google Fonts: Montserrat & Outfit -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="shortcut icon" href="assets/img/hologo.png">

    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#14354e",
                        tertiary: "#00c1be",
                        background: "#f8fafc"
                    },
                    fontFamily: {
                        sans: ["Montserrat", "sans-serif"],
                        outfit: ["Outfit", "sans-serif"]
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Montserrat', sans-serif; }
        .font-outfit { font-family: 'Outfit', sans-serif; }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header Navigation -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Container -->
    <main class="flex-grow max-w-[1200px] w-full mx-auto px-4 sm:px-6 py-8">

        <!-- Encabezado y Pestañas de Navegación -->
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-8">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-500/10 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 text-[11px] font-extrabold uppercase tracking-wider mb-2 border border-teal-200/60 dark:border-teal-800">
                    <span class="material-symbols-outlined text-sm">account_balance</span>
                    <span>Seguridad Social & Aportes Parafiscales</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-primary dark:text-white tracking-tight font-outfit">
                    Maestro de Parafiscales
                </h1>
                <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-1">
                    Configuración centralizada de tasas en vivo para IBC, Salud, ARL y deducciones de liquidación
                </p>
            </div>

            <!-- Selector de Pestañas y Botones de Acción -->
            <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                <!-- Pestañas -->
                <div class="inline-flex p-1 bg-slate-200/70 dark:bg-slate-800 rounded-2xl border border-slate-300/60 dark:border-slate-700">
                    <a href="tarifario.php" class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-white transition-all">
                        Tarifario General
                    </a>
                    <a href="tarifario_especial.php" class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-white transition-all flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">star</span>
                        <span>Tarifas Especiales</span>
                    </a>
                    <a href="maestro_porcentajes.php" class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-white transition-all flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs">percent</span>
                        <span>Porcentajes Pago</span>
                    </a>
                    <a href="maestro_parafiscales.php" class="px-3 py-1.5 rounded-xl text-xs font-extrabold bg-teal-600 text-white shadow-sm transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xs">account_balance</span>
                        <span>Parafiscales</span>
                    </a>
                </div>

                <!-- Historial / Auditoría -->
                <a href="historial_tarifario.php" class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100 text-xs font-bold shadow-md border border-slate-700 transition-all">
                    <span class="material-symbols-outlined text-lg text-purple-400">history</span>
                    <span>Auditoría</span>
                </a>

                <!-- Exportar CSV -->
                <a href="maestro_parafiscales.php?action=export_csv" 
                   class="px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/60 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all shadow-xs inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-file-csv text-teal-600 text-sm"></i>
                    <span>Exportar CSV</span>
                </a>

                <?php if ($canEditTarifa): ?>
                <!-- Registrar Nuevo Concepto -->
                <button type="button" onclick="abrirModalCrear()" 
                        class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-teal-600 to-emerald-600 hover:from-teal-500 hover:to-emerald-500 text-white font-extrabold text-xs shadow-md shadow-teal-600/20 hover:shadow-lg transition-all cursor-pointer inline-flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">add_circle</span>
                    <span>Nuevo Concepto</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tarjetas de Métricas Rápidas (KPIs) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <!-- Total Conceptos -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Conceptos</p>
                    <h3 class="text-2xl font-black text-primary dark:text-white font-outfit mt-1"><?php echo count($parafiscalesList); ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?php echo $totalActivas; ?> activos, <?php echo $totalInactivas; ?> inactivos</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">account_balance</span>
                </div>
            </div>

            <!-- Tasa IBC -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">% IBC Base</p>
                    <h3 class="text-2xl font-black text-teal-600 dark:text-teal-400 font-outfit mt-1"><?php echo number_format($ibcPctVal, 2); ?>%</h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">Sobre total facturado</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">calculate</span>
                </div>
            </div>

            <!-- Tasa Salud -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">% Aporte Salud</p>
                    <h3 class="text-2xl font-black text-rose-600 dark:text-rose-400 font-outfit mt-1"><?php echo number_format($saludPctVal, 2); ?>%</h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">Calculado sobre el IBC</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">health_and_safety</span>
                </div>
            </div>

            <!-- Tasa Pensión -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">% Aporte Pensión</p>
                    <h3 class="text-2xl font-black text-indigo-600 dark:text-indigo-400 font-outfit mt-1"><?php echo number_format($pensionPctVal, 2); ?>%</h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">Calculado sobre el IBC</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">assured_workload</span>
                </div>
            </div>

            <!-- Tasa ARL -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">% Aporte ARL</p>
                    <h3 class="text-2xl font-black text-amber-600 dark:text-amber-400 font-outfit mt-1"><?php echo number_format($arlPctVal, 4); ?>%</h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">Riesgo III Sector Salud</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">medical_services</span>
                </div>
            </div>
        </div>

        <!-- Tabla Principal de Maestro de Parafiscales -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="tablaParafiscales">
                    <thead>
                        <tr class="bg-slate-50/80 dark:bg-slate-800/80 border-b border-slate-200/80 dark:border-slate-800 text-[11px] font-extrabold text-slate-400 dark:text-slate-300 uppercase tracking-wider">
                            <th class="py-4 px-6">Código / Concepto</th>
                            <th class="py-4 px-6">Aplica Sobre</th>
                            <th class="py-4 px-6 text-center whitespace-nowrap">Porcentaje (%)</th>
                            <th class="py-4 px-6">Descripción Normativa</th>
                            <th class="py-4 px-6 text-center">Estado</th>
                            <th class="py-4 px-6 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($parafiscalesList)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-12 text-slate-400 font-medium">
                                    <span class="material-symbols-outlined text-4xl mb-2 text-slate-300">account_balance_wallet</span>
                                    <p>No hay conceptos parafiscales configurados en el sistema.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($parafiscalesList as $p): ?>
                                <?php 
                                    $isActivo = ($p['estado'] === 1);
                                    $cCode = strtoupper(trim($p['codigo']));
                                    $codColor = ($cCode === 'IBC') ? 'bg-teal-100 text-teal-800 border-teal-200 dark:bg-teal-950/80 dark:text-teal-300 dark:border-teal-800' :
                                               (($cCode === 'SALUD') ? 'bg-rose-100 text-rose-800 border-rose-200 dark:bg-rose-950/80 dark:text-rose-300 dark:border-rose-800' :
                                               (($cCode === 'PENSION') ? 'bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-950/80 dark:text-indigo-300 dark:border-indigo-800' :
                                               'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-950/80 dark:text-amber-300 dark:border-amber-800'));
                                ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/50 transition-colors parafiscal-row border-b border-slate-100 dark:border-slate-800" id="row-parafiscal-<?php echo $p['id']; ?>">
                                    <!-- Código y Nombre -->
                                    <td class="py-4 px-6 font-bold text-primary dark:text-slate-100">
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg border text-[11px] font-black uppercase font-mono <?php echo $codColor; ?>">
                                                <?php echo htmlspecialchars($p['codigo']); ?>
                                            </span>
                                            <div>
                                                <p class="font-extrabold text-primary dark:text-white text-xs"><?php echo htmlspecialchars($p['nombre']); ?></p>
                                                <p class="text-[10px] font-bold text-slate-400 tracking-wider">Concepto de Deducción</p>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Aplica Sobre -->
                                    <td class="py-4 px-6 font-semibold text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-[11px] font-bold">
                                            <span class="material-symbols-outlined text-xs">straighten</span>
                                            <?php echo htmlspecialchars($p['aplica_sobre']); ?>
                                        </span>
                                    </td>

                                    <!-- Porcentaje -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap font-mono">
                                        <span class="inline-flex items-center justify-center px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-teal-600 dark:text-teal-400 font-black text-xs whitespace-nowrap">
                                            <?php echo number_format((float)$p['porcentaje'], 4); ?> %
                                        </span>
                                    </td>

                                    <!-- Descripción -->
                                    <td class="py-4 px-6 text-slate-500 dark:text-slate-400 max-w-xs text-[11px] font-medium leading-relaxed">
                                        <?php echo !empty($p['descripcion']) ? htmlspecialchars($p['descripcion']) : '<span class="italic text-slate-400">Sin descripción registrada</span>'; ?>
                                    </td>

                                    <!-- Estado -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <?php if ($isActivo): ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-100/80 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800 text-[10px] font-extrabold uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-100/80 dark:bg-rose-950/70 text-rose-800 dark:text-rose-300 border border-rose-200/60 dark:border-rose-800 text-[10px] font-extrabold uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="py-4 px-6 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <?php if ($canEditTarifa): ?>
                                                <button type="button" 
                                                    title="Editar porcentaje parafiscal" 
                                                    onclick="abrirModalEditar(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>)"
                                                    class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/80 border border-amber-200 dark:border-amber-800 font-bold text-[11px] transition-colors inline-flex items-center gap-1 cursor-pointer">
                                                    <span class="material-symbols-outlined text-sm">edit</span>
                                                    <span>Editar</span>
                                                </button>

                                                <button type="button" 
                                                    title="<?php echo $isActivo ? 'Desactivar concepto' : 'Activar concepto'; ?>" 
                                                    onclick="toggleEstado(<?php echo $p['id']; ?>, <?php echo $isActivo ? 0 : 1; ?>, '<?php echo htmlspecialchars($p['nombre'], ENT_QUOTES); ?>')"
                                                    class="px-3 py-1.5 rounded-xl <?php echo $isActivo ? 'bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/80 border border-rose-200 dark:border-rose-800' : 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/80 border border-emerald-200 dark:border-emerald-800'; ?> font-bold text-[11px] transition-colors inline-flex items-center gap-1 cursor-pointer">
                                                    <span class="material-symbols-outlined text-sm"><?php echo $isActivo ? 'block' : 'check_circle'; ?></span>
                                                    <span><?php echo $isActivo ? 'Desactivar' : 'Activar'; ?></span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Registrar Nuevo Concepto Parafiscal -->
    <div id="modalCrearParafiscal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full p-8 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300">
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">account_balance</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Nuevo Concepto Parafiscal</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Configure una nueva regla de deducción o seguridad social</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalCrear()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formCrearParafiscal" onsubmit="guardarNuevoParafiscal(event)" class="space-y-4">
                <input type="hidden" name="action" value="crear_parafiscal" />

                <!-- Código -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Código del Concepto *</label>
                    <input type="text" id="add_codigo" name="codigo" required placeholder="Ej: PENSION, FONDO_SOL" uppercase class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-teal-500/30 outline-none uppercase" />
                </div>

                <!-- Nombre -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Nombre Descriptivo *</label>
                    <input type="text" id="add_nombre" name="nombre" required placeholder="Ej: Aporte Pensión Mes" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-teal-500/30 outline-none" />
                </div>

                <!-- Aplica Sobre & Porcentaje -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Aplica Sobre *</label>
                        <select id="add_aplica_sobre" name="aplica_sobre" required class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-teal-500/30 outline-none">
                            <option value="Valor IBC" selected>Valor IBC</option>
                            <option value="Total Facturado">Total Facturado</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Porcentaje (%) *</label>
                        <input type="number" id="add_porcentaje" name="porcentaje" step="0.0001" min="0" max="100" required placeholder="Ej: 16.0000" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-teal-600 dark:text-teal-400 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-teal-500/30 outline-none" />
                    </div>
                </div>

                <!-- Descripción -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Descripción / Normativa</label>
                    <textarea id="add_descripcion" name="descripcion" rows="2" placeholder="Detalles de la ley, decreto o porcentaje aplicado..." class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-medium text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-teal-500/30 outline-none"></textarea>
                </div>

                <!-- Botones -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalCrear()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-teal-600 hover:bg-teal-700 text-white shadow-md transition-all cursor-pointer">
                        Guardar Concepto
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Editar Concepto Parafiscal -->
    <div id="modalEditarParafiscal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full p-8 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300">
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">edit_note</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Actualizar Concepto Parafiscal</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Modifique las tasas y parámetros en vivo</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalEditar()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formEditarParafiscal" onsubmit="guardarEdicionParafiscal(event)" class="space-y-4">
                <input type="hidden" name="action" value="editar_parafiscal" />
                <input type="hidden" id="edit_id" name="id" value="" />

                <!-- Código (Readonly) -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Código</label>
                    <input type="text" id="edit_codigo" readonly class="w-full bg-slate-100 dark:bg-slate-800/60 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-500 dark:text-slate-400 cursor-not-allowed outline-none uppercase" />
                </div>

                <!-- Nombre -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Nombre Descriptivo *</label>
                    <input type="text" id="edit_nombre" name="nombre" required class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-amber-500/30 outline-none" />
                </div>

                <!-- Aplica Sobre & Porcentaje -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Aplica Sobre *</label>
                        <select id="edit_aplica_sobre" name="aplica_sobre" required class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-amber-500/30 outline-none">
                            <option value="Valor IBC">Valor IBC</option>
                            <option value="Total Facturado">Total Facturado</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Porcentaje (%) *</label>
                        <input type="number" id="edit_porcentaje" name="porcentaje" step="0.0001" min="0" max="100" required class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-amber-600 dark:text-amber-400 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-amber-500/30 outline-none" />
                    </div>
                </div>

                <!-- Descripción -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Descripción / Normativa</label>
                    <textarea id="edit_descripcion" name="descripcion" rows="2" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-medium text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-amber-500/30 outline-none"></textarea>
                </div>

                <!-- Estado -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Estado</label>
                    <select id="edit_estado" name="estado" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-amber-500/30 outline-none">
                        <option value="1">Activo</option>
                        <option value="0">Inactivo</option>
                    </select>
                </div>

                <!-- Botones -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalEditar()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white shadow-md transition-all cursor-pointer">
                        Actualizar Concepto
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <script>
    function abrirModalCrear() {
        document.getElementById('formCrearParafiscal').reset();
        document.getElementById('modalCrearParafiscal').classList.remove('hidden');
    }

    function cerrarModalCrear() {
        document.getElementById('modalCrearParafiscal').classList.add('hidden');
    }

    function abrirModalEditar(data) {
        document.getElementById('edit_id').value = data.id || '';
        document.getElementById('edit_codigo').value = data.codigo || '';
        document.getElementById('edit_nombre').value = data.nombre || '';
        document.getElementById('edit_aplica_sobre').value = data.aplica_sobre || 'Valor IBC';
        document.getElementById('edit_porcentaje').value = parseFloat(data.porcentaje) || 0;
        document.getElementById('edit_descripcion').value = data.descripcion || '';
        document.getElementById('edit_estado').value = (data.estado !== undefined) ? data.estado : 1;

        document.getElementById('modalEditarParafiscal').classList.remove('hidden');
    }

    function cerrarModalEditar() {
        document.getElementById('modalEditarParafiscal').classList.add('hidden');
    }

    async function guardarNuevoParafiscal(e) {
        e.preventDefault();
        const form = document.getElementById('formCrearParafiscal');
        const formData = new FormData(form);

        try {
            Swal.fire({ title: 'Guardando...', text: 'Registrando concepto parafiscal', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const res = await fetch('maestro_parafiscales.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success) {
                Swal.fire({ icon: 'success', title: '¡Registrado!', text: json.message, timer: 1500, showConfirmButton: false }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: json.message || 'No se pudo guardar el concepto.' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error de Red', text: 'Ocurrió un problema al comunicarse con el servidor.' });
        }
    }

    async function guardarEdicionParafiscal(e) {
        e.preventDefault();
        const form = document.getElementById('formEditarParafiscal');
        const formData = new FormData(form);

        try {
            Swal.fire({ title: 'Actualizando...', text: 'Guardando modificaciones', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const res = await fetch('maestro_parafiscales.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success) {
                Swal.fire({ icon: 'success', title: '¡Actualizado!', text: json.message, timer: 1500, showConfirmButton: false }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: json.message || 'No se pudo actualizar el concepto.' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error de Red', text: 'Ocurrió un problema al comunicarse con el servidor.' });
        }
    }

    async function toggleEstado(id, nuevoEstado, nombre) {
        const accionStr = (nuevoEstado === 1) ? 'activar' : 'desactivar';
        const result = await Swal.fire({
            title: `¿Desea ${accionStr} el concepto?`,
            text: `Se cambiará el estado para '${nombre}'`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: (nuevoEstado === 1) ? '#0d9488' : '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: `Sí, ${accionStr}`,
            cancelButtonText: 'Cancelar'
        });

        if (!result.isConfirmed) return;

        const formData = new FormData();
        formData.append('action', 'toggle_estado');
        formData.append('id', id);
        formData.append('estado', nuevoEstado);

        try {
            Swal.fire({ title: 'Procesando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const res = await fetch('maestro_parafiscales.php', { method: 'POST', body: formData });
            const json = await res.json();

            if (json.success) {
                Swal.fire({ icon: 'success', title: 'Estado Actualizado', text: json.message, timer: 1200, showConfirmButton: false }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: json.message });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error de Red', text: 'Error al cambiar estado.' });
        }
    }
    </script>
</body>
</html>

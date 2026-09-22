<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/audit_logger.php');

$userName  = $_SESSION['user_name'] ?? 'Usuario';
$userRole  = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId    = $_SESSION['user_id'] ?? null;
$userEmail = $_SESSION['user_email'] ?? '';
$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

// Permisos para editar (Administradores [rol_id 1] y Financiera [rol_id 2])
$canEditTarifa = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

// --------------------------------------------------------------------------
// 1. AJAX: CREAR NUEVO PROCEDIMIENTO DE BLOQUEO
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_bloqueo') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para registrar procedimientos de bloqueo.']);
        exit;
    }

    $codigo           = strtoupper(trim($_POST['codigo'] ?? ''));
    $estudio          = strtoupper(trim($_POST['estudio'] ?? ''));
    $especialidad     = strtoupper(trim($_POST['especialidad'] ?? 'BLOQUEOS'));
    $valorBase        = floatval(str_replace(['$', '.', ' '], ['', '', ''], str_replace(',', '.', $_POST['valor_base'] ?? '0')));
    $valorCant2       = floatval(str_replace(['$', '.', ' '], ['', '', ''], str_replace(',', '.', $_POST['valor_cant_2'] ?? '0')));
    $valorCant3       = floatval(str_replace(['$', '.', ' '], ['', '', ''], str_replace(',', '.', $_POST['valor_cant_3'] ?? '0')));
    $porcentajeBase   = floatval($_POST['porcentaje_base'] ?? 40.00);
    $porcentajeCant2  = floatval($_POST['porcentaje_cant_2'] ?? 70.00);
    $porcentajeCant3  = floatval($_POST['porcentaje_cant_3'] ?? 60.00);
    $observaciones    = trim($_POST['observaciones'] ?? '');
    $estado           = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;

    if (empty($codigo) || empty($estudio)) {
        echo json_encode(['success' => false, 'message' => 'El código CUPS y la descripción del estudio son obligatorios.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos.']);
        exit;
    }

    // Verificar si el código ya existe
    $stmtChk = sqlsrv_query($con, "SELECT id FROM dbo.tarifario_bloqueos WHERE codigo = ?", array($codigo));
    if ($stmtChk !== false && sqlsrv_has_rows($stmtChk)) {
        echo json_encode(['success' => false, 'message' => "El código CUPS '$codigo' ya se encuentra registrado en el tarifario de bloqueos."]);
        exit;
    }

    $sqlIns = "INSERT INTO dbo.tarifario_bloqueos 
               (codigo, estudio, especialidad, valor_base, valor_cant_2, valor_cant_3, porcentaje_base, porcentaje_cant_2, porcentaje_cant_3, tipo_calculo, observaciones, entidad_id, estado, fecha_creacion, fecha_actualizacion, usuario_id) 
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PORCENTAJE', ?, 4, ?, GETDATE(), GETDATE(), ?); 
               SELECT SCOPE_IDENTITY() AS new_id;";

    $paramsIns = array($codigo, $estudio, $especialidad, $valorBase, $valorCant2, $valorCant3, $porcentajeBase, $porcentajeCant2, $porcentajeCant3, $observaciones, $estado, $userId);
    $stmtIns = sqlsrv_query($con, $sqlIns, $paramsIns);

    if ($stmtIns === false) {
        echo json_encode(['success' => false, 'message' => 'Error al registrar el procedimiento: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    sqlsrv_next_result($stmtIns);
    $rowId = sqlsrv_fetch_array($stmtIns, SQLSRV_FETCH_ASSOC);
    $newId = $rowId['new_id'] ?? 0;

    // Registrar en Historial de Auditoría
    registrarAuditoriaTarifario(
        $con,
        $newId,
        $codigo,
        $estudio,
        $userId,
        $userName,
        $userEmail,
        $userRole,
        'NUEVO_BLOQUEO',
        'N/A',
        "CUPS: {$codigo} | C1: \${$valorBase} ({$porcentajeBase}%) | C2: \${$valorCant2} ({$porcentajeCant2}%) | C3: \${$valorCant3} ({$porcentajeCant3}%)",
        'Creación de estudio en Tarifario Bloqueos HO',
        'CREACION',
        'TARIFARIO_BLOQUEOS'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Procedimiento de bloqueo registrado exitosamente.',
        'data' => [
            'id' => $newId,
            'codigo' => $codigo,
            'estudio' => $estudio,
            'valor_base' => $valorBase,
            'valor_cant_2' => $valorCant2,
            'valor_cant_3' => $valorCant3,
            'porcentaje_base' => $porcentajeBase,
            'porcentaje_cant_2' => $porcentajeCant2,
            'porcentaje_cant_3' => $porcentajeCant3,
            'observaciones' => $observaciones,
            'estado' => $estado
        ]
    ]);
    exit;
}

// --------------------------------------------------------------------------
// 2. AJAX: GUARDAR / EDITAR PROCEDIMIENTO DE BLOQUEO
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_bloqueo') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar el tarifario de bloqueos.']);
        exit;
    }

    $id               = (int)($_POST['id'] ?? 0);
    $codigo           = strtoupper(trim($_POST['codigo'] ?? ''));
    $estudio          = strtoupper(trim($_POST['estudio'] ?? ''));
    $especialidad     = strtoupper(trim($_POST['especialidad'] ?? 'BLOQUEOS'));
    $valorBase        = floatval(str_replace(['$', '.', ' '], ['', '', ''], str_replace(',', '.', $_POST['valor_base'] ?? '0')));
    $valorCant2       = floatval(str_replace(['$', '.', ' '], ['', '', ''], str_replace(',', '.', $_POST['valor_cant_2'] ?? '0')));
    $valorCant3       = floatval(str_replace(['$', '.', ' '], ['', '', ''], str_replace(',', '.', $_POST['valor_cant_3'] ?? '0')));
    $porcentajeBase   = floatval($_POST['porcentaje_base'] ?? 40.00);
    $porcentajeCant2  = floatval($_POST['porcentaje_cant_2'] ?? 70.00);
    $porcentajeCant3  = floatval($_POST['porcentaje_cant_3'] ?? 60.00);
    $observaciones    = trim($_POST['observaciones'] ?? '');
    $estado           = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;

    if ($id <= 0 || empty($codigo) || empty($estudio)) {
        echo json_encode(['success' => false, 'message' => 'Parámetros incompletos o inválidos.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos.']);
        exit;
    }

    // Consultar registro previo para auditoría
    $stmtCurr = sqlsrv_query($con, "SELECT * FROM dbo.tarifario_bloqueos WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    if (!$curr) {
        echo json_encode(['success' => false, 'message' => 'El procedimiento solicitado no existe.']);
        exit;
    }

    // Verificar si el nuevo código CUPS choca con otro registro
    $stmtChk = sqlsrv_query($con, "SELECT id FROM dbo.tarifario_bloqueos WHERE codigo = ? AND id != ?", array($codigo, $id));
    if ($stmtChk !== false && sqlsrv_has_rows($stmtChk)) {
        echo json_encode(['success' => false, 'message' => "El código CUPS '$codigo' ya está asignado a otro procedimiento."]);
        exit;
    }

    $sqlUpd = "UPDATE dbo.tarifario_bloqueos 
               SET codigo = ?, estudio = ?, especialidad = ?, valor_base = ?, valor_cant_2 = ?, valor_cant_3 = ?, 
                   porcentaje_base = ?, porcentaje_cant_2 = ?, porcentaje_cant_3 = ?, 
                   observaciones = ?, estado = ?, fecha_actualizacion = GETDATE(), usuario_id = ? 
               WHERE id = ?";

    $paramsUpd = array($codigo, $estudio, $especialidad, $valorBase, $valorCant2, $valorCant3, $porcentajeBase, $porcentajeCant2, $porcentajeCant3, $observaciones, $estado, $userId, $id);
    $stmtUpd = sqlsrv_query($con, $sqlUpd, $paramsUpd);

    if ($stmtUpd === false) {
        echo json_encode(['success' => false, 'message' => 'Error actualizando el procedimiento: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    // Registrar cambios en Auditoría
    $oldVal1 = floatval($curr['valor_base']);
    if ($oldVal1 != $valorBase) {
        registrarAuditoriaTarifario($con, $id, $codigo, $estudio, $userId, $userName, $userEmail, $userRole, 'VALOR_CANT_1', (string)$oldVal1, (string)$valorBase, 'Modificación de valor Cantidad 1', 'EDICION', 'TARIFARIO_BLOQUEOS');
    }
    if ($curr['codigo'] !== $codigo) {
        registrarAuditoriaTarifario($con, $id, $codigo, $estudio, $userId, $userName, $userEmail, $userRole, 'CODIGO_CUPS', $curr['codigo'], $codigo, 'Modificación de código CUPS', 'EDICION', 'TARIFARIO_BLOQUEOS');
    }
    if ((int)$curr['estado'] !== $estado) {
        $act = ($estado === 1) ? 'ACTIVACION' : 'DESACTIVACION';
        registrarAuditoriaTarifario($con, $id, $codigo, $estudio, $userId, $userName, $userEmail, $userRole, 'ESTADO', ((int)$curr['estado'] === 1 ? 'ACTIVO' : 'INACTIVO'), ($estado === 1 ? 'ACTIVO' : 'INACTIVO'), 'Cambio de estado', $act, 'TARIFARIO_BLOQUEOS');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Procedimiento de bloqueo actualizado correctamente.',
        'data' => [
            'id' => $id,
            'codigo' => $codigo,
            'estudio' => $estudio,
            'valor_base' => $valorBase,
            'valor_cant_2' => $valorCant2,
            'valor_cant_3' => $valorCant3,
            'porcentaje_base' => $porcentajeBase,
            'porcentaje_cant_2' => $porcentajeCant2,
            'porcentaje_cant_3' => $porcentajeCant3,
            'observaciones' => $observaciones,
            'estado' => $estado
        ]
    ]);
    exit;
}

// --------------------------------------------------------------------------
// 3. AJAX: TOGGLE DE ESTADO RÁPIDO (ACTIVO / INACTIVO)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cambiar_estado') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar estados.']);
        exit;
    }

    $id     = (int)($_POST['id'] ?? 0);
    $estado = (int)($_POST['estado'] ?? 1);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        exit;
    }

    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, estudio, estado FROM dbo.tarifario_bloqueos WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    $stmtUpd = sqlsrv_query($con, "UPDATE dbo.tarifario_bloqueos SET estado = ?, fecha_actualizacion = GETDATE(), usuario_id = ? WHERE id = ?", array($estado, $userId, $id));

    if ($stmtUpd === false) {
        echo json_encode(['success' => false, 'message' => 'Error al cambiar estado.']);
        exit;
    }

    if ($curr) {
        $act = ($estado === 1) ? 'ACTIVACION' : 'DESACTIVACION';
        registrarAuditoriaTarifario(
            $con,
            $id,
            $curr['codigo'],
            $curr['estudio'],
            $userId,
            $userName,
            $userEmail,
            $userRole,
            'ESTADO',
            ((int)$curr['estado'] === 1 ? 'ACTIVO' : 'INACTIVO'),
            ($estado === 1 ? 'ACTIVO' : 'INACTIVO'),
            "Cambio rápido de estado a " . ($estado === 1 ? 'Activo' : 'Inactivo'),
            $act,
            'TARIFARIO_BLOQUEOS'
        );
    }

    echo json_encode(['success' => true, 'message' => 'Estado actualizado exitosamente.', 'nuevo_estado' => $estado]);
    exit;
}

// --------------------------------------------------------------------------
// 4. AJAX: ELIMINAR PROCEDIMIENTO DE BLOQUEO
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'eliminar_bloqueo') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para eliminar procedimientos.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        exit;
    }

    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, estudio FROM dbo.tarifario_bloqueos WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    $stmtDel = sqlsrv_query($con, "DELETE FROM dbo.tarifario_bloqueos WHERE id = ?", array($id));
    if ($stmtDel === false) {
        echo json_encode(['success' => false, 'message' => 'Error al eliminar el procedimiento.']);
        exit;
    }

    if ($curr) {
        registrarAuditoriaTarifario(
            $con,
            $id,
            $curr['codigo'],
            $curr['estudio'],
            $userId,
            $userName,
            $userEmail,
            $userRole,
            'ELIMINACION',
            'ACTIVO',
            'ELIMINADO',
            'Eliminación de procedimiento del tarifario de bloqueos',
            'ELIMINACION',
            'TARIFARIO_BLOQUEOS'
        );
    }

    echo json_encode(['success' => true, 'message' => 'Procedimiento eliminado correctamente.']);
    exit;
}

// --------------------------------------------------------------------------
// 5. CONSULTA DE PROCEDIMIENTOS DE BLOQUEO PARA LA VISTA
// --------------------------------------------------------------------------
require_once(__DIR__ . '/includes/vigencias_helper.php');

$bloqueosList = [];
$totalBloqueos = 0;
$activosCount = 0;
$inactivosCount = 0;
$sumaValorBase = 0;

if (isset($con) && $con !== false) {
    $sqlSel = "SELECT id, codigo, estudio, especialidad, valor_base, ISNULL(valor_cant_2, 0) AS valor_cant_2, ISNULL(valor_cant_3, 0) AS valor_cant_3, 
                      porcentaje_base, porcentaje_cant_2, porcentaje_cant_3, observaciones, estado, fecha_creacion, fecha_actualizacion,
                      CONVERT(VARCHAR(10), ISNULL(vigencia_desde, '2020-01-01'), 120) AS vigencia_desde,
                      CONVERT(VARCHAR(10), vigencia_hasta, 120) AS vigencia_hasta, version_id 
               FROM dbo.tarifario_bloqueos 
               WHERE (vigencia_hasta IS NULL)
               ORDER BY id ASC";
    $stmtSel = sqlsrv_query($con, $sqlSel);
    if ($stmtSel !== false) {
        while ($r = sqlsrv_fetch_array($stmtSel, SQLSRV_FETCH_ASSOC)) {
            $r['id'] = (int)$r['id'];
            $r['valor_base'] = floatval($r['valor_base']);
            $r['valor_cant_2'] = floatval($r['valor_cant_2']);
            $r['valor_cant_3'] = floatval($r['valor_cant_3']);
            $r['porcentaje_base'] = floatval($r['porcentaje_base']);
            $r['porcentaje_cant_2'] = floatval($r['porcentaje_cant_2']);
            $r['porcentaje_cant_3'] = floatval($r['porcentaje_cant_3']);
            $r['estado'] = (int)$r['estado'];

            $bloqueosList[] = $r;
            $totalBloqueos++;
            if ($r['estado'] === 1) {
                $activosCount++;
                $sumaValorBase += $r['valor_base'];
            } else {
                $inactivosCount++;
            }
        }
    }
}

$promedioValorBase = $activosCount > 0 ? ($sumaValorBase / $activosCount) : 0;
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarifario Bloqueos HO | LIHO</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
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
    <main class="flex-grow max-w-[98%] 2xl:max-w-[1850px] w-full mx-auto px-3 sm:px-6 py-6 sm:py-8 relative">

        <!-- Encabezado del Módulo -->
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
            <div>
                <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 text-xs font-bold uppercase tracking-wider mb-2 border border-amber-300/80 dark:border-amber-700/80">
                    <span class="material-symbols-outlined text-sm">syringe</span>
                    <span>Maestro de Procedimientos Intervencionistas</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight font-outfit flex items-center gap-3">
                    <span>Tarifario Bloqueos HO</span>
                    <span class="text-xs px-3 py-1 rounded-full bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300 font-extrabold border border-teal-200 dark:border-teal-800">
                        Hernán Ocazionez
                    </span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                    Gestione los valores contratados por cada cantidad (Cant. 1, Cant. 2 y Cant. 3+) y los respectivos porcentajes de liquidación médica para estudios de Bloqueo.
                </p>
            </div>

            <!-- Botones de Acción Superior -->
            <div class="flex items-center gap-3 flex-wrap">
                <button type="button" onclick="exportarExcel()" 
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-all shadow-xs cursor-pointer">
                    <span class="material-symbols-outlined text-lg text-emerald-600">table_view</span>
                    <span>Exportar (.csv)</span>
                </button>

                <?php if ($canEditTarifa): ?>
                <button type="button" onclick="abrirModalNuevoBloqueo()" 
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white text-xs font-bold shadow-lg shadow-amber-500/25 transition-all hover:scale-[1.02] active:scale-[0.98] cursor-pointer">
                    <span class="material-symbols-outlined text-lg">add_circle</span>
                    <span>Nuevo Estudio de Bloqueo</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tarjetas de Métricas Resumen -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <!-- Total Procedimientos -->
            <div class="p-4 sm:p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-200/60 dark:border-amber-800/60">
                    <span class="material-symbols-outlined text-2xl">syringe</span>
                </div>
                <div>
                    <p class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider">Total Procedimientos</p>
                    <p class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white font-outfit mt-0.5">
                        <span id="statTotalBloqueos"><?php echo $totalBloqueos; ?></span> <span class="text-xs font-medium text-slate-400">estudios</span>
                    </p>
                </div>
            </div>

            <!-- Procedimientos Activos -->
            <div class="p-4 sm:p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0 border border-emerald-200/60 dark:border-emerald-800/60">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
                <div>
                    <p class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider">Estudios Activos</p>
                    <p class="text-xl sm:text-2xl font-black text-emerald-600 dark:text-emerald-400 font-outfit mt-0.5">
                        <span id="statActivos"><?php echo $activosCount; ?></span> <span class="text-xs font-medium text-slate-400">/ <?php echo $totalBloqueos; ?></span>
                    </p>
                </div>
            </div>

            <!-- Promedio Valor Base Contratado -->
            <div class="p-4 sm:p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-400 flex items-center justify-center shrink-0 border border-sky-200/60 dark:border-sky-800/60">
                    <span class="material-symbols-outlined text-2xl">payments</span>
                </div>
                <div>
                    <p class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider">Promedio Cantidad 1</p>
                    <p class="text-lg sm:text-xl font-black text-slate-900 dark:text-white font-outfit mt-0.5">
                        $<?php echo number_format($promedioValorBase, 0, ',', '.'); ?>
                    </p>
                </div>
            </div>

            <!-- Esquema Escalonado Base -->
            <div class="p-4 sm:p-5 rounded-3xl bg-gradient-to-br from-amber-500/10 via-amber-500/5 to-teal-500/10 border border-amber-500/30 dark:border-amber-600/40 shadow-xs flex items-center gap-3">
                <div class="w-12 h-12 rounded-2xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">percent</span>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-black text-amber-800 dark:text-amber-300 uppercase tracking-wider">Escala por Cantidad</p>
                    <div class="flex items-center gap-1.5 mt-1 font-mono text-xs font-bold">
                        <span class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-800">C1: 40%</span>
                        <span class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-800 text-sky-600 dark:text-sky-400 border border-sky-300 dark:border-sky-800">C2: 70%</span>
                        <span class="px-1.5 py-0.5 rounded bg-white dark:bg-slate-800 text-purple-600 dark:text-purple-400 border border-purple-300 dark:border-purple-800">C3: 60%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barra de Búsqueda y Filtros -->
        <div class="p-4 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-xs mb-6 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="relative w-full md:w-96">
                <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                <input type="text" id="busquedaInput" onkeyup="filtrarTabla()" placeholder="Buscar por CUPS, nombre o nota..." 
                    class="w-full pl-10 pr-4 py-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500/50 transition-all" />
            </div>

            <div class="flex items-center gap-3 w-full md:w-auto justify-between md:justify-end">
                <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
                    <span class="material-symbols-outlined text-base">filter_list</span>
                    <span>Estado:</span>
                    <select id="filtroEstado" onchange="filtrarTabla()" class="px-3 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-800 dark:text-slate-200 focus:outline-none">
                        <option value="ALL">Todos los estados</option>
                        <option value="1">Sólo Activos</option>
                        <option value="0">Sólo Inactivos</option>
                    </select>
                </div>

                <span class="text-xs text-slate-400 font-semibold hidden sm:inline" id="lblTotalFiltrados">
                    Mostrando <?php echo $totalBloqueos; ?> registros
                </span>
            </div>
        </div>

        <!-- Tabla de Procedimientos de Bloqueo -->
        <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden mb-12">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse" id="tablaBloqueos">
                    <thead>
                        <tr class="border-b border-slate-200/80 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 text-slate-500 dark:text-slate-400 font-extrabold uppercase tracking-wider text-[11px]">
                            <th class="py-4 px-5">CUPS</th>
                            <th class="py-4 px-5">Estudio / Procedimiento</th>
                            <th class="py-4 px-4 text-center">Cantidad 1 (Base)</th>
                            <th class="py-4 px-4 text-center">Cantidad 2</th>
                            <th class="py-4 px-4 text-center">Cantidad 3+</th>
                            <th class="py-4 px-5">Condiciones y Notas</th>
                            <th class="py-4 px-4 text-center">Estado</th>
                            <th class="py-4 px-5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 font-medium">
                        <?php if (empty($bloqueosList)): ?>
                        <tr id="filaVacia">
                            <td colspan="8" class="py-12 text-center text-slate-400">
                                <span class="material-symbols-outlined text-4xl block mb-2 opacity-50">inbox</span>
                                No hay procedimientos de bloqueo registrados en el tarifario.
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($bloqueosList as $b): ?>
                            <tr class="fila-bloqueo hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                                data-id="<?php echo $b['id']; ?>"
                                data-codigo="<?php echo htmlspecialchars($b['codigo']); ?>"
                                data-estudio="<?php echo htmlspecialchars($b['estudio']); ?>"
                                data-especialidad="<?php echo htmlspecialchars($b['especialidad'] ?: 'BLOQUEOS'); ?>"
                                data-valor-base="<?php echo $b['valor_base']; ?>"
                                data-valor-cant2="<?php echo $b['valor_cant_2']; ?>"
                                data-valor-cant3="<?php echo $b['valor_cant_3']; ?>"
                                data-pct-base="<?php echo $b['porcentaje_base']; ?>"
                                data-pct-cant2="<?php echo $b['porcentaje_cant_2']; ?>"
                                data-pct-cant3="<?php echo $b['porcentaje_cant_3']; ?>"
                                data-observaciones="<?php echo htmlspecialchars($b['observaciones'] ?? ''); ?>"
                                data-estado="<?php echo $b['estado']; ?>">
                                
                                <!-- Código CUPS -->
                                <td class="py-4 px-5 font-mono font-black text-slate-900 dark:text-white whitespace-nowrap">
                                    <span class="px-2.5 py-1 rounded-xl bg-amber-50 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 shadow-2xs">
                                        <?php echo htmlspecialchars($b['codigo']); ?>
                                    </span>
                                </td>

                                <!-- Estudio -->
                                <td class="py-4 px-5 min-w-[260px]">
                                    <p class="font-bold text-slate-900 dark:text-white leading-snug">
                                        <?php echo htmlspecialchars($b['estudio']); ?>
                                    </p>
                                    <span class="inline-flex items-center gap-1 text-[10px] text-slate-400 font-semibold uppercase mt-0.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        <?php echo htmlspecialchars($b['especialidad'] ?: 'BLOQUEOS'); ?>
                                    </span>
                                </td>

                                <!-- Cantidad 1 (Valor & % Pago) -->
                                <td class="py-4 px-4 text-center whitespace-nowrap">
                                    <div class="font-mono font-black text-slate-900 dark:text-white text-xs">
                                        $<?php echo number_format($b['valor_base'], 0, ',', '.'); ?>
                                    </div>
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded-md bg-emerald-50 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 font-mono text-[10px] font-extrabold border border-emerald-200 dark:border-emerald-800">
                                        Paga <?php echo number_format($b['porcentaje_base'], 1); ?>%
                                    </span>
                                </td>

                                <!-- Cantidad 2 (Valor & % Pago) -->
                                <td class="py-4 px-4 text-center whitespace-nowrap">
                                    <div class="font-mono font-black text-slate-900 dark:text-white text-xs">
                                        $<?php echo number_format($b['valor_cant_2'], 0, ',', '.'); ?>
                                    </div>
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded-md bg-sky-50 dark:bg-sky-950/80 text-sky-700 dark:text-sky-300 font-mono text-[10px] font-extrabold border border-sky-200 dark:border-sky-800">
                                        Paga <?php echo number_format($b['porcentaje_cant_2'], 1); ?>%
                                    </span>
                                </td>

                                <!-- Cantidad 3+ (Valor & % Pago) -->
                                <td class="py-4 px-4 text-center whitespace-nowrap">
                                    <div class="font-mono font-black text-slate-900 dark:text-white text-xs">
                                        $<?php echo number_format($b['valor_cant_3'], 0, ',', '.'); ?>
                                    </div>
                                    <span class="inline-block mt-0.5 px-2 py-0.5 rounded-md bg-purple-50 dark:bg-purple-950/80 text-purple-700 dark:text-purple-300 font-mono text-[10px] font-extrabold border border-purple-200 dark:border-purple-800">
                                        Paga <?php echo number_format($b['porcentaje_cant_3'], 1); ?>%
                                    </span>
                                </td>

                                <!-- Observaciones y Notas -->
                                <td class="py-4 px-5 max-w-[300px]">
                                    <?php if (!empty($b['observaciones'])): ?>
                                        <p class="text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed italic bg-slate-50 dark:bg-slate-800/60 p-2 rounded-xl border border-slate-200/80 dark:border-slate-700/60">
                                            <?php echo htmlspecialchars($b['observaciones']); ?>
                                        </p>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px] italic">Sin observaciones</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Estado Toggle -->
                                <td class="py-4 px-4 text-center">
                                    <?php if ($canEditTarifa): ?>
                                    <button type="button" onclick="cambiarEstadoBloqueo(<?php echo $b['id']; ?>, <?php echo $b['estado'] === 1 ? 0 : 1; ?>)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider transition-all cursor-pointer <?php echo $b['estado'] === 1 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800 hover:bg-emerald-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400 border border-slate-300 dark:border-slate-700 hover:bg-slate-300'; ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?php echo $b['estado'] === 1 ? 'bg-emerald-500' : 'bg-slate-400'; ?>"></span>
                                        <span><?php echo $b['estado'] === 1 ? 'Activo' : 'Inactivo'; ?></span>
                                    </button>
                                    <?php else: ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider <?php echo $b['estado'] === 1 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400'; ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?php echo $b['estado'] === 1 ? 'bg-emerald-500' : 'bg-slate-400'; ?>"></span>
                                        <span><?php echo $b['estado'] === 1 ? 'Activo' : 'Inactivo'; ?></span>
                                    </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Acciones -->
                                <td class="py-4 px-5 text-right whitespace-nowrap">
                                    <?php if ($canEditTarifa): ?>
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" onclick="abrirModalEditarBloqueo(<?php echo htmlspecialchars(json_encode($b)); ?>)" 
                                            class="p-2 rounded-xl text-slate-500 hover:text-amber-600 hover:bg-amber-50 dark:hover:bg-slate-800 transition-all cursor-pointer" title="Editar procedimiento">
                                            <span class="material-symbols-outlined text-lg">edit</span>
                                        </button>
                                        <button type="button" onclick="confirmarEliminarBloqueo(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars(addslashes($b['codigo'])); ?>')" 
                                            class="p-2 rounded-xl text-slate-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800 transition-all cursor-pointer" title="Eliminar procedimiento">
                                            <span class="material-symbols-outlined text-lg">delete</span>
                                        </button>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-slate-400 text-xs italic">Solo lectura</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- MODAL: CREAR / EDITAR PROCEDIMIENTO DE BLOQUEO -->
    <div id="modalBloqueo" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
        <div class="relative w-full max-w-2xl bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden my-8 transform transition-all">
            <!-- Modal Header -->
            <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between bg-slate-50/80 dark:bg-slate-800/40">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-amber-500/20 text-amber-600 dark:text-amber-400">
                        <span class="material-symbols-outlined text-xl">syringe</span>
                    </div>
                    <div>
                        <h3 id="modalBloqueoTitulo" class="text-base sm:text-lg font-black text-slate-900 dark:text-white font-outfit">
                            Nuevo Estudio de Bloqueo
                        </h3>
                        <p class="text-xs text-slate-400">Configure los valores y porcentajes contratados para cada cantidad</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalBloqueo()" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-all cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Modal Form -->
            <form id="formBloqueo" onsubmit="guardarFormBloqueo(event)" class="p-6 space-y-5">
                <input type="hidden" name="action" id="bloqueoAction" value="crear_bloqueo" />
                <input type="hidden" name="id" id="bloqueoId" value="" />

                <!-- Código CUPS y Especialidad -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                            Código CUPS <span class="text-rose-500">*</span>
                        </label>
                        <input type="text" name="codigo" id="bloqueoCodigo" required placeholder="Ej: 048101" 
                            class="w-full px-4 py-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/50 uppercase" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                            Especialidad
                        </label>
                        <input type="text" name="especialidad" id="bloqueoEspecialidad" value="BLOQUEOS" placeholder="Ej: BLOQUEOS" 
                            class="w-full px-4 py-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/50 uppercase" />
                    </div>
                </div>

                <!-- Nombre / Descripción del Estudio -->
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                        Nombre / Descripción del Procedimiento <span class="text-rose-500">*</span>
                    </label>
                    <textarea name="estudio" id="bloqueoEstudio" required rows="2" placeholder="Ej: BLOQUEO DE NERVIO TRIGEMINAL O ESFENOPALATINO" 
                        class="w-full px-4 py-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/50 uppercase"></textarea>
                </div>

                <!-- Configuración por Cantidad (3 Bloques independientes) -->
                <div class="space-y-3">
                    <p class="text-[11px] font-black uppercase tracking-wider text-amber-700 dark:text-amber-300 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">tune</span>
                        <span>Valores del Examen y Porcentajes de Pago por Cantidad</span>
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <!-- Cantidad 1 -->
                        <div class="p-3.5 rounded-2xl bg-emerald-50/50 dark:bg-emerald-950/20 border border-emerald-200/80 dark:border-emerald-800/60 space-y-2.5">
                            <div class="flex items-center justify-between">
                                <span class="px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-900/60 text-emerald-800 dark:text-emerald-300 text-[10px] font-black uppercase">
                                    Cantidad 1 (Base)
                                </span>
                            </div>
                            <div>
                                <label class="block text-[10px] font-extrabold uppercase text-slate-500 mb-1">Valor Estudio ($)</label>
                                <input type="number" step="1" name="valor_base" id="bloqueoValorBase" required placeholder="3260400" 
                                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-extrabold uppercase text-slate-500 mb-1">% Pago Médico</label>
                                <input type="number" step="0.1" name="porcentaje_base" id="bloqueoPorcentajeBase" value="40.0" required 
                                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-emerald-600 dark:text-emerald-400 text-center" />
                            </div>
                        </div>

                        <!-- Cantidad 2 -->
                        <div class="p-3.5 rounded-2xl bg-sky-50/50 dark:bg-sky-950/20 border border-sky-200/80 dark:border-sky-800/60 space-y-2.5">
                            <div class="flex items-center justify-between">
                                <span class="px-2 py-0.5 rounded-md bg-sky-100 dark:bg-sky-900/60 text-sky-800 dark:text-sky-300 text-[10px] font-black uppercase">
                                    Cantidad 2
                                </span>
                            </div>
                            <div>
                                <label class="block text-[10px] font-extrabold uppercase text-slate-500 mb-1">Valor Estudio ($)</label>
                                <input type="number" step="1" name="valor_cant_2" id="bloqueoValorCant2" required placeholder="5542680" 
                                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-sky-500/50" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-extrabold uppercase text-slate-500 mb-1">% Pago Médico</label>
                                <input type="number" step="0.1" name="porcentaje_cant_2" id="bloqueoPorcentajeCant2" value="70.0" required 
                                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-sky-600 dark:text-sky-400 text-center" />
                            </div>
                        </div>

                        <!-- Cantidad 3+ -->
                        <div class="p-3.5 rounded-2xl bg-purple-50/50 dark:bg-purple-950/20 border border-purple-200/80 dark:border-purple-800/60 space-y-2.5">
                            <div class="flex items-center justify-between">
                                <span class="px-2 py-0.5 rounded-md bg-purple-100 dark:bg-purple-900/60 text-purple-800 dark:text-purple-300 text-[10px] font-black uppercase">
                                    Cantidad 3 o más
                                </span>
                            </div>
                            <div>
                                <label class="block text-[10px] font-extrabold uppercase text-slate-500 mb-1">Valor Estudio ($)</label>
                                <input type="number" step="1" name="valor_cant_3" id="bloqueoValorCant3" required placeholder="7498920" 
                                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500/50" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-extrabold uppercase text-slate-500 mb-1">% Pago Médico</label>
                                <input type="number" step="0.1" name="porcentaje_cant_3" id="bloqueoPorcentajeCant3" value="60.0" required 
                                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-purple-600 dark:text-purple-400 text-center" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Observaciones y Notas Contractuales -->
                <div>
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">
                        Observaciones y Condiciones Contractuales
                    </label>
                    <textarea name="observaciones" id="bloqueoObservaciones" rows="2" placeholder="Ej: CANTIDAD 2, SE PAGA AL 70%. CANTIDAD 3 SE PAGA AL 60%..." 
                        class="w-full px-4 py-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-medium text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500/50"></textarea>
                </div>

                <!-- Estado Switch -->
                <div class="flex items-center justify-between p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                    <div>
                        <p class="text-xs font-extrabold text-slate-900 dark:text-white">Estado del Procedimiento</p>
                        <p class="text-[10px] text-slate-400">Determina si está habilitado para liquidaciones activas</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" id="bloqueoEstadoToggle" class="sr-only peer" checked onchange="document.getElementById('bloqueoEstado').value = this.checked ? 1 : 0;" />
                        <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-emerald-500"></div>
                    </label>
                    <input type="hidden" name="estado" id="bloqueoEstado" value="1" />
                </div>

                <!-- Modal Actions -->
                <div class="pt-3 border-t border-slate-200 dark:border-slate-800 flex items-center justify-end gap-3">
                    <button type="button" onclick="cerrarModalBloqueo()" 
                        class="px-4 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold transition-all cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarBloqueo" 
                        class="px-5 py-2.5 rounded-2xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white text-xs font-black shadow-md shadow-amber-500/25 transition-all cursor-pointer flex items-center gap-2">
                        <span class="material-symbols-outlined text-base">save</span>
                        <span id="btnGuardarTexto">Guardar Procedimiento</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript Functions -->
    <script>
        // Filtrado en tiempo real por búsqueda y estado
        function filtrarTabla() {
            const query = (document.getElementById('busquedaInput').value || '').toLowerCase().trim();
            const filtroEstado = document.getElementById('filtroEstado').value;
            const filas = document.querySelectorAll('.fila-bloqueo');
            let visibles = 0;

            filas.forEach(f => {
                const codigo = (f.getAttribute('data-codigo') || '').toLowerCase();
                const estudio = (f.getAttribute('data-estudio') || '').toLowerCase();
                const obs = (f.getAttribute('data-observaciones') || '').toLowerCase();
                const estado = f.getAttribute('data-estado');

                const coincideTexto = !query || codigo.includes(query) || estudio.includes(query) || obs.includes(query);
                const coincideEstado = (filtroEstado === 'ALL' || estado === filtroEstado);

                if (coincideTexto && coincideEstado) {
                    f.classList.remove('hidden');
                    visibles++;
                } else {
                    f.classList.add('hidden');
                }
            });

            const lbl = document.getElementById('lblTotalFiltrados');
            if (lbl) {
                lbl.innerText = `Mostrando ${visibles} registros`;
            }
        }

        // Modal Nuevo Bloqueo
        function abrirModalNuevoBloqueo() {
            document.getElementById('modalBloqueoTitulo').innerText = 'Nuevo Estudio de Bloqueo';
            document.getElementById('bloqueoAction').value = 'crear_bloqueo';
            document.getElementById('bloqueoId').value = '';
            document.getElementById('bloqueoCodigo').value = '';
            document.getElementById('bloqueoEstudio').value = '';
            document.getElementById('bloqueoEspecialidad').value = 'BLOQUEOS';
            document.getElementById('bloqueoValorBase').value = '';
            document.getElementById('bloqueoValorCant2').value = '';
            document.getElementById('bloqueoValorCant3').value = '';
            document.getElementById('bloqueoPorcentajeBase').value = '40.0';
            document.getElementById('bloqueoPorcentajeCant2').value = '70.0';
            document.getElementById('bloqueoPorcentajeCant3').value = '60.0';
            document.getElementById('bloqueoObservaciones').value = '';
            document.getElementById('bloqueoEstado').value = '1';
            document.getElementById('bloqueoEstadoToggle').checked = true;
            document.getElementById('btnGuardarTexto').innerText = 'Guardar Procedimiento';

            document.getElementById('modalBloqueo').classList.remove('hidden');
            document.getElementById('bloqueoCodigo').focus();
        }

        // Modal Editar Bloqueo
        function abrirModalEditarBloqueo(b) {
            document.getElementById('modalBloqueoTitulo').innerText = 'Editar Estudio de Bloqueo';
            document.getElementById('bloqueoAction').value = 'guardar_bloqueo';
            document.getElementById('bloqueoId').value = b.id;
            document.getElementById('bloqueoCodigo').value = b.codigo;
            document.getElementById('bloqueoEstudio').value = b.estudio;
            document.getElementById('bloqueoEspecialidad').value = b.especialidad || 'BLOQUEOS';
            document.getElementById('bloqueoValorBase').value = Math.round(b.valor_base);
            document.getElementById('bloqueoValorCant2').value = Math.round(b.valor_cant_2 || 0);
            document.getElementById('bloqueoValorCant3').value = Math.round(b.valor_cant_3 || 0);
            document.getElementById('bloqueoPorcentajeBase').value = b.porcentaje_base;
            document.getElementById('bloqueoPorcentajeCant2').value = b.porcentaje_cant_2;
            document.getElementById('bloqueoPorcentajeCant3').value = b.porcentaje_cant_3;
            document.getElementById('bloqueoObservaciones').value = b.observaciones || '';
            document.getElementById('bloqueoEstado').value = b.estado;
            document.getElementById('bloqueoEstadoToggle').checked = (parseInt(b.estado) === 1);
            document.getElementById('btnGuardarTexto').innerText = 'Actualizar Procedimiento';

            document.getElementById('modalBloqueo').classList.remove('hidden');
            document.getElementById('bloqueoEstudio').focus();
        }

        // Cerrar Modal
        function cerrarModalBloqueo() {
            document.getElementById('modalBloqueo').classList.add('hidden');
        }

        // Guardar Formulario (Creación o Edición)
        async function guardarFormBloqueo(e) {
            e.preventDefault();
            const form = document.getElementById('formBloqueo');
            const formData = new FormData(form);
            const btn = document.getElementById('btnGuardarBloqueo');
            btn.disabled = true;

            try {
                const res = await fetch('tarifario_bloqueos.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Operación Exitosa',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'No se pudo procesar la solicitud.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error de Conexión',
                    text: 'Ocurrió un fallo de red al comunicar con el servidor.'
                });
            } finally {
                btn.disabled = false;
            }
        }

        // Cambiar Estado Rápido
        async function cambiarEstadoBloqueo(id, nuevoEstado) {
            try {
                const formData = new FormData();
                formData.append('action', 'cambiar_estado');
                formData.append('id', id);
                formData.append('estado', nuevoEstado);

                const res = await fetch('tarifario_bloqueos.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    window.location.reload();
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo actualizar el estado.' });
            }
        }

        // Confirmar y Eliminar
        async function confirmarEliminarBloqueo(id, cups) {
            const confirmacion = await Swal.fire({
                title: '¿Eliminar procedimiento?',
                text: `Se eliminará el código CUPS ${cups} del Tarifario de Bloqueos HO.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            });

            if (!confirmacion.isConfirmed) return;

            try {
                const formData = new FormData();
                formData.append('action', 'eliminar_bloqueo');
                formData.append('id', id);

                const res = await fetch('tarifario_bloqueos.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Eliminado',
                        text: data.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({ icon: 'error', title: 'Error', text: 'Error al eliminar el registro.' });
            }
        }

        // Exportar a CSV
        function exportarExcel() {
            const filas = document.querySelectorAll('.fila-bloqueo:not(.hidden)');
            if (!filas.length) {
                Swal.fire({ icon: 'info', title: 'Sin registros', text: 'No hay procedimientos para exportar.' });
                return;
            }

            let csvContent = "\uFEFFCUPS;Estudio;Especialidad;Valor Cant. 1;Porcentaje C1;Valor Cant. 2;Porcentaje C2;Valor Cant. 3;Porcentaje C3;Observaciones;Estado\n";

            filas.forEach(f => {
                const cups = f.getAttribute('data-codigo');
                const estudio = (f.getAttribute('data-estudio') || '').replace(/;/g, ',');
                const v1 = f.getAttribute('data-valor-base');
                const p1 = f.getAttribute('data-pct-base') + '%';
                const v2 = f.getAttribute('data-valor-cant2');
                const p2 = f.getAttribute('data-pct-cant2') + '%';
                const v3 = f.getAttribute('data-valor-cant3');
                const p3 = f.getAttribute('data-pct-cant3') + '%';
                const obs = (f.getAttribute('data-observaciones') || '').replace(/;/g, ',').replace(/\n/g, ' ');
                const estado = f.getAttribute('data-estado') === '1' ? 'Activo' : 'Inactivo';

                csvContent += `"${cups}";"${estudio}";"BLOQUEOS";"${v1}";"${p1}";"${v2}";"${p2}";"${v3}";"${p3}";"${obs}";"${estado}"\n`;
            });

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("download", `Tarifario_Bloqueos_HO_${new Date().toISOString().slice(0,10)}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>

<?php
/**
 * Módulo de Aprobación de Liquidaciones Médicas y Trazabilidad - LIHO
 * IPS Hernán Ocazionez y Cía S.A.S.
 */
if (isset($_GET['action']) || isset($_POST['action'])) {
    @ini_set('display_errors', '0');
    @error_reporting(0);
}

date_default_timezone_set('America/Bogota');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (!empty($_GET['action']) || !empty($_POST['action'])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'error' => 'Sesión expirada. Inicie sesión nuevamente.'));
        exit;
    }
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/includes/liquidaciones_helper.php';

$userId     = $_SESSION['user_id'] ?? 0;
$userName   = $_SESSION['user_name'] ?? 'Usuario';
$userRole   = $_SESSION['user_role'] ?? 'MÉDICO';
$userRoleId = $_SESSION['user_role_id'] ?? 3;

$canApprove = ($userRoleId == 1 || $userRoleId == 2 || in_array(strtoupper($userRole), ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']));

if (!$canApprove && !tienePermisoModulo($userId, $userRoleId, 'liquidaciones')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// --------------------------------------------------------------------------
// ENDPOINTS AJAX (fetch_data, cambiar_estado, ver_detalle)
// --------------------------------------------------------------------------
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'fetch_data') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $filtros = array(
        'estado'      => trim($_GET['estado'] ?? ''),
        'medico'      => trim($_GET['medico'] ?? ''),
        'fecha_desde' => trim($_GET['fecha_desde'] ?? ''),
        'fecha_hasta' => trim($_GET['fecha_hasta'] ?? '')
    );

    // Obtener liquidaciones generales (sin filtro de estado) para calcular KPIs reales
    $filtrosGenerales = $filtros;
    $filtrosGenerales['estado'] = '';
    $todasLiquidaciones = obtenerLiquidacionesBD($filtrosGenerales);

    $kpis = array(
        'total'          => count($todasLiquidaciones),
        'pendientes'     => 0,
        'aprobadas'      => 0,
        'rechazadas'     => 0,
        'monto_aprobado' => 0
    );

    foreach ($todasLiquidaciones as $l) {
        $st = strtoupper($l['estado']);
        if ($st === 'PENDIENTE') {
            $kpis['pendientes']++;
        } elseif ($st === 'APROBADA') {
            $kpis['aprobadas']++;
            $kpis['monto_aprobado'] += floatval($l['total_a_pagar']);
        } elseif ($st === 'CANCELADA' || $st === 'RECHAZADA') {
            $kpis['rechazadas']++;
        }
    }

    // Filtrar tabla por estado en memoria si se requiere
    $liquidacionesTabla = empty($filtros['estado']) ? $todasLiquidaciones : array_values(array_filter($todasLiquidaciones, function($item) use ($filtros) {
        return strtoupper($item['estado']) === strtoupper($filtros['estado']);
    }));

    echo json_encode(array('success' => true, 'data' => $liquidacionesTabla, 'kpis' => $kpis));
    exit;
}

if ($action === 'ver_detalle') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    $item = obtenerLiquidacionPorIdBD($id, false);
    if ($item) {
        echo json_encode(array('success' => true, 'data' => $item));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Liquidación no encontrada.'));
    }
    exit;
}

if ($action === 'fetch_sede_examenes' || $action === 'ver_examenes_sede') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id   = intval($_GET['id'] ?? 0);
    $sede = trim($_GET['sede'] ?? '');
    $examenes = obtenerExamenesSedeLiquidacionBD($id, $sede);
    $total = 0;
    foreach ($examenes as $ex) {
        $total += floatval($ex['valor_a_pagar'] ?? 0);
    }
    echo json_encode(array('success' => true, 'examenes' => $examenes, 'total' => $total, 'sede' => $sede));
    exit;
}

if ($action === 'obtener_detalles_excel') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    $item = obtenerLiquidacionPorIdBD($id, true);
    if ($item) {
        echo json_encode(array('success' => true, 'data' => $item));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Liquidación no encontrada.'));
    }
    exit;
}

if ($action === 'verificar_hash') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    $res = verificarIntegridadLiquidacionBD($id);
    echo json_encode($res);
    exit;
}

if ($action === 'descargar_pdf') {
    $id = intval($_GET['id'] ?? 0);
    $liq = obtenerLiquidacionPorIdBD($id, true);
    if ($liq) {
        $pdfData = generarPDFLiquidacion($id);
        if (!empty($pdfData)) {
            $safeMedico = preg_replace('/[^a-zA-Z0-9_-]/', '_', $liq['medico_nombre'] ?? 'MEDICO');
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="Liquidacion_' . $id . '_' . $safeMedico . '.pdf"');
            echo $pdfData;
            exit;
        }
    }
    header("Location: aprobacion_liquidaciones.php?error=pdf_not_found");
    exit;
}

if ($action === 'cambiar_estado') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!$canApprove) {
        echo json_encode(array('success' => false, 'error' => 'No tienes permiso para aprobar o rechazar liquidaciones.'));
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true) ?: $_POST;

    $id           = intval($inputData['id'] ?? 0);
    $nuevoEstado  = strtoupper(trim($inputData['estado'] ?? ''));
    $observaciones = trim($inputData['observaciones'] ?? '');

    if ($id <= 0 || !in_array($nuevoEstado, array('APROBADA', 'RECHAZADA', 'CANCELADA'))) {
        echo json_encode(array('success' => false, 'error' => 'Parámetros inválidos.'));
        exit;
    }

    $res = cambiarEstadoLiquidacionBD($id, $nuevoEstado, $userId, $userName, $userRole, $observaciones);
    echo json_encode($res);
    exit;
}

if ($action === 'obtener_destinatarios_reenvio') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'ID de liquidación inválido.'));
        exit;
    }
    $destinatarios = obtenerDestinatariosReenvioLiquidacion($id);
    echo json_encode(array('success' => true, 'data' => $destinatarios));
    exit;
}

if ($action === 'reenviar_correo') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true) ?: $_POST;

    $id = intval($inputData['id'] ?? 0);
    $correosExtra = $inputData['correos_extra'] ?? array();
    if (!is_array($correosExtra)) {
        $correosExtra = array();
    }

    if ($id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'ID de liquidación inválido.'));
        exit;
    }

    $res = reenviarLiquidacionPorCorreo($id, $userId, $userName, $userRole, $correosExtra);
    echo json_encode($res);
    exit;
}


// Generador de Meses para filtro
$nombresMeses = array(
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
);
$mesActualKey = date('Y-m');
$primerDiaMesActual = date('Y-m-01');
$ultimoDiaMesActual = date('Y-m-t');
$nombreMesActual = $nombresMeses[intval(date('n'))] . ' ' . date('Y');
$anioActual = date('Y');

$opcionesMeses = array();
for ($i = 0; $i < 18; $i++) {
    $time = strtotime("first day of -$i month");
    $k = date('Y-m', $time);
    $a = date('Y', $time);
    $m = intval(date('n', $time));
    $nombre = $nombresMeses[$m] . ' ' . $a;
    if ($i === 0) {
        $nombre .= ' (Mes Actual)';
    }
    $opcionesMeses[$k] = $nombre;
}

// --------------------------------------------------------------------------
// VISTA HTML
// --------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aprobación de Liquidaciones Médicas - LIHO</title>
    
    <!-- Logo Favicon -->
    <link rel="shortcut icon" href="assets/img/hologo.png">
    <link rel="icon" type="image/png" href="assets/img/hologo.png">

    <!-- Fuentes Google & Tailwind CSS -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Material Symbols Outlined -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />

    <!-- FontAwesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />

    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: '#11263c',
                        secondary: '#1e3a5f',
                        tertiary: '#008b8b',
                        accent: '#0d9488'
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        outfit: ['Outfit', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <style>
    @media print {
        @page {
            size: letter portrait;
            margin: 10mm 12mm 12mm 12mm;
        }

        html, body {
            background: #ffffff !important;
            color: #0f172a !important;
            font-size: 10.5px !important;
            line-height: 1.25 !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            height: auto !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Ocultar toda la página excepto el modal de liquidación */
        body > *:not(#modalDetalleLiq) {
            display: none !important;
        }

        #modalDetalleLiq {
            position: static !important;
            display: block !important;
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
            opacity: 1 !important;
            pointer-events: auto !important;
            width: 100% !important;
            max-width: 100% !important;
            box-shadow: none !important;
            border: none !important;
            overflow: visible !important;
            backdrop-filter: none !important;
            transform: none !important;
        }

        #modalDetalleLiq > div {
            max-width: 100% !important;
            max-height: none !important;
            box-shadow: none !important;
            border: none !important;
            background: #ffffff !important;
            border-radius: 0 !important;
            overflow: visible !important;
            display: block !important;
            transform: none !important;
        }

        /* Ocultar elementos no imprimibles */
        .no-print,
        #modalDetalleLiq .no-print,
        button,
        input,
        .cursor-pointer,
        .material-symbols-outlined,
        .fa-arrow-right {
            display: none !important;
        }

        #modalBodyDetail {
            padding: 0 !important;
            background: #ffffff !important;
            overflow: visible !important;
            max-height: none !important;
            display: block !important;
        }

        /* Bloques no divisibles */
        .avoid-page-break,
        .border,
        table,
        tr,
        .card-sede-print {
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }

        /* Colores corporativos de impresión de alto contraste */
        .bg-primary {
            background-color: #0f172a !important;
            color: #ffffff !important;
        }
        .bg-slate-900, .dark\:bg-slate-900, .bg-slate-50, .dark\:bg-slate-950 {
            background-color: #ffffff !important;
            color: #0f172a !important;
        }
        .bg-slate-100, .dark\:bg-slate-800, .bg-slate-800 {
            background-color: #f8fafc !important;
            color: #0f172a !important;
        }
        .text-slate-900, .dark\:text-white, .text-white {
            color: #0f172a !important;
        }
        .text-slate-500, .dark\:text-slate-400, .text-slate-400 {
            color: #475569 !important;
        }
        .border-slate-200, .border-slate-700, .dark\:border-slate-800, .border-slate-300 {
            border-color: #cbd5e1 !important;
        }

        /* Tablas */
        table {
            width: 100% !important;
            border-collapse: collapse !important;
        }
        th, td {
            padding: 3.5px 5px !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        th {
            background-color: #f1f5f9 !important;
            color: #1e293b !important;
            font-weight: 800 !important;
            font-size: 9px !important;
            text-transform: uppercase !important;
        }

        /* Layout Grid para impresión */
        .xl\:grid-cols-12 {
            display: flex !important;
            flex-direction: row !important;
            gap: 14px !important;
        }
        .xl\:col-span-8 {
            flex: 0 0 62% !important;
            max-width: 62% !important;
        }
        .xl\:col-span-4 {
            flex: 0 0 38% !important;
            max-width: 38% !important;
        }
        .md\:grid-cols-2 {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 8px !important;
        }

        /* Total a pagar Banner Corporativo */
        .bg-teal-950\/40 {
            background-color: #0f766e !important;
            border: 2px solid #0f766e !important;
            border-radius: 8px !important;
            padding: 8px !important;
            text-align: center !important;
        }
        .bg-teal-950\/40 * {
            color: #ffffff !important;
        }

        /* Firmas Corporativas */
        .print-signatures-block {
            display: flex !important;
            justify-content: space-between !important;
            margin-top: 28px !important;
            padding-top: 15px !important;
            border-top: 1px solid #cbd5e1 !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
        }
        .print-signature-col {
            width: 45% !important;
            text-align: center !important;
        }
        .print-signature-line {
            border-top: 1px solid #0f172a !important;
            margin-bottom: 4px !important;
            padding-top: 4px !important;
            font-weight: bold !important;
            color: #0f172a !important;
            font-size: 10px !important;
        }
    }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 font-sans min-h-screen flex flex-col transition-colors duration-300">

    <!-- Header / Navbar -->
    <?php include_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="flex-1 max-w-[98%] 2xl:max-w-[1780px] w-full mx-auto px-2 sm:px-4 lg:px-6 py-8 space-y-6">
        
        <!-- Header del Módulo & Barra de Período Global -->
        <div class="bg-white dark:bg-slate-900 p-6 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs space-y-5">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="p-3.5 rounded-2xl bg-tertiary/10 dark:bg-tertiary/20 text-tertiary dark:text-emerald-400 border border-tertiary/20">
                        <span class="material-symbols-outlined text-3xl">payments</span>
                    </div>
                    <div>
                        <h1 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white font-outfit tracking-tight">
                            Aprobación de Liquidaciones Médicas
                        </h1>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400">
                            Gestión, revisión de deducciones contables y trazabilidad completa de aprobaciones
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2.5">
                    <button type="button" id="btnActualizar" onclick="actualizarDatosManual()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs transition-all cursor-pointer shadow-xs">
                        <i id="iconActualizar" class="fa-solid fa-rotate-right text-sm"></i>
                        <span>Actualizar</span>
                    </button>
                </div>
            </div>

            <!-- Control de Período Global: Navegador de Meses + Píldoras Rápidas -->
            <div class="pt-4 border-t border-slate-100 dark:border-slate-800/80 flex flex-col md:flex-row md:items-center justify-between gap-3.5">
                
                <!-- Selector / Navegador Interactivo de Meses -->
                <div class="flex items-center gap-1.5 bg-slate-100 dark:bg-slate-800/90 p-1.5 rounded-2xl border border-slate-200 dark:border-slate-700/80 shadow-xs relative" id="periodNavContainer">
                    <button type="button" onclick="navegarMesRelativo(-1)" class="p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 hover:text-tertiary transition-all cursor-pointer" title="Mes Anterior">
                        <i class="fa-solid fa-chevron-left text-xs"></i>
                    </button>

                    <!-- Botón Desplegable Popover -->
                    <button type="button" id="btnPeriodoActivo" onclick="toggleModalSelectorMeses(event)" class="px-3.5 py-1.5 rounded-xl bg-white dark:bg-slate-700 text-slate-900 dark:text-white font-black font-outfit text-xs sm:text-sm flex items-center gap-2 shadow-xs hover:border-tertiary border border-transparent transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-base text-tertiary">calendar_month</span>
                        <span id="labelPeriodoActivo"><?php echo htmlspecialchars($nombreMesActual); ?></span>
                        <span id="badgeMesActualTag" class="px-1.5 py-0.5 rounded text-[9px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800">Mes Actual</span>
                        <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 ml-1"></i>
                    </button>

                    <button type="button" onclick="navegarMesRelativo(1)" class="p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 hover:text-tertiary transition-all cursor-pointer" title="Mes Siguiente">
                        <i class="fa-solid fa-chevron-right text-xs"></i>
                    </button>

                    <!-- Popover flotante con cuadrícula de meses -->
                    <div id="popoverSelectorMeses" class="hidden absolute top-full left-0 mt-2 z-40 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700/90 rounded-2xl shadow-2xl p-4 w-[320px] sm:w-[360px] animate__animated animate__fadeIn animate__faster space-y-3">
                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-2">
                            <span class="text-xs font-black text-slate-800 dark:text-slate-200 uppercase tracking-wider font-outfit">Seleccionar Período</span>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="cambiarAnioSelector(-1)" class="p-1 rounded hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-200 cursor-pointer"><i class="fa-solid fa-chevron-left text-xs"></i></button>
                                <span id="labelAnioSelector" class="font-bold text-xs text-tertiary font-mono"><?php echo htmlspecialchars($anioActual); ?></span>
                                <button type="button" onclick="cambiarAnioSelector(1)" class="p-1 rounded hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-200 cursor-pointer"><i class="fa-solid fa-chevron-right text-xs"></i></button>
                            </div>
                        </div>

                        <!-- 12 Meses en Grid -->
                        <div class="grid grid-cols-3 gap-1.5" id="gridMesesSelector">
                            <!-- Dinámico en JS -->
                        </div>

                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs">
                            <button type="button" onclick="seleccionarPeriodoEspecial('TODOS')" class="text-[11px] font-bold text-slate-500 hover:text-tertiary cursor-pointer inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">public</span>
                                <span>Todo el Histórico</span>
                            </button>
                            <button type="button" onclick="seleccionarPeriodoEspecial('ACTUAL')" class="text-[11px] font-bold text-emerald-600 hover:text-emerald-500 cursor-pointer inline-flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">bolt</span>
                                <span>Mes Actual</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Píldoras de Acceso Rápido -->
                <div class="flex items-center flex-wrap gap-1.5 text-xs font-bold" id="periodQuickPills">
                    <button type="button" id="pillMesActual" onclick="seleccionarPeriodoEspecial('ACTUAL')" class="px-3 py-1.5 rounded-xl bg-primary text-white dark:bg-tertiary dark:text-white shadow-xs transition-all cursor-pointer flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-xs">event</span>
                        <span>Mes Actual</span>
                    </button>
                    <button type="button" id="pillMesAnterior" onclick="seleccionarPeriodoEspecial('ANTERIOR')" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 transition-all cursor-pointer flex items-center gap-1.5">
                        <span>Mes Anterior</span>
                    </button>
                    <button type="button" id="pillHistorico" onclick="seleccionarPeriodoEspecial('TODOS')" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 transition-all cursor-pointer flex items-center gap-1.5">
                        <span>Todo el Histórico</span>
                    </button>
                    <button type="button" id="pillPersonalizado" onclick="toggleRangoPersonalizado()" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 transition-all cursor-pointer flex items-center gap-1.5">
                        <i class="fa-solid fa-sliders text-[10px]"></i>
                        <span>Personalizado</span>
                    </button>
                </div>

            </div>

            <!-- Panel Desplegable para Rango de Fechas Personalizado (Oculto por defecto) -->
            <div id="panelRangoPersonalizado" class="hidden p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 animate__animated animate__fadeIn animate__faster">
                <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Fecha Desde</label>
                            <input type="date" id="filtroFechaDesde" value="<?php echo $primerDiaMesActual; ?>" class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 outline-none focus:ring-2 focus:ring-tertiary">
                        </div>
                        <span class="text-slate-400 mt-5">a</span>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-1">Fecha Hasta</label>
                            <input type="date" id="filtroFechaHasta" value="<?php echo $ultimoDiaMesActual; ?>" class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 outline-none focus:ring-2 focus:ring-tertiary">
                        </div>
                    </div>
                    <button type="button" onclick="aplicarRangoPersonalizadoManual()" class="px-4 py-2 rounded-xl bg-primary dark:bg-tertiary text-white font-bold text-xs shadow-xs hover:opacity-90 transition-all cursor-pointer">
                        Aplicar Rango
                    </button>
                </div>
            </div>

        </div>

        <!-- Tarjetas KPI Resumen -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <!-- Total Liquidaciones -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Total Liquidaciones</p>
                    <h3 class="text-2xl font-black font-outfit text-slate-900 dark:text-white mt-1" id="kpiTotal">0</h3>
                </div>
                <div class="p-3 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                    <span class="material-symbols-outlined text-2xl">receipt_long</span>
                </div>
            </div>

            <!-- Pendientes -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-amber-200 dark:border-amber-900/60 shadow-xs flex items-center justify-between border-l-4 border-l-amber-500">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Pendientes por Aprobar</p>
                    <h3 class="text-2xl font-black font-outfit text-amber-700 dark:text-amber-300 mt-1" id="kpiPendientes">0</h3>
                </div>
                <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400">
                    <span class="material-symbols-outlined text-2xl">pending_actions</span>
                </div>
            </div>

            <!-- Aprobadas -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-emerald-200 dark:border-emerald-900/60 shadow-xs flex items-center justify-between border-l-4 border-l-emerald-500">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Aprobadas</p>
                    <h3 class="text-2xl font-black font-outfit text-emerald-700 dark:text-emerald-300 mt-1" id="kpiAprobadas">0</h3>
                </div>
                <div class="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
            </div>

            <!-- Monto Total Aprobado -->
            <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-teal-200 dark:border-teal-900/60 shadow-xs flex items-center justify-between border-l-4 border-l-tertiary">
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-tertiary dark:text-teal-400">Monto Total Aprobado</p>
                    <h3 class="text-xl font-black font-outfit text-tertiary dark:text-teal-300 mt-1" id="kpiMontoAprobado">$0</h3>
                </div>
                <div class="p-3 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-tertiary dark:text-teal-400">
                    <span class="material-symbols-outlined text-2xl">attach_money</span>
                </div>
            </div>

        </div>

        <!-- Barra de Búsqueda y Filtros de la Tabla -->
        <div class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-3">
            
            <!-- Buscador por Médico / Cédula -->
            <div class="relative flex-1 max-w-md">
                <span class="material-symbols-outlined text-slate-400 absolute left-3 top-2.5 text-lg pointer-events-none">person_search</span>
                <input type="text" id="filtroMedico" placeholder="Buscar por médico o cédula..." oninput="cargarLiquidaciones()" class="w-full pl-9 pr-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-200 outline-none focus:ring-2 focus:ring-tertiary transition-all">
            </div>

            <!-- Filtro de Estados (Píldoras Segmentadas) -->
            <div class="flex items-center gap-1.5 bg-slate-100 dark:bg-slate-800/80 p-1 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold overflow-x-auto">
                <input type="hidden" id="filtroEstado" value="">
                <button type="button" onclick="filtrarPorEstado('')" id="tabEstadoAll" class="px-3.5 py-1.5 rounded-lg transition-all bg-primary dark:bg-tertiary text-white shadow-xs cursor-pointer">
                    Todos
                </button>
                <button type="button" onclick="filtrarPorEstado('PENDIENTE')" id="tabEstadoPendiente" class="px-3.5 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:text-amber-500 transition-all cursor-pointer flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span>Pendientes</span>
                </button>
                <button type="button" onclick="filtrarPorEstado('APROBADA')" id="tabEstadoAprobada" class="px-3.5 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:text-emerald-500 transition-all cursor-pointer flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span>Aprobadas</span>
                </button>
                <button type="button" onclick="filtrarPorEstado('CANCELADA')" id="tabEstadoCancelada" class="px-3.5 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:text-rose-500 transition-all cursor-pointer flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                    <span>Canceladas</span>
                </button>
            </div>

        </div>

        <!-- Tabla de Liquidaciones -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100 dark:bg-slate-800/80 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-800">
                            <th class="py-3 px-4">ID</th>
                            <th class="py-3 px-4">FECHA REGISTRO</th>
                            <th class="py-3 px-4">PERIODO</th>
                            <th class="py-3 px-4">MÉDICO / PROFESIONAL</th>
                            <th class="py-3 px-4 text-right">TOTAL FACTURA</th>
                            <th class="py-3 px-4 text-right">DEDUCCIONES</th>
                            <th class="py-3 px-4 text-right">TOTAL A PAGAR</th>
                            <th class="py-3 px-4 text-center">ESTADO</th>
                            <th class="py-3 px-4 text-center">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyLiquidaciones" class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-200">
                        <!-- Cargado por AJAX -->
                    </tbody>
                </table>
            </div>
            <div id="loadingTable" class="p-8 text-center text-slate-400 hidden">
                <span class="material-symbols-outlined text-3xl animate-spin block mb-2">sync</span>
                <p class="font-bold text-xs">Cargando registros de liquidación...</p>
            </div>
        </div>

    </main>

    <!-- Modal Detalle y Revisión de Liquidación -->
    <div id="modalDetalleLiq" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/70 backdrop-blur-md hidden transition-opacity duration-300 opacity-0 pointer-events-none">
        <div class="bg-slate-50 dark:bg-slate-950 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl max-w-6xl w-full overflow-hidden flex flex-col max-h-[92vh] transition-transform duration-300 scale-95">
            
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between bg-white dark:bg-slate-900 no-print">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-tertiary/10 dark:bg-tertiary/30 text-tertiary dark:text-emerald-400 border border-tertiary/20">
                        <span class="material-symbols-outlined text-2xl">receipt_long</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white font-outfit" id="modalTitleDetail">Liquidación de Turnos / Honorarios Médicos</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Revisión de montos, deducciones y bitácora de trazabilidad</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="confirmarReenvioCorreoModal()" class="px-3.5 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white font-extrabold text-xs shadow-md transition-all cursor-pointer inline-flex items-center gap-1.5 border border-sky-400/20" title="Reenviar liquidación por correo a Dirección Médica, Médico y Creador">
                        <i class="fa-solid fa-paper-plane text-xs"></i>
                        <span>Reenviar Correo</span>
                    </button>
                    <button type="button" onclick="imprimirLiquidacionDetalle()" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold text-xs flex items-center gap-1.5 transition-colors cursor-pointer">
                        <span class="material-symbols-outlined text-base">print</span>
                        <span>Imprimir / PDF</span>
                    </button>
                    <button type="button" onclick="exportarLiquidacionCompletaExcel()" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold text-xs shadow-md transition-all cursor-pointer inline-flex items-center gap-1.5 border border-emerald-400/20">
                        <i class="fa-solid fa-file-excel text-sm"></i>
                        <span>Exportar Excel</span>
                    </button>
                    <button type="button" onclick="cerrarModalDetalle()" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors cursor-pointer rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span class="material-symbols-outlined text-2xl">close</span>
                    </button>
                </div>
            </div>

            <div class="p-6 overflow-y-auto flex-1 space-y-6 text-xs bg-slate-100/50 dark:bg-slate-950/50" id="modalBodyDetail">
                <!-- Contenido Dinámico con Layout Exacto -->
            </div>

            <div class="px-6 py-3.5 border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 flex flex-col sm:flex-row items-center justify-between gap-3 no-print" id="modalFooterActions">
                <!-- Botones de Acción según estado -->
            </div>

        </div>
    </div>

    <!-- Footer -->
    <?php include_once __DIR__ . '/includes/footer.php'; ?>

    <script>
        let liquidacionesData = [];

        // -------------------------------------------------------------
        // GESTOR DE PERÍODO EJECUTIVO & MESES
        // -------------------------------------------------------------
        const NOMBRES_MESES = [
            'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
        ];

        const hoy = new Date();
        const anioActualReal = hoy.getFullYear();
        const mesActualReal = hoy.getMonth() + 1; // 1-12

        let periodState = {
            mode: 'MONTH', // 'MONTH', 'ALL', 'CUSTOM'
            year: anioActualReal,
            month: mesActualReal,
            selectorYear: anioActualReal
        };

        function inicializarModuloAprobacion() {
            inicializarPeriodo();
            cargarLiquidaciones();

            // Cerrar popover al hacer clic afuera
            document.addEventListener('click', (e) => {
                const popover = document.getElementById('popoverSelectorMeses');
                const btnPeriodo = document.getElementById('btnPeriodoActivo');
                if (popover && !popover.classList.contains('hidden')) {
                    if (!popover.contains(e.target) && !btnPeriodo.contains(e.target)) {
                        popover.classList.add('hidden');
                    }
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', inicializarModuloAprobacion);
        } else {
            inicializarModuloAprobacion();
        }

        function inicializarPeriodo() {
            setPeriodoMes(anioActualReal, mesActualReal, false);
        }

        function setPeriodoMes(year, month, doReload = true) {
            periodState.mode = 'MONTH';
            periodState.year = year;
            periodState.month = month;
            periodState.selectorYear = year;

            const primerDia = `${year}-${String(month).padStart(2, '0')}-01`;
            const ultimoDiaNum = new Date(year, month, 0).getDate();
            const ultimoDia = `${year}-${String(month).padStart(2, '0')}-${String(ultimoDiaNum).padStart(2, '0')}`;

            document.getElementById('filtroFechaDesde').value = primerDia;
            document.getElementById('filtroFechaHasta').value = ultimoDia;

            // Actualizar etiqueta del período
            const esMesActual = (year === anioActualReal && month === mesActualReal);
            document.getElementById('labelPeriodoActivo').textContent = `${NOMBRES_MESES[month - 1]} ${year}`;
            
            const badgeActual = document.getElementById('badgeMesActualTag');
            if (badgeActual) {
                if (esMesActual) {
                    badgeActual.classList.remove('hidden');
                    badgeActual.textContent = 'Mes Actual';
                } else {
                    badgeActual.classList.add('hidden');
                }
            }

            // Ocultar panel de rango personalizado
            const panelCustom = document.getElementById('panelRangoPersonalizado');
            if (panelCustom) panelCustom.classList.add('hidden');

            actualizarEstilosPildorasPeriodo();

            if (doReload) {
                cargarLiquidaciones();
            }
        }

        function navegarMesRelativo(delta) {
            if (periodState.mode !== 'MONTH') {
                setPeriodoMes(anioActualReal, mesActualReal, true);
                return;
            }
            let y = periodState.year;
            let m = periodState.month + delta;
            if (m > 12) {
                m = 1;
                y++;
            } else if (m < 1) {
                m = 12;
                y--;
            }
            setPeriodoMes(y, m, true);
        }

        function toggleModalSelectorMeses(e) {
            if (e) e.stopPropagation();
            const popover = document.getElementById('popoverSelectorMeses');
            if (!popover) return;
            const isHidden = popover.classList.contains('hidden');
            if (isHidden) {
                periodState.selectorYear = periodState.year || anioActualReal;
                renderGridMesesSelector();
                popover.classList.remove('hidden');
            } else {
                popover.classList.add('hidden');
            }
        }

        function cambiarAnioSelector(delta) {
            periodState.selectorYear += delta;
            renderGridMesesSelector();
        }

        function renderGridMesesSelector() {
            const labelAnio = document.getElementById('labelAnioSelector');
            if (labelAnio) labelAnio.textContent = periodState.selectorYear;

            const grid = document.getElementById('gridMesesSelector');
            if (!grid) return;

            let html = '';
            for (let m = 1; m <= 12; m++) {
                const isSelected = (periodState.mode === 'MONTH' && periodState.year === periodState.selectorYear && periodState.month === m);
                const isCurrent = (anioActualReal === periodState.selectorYear && mesActualReal === m);

                let btnClass = 'p-2 rounded-xl text-center text-xs font-bold transition-all cursor-pointer border ';
                if (isSelected) {
                    btnClass += 'bg-primary text-white dark:bg-tertiary dark:text-white border-transparent shadow-xs';
                } else if (isCurrent) {
                    btnClass += 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700 hover:bg-emerald-100';
                } else {
                    btnClass += 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700';
                }

                html += `
                    <button type="button" onclick="seleccionarMesDirecto(${periodState.selectorYear}, ${m})" class="${btnClass}">
                        <span>${NOMBRES_MESES[m - 1].substring(0, 3)}</span>
                        ${isCurrent ? '<span class="block text-[8px] font-black uppercase text-emerald-500">• Actual</span>' : ''}
                    </button>
                `;
            }
            grid.innerHTML = html;
        }

        function seleccionarMesDirecto(year, month) {
            const popover = document.getElementById('popoverSelectorMeses');
            if (popover) popover.classList.add('hidden');
            setPeriodoMes(year, month, true);
        }

        function seleccionarPeriodoEspecial(tipo) {
            const popover = document.getElementById('popoverSelectorMeses');
            if (popover) popover.classList.add('hidden');

            const panelCustom = document.getElementById('panelRangoPersonalizado');
            if (panelCustom) panelCustom.classList.add('hidden');

            if (tipo === 'ACTUAL') {
                setPeriodoMes(anioActualReal, mesActualReal, true);
            } else if (tipo === 'ANTERIOR') {
                let y = anioActualReal;
                let m = mesActualReal - 1;
                if (m < 1) {
                    m = 12;
                    y--;
                }
                setPeriodoMes(y, m, true);
            } else if (tipo === 'TODOS') {
                periodState.mode = 'ALL';
                document.getElementById('filtroFechaDesde').value = '';
                document.getElementById('filtroFechaHasta').value = '';
                document.getElementById('labelPeriodoActivo').textContent = 'Todo el Histórico';
                const badgeActual = document.getElementById('badgeMesActualTag');
                if (badgeActual) badgeActual.classList.add('hidden');
                actualizarEstilosPildorasPeriodo();
                cargarLiquidaciones();
            }
        }

        function toggleRangoPersonalizado() {
            const panel = document.getElementById('panelRangoPersonalizado');
            if (!panel) return;
            const isHidden = panel.classList.contains('hidden');
            if (isHidden) {
                panel.classList.remove('hidden');
                periodState.mode = 'CUSTOM';
                document.getElementById('labelPeriodoActivo').textContent = 'Personalizado';
                const badgeActual = document.getElementById('badgeMesActualTag');
                if (badgeActual) badgeActual.classList.add('hidden');
                actualizarEstilosPildorasPeriodo();
            } else {
                panel.classList.add('hidden');
            }
        }

        function aplicarRangoPersonalizadoManual() {
            const fDesde = document.getElementById('filtroFechaDesde').value;
            const fHasta = document.getElementById('filtroFechaHasta').value;
            if (!fDesde || !fHasta) {
                SwalCustom.fire({ icon: 'warning', title: 'Rango Incompleto', text: 'Por favor seleccione ambas fechas.' });
                return;
            }
            periodState.mode = 'CUSTOM';
            document.getElementById('labelPeriodoActivo').textContent = `${fDesde} al ${fHasta}`;
            const badgeActual = document.getElementById('badgeMesActualTag');
            if (badgeActual) badgeActual.classList.add('hidden');
            actualizarEstilosPildorasPeriodo();
            cargarLiquidaciones();
        }

        function actualizarEstilosPildorasPeriodo() {
            const pillActual = document.getElementById('pillMesActual');
            const pillAnterior = document.getElementById('pillMesAnterior');
            const pillHistorico = document.getElementById('pillHistorico');
            const pillPersonalizado = document.getElementById('pillPersonalizado');

            const classActive = 'px-3 py-1.5 rounded-xl bg-primary text-white dark:bg-tertiary dark:text-white shadow-xs transition-all cursor-pointer flex items-center gap-1.5 font-bold';
            const classInactive = 'px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 transition-all cursor-pointer flex items-center gap-1.5 font-bold';

            if (pillActual) pillActual.className = (periodState.mode === 'MONTH' && periodState.year === anioActualReal && periodState.month === mesActualReal) ? classActive : classInactive;
            
            let prevYear = anioActualReal;
            let prevMonth = mesActualReal - 1;
            if (prevMonth < 1) { prevMonth = 12; prevYear--; }
            if (pillAnterior) pillAnterior.className = (periodState.mode === 'MONTH' && periodState.year === prevYear && periodState.month === prevMonth) ? classActive : classInactive;

            if (pillHistorico) pillHistorico.className = (periodState.mode === 'ALL') ? classActive : classInactive;
            if (pillPersonalizado) pillPersonalizado.className = (periodState.mode === 'CUSTOM') ? classActive : classInactive;
        }

        function filtrarPorEstado(estado) {
            document.getElementById('filtroEstado').value = estado;

            // Actualizar estilo de tabs
            const tabs = {
                '': document.getElementById('tabEstadoAll'),
                'PENDIENTE': document.getElementById('tabEstadoPendiente'),
                'APROBADA': document.getElementById('tabEstadoAprobada'),
                'CANCELADA': document.getElementById('tabEstadoCancelada')
            };

            for (const key in tabs) {
                if (tabs[key]) {
                    if (key === estado) {
                        tabs[key].className = 'px-3.5 py-1.5 rounded-lg transition-all bg-primary dark:bg-tertiary text-white shadow-xs cursor-pointer flex items-center gap-1.5 font-bold';
                    } else {
                        tabs[key].className = 'px-3.5 py-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-tertiary transition-all cursor-pointer flex items-center gap-1.5 font-bold';
                    }
                }
            }

            cargarLiquidaciones();
        }

        const SwalCustom = Swal.mixin({
            customClass: {
                popup: 'bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl text-slate-100 font-sans p-6',
                title: 'text-lg font-black font-outfit text-white tracking-wide',
                htmlContainer: 'text-xs text-slate-300 font-medium leading-relaxed',
                confirmButton: 'px-5 py-2.5 rounded-xl bg-primary hover:bg-slate-800 dark:bg-tertiary dark:hover:bg-teal-700 text-white font-bold text-xs shadow-md transition-all cursor-pointer mx-1 border border-white/10',
                cancelButton: 'px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-all cursor-pointer mx-1 border border-slate-700',
                input: 'bg-slate-800 border border-slate-700 text-white rounded-xl text-xs focus:ring-2 focus:ring-tertiary outline-none p-3'
            },
            buttonsStyling: false,
            showClass: {
                popup: 'animate__animated animate__fadeInDown animate__faster'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOutUp animate__faster'
            }
        });

        let isFetchingLiquidaciones = false;
        async function cargarLiquidaciones() {
            if (isFetchingLiquidaciones) return;
            isFetchingLiquidaciones = true;

            const btnActualizar = document.querySelector('button[onclick*="actualizarDatosManual"]');
            if (btnActualizar) {
                btnActualizar.disabled = true;
                btnActualizar.classList.add('opacity-70', 'cursor-wait');
            }

            const spinner = document.getElementById('loadingTable');
            const tbody   = document.getElementById('tbodyLiquidaciones');
            if (spinner) spinner.classList.remove('hidden');

            const estado = document.getElementById('filtroEstado').value;
            const medico = document.getElementById('filtroMedico').value;
            const fDesde = document.getElementById('filtroFechaDesde').value;
            const fHasta = document.getElementById('filtroFechaHasta').value;

            const url = `aprobacion_liquidaciones.php?action=fetch_data&estado=${encodeURIComponent(estado)}&medico=${encodeURIComponent(medico)}&fecha_desde=${encodeURIComponent(fDesde)}&fecha_hasta=${encodeURIComponent(fHasta)}`;

            try {
                const response = await fetch(url);
                const result = await response.json();

                if (result.success) {
                    liquidacionesData = result.data || [];
                    actualizarKPIs(result.kpis || {});
                    renderizarTabla(liquidacionesData);
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error de Carga', text: result.error || 'Error desconocido' });
                }
            } catch (err) {
                console.error("Error en la solicitud AJAX:", err);
                SwalCustom.fire({ icon: 'error', title: 'Error de Conexión', text: 'Ocurrió un error al cargar las liquidaciones.' });
            } finally {
                if (spinner) spinner.classList.add('hidden');
                if (btnActualizar) {
                    btnActualizar.disabled = false;
                    btnActualizar.classList.remove('opacity-70', 'cursor-wait');
                }
                isFetchingLiquidaciones = false;
            }
        }

        async function actualizarDatosManual() {
            const icon = document.getElementById('iconActualizar');
            if (icon) icon.classList.add('fa-spin');
            await cargarLiquidaciones();
            if (icon) setTimeout(() => icon.classList.remove('fa-spin'), 400);
        }

        function actualizarKPIs(kpis) {
            document.getElementById('kpiTotal').textContent = (kpis.total || 0).toLocaleString();
            document.getElementById('kpiPendientes').textContent = (kpis.pendientes || 0).toLocaleString();
            document.getElementById('kpiAprobadas').textContent = (kpis.aprobadas || 0).toLocaleString();
            document.getElementById('kpiMontoAprobado').textContent = `$ ${(kpis.monto_aprobado || 0).toLocaleString('es-CO')}`;
        }

        function renderizarTabla(list) {
            const tbody = document.getElementById('tbodyLiquidaciones');
            tbody.innerHTML = '';

            if (list.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="py-8 text-center text-slate-400">
                            <span class="material-symbols-outlined text-3xl block mb-1">inbox</span>
                            <p class="font-bold text-xs">No se encontraron liquidaciones registradas.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            list.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors";

                let badgeEstado = '';
                const st = (item.estado || 'PENDIENTE').toUpperCase();

                if (st === 'APROBADA') {
                    badgeEstado = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800"><i class="fa-solid fa-circle-check"></i> APROBADA</span>`;
                } else if (st === 'CANCELADA' || st === 'RECHAZADA') {
                    badgeEstado = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-200 dark:border-rose-800"><i class="fa-solid fa-ban"></i> CANCELADA</span>`;
                } else {
                    badgeEstado = `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800"><i class="fa-solid fa-clock"></i> PENDIENTE</span>`;
                }

                let subUserText = '';
                if (st === 'APROBADA') {
                    subUserText = `<div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-bold mt-1" title="Aprobado por ${htmlspecialchars(item.usuario_aprobador_nombre || 'N/A')} el ${item.fecha_aprobacion || ''}">
                        <i class="fa-solid fa-user-check mr-1"></i>${htmlspecialchars(item.usuario_aprobador_nombre || 'Aprobado')}
                    </div>`;
                } else if (st === 'CANCELADA' || st === 'RECHAZADA') {
                    subUserText = `<div class="text-[10px] text-rose-600 dark:text-rose-400 font-bold mt-1" title="Cancelado por ${htmlspecialchars(item.usuario_aprobador_nombre || 'N/A')} el ${item.fecha_aprobacion || ''}">
                        <i class="fa-solid fa-user-xmark mr-1"></i>${htmlspecialchars(item.usuario_aprobador_nombre || 'Cancelado')}
                    </div>`;
                } else {
                    subUserText = `<div class="text-[10px] text-slate-400 font-medium mt-1">Por revisar</div>`;
                }

                const isGlobalMed = (item.medico_cedula === 'GLOBAL' || (item.medico_nombre && (item.medico_nombre.toUpperCase().includes('GLOBAL') || item.medico_nombre.toUpperCase().includes('TODOS'))));
                let medicoCellHtml = '';
                if (isGlobalMed) {
                    const entNom = item.entidad_nombre || 'Hernán Ocazionez y Cía S.A.S.';
                    medicoCellHtml = `
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 border border-indigo-200/60 dark:border-indigo-800/60 shrink-0">
                                <i class="fa-solid fa-hospital text-xs"></i>
                            </span>
                            <div>
                                <div class="font-bold text-slate-900 dark:text-white text-xs uppercase tracking-tight">${htmlspecialchars(entNom)}</div>
                                <div class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800/40 mt-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    <span>Liquidación Global de Entidad</span>
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    medicoCellHtml = `
                        <div class="font-bold text-slate-900 dark:text-white">${htmlspecialchars(item.medico_nombre)}</div>
                        <div class="text-[10px] text-slate-400 font-mono">CC/ID: ${htmlspecialchars(item.medico_cedula)}</div>
                    `;
                }

                tr.innerHTML = `
                    <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">#${item.id}</td>
                    <td class="py-3 px-4">
                        <div class="text-xs font-semibold text-slate-700 dark:text-slate-300 font-mono">${item.fecha_creacion}</div>
                        <div class="text-[10px] text-slate-400 font-semibold mt-0.5" title="Usuario que registró la liquidación">
                            <i class="fa-solid fa-user-pen mr-1 text-slate-500"></i>${htmlspecialchars(item.usuario_creador_nombre || 'Sistema')}
                        </div>
                    </td>
                    <td class="py-3 px-4 font-semibold text-slate-700 dark:text-slate-300">${item.periodo_desde} AL ${item.periodo_hasta}</td>
                    <td class="py-3 px-4">
                        ${medicoCellHtml}
                    </td>
                    <td class="py-3 px-4 text-right font-mono font-bold text-slate-900 dark:text-white">$ ${(parseFloat(item.total_factura) || 0).toLocaleString('es-CO')}</td>
                    <td class="py-3 px-4 text-right font-mono font-bold text-rose-600 dark:text-rose-400">- $ ${(parseFloat(item.total_deducciones) || 0).toLocaleString('es-CO')}</td>
                    <td class="py-3 px-4 text-right">
                        <div class="font-mono font-black text-tertiary dark:text-emerald-400 text-sm">$ ${(parseFloat(item.total_a_pagar) || 0).toLocaleString('es-CO')}</div>
                        ${item.tiene_ajustes ? `
                            <div class="text-[10px] font-bold text-indigo-600 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/80 px-2 py-0.5 rounded border border-indigo-200 dark:border-indigo-800 mt-1 inline-flex items-center gap-1" title="Ajustes registrados para esta liquidación">
                                <span class="material-symbols-outlined text-[11px]">edit_note</span>
                                <span>${item.cant_notas_ajuste} Ajuste(s)</span>
                            </div>
                        ` : ''}
                    </td>
                    <td class="py-3 px-4 text-center">
                        ${badgeEstado}
                        ${subUserText}
                    </td>
                    <td class="py-3 px-4 text-center">
                        <div class="flex items-center justify-center gap-1.5">
                            <button type="button" onclick="verDetalleLiquidacion(${item.id}, this)" class="px-2.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-bold text-xs inline-flex items-center gap-1.5 transition-all cursor-pointer shadow-xs border border-transparent hover:border-slate-300 dark:hover:border-slate-600" title="Ver Detalle y Auditoría">
                                <i class="fa-solid fa-eye text-tertiary"></i>
                                <span>Ver Detalle</span>
                            </button>
                            <button type="button" onclick="confirmarReenvioCorreo(${item.id}, event)" class="px-2.5 py-1.5 rounded-xl bg-sky-50 hover:bg-sky-100 dark:bg-sky-950/60 dark:hover:bg-sky-900/60 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800 font-bold text-xs inline-flex items-center gap-1 transition-all cursor-pointer shadow-xs hover:scale-105" title="Reenviar liquidación al correo a Dirección Médica, Médico y Creador">
                                <i class="fa-solid fa-paper-plane text-sky-600 dark:text-sky-400"></i>
                                <span>Reenviar</span>
                            </button>
                        </div>
                    </td>
                `;

                tbody.appendChild(tr);
            });
        }

        function imprimirLiquidacionDetalle() {
            const item = window.currentLiquidationData;
            if (item && item.id) {
                window.open(`aprobacion_liquidaciones.php?action=descargar_pdf&id=${item.id}`, '_blank');
            } else {
                window.print();
            }
        }

        async function exportarLiquidacionCompletaExcel() {
            const item = window.currentLiquidationData;
            if (!item) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin datos', text: 'No hay liquidación seleccionada para exportar.' });
                return;
            }

            let sedesObj = window.sedesObjCache || {};
            // Verificar si tenemos los examenes detallados en memoria; si no, recuperarlos en 1 llamada
            let tieneExamenes = Object.values(sedesObj).some(s => s && Array.isArray(s.examenes) && s.examenes.length > 0);
            if (!tieneExamenes) {
                try {
                    SwalCustom.fire({
                        title: 'Preparando Excel...',
                        text: 'Generando archivo de exportación detallado...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                    const resp = await fetch(`aprobacion_liquidaciones.php?action=obtener_detalles_excel&id=${item.id}`);
                    const res = await resp.json();
                    if (res.success && res.data && res.data.detalles_json) {
                        sedesObj = typeof res.data.detalles_json === 'string' ? JSON.parse(res.data.detalles_json) : res.data.detalles_json;
                        window.sedesObjCache = sedesObj;
                    }
                    Swal.close();
                } catch(e) {
                    Swal.close();
                    console.error(e);
                }
            }

            let rows = [];
            rows.push(['LIQUIDACIÓN DE TURNOS / HONORARIOS MÉDICOS - LIHO']);
            rows.push(['EMPRESA:', item.entidad_nombre || 'HERNÁN OCAZIONEZ Y CÍA S.A.S.']);
            rows.push(['LIQUIDACIÓN N°:', item.id]);
            rows.push(['ESTADO:', item.estado]);
            rows.push(['PROFESIONAL / MÉDICO:', item.medico_nombre]);
            rows.push(['CÉDULA / USUARIO:', item.medico_cedula]);
            rows.push(['PERIODO:', `${item.periodo_desde} AL ${item.periodo_hasta}`]);
            rows.push(['FECHA REGISTRO:', item.fecha_creacion]);
            rows.push(['REGISTRADO POR:', item.usuario_creador_nombre || 'SISTEMA']);
            rows.push([]);

            rows.push(['--- CONSULTA DETALLADA DE EXÁMENES Y ESTUDIOS ---']);
            rows.push([
                'ORIGEN',
                'REF / ID EVENTO',
                'FUENTE',
                'INGRESO',
                'TIPO PACIENTE',
                'FECHA',
                'SEDE',
                'MÉDICO (PROTEO)',
                'CÉDULA MÉDICO',
                'PACIENTE',
                'IDENTIFICACIÓN PACIENTE',
                'ENTIDAD / CONVENIO',
                'CÓDIGO CUPS',
                'DESCRIPCIÓN EXAMEN',
                'CANTIDAD'
            ]);

            for (const [sede, sData] of Object.entries(sedesObj)) {
                if (sData && typeof sData === 'object' && Array.isArray(sData.examenes)) {
                    sData.examenes.forEach(ex => {
                        const esBoni = (ex.es_bonificacion === true || ex.cups === 'BONI_TOHO' || ex.id_ref === 'BONI_TOHO' || (ex.examen && (ex.examen.toUpperCase().includes('BONIFICACI') || ex.examen.toUpperCase().includes('BONI_TOHO'))));
                        rows.push([
                            ex.origen || (esBoni ? 'SISTEMA' : 'PROTEO'),
                            ex.id_ref || ex.id || (esBoni ? 'BONI_TOHO' : ''),
                            ex.fuente || (esBoni ? 'LIHO' : ''),
                            ex.ingreso || (esBoni ? 'BONIFICACION' : ''),
                            ex.tipo_paciente || (esBoni ? 'Incentivo' : 'Empresa'),
                            ex.fecha || item.periodo_hasta || '',
                            ex.sede || sede,
                            ex.medico_nombre || item.medico_nombre || '',
                            ex.medico_cedula || item.medico_cedula || '',
                            ex.paciente || (esBoni ? 'INCENTIVO POR PRODUCTIVIDAD' : (ex.nombre || '')),
                            ex.documento || (esBoni ? 'N/A' : ''),
                            ex.entidad || (esBoni ? 'LIHO IPS' : ''),
                            ex.cups || (esBoni ? 'BONI_TOHO' : ''),
                            ex.examen || ex.nombre_examen || (esBoni ? 'BONIFICACIÓN TOMOGRAFÍAS (REGLA 50 CT x $150.000 COP)' : ''),
                            parseInt(ex.cantidad || 1)
                        ]);
                    });
                } else if (sData && sData.conceptos) {
                    for (const [cKey, cVal] of Object.entries(sData.conceptos)) {
                        const esBoni = (cVal.es_bonificacion === true || cKey.includes('BONIFICACI') || cKey.includes('BONI') || cKey === 'BONI_TOHO');
                        rows.push([
                            esBoni ? 'SISTEMA' : 'PROTEO',
                            esBoni ? 'BONI_TOHO' : '',
                            esBoni ? 'LIHO' : '',
                            esBoni ? 'BONIFICACION' : '',
                            esBoni ? 'Incentivo' : 'Empresa',
                            item.periodo_hasta || '',
                            sede,
                            item.medico_nombre,
                            item.medico_cedula,
                            esBoni ? 'INCENTIVO POR PRODUCTIVIDAD' : 'PACIENTES AGRUPADOS',
                            esBoni ? 'N/A' : '',
                            esBoni ? 'LIHO IPS' : '',
                            esBoni ? 'BONI_TOHO' : cKey,
                            esBoni ? 'BONIFICACIÓN TOMOGRAFÍAS (REGLA 50 CT x $150.000 COP)' : 'ESTUDIOS MÉDICOS / RXSI',
                            parseInt(cVal.cantidad || cVal.cant || 1)
                        ]);
                    }
                } else {
                    const totalSedeCant = parseInt(sData?.examenes_count || sData?.cant || 1);
                    rows.push([
                        'PROTEO',
                        '',
                        '',
                        '',
                        'Empresa',
                        item.periodo_hasta || '',
                        sede,
                        item.medico_nombre,
                        item.medico_cedula,
                        'PACIENTES SEDE',
                        '',
                        '',
                        'RXSI',
                        'ESTUDIOS MÉDICOS',
                        totalSedeCant
                    ]);
                }
            }

            rows.push([]);
            if (item.desglose_medicos && Array.isArray(item.desglose_medicos) && item.desglose_medicos.length > 0) {
                rows.push(['--- PRODUCCIÓN POR MÉDICO (LIQUIDACIÓN GLOBAL) ---']);
                rows.push(['MÉDICO / ESPECIALISTA', 'CÉDULA / ID', 'CANTIDAD ESTUDIOS', 'VALOR TOTAL GENERADO']);
                item.desglose_medicos.forEach(m => {
                    rows.push([m.nombre, m.cedula, m.cantidad, m.total]);
                });
                rows.push([]);
            }
            rows.push(['--- INFORMACIÓN CONTABLE Y DEDUCCIONES ---']);
            rows.push(['TOTAL FACTURA:', item.total_factura]);
            rows.push(['AFC MES (ESTIMADO):', item.ded_ibc || 0]);
            rows.push(['MENOS APORTE SALUD:', item.ded_salud || 0]);
            rows.push(['MENOS APORTE ARL:', item.ded_arl || 0]);
            rows.push(['MENOS APORTE PENSIÓN:', item.ded_pension || 0]);
            rows.push(['MENOS APORTES AFC:', item.ded_afc || 0]);
            rows.push(['MENOS FONDO SOLIDARIDAD:', item.ded_solidaridad || 0]);
            if (parseFloat(item.ded_rete_383_info || 0) > 0 || parseFloat(item.ded_rete_383 || 0) > 0) {
                rows.push(['RETE FUENTE ART 383 (INFORMATIVO):', item.ded_rete_383_info || 0]);
                rows.push(['RETENCIÓN ART 383 (MANUAL):', item.ded_rete_383 || 0]);
            } else if (parseFloat(item.ded_retencion || 0) > 0) {
                rows.push([`RETENCIÓN (${item.ded_retencion_pct || 0}%):`, item.ded_retencion || 0]);
            }
            rows.push(['TOTAL DEDUCCIONES:', item.total_deducciones || 0]);

            // Novedades aplicadas
            let novList = [];
            try {
                if (item.novedades_json) {
                    novList = typeof item.novedades_json === 'string' ? JSON.parse(item.novedades_json) : (item.novedades_json || []);
                }
            } catch(e) {}
            if (novList && novList.length > 0) {
                rows.push([]);
                rows.push(['--- NOVEDADES APLICADAS ---']);
                rows.push(['CÓDIGO / CONCEPTO', 'TIPO', 'OBSERVACIÓN', 'VALOR']);
                novList.forEach(n => {
                    const esAd = (n.tipo === 'ADICION' || n.tipo === 'ADICIÓN');
                    rows.push([n.nombre || n.codigo || 'NOVEDAD', esAd ? 'ADICIÓN (+)' : 'DEDUCCIÓN (-)', n.observacion || '', (esAd ? '+' : '-') + (n.valor || 0)]);
                });
                rows.push(['TOTAL NOVEDADES NETO:', item.total_novedades_neto || 0]);
            }

            rows.push(['TOTAL A PAGAR:', item.total_a_pagar || 0]);

            let csvContent = '\uFEFF';
            rows.forEach(r => {
                const line = r.map(val => `"${String(val ?? '').replace(/"/g, '""')}"`).join(';');
                csvContent += line + '\r\n';
            });

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            const safeName = (item.medico_nombre || 'MEDICO').replace(/[^a-zA-Z0-9_-]/g, '_');
            link.setAttribute('href', url);
            link.setAttribute('download', `Liquidacion_${item.id}_${safeName}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        let isOpeningDetail = false;
        async function verDetalleLiquidacion(id, btnEl = null) {
            if (isOpeningDetail) return;
            isOpeningDetail = true;

            window.currentLiquidationId = id;
            let origBtnHtml = '';
            if (btnEl) {
                origBtnHtml = btnEl.innerHTML;
                btnEl.disabled = true;
                btnEl.classList.add('opacity-70', 'cursor-wait');
                btnEl.innerHTML = `<span class="material-symbols-outlined text-xs animate-spin text-tertiary">sync</span><span>Cargando...</span>`;
            }

            // Abrir el modal inmediatamente con skeleton loader interactivo
            document.getElementById('modalTitleDetail').textContent = `Liquidación de Turnos N° ${id} - Cargando...`;
            const body = document.getElementById('modalBodyDetail');
            body.innerHTML = `
                <div class="py-20 flex flex-col items-center justify-center space-y-4 text-center">
                    <div class="w-16 h-16 rounded-3xl bg-teal-500/10 border border-teal-500/30 flex items-center justify-center text-teal-400 shadow-inner">
                        <span class="material-symbols-outlined text-3xl animate-spin">sync</span>
                    </div>
                    <div class="space-y-1 max-w-sm">
                        <h4 class="text-sm font-black text-slate-800 dark:text-slate-100 font-outfit">Cargando Detalle de Liquidación...</h4>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Recuperando desglose de estudios por sede, deducciones contables y bitácora de auditoría.</p>
                    </div>
                </div>
            `;
            const footer = document.getElementById('modalFooterActions');
            footer.innerHTML = `
                <div class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                    <span class="material-symbols-outlined text-xs animate-spin">sync</span>
                    <span>Cargando auditoría...</span>
                </div>
                <button type="button" onclick="cerrarModalDetalle()" class="px-5 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-bold text-xs cursor-pointer hover:bg-slate-300 dark:hover:bg-slate-700 transition-colors">
                    Cerrar
                </button>
            `;
            abrirModalDetalle();
            try {
                const response = await fetch(`aprobacion_liquidaciones.php?action=ver_detalle&id=${id}`);
                const result = await response.json();

                if (!result.success) {
                    SwalCustom.fire({ icon: 'error', title: 'Error de Detalle', text: result.error || 'Error no especificado' });
                    return;
                }

                const item = result.data;
                window.currentLiquidationId = item.id;
                window.currentLiquidationData = item;
                document.getElementById('modalTitleDetail').textContent = `Liquidación de Turnos N° ${item.id} - ${item.medico_nombre}`;

                // Procesar detalles de sedes y conceptos (utiliza resumen ultrarrápido)
                window.sedesObjCache = {};
                let sedesObj = {};
                try {
                    const rawSedes = item.resumen_sedes_json || item.detalles_json || '{}';
                    sedesObj = typeof rawSedes === 'string' ? JSON.parse(rawSedes) : (rawSedes || {});
                    window.sedesObjCache = sedesObj;
                } catch(e) {}

                let sedesCardsHtml = '';
                let resumenSedesRowsHtml = '';
                let totalFacturaSum = 0;
                let globalNoCruzadosCount = 0;
                let globalNoCruzadosValor = 0;
                let totalBonosTomografia = 0;
                let totalBonosTomografiaValor = 0;
                let sedesConBono = [];

                const sedesKeys = Object.keys(sedesObj).sort();

                if (sedesKeys.length === 0) {
                    sedesCardsHtml = `
                        <div class="col-span-2 py-8 text-center text-slate-400">
                            <span class="material-symbols-outlined text-3xl block mb-1">info</span>
                            <p class="font-bold text-xs">No se encontraron detalles de sedes almacenados para esta liquidación.</p>
                        </div>
                    `;
                } else {
                    sedesKeys.forEach(sKey => {
                        const sData = sedesObj[sKey];
                        let totalSedeVal = 0;
                        let totalSedeCant = 0;
                        let sNoCruzadoCount = 0;
                        let sNoCruzadoValor = 0;
                        let conceptosMap = {};

                        // Si viene del resumen precalculado (ultrarrápido)
                        if (sData && typeof sData === 'object' && sData.conceptos) {
                            totalSedeVal = parseFloat(sData.total || 0);
                            totalSedeCant = parseInt(sData.examenes_count || 0);
                            sNoCruzadoCount = parseInt(sData.no_cruzados_count || 0);
                            sNoCruzadoValor = parseFloat(sData.no_cruzados_valor || 0);
                            globalNoCruzadosCount += sNoCruzadoCount;
                            globalNoCruzadosValor += sNoCruzadoValor;

                            for (const [cKey, cVal] of Object.entries(sData.conceptos)) {
                                const esBoni = (cVal.es_bonificacion === true || cKey.includes('BONIFICACI') || cKey.includes('BONI') || cKey === 'BONI_TOHO');
                                const cNombre = esBoni ? 'BONIFICACIÓN TOMOGRAFÍAS' : cKey;
                                const cCant = parseInt(cVal.cantidad || cVal.cant || 0);
                                const cValPagar = parseFloat(cVal.total || cVal.valor || 0);
                                const noCruzCant = parseInt(cVal.no_cruzado_cant || 0);
                                const noCruzVal = parseFloat(cVal.no_cruzado_valor || 0);
                                const cruzCant = cVal.cruzado_cant !== undefined ? parseInt(cVal.cruzado_cant) : (noCruzCant ? Math.max(0, cCant - noCruzCant) : cCant);
                                const cruzVal = cVal.cruzado_valor !== undefined ? parseFloat(cVal.cruzado_valor) : (noCruzVal ? Math.max(0, cValPagar - noCruzVal) : cValPagar);
                                const cTipos = Array.isArray(cVal.cruce_tipos) ? new Set(cVal.cruce_tipos) : new Set();
                                if (noCruzCant > 0 && cTipos.size === 0) cTipos.add('No Cruzado');

                                if (esBoni) {
                                    totalBonosTomografia += cCant;
                                    totalBonosTomografiaValor += cValPagar;
                                    if (!sedesConBono.includes(sKey)) sedesConBono.push(sKey);
                                }

                                conceptosMap[cNombre] = {
                                    nombre: cNombre,
                                    cant: cCant,
                                    valor: cValPagar,
                                    cruzadoCant: cruzCant,
                                    cruzadoValor: cruzVal,
                                    noCruzadoCant: noCruzCant,
                                    noCruzadoValor: noCruzVal,
                                    cruceTipos: cTipos,
                                    esBonificacion: esBoni
                                };
                            }
                        } else if (sData && typeof sData === 'object' && Array.isArray(sData.examenes) && sData.examenes.length > 0) {
                            totalSedeVal = parseFloat(sData.total || 0);
                            sData.examenes.forEach(ex => {
                                const esBoni = (ex.es_bonificacion === true || ex.cups === 'BONI_TOHO' || ex.id_ref === 'BONI_TOHO' || (ex.examen && (ex.examen.toUpperCase().includes('BONIFICACI') || ex.examen.toUpperCase().includes('BONI_TOHO'))));
                                const esCruzado = (ex.cruce === 'CRUZADO');
                                const cruceTipo = ex.cruce || 'SOLO_PROTEO';
                                const v = parseFloat(ex.valor_a_pagar) || 0;
                                const cantEx = parseInt(ex.cantidad) || 1;

                                let cKey = '';
                                if (esBoni) {
                                    cKey = 'BONIFICACIÓN TOMOGRAFÍAS';
                                    totalBonosTomografia += cantEx;
                                    totalBonosTomografiaValor += v;
                                    if (!sedesConBono.includes(sKey)) sedesConBono.push(sKey);
                                } else if (esCruzado) {
                                    if (ex.cups && (ex.cups.toLowerCase().includes('eco') || ex.cups.toLowerCase().includes('ultrasonido'))) {
                                        cKey = 'ECOGRAFÍAS';
                                    } else {
                                        cKey = 'RXSI';
                                    }
                                } else {
                                    let nomEx = (ex.examen || ex.cups || 'EXAMEN').trim();
                                    if (ex.cups && !nomEx.includes(ex.cups)) {
                                        nomEx = `${ex.cups} - ${nomEx}`;
                                    }
                                    cKey = nomEx.length > 32 ? nomEx.substring(0, 32) + '...' : nomEx;
                                }

                                if (!conceptosMap[cKey]) {
                                    conceptosMap[cKey] = {
                                        nombre: cKey,
                                        cant: 0,
                                        valor: 0,
                                        cruzadoCant: 0,
                                        cruzadoValor: 0,
                                        noCruzadoCant: 0,
                                        noCruzadoValor: 0,
                                        cruceTipos: new Set(),
                                        esBonificacion: esBoni
                                    };
                                }

                                conceptosMap[cKey].cant += cantEx;
                                conceptosMap[cKey].valor += v;
                                totalSedeCant += cantEx;

                                if (esBoni || esCruzado) {
                                    conceptosMap[cKey].cruzadoCant += cantEx;
                                    conceptosMap[cKey].cruzadoValor += v;
                                } else {
                                    conceptosMap[cKey].noCruzadoCant += cantEx;
                                    conceptosMap[cKey].noCruzadoValor += v;
                                    conceptosMap[cKey].cruceTipos.add(cruceTipo);
                                    sNoCruzadoCount += cantEx;
                                    sNoCruzadoValor += v;
                                    globalNoCruzadosCount += cantEx;
                                    globalNoCruzadosValor += v;
                                }
                            });
                        } else {
                            if (typeof sData === 'number' || typeof sData === 'string') {
                                totalSedeVal = parseFloat(sData);
                            } else if (sData && typeof sData === 'object') {
                                totalSedeVal = parseFloat(sData.total || 0);
                            }
                            totalSedeCant = Math.round(totalSedeVal / 5800) || 1;
                            conceptosMap['RXSI'] = {
                                nombre: 'RXSI',
                                cant: totalSedeCant,
                                valor: totalSedeVal,
                                cruzadoCant: totalSedeCant,
                                cruzadoValor: totalSedeVal,
                                noCruzadoCant: 0,
                                noCruzadoValor: 0,
                                cruceTipos: new Set(),
                                esBonificacion: false
                            };
                        }

                        totalFacturaSum += totalSedeVal;

                        // Fallback de seguridad: si no hay conceptos pero la sede tiene valor o cantidad
                        if (Object.keys(conceptosMap).length === 0 && (totalSedeVal > 0 || totalSedeCant > 0)) {
                            const cNomFallback = 'ESTUDIOS REALIZADOS';
                            conceptosMap[cNomFallback] = {
                                nombre: cNomFallback,
                                cant: totalSedeCant,
                                valor: totalSedeVal,
                                cruzadoCant: Math.max(0, totalSedeCant - sNoCruzadoCount),
                                cruzadoValor: Math.max(0, totalSedeVal - sNoCruzadoValor),
                                noCruzadoCant: sNoCruzadoCount,
                                noCruzadoValor: sNoCruzadoValor,
                                cruceTipos: sNoCruzadoCount > 0 ? new Set(['No Cruzado']) : new Set(),
                                esBonificacion: false
                            };
                        }

                        // Warning Badge Header
                        let warningBadgeHeader = '';
                        if (sNoCruzadoCount > 0) {
                            warningBadgeHeader = `
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-[#f9f5ec] text-[#664b22] dark:bg-amber-950 dark:text-amber-300 border border-[#e5d9c2] dark:border-amber-700 flex items-center gap-1 shrink-0" title="${sNoCruzadoCount} registro(s) no cruzado(s) incluidos en esta sede">
                                    <span class="material-symbols-outlined text-xs text-[#8a6021] dark:text-amber-400">warning</span>
                                    ${sNoCruzadoCount} NO CRUZADO(S) ($${sNoCruzadoValor.toLocaleString('es-CO')})
                                </span>
                            `;
                        }

                        // Conceptos Rows
                        let conceptosRowsHtml = '';
                        Object.values(conceptosMap).forEach(cObj => {
                            if (cObj.esBonificacion) {
                                conceptosRowsHtml += `
                                    <tr class="border-b border-[#e9dfcc] dark:border-amber-900/60 bg-[#faf6ee] dark:bg-amber-950/40 hover:bg-[#f3ede1] dark:hover:bg-amber-900/50 transition-colors">
                                        <td class="py-2 px-2 text-[11px] font-bold text-[#4a3713] dark:text-amber-200">
                                            <div class="flex items-center flex-wrap gap-1.5">
                                                <span class="material-symbols-outlined text-[#8a6021] dark:text-amber-400 text-sm">military_tech</span>
                                                <span class="font-black tracking-tight text-[#3a2c16] dark:text-amber-100" title="${htmlspecialchars(cObj.nombre)}">${htmlspecialchars(cObj.nombre)}</span>
                                                <span class="px-1.5 py-0.5 rounded text-[8px] font-black uppercase bg-[#ece2cb] dark:bg-amber-900 text-[#4a3713] dark:text-amber-100 border border-[#d8c8a8] dark:border-amber-700">INCENTIVO (50 CT)</span>
                                            </div>
                                        </td>
                                        <td class="py-2 px-2 text-right font-mono font-black text-[#5c4217] dark:text-amber-200 text-[11px]">${cObj.cant.toLocaleString('es-CO')} bono(s)</td>
                                        <td class="py-2 px-2 text-right font-mono font-black text-[#8c5717] dark:text-amber-400 text-[11px]">+$ ${cObj.valor.toLocaleString('es-CO')}</td>
                                    </tr>
                                `;
                                return;
                            }

                            let advertenciaConcepto = '';
                            if (cObj.noCruzadoCant > 0) {
                                const tiposArr = Array.from(cObj.cruceTipos).map(t => {
                                    if (t === 'SOLO_PROTEO') return 'Solo Proteo';
                                    if (t === 'SOLO_SERVINTE') return 'Solo Servinte';
                                    return 'No Cruzado';
                                });
                                const tiposStr = tiposArr.join(', ');

                                if (cObj.cruzadoCant === 0) {
                                    advertenciaConcepto = `<span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-200 dark:border-rose-800 ml-1.5 shrink-0" title="Registro no cruzado en la conciliación (${tiposStr})"><span class="material-symbols-outlined text-[10px]">cancel</span> No Cruzado (${tiposStr})</span>`;
                                } else {
                                    advertenciaConcepto = `<span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800 ml-1.5 shrink-0" title="Incluye ${cObj.noCruzadoCant} registro(s) no cruzado(s) ($${cObj.noCruzadoValor.toLocaleString('es-CO')})"><span class="material-symbols-outlined text-[10px]">warning</span> ${cObj.noCruzadoCant} No Cruzado(s)</span>`;
                                }
                            }

                            conceptosRowsHtml += `
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <td class="py-1.5 px-2 text-[11px] font-medium text-slate-700 dark:text-slate-300">
                                        <div class="flex items-center flex-wrap gap-1">
                                            <span class="truncate max-w-[180px]" title="${htmlspecialchars(cObj.nombre)}">${htmlspecialchars(cObj.nombre)}</span>
                                            ${advertenciaConcepto}
                                        </div>
                                    </td>
                                    <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-[11px]">${cObj.cant.toLocaleString('es-CO')}</td>
                                    <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-900 dark:text-white text-[11px]">$ ${cObj.valor.toLocaleString('es-CO')}</td>
                                </tr>
                            `;
                        });

                        sedesCardsHtml += `
                            <div class="border border-slate-200 dark:border-slate-700/80 rounded-xl overflow-hidden flex flex-col bg-white dark:bg-slate-900 shadow-xs">
                                <div class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700/80 cursor-pointer transition-colors py-1.5 px-3 border-b border-slate-200 dark:border-slate-700 font-bold text-xs text-slate-800 dark:text-slate-200 font-outfit uppercase flex items-center justify-between gap-2 group" onclick="mostrarInformeExamenesSede('${htmlspecialchars(sKey)}', ${item.id})">
                                    <span class="truncate flex items-center gap-1.5">
                                        <i class="fa-solid fa-building text-tertiary"></i>
                                        ${htmlspecialchars(sKey)}
                                    </span>
                                    <div class="flex items-center gap-2">
                                        ${warningBadgeHeader}
                                        <span class="text-[10px] text-tertiary group-hover:underline font-extrabold flex items-center gap-1">
                                            <span>Ver informe</span>
                                            <i class="fa-solid fa-arrow-right text-[9px]"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="overflow-x-auto flex-1 p-1">
                                    <table class="w-full text-left border-collapse">
                                        <thead>
                                            <tr class="bg-slate-50 dark:bg-slate-800/50 text-[10px] font-bold text-slate-400 uppercase border-b border-slate-200 dark:border-slate-700">
                                                <th class="py-1.5 px-2">CENTRO DE COSTO</th>
                                                <th class="py-1.5 px-2 text-right">CANT</th>
                                                <th class="py-1.5 px-2 text-right">VALOR</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${conceptosRowsHtml}
                                            <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold bg-slate-50 dark:bg-slate-800/80 text-xs">
                                                <td class="py-1.5 px-2 text-right font-black uppercase text-[10px] text-slate-500">TOTAL</td>
                                                <td class="py-1.5 px-2 text-right font-mono font-black text-slate-800 dark:text-slate-200">${totalSedeCant.toLocaleString('es-CO')}</td>
                                                <td class="py-1.5 px-2 text-right font-mono font-black text-primary dark:text-tertiary">$ ${totalSedeVal.toLocaleString('es-CO')}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;

                        let noCruzadoSedeTag = '';
                        if (sNoCruzadoCount > 0) {
                            noCruzadoSedeTag = `<span class="inline-flex items-center gap-0.5 text-amber-600 dark:text-amber-400 font-extrabold text-[10px] ml-1" title="${sNoCruzadoCount} registro(s) no cruzado(s) por valor de $${sNoCruzadoValor.toLocaleString('es-CO')}"><span class="material-symbols-outlined text-xs">warning</span> (${sNoCruzadoCount} no cruzado)</span>`;
                        }

                        resumenSedesRowsHtml += `
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="py-1.5 px-3 text-[11px] font-medium text-slate-700 dark:text-slate-300">${htmlspecialchars(sKey)} ${noCruzadoSedeTag}</td>
                                <td class="py-1.5 px-3 text-right font-mono font-bold text-slate-900 dark:text-white">$ ${totalSedeVal.toLocaleString('es-CO')}</td>
                            </tr>
                        `;
                    });
                }

                if (totalFacturaSum === 0) totalFacturaSum = parseFloat(item.total_factura || 0);

                // Warning Banner Registros No Cruzados
                let warningBannerHtml = '';
                if (globalNoCruzadosCount > 0) {
                    warningBannerHtml = `
                        <div class="p-3.5 rounded-2xl bg-[#fbf9f4] dark:bg-amber-950/50 border border-[#dfd5c0] dark:border-amber-700/80 text-[#4e3b1f] dark:text-amber-200 text-xs shadow-xs">
                            <div class="flex items-start gap-2.5">
                                <span class="material-symbols-outlined text-[#8a6021] dark:text-amber-400 text-xl shrink-0 mt-0.5">warning</span>
                                <div class="space-y-0.5">
                                    <p class="font-extrabold text-[#423219] dark:text-amber-300 uppercase tracking-wider text-[11px]">ADVERTENCIA: INCLUYE REGISTROS NO CRUZADOS</p>
                                    <p class="text-[11px] leading-snug opacity-95">
                                        La liquidación total incluye <strong>${globalNoCruzadosCount} registro(s) no cruzado(s)</strong> por valor de <strong>$ ${globalNoCruzadosValor.toLocaleString('es-CO')}</strong>. Los ítems no cruzados han sido incluidos pero están señalizados con la insignia <span class="px-1 py-0.5 rounded bg-[#ece2cb] dark:bg-amber-900/80 text-[#4a3713] dark:text-amber-200 text-[10px] font-black border border-[#d8c8a8] dark:border-amber-700"><i class="fa-solid fa-triangle-exclamation"></i> No Cruzado</span> en cada sede.
                                    </p>
                                </div>
                            </div>
                        </div>
                    `;
                }

                // Deducciones
                const dedAfc         = parseFloat(item.ded_afc || 0);
                const dedSolidaridad = parseFloat(item.ded_solidaridad || 0);
                const dedIbc         = parseFloat(item.ded_ibc || 0);
                const dedSalud       = parseFloat(item.ded_salud || 0);
                const dedPension     = parseFloat(item.ded_pension || 0);
                const dedArl         = parseFloat(item.ded_arl || 0);
                const dedRete383Info = parseFloat(item.ded_rete_383_info || 0);
                const dedRete383     = parseFloat(item.ded_rete_383 || 0);
                const dedRetencionPct= parseFloat(item.ded_retencion_pct || 0);
                const dedRetencion   = parseFloat(item.ded_retencion || 0);
                const totalDeducciones = parseFloat(item.total_deducciones || 0);
                const totalAPagar    = parseFloat(item.total_a_pagar || 0);

                // Novedades aplicadas a la liquidación
                let novedadesLista = [];
                try {
                    if (item.novedades_json) {
                        novedadesLista = typeof item.novedades_json === 'string' ? JSON.parse(item.novedades_json) : (item.novedades_json || []);
                    }
                } catch(e) {
                    novedadesLista = [];
                }
                const totalNovAdicion = parseFloat(item.total_novedades_adicion || 0);
                const totalNovDeduccion = parseFloat(item.total_novedades_deduccion || 0);
                const totalNovNeto = parseFloat(item.total_novedades_neto || (totalNovAdicion - totalNovDeduccion));

                let novedadesCardHtml = '';
                if (novedadesLista && novedadesLista.length > 0) {
                    let novRows = '';
                    novedadesLista.forEach(nov => {
                        const esAdic = (nov.tipo === 'ADICION' || nov.tipo === 'ADICIÓN');
                        const valNum = parseFloat(nov.valor || 0);
                        novRows += `
                            <div class="flex items-start justify-between gap-2 text-xs py-1.5 border-b border-indigo-100/60 dark:border-indigo-900/40 last:border-0">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase ${esAdic ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'}">${esAdic ? '+ ADICIÓN' : '- DEDUCCIÓN'}</span>
                                        <span class="font-bold text-slate-800 dark:text-slate-200 text-[11px]">${htmlspecialchars(nov.nombre || nov.codigo || 'Novedad')}</span>
                                    </div>
                                    ${nov.observacion ? `<p class="text-[10px] text-slate-500 italic mt-0.5">${htmlspecialchars(nov.observacion)}</p>` : ''}
                                </div>
                                <span class="font-mono font-bold text-xs shrink-0 ${esAdic ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}">
                                    ${esAdic ? '+' : '-'}$ ${valNum.toLocaleString('es-CO')}
                                </span>
                            </div>
                        `;
                    });

                    novedadesCardHtml = `
                        <!-- Novedades de la Entidad -->
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200 dark:border-indigo-900/60 shadow-sm overflow-hidden border-l-4 border-l-indigo-500 flex flex-col avoid-page-break">
                            <div class="bg-indigo-50 dark:bg-indigo-950/60 text-indigo-800 dark:text-indigo-300 py-2.5 px-4 font-bold text-xs tracking-widest text-center uppercase border-b border-indigo-200 dark:border-indigo-900/40 flex items-center justify-center gap-1.5 shrink-0">
                                <i class="fa-solid fa-tags text-sm"></i>
                                NOVEDADES APLICADAS (${novedadesLista.length})
                            </div>
                            <div class="p-4 space-y-2">
                                ${novRows}
                                <div class="pt-2 border-t border-slate-200 dark:border-slate-700 flex justify-between items-center text-xs font-bold ${totalNovNeto >= 0 ? 'text-indigo-600 dark:text-indigo-400' : 'text-rose-600 dark:text-rose-400'}">
                                    <span class="uppercase tracking-wider">IMPACTO NETO NOVEDADES</span>
                                    <span class="font-mono text-sm font-black">${totalNovNeto >= 0 ? '+' : ''}$ ${totalNovNeto.toLocaleString('es-CO')}</span>
                                </div>
                            </div>
                        </div>
                    `;
                }

                let statusBadgeText = '';
                if (dedPension === 0 && (dedSalud > 0 || dedArl > 0)) {
                    statusBadgeText = `
                        <div class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-1 text-[10px]">
                            <span class="font-bold flex items-center gap-1.5 text-indigo-700 dark:text-indigo-300">
                                <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                                <span>Médico Pensionado (Salud y ARL)</span>
                            </span>
                            <span class="font-mono font-extrabold text-[9px] text-indigo-600 dark:text-indigo-400">Pensión: $0 (Exento)</span>
                        </div>
                    `;
                } else if (dedSalud > 0 || dedPension > 0 || dedArl > 0 || dedIbc > 0) {
                    statusBadgeText = `
                        <div class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-1 text-[10px]">
                            <span class="font-bold flex items-center gap-1.5 text-teal-700 dark:text-teal-300">
                                <span class="w-2 h-2 rounded-full bg-teal-500"></span>
                                <span>Parafiscales / AFC Activos</span>
                            </span>
                            <span class="font-mono font-extrabold text-[9px] text-teal-600 dark:text-teal-400">Seguridad Social Aplicada</span>
                        </div>
                    `;
                } else {
                    statusBadgeText = `
                        <div class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-1 text-[10px]">
                            <span class="font-medium flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                                <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                                <span>Parafiscales Desactivados ($0)</span>
                            </span>
                            <span class="font-mono font-extrabold text-[9px] text-slate-400">Valores en $0</span>
                        </div>
                    `;
                }

                // Deducciones Rows
                let deduccionesRowsHtml = '';
                if (dedAfc > 0) {
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2">
                            <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">MENOS APORTES AFC</label>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200 text-xs">$ ${dedAfc.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }
                if (dedSolidaridad > 0) {
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2">
                            <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">MENOS APORTES FONDO SOLIDARIDAD</label>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200 text-xs">$ ${dedSolidaridad.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }

                if (dedSalud > 0 || dedArl > 0 || dedIbc > 0) {
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2 pt-1 border-t border-slate-100 dark:border-slate-800">
                            <label class="text-[11px] font-bold text-slate-700 dark:text-slate-200">AFC MES (ESTIMADO)</label>
                            <span class="font-mono font-black text-xs text-slate-900 dark:text-white">$ ${dedIbc.toLocaleString('es-CO')}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                            <label class="text-[11px] font-medium">MENOS APORTES SALUD MES</label>
                            <span class="font-mono font-bold text-xs">- $ ${dedSalud.toLocaleString('es-CO')}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                            <label class="text-[11px] font-medium">MENOS APORTES ARL MES</label>
                            <span class="font-mono font-bold text-xs">- $ ${dedArl.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }

                if (dedPension > 0) {
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                            <label class="text-[11px] font-medium">MENOS APORTES PENSIÓN MES</label>
                            <span class="font-mono font-bold text-xs">- $ ${dedPension.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }

                if (dedRete383Info > 0 || dedRete383 > 0) {
                    deduccionesRowsHtml += `
                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-2">
                            <div class="flex items-center justify-between gap-2 py-1 px-1.5 rounded-lg bg-slate-50/60 dark:bg-slate-800/40">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">RETE FUENTE ART 383</label>
                                    <span class="text-[9px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider bg-slate-200/60 dark:bg-slate-700/60 px-1.5 py-0.5 rounded">Informativo</span>
                                </div>
                                <span class="font-mono font-bold text-xs text-slate-700 dark:text-slate-300">$ ${dedRete383Info.toLocaleString('es-CO')}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">RETENCIÓN ART 383</label>
                                    <span class="text-[9px] font-bold text-cyan-600 dark:text-cyan-400 uppercase tracking-wider bg-cyan-100 dark:bg-cyan-950/60 px-1.5 py-0.5 rounded">Manual</span>
                                </div>
                                <span class="font-mono font-bold text-xs text-rose-700 dark:text-rose-300">- $ ${dedRete383.toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                    `;
                } else if (dedRetencion > 0 || dedRetencionPct > 0) {
                    deduccionesRowsHtml += `
                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">% RETENCIÓN</label>
                                <span class="font-mono font-bold text-slate-800 dark:text-slate-200 text-xs">${dedRetencionPct} %</span>
                            </div>
                            <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                <label class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">RETENCIÓN</label>
                                <span class="font-mono font-bold text-xs">- $ ${dedRetencion.toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                    `;
                }

                if (deduccionesRowsHtml.trim() === '') {
                    deduccionesRowsHtml = `
                        <div class="py-4 px-2 text-center text-slate-400">
                            <div class="w-8 h-8 mx-auto mb-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                                <span class="material-symbols-outlined text-base">money_off</span>
                            </div>
                            <p class="text-[11px] font-semibold">El médico no tiene ninguna deducción activa.</p>
                        </div>
                    `;
                }

                // Logs y Hash
                let logsHtml = '';
                if (item.logs && item.logs.length > 0) {
                    logsHtml = '<div class="space-y-2.5">';
                    item.logs.forEach(l => {
                        let stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950/90 dark:text-amber-300 border border-amber-200 dark:border-amber-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-clock"></i> ${l.accion}</span>`;
                        if (l.accion === 'APROBACIÓN' || l.accion === 'APROBADA') {
                            stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/90 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> APROBACIÓN</span>`;
                        } else if (l.accion === 'CANCELACIÓN' || l.accion === 'CANCELADA' || l.accion === 'RECHAZO' || l.accion === 'RECHAZADA') {
                            stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950/90 dark:text-rose-300 border border-rose-200 dark:border-rose-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-ban"></i> ${l.accion}</span>`;
                        } else if (l.accion === 'CREACIÓN') {
                            stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-sky-100 text-sky-800 dark:bg-sky-950/90 dark:text-sky-300 border border-sky-200 dark:border-sky-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-plus-circle"></i> CREACIÓN</span>`;
                        } else if (l.accion === 'REENVIO_CORREO_LIQUIDACION' || l.accion.includes('REENVIO')) {
                            stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-sky-100 text-sky-800 dark:bg-sky-950/90 dark:text-sky-300 border border-sky-300 dark:border-sky-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-paper-plane"></i> REENVÍO DE CORREO</span>`;
                        } else if (l.modulo === 'CORREO' || l.accion.includes('CORREO') || l.accion.includes('NOTIFICACION')) {
                            stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-indigo-100 text-indigo-800 dark:bg-indigo-950/90 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-envelope"></i> NOTIFICACIÓN CORREO</span>`;
                        }
                        logsHtml += `
                            <div class="p-3.5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/50 shadow-xs flex flex-col gap-1.5">
                                <div class="flex items-center justify-between">
                                    ${stBadge}
                                    <span class="text-[10px] text-slate-500 dark:text-slate-400 font-mono">${l.fecha_registro}</span>
                                </div>
                                <div class="text-xs text-slate-700 dark:text-slate-300">
                                    <strong class="font-bold text-slate-900 dark:text-slate-100">Usuario / Emisor:</strong> ${htmlspecialchars(l.usuario_nombre)} (${htmlspecialchars(l.usuario_rol || 'SISTEMA')})
                                </div>
                                ${l.observaciones ? `<div class="text-[11px] text-slate-600 dark:text-slate-300 italic mt-1 bg-slate-50 dark:bg-slate-800/60 p-2 rounded-xl border border-slate-200/70 dark:border-slate-800">"${htmlspecialchars(l.observaciones)}"</div>` : ''}
                            </div>
                        `;
                    });
                    logsHtml += '</div>';
                }

                // -------------------------------------------------------------
                // Sección de Auditoría de Exámenes Excluidos de Esta Liquidación
                // -------------------------------------------------------------
                let exclusionesSectionHtml = '';
                const exclusionesList = item.exclusiones || [];
                if (exclusionesList.length > 0) {
                    let exclTotalVal = 0;
                    let exclRowsHtml = '';

                    exclusionesList.forEach(ex => {
                        const valExcl = parseFloat(ex.valor_a_pagar || 0);
                        exclTotalVal += valExcl;
                        const pacNom = ex.paciente || 'PACIENTE';
                        const pacDoc = ex.documento_paciente || ex.documento || '-';
                        const exNom = ex.examen_nombre || ex.examen || ex.cups || 'EXAMEN';
                        const cupsCode = ex.cups || '-';
                        const motivo = ex.motivo_exclusion || 'Exclusión manual';
                        const detalle = ex.detalle_exclusion || 'Sin justificación adicional';
                        const auditor = ex.usuario_nombre || item.usuario_creador_nombre || 'Auditor';

                        exclRowsHtml += `
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors">
                                <td class="py-2 px-3">
                                    <div class="font-bold text-slate-800 dark:text-slate-200">${htmlspecialchars(pacNom)}</div>
                                    <div class="text-[10px] font-mono text-slate-400">Doc: ${htmlspecialchars(pacDoc)}</div>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="font-semibold text-slate-900 dark:text-white truncate max-w-[200px]" title="${htmlspecialchars(exNom)}">${htmlspecialchars(exNom)}</div>
                                    <div class="text-[10px] font-mono text-teal-600 dark:text-teal-400 font-bold">CUPS: ${htmlspecialchars(cupsCode)}</div>
                                </td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300 border border-amber-300 dark:border-amber-700">
                                        <span class="material-symbols-outlined text-[10px]">do_not_disturb_on</span>
                                        ${htmlspecialchars(motivo)}
                                    </span>
                                </td>
                                <td class="py-2 px-3 text-slate-600 dark:text-slate-300 italic text-[11px] min-w-[220px] max-w-md break-words whitespace-normal leading-relaxed" title="${htmlspecialchars(detalle)}">
                                    "${htmlspecialchars(detalle)}"
                                </td>
                                <td class="py-2 px-3 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400 whitespace-nowrap">
                                    $ ${valExcl.toLocaleString('es-CO')}
                                </td>
                                <td class="py-2 px-3 text-[10px] text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                    ${htmlspecialchars(auditor)}
                                </td>
                            </tr>
                        `;
                    });

                    exclusionesSectionHtml = `
                        <div class="border-2 border-amber-500/30 dark:border-amber-500/20 rounded-2xl p-4 sm:p-5 bg-gradient-to-br from-amber-50/40 via-white to-slate-50 dark:from-slate-900 dark:via-slate-900 dark:to-slate-800/80 shadow-sm space-y-3 avoid-page-break">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-amber-100 dark:border-slate-800">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-xl bg-amber-500/10 text-amber-500 flex items-center justify-center font-bold shrink-0">
                                        <span class="material-symbols-outlined text-lg">do_not_disturb_on</span>
                                    </div>
                                    <div>
                                        <h4 class="font-outfit font-black text-xs uppercase tracking-wider text-slate-900 dark:text-white flex items-center gap-2">
                                            <span>Exámenes Excluidos de Esta Liquidación (${exclusionesList.length})</span>
                                        </h4>
                                        <p class="text-[10px] text-slate-400">Exámenes omitidos durante la conciliación con causal y justificación obligatoria registrada</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-amber-100 dark:bg-amber-950 text-amber-900 dark:text-amber-300 border border-amber-300 dark:border-amber-800">
                                        Total Excluido: $ ${exclTotalVal.toLocaleString('es-CO')}
                                    </span>
                                    <a href="examenes_excluidos.php" target="_blank" class="px-2.5 py-1 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-700 dark:text-amber-300 text-[10px] font-bold inline-flex items-center gap-1 transition-colors">
                                        <span class="material-symbols-outlined text-xs">open_in_new</span>
                                        <span>Módulo Auditoría</span>
                                    </a>
                                </div>
                            </div>

                            <div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden shadow-xs">
                                <div class="max-h-60 overflow-y-auto">
                                    <table class="w-full text-left text-xs border-collapse">
                                        <thead class="sticky top-0 bg-slate-100 dark:bg-slate-800 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase border-b border-slate-200 dark:border-slate-700">
                                            <tr>
                                                <th class="py-2 px-3">Paciente / Documento</th>
                                                <th class="py-2 px-3">CUPS / Examen</th>
                                                <th class="py-2 px-3">Causal</th>
                                                <th class="py-2 px-3">Justificación / Detalle</th>
                                                <th class="py-2 px-3 text-right">Valor A Pagar</th>
                                                <th class="py-2 px-3">Auditor</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            ${exclRowsHtml}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    `;
                }

                // -------------------------------------------------------------
                // Consolidación de Notas de Ajuste y Doble Huella Digital
                // -------------------------------------------------------------
                const ca = item.consolidacion_ajustes || {
                    tiene_ajustes: false,
                    notas: [],
                    hash_original: item.hash_integridad,
                    hash_consolidado: item.hash_integridad,
                    delta_aprobado: 0,
                    delta_pendiente: 0,
                    total_consolidado_aprobado: totalAPagar,
                    cantidad_notas: 0
                };

                let notasAjusteSectionHtml = '';
                if (ca.tiene_ajustes && ca.notas.length > 0) {
                    let notasFilasHtml = '';
                    ca.notas.forEach(n => {
                        const valAj = parseFloat(n.valor_ajuste || 0);
                        const esCred = (n.tipo_nota === 'CREDITO' || valAj > 0);
                        let badgeSt = `<span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-300 dark:border-amber-800">PENDIENTE</span>`;
                        if (n.estado === 'APROBADA') {
                            badgeSt = `<span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800">APROBADA</span>`;
                        } else if (n.estado === 'ANULADA') {
                            badgeSt = `<span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-300 dark:border-rose-800">ANULADA</span>`;
                        }

                        notasFilasHtml += `
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors">
                                <td class="py-2.5 px-3 font-mono font-bold text-primary dark:text-tertiary">#${n.numero_nota || 'NA-' + n.id}</td>
                                <td class="py-2.5 px-3 font-bold text-[11px] ${esCred ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}">
                                    ${esCred ? 'CRÉDITO (+)' : 'DÉBITO (-)'}
                                </td>
                                <td class="py-2.5 px-3 text-slate-700 dark:text-slate-300 font-medium text-xs max-w-xs truncate" title="${htmlspecialchars(n.motivo_ajuste)}">
                                    ${htmlspecialchars(n.motivo_ajuste)}
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold ${esCred ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}">
                                    ${esCred ? '+' : ''}$ ${valAj.toLocaleString('es-CO')}
                                </td>
                                <td class="py-2.5 px-3 text-center">${badgeSt}</td>
                                <td class="py-2.5 px-3 text-slate-500 dark:text-slate-400 text-[10px] font-mono">${n.fecha_creacion}</td>
                                <td class="py-2.5 px-3 text-center no-print">
                                    <a href="notas_ajuste.php" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-tertiary hover:text-white dark:bg-slate-800 dark:hover:bg-tertiary text-slate-700 dark:text-slate-200 text-[10px] font-bold inline-flex items-center gap-1 transition-colors">
                                        <span class="material-symbols-outlined text-xs">open_in_new</span>
                                        <span>Ver en Módulo</span>
                                    </a>
                                </td>
                            </tr>
                        `;
                    });

                    notasAjusteSectionHtml = `
                        <div class="border-2 border-indigo-500/30 dark:border-indigo-500/20 rounded-2xl p-5 bg-gradient-to-br from-indigo-50/40 via-white to-slate-50 dark:from-slate-900 dark:via-slate-900 dark:to-slate-800/80 shadow-sm space-y-4 avoid-page-break">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-indigo-100 dark:border-slate-800">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center font-bold">
                                        <span class="material-symbols-outlined text-lg">edit_note</span>
                                    </div>
                                    <div>
                                        <h4 class="font-outfit font-black text-xs uppercase tracking-wider text-slate-900 dark:text-white">
                                            Notas de Ajustes Vinculadas (${ca.cantidad_notas})
                                        </h4>
                                        <p class="text-[10px] text-slate-400">Modificaciones y adiciones contables registradas sobre esta liquidación</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-indigo-100 dark:bg-indigo-950 text-indigo-800 dark:text-indigo-300 border border-indigo-300 dark:border-indigo-800">
                                        Liquidación con Ajustes
                                    </span>
                                </div>
                            </div>

                            <!-- Resumen Comparativo de Saldos -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-center">
                                <div class="p-3 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total Liquidación Base Original</span>
                                    <span class="font-outfit font-black text-base text-slate-800 dark:text-slate-100 mt-0.5 block">$ ${ca.total_original.toLocaleString('es-CO')}</span>
                                </div>
                                <div class="p-3 rounded-xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Ajustes Netos (${ca.cantidad_notas} Notas)</span>
                                    <span class="font-outfit font-black text-base mt-0.5 block ${ca.delta_aprobado >= 0 ? 'text-emerald-500' : 'text-rose-500'}">
                                        ${ca.delta_aprobado >= 0 ? '+ ' : ''}$ ${ca.delta_aprobado.toLocaleString('es-CO')}
                                    </span>
                                </div>
                                <div class="p-3 rounded-xl bg-teal-50 dark:bg-teal-950/60 border border-teal-300 dark:border-teal-700">
                                    <span class="text-[10px] font-black text-teal-700 dark:text-teal-400 uppercase tracking-wider block">Nuevo Saldo Consolidado Final</span>
                                    <span class="font-outfit font-black text-lg text-teal-800 dark:text-teal-300 mt-0.5 block">$ ${ca.total_consolidado_aprobado.toLocaleString('es-CO')}</span>
                                </div>
                            </div>

                            <!-- Tabla de Notas -->
                            <div class="border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden shadow-xs">
                                <table class="w-full text-left text-xs border-collapse">
                                    <thead>
                                        <tr class="bg-slate-100 dark:bg-slate-800 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase border-b border-slate-200 dark:border-slate-700">
                                            <th class="py-2 px-3">N° Nota</th>
                                            <th class="py-2 px-3">Tipo</th>
                                            <th class="py-2 px-3">Motivo / Justificación</th>
                                            <th class="py-2 px-3 text-right">Monto</th>
                                            <th class="py-2 px-3 text-center">Estado</th>
                                            <th class="py-2 px-3">Fecha</th>
                                            <th class="py-2 px-3 text-center no-print">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        ${notasFilasHtml}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                }

                let hashHtml = '';
                if (item.hash_integridad || ca.hash_consolidado) {
                    const tieneAjustes = ca.tiene_ajustes;
                    hashHtml = `
                        <div class="border border-emerald-200 dark:border-emerald-800/60 rounded-2xl p-4 bg-emerald-50/60 dark:bg-emerald-950/20 space-y-3 avoid-page-break shadow-xs">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2 text-emerald-800 dark:text-emerald-300 font-black text-xs uppercase tracking-wider">
                                    <span class="material-symbols-outlined text-base text-emerald-600 dark:text-emerald-400">verified_user</span>
                                    <span>Seguridad e Integridad Criptográfica (SHA-256)</span>
                                </div>
                                <button type="button" onclick="verificarHashIntegridad(${item.id})" class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] inline-flex items-center gap-1.5 shadow-sm transition-all cursor-pointer">
                                    <span class="material-symbols-outlined text-xs">check_circle</span>
                                    <span>Verificar Integridad</span>
                                </button>
                            </div>

                            <!-- Huella 1: Original Base -->
                            <div class="space-y-1.5">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-bold text-slate-800 dark:text-slate-200">1. Huella Digital Original (Liquidación Base #${item.id}):</span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">Intacta e Inmutable</span>
                                </div>
                                <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 font-mono text-[11px] text-slate-800 dark:text-slate-300 break-all flex items-center justify-between gap-2 shadow-xs">
                                    <span>${ca.hash_original || item.hash_integridad}</span>
                                    <button type="button" onclick="navigator.clipboard.writeText('${ca.hash_original || item.hash_integridad}'); SwalCustom.fire({icon: 'success', title: 'Copiado', text: 'Huella original copiada al portapapeles', timer: 1200, showConfirmButton: false});" class="text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white p-1" title="Copiar Hash Original">
                                        <span class="material-symbols-outlined text-sm">content_copy</span>
                                    </button>
                                </div>
                            </div>

                            ${tieneAjustes ? `
                            <!-- Huella 2: Nueva Huella Consolidada Post-Ajustes -->
                            <div class="space-y-1.5 pt-2 border-t border-emerald-200 dark:border-emerald-800/40">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="font-bold text-emerald-950 dark:text-teal-300">2. Nueva Huella Digital Consolidada (Post-Ajustes):</span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800">Certificada con ${ca.cantidad_notas} Nota(s)</span>
                                </div>
                                <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-emerald-200 dark:border-teal-800/50 font-mono text-[11px] text-emerald-900 dark:text-teal-300 break-all flex items-center justify-between gap-2 shadow-xs">
                                    <span>${ca.hash_consolidado}</span>
                                    <button type="button" onclick="navigator.clipboard.writeText('${ca.hash_consolidado}'); SwalCustom.fire({icon: 'success', title: 'Copiado', text: 'Nueva huella consolidada copiada al portapapeles', timer: 1200, showConfirmButton: false});" class="text-emerald-600 hover:text-emerald-800 dark:text-teal-400 dark:hover:text-white p-1" title="Copiar Hash Consolidado">
                                        <span class="material-symbols-outlined text-sm">content_copy</span>
                                    </button>
                                </div>
                            </div>
                            ` : ''}

                            <p class="text-[10px] text-slate-600 dark:text-slate-400 leading-relaxed font-medium">
                                ${tieneAjustes 
                                    ? 'Al registrarse notas de ajuste, el sistema genera una nueva huella digital consolidada que encadena criptográficamente el registro base con cada nota de ajuste aprobada, preservando simultáneamente la firma original inalterada.' 
                                    : 'Esta huella digital garantiza que la liquidación aprobada es 100% auténtica y no ha sufrido modificaciones.'}
                            </p>
                        </div>
                    `;
                }

                let badgeEstadoCard = '';
                if (item.estado === 'APROBADA') {
                    badgeEstadoCard = `<span class="px-3 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/90 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> APROBADA</span>`;
                } else if (item.estado === 'CANCELADA' || item.estado === 'RECHAZADA') {
                    badgeEstadoCard = `<span class="px-3 py-1 rounded-full text-xs font-black bg-rose-100 text-rose-800 dark:bg-rose-950/90 dark:text-rose-400 border border-rose-300 dark:border-rose-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-ban"></i> ${item.estado}</span>`;
                } else {
                    badgeEstadoCard = `<span class="px-3 py-1 rounded-full text-xs font-black bg-amber-100 text-amber-800 dark:bg-amber-950/90 dark:text-amber-400 border border-amber-300 dark:border-amber-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-clock"></i> PENDIENTE</span>`;
                }

                // Desglose de Producción por Médico si es Liquidación Global
                let desgloseMedicosHtml = '';
                if (item.desglose_medicos && Array.isArray(item.desglose_medicos) && item.desglose_medicos.length > 0) {
                    let medicosRowsHtml = '';
                    let totalCantDocs = 0;
                    let totalValDocs = 0;

                    item.desglose_medicos.forEach(m => {
                        totalCantDocs += parseInt(m.cantidad || 0);
                        totalValDocs += parseFloat(m.total || 0);
                    });

                    item.desglose_medicos.forEach((m, idx) => {
                        const val = parseFloat(m.total || 0);
                        const cant = parseInt(m.cantidad || 0);
                        const pct = totalValDocs > 0 ? ((val / totalValDocs) * 100).toFixed(1) : '0.0';

                        medicosRowsHtml += `
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors">
                                <td class="py-2 px-3">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-6 h-6 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 flex items-center justify-center font-bold text-xs shrink-0 font-mono">
                                            ${idx + 1}
                                        </div>
                                        <div>
                                            <div class="font-bold text-xs text-slate-900 dark:text-white font-outfit uppercase">${htmlspecialchars(m.nombre)}</div>
                                            <div class="text-[10px] font-mono text-slate-400 flex items-center gap-1.5 flex-wrap">
                                                <span>CC / ID: ${htmlspecialchars(m.cedula || 'N/A')}</span>
                                                ${m.entidad_nombre ? `<span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-teal-50 dark:bg-teal-950 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800" title="Entidad vinculada"><i class="fa-solid fa-hospital text-[8px] mr-1"></i>${htmlspecialchars(m.entidad_nombre)}</span>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2 px-3 text-center font-mono font-bold text-xs text-slate-700 dark:text-slate-300">
                                    ${cant.toLocaleString('es-CO')}
                                </td>
                                <td class="py-2 px-3 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                        ${pct}%
                                    </span>
                                </td>
                                <td class="py-2 px-3 text-right font-mono font-black text-xs text-emerald-600 dark:text-emerald-400">
                                    $ ${val.toLocaleString('es-CO')}
                                </td>
                            </tr>
                        `;
                    });

                    desgloseMedicosHtml = `
                        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden flex flex-col avoid-page-break mt-4">
                            <div class="bg-primary dark:bg-slate-800 text-white font-bold text-xs tracking-widest uppercase py-2.5 px-4 font-outfit flex items-center justify-between">
                                <span class="flex items-center gap-2">
                                    <i class="fa-solid fa-user-doctor text-tertiary"></i>
                                    <span>PRODUCCIÓN GENERADA POR CADA MÉDICO (${item.desglose_medicos.length})</span>
                                </span>
                                <span class="text-[10px] bg-teal-500/20 text-teal-300 border border-teal-500/40 px-2 py-0.5 rounded-full font-sans font-bold">
                                    Liquidación Global
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="bg-slate-100 dark:bg-slate-800/80 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-800">
                                            <th class="py-2 px-3">Médico / Especialista</th>
                                            <th class="py-2 px-3 text-center">Estudios</th>
                                            <th class="py-2 px-3 text-center">% Part.</th>
                                            <th class="py-2 px-3 text-right">Valor Generado</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        ${medicosRowsHtml}
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold bg-slate-100/80 dark:bg-slate-800/80 text-slate-900 dark:text-white">
                                            <td class="py-2.5 px-3 text-right uppercase text-[11px] font-black">TOTAL PRODUCCIÓN:</td>
                                            <td class="py-2.5 px-3 text-center font-mono font-black text-xs">${totalCantDocs.toLocaleString('es-CO')}</td>
                                            <td class="py-2.5 px-3 text-center text-xs font-bold">100%</td>
                                            <td class="py-2.5 px-3 text-right font-black text-sm text-primary dark:text-tertiary">$ ${totalValDocs.toLocaleString('es-CO')}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    `;
                }

                let bonoTomografiaBannerHtml = '';
                if (totalBonosTomografia > 0 || totalBonosTomografiaValor > 0) {
                    const sedesStr = sedesConBono.length > 0 ? sedesConBono.join(', ') : 'Principal';
                    bonoTomografiaBannerHtml = `
                        <!-- Banner Destacado de Bonificación por Tomografías Contrastadas (Tono Mate Opaco en Modo Claro) -->
                        <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-[#fbf8f1] via-[#f7f3e8] to-[#fbf8f1] dark:from-[#2a1d0d] dark:via-[#20170a] dark:to-[#16120b] border border-[#dfd5c0] dark:border-amber-500/40 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 avoid-page-break">
                            <div class="flex items-center gap-3.5">
                                <div class="w-12 h-12 rounded-2xl bg-[#ece1c7] text-[#6e501a] dark:bg-amber-500/20 dark:text-amber-400 flex items-center justify-center font-bold text-2xl shadow-inner shrink-0 border border-[#d6c39f] dark:border-amber-400/30">
                                    <span class="material-symbols-outlined text-2xl">military_tech</span>
                                </div>
                                <div class="space-y-0.5">
                                    <div class="flex items-center flex-wrap gap-2">
                                        <h4 class="font-outfit font-black text-xs sm:text-sm text-slate-900 dark:text-white uppercase tracking-wider flex items-center gap-1.5">
                                            <span>Bonificación por Tomografías Contrastadas</span>
                                        </h4>
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-[#ebdfc6] text-[#523b16] dark:bg-amber-950 dark:text-amber-200 border border-[#cfbe9b] dark:border-amber-700">
                                            Regla Institucional: 50 CT × $150.000 COP
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-slate-600 dark:text-slate-400 font-medium">
                                        Concepto de incentivo reconocido al especialista: <strong class="text-[#7d5013] dark:text-amber-300 font-bold">${totalBonosTomografia} bono(s) alcanzado(s)</strong> en sede(s) <strong class="text-slate-800 dark:text-slate-200">${htmlspecialchars(sedesStr)}</strong>.
                                    </p>
                                </div>
                            </div>
                            <div class="text-left sm:text-right shrink-0 bg-white/90 dark:bg-slate-900/70 sm:bg-transparent px-3.5 py-2 rounded-xl sm:p-0 border sm:border-0 border-[#dfd2ba] dark:border-amber-700/40">
                                <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider block">Total Incentivo Reconocido</span>
                                <span class="font-outfit font-black text-lg sm:text-xl text-[#8c5717] dark:text-amber-400">+$ ${totalBonosTomografiaValor.toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                    `;
                }

                const body = document.getElementById('modalBodyDetail');
                body.innerHTML = `
                    <!-- Formal Corporate Print Header (Visible en Impresión / PDF) -->
                    <div class="hidden print:block mb-4 pb-3 border-b-2 border-slate-900 avoid-page-break">
                        <div class="flex justify-between items-start">
                            <div>
                                <h1 class="text-base font-black text-slate-900 uppercase tracking-wide font-outfit">HERNÁN OCAZIONEZ Y CIA S.A.S.</h1>
                                <p class="text-[10px] font-semibold text-slate-700">SISTEMAS DIAGNÓSTICOS E IMÁGENES MÉDICAS | NIT: 890.980.123-4</p>
                                <p class="text-[9px] text-slate-500">LIHO - Sistema de Liquidación de Honorarios y Turnos Médicos</p>
                            </div>
                            <div class="text-right">
                                <div class="inline-block px-2.5 py-0.5 bg-slate-100 border border-slate-400 rounded text-[11px] font-black uppercase text-slate-900">
                                    LIQUIDACIÓN N° ${item.id}
                                </div>
                                <p class="text-[9px] font-mono text-slate-600 mt-0.5">FECHA EMISIÓN: ${new Date().toLocaleDateString('es-CO')}</p>
                                <p class="text-[9px] font-bold text-slate-800">ESTADO: ${item.estado}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Header Information Card -->
                    <div class="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm flex flex-col md:flex-row justify-between items-start md:items-center gap-4 avoid-page-break">
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <h2 class="text-lg font-black text-slate-900 dark:text-white font-outfit tracking-wide">LIQUIDACIÓN TURNOS</h2>
                                ${badgeEstadoCard}
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 flex flex-col sm:flex-row sm:gap-6 font-medium">
                                <p><strong class="text-slate-700 dark:text-slate-300">EMPRESA:</strong> <span class="font-bold text-slate-800 dark:text-slate-200 uppercase">${htmlspecialchars(item.entidad_nombre || 'HERNÁN OCAZIONEZ Y CÍA S.A.S.')}</span></p>
                                <p><strong class="text-slate-700 dark:text-slate-300">PERIODO:</strong> <span class="font-mono font-bold text-teal-600 dark:text-teal-400">${item.periodo_desde} AL ${item.periodo_hasta}</span></p>
                            </div>
                        </div>
                        <div class="bg-slate-50 dark:bg-slate-800/60 p-3 px-4 rounded-xl border border-slate-200 dark:border-slate-700/80 text-right">
                            <p class="text-[10px] font-bold uppercase text-slate-400 tracking-wider">DOCTOR / PROFESIONAL</p>
                            <p class="text-sm font-black text-primary dark:text-tertiary">${htmlspecialchars(item.medico_nombre)}</p>
                            <p class="text-[11px] font-mono text-slate-500 dark:text-slate-400">CÉDULA / USUARIO: ${htmlspecialchars(item.medico_cedula)}</p>
                            <p class="text-[10px] text-slate-400 mt-0.5">REGISTRADO POR: ${htmlspecialchars(item.usuario_creador_nombre || 'SISTEMA')}</p>
                        </div>
                    </div>

                    ${bonoTomografiaBannerHtml}

                    <!-- Main 2-Column Grid -->
                    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
                        
                        <!-- Left Column: Detailed Studies -->
                        <div class="xl:col-span-8 space-y-4">
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden flex flex-col avoid-page-break">
                                <div class="bg-primary dark:bg-slate-800 text-white font-bold text-xs tracking-widest text-center uppercase py-2.5 px-4 font-outfit">
                                    DETALLE DE LIQUIDACIÓN: ESTUDIOS REALIZADOS
                                </div>
                                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                    ${sedesCardsHtml}
                                </div>
                            </div>
                            ${desgloseMedicosHtml}
                        </div>

                        <!-- Right Column: Summaries & Deductions & Totals -->
                        <div class="xl:col-span-4 space-y-4 flex flex-col">
                            
                            <!-- Banner Informativo / Advertencia Registros No Cruzados -->
                            ${warningBannerHtml}

                            <!-- Resumen Administrativo por Sede -->
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden avoid-page-break">
                                <div class="bg-primary dark:bg-slate-800 text-white font-bold text-xs tracking-widest text-center uppercase py-2.5 px-4 font-outfit">
                                    ESTUDIOS POR ESTRUCTURA ADMINISTRATIVA
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left border-collapse text-xs">
                                        <thead>
                                            <tr class="bg-slate-100 dark:bg-slate-800/80 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200 dark:border-slate-800">
                                                <th class="py-2 px-3">SEDE</th>
                                                <th class="py-2 px-3 text-right">VALOR TOTAL</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-200">
                                            ${resumenSedesRowsHtml}
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t-2 border-slate-300 dark:border-slate-700 font-bold bg-slate-100/80 dark:bg-slate-800/80 text-slate-900 dark:text-white">
                                                <td class="py-2.5 px-3 text-right uppercase text-[11px] font-black">TOTAL FACTURA</td>
                                                <td class="py-2.5 px-3 text-right font-black text-sm text-primary dark:text-tertiary">$ ${totalFacturaSum.toLocaleString('es-CO')}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- Información Contable - Deducciones (Solo Lectura) -->
                            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-rose-200 dark:border-rose-900/60 shadow-sm overflow-hidden border-l-4 border-l-rose-500 flex flex-col avoid-page-break">
                                <div class="bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 py-2.5 px-4 font-bold text-xs tracking-widest text-center uppercase border-b border-rose-200 dark:border-rose-900/40 flex items-center justify-center gap-1.5 shrink-0">
                                    <i class="fa-solid fa-calculator text-sm"></i>
                                    INFORMACIÓN CONTABLE - DEDUCCIONES
                                </div>
                                
                                ${statusBadgeText}

                                <div class="p-4 space-y-3">
                                    ${deduccionesRowsHtml}

                                    <div class="pt-2 border-t border-slate-200 dark:border-slate-700 flex justify-between items-center text-xs font-bold text-rose-600 dark:text-rose-400">
                                        <span class="uppercase tracking-wider">TOTAL DEDUCCIONES</span>
                                        <span class="font-mono text-sm font-black">- $ ${totalDeducciones.toLocaleString('es-CO')}</span>
                                    </div>
                                </div>
                            </div>

                            ${novedadesCardHtml}

                            <!-- Tarjeta de Total a Pagar Grande -->
                            <div class="bg-emerald-50 dark:bg-slate-900 border-2 border-emerald-500 dark:border-emerald-600/80 p-5 rounded-2xl shadow-sm text-center flex flex-col justify-center items-center avoid-page-break">
                                <span class="text-xs font-black uppercase tracking-widest text-emerald-800 dark:text-emerald-400">TOTAL A PAGAR ===>>></span>
                                <div class="text-2xl sm:text-3xl font-black font-outfit text-emerald-700 dark:text-emerald-300 tracking-tight mt-1 font-mono">
                                    $ ${totalAPagar.toLocaleString('es-CO')}
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Exámenes Excluidos (Si Existen) -->
                    ${exclusionesSectionHtml}

                    <!-- Notas de Ajuste Vinculadas (Si Existen) -->
                    ${notasAjusteSectionHtml}

                    <!-- Hash & Logs -->
                    <div class="space-y-4 pt-2 avoid-page-break">
                        ${hashHtml}

                        <div class="border border-slate-200 dark:border-slate-800 rounded-2xl p-4 bg-white dark:bg-slate-900 shadow-sm">
                            <h5 class="font-black text-slate-900 dark:text-white uppercase tracking-wider text-xs mb-3 flex items-center gap-2">
                                <i class="fa-solid fa-clock-rotate-left text-tertiary"></i>
                                <span>Trazabilidad y Bitácora de Auditoría (Logs)</span>
                            </h5>
                            ${logsHtml}
                        </div>
                    </div>

                    <!-- Bloque Formal de Firmas Corporativas (Visible en Impresión / PDF) -->
                    <div class="hidden print:flex print-signatures-block">
                        <div class="print-signature-col">
                            <div class="print-signature-line">
                                ${htmlspecialchars(item.medico_nombre)}
                            </div>
                            <p class="text-[9px] text-slate-600 uppercase font-semibold">FIRMA DEL PROFESIONAL MÉDICO</p>
                            <p class="text-[8.5px] text-slate-500 font-mono">C.C. / ID: ${htmlspecialchars(item.medico_cedula)}</p>
                        </div>
                        <div class="print-signature-col">
                            <div class="print-signature-line">
                                HERNÁN OCAZIONEZ Y CIA S.A.S.
                            </div>
                            <p class="text-[9px] text-slate-600 uppercase font-semibold">DIRECCIÓN FINANCIERA Y CONTABILIDAD</p>
                            <p class="text-[8.5px] text-slate-500 font-mono">Revisión y Aprobación Autorizada</p>
                        </div>
                    </div>
                `;

                // Footer Actions
                const footer = document.getElementById('modalFooterActions');
                if (item.estado === 'PENDIENTE' && <?php echo $canApprove ? 'true' : 'false'; ?>) {
                    footer.innerHTML = `
                        <div class="w-full flex flex-col sm:flex-row items-center justify-between gap-3">
                            <input type="text" id="inputObservacionApprove" placeholder="Motivo u observación opcional / obligatoria para cancelación..." class="w-full sm:flex-1 px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs outline-none focus:ring-2 focus:ring-tertiary font-medium">
                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" onclick="procesarCambioEstado(${item.id}, 'CANCELADA')" class="px-4 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs flex items-center gap-1.5 cursor-pointer transition-colors shadow-sm">
                                    <i class="fa-solid fa-ban"></i>
                                    <span>Cancelar Liquidación</span>
                                </button>
                                <button type="button" onclick="procesarCambioEstado(${item.id}, 'APROBADA')" class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-black text-xs flex items-center gap-1.5 cursor-pointer transition-colors shadow-md hover:scale-105 active:scale-95">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <span>Aprobar Liquidación</span>
                                </button>
                            </div>
                        </div>
                    `;
                } else {
                    let footerBadge = '';
                    if (item.estado === 'APROBADA') {
                        footerBadge = `<span class="px-3 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/90 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700/80 inline-flex items-center gap-1.5 shadow-xs"><i class="fa-solid fa-circle-check"></i> APROBADA</span>`;
                    } else if (item.estado === 'CANCELADA' || item.estado === 'RECHAZADA') {
                        footerBadge = `<span class="px-3 py-1 rounded-full text-xs font-black bg-rose-100 text-rose-800 dark:bg-rose-950/90 dark:text-rose-400 border border-rose-300 dark:border-rose-700/80 inline-flex items-center gap-1.5 shadow-xs"><i class="fa-solid fa-ban"></i> ${item.estado}</span>`;
                    } else {
                        footerBadge = `<span class="px-3 py-1 rounded-full text-xs font-black bg-amber-100 text-amber-800 dark:bg-amber-950/90 dark:text-amber-400 border border-amber-300 dark:border-amber-700/80 inline-flex items-center gap-1.5 shadow-xs"><i class="fa-solid fa-clock"></i> PENDIENTE</span>`;
                    }

                    footer.innerHTML = `
                        <div class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                            <span>Estado actual:</span>
                            ${footerBadge}
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="confirmarReenvioCorreo(${item.id})" class="px-3.5 py-2.5 rounded-xl bg-sky-50 hover:bg-sky-100 dark:bg-sky-950/60 dark:hover:bg-sky-900/60 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800 font-bold text-xs flex items-center gap-1.5 cursor-pointer transition-colors shadow-xs" title="Reenviar expediente y comprobante por correo">
                                <i class="fa-solid fa-paper-plane text-sky-600 dark:text-sky-400"></i>
                                <span>Reenviar Correo</span>
                            </button>
                            <a href="notas_ajuste.php?crear_para_liq=${item.id}" class="px-4 py-2.5 rounded-xl bg-teal-600/10 hover:bg-teal-600/20 text-teal-600 dark:text-teal-400 font-bold text-xs flex items-center gap-1.5 cursor-pointer transition-colors border border-teal-500/20" title="Crear Nota de Ajuste a esta liquidación">
                                <span class="material-symbols-outlined text-base">note_add</span>
                                <span>Crear Nota de Ajuste</span>
                            </a>
                            <button type="button" onclick="cerrarModalDetalle()" class="px-5 py-2.5 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-bold text-xs cursor-pointer hover:bg-slate-300 dark:hover:bg-slate-700 transition-colors">
                                Cerrar
                            </button>
                        </div>
                    `;
                }

            } catch (err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error', text: 'Ocurrió un error al cargar el detalle de la liquidación.' });
            } finally {
                if (btnEl) {
                    btnEl.disabled = false;
                    btnEl.classList.remove('opacity-70', 'cursor-wait');
                    btnEl.innerHTML = origBtnHtml;
                }
                isOpeningDetail = false;
            }
        }

        let isOpeningSedeInforme = false;
        async function mostrarInformeExamenesSede(sedeKey, explicitLiqId = null) {
            if (isOpeningSedeInforme) return;
            isOpeningSedeInforme = true;

            const targetId = explicitLiqId || window.currentLiquidationId;
            const dataObj = window.sedesObjCache ? window.sedesObjCache[sedeKey] : null;
            let examenes = [];
            let totalVal = 0;

            try {
                if (dataObj && typeof dataObj === 'object' && Array.isArray(dataObj.examenes) && dataObj.examenes.length > 0) {
                    totalVal = parseFloat(dataObj.total || 0);
                    examenes = dataObj.examenes;
                } else {
                    if (dataObj && (typeof dataObj === 'number' || typeof dataObj === 'string')) {
                        totalVal = parseFloat(dataObj);
                    } else if (dataObj && typeof dataObj === 'object') {
                        totalVal = parseFloat(dataObj.total || 0);
                    }

                    try {
                        SwalCustom.fire({
                            title: 'Cargando exámenes...',
                            text: `Obteniendo registros de la sede ${sedeKey}`,
                            allowOutsideClick: false,
                            didOpen: () => { Swal.showLoading(); }
                        });
                        const resp = await fetch(`aprobacion_liquidaciones.php?action=fetch_sede_examenes&id=${targetId}&sede=${encodeURIComponent(sedeKey)}`);
                        const res = await resp.json();
                        if (res.success) {
                            examenes = res.examenes || [];
                            if (res.total) totalVal = res.total;
                        }
                        Swal.close();
                    } catch (e) {
                        Swal.close();
                        console.error(e);
                    }
                }

                // 1. Construir Resumen por Centro de Costo / Concepto Exacto
                const conceptosMap = {};
                let totalCantSede = 0;

                if (examenes.length > 0) {
                    examenes.forEach(ex => {
                        const esBoni = (ex.es_bonificacion === true || ex.cups === 'BONI_TOHO' || ex.id_ref === 'BONI_TOHO' || (ex.examen && (ex.examen.toUpperCase().includes('BONIFICACI') || ex.examen.toUpperCase().includes('BONI_TOHO'))));
                        const cName = esBoni ? 'BONIFICACIÓN TOMOGRAFÍAS' : (ex.examen || ex.cups || 'CONCEPTO').trim();
                        if (!conceptosMap[cName]) {
                            conceptosMap[cName] = { nombre: cName, cant: 0, valor: 0, noCruzados: 0, esSoloProteo: false, esBonificacion: esBoni };
                        }
                        const cantEx = parseInt(ex.cantidad) || 1;
                        conceptosMap[cName].cant += cantEx;
                        const v = parseFloat(ex.valor_a_pagar) || 0;
                        conceptosMap[cName].valor += v;
                        totalCantSede += cantEx;

                        if (!esBoni && ex.cruce !== 'CRUZADO') {
                            conceptosMap[cName].noCruzados += cantEx;
                            conceptosMap[cName].esSoloProteo = true;
                        }
                    });
                } else {
                    const cantEst = Math.round(totalVal / 5800) || 1;
                    conceptosMap['RXSI / ESTUDIOS MÉDICOS'] = {
                        nombre: 'RXSI / ESTUDIOS MÉDICOS',
                        cant: cantEst,
                        valor: totalVal,
                        noCruzados: 0,
                        esSoloProteo: false,
                        esBonificacion: false
                    };
                    totalCantSede = cantEst;
                }

                let conceptosRowsHtml = '';
                Object.values(conceptosMap).forEach(cObj => {
                    if (cObj.esBonificacion) {
                        conceptosRowsHtml += `
                            <tr class="border-b border-amber-900/40 bg-amber-950/30 hover:bg-amber-950/50">
                                <td class="py-2 px-3 font-bold text-amber-200 text-xs">
                                    <div class="flex items-center flex-wrap gap-1.5">
                                        <span class="material-symbols-outlined text-amber-400 text-sm">military_tech</span>
                                        <span class="truncate max-w-[320px]" title="${htmlspecialchars(cObj.nombre)}">${htmlspecialchars(cObj.nombre)}</span>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[8px] font-black uppercase bg-amber-900 text-amber-200 border border-amber-700">INCENTIVO (50 CT)</span>
                                    </div>
                                </td>
                                <td class="py-2 px-3 text-right font-mono font-bold text-amber-200 text-xs">${cObj.cant.toLocaleString('es-CO')} bono(s)</td>
                                <td class="py-2 px-3 text-right font-mono font-bold text-amber-400 text-xs">+$ ${cObj.valor.toLocaleString('es-CO')}</td>
                            </tr>
                        `;
                        return;
                    }

                    let badgeAdvertencia = '';
                    if (cObj.noCruzados > 0 || cObj.esSoloProteo) {
                        badgeAdvertencia = `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[9px] font-black bg-rose-950/90 text-rose-300 border border-rose-700/80 ml-2 shrink-0"><i class="fa-solid fa-circle-xmark text-[9px]"></i> No Cruzado (Solo Proteo)</span>`;
                    }

                    conceptosRowsHtml += `
                        <tr class="border-b border-slate-800 hover:bg-slate-800/40">
                            <td class="py-2 px-3 font-medium text-slate-200 text-xs">
                                <div class="flex items-center flex-wrap gap-1">
                                    <span class="truncate max-w-[320px]" title="${htmlspecialchars(cObj.nombre)}">${htmlspecialchars(cObj.nombre)}</span>
                                    ${badgeAdvertencia}
                                </div>
                            </td>
                            <td class="py-2 px-3 text-right font-mono font-bold text-slate-200 text-xs">${cObj.cant.toLocaleString('es-CO')}</td>
                            <td class="py-2 px-3 text-right font-mono font-bold text-emerald-400 text-xs">$ ${cObj.valor.toLocaleString('es-CO')}</td>
                        </tr>
                    `;
                });

                // 2. Construir Detalle por Paciente
                let pacienteRowsHtml = '';
                if (examenes.length > 0) {
                    examenes.forEach((ex) => {
                        const esBoni = (ex.es_bonificacion === true || ex.cups === 'BONI_TOHO' || ex.id_ref === 'BONI_TOHO' || (ex.examen && (ex.examen.toUpperCase().includes('BONIFICACI') || ex.examen.toUpperCase().includes('BONI_TOHO'))));

                        if (esBoni) {
                            pacienteRowsHtml += `
                                <tr class="border-b border-amber-900/40 bg-amber-950/20 hover:bg-amber-950/40 transition-colors text-left text-xs">
                                    <td class="py-2 px-3">
                                        <div class="font-bold text-amber-300 flex items-center gap-1.5">
                                            <span class="material-symbols-outlined text-sm text-amber-400">military_tech</span>
                                            <span>INCENTIVO POR PRODUCTIVIDAD</span>
                                        </div>
                                        <div class="text-[10px] font-mono text-slate-400">Regla: 50 tomografías contrastadas</div>
                                    </td>
                                    <td class="py-2 px-3">
                                        <div class="font-semibold text-amber-100 flex items-center gap-1.5 flex-wrap">
                                            <span>${htmlspecialchars(ex.examen || 'BONIFICACIÓN TOMOGRAFÍAS')}</span>
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[8px] font-black uppercase bg-amber-900 text-amber-200 border border-amber-700">
                                                INCENTIVO / BONO
                                            </span>
                                        </div>
                                        <div class="text-[10px] text-amber-400 font-mono">CUPS: BONI_TOHO</div>
                                    </td>
                                    <td class="py-2 px-3 font-mono text-[11px] text-amber-300">BONIFICACIÓN</td>
                                    <td class="py-2 px-3 text-right font-mono font-bold text-amber-400">+$ ${(parseFloat(ex.valor_a_pagar) || 0).toLocaleString('es-CO')}</td>
                                    <td class="py-2 px-3 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-amber-950 text-amber-300 border border-amber-700 inline-flex items-center gap-1">
                                            <i class="fa-solid fa-award"></i> Bonificación
                                        </span>
                                    </td>
                                </tr>
                            `;
                            return;
                        }

                        const esCruzado = (ex.cruce === 'CRUZADO');
                        const badgeCruce = esCruzado 
                            ? `<span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 inline-flex items-center gap-1"><i class="fa-solid fa-circle-check"></i> Cruzado OK</span>`
                            : `<span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-rose-950 text-rose-300 border border-rose-800 inline-flex items-center gap-1"><i class="fa-solid fa-circle-xmark"></i> No Cruzado (Solo Proteo)</span>`;

                        pacienteRowsHtml += `
                            <tr class="border-b border-slate-800 hover:bg-slate-800/50 transition-colors text-left text-xs">
                                <td class="py-2 px-3">
                                    <div class="font-bold text-slate-100">${htmlspecialchars(ex.paciente || 'PACIENTE')}</div>
                                    <div class="text-[10px] font-mono text-slate-400">ID/CC: ${htmlspecialchars(ex.documento || 'N/A')}</div>
                                </td>
                                <td class="py-2 px-3">
                                    <div class="font-semibold text-slate-200 flex items-center gap-1.5 flex-wrap">
                                        <span>${htmlspecialchars(ex.examen || ex.cups || 'EXAMEN')}</span>
                                        ${esTextoComparativo(ex.examen || ex.cups || '') ? `
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[8px] font-black uppercase bg-indigo-900/90 text-indigo-200 border border-indigo-700 shadow-xs">
                                                <i class="fa-solid fa-code-compare text-[8px]"></i> COMPARATIVO
                                            </span>
                                        ` : ''}
                                    </div>
                                    ${ex.cups ? `<div class="text-[10px] text-teal-400 font-mono">CUPS: ${htmlspecialchars(ex.cups)}</div>` : ''}
                                </td>
                                <td class="py-2 px-3 font-mono text-[11px] text-slate-300">${htmlspecialchars(ex.ingreso || 'N/A')}</td>
                                <td class="py-2 px-3 text-right font-mono font-bold text-emerald-400">$ ${(parseFloat(ex.valor_a_pagar) || 0).toLocaleString('es-CO')}</td>
                                <td class="py-2 px-3 text-center">${badgeCruce}</td>
                            </tr>
                        `;
                    });
                }

                await SwalCustom.fire({
                    title: `<i class="fa-solid fa-building text-tertiary mr-1.5"></i> Informe Desglose de Exámenes - Sede ${htmlspecialchars(sedeKey)}`,
                    width: '880px',
                    html: `
                        <div class="space-y-4 text-left my-2">
                            <div class="flex justify-between items-center p-3 rounded-xl bg-slate-800/80 border border-slate-700/80 text-xs">
                                <span class="text-slate-300 font-medium">Total Estudios Sede: <strong class="text-white font-mono text-sm">${totalCantSede.toLocaleString('es-CO')}</strong></span>
                                <span class="text-slate-300 font-medium">Total Facturado Sede: <strong class="text-tertiary font-mono text-sm">$ ${totalVal.toLocaleString('es-CO')}</strong></span>
                            </div>

                            <!-- Resumen Centro de Costo / Conceptos -->
                            <div class="rounded-xl border border-slate-800 bg-slate-950 overflow-hidden">
                                <div class="bg-slate-900/90 px-3 py-2 border-b border-slate-800 text-xs font-black text-slate-300 uppercase tracking-wider flex items-center gap-1.5">
                                    <i class="fa-solid fa-list-check text-tertiary"></i>
                                    <span>Resumen por Centro de Costo / Concepto</span>
                                </div>
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="bg-slate-900/50 text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                            <th class="py-2 px-3">CENTRO DE COSTO</th>
                                            <th class="py-2 px-3 text-right">CANTIDAD</th>
                                            <th class="py-2 px-3 text-right">VALOR TOTAL</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800 font-mono">
                                        ${conceptosRowsHtml}
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t-2 border-slate-700 font-bold bg-slate-900/80 text-slate-100">
                                            <td class="py-2 px-3 font-sans uppercase text-xs">TOTAL SEDE</td>
                                            <td class="py-2 px-3 text-right font-mono text-xs">${totalCantSede.toLocaleString('es-CO')}</td>
                                            <td class="py-2 px-3 text-right font-mono text-xs text-emerald-400 font-black">$ ${totalVal.toLocaleString('es-CO')}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            <!-- Desglose por Paciente -->
                            ${examenes.length > 0 ? `
                            <div class="rounded-xl border border-slate-800 bg-slate-950 overflow-hidden">
                                <div class="bg-slate-900/90 px-3 py-2 border-b border-slate-800 text-xs font-black text-slate-300 uppercase tracking-wider flex items-center justify-between">
                                    <div class="flex items-center gap-1.5">
                                        <i class="fa-solid fa-users text-tertiary"></i>
                                        <span>Detalle de Pacientes Atendidos (${examenes.length} exámenes)</span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 font-normal">Ordenado cronológicamente</span>
                                </div>
                                <div class="max-h-60 overflow-y-auto">
                                    <table class="w-full text-left border-collapse font-mono">
                                        <thead class="sticky top-0 bg-slate-900 text-[10px] font-bold text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                            <tr>
                                                <th class="py-2 px-3">Paciente / Cédula</th>
                                                <th class="py-2 px-3">Examen / CUPS</th>
                                                <th class="py-2 px-3">Ingreso</th>
                                                <th class="py-2 px-3 text-right">Valor A Pagar</th>
                                                <th class="py-2 px-3 text-center">Estado Cruce</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-800">
                                            ${pacienteRowsHtml}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-file-excel mr-1.5"></i> Exportar a Excel',
                    cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cerrar Informe'
                }).then((result) => {
                    if (result.isConfirmed) {
                        exportarInformeSedeExcel(sedeKey, examenes, totalVal);
                    }
                });
            } catch(e) {
                console.error(e);
            } finally {
                isOpeningSedeInforme = false;
            }
        }

        let isVerifyingHash = false;
        async function verificarHashIntegridad(id) {
            if (isVerifyingHash) return;
            isVerifyingHash = true;

            try {
                const resp = await fetch(`aprobacion_liquidaciones.php?action=verificar_hash&id=${id}`);
                const res = await resp.json();

                if (res.success && res.integridad_ok) {
                    SwalCustom.fire({
                        icon: 'success',
                        title: 'Integridad Verificada (SHA-256)',
                        html: `
                            <div class="p-4 rounded-2xl bg-emerald-950/40 border border-emerald-800/80 text-emerald-200 text-xs my-2 space-y-2 text-left">
                                <p class="font-bold text-sm text-emerald-300"><i class="fa-solid fa-shield-halved mr-1.5"></i>Registro 100% Auténtico</p>
                                <p class="text-[11px] leading-relaxed">${res.mensaje}</p>
                                <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 font-mono text-[10px] text-slate-300 break-all select-all mt-1">
                                    <strong>HUELLA DIGITAL HASH SHA-256:</strong><br>${res.hash_calculado}
                                </div>
                            </div>
                        `,
                        confirmButtonText: '<i class="fa-solid fa-check mr-1.5"></i> Entendido'
                    });
                } else {
                    SwalCustom.fire({
                        icon: 'error',
                        title: 'Alerta de Integridad',
                        text: res.error || res.mensaje || 'Discrepancia detectada en la información.'
                    });
                }
            } catch (err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error', text: 'No fue posible realizar la verificación de integridad.' });
            } finally {
                isVerifyingHash = false;
            }
        }

        let isProcessingStateChange = false;
        async function procesarCambioEstado(id, nuevoEstado) {
            if (isProcessingStateChange) return;

            let obs = (document.getElementById('inputObservacionApprove')?.value || '').trim();
            const isAprobar = (nuevoEstado === 'APROBADA');
            const isCancelar = (nuevoEstado === 'CANCELADA' || nuevoEstado === 'RECHAZADA');

            if (isCancelar && !obs) {
                const { value: reasonText, isConfirmed } = await SwalCustom.fire({
                    title: 'Motivo de Cancelación Obligatorio',
                    text: 'Para cancelar esta liquidación debes especificar obligatoriamente la razón o justificación:',
                    input: 'textarea',
                    inputPlaceholder: 'Escribe aquí la razón o motivo detallado...',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-ban mr-1.5"></i> Confirmar Cancelación',
                    cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Volver',
                    inputValidator: (value) => {
                        if (!value || !value.trim()) {
                            return '¡La razón de la cancelación es obligatoria!';
                        }
                    }
                });

                if (!isConfirmed || !reasonText || !reasonText.trim()) return;
                obs = reasonText.trim();
            } else {
                const confirmResult = await SwalCustom.fire({
                    title: isAprobar ? '¿Aprobar Liquidación?' : '¿Cancelar Liquidación?',
                    text: isAprobar ? `¿Deseas APROBAR la liquidación N° ${id}?` : `¿Deseas CANCELAR la liquidación N° ${id}?`,
                    icon: isAprobar ? 'question' : 'warning',
                    showCancelButton: true,
                    confirmButtonText: isAprobar ? '<i class="fa-solid fa-circle-check mr-1.5"></i> Sí, Aprobar' : '<i class="fa-solid fa-ban mr-1.5"></i> Sí, Cancelar',
                    cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Volver'
                });

                if (!confirmResult.isConfirmed) return;
            }

            isProcessingStateChange = true;
            SwalCustom.fire({
                title: isAprobar ? 'Aprobando Liquidación...' : 'Cancelando Liquidación...',
                text: 'Guardando cambios de estado en el servidor y firmando bitácora...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const resp = await fetch('aprobacion_liquidaciones.php?action=cambiar_estado', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, estado: nuevoEstado, observaciones: obs })
                });
                const res = await resp.json();

                if (res.success) {
                    SwalCustom.fire({
                        icon: 'success',
                        title: isAprobar ? 'Liquidación Aprobada' : 'Liquidación Cancelada',
                        html: `
                            <div class="p-4 rounded-2xl bg-teal-950/40 border border-teal-800/80 text-teal-200 text-xs my-2">
                                Liquidación <strong>N° ${id}</strong> ha sido marcada como <strong>'${nuevoEstado}'</strong> correctamente.
                            </div>
                        `,
                        confirmButtonText: '<i class="fa-solid fa-check mr-1.5"></i> Entendido'
                    });
                    cerrarModalDetalle();
                    cargarLiquidaciones();
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: res.error || 'Desconocido' });
                }
            } catch(e) {
                console.error(e);
                SwalCustom.fire({ icon: 'error', title: 'Error', text: 'Ocurrió un error al procesar el cambio de estado.' });
            } finally {
                isProcessingStateChange = false;
            }
        }

        function confirmarReenvioCorreoModal() {
            const item = window.currentLiquidationData;
            const liqId = item?.id || window.currentLiquidationId;
            if (!liqId) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin Selección', text: 'No hay ninguna liquidación activa para reenviar.' });
                return;
            }
            confirmarReenvioCorreo(liqId);
        }

        let isResendingEmail = false;
        async function confirmarReenvioCorreo(id, ev = null) {
            if (ev) {
                ev.stopPropagation();
                ev.preventDefault();
            }
            if (isResendingEmail) return;

            // 1. Mostrar loader preliminar mientras consultamos los destinatarios
            SwalCustom.fire({
                title: 'Preparando Reenvío...',
                html: `
                    <div class="py-3 flex flex-col items-center justify-center space-y-2">
                        <div class="w-10 h-10 border-4 border-sky-500 border-t-transparent rounded-full animate-spin"></div>
                        <p class="text-xs text-slate-500">Consultando destinatarios oficiales de la liquidación #${id}...</p>
                    </div>
                `,
                showConfirmButton: false,
                allowOutsideClick: false
            });

            try {
                const respDest = await fetch(`aprobacion_liquidaciones.php?action=obtener_destinatarios_reenvio&id=${id}`);
                const dataDest = await respDest.json();

                if (!dataDest.success) {
                    SwalCustom.fire({
                        icon: 'error',
                        title: 'Error al consultar destinatarios',
                        text: dataDest.error || 'No se pudo obtener la información de los destinatarios.'
                    });
                    return;
                }

                const d = dataDest.data;
                const med = d.medico || {};
                const dir = d.dir_medica || {};
                const cre = d.creador || {};

                // Construcción de la vista previa de destinatarios
                const confirmHtml = `
                    <div class="text-left space-y-3 my-2">
                        <div class="p-3 rounded-2xl bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-800 text-xs">
                            <div class="flex items-center justify-between pb-2 border-b border-sky-200 dark:border-sky-800/80 mb-2">
                                <span class="font-bold text-sky-900 dark:text-sky-200 flex items-center gap-1.5">
                                    <i class="fa-solid fa-file-invoice-dollar text-sky-600"></i>
                                    <span>Liquidación #${id}</span>
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200 uppercase font-mono">
                                    ${htmlspecialchars(d.estado || 'APROBADA')}
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-600 dark:text-slate-300 space-y-0.5">
                                <div><strong class="text-slate-700 dark:text-slate-200">Período:</strong> ${htmlspecialchars(d.periodo || '')}</div>
                                <div><strong class="text-slate-700 dark:text-slate-200">Total a Liquidar:</strong> <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">$ ${(parseFloat(d.total_a_pagar) || 0).toLocaleString('es-CO')} COP</span></div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <span class="text-[11px] font-black text-slate-700 dark:text-slate-300 uppercase tracking-wider block">
                                <i class="fa-solid fa-users text-sky-500 mr-1"></i> Destinatarios que recibirán el reenvío:
                            </span>

                            <!-- 1. Dirección Médica -->
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 flex items-start gap-2.5">
                                <div class="w-7 h-7 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 flex items-center justify-center font-bold shrink-0 mt-0.5">
                                    <i class="fa-solid fa-user-doctor text-xs"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200">Dirección Médica y Coordinación</span>
                                        <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-300">Copia Oficial</span>
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-500 dark:text-slate-400 truncate">
                                        coordinacionsistemas@hernanocazionez.com.co, dirasistencial@hernanocazionez.com
                                    </div>
                                </div>
                            </div>

                            <!-- 2. Médico Titular -->
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 flex items-start gap-2.5">
                                <div class="w-7 h-7 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold shrink-0 mt-0.5">
                                    <i class="fa-solid fa-stethoscope text-xs"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate">${htmlspecialchars(med.nombre || 'Profesional Médico')}</span>
                                        <span class="text-[9px] font-bold px-1.5 py-0.2 rounded ${d.modo_pruebas ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' : 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300'} shrink-0">${d.modo_pruebas ? 'Prueba Redirigida' : 'Médico Titular'}</span>
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-600 dark:text-slate-300 truncate font-semibold">
                                        ${htmlspecialchars(med.email || 'juane6462@gmail.com')}
                                    </div>
                                    <div class="text-[10px] text-slate-400 flex items-center gap-2">
                                        <span>CC/ID: ${htmlspecialchars(med.cedula || 'N/A')}</span>
                                        ${d.modo_pruebas ? '<span class="text-amber-600 dark:text-amber-400 font-bold">• Modo Desarrollo / Pruebas Activo</span>' : ''}
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Quien Creó la Liquidación -->
                            <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 flex items-start gap-2.5">
                                <div class="w-7 h-7 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold shrink-0 mt-0.5">
                                    <i class="fa-solid fa-user-pen text-xs"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-1">
                                        <span class="text-xs font-bold text-slate-800 dark:text-slate-200 truncate">${htmlspecialchars(cre.nombre || 'Usuario Creador')}</span>
                                        <span class="text-[9px] font-bold px-1.5 py-0.2 rounded bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300 shrink-0">Creador</span>
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-500 dark:text-slate-400 truncate">
                                        ${htmlspecialchars(cre.email || 'contabilidad2@hernanocazionez.com')}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Adjuntos automáticos -->
                        <div class="p-2.5 rounded-xl bg-slate-100/70 dark:bg-slate-800/60 text-[11px] text-slate-600 dark:text-slate-400 space-y-1">
                            <div class="flex items-center gap-1.5 text-slate-700 dark:text-slate-300 font-semibold">
                                <i class="fa-solid fa-paperclip text-slate-500"></i>
                                <span>Archivos oficiales adjuntos en el reenvío:</span>
                            </div>
                            <div class="flex items-center gap-2 pl-4 text-[10.5px]">
                                <span class="text-rose-600 dark:text-rose-400 font-bold"><i class="fa-solid fa-file-pdf mr-1"></i>Reporte Oficial PDF</span>
                                <span>•</span>
                                <span class="text-emerald-600 dark:text-emerald-400 font-bold"><i class="fa-solid fa-file-excel mr-1"></i>Desglose Sede Excel</span>
                            </div>
                        </div>

                        <!-- Aviso obligatorio de Logs y Auditoría -->
                        <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 text-[10.5px] text-amber-800 dark:text-amber-300 flex items-start gap-2">
                            <i class="fa-solid fa-shield-halved text-amber-600 text-sm mt-0.5 shrink-0"></i>
                            <div>
                                <strong>Trazabilidad y Bitácora de Auditoría (Logs):</strong>
                                <p class="text-[10px] text-amber-700 dark:text-amber-400 mt-0.5">
                                    Este reenvío quedará registrado formal e inmutablemente en la base de datos de auditoría (<code>sistema_auditoria_logs</code> y <code>logs_sistema</code>) con estampilla de tiempo, emisor y detalle de entrega.
                                </p>
                            </div>
                        </div>
                    </div>
                `;

                const confirmResult = await SwalCustom.fire({
                    title: '¿Reenviar Liquidación por Correo?',
                    html: confirmHtml,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fa-solid fa-paper-plane mr-1.5"></i> Sí, Reenviar Ahora',
                    cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cancelar',
                    focusConfirm: false
                });

                if (!confirmResult.isConfirmed) return;

                // Ejecución del reenvío
                isResendingEmail = true;
                SwalCustom.fire({
                    title: 'Enviando Correos...',
                    html: `
                        <div class="py-4 space-y-3 text-center">
                            <div class="w-12 h-12 border-4 border-sky-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
                            <p class="text-xs text-slate-600 dark:text-slate-300 font-medium">Generando PDF oficial, Excel detallado y enviando por SMTP...</p>
                            <p class="text-[10.5px] text-slate-400">Registrando traza inmutable en bitácora de auditoría...</p>
                        </div>
                    `,
                    showConfirmButton: false,
                    allowOutsideClick: false
                });

                const respSend = await fetch('aprobacion_liquidaciones.php?action=reenviar_correo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });

                const resSend = await respSend.json();

                if (resSend.success) {
                    const destCount = (resSend.todos_destinatarios || []).length;
                    const destListStr = (resSend.todos_destinatarios || []).map(e => `• ${htmlspecialchars(e)}`).join('<br>');

                    SwalCustom.fire({
                        icon: 'success',
                        title: '¡Liquidación Reenviada con Éxito!',
                        html: `
                            <div class="text-left space-y-2.5 my-2">
                                <div class="p-3 rounded-2xl bg-teal-950/40 border border-teal-800/80 text-teal-200 text-xs">
                                    La liquidación <strong>#${id}</strong> ha sido remitida formalmente por correo electrónico.
                                </div>
                                <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-[11px]">
                                    <strong class="text-slate-700 dark:text-slate-200 block mb-1">Destinatarios que recibieron el correo (${destCount}):</strong>
                                    <div class="font-mono text-[10.5px] text-slate-600 dark:text-slate-300">
                                        ${destListStr}
                                    </div>
                                </div>
                                <div class="text-[10px] text-slate-400 flex items-center gap-1.5">
                                    <i class="fa-solid fa-clock-rotate-left text-teal-500"></i>
                                    <span>Acción guardada en logs_sistema y reflejada en la bitácora de auditoría.</span>
                                </div>
                            </div>
                        `,
                        confirmButtonText: '<i class="fa-solid fa-check mr-1.5"></i> Entendido'
                    });

                    // Si el modal de detalle de esta misma liquidación está abierto, recargar su vista para ver el nuevo log de inmediato
                    if (window.currentLiquidationId == id && !document.getElementById('modalDetalleLiq').classList.contains('hidden')) {
                        verDetalleLiquidacion(id);
                    }
                } else {
                    SwalCustom.fire({
                        icon: 'error',
                        title: 'Error al Reenviar',
                        text: resSend.error || resSend.mensaje || 'No fue posible completar el envío por correo.'
                    });
                }
            } catch (err) {
                console.error(err);
                SwalCustom.fire({
                    icon: 'error',
                    title: 'Error Inesperado',
                    text: 'Ocurrió un error al procesar la solicitud de reenvío de correo.'
                });
            } finally {
                isResendingEmail = false;
            }
        }

        function abrirModalDetalle() {
            const modal = document.getElementById('modalDetalleLiq');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0', 'pointer-events-none');
                modal.firstElementChild.classList.remove('scale-95');
                modal.firstElementChild.classList.add('scale-100');
            }, 10);
        }

        function cerrarModalDetalle() {
            const modal = document.getElementById('modalDetalleLiq');
            modal.firstElementChild.classList.remove('scale-100');
            modal.firstElementChild.classList.add('scale-95');
            modal.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        function htmlspecialchars(str) {
            if (typeof str !== 'string') return str;
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function esTextoComparativo(texto) {
            if (!texto) return false;
            if (/\bcomparativ[a-záéíóúñ\/]*/i.test(texto)) return true;
            const tieneProyeccion = /\b(a\s*\.?\s*p\.?|p\s*\.?\s*a\.?)\b/i.test(texto) || /a\.p/i.test(texto) || /p\.a/i.test(texto);
            const tieneLateral    = /\blateral(?:es)?\b/i.test(texto) || /\blat\b/i.test(texto);
            if (tieneProyeccion && tieneLateral) return true;
            return false;
        }
        function exportarInformeSedeExcel(sedeKey, examenesList = [], totalMontoSede = 0) {
            if (!examenesList || examenesList.length === 0) {
                SwalCustom.fire({ icon: 'warning', title: 'Sin Datos', text: 'No hay datos disponibles para exportar.' });
                return;
            }

            const liqId = window.currentLiquidationId || 'N/A';
            let csvContent = "\uFEFF";
            csvContent += `INFORME DESGLOSE DE EXÁMENES - SEDE ${sedeKey.toUpperCase()}\n`;
            csvContent += `Liquidación N°;#${liqId}\n`;
            csvContent += `Fecha Exportación;${new Date().toLocaleString('es-CO')}\n\n`;

            // 1. Resumen por Centro de Costo
            csvContent += "RESUMEN POR CENTRO DE COSTO / CONCEPTO\n";
            csvContent += "CENTRO DE COSTO;CANTIDAD;VALOR TOTAL;ESTADO CRUCE\n";

            const conceptosMap = {};
            let totalCant = 0;
            let totalVal  = 0;

            examenesList.forEach(ex => {
                const cName = (ex.examen || ex.cups || 'CONCEPTO').trim();
                if (!conceptosMap[cName]) {
                    conceptosMap[cName] = { nombre: cName, cant: 0, valor: 0, noCruzados: 0 };
                }
                conceptosMap[cName].cant++;
                const v = parseFloat(ex.valor_a_pagar) || 0;
                conceptosMap[cName].valor += v;
                totalCant++;
                totalVal += v;
                if (ex.cruce !== 'CRUZADO') conceptosMap[cName].noCruzados++;
            });

            Object.values(conceptosMap).forEach(cObj => {
                const stCruce = cObj.noCruzados > 0 ? "NO CRUZADO (SOLO PROTEO)" : "CRUZADO OK";
                csvContent += `"${cObj.nombre.replace(/"/g, '""')}";${cObj.cant};${cObj.valor.toFixed(2)};"${stCruce}"\n`;
            });
            csvContent += `TOTAL SEDE;${totalCant};${totalVal.toFixed(2)};\n\n`;

            // 2. Detalle por Paciente
            csvContent += "DETALLE INDIVIDUAL DE PACIENTES Y ESTUDIOS\n";
            csvContent += "PACIENTE;CÉDULA / DOCUMENTO;EXAMEN / CONCEPTO;CUPS;N° INGRESO;VALOR A PAGAR ($);ESTADO CRUCE\n";

            examenesList.forEach(ex => {
                const paciente = (ex.paciente || 'N/A').replace(/"/g, '""');
                const doc      = (ex.documento || 'N/A').replace(/"/g, '""');
                const examen   = (ex.examen || 'N/A').replace(/"/g, '""');
                const cups     = (ex.cups || 'N/A').replace(/"/g, '""');
                const ingreso  = (ex.ingreso || 'N/A').replace(/"/g, '""');
                const valor    = parseFloat(ex.valor_a_pagar) || 0;
                const cruce    = ex.cruce === 'CRUZADO' ? 'CRUZADO OK' : 'NO CRUZADO (SOLO PROTEO)';

                csvContent += `"${paciente}";"${doc}";"${examen}";"${cups}";"${ingreso}";${valor.toFixed(2)};"${cruce}"\n`;
            });

            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("download", `Informe_Sede_${sedeKey}_Liq_${liqId}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>

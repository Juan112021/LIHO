<?php
/**
 * Módulo de Notas de Ajuste de Liquidaciones - LIHO
 * Permite registrar y gestionar notas crédito/débito y reajustes a liquidaciones
 * previamente creadas sin alterar o sobreescribir el registro original (inmutabilidad).
 */
date_default_timezone_set('America/Bogota');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/includes/liquidaciones_helper.php';
require_once __DIR__ . '/includes/permisos_helper.php';

// Asegurar tablas de BD
asegurarTablasLiquidaciones();

// Validación de Acceso
if (!isset($_SESSION['user_id'])) {
    if (!empty($_GET['action']) || !empty($_POST['action'])) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'error' => 'Sesión expirada. Inicie sesión nuevamente.'));
        exit;
    }
    header("Location: index.php");
    exit;
}

$userRole = strtoupper($_SESSION['user_role'] ?? 'SIN ROL');
$userRoleId = intval($_SESSION['user_role_id'] ?? ($userRole === 'ADMINISTRADOR' ? 1 : ($userRole === 'FINANCIERO' ? 2 : 0)));
$userId   = intval($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? 'Usuario';

$hasAccess = ($userRoleId === 1 || $userRoleId === 2 || in_array($userRole, ['ADMINISTRADOR', 'FINANCIERA', 'FINANCIERO']) || tienePermisoModulo($userId, $userRoleId, 'notas_ajuste'));
if (!$hasAccess) {
    header("Location: dashboard.php");
    exit;
}

$canApprove = ($userRoleId === 1 || in_array($userRole, ['ADMINISTRADOR', 'FINANCIERA', 'FINANCIERO']));

// Router de Acciones AJAX (API)
$jsonPayload = null;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody)) {
    $jsonPayload = json_decode($rawBody, true);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? ($jsonPayload['action'] ?? ''));

if ($action === 'listar_notas') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $filtroMes = trim($_GET['periodo'] ?? '');
    $medico    = trim($_GET['medico'] ?? '');
    $estado    = strtoupper(trim($_GET['estado'] ?? ''));

    $notas = obtenerNotasAjusteBD($filtroMes, $medico, $estado);

    // Calcular KPIs
    $totalNotas = count($notas);
    $totalCreditos = 0;
    $totalDebitos = 0;
    $balanceNeto = 0;

    foreach ($notas as $n) {
        $val = floatval($n['valor_ajuste'] ?? 0);
        if ($n['tipo_nota'] === 'CREDITO' || $val > 0) {
            $totalCreditos += abs($val);
        } else {
            $totalDebitos += abs($val);
        }
        $balanceNeto += $val;
    }

    echo json_encode(array(
        'success' => true,
        'data' => $notas,
        'kpis' => array(
            'total_notas' => $totalNotas,
            'total_creditos' => $totalCreditos,
            'total_debitos' => $totalDebitos,
            'balance_neto' => $balanceNeto
        )
    ));
    exit;
}

if ($action === 'ver_detalle_nota') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    $nota = obtenerNotaAjustePorIdBD($id);
    if ($nota) {
        echo json_encode(array('success' => true, 'data' => $nota));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Nota de ajuste no encontrada.'));
    }
    exit;
}

if ($action === 'obtener_liquidaciones_select') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $periodo = trim($_GET['periodo'] ?? '');
    $liquidaciones = obtenerLiquidacionesParaSelectBD($periodo);
    echo json_encode(array('success' => true, 'data' => $liquidaciones));
    exit;
}

if ($action === 'obtener_detalle_liquidacion_base') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    $liq = obtenerLiquidacionPorIdBD($id, false);
    if ($liq) {
        echo json_encode(array('success' => true, 'data' => $liq));
    } else {
        echo json_encode(array('success' => false, 'error' => 'Liquidación base no encontrada.'));
    }
    exit;
}

if ($action === 'obtener_catalogo_cups') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $entidadId = !empty($_GET['entidad_id']) ? intval($_GET['entidad_id']) : null;
    $catalogo = obtenerCatalogoCupsBD($entidadId);
    echo json_encode(array('success' => true, 'data' => $catalogo));
    exit;
}

if ($action === 'obtener_novedades_entidad') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $entidadId = $_GET['entidad_id'] ?? null;
    $novedades = obtenerNovedadesEntidadBD($entidadId, true);
    echo json_encode(array('success' => true, 'data' => $novedades));
    exit;
}

if ($action === 'registrar_log_toggle_novedades') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $refId  = $_GET['referencia_id'] ?? '0';
    $entId  = $_GET['entidad_id'] ?? 'PROPIO';
    $medico = $_GET['medico'] ?? 'MÉDICO';
    $activo = isset($_GET['activo']) ? ($_GET['activo'] === '1' || $_GET['activo'] === 'true') : true;

    registrarLogToggleNovedades('NOTA_AJUSTE', $refId, $entId, $medico, $userId, $userName, $userRole, $activo);
    echo json_encode(array('success' => true));
    exit;
}

if ($action === 'guardar_nota') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $inputData = $jsonPayload ?: ($_POST ?: array());

    $res = guardarNotaAjusteBD($inputData, $userId, $userName, $userRole);
    echo json_encode($res);
    exit;
}

if ($action === 'cambiar_estado_nota') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    if (!$canApprove) {
        echo json_encode(array('success' => false, 'error' => 'No tienes permisos para aprobar o anular notas de ajuste.'));
        exit;
    }

    $inputData = $jsonPayload ?: ($_POST ?: array());

    $id            = intval($inputData['id'] ?? 0);
    $nuevoEstado   = strtoupper(trim($inputData['estado'] ?? ''));
    $observaciones = trim($inputData['observaciones'] ?? '');

    if ($id <= 0 || !in_array($nuevoEstado, array('APROBADA', 'ANULADA'))) {
        echo json_encode(array('success' => false, 'error' => 'Parámetros inválidos.'));
        exit;
    }

    $res = cambiarEstadoNotaAjusteBD($id, $nuevoEstado, $userId, $userName, $userRole, $observaciones);
    echo json_encode($res);
    exit;
}

if ($action === 'verificar_hash_nota') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_GET['id'] ?? 0);
    $res = verificarIntegridadNotaAjusteBD($id);
    echo json_encode($res);
    exit;
}

// Preselección de liquidación si viene por GET
$preselectedLiqId = intval($_GET['crear_para_liq'] ?? 0);
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Notas de Ajustes | LIHO - Liquidación Médica IPS</title>

    <!-- Tailwind & Google Fonts -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&family=Outfit:wght@300..900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
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
                        background: "#f8fafc",
                        "on-surface": "#1a1c1c"
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

        @keyframes cardEntry {
            0% { opacity: 0; transform: translateY(16px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .animate-card-entry {
            animation: cardEntry 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        /* Estilos de Impresión Corporativa */
        @media print {
            body { background: white !important; color: black !important; }
            header, nav, #openDrawerBtn, #periodoNavToolbar, #mainFiltersBar, #mainKpiCards, #mainNotasTableWrapper, #crearNotaBtnTop, .no-print { display: none !important; }
            #modalDetalleNota { position: static !important; display: block !important; background: transparent !important; padding: 0 !important; }
            #modalContainerDetail { max-width: 100% !important; box-shadow: none !important; border: none !important; margin: 0 !important; }
            .avoid-page-break { page-break-inside: avoid; }
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header Navigation -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Content -->
    <main class="flex-grow max-w-[1480px] w-full mx-auto px-4 sm:px-6 py-6 animate-card-entry">

        <!-- 1. Encabezado del Módulo con Botón de Creación -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-500/10 text-teal-700 dark:text-teal-300 text-[11px] font-black uppercase tracking-wider mb-2 border border-teal-500/20">
                    <span class="material-symbols-outlined text-sm">note_alt</span>
                    <span>Gestión y Control Contable</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black font-outfit text-primary dark:text-white tracking-tight">Notas de Ajustes de Liquidación</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mt-0.5">
                    Registre créditos, débitos y reajustes a liquidaciones emitidas con preservación inmutable del registro original.
                </p>
            </div>

            <button type="button" id="crearNotaBtnTop" onclick="abrirModalCrearNota()" class="inline-flex items-center gap-2 bg-gradient-to-r from-primary to-[#184568] hover:from-[#1b4363] hover:to-primary text-white font-bold text-xs px-5 py-3 rounded-2xl shadow-md shadow-primary/20 transition-all hover:scale-[1.02] active:scale-95 cursor-pointer shrink-0 border border-white/10">
                <span class="material-symbols-outlined text-lg text-tertiary">add_circle</span>
                <span>Crear Nota de Ajuste</span>
            </button>
        </div>

        <!-- 2. Barra de Navegación Ejecutiva de Periodo (Toolbar) -->
        <div id="periodoNavToolbar" class="bg-white dark:bg-slate-900 rounded-3xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs mb-6 relative">
            <div class="flex flex-col lg:flex-row items-center justify-between gap-4">
                
                <!-- Selector Central de Mes con Popover Interactivo -->
                <div class="flex items-center gap-2 sm:gap-3 w-full lg:w-auto justify-between lg:justify-start">
                    <button type="button" onclick="navegarMesRelativo(-1)" title="Mes Anterior" class="p-2.5 rounded-2xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-all cursor-pointer flex items-center justify-center shadow-xs">
                        <span class="material-symbols-outlined text-base">chevron_left</span>
                    </button>

                    <div class="relative">
                        <button type="button" id="btnPeriodoSelector" onclick="togglePopoverMeses()" class="px-4 py-2.5 rounded-2xl bg-primary dark:bg-slate-800 text-white font-outfit font-black text-xs sm:text-sm tracking-wide shadow-md shadow-primary/20 flex items-center gap-2.5 transition-all cursor-pointer border border-white/10 hover:brightness-110">
                            <span class="material-symbols-outlined text-tertiary text-lg">calendar_month</span>
                            <span id="labelPeriodoActual">Cargando Periodo...</span>
                            <span class="material-symbols-outlined text-xs text-slate-300 transition-transform duration-200" id="chevronPeriodo">expand_more</span>
                        </button>

                        <!-- Popover Grid de 12 Meses -->
                        <div id="popoverMesesGrid" class="hidden absolute left-0 sm:left-1/2 sm:-translate-x-1/2 top-full mt-2 w-72 bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-200 dark:border-slate-800 p-4 z-50 animate__animated animate__fadeIn animate__faster">
                            <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-100 dark:border-slate-800">
                                <button type="button" onclick="cambiarAnioSelector(-1)" class="p-1 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300">
                                    <span class="material-symbols-outlined text-sm">chevron_left</span>
                                </button>
                                <span id="labelAnioSelector" class="font-outfit font-black text-sm text-primary dark:text-white">2026</span>
                                <button type="button" onclick="cambiarAnioSelector(1)" class="p-1 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300">
                                    <span class="material-symbols-outlined text-sm">chevron_right</span>
                                </button>
                            </div>
                            <div class="grid grid-cols-3 gap-2" id="gridMesesButtons"></div>
                        </div>
                    </div>

                    <button type="button" onclick="navegarMesRelativo(1)" title="Mes Siguiente" class="p-2.5 rounded-2xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-all cursor-pointer flex items-center justify-center shadow-xs">
                        <span class="material-symbols-outlined text-base">chevron_right</span>
                    </button>
                </div>

                <!-- Pastillas de Acceso Rápido al Periodo -->
                <div class="flex items-center gap-1.5 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-2xl overflow-x-auto max-w-full">
                    <button type="button" onclick="setPeriodoMes('CURRENT')" id="pillMesActual" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all whitespace-nowrap cursor-pointer bg-white dark:bg-slate-700 text-primary dark:text-white shadow-xs inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-tertiary">bolt</span>
                        <span>Mes Actual</span>
                    </button>
                    <button type="button" onclick="setPeriodoMes('PREVIOUS')" id="pillMesAnterior" class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-white transition-all whitespace-nowrap cursor-pointer inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-slate-400">history</span>
                        <span>Mes Anterior</span>
                    </button>
                    <button type="button" onclick="setPeriodoMes('ALL')" id="pillTodoHistorico" class="px-3 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-white transition-all whitespace-nowrap cursor-pointer inline-flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-slate-400">public</span>
                        <span>Todo el Histórico</span>
                    </button>
                </div>

            </div>
        </div>

        <!-- 3. Tarjetas KPI de Notas de Ajuste -->
        <div id="mainKpiCards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            
            <!-- Total Notas -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-teal-500/10 dark:bg-slate-800 text-tertiary flex items-center justify-center font-bold shrink-0">
                    <span class="material-symbols-outlined text-2xl">note_alt</span>
                </div>
                <div>
                    <p class="text-2xl font-black font-outfit text-primary dark:text-white" id="kpiTotalNotas">0</p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">Notas de Ajuste</p>
                </div>
            </div>

            <!-- Ajustes Crédito (A Favor) -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold shrink-0">
                    <span class="material-symbols-outlined text-2xl">add_circle</span>
                </div>
                <div>
                    <p class="text-xl sm:text-2xl font-black font-outfit text-emerald-600 dark:text-emerald-400" id="kpiTotalCreditos">$ 0</p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">A Favor Médicos (+)</p>
                </div>
            </div>

            <!-- Ajustes Débito (Descuentos) -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-rose-500/10 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 flex items-center justify-center font-bold shrink-0">
                    <span class="material-symbols-outlined text-2xl">remove_circle</span>
                </div>
                <div>
                    <p class="text-xl sm:text-2xl font-black font-outfit text-rose-600 dark:text-rose-400" id="kpiTotalDebitos">$ 0</p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">Descuentos IPS (-)</p>
                </div>
            </div>

            <!-- Balance Neto Consolidado -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-primary/10 dark:bg-slate-800 text-primary dark:text-tertiary flex items-center justify-center font-bold shrink-0">
                    <span class="material-symbols-outlined text-2xl">balance</span>
                </div>
                <div>
                    <p class="text-xl sm:text-2xl font-black font-outfit text-primary dark:text-tertiary" id="kpiBalanceNeto">$ 0</p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">Balance Neto Ajustado</p>
                </div>
            </div>

        </div>

        <!-- 4. Filtros Locales de la Tabla (Buscador y Estados) -->
        <div id="mainFiltersBar" class="bg-white dark:bg-slate-900 rounded-3xl p-4 sm:p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs mb-6">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                
                <!-- Buscador en tiempo real -->
                <div class="relative w-full md:w-80">
                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                    <input type="text" id="searchInput" placeholder="Buscar por médico, cédula o N° nota..." class="w-full pl-10 pr-4 py-2.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl text-xs font-semibold text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:ring-2 focus:ring-tertiary outline-none transition-all" />
                </div>

                <!-- Pestañas de Estado -->
                <div class="flex items-center gap-1.5 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-2xl overflow-x-auto w-full md:w-auto justify-start md:justify-end">
                    <button type="button" onclick="filtrarPorEstado('TODOS')" id="tabEstadoTODOS" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all whitespace-nowrap cursor-pointer bg-white dark:bg-slate-700 text-primary dark:text-white shadow-xs">
                        Todos
                    </button>
                    <button type="button" onclick="filtrarPorEstado('PENDIENTE')" id="tabEstadoPENDIENTE" class="px-3.5 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-amber-500 transition-all whitespace-nowrap cursor-pointer inline-flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                        <span>Pendientes</span>
                    </button>
                    <button type="button" onclick="filtrarPorEstado('APROBADA')" id="tabEstadoAPROBADA" class="px-3.5 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-emerald-500 transition-all whitespace-nowrap cursor-pointer inline-flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span>Aprobadas</span>
                    </button>
                    <button type="button" onclick="filtrarPorEstado('ANULADA')" id="tabEstadoANULADA" class="px-3.5 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-rose-500 transition-all whitespace-nowrap cursor-pointer inline-flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                        <span>Anuladas</span>
                    </button>
                </div>

            </div>
        </div>

        <!-- 5. Tabla Principal de Notas de Ajuste -->
        <div id="mainNotasTableWrapper" class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-slate-100/80 dark:bg-slate-800/80 text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-wider border-b border-slate-200/80 dark:border-slate-800">
                            <th class="py-3.5 px-4">N° NOTA</th>
                            <th class="py-3.5 px-4">LIQ. BASE</th>
                            <th class="py-3.5 px-4">MÉDICO / ESPECIALISTA</th>
                            <th class="py-3.5 px-4 text-center">TIPO</th>
                            <th class="py-3.5 px-4 text-right">VALOR AJUSTE</th>
                            <th class="py-3.5 px-4 text-right">TOTAL CONSOLIDADO</th>
                            <th class="py-3.5 px-4">FECHA REGISTRO</th>
                            <th class="py-3.5 px-4 text-center">ESTADO</th>
                            <th class="py-3.5 px-4 text-right">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody id="notasTableBody" class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr>
                            <td colspan="9" class="py-12 text-center text-slate-400">
                                <span class="material-symbols-outlined text-3xl animate-spin block mb-2 text-tertiary">sync</span>
                                <p class="font-bold text-xs">Cargando notas de ajuste...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- ========================================================================= -->
    <!-- MODAL 1: Crear Nueva Nota de Ajuste -->
    <!-- ========================================================================= -->
    <div id="modalCrearNota" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-950/70 backdrop-blur-md">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-5xl xl:max-w-6xl w-full shadow-2xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[92vh] overflow-hidden transform transition-all animate__animated animate__fadeInDown animate__faster">
            
            <!-- Header Modal Fijo -->
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-white dark:bg-slate-900 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-tertiary/10 text-tertiary flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">note_add</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black font-outfit text-primary dark:text-white">Nueva Nota de Ajuste</h3>
                        <p class="text-xs text-slate-400 font-medium">Asocie el ajuste a una liquidación previa sin modificar su registro base</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalCrearNota()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Body con Scroll Interno -->
            <form id="formCrearNota" onsubmit="guardarNuevaNotaAjuste(event)" class="flex flex-col flex-1 overflow-hidden">
                <div class="p-6 sm:p-8 overflow-y-auto space-y-5 flex-1 bg-slate-50/50 dark:bg-slate-950/30">
                    
                    <!-- Paso 1: Selección de Liquidación Base Aprobada -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                            1. Seleccionar Liquidación Aprobada a Ajustar <span class="text-rose-500">*</span>
                        </label>
                        <select id="selectLiquidacionBase" onchange="alCambiarLiquidacionBase()" required class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-3 text-xs font-semibold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-tertiary outline-none">
                            <option value="">-- Seleccione una liquidación aprobada --</option>
                        </select>
                    </div>

                    <!-- Tarjeta Snapshot Liquidación Original (Solo Lectura) -->
                    <div id="snapshotLiqOriginal" class="hidden p-4 rounded-2xl bg-white dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-tertiary text-base">receipt_long</span>
                                <span class="font-outfit font-black text-xs text-primary dark:text-white" id="snapLiqTitulo">Liquidación #</span>
                            </div>
                            <span id="snapLiqEstado" class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800">APROBADA</span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                            <div>
                                <span class="text-[10px] font-bold text-slate-400 block uppercase">Médico:</span>
                                <strong class="text-slate-800 dark:text-slate-200" id="snapLiqMedico">-</strong>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold text-slate-400 block uppercase">Periodo:</span>
                                <span class="text-slate-700 dark:text-slate-300 font-mono" id="snapLiqPeriodo">-</span>
                            </div>
                            <div>
                                <span class="text-[10px] font-bold text-slate-400 block uppercase">Total Liquidado Original:</span>
                                <strong class="text-primary dark:text-tertiary font-mono text-sm" id="snapLiqTotal">$ 0</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Paso 2: Tipo de Nota y Configuración General -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                                2. Tipo de Nota de Ajuste <span class="text-rose-500">*</span>
                            </label>
                            <select id="inputTipoNota" onchange="recalcularTotalesAjuste()" required class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-3 text-xs font-bold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-tertiary outline-none">
                                <option value="CREDITO">NOTA CRÉDITO (A Favor del Médico / Adición)</option>
                                <option value="DEBITO">NOTA DÉBITO (Descuento IPS / Devolución)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5 uppercase tracking-wider">
                                3. Motivo / Justificación Auditoría <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" id="inputMotivoAjuste" required placeholder="Ej: Reconocimiento de ecografías omitidas en el cruce" class="w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl p-3 text-xs font-semibold text-slate-800 dark:text-slate-200 focus:ring-2 focus:ring-tertiary outline-none" />
                        </div>
                    </div>

                    <!-- Paso 3: Desglose Dinámico de Ítems / Conceptos del Ajuste -->
                    <div class="space-y-3 pt-2">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="text-xs font-black font-outfit uppercase tracking-wider text-slate-800 dark:text-slate-200 flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-tertiary text-base">format_list_bulleted</span>
                                <span>Conceptos / Ítems de Ajuste</span>
                            </label>

                            <div class="flex items-center gap-2.5">
                                <!-- Checkbox / Interruptor "¿Registra novedades?" -->
                                <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50/90 dark:bg-amber-950/40 border-2 border-amber-400 dark:border-amber-700/80 rounded-xl shadow-xs">
                                    <label for="chkRegistraNovedadesNota" class="text-xs font-bold text-amber-900 dark:text-amber-200 flex items-center gap-1.5 cursor-pointer select-none">
                                        <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-base">campaign</span>
                                        <span>¿Registra novedades?</span>
                                    </label>
                                    <input type="checkbox" id="chkRegistraNovedadesNota" onchange="alCambiarToggleNovedadesNota(this)" class="w-4 h-4 text-amber-600 bg-white dark:bg-slate-800 border-amber-400 rounded focus:ring-amber-500 cursor-pointer" />
                                </div>

                                <button type="button" onclick="agregarFilaItemAjuste()" class="px-3 py-1.5 rounded-xl bg-tertiary/10 hover:bg-tertiary/20 text-tertiary font-bold text-xs inline-flex items-center gap-1 cursor-pointer transition-colors">
                                    <span class="material-symbols-outlined text-sm">add</span>
                                    <span>Agregar Ítem</span>
                                </button>
                            </div>
                        </div>

                        <datalist id="cupsDatalist"></datalist>

                        <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden shadow-inner bg-white dark:bg-slate-900">
                            <table class="w-full text-left text-xs">
                                <thead>
                                    <tr class="bg-slate-100 dark:bg-slate-800 text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase">
                                        <th class="py-2.5 px-3 w-40">Sede</th>
                                        <th class="py-2.5 px-3 w-32">Código CUPS</th>
                                        <th class="py-2.5 px-3 w-32">Tipo Paciente</th>
                                        <th class="py-2.5 px-3">Concepto / Estudio (Tarifario)</th>
                                        <th class="py-2.5 px-2 text-center w-14">Cant</th>
                                        <th class="py-2.5 px-2 text-right w-28">Valor Unit.</th>
                                        <th class="py-2.5 px-3 text-right w-28">Subtotal</th>
                                        <th class="py-2.5 px-2 text-center w-8"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsAjusteTableBody" class="divide-y divide-slate-100 dark:divide-slate-800">
                                    <!-- Filas generadas dinámicamente -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Resumen Dinámico de Novedades Aplicadas en la Nota -->
                        <div id="containerNovedadesNotaResumen" class="hidden p-3 rounded-2xl bg-amber-50/90 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800/80 space-y-2">
                            <div class="flex items-center justify-between text-xs font-bold text-amber-900 dark:text-amber-200">
                                <span class="flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-sm text-amber-600 dark:text-amber-400">campaign</span>
                                    <span>Novedades de la Entidad Incorporadas</span>
                                </span>
                                <button type="button" onclick="abrirModalNovedadesNota()" class="text-[11px] text-amber-700 dark:text-amber-400 hover:underline flex items-center gap-0.5 cursor-pointer font-bold">
                                    <span class="material-symbols-outlined text-xs">edit_note</span>
                                    <span>Modificar Novedades</span>
                                </button>
                            </div>
                            <div id="listaNovedadesNotaBadges" class="flex flex-wrap gap-2"></div>
                        </div>
                    </div>

                    <!-- CONTENEDOR DINÁMICO: INFORMACIÓN CONTABLE - DEDUCCIONES & BALANCE DE AJUSTE -->
                    <div id="containerContableDinamico" class="grid grid-cols-1 lg:grid-cols-12 gap-4">
                        
                        <!-- Columna Izquierda (7 cols): Card Exacta de Información Contable - Deducciones de la Liquidación -->
                        <div class="lg:col-span-7 bg-white dark:bg-slate-900 rounded-2xl border border-rose-200 dark:border-rose-900/60 shadow-sm overflow-hidden border-l-4 border-l-rose-500 flex flex-col">
                            <div class="bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 py-2.5 px-4 font-bold text-xs tracking-widest text-center uppercase border-b border-rose-200 dark:border-rose-900/40 flex items-center justify-center gap-1.5 shrink-0 font-outfit">
                                <i class="fa-solid fa-calculator text-sm"></i>
                                <span>INFORMACIÓN CONTABLE - DEDUCCIONES</span>
                            </div>
                            
                            <!-- Barra de Estado de Parafiscales / Pensionado (Dinámica) -->
                            <div id="modalDeduccionesStatusBar">
                                <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between text-[10px]">
                                    <span class="font-medium flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                                        <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                                        <span>Seleccione una liquidación base</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Filas Dinámicas de Deducciones (AFC, Fondo Solidaridad, IBC, Salud, ARL, Pensión, Retención) -->
                            <div class="p-4 space-y-2.5 flex-1" id="modalDeduccionesRows">
                                <div class="py-4 text-center text-slate-400 text-xs">
                                    Cargando información contable...
                                </div>
                            </div>

                            <!-- Total Deducciones con Delta -->
                            <div class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/60 border-t border-slate-200 dark:border-slate-700 flex justify-between items-center text-xs font-bold text-rose-600 dark:text-rose-400">
                                <div class="flex items-center gap-1.5">
                                    <span class="uppercase tracking-wider">TOTAL DEDUCCIONES</span>
                                    <span id="modalDeduccionesDeltaBadge" class="hidden text-[10px] font-mono px-1.5 py-0.5 rounded bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 border border-rose-300 dark:border-rose-800 font-bold"></span>
                                </div>
                                <span class="font-mono text-sm font-black" id="modalTotalDeduccionesDisplay">- $ 0</span>
                            </div>
                        </div>

                        <!-- Columna Derecha (5 cols): Impacto Financiero y Consolidación del Pago -->
                        <div class="lg:col-span-5 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 text-slate-800 dark:text-white flex flex-col justify-between space-y-3.5 shadow-sm">
                            <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-teal-600 dark:text-teal-400 text-base">balance</span>
                                    <span class="font-outfit font-black text-xs uppercase tracking-wider text-teal-800 dark:text-teal-300">
                                        Balance Financiero del Ajuste
                                    </span>
                                </div>
                                <span id="modalBadgeTipoAjuste" class="text-[10px] font-mono font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700">
                                    CRÉDITO (+)
                                </span>
                            </div>

                            <div class="space-y-2 text-xs">
                                <div class="flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                                    <span class="text-slate-500 dark:text-slate-400 text-[11px]">Subtotal Exámenes (Bruto):</span>
                                    <span class="font-mono font-black text-slate-900 dark:text-slate-200" id="resumenBrutoAjuste">$ 0</span>
                                </div>
                                <div class="flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                                    <span class="text-slate-500 dark:text-slate-400 text-[11px]">Impacto Deducciones de Ley:</span>
                                    <span class="font-mono font-bold text-rose-600 dark:text-rose-400" id="resumenDeltaDeducciones">$ 0</span>
                                </div>
                                <div id="resumenNovedadesRow" class="hidden flex items-center justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                                    <span class="text-amber-600 dark:text-amber-400 text-[11px] font-semibold flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs">campaign</span>
                                        <span>Novedades Entidad:</span>
                                    </span>
                                    <span class="font-mono font-bold text-amber-700 dark:text-amber-300" id="resumenNovedadesAjuste">$ 0</span>
                                </div>
                                <div class="flex items-center justify-between py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800/80 px-2.5 border border-slate-200 dark:border-slate-700/60">
                                    <span class="text-slate-700 dark:text-slate-200 font-bold text-[11px]">(=) Ajuste Neto Real:</span>
                                    <span class="font-mono font-black text-sm text-teal-700 dark:text-emerald-400" id="resumenValorAjuste">$ 0</span>
                                </div>
                            </div>

                            <!-- Comparativa Total Original vs Nuevo Saldo Final -->
                            <div class="grid grid-cols-2 gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 text-center">
                                    <span class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase block">Total Original</span>
                                    <span class="font-outfit font-black text-xs text-slate-800 dark:text-slate-200 mt-0.5 block" id="resumenTotalOriginal">$ 0</span>
                                </div>
                                <div class="p-2.5 rounded-xl bg-teal-50 dark:bg-teal-950/70 border border-teal-200 dark:border-emerald-500/60 text-center">
                                    <span class="text-[9px] font-bold text-teal-800 dark:text-emerald-400 uppercase block">Nuevo Total a Pagar</span>
                                    <span class="font-outfit font-black text-sm text-teal-700 dark:text-emerald-300 mt-0.5 block" id="resumenTotalAjustado">$ 0</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Footer Botones Fijo -->
                <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center justify-end gap-3 shrink-0">
                    <button type="button" onclick="cerrarModalCrearNota()" class="px-5 py-2.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarNota" class="px-6 py-2.5 rounded-xl text-xs font-bold bg-tertiary hover:bg-teal-500 text-white shadow-md transition-all flex items-center gap-2 cursor-pointer">
                        <span class="material-symbols-outlined text-sm">check_circle</span>
                        <span>Guardar Nota de Ajuste</span>
                    </button>
                </div>

            </form>

        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 1.1: Registrar Novedades de la Entidad en Nota de Ajuste -->
    <!-- ========================================================================= -->
    <div id="modalNovedadesNota" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-950/75 backdrop-blur-md">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-2xl w-full shadow-2xl border border-amber-300 dark:border-amber-700/60 flex flex-col max-h-[90vh] overflow-hidden animate__animated animate__zoomIn animate__faster">
            
            <div class="px-6 py-4 border-b border-amber-200 dark:border-amber-800/60 flex items-center justify-between bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-2xl">campaign</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black font-outfit text-slate-900 dark:text-white">Novedades de la Entidad</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400" id="modalNovNotaSubhead">Catálogo de novedades disponible para esta liquidación</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalNovedadesNota()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <div class="p-6 overflow-y-auto space-y-4 flex-1 custom-scrollbar">
                <div id="modalNovNotaLoading" class="py-8 text-center text-slate-400">
                    <span class="material-symbols-outlined text-3xl animate-spin text-amber-500 mb-2">sync</span>
                    <p class="text-xs font-semibold">Consultando catálogo de novedades de la entidad...</p>
                </div>

                <div id="modalNovNotaEmpty" class="hidden py-8 text-center text-slate-400">
                    <span class="material-symbols-outlined text-4xl text-slate-300 dark:text-slate-600 mb-2">info</span>
                    <p class="text-xs font-semibold">No hay novedades activas configuradas para esta entidad en el Maestro de Novedades.</p>
                    <a href="maestro_novedades.php" target="_blank" class="mt-2 inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400 hover:underline font-bold">
                        <span class="material-symbols-outlined text-xs">open_in_new</span> Ir al Maestro de Novedades
                    </a>
                </div>

                <div id="modalNovNotaCards" class="hidden space-y-3">
                    <!-- Dinámico: cards con checkbox, código, nombre, tipo, input de valor e input de observación -->
                </div>
            </div>

            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between bg-slate-50 dark:bg-slate-950/50">
                <div class="text-xs text-slate-500 dark:text-slate-400">
                    <span id="modalNovNotaSeleccionadasCount" class="font-bold text-amber-600 dark:text-amber-400 font-mono">0</span> novedad(es) seleccionada(s)
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="cerrarModalNovedadesNota()" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="button" onclick="aplicarNovedadesANota()" class="px-5 py-2 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 shadow-md shadow-amber-600/20 active:scale-95 transition-all flex items-center gap-1.5 cursor-pointer">
                        <span class="material-symbols-outlined text-base">check_circle</span>
                        <span>Aplicar al Ajuste</span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- MODAL 2: Ver Detalle de Nota de Ajuste -->
    <!-- ========================================================================= -->
    <div id="modalDetalleNota" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-950/70 backdrop-blur-md">
        <div id="modalContainerDetail" class="bg-white dark:bg-slate-900 rounded-3xl max-w-6xl 2xl:max-w-7xl w-full shadow-2xl border border-slate-200 dark:border-slate-800 flex flex-col max-h-[92vh] overflow-hidden transform transition-all animate__animated animate__fadeInDown animate__faster">
            
            <!-- Header Modal Fijo -->
            <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-white dark:bg-slate-900 shrink-0 no-print">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-teal-500/10 text-teal-400 flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">description</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black font-outfit text-primary dark:text-white" id="detalleNotaTitulo">Detalle de Nota de Ajuste</h3>
                        <p class="text-xs text-slate-400 font-medium" id="detalleNotaSubtitulo">Trazabilidad y desglose contable</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalDetalleNota()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors no-print">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Body con Scroll Interno -->
            <div id="detalleNotaBody" class="p-6 sm:p-8 overflow-y-auto space-y-6 flex-1 bg-slate-50/50 dark:bg-slate-950/50 print:p-0 print:overflow-visible">
                <!-- Contenido inyectado dinámicamente -->
            </div>

            <!-- Footer Fijo con Acciones -->
            <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900 flex items-center justify-between shrink-0 no-print" id="detalleNotaFooterActions">
                <!-- Acciones dinámicas de aprobación/anulación -->
            </div>

        </div>
    </div>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <!-- Scripts de la Aplicación -->
    <script>
        const SwalCustom = Swal.mixin({
            customClass: {
                popup: 'bg-slate-900 border border-slate-700/80 rounded-3xl shadow-2xl text-slate-100 font-sans p-6',
                title: 'text-base font-black font-sans text-white tracking-wide',
                htmlContainer: 'text-xs text-slate-300 font-medium leading-relaxed',
                confirmButton: 'px-5 py-2.5 rounded-xl bg-tertiary hover:bg-teal-600 text-white font-bold text-xs shadow-md transition-all cursor-pointer mx-1 border border-white/10',
                cancelButton: 'px-5 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition-all cursor-pointer mx-1 border border-slate-700',
                input: 'bg-slate-800 border border-slate-700 text-white rounded-xl text-xs focus:ring-2 focus:ring-tertiary outline-none p-3'
            },
            buttonsStyling: false,
            showClass: { popup: 'animate__animated animate__fadeInDown animate__faster' },
            hideClass: { popup: 'animate__animated animate__fadeOutUp animate__faster' }
        });

        // Estado Global del Periodo
        const periodState = {
            activeMonthKey: '<?php echo date("Y-m"); ?>',
            selectedYear: new Date().getFullYear(),
            isAllHistory: false,
            activeEstadoFilter: 'TODOS'
        };

        const mesesNombres = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        let notasDataCache = [];
        let liquidacionesDisponiblesCache = [];
        let liquidacionSeleccionadaActual = null;
        let catalogoCupsCache = {};
        const SEDES_MAESTRAS = ['ENVIGADO', 'POBLADO', 'UNICENTRO', 'SABANETA', 'CENTRO 101', 'CALDAS', 'CENTRO 907', 'BELLO', 'ITAGÜÍ', 'LAURELES', 'GENERAL'];
        window.sedesDisponibles = [...SEDES_MAESTRAS];

        document.addEventListener('DOMContentLoaded', () => {
            actualizarLabelPeriodo();
            renderGridMesesSelector();
            cargarNotas();
            cargarCatalogoCups();

            // Buscador en vivo
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('input', () => renderTablaNotas());
            }

            // Si viene preseleccionada por GET, asegurar carga limpia de opciones y abrir
            const preLiq = <?php echo $preselectedLiqId; ?>;
            cargarLiquidacionesSelect(preLiq > 0 ? preLiq : null).then(() => {
                if (preLiq > 0) {
                    abrirModalCrearNota(preLiq);
                }
            });
        });

        async function cargarCatalogoCups(entidadId = null) {
            try {
                const url = (entidadId && parseInt(entidadId, 10) > 0) 
                    ? `notas_ajuste.php?action=obtener_catalogo_cups&entidad_id=${encodeURIComponent(entidadId)}` 
                    : 'notas_ajuste.php?action=obtener_catalogo_cups';
                const res = await fetch(url);
                const result = await res.json();
                if (result.success && result.data) {
                    catalogoCupsCache = result.data;
                    const datalist = document.getElementById('cupsDatalist');
                    if (datalist) {
                        datalist.innerHTML = '';
                        const vistos = new Set();
                        for (const [code, item] of Object.entries(catalogoCupsCache)) {
                            if (item && item.codigo && !vistos.has(item.codigo)) {
                                vistos.add(item.codigo);
                                const opt = document.createElement('option');
                                opt.value = `${item.codigo} - ${item.examen}`;
                                datalist.appendChild(opt);
                            }
                        }
                    }
                }
            } catch(e) {
                console.error(e);
            }
        }

        function generarOpcionesSedesHtml(selectedVal = '') {
            const list = (window.sedesDisponibles && window.sedesDisponibles.length > 0) 
                ? window.sedesDisponibles 
                : SEDES_MAESTRAS;
            
            let html = '';
            list.forEach(s => {
                const isSel = (selectedVal && selectedVal.toUpperCase() === s.toUpperCase()) ? 'selected' : '';
                html += `<option value="${htmlspecialchars(s)}" ${isSel}>${htmlspecialchars(s)}</option>`;
            });
            return html;
        }

        function alCambiarTipoPacienteFila(selectEl) {
            const tr = selectEl.closest('tr');
            const cupsInput = tr.querySelector('.item-cups');
            if (cupsInput) alCambiarCupsFila(cupsInput);
        }

        function alCambiarCupsFila(inputEl) {
            let code = inputEl.value.trim().toUpperCase();
            const tr = inputEl.closest('tr');
            const conceptoInput = tr.querySelector('.item-concepto');
            const valorInput = tr.querySelector('.item-valor');
            const tipoPacSelect = tr.querySelector('.item-tipo-paciente');
            const tipoPac = tipoPacSelect ? tipoPacSelect.value : 'E';

            if (!code) {
                if (conceptoInput) conceptoInput.value = '';
                if (valorInput) valorInput.value = 0;
                calcularSubtotalFila(valorInput);
                return;
            }

            if (code.includes(' - ')) {
                const parts = code.split(' - ');
                code = parts[0].trim();
                inputEl.value = code;
            }

            const cleanCode = code.replace(/[^A-Za-z0-9]/g, '');
            const match = catalogoCupsCache[code] || catalogoCupsCache[cleanCode];

            if (match) {
                if (conceptoInput) {
                    conceptoInput.value = match.examen || match.codigo;
                }

                let tarifaFinal = 0;
                if (tipoPac === 'P') {
                    tarifaFinal = (match.valor_p > 0) ? match.valor_p : ((match.valor > 0) ? match.valor : (match.valor_e || 0));
                } else {
                    tarifaFinal = (match.valor_e > 0) ? match.valor_e : ((match.valor > 0) ? match.valor : (match.valor_p || 0));
                }

                if (valorInput) {
                    valorInput.value = tarifaFinal;
                }
                calcularSubtotalFila(valorInput);
            }
        }

        // 1. Manejo del Periodo
        function actualizarLabelPeriodo() {
            const label = document.getElementById('labelPeriodoActual');
            if (periodState.isAllHistory) {
                label.textContent = 'Todo el Histórico';
                return;
            }
            const [y, m] = periodState.activeMonthKey.split('-');
            const mIdx = parseInt(m, 10) - 1;
            label.textContent = `${mesesNombres[mIdx]} ${y}`;
        }

        function togglePopoverMeses() {
            const pop = document.getElementById('popoverMesesGrid');
            pop.classList.toggle('hidden');
        }

        function cambiarAnioSelector(delta) {
            periodState.selectedYear += delta;
            document.getElementById('labelAnioSelector').textContent = periodState.selectedYear;
            renderGridMesesSelector();
        }

        function renderGridMesesSelector() {
            const container = document.getElementById('gridMesesButtons');
            container.innerHTML = '';
            const y = periodState.selectedYear;

            mesesNombres.forEach((nom, idx) => {
                const mNum = String(idx + 1).padStart(2, '0');
                const mKey = `${y}-${mNum}`;
                const isSelected = (!periodState.isAllHistory && periodState.activeMonthKey === mKey);

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `p-2 rounded-xl text-[11px] font-bold text-center transition-all cursor-pointer ${
                    isSelected ? 'bg-tertiary text-white shadow-xs' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'
                }`;
                btn.textContent = nom.substring(0, 3);
                btn.onclick = () => {
                    periodState.activeMonthKey = mKey;
                    periodState.isAllHistory = false;
                    actualizarLabelPeriodo();
                    document.getElementById('popoverMesesGrid').classList.add('hidden');
                    cargarNotas();
                    cargarLiquidacionesSelect();
                };
                container.appendChild(btn);
            });
        }

        function setPeriodoMes(tipo) {
            const now = new Date();
            if (tipo === 'CURRENT') {
                const y = now.getFullYear();
                const m = String(now.getMonth() + 1).padStart(2, '0');
                periodState.activeMonthKey = `${y}-${m}`;
                periodState.isAllHistory = false;
            } else if (tipo === 'PREVIOUS') {
                const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                const y = prev.getFullYear();
                const m = String(prev.getMonth() + 1).padStart(2, '0');
                periodState.activeMonthKey = `${y}-${m}`;
                periodState.isAllHistory = false;
            } else if (tipo === 'ALL') {
                periodState.isAllHistory = true;
            }
            actualizarLabelPeriodo();
            cargarNotas();
            cargarLiquidacionesSelect();
        }

        function navegarMesRelativo(delta) {
            if (periodState.isAllHistory) periodState.isAllHistory = false;
            const [y, m] = periodState.activeMonthKey.split('-');
            const d = new Date(parseInt(y, 10), parseInt(m, 10) - 1 + delta, 1);
            const newY = d.getFullYear();
            const newM = String(d.getMonth() + 1).padStart(2, '0');
            periodState.activeMonthKey = `${newY}-${newM}`;
            actualizarLabelPeriodo();
            cargarNotas();
            cargarLiquidacionesSelect();
        }

        // 2. Cargar Notas y Renderizar Tabla
        async function cargarNotas() {
            const tbody = document.getElementById('notasTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="py-12 text-center text-slate-400">
                        <span class="material-symbols-outlined text-3xl animate-spin block mb-2 text-tertiary">sync</span>
                        <p class="font-bold text-xs">Cargando notas de ajuste...</p>
                    </td>
                </tr>
            `;

            const pParam = periodState.isAllHistory ? '' : periodState.activeMonthKey;
            try {
                const res = await fetch(`notas_ajuste.php?action=listar_notas&periodo=${pParam}`);
                const result = await res.json();
                if (result.success) {
                    notasDataCache = result.data || [];
                    
                    // Actualizar KPIs
                    const k = result.kpis || {};
                    document.getElementById('kpiTotalNotas').textContent = (k.total_notas || 0).toLocaleString('es-CO');
                    document.getElementById('kpiTotalCreditos').textContent = '$ ' + (k.total_creditos || 0).toLocaleString('es-CO');
                    document.getElementById('kpiTotalDebitos').textContent = '$ ' + (k.total_debitos || 0).toLocaleString('es-CO');
                    document.getElementById('kpiBalanceNeto').textContent = '$ ' + (k.balance_neto || 0).toLocaleString('es-CO');

                    renderTablaNotas();
                } else {
                    tbody.innerHTML = `<tr><td colspan="9" class="py-8 text-center text-rose-500 font-bold">${result.error || 'Error cargando notas'}</td></tr>`;
                }
            } catch(e) {
                console.error(e);
                tbody.innerHTML = `<tr><td colspan="9" class="py-8 text-center text-rose-500 font-bold">Error de conexión al cargar notas de ajuste</td></tr>`;
            }
        }

        function filtrarPorEstado(estado) {
            periodState.activeEstadoFilter = estado;
            ['TODOS', 'PENDIENTE', 'APROBADA', 'ANULADA'].forEach(st => {
                const btn = document.getElementById('tabEstado' + st);
                if (btn) {
                    if (st === estado) {
                        btn.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all whitespace-nowrap cursor-pointer bg-white dark:bg-slate-700 text-primary dark:text-white shadow-xs";
                    } else {
                        btn.className = "px-3.5 py-1.5 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-primary dark:hover:text-white transition-all whitespace-nowrap cursor-pointer";
                    }
                }
            });
            renderTablaNotas();
        }

        function renderTablaNotas() {
            const tbody = document.getElementById('notasTableBody');
            tbody.innerHTML = '';

            const term = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
            const filterSt = periodState.activeEstadoFilter;

            const filtered = notasDataCache.filter(n => {
                if (filterSt !== 'TODOS' && n.estado !== filterSt) return false;
                if (term) {
                    const matchNom = (n.medico_nombre || '').toLowerCase().includes(term);
                    const matchCed = (n.medico_cedula || '').toLowerCase().includes(term);
                    const matchNum = (n.numero_nota || '').toLowerCase().includes(term);
                    const matchMot = (n.motivo_ajuste || '').toLowerCase().includes(term);
                    if (!matchNom && !matchCed && !matchNum && !matchMot) return false;
                }
                return true;
            });

            if (filtered.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="py-12 text-center text-slate-400">
                            <span class="material-symbols-outlined text-4xl block mb-2 opacity-50">search_off</span>
                            <p class="font-bold text-xs">No se encontraron notas de ajuste para el criterio de búsqueda o periodo seleccionado.</p>
                        </td>
                    </tr>
                `;
                return;
            }

            filtered.forEach(n => {
                const tr = document.createElement('tr');
                tr.className = "hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors";

                const valAjuste = floatval(n.valor_ajuste || 0);
                const esCredito = (n.tipo_nota === 'CREDITO' && valAjuste >= 0) || (valAjuste > 0 && n.tipo_nota !== 'DEBITO');

                let badgeTipo = esCredito
                    ? `<span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 flex items-center justify-center gap-1 w-24 mx-auto"><span class="material-symbols-outlined text-xs">add_circle</span> CRÉDITO</span>`
                    : `<span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950/70 dark:text-rose-300 border border-rose-200 dark:border-rose-800 flex items-center justify-center gap-1 w-24 mx-auto"><span class="material-symbols-outlined text-xs">remove_circle</span> DÉBITO</span>`;

                let badgeEstado = '';
                if (n.estado === 'PENDIENTE') {
                    badgeEstado = `<span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300 border border-amber-200 dark:border-amber-800 inline-flex items-center gap-1"><span class="material-symbols-outlined text-xs">hourglass_top</span> Pendiente</span>`;
                } else if (n.estado === 'APROBADA') {
                    badgeEstado = `<span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 inline-flex items-center gap-1"><span class="material-symbols-outlined text-xs">check_circle</span> Aprobada</span>`;
                } else {
                    badgeEstado = `<span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950/70 dark:text-rose-300 border border-rose-200 dark:border-rose-800 inline-flex items-center gap-1"><span class="material-symbols-outlined text-xs">cancel</span> Anulada</span>`;
                }

                tr.innerHTML = `
                    <td class="py-3.5 px-4 font-mono font-black text-primary dark:text-tertiary">
                        ${htmlspecialchars(n.numero_nota || 'NA-' + n.id)}
                    </td>
                    <td class="py-3.5 px-4 font-mono font-bold text-slate-700 dark:text-slate-300">
                        <a href="aprobacion_liquidaciones.php" title="Ver liquidación base" class="hover:underline text-tertiary">
                            Liq #${n.liquidacion_id}
                        </a>
                    </td>
                    <td class="py-3.5 px-4">
                        <div class="font-bold text-slate-800 dark:text-slate-100">${htmlspecialchars(n.medico_nombre)}</div>
                        <div class="text-[10px] text-slate-400 font-mono">CC: ${htmlspecialchars(n.medico_cedula)}</div>
                    </td>
                    <td class="py-3.5 px-4 text-center">
                        ${badgeTipo}
                    </td>
                    <td class="py-3.5 px-4 text-right font-mono font-black ${esCredito ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}">
                        ${esCredito ? '+ ' : '- '}$ ${Math.abs(valAjuste).toLocaleString('es-CO')}
                    </td>
                    <td class="py-3.5 px-4 text-right font-mono font-black text-primary dark:text-tertiary">
                        $ ${floatval(n.total_ajustado || 0).toLocaleString('es-CO')}
                    </td>
                    <td class="py-3.5 px-4 text-slate-500 font-mono text-[11px]">
                        ${n.fecha_creacion}
                    </td>
                    <td class="py-3.5 px-4 text-center">
                        ${badgeEstado}
                    </td>
                    <td class="py-3.5 px-4 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <button type="button" onclick="verDetalleNota(${n.id})" title="Ver Detalle y Trazabilidad" class="p-2 rounded-xl bg-slate-100 hover:bg-tertiary hover:text-white dark:bg-slate-800 dark:hover:bg-tertiary text-slate-600 dark:text-slate-300 transition-colors cursor-pointer">
                                <span class="material-symbols-outlined text-base">visibility</span>
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        // 3. Crear Nota de Ajuste
        async function cargarLiquidacionesSelect(selectedIdToSet = null) {
            const select = document.getElementById('selectLiquidacionBase');
            if (!select) return;

            try {
                const res = await fetch(`notas_ajuste.php?action=obtener_liquidaciones_select`);
                const result = await res.json();
                if (result.success) {
                    liquidacionesDisponiblesCache = result.data || [];
                    const currentVal = selectedIdToSet || select.value;
                    select.innerHTML = '<option value="">-- Seleccione una liquidación aprobada --</option>';
                    liquidacionesDisponiblesCache.forEach(l => {
                        const opt = document.createElement('option');
                        opt.value = l.id;
                        opt.textContent = `Liquidación #${l.id} - ${l.medico_nombre} (${l.periodo_desde} al ${l.periodo_hasta}) - $ ${floatval(l.total_a_pagar).toLocaleString('es-CO')}`;
                        if (currentVal && String(currentVal) === String(l.id)) {
                            opt.selected = true;
                        }
                        select.appendChild(opt);
                    });
                    if (currentVal) {
                        select.value = currentVal;
                    }
                }
            } catch(e) {
                console.error(e);
            }
        }

        async function abrirModalCrearNota(preselectedId = null) {
            document.getElementById('formCrearNota').reset();
            document.getElementById('itemsAjusteTableBody').innerHTML = '';
            document.getElementById('snapshotLiqOriginal').classList.add('hidden');
            window.novedadesNotaAplicadas = [];
            const chkNov = document.getElementById('chkRegistraNovedadesNota');
            if (chkNov) chkNov.checked = false;
            actualizarResumenBadgesNovedadesNota();
            
            if (!liquidacionesDisponiblesCache || liquidacionesDisponiblesCache.length === 0) {
                await cargarLiquidacionesSelect(preselectedId);
            }

            if (preselectedId) {
                document.getElementById('selectLiquidacionBase').value = preselectedId;
                await alCambiarLiquidacionBase();
            } else {
                recalcularTotalesAjuste();
            }

            // Agregar primera fila de ítem por defecto si no hay ninguna
            if (document.querySelectorAll('#itemsAjusteTableBody tr').length === 0) {
                agregarFilaItemAjuste();
            }
            recalcularTotalesAjuste();

            document.getElementById('modalCrearNota').classList.remove('hidden');
        }

        function cerrarModalCrearNota() {
            window.edicionRetencionActiva = false;
            window.retencionManualPct = null;
            window.anotacionRetencion = '';
            window.novedadesNotaAplicadas = [];
            const chk = document.getElementById('chkRegistraNovedadesNota');
            if (chk) chk.checked = false;
            actualizarResumenBadgesNovedadesNota();
            document.getElementById('modalCrearNota').classList.add('hidden');
        }

        window.novedadesNotaAplicadas = [];
        window.catalogoNovedadesEntidadCache = {};

        async function alCambiarToggleNovedadesNota(chk) {
            const isChecked = chk.checked;
            const liq = liquidacionSeleccionadaActual;

            if (isChecked) {
                if (!liq || !liq.id) {
                    chk.checked = false;
                    SwalCustom.fire({
                        icon: 'info',
                        title: 'Liquidación requerida',
                        text: 'Seleccione primero una liquidación base para consultar las novedades disponibles para su entidad.'
                    });
                    return;
                }

                // Registrar en logs de auditoría que activó el registro de novedades
                fetch(`notas_ajuste.php?action=registrar_log_toggle_novedades&referencia_id=${liq.id}&entidad_id=${encodeURIComponent(liq.entidad_id || 'PROPIO')}&medico=${encodeURIComponent(liq.medico_nombre || '')}&activo=1`);

                abrirModalNovedadesNota();
            } else {
                if (window.novedadesNotaAplicadas && window.novedadesNotaAplicadas.length > 0) {
                    const confirmDeselect = await SwalCustom.fire({
                        title: '¿Remover novedades?',
                        text: 'Al desmarcar esta opción se removerán todas las novedades que había agregado a los conceptos del ajuste.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, remover novedades',
                        cancelButtonText: 'Mantener novedades'
                    });

                    if (!confirmDeselect.isConfirmed) {
                        chk.checked = true;
                        return;
                    }

                    // Remover filas de novedades de la tabla
                    document.querySelectorAll('#itemsAjusteTableBody tr[data-is-novedad="1"]').forEach(tr => tr.remove());
                    window.novedadesNotaAplicadas = [];
                    actualizarResumenBadgesNovedadesNota();
                    recalcularTotalesAjuste();
                }

                if (liq && liq.id) {
                    fetch(`notas_ajuste.php?action=registrar_log_toggle_novedades&referencia_id=${liq.id}&entidad_id=${encodeURIComponent(liq.entidad_id || 'PROPIO')}&medico=${encodeURIComponent(liq.medico_nombre || '')}&activo=0`);
                }
            }
        }

        async function abrirModalNovedadesNota() {
            const liq = liquidacionSeleccionadaActual;
            if (!liq) {
                SwalCustom.fire({ icon: 'warning', title: 'Atención', text: 'Debe seleccionar una liquidación base.' });
                return;
            }

            const entidadId = liq.entidad_id || 'PROPIO';
            const modal = document.getElementById('modalNovedadesNota');
            const subhead = document.getElementById('modalNovNotaSubhead');
            const loading = document.getElementById('modalNovNotaLoading');
            const empty = document.getElementById('modalNovNotaEmpty');
            const cardsContainer = document.getElementById('modalNovNotaCards');

            subhead.textContent = `Entidad: ${entidadId} • Liquidación #${liq.id} (${liq.medico_nombre})`;
            modal.classList.remove('hidden');

            loading.classList.remove('hidden');
            empty.classList.add('hidden');
            cardsContainer.classList.add('hidden');
            cardsContainer.innerHTML = '';

            try {
                let novedades = window.catalogoNovedadesEntidadCache[entidadId];
                if (!novedades) {
                    const res = await fetch(`notas_ajuste.php?action=obtener_novedades_entidad&entidad_id=${encodeURIComponent(entidadId)}`);
                    const json = await res.json();
                    if (json.success && Array.isArray(json.data)) {
                        novedades = json.data;
                        window.catalogoNovedadesEntidadCache[entidadId] = novedades;
                    } else {
                        novedades = [];
                    }
                }

                loading.classList.add('hidden');

                if (novedades.length === 0) {
                    empty.classList.remove('hidden');
                    return;
                }

                cardsContainer.classList.remove('hidden');

                // Renderizar cards
                novedades.forEach((nov, idx) => {
                    const yaAplicada = (window.novedadesNotaAplicadas || []).find(n => n.codigo === nov.codigo);
                    const isChecked = !!yaAplicada;
                    const valorActual = yaAplicada ? yaAplicada.valor : floatval(nov.valor_predeterminado || 0);
                    const obsActual = yaAplicada ? (yaAplicada.observacion || '') : '';
                    const esAdicion = (nov.tipo === 'ADICION');

                    const card = document.createElement('div');
                    card.className = `p-4 rounded-2xl border transition-all ${isChecked ? 'bg-amber-500/10 border-amber-500 dark:border-amber-400' : 'bg-slate-50 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700/80 hover:border-amber-400'}`;
                    card.setAttribute('data-nov-codigo', nov.codigo);

                    card.innerHTML = `
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="chkNovNota_${idx}" ${isChecked ? 'checked' : ''} onchange="alCambiarCheckCardNovedadNota(this)" class="nov-card-check mt-1 w-4 h-4 text-amber-600 bg-white dark:bg-slate-800 border-amber-400 rounded focus:ring-amber-500 cursor-pointer" />
                            <div class="flex-1 space-y-2">
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <label for="chkNovNota_${idx}" class="font-bold text-xs text-slate-800 dark:text-slate-100 cursor-pointer flex items-center gap-1.5">
                                        <span class="font-mono text-[11px] px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300 font-black">${nov.codigo}</span>
                                        <span>${htmlspecialchars(nov.nombre)}</span>
                                    </label>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider ${esAdicion ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700' : 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border border-rose-300 dark:border-rose-700'}">
                                        ${esAdicion ? '+ ADICIÓN' : '- DEDUCCIÓN'}
                                    </span>
                                </div>
                                ${nov.descripcion ? `<p class="text-[11px] text-slate-500 dark:text-slate-400">${htmlspecialchars(nov.descripcion)}</p>` : ''}
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1">
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-600 dark:text-slate-400 uppercase">Valor ($ COP)</label>
                                        <input type="number" step="0.01" min="0" value="${valorActual}" placeholder="0" class="nov-card-valor w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs font-mono font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-1 focus:ring-amber-500" />
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-600 dark:text-slate-400 uppercase">Observación / Detalle</label>
                                        <input type="text" value="${htmlspecialchars(obsActual)}" placeholder="Opcional..." class="nov-card-obs w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl p-2 text-xs text-slate-800 dark:text-slate-200 outline-none focus:ring-1 focus:ring-amber-500" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;

                    card._novData = nov;
                    cardsContainer.appendChild(card);
                });

                actualizarConteoSeleccionadasNovedadesNota();

            } catch(e) {
                loading.classList.add('hidden');
                empty.classList.remove('hidden');
                console.error("Error al cargar novedades de la entidad:", e);
            }
        }

        function alCambiarCheckCardNovedadNota(inputEl) {
            const card = inputEl.closest('[data-nov-codigo]');
            if (inputEl.checked) {
                card.classList.add('bg-amber-500/10', 'border-amber-500', 'dark:border-amber-400');
                card.classList.remove('bg-slate-50', 'dark:bg-slate-800/60', 'border-slate-200', 'dark:border-slate-700/80');
            } else {
                card.classList.remove('bg-amber-500/10', 'border-amber-500', 'dark:border-amber-400');
                card.classList.add('bg-slate-50', 'dark:bg-slate-800/60', 'border-slate-200', 'dark:border-slate-700/80');
            }
            actualizarConteoSeleccionadasNovedadesNota();
        }

        function actualizarConteoSeleccionadasNovedadesNota() {
            const count = document.querySelectorAll('#modalNovNotaCards .nov-card-check:checked').length;
            const el = document.getElementById('modalNovNotaSeleccionadasCount');
            if (el) el.textContent = count;
        }

        function cerrarModalNovedadesNota() {
            document.getElementById('modalNovedadesNota').classList.add('hidden');
            if (!window.novedadesNotaAplicadas || window.novedadesNotaAplicadas.length === 0) {
                const chk = document.getElementById('chkRegistraNovedadesNota');
                if (chk) chk.checked = false;
            }
        }

        function aplicarNovedadesANota() {
            const cardEls = document.querySelectorAll('#modalNovNotaCards [data-nov-codigo]');
            const seleccionadas = [];

            cardEls.forEach(card => {
                const chk = card.querySelector('.nov-card-check');
                if (chk && chk.checked) {
                    const nov = card._novData;
                    const val = parseFloat(card.querySelector('.nov-card-valor')?.value) || 0;
                    const obs = (card.querySelector('.nov-card-obs')?.value || '').trim();

                    seleccionadas.push({
                        id: nov.id,
                        codigo: nov.codigo,
                        nombre: nov.nombre,
                        tipo: nov.tipo,
                        valor: val,
                        observacion: obs
                    });
                }
            });

            // Remover filas viejas de novedades en la tabla de conceptos
            document.querySelectorAll('#itemsAjusteTableBody tr[data-is-novedad="1"]').forEach(tr => tr.remove());

            // Agregar cada novedad seleccionada como ítem de ajuste
            seleccionadas.forEach(item => {
                const conceptoCompleto = `[NOVEDAD] ${item.nombre}${item.observacion ? ' - ' + item.observacion : ''}`;
                agregarFilaItemAjuste({
                    is_novedad: 1,
                    nov_tipo: item.tipo,
                    nov_codigo: item.codigo,
                    cups: item.codigo,
                    concepto: conceptoCompleto,
                    cantidad: 1,
                    valor_unitario: item.valor
                });
            });

            window.novedadesNotaAplicadas = seleccionadas;
            actualizarResumenBadgesNovedadesNota();

            const chkMain = document.getElementById('chkRegistraNovedadesNota');
            if (chkMain) chkMain.checked = (seleccionadas.length > 0);

            cerrarModalNovedadesNota();
            recalcularTotalesAjuste();
        }

        function actualizarResumenBadgesNovedadesNota() {
            const container = document.getElementById('containerNovedadesNotaResumen');
            const listaBadges = document.getElementById('listaNovedadesNotaBadges');
            if (!container || !listaBadges) return;

            const items = window.novedadesNotaAplicadas || [];
            if (items.length === 0) {
                container.classList.add('hidden');
                listaBadges.innerHTML = '';
                return;
            }

            container.classList.remove('hidden');
            let badgesHtml = '';
            items.forEach(nov => {
                const esAdicion = (nov.tipo === 'ADICION');
                badgesHtml += `
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-bold ${esAdicion ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border border-rose-300'}">
                        <span>${esAdicion ? '+' : '-'}</span>
                        <span class="font-mono text-[10px]">${nov.codigo}</span>
                        <span>${htmlspecialchars(nov.nombre)}:</span>
                        <strong class="font-mono">$ ${floatval(nov.valor || 0).toLocaleString('es-CO')}</strong>
                    </span>
                `;
            });
            listaBadges.innerHTML = badgesHtml;
        }

        async function alCambiarLiquidacionBase() {
            window.edicionRetencionActiva = false;
            window.retencionManualPct = null;
            window.anotacionRetencion = '';
            window.novedadesNotaAplicadas = [];
            const chkNov = document.getElementById('chkRegistraNovedadesNota');
            if (chkNov) chkNov.checked = false;
            actualizarResumenBadgesNovedadesNota();
            document.querySelectorAll('#itemsAjusteTableBody tr[data-is-novedad="1"]').forEach(tr => tr.remove());
            const id = document.getElementById('selectLiquidacionBase').value;
            const snap = document.getElementById('snapshotLiqOriginal');
            if (!id) {
                snap.classList.add('hidden');
                liquidacionSeleccionadaActual = null;
                recalcularTotalesAjuste();
                return;
            }

            try {
                const res = await fetch(`notas_ajuste.php?action=obtener_detalle_liquidacion_base&id=${id}`);
                const result = await res.json();
                if (result.success && result.data) {
                    liquidacionSeleccionadaActual = result.data;
                    document.getElementById('snapLiqTitulo').textContent = `Liquidación N° ${result.data.id}`;
                    document.getElementById('snapLiqEstado').textContent = result.data.estado;
                    document.getElementById('snapLiqMedico').textContent = `${result.data.medico_nombre} (CC: ${result.data.medico_cedula})`;
                    document.getElementById('snapLiqPeriodo').textContent = `${result.data.periodo_desde} al ${result.data.periodo_hasta}`;
                    document.getElementById('snapLiqTotal').textContent = `$ ${floatval(result.data.total_a_pagar).toLocaleString('es-CO')}`;
                    snap.classList.remove('hidden');

                    // Si la liquidación tiene entidad asociada, actualizar catálogo CUPS
                    if (result.data.entidad_id) {
                        cargarCatalogoCups(result.data.entidad_id);
                    }

                    // Extraer sedes de la liquidación
                    let rawSedes = result.data.resumen_sedes_json || result.data.detalles_json || '{}';
                    let sedesObj = typeof rawSedes === 'string' ? JSON.parse(rawSedes) : (rawSedes || {});
                    let sedesKeys = Object.keys(sedesObj).sort();
                    window.sedesDisponibles = Array.from(new Set([...SEDES_MAESTRAS, ...sedesKeys]));

                    // Actualizar selects de sede existentes en la tabla
                    document.querySelectorAll('#itemsAjusteTableBody .item-sede').forEach(sel => {
                        const currVal = sel.value;
                        sel.innerHTML = generarOpcionesSedesHtml(currVal);
                    });
                }
            } catch(e) {
                console.error(e);
            }
            recalcularTotalesAjuste();
        }

        function agregarFilaItemAjuste(prefill = {}) {
            const tbody = document.getElementById('itemsAjusteTableBody');
            const tr = document.createElement('tr');
            tr.className = "hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors";

            if (prefill.is_novedad) {
                tr.setAttribute('data-is-novedad', '1');
                tr.setAttribute('data-nov-tipo', prefill.nov_tipo || 'ADICION');
                tr.setAttribute('data-nov-codigo', prefill.nov_codigo || '');
            }

            const defaultSede = prefill.sede || (window.sedesDisponibles && window.sedesDisponibles.length > 0 ? window.sedesDisponibles[0] : 'ENVIGADO');
            const defaultCups = prefill.cups || '';
            const defaultTipoPac = (prefill.tipo_paciente && prefill.tipo_paciente.toUpperCase() === 'P') ? 'P' : 'E';
            const defaultConcepto = prefill.concepto || '';
            const defaultCant = prefill.cantidad || 1;
            const defaultVal = (prefill.valor_unitario !== undefined) ? prefill.valor_unitario : 0;
            const defaultSub = defaultCant * defaultVal;
            const isNov = !!prefill.is_novedad;
            const novTipo = prefill.nov_tipo || 'ADICION';

            tr.innerHTML = `
                <td class="py-2 px-2">
                    <select class="item-sede w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-1 focus:ring-tertiary">
                        ${generarOpcionesSedesHtml(defaultSede)}
                    </select>
                </td>
                <td class="py-2 px-2">
                    <input type="text" list="cupsDatalist" value="${htmlspecialchars(defaultCups)}" placeholder="Ej: 871010" ${isNov ? 'readonly' : ''} oninput="alCambiarCupsFila(this)" onchange="alCambiarCupsFila(this)" class="item-cups w-full ${isNov ? 'bg-amber-50/80 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300' : 'bg-slate-50 dark:bg-slate-800 text-slate-800 dark:text-slate-200'} border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-mono font-bold uppercase outline-none focus:ring-1 focus:ring-tertiary" />
                </td>
                <td class="py-2 px-2">
                    <select onchange="alCambiarTipoPacienteFila(this)" ${isNov ? 'disabled' : ''} class="item-tipo-paciente w-full bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-1 focus:ring-tertiary">
                        <option value="E" ${defaultTipoPac === 'E' ? 'selected' : ''}>Empresa</option>
                        <option value="P" ${defaultTipoPac === 'P' ? 'selected' : ''}>Particular</option>
                    </select>
                </td>
                <td class="py-2 px-2">
                    <input type="text" value="${htmlspecialchars(defaultConcepto)}" ${isNov ? '' : 'readonly'} placeholder="Automático según CUPS" oninput="recalcularTotalesAjuste()" class="item-concepto w-full ${isNov ? 'bg-amber-50/50 dark:bg-amber-950/20 font-bold text-amber-900 dark:text-amber-200' : 'bg-slate-100 dark:bg-slate-800/60 font-semibold text-slate-600 dark:text-slate-300 cursor-not-allowed select-none'} border border-slate-200 dark:border-slate-700/80 rounded-xl p-2.5 text-xs outline-none" />
                </td>
                <td class="py-2 px-2 text-center">
                    <input type="number" min="1" value="${defaultCant}" oninput="calcularSubtotalFila(this)" class="item-cantidad w-16 text-center bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-2.5 text-xs font-bold text-slate-800 dark:text-slate-200 outline-none focus:ring-1 focus:ring-tertiary" />
                </td>
                <td class="py-2 px-2 text-right">
                    <input type="number" step="0.01" min="0" value="${defaultVal}" ${isNov ? '' : 'readonly'} oninput="calcularSubtotalFila(this)" class="item-valor w-28 text-right ${isNov ? 'bg-white dark:bg-slate-900 border-amber-300 text-amber-700 dark:text-amber-300' : 'bg-slate-100 dark:bg-slate-800/60 border-slate-200 dark:border-slate-700/80 text-slate-600 dark:text-slate-300 cursor-not-allowed select-none'} border rounded-xl p-2.5 text-xs font-mono font-bold outline-none" />
                </td>
                <td class="py-2 px-2 text-right font-mono font-bold ${isNov ? (novTipo === 'ADICION' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400') : 'text-slate-800 dark:text-slate-200'} item-subtotal-label whitespace-nowrap">
                    ${isNov ? (novTipo === 'ADICION' ? '+ ' : '- ') : ''}$ ${defaultSub.toLocaleString('es-CO')}
                </td>
                <td class="py-2 px-1 text-center">
                    <button type="button" onclick="eliminarFilaItem(this)" class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950 transition-colors" title="Eliminar ítem">
                        <span class="material-symbols-outlined text-base">delete</span>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
            recalcularTotalesAjuste();
        }

        function calcularSubtotalFila(inputEl) {
            const tr = inputEl.closest('tr');
            const cant = parseFloat(tr.querySelector('.item-cantidad').value) || 0;
            const val  = parseFloat(tr.querySelector('.item-valor').value) || 0;
            const sub  = cant * val;
            const isNov = tr.getAttribute('data-is-novedad') === '1';
            const novTipo = tr.getAttribute('data-nov-tipo') || 'ADICION';
            tr.querySelector('.item-subtotal-label').textContent = (isNov ? (novTipo === 'ADICION' ? '+ ' : '- ') : '') + '$ ' + sub.toLocaleString('es-CO');
            recalcularTotalesAjuste();
        }

        function eliminarFilaItem(btn) {
            const tbody = document.getElementById('itemsAjusteTableBody');
            const tr = btn.closest('tr');
            const isNov = tr.getAttribute('data-is-novedad') === '1';
            const novCod = tr.getAttribute('data-nov-codigo');

            if (tbody.children.length > 1 || isNov) {
                tr.remove();
                if (isNov && novCod) {
                    window.novedadesNotaAplicadas = window.novedadesNotaAplicadas.filter(n => n.codigo !== novCod);
                    actualizarResumenBadgesNovedadesNota();
                    if (window.novedadesNotaAplicadas.length === 0) {
                        const chk = document.getElementById('chkRegistraNovedadesNota');
                        if (chk) chk.checked = false;
                    }
                }
                recalcularTotalesAjuste();
            } else {
                SwalCustom.fire({ icon: 'warning', title: 'Atención', text: 'Debe existir al menos un concepto en la nota de ajuste.' });
            }
        }

        function renderDeduccionesContablesModal(liqOrig, c) {
            const statusBar = document.getElementById('modalDeduccionesStatusBar');
            const rowsContainer = document.getElementById('modalDeduccionesRows');
            const totalDisplay = document.getElementById('modalTotalDeduccionesDisplay');
            const deltaBadge = document.getElementById('modalDeduccionesDeltaBadge');

            const resBruto = document.getElementById('resumenBrutoAjuste');
            const resDeltaDed = document.getElementById('resumenDeltaDeducciones');
            const resNovRow = document.getElementById('resumenNovedadesRow');
            const resNovVal = document.getElementById('resumenNovedadesAjuste');
            const resAjuste = document.getElementById('resumenValorAjuste');
            const resOrig = document.getElementById('resumenTotalOriginal');
            const resFinal = document.getElementById('resumenTotalAjustado');
            const badgeTipo = document.getElementById('modalBadgeTipoAjuste');

            if (!statusBar || !rowsContainer) return;

            if (!liqOrig || !liqOrig.id) {
                statusBar.innerHTML = `
                    <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between text-[10px]">
                        <span class="font-medium flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                            <span>Seleccione una liquidación base</span>
                        </span>
                    </div>
                `;
                rowsContainer.innerHTML = `
                    <div class="py-6 text-center text-slate-400 text-xs">
                        <span class="material-symbols-outlined text-2xl block mb-1">receipt_long</span>
                        Seleccione una liquidación aprobada para visualizar sus deducciones contables.
                    </div>
                `;
                if (totalDisplay) totalDisplay.textContent = '- $ 0';
                if (deltaBadge) deltaBadge.classList.add('hidden');
                if (resBruto) resBruto.textContent = '$ 0';
                if (resDeltaDed) resDeltaDed.textContent = '$ 0';
                if (resNovRow) resNovRow.classList.add('hidden');
                if (resNovVal) resNovVal.textContent = '$ 0';
                if (resAjuste) resAjuste.textContent = '$ 0';
                if (resOrig) resOrig.textContent = '$ 0';
                if (resFinal) resFinal.textContent = '$ 0';
                return;
            }

            // Datos base de la liquidación original
            const dedAfc = floatval(liqOrig.ded_afc || 0);
            const dedSolidaridad = floatval(liqOrig.ded_solidaridad || 0);
            const dedIbc = floatval(liqOrig.ded_ibc || 0);
            const dedSalud = floatval(liqOrig.ded_salud || 0);
            const dedPension = floatval(liqOrig.ded_pension || 0);
            const dedArl = floatval(liqOrig.ded_arl || 0);
            const dedRete383Info = floatval(liqOrig.ded_rete_383_info || 0);
            const dedRete383 = floatval(liqOrig.ded_rete_383 || 0);
            const retencionPct = floatval(liqOrig.ded_retencion_pct || 0);
            const retencionOrig = floatval(liqOrig.ded_retencion || 0);

            // Datos recalculados del ajuste
            const calc = c || {};
            const nuevaRetencion = (calc.nueva_retencion !== undefined) ? calc.nueva_retencion : retencionOrig;
            const deltaRetencion = calc.delta_retencion || 0;
            const nuevoSalud = (calc.nuevo_salud !== undefined) ? calc.nuevo_salud : dedSalud;
            const nuevoPension = (calc.nuevo_pension !== undefined) ? calc.nuevo_pension : dedPension;
            const nuevoArl = (calc.nuevo_arl !== undefined) ? calc.nuevo_arl : dedArl;
            const nuevoIbc = (calc.nueva_factura && dedIbc > 0 && calc.total_factura_original > 0) ? Math.round(calc.nueva_factura * 0.40) : dedIbc;
            const deltaDeducciones = calc.delta_deducciones || 0;
            const nuevasDeducciones = (calc.nuevas_deducciones !== undefined) ? calc.nuevas_deducciones : floatval(liqOrig.total_deducciones || 0);
            const totalItemsBruto = calc.total_items_bruto || 0;
            const ajusteNetoReal = (calc.ajuste_neto_real !== undefined) ? calc.ajuste_neto_real : 0;
            const nuevoTotalPagar = (calc.nuevo_total_pagar !== undefined) ? calc.nuevo_total_pagar : floatval(liqOrig.total_a_pagar || 0);
            const esCredito = (document.getElementById('inputTipoNota').value === 'CREDITO');

            // 1. Barra de estado superior
            let statusBadgeText = '';
            if (dedPension === 0 && (dedSalud > 0 || dedArl > 0)) {
                statusBadgeText = `
                    <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between text-[10px]">
                        <span class="font-bold flex items-center gap-1.5 text-indigo-700 dark:text-indigo-300">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                            <span>Médico Pensionado (Salud y ARL)</span>
                        </span>
                        <span class="font-mono font-extrabold text-[9px] text-indigo-600 dark:text-indigo-400">Pensión: $0 (Exento)</span>
                    </div>
                `;
            } else if (dedSalud > 0 || dedPension > 0 || dedArl > 0 || dedIbc > 0) {
                const solBadge = (dedSolidaridad > 0) ? ` | F. Sol.: ${floatval(liqOrig.ded_solidaridad_pct || 0)}%` : '';
                statusBadgeText = `
                    <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between text-[10px]">
                        <span class="font-bold flex items-center gap-1.5 text-teal-700 dark:text-teal-300">
                            <span class="w-2 h-2 rounded-full bg-teal-500"></span>
                            <span>Parafiscales / AFC Activos</span>
                        </span>
                        <span class="font-mono font-extrabold text-[9px] text-teal-600 dark:text-teal-400">Seguridad Social Aplicada${solBadge}</span>
                    </div>
                `;
            } else {
                statusBadgeText = `
                    <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between text-[10px]">
                        <span class="font-medium flex items-center gap-1.5 text-slate-500 dark:text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                            <span>Parafiscales Desactivados ($0)</span>
                        </span>
                        <span class="font-mono font-extrabold text-[9px] text-slate-400">Valores en $0</span>
                    </div>
                `;
            }
            statusBar.innerHTML = statusBadgeText;

            // 2. Filas dinámicas exactamente como en aprobacion_liquidaciones.php
            let rowsHtml = '';
            if (dedAfc > 0) {
                rowsHtml += `
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">MENOS APORTES AFC</label>
                        <span class="font-mono font-bold text-slate-800 dark:text-slate-200 text-xs">$ ${dedAfc.toLocaleString('es-CO')}</span>
                    </div>
                `;
            }

            const nuevoSolidaridad = (calc.nuevo_solidaridad !== undefined) ? calc.nuevo_solidaridad : dedSolidaridad;
            if (dedSolidaridad > 0 || nuevoSolidaridad > 0) {
                const solPct = floatval(liqOrig.ded_solidaridad_pct || 0);
                const solPctText = solPct > 0 ? ` (${solPct}%)` : '';
                rowsHtml += `
                    <div class="flex items-center justify-between gap-2">
                        <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">MENOS APORTES FONDO SOLIDARIDAD${solPctText}</label>
                        <span class="font-mono font-bold text-slate-800 dark:text-slate-200 text-xs">$ ${nuevoSolidaridad.toLocaleString('es-CO')}</span>
                    </div>
                `;
            }

            if (dedSalud > 0 || dedArl > 0 || dedIbc > 0) {
                rowsHtml += `
                    <div class="pt-1.5 border-t border-slate-100 dark:border-slate-800 space-y-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <label class="text-[11px] font-bold text-slate-700 dark:text-slate-200">AFC MES (ESTIMADO)</label>
                            <span class="font-mono font-black text-xs text-slate-900 dark:text-white">$ ${nuevoIbc.toLocaleString('es-CO')}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                            <label class="text-[11px] font-medium">MENOS APORTES SALUD MES</label>
                            <span class="font-mono font-bold text-xs">- $ ${nuevoSalud.toLocaleString('es-CO')}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                            <label class="text-[11px] font-medium">MENOS APORTES ARL MES</label>
                            <span class="font-mono font-bold text-xs">- $ ${nuevoArl.toLocaleString('es-CO')}</span>
                        </div>
                `;
                if (dedPension > 0) {
                    rowsHtml += `
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                            <label class="text-[11px] font-medium">MENOS APORTES PENSIÓN MES</label>
                            <span class="font-mono font-bold text-xs">- $ ${nuevoPension.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }
                rowsHtml += `</div>`;
            }

            if (dedRete383Info > 0 || dedRete383 > 0) {
                rowsHtml += `
                    <div class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-1.5">
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
                                ${deltaRetencion !== 0 ? `
                                    <span class="text-[9px] font-mono font-bold px-1.5 py-0.2 rounded bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                                        Delta: ${deltaRetencion > 0 ? '+ ' : '- '}$ ${Math.abs(deltaRetencion).toLocaleString('es-CO')}
                                    </span>
                                ` : ''}
                            </div>
                            <span class="font-mono font-bold text-xs">- $ ${nuevaRetencion.toLocaleString('es-CO')}</span>
                        </div>
                    </div>
                `;
            } else {
                const pctActual = (window.edicionRetencionActiva && window.retencionManualPct !== null) 
                    ? window.retencionManualPct 
                    : retencionPct;

                if (!window.edicionRetencionActiva) {
                    rowsHtml += `
                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-1.5">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">% RETENCIÓN</label>
                                    <button type="button" onclick="activarEdicionRetencion()" class="inline-flex items-center gap-1 text-[10px] font-bold text-amber-700 dark:text-amber-400 hover:text-amber-800 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/60 dark:hover:bg-amber-900/80 px-2 py-0.5 rounded-lg border border-amber-300 dark:border-amber-700/80 transition-all cursor-pointer shadow-2xs" title="Haga clic si el porcentaje fue mal digitado y desea corregirlo">
                                        <span class="material-symbols-outlined text-xs">edit_note</span>
                                        <span>¿Corregir % por equivocación?</span>
                                    </button>
                                </div>
                                <span class="font-mono font-bold text-slate-800 dark:text-slate-200 text-xs">${pctActual} %</span>
                            </div>
                            <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">RETENCIÓN</label>
                                    <span id="reteDeltaBadgeDisplay" class="${deltaRetencion !== 0 ? '' : 'hidden'} text-[9px] font-mono font-bold px-1.5 py-0.2 rounded bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                                        Delta: ${deltaRetencion > 0 ? '+ ' : '- '}$ ${Math.abs(deltaRetencion).toLocaleString('es-CO')}
                                    </span>
                                </div>
                                <span class="font-mono font-bold text-xs" id="reteRecalculadaDisplay">- $ ${nuevaRetencion.toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                    `;
                } else {
                    rowsHtml += `
                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800 space-y-2.5">
                            <!-- Panel Activo de Corrección de Retención -->
                            <div class="p-3 rounded-2xl bg-amber-50/90 dark:bg-amber-950/50 border-2 border-amber-400/90 dark:border-amber-600/80 space-y-2.5 shadow-sm">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-amber-900 dark:text-amber-200">
                                        <span class="material-symbols-outlined text-base text-amber-600 dark:text-amber-400">edit_note</span>
                                        <span class="text-[11px] font-black uppercase tracking-wider font-outfit">Corrección Manual de % Retención</span>
                                    </div>
                                    <button type="button" onclick="cancelarEdicionRetencion()" class="text-[10px] font-bold text-slate-500 hover:text-slate-800 dark:text-slate-300 dark:hover:text-white flex items-center gap-0.5 px-2 py-0.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 transition-colors cursor-pointer shadow-2xs">
                                        <span class="material-symbols-outlined text-xs">close</span>
                                        <span>Deshacer</span>
                                    </button>
                                </div>

                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-2 bg-white/80 dark:bg-slate-900/80 rounded-xl border border-amber-200 dark:border-amber-800/80">
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-600 dark:text-slate-300 block uppercase mb-1">Porcentaje Corregido:</label>
                                        <div class="flex items-center gap-2">
                                            <input type="number" id="inputManualRetencionPct" min="0" max="100" step="0.1" 
                                                value="${pctActual}" 
                                                oninput="alCambiarInputRetencionManual(this.value)"
                                                class="w-20 px-2.5 py-1.5 bg-white dark:bg-slate-950 border-2 border-amber-500 dark:border-amber-500 rounded-lg text-xs font-mono font-black text-slate-900 dark:text-white text-center focus:ring-2 focus:ring-amber-400 outline-none" />
                                            <span class="font-black text-xs text-slate-700 dark:text-slate-300">%</span>
                                            <span class="text-[10px] text-slate-400 font-mono">(Original: ${retencionPct}%)</span>
                                        </div>
                                    </div>

                                    <div class="sm:text-right">
                                        <span class="text-[9px] font-bold text-slate-500 dark:text-slate-400 uppercase block">Retención Recalculada</span>
                                        <span class="font-mono font-black text-sm text-rose-600 dark:text-rose-400" id="reteRecalculadaDisplay">- $ ${nuevaRetencion.toLocaleString('es-CO')}</span>
                                    </div>
                                </div>

                                <!-- Anotación Obligatoria para Auditoría y Logs -->
                                <div>
                                    <label class="block text-[10px] font-bold text-amber-900 dark:text-amber-200 uppercase mb-1 flex items-center justify-between">
                                        <span>Anotación de Corrección para Auditoría & Logs <span class="text-rose-500 font-black">*</span></span>
                                        <span class="text-[9px] text-amber-600 dark:text-amber-400 lowercase font-medium">Se registrará en Logs</span>
                                    </label>
                                    <input type="text" id="inputAnotacionRete" 
                                        value="${htmlspecialchars(window.anotacionRetencion || '')}" 
                                        oninput="window.anotacionRetencion = this.value; this.classList.remove('ring-2', 'ring-rose-500');"
                                        placeholder="Ej: Se corrige % de retención por equivocación de digitación en la liquidación base..."
                                        class="w-full px-3 py-1.5 bg-white dark:bg-slate-950 border border-amber-300 dark:border-amber-700 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-200 placeholder-slate-400 focus:ring-2 focus:ring-amber-400 outline-none transition-all" />
                                </div>
                            </div>

                            <!-- Fila de Resumen de Retención -->
                            <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400 px-1">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-[11px] font-bold text-slate-700 dark:text-slate-200 uppercase">Impacto Retención</label>
                                    <span id="reteDeltaBadgeDisplay" class="${deltaRetencion !== 0 ? '' : 'hidden'} text-[9px] font-mono font-bold px-1.5 py-0.2 rounded bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                                        Delta: ${deltaRetencion > 0 ? '+ ' : '- '}$ ${Math.abs(deltaRetencion).toLocaleString('es-CO')}
                                    </span>
                                </div>
                                <span class="font-mono font-bold text-xs" id="reteRecalculadaDisplay2">- $ ${nuevaRetencion.toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                    `;
                }
            }

            if (!rowsHtml.trim()) {
                rowsHtml = `
                    <div class="py-4 px-2 text-center text-slate-400">
                        <div class="w-8 h-8 mx-auto mb-1 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                            <span class="material-symbols-outlined text-base">money_off</span>
                        </div>
                        <p class="text-[11px] font-semibold">El médico no tiene ninguna deducción activa.</p>
                    </div>
                `;
            }
            rowsContainer.innerHTML = rowsHtml;

            // 3. Footer Bar de Deducciones Totales
            if (totalDisplay) {
                totalDisplay.textContent = '- $ ' + nuevasDeducciones.toLocaleString('es-CO');
            }
            if (deltaBadge) {
                if (deltaDeducciones !== 0) {
                    deltaBadge.textContent = `Delta: ${deltaDeducciones > 0 ? '+ ' : '- '}$ ${Math.abs(deltaDeducciones).toLocaleString('es-CO')}`;
                    deltaBadge.classList.remove('hidden');
                } else {
                    deltaBadge.classList.add('hidden');
                }
            }

            // 4. Panel Derecho: Balance Financiero
            const deltaEstudios = calc.delta_estudios !== undefined ? calc.delta_estudios : (calc.total_estudios_bruto || 0) * (esCredito ? 1 : -1);
            const totalNovNeto = calc.total_novedades_neto || 0;
            const esPositivo = ajusteNetoReal >= 0;

            if (badgeTipo) {
                badgeTipo.textContent = esPositivo ? 'CRÉDITO (+)' : 'DÉBITO (-)';
                badgeTipo.className = `text-[10px] font-mono font-bold px-2 py-0.5 rounded-full ${esPositivo ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-200 dark:border-rose-700'}`;
            }
            if (resBruto) {
                if (deltaEstudios !== 0) {
                    resBruto.textContent = (deltaEstudios > 0 ? '+ ' : '- ') + '$ ' + Math.abs(deltaEstudios).toLocaleString('es-CO');
                    resBruto.className = `font-mono font-black ${deltaEstudios > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`;
                } else {
                    resBruto.textContent = '$ 0';
                    resBruto.className = 'font-mono font-bold text-slate-500 dark:text-slate-400';
                }
            }
            if (resDeltaDed) {
                if (deltaDeducciones !== 0) {
                    const dedSign = deltaDeducciones > 0 ? '- ' : '+ ';
                    resDeltaDed.textContent = dedSign + '$ ' + Math.abs(deltaDeducciones).toLocaleString('es-CO');
                    resDeltaDed.className = `font-mono font-bold ${deltaDeducciones > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'}`;
                } else {
                    resDeltaDed.textContent = '$ 0';
                    resDeltaDed.className = 'font-mono font-bold text-slate-500 dark:text-slate-400';
                }
            }
            if (resNovRow && resNovVal) {
                if (totalNovNeto !== 0 || (window.novedadesNotaAplicadas && window.novedadesNotaAplicadas.length > 0)) {
                    resNovRow.classList.remove('hidden');
                    resNovVal.textContent = (totalNovNeto >= 0 ? '+ ' : '- ') + '$ ' + Math.abs(totalNovNeto).toLocaleString('es-CO');
                    resNovVal.className = 'font-mono font-bold ' + (totalNovNeto >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
                } else {
                    resNovRow.classList.add('hidden');
                }
            }
            if (resAjuste) {
                resAjuste.textContent = (esPositivo ? '+ ' : '- ') + '$ ' + Math.abs(ajusteNetoReal).toLocaleString('es-CO');
                resAjuste.className = `font-mono font-black text-sm ${esPositivo ? 'text-teal-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`;
            }
            if (resOrig) {
                resOrig.textContent = '$ ' + floatval(liqOrig.total_a_pagar || 0).toLocaleString('es-CO');
            }
            if (resFinal) {
                resFinal.textContent = '$ ' + nuevoTotalPagar.toLocaleString('es-CO');
            }
        }

        // Variables de estado para Corrección Manual de Retención
        window.edicionRetencionActiva = false;
        window.retencionManualPct = null;
        window.anotacionRetencion = '';

        function activarEdicionRetencion() {
            window.edicionRetencionActiva = true;
            const liqOrig = liquidacionSeleccionadaActual || {};
            if (window.retencionManualPct === null) {
                window.retencionManualPct = floatval(liqOrig.ded_retencion_pct || 0);
            }
            recalcularTotalesAjuste(true);
            setTimeout(() => {
                const inp = document.getElementById('inputManualRetencionPct');
                if (inp) {
                    inp.focus();
                    inp.select();
                }
            }, 60);
        }

        function cancelarEdicionRetencion() {
            window.edicionRetencionActiva = false;
            window.retencionManualPct = null;
            window.anotacionRetencion = '';
            recalcularTotalesAjuste(true);
        }

        function alCambiarInputRetencionManual(val) {
            window.retencionManualPct = parseFloat(val) || 0;
            recalcularTotalesAjuste(false);
        }

        function recalcularTotalesAjuste(rebuildHtml = true) {
            let totalEstudiosSum = 0;
            let totalNovAdicion = 0;
            let totalNovDeduccion = 0;

            document.querySelectorAll('#itemsAjusteTableBody tr').forEach(tr => {
                const cant = parseFloat(tr.querySelector('.item-cantidad')?.value) || 0;
                const val  = parseFloat(tr.querySelector('.item-valor')?.value) || 0;
                const sub  = cant * val;
                const isNov = tr.getAttribute('data-is-novedad') === '1';
                const novTipo = tr.getAttribute('data-nov-tipo') || 'ADICION';

                if (isNov) {
                    if (novTipo === 'DEDUCCION') {
                        totalNovDeduccion += sub;
                    } else {
                        totalNovAdicion += sub;
                    }
                } else {
                    totalEstudiosSum += sub;
                }
            });

            const tipoSelect = document.getElementById('inputTipoNota');
            let tipo = tipoSelect ? tipoSelect.value : 'CREDITO';
            const totalNovNeto = totalNovAdicion - totalNovDeduccion;

            // Sincronizar automáticamente el tipo de nota si solo hay novedades de adición o deducción (sin exámenes nuevos)
            if (totalEstudiosSum === 0 && (totalNovAdicion > 0 || totalNovDeduccion > 0)) {
                if (totalNovNeto < 0 && tipoSelect && tipoSelect.value !== 'DEBITO') {
                    tipoSelect.value = 'DEBITO';
                    tipo = 'DEBITO';
                } else if (totalNovNeto > 0 && tipoSelect && tipoSelect.value !== 'CREDITO') {
                    tipoSelect.value = 'CREDITO';
                    tipo = 'CREDITO';
                }
            }

            const esCredito = (tipo === 'CREDITO');
            const deltaEstudios = esCredito ? totalEstudiosSum : -totalEstudiosSum;

            // Datos contables de la liquidación original
            const liqOrig = liquidacionSeleccionadaActual || {};
            const totalFacturaOrig = floatval(liqOrig.total_factura || 0);
            const totalPagarOrig   = floatval(liqOrig.total_a_pagar || 0);
            const totalDedOrig     = floatval(liqOrig.total_deducciones || 0);
            const retencionPctOrig = floatval(liqOrig.ded_retencion_pct || 0);
            const retencionOrig    = floatval(liqOrig.ded_retencion || 0);
            const rete383Orig      = floatval(liqOrig.ded_rete_383 || 0);
            const dedIbcOrig       = floatval(liqOrig.ded_ibc || 0);
            const dedSaludOrig     = floatval(liqOrig.ded_salud || 0);
            const dedPensionOrig   = floatval(liqOrig.ded_pension || 0);
            const dedArlOrig       = floatval(liqOrig.ded_arl || 0);
            const dedSolidaridadOrig = floatval(liqOrig.ded_solidaridad || 0);

            // Porcentaje de retención a aplicar (manual si el usuario activó la corrección)
            let retencionPct = retencionPctOrig;
            if (window.edicionRetencionActiva && window.retencionManualPct !== null) {
                retencionPct = floatval(window.retencionManualPct);
            }

            // Delta Bruto Total: Producción de exámenes + Novedades de la entidad (las novedades afectan el valor general/total factura)
            const deltaBruto = deltaEstudios + totalNovNeto;

            // 1. Nueva Factura Bruta (modificada por producción médica y novedades)
            const nuevaFactura = Math.max(0, totalFacturaOrig + deltaBruto);

            // 2. Recálculo de Retención en la Fuente sobre la nueva Factura Bruta
            let nuevaRetencion = retencionOrig;
            let deltaRetencion = 0;
            if (retencionPct > 0 || (window.edicionRetencionActiva && window.retencionManualPct !== null)) {
                nuevaRetencion = Math.round(nuevaFactura * (retencionPct / 100));
                deltaRetencion = nuevaRetencion - retencionOrig;
            } else if (rete383Orig > 0 && totalFacturaOrig > 0) {
                const tasaRete383 = rete383Orig / totalFacturaOrig;
                deltaRetencion = Math.round(deltaBruto * tasaRete383);
                nuevaRetencion = Math.max(0, rete383Orig + deltaRetencion);
            }

            // 3. Recálculo de Parafiscales y Fondo Solidaridad si estaban activos en la liquidación original
            let deltaParafiscales = 0;
            let nuevoSalud = dedSaludOrig;
            let nuevoPension = dedPensionOrig;
            let nuevoArl = dedArlOrig;
            let nuevoSolidaridad = dedSolidaridadOrig;
            if (dedIbcOrig > 0 && totalFacturaOrig > 0 && deltaBruto !== 0) {
                const factor = (nuevaFactura / totalFacturaOrig);
                nuevoSalud = Math.round(dedSaludOrig * factor);
                nuevoPension = Math.round(dedPensionOrig * factor);
                nuevoArl = Math.round(dedArlOrig * factor);
                nuevoSolidaridad = Math.round(dedSolidaridadOrig * factor);
                const parafiscalesOrig = dedSaludOrig + dedPensionOrig + dedArlOrig + dedSolidaridadOrig;
                const nuevosParafiscales = nuevoSalud + nuevoPension + nuevoArl + nuevoSolidaridad;
                deltaParafiscales = nuevosParafiscales - parafiscalesOrig;
            }

            // 4. Impacto en Deducciones de Ley
            const deltaDeducciones = deltaRetencion + deltaParafiscales;
            const nuevasDeducciones = Math.max(0, totalDedOrig + deltaDeducciones);

            // 5. Ajuste Neto Final al Médico:
            // deltaBruto (exámenes + novedades) menos el impacto de las deducciones de ley
            const ajusteNetoReal = deltaBruto - deltaDeducciones;
            const nuevoTotalPagar = Math.max(0, totalPagarOrig + ajusteNetoReal);

            window.calculoContableActual = {
                total_items_bruto: Math.abs(deltaBruto),
                total_estudios_bruto: totalEstudiosSum,
                delta_estudios: deltaEstudios,
                total_novedades_adicion: totalNovAdicion,
                total_novedades_deduccion: totalNovDeduccion,
                total_novedades_neto: totalNovNeto,
                delta_bruto: deltaBruto,
                total_factura_original: totalFacturaOrig,
                nueva_factura: nuevaFactura,
                retencion_pct: retencionPct,
                retencion_pct_original: retencionPctOrig,
                retencion_original: retencionOrig,
                nueva_retencion: nuevaRetencion,
                delta_retencion: deltaRetencion,
                nuevo_salud: nuevoSalud,
                nuevo_pension: nuevoPension,
                nuevo_arl: nuevoArl,
                nuevo_solidaridad: nuevoSolidaridad,
                solidaridad_original: dedSolidaridadOrig,
                parafiscales_original: (dedSaludOrig + dedPensionOrig + dedArlOrig + dedSolidaridadOrig),
                delta_parafiscales: deltaParafiscales,
                delta_deducciones: deltaDeducciones,
                deducciones_originales: totalDedOrig,
                nuevas_deducciones: nuevasDeducciones,
                total_pagar_original: totalPagarOrig,
                ajuste_neto_real: ajusteNetoReal,
                nuevo_total_pagar: nuevoTotalPagar
            };

            if (rebuildHtml) {
                renderDeduccionesContablesModal(liqOrig, window.calculoContableActual);
            } else {
                // Actualizar sólo valores en DOM para evitar pérdida de foco en inputs
                const reteDisplay = document.getElementById('reteRecalculadaDisplay');
                const reteDisplay2 = document.getElementById('reteRecalculadaDisplay2');
                const reteDeltaBadge = document.getElementById('reteDeltaBadgeDisplay');
                const totalDisplay = document.getElementById('modalTotalDeduccionesDisplay');
                const deltaBadge = document.getElementById('modalDeduccionesDeltaBadge');
                const resBruto = document.getElementById('resumenBrutoAjuste');
                const resDeltaDed = document.getElementById('resumenDeltaDeducciones');
                const resNovRow = document.getElementById('resumenNovedadesRow');
                const resNovVal = document.getElementById('resumenNovedadesAjuste');
                const resAjuste = document.getElementById('resumenValorAjuste');
                const resFinal = document.getElementById('resumenTotalAjustado');
                const badgeTipo = document.getElementById('modalBadgeTipoAjuste');
                const esPositivo = ajusteNetoReal >= 0;

                if (reteDisplay) reteDisplay.textContent = '- $ ' + nuevaRetencion.toLocaleString('es-CO');
                if (reteDisplay2) reteDisplay2.textContent = '- $ ' + nuevaRetencion.toLocaleString('es-CO');
                if (reteDeltaBadge) {
                    if (deltaRetencion !== 0) {
                        reteDeltaBadge.textContent = `Delta: ${deltaRetencion > 0 ? '+ ' : '- '}$ ${Math.abs(deltaRetencion).toLocaleString('es-CO')}`;
                        reteDeltaBadge.classList.remove('hidden');
                    } else {
                        reteDeltaBadge.classList.add('hidden');
                    }
                }
                if (totalDisplay) totalDisplay.textContent = '- $ ' + nuevasDeducciones.toLocaleString('es-CO');
                if (deltaBadge) {
                    if (deltaDeducciones !== 0) {
                        deltaBadge.textContent = `Delta: ${deltaDeducciones > 0 ? '+ ' : '- '}$ ${Math.abs(deltaDeducciones).toLocaleString('es-CO')}`;
                        deltaBadge.classList.remove('hidden');
                    } else {
                        deltaBadge.classList.add('hidden');
                    }
                }
                if (badgeTipo) {
                    badgeTipo.textContent = esPositivo ? 'CRÉDITO (+)' : 'DÉBITO (-)';
                    badgeTipo.className = `text-[10px] font-mono font-bold px-2 py-0.5 rounded-full ${esPositivo ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-200 dark:border-rose-700'}`;
                }
                if (resBruto) {
                    if (deltaEstudios !== 0) {
                        resBruto.textContent = (deltaEstudios > 0 ? '+ ' : '- ') + '$ ' + Math.abs(deltaEstudios).toLocaleString('es-CO');
                        resBruto.className = `font-mono font-black ${deltaEstudios > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`;
                    } else {
                        resBruto.textContent = '$ 0';
                        resBruto.className = 'font-mono font-bold text-slate-500 dark:text-slate-400';
                    }
                }
                if (resDeltaDed) {
                    if (deltaDeducciones !== 0) {
                        const dedSign = deltaDeducciones > 0 ? '- ' : '+ ';
                        resDeltaDed.textContent = dedSign + '$ ' + Math.abs(deltaDeducciones).toLocaleString('es-CO');
                        resDeltaDed.className = `font-mono font-bold ${deltaDeducciones > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'}`;
                    } else {
                        resDeltaDed.textContent = '$ 0';
                        resDeltaDed.className = 'font-mono font-bold text-slate-500 dark:text-slate-400';
                    }
                }
                if (resNovRow && resNovVal) {
                    if (totalNovNeto !== 0 || (window.novedadesNotaAplicadas && window.novedadesNotaAplicadas.length > 0)) {
                        resNovRow.classList.remove('hidden');
                        resNovVal.textContent = (totalNovNeto >= 0 ? '+ ' : '- ') + '$ ' + Math.abs(totalNovNeto).toLocaleString('es-CO');
                        resNovVal.className = 'font-mono font-bold ' + (totalNovNeto >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
                    } else {
                        resNovRow.classList.add('hidden');
                    }
                }
                if (resAjuste) {
                    resAjuste.textContent = (esPositivo ? '+ ' : '- ') + '$ ' + Math.abs(ajusteNetoReal).toLocaleString('es-CO');
                    resAjuste.className = `font-mono font-black text-sm ${esPositivo ? 'text-teal-700 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`;
                }
                if (resFinal) {
                    resFinal.textContent = '$ ' + nuevoTotalPagar.toLocaleString('es-CO');
                }
            }
        }

        async function guardarNuevaNotaAjuste(e) {
            e.preventDefault();

            const liqId = document.getElementById('selectLiquidacionBase').value;
            if (!liqId) {
                SwalCustom.fire({ icon: 'warning', title: 'Liquidación requerida', text: 'Por favor seleccione la liquidación original que desea ajustar.' });
                return;
            }

            const tipoInput = document.getElementById('inputTipoNota').value;
            const motivo = document.getElementById('inputMotivoAjuste').value.trim();
            const liqOrig = liquidacionSeleccionadaActual || {};
            const pctOrig = floatval(liqOrig.ded_retencion_pct || 0);
            const pctNuevo = (window.edicionRetencionActiva && window.retencionManualPct !== null) ? floatval(window.retencionManualPct) : pctOrig;
            const cambioRete = (window.edicionRetencionActiva && pctOrig !== pctNuevo);
            const anotacionRete = (window.anotacionRetencion || '').trim();

            if (cambioRete && anotacionRete.length < 5) {
                SwalCustom.fire({
                    icon: 'warning',
                    title: 'Anotación Requerida',
                    text: `Ha corregido el porcentaje de retención (de ${pctOrig}% a ${pctNuevo}%). Debe ingresar una anotación o justificación obligatoria para los registros de auditoría y logs del sistema.`
                });
                const inp = document.getElementById('inputAnotacionRete');
                if (inp) {
                    inp.focus();
                    inp.classList.add('ring-2', 'ring-rose-500');
                }
                return;
            }

            const items = [];
            let totalItems = 0;
            document.querySelectorAll('#itemsAjusteTableBody tr').forEach(tr => {
                const sede = tr.querySelector('.item-sede').value.trim();
                const cups = tr.querySelector('.item-cups').value.trim().toUpperCase();
                const tipoPac = tr.querySelector('.item-tipo-paciente') ? tr.querySelector('.item-tipo-paciente').value : 'E';
                const concepto = tr.querySelector('.item-concepto').value.trim();
                const cant = parseFloat(tr.querySelector('.item-cantidad').value) || 0;
                const val  = parseFloat(tr.querySelector('.item-valor').value) || 0;
                const sub  = cant * val;
                const isNov = tr.getAttribute('data-is-novedad') === '1';
                const novTipo = tr.getAttribute('data-nov-tipo') || 'ADICION';
                const novCod = tr.getAttribute('data-nov-codigo') || '';
                totalItems += sub;
                if (concepto || cups) {
                    items.push({
                        sede: sede || 'GENERAL',
                        cups: cups,
                        tipo_paciente: tipoPac,
                        concepto: concepto || cups,
                        cantidad: cant,
                        valor_unitario: val,
                        subtotal: sub,
                        is_novedad: isNov ? 1 : 0,
                        nov_tipo: novTipo,
                        nov_codigo: novCod
                    });
                }
            });

            // Si se corrigió retención pero no hay ítems de exámenes agregados, agregar un concepto contable automático
            if (cambioRete && (items.length === 0 || totalItems <= 0)) {
                items.push({
                    sede: 'GENERAL',
                    cups: 'REAJUSTE',
                    tipo_paciente: 'E',
                    concepto: `Reajuste contable por corrección de % de retención en la fuente (${pctOrig}% a ${pctNuevo}%)`,
                    cantidad: 1,
                    valor_unitario: 0,
                    subtotal: 0
                });
            } else if (items.length === 0 && !cambioRete) {
                SwalCustom.fire({ icon: 'warning', title: 'Valores inválidos', text: 'Debe ingresar al menos un concepto de ajuste con valor superior a cero o corregir el porcentaje de retención.' });
                return;
            }

            const calc = window.calculoContableActual || {};
            const ajusteNetoReal = (calc.ajuste_neto_real !== undefined) ? calc.ajuste_neto_real : (tipoInput === 'CREDITO' ? totalItems : -totalItems);
            const valorAjusteNeto = Math.abs(ajusteNetoReal);
            const tipoEfectivo = (ajusteNetoReal < 0) ? 'DEBITO' : 'CREDITO';

            let extraHtmlAviso = '';
            if (cambioRete) {
                extraHtmlAviso = `
                    <div class="p-3 rounded-xl bg-amber-500/15 border border-amber-500/40 text-amber-200 text-xs text-left my-2">
                        <div class="font-bold flex items-center gap-1.5 text-amber-300 mb-1">
                            <span class="material-symbols-outlined text-sm">edit_note</span>
                            <span>Corrección de Retención a Registrar en Logs:</span>
                        </div>
                        <p class="text-[11px]">Porcentaje corregido: <strong>${pctOrig}%</strong> → <strong class="text-white">${pctNuevo}%</strong>.</p>
                        <p class="text-[10px] text-amber-300/90 italic mt-1 bg-amber-950/60 p-1.5 rounded">"${htmlspecialchars(anotacionRete)}"</p>
                    </div>
                `;
            }

            let extraHtmlNovAviso = '';
            if (window.novedadesNotaAplicadas && window.novedadesNotaAplicadas.length > 0) {
                extraHtmlNovAviso = `
                    <div class="flex justify-between text-xs text-amber-400">
                        <span>Novedades Registradas:</span>
                        <span class="font-bold">${window.novedadesNotaAplicadas.length} novedades (${(calc.total_novedades_neto >= 0 ? '+ ' : '- ')}$ ${Math.abs(calc.total_novedades_neto || 0).toLocaleString('es-CO')})</span>
                    </div>
                `;
            }

            const deltaEstudios = calc.delta_estudios !== undefined ? calc.delta_estudios : (calc.total_estudios_bruto || 0);
            const deltaBruto = calc.delta_bruto !== undefined ? calc.delta_bruto : (deltaEstudios + (calc.total_novedades_neto || 0));

            const confirmModal = await SwalCustom.fire({
                title: '¿Registrar Nota de Ajuste?',
                icon: 'question',
                html: `
                    <div class="p-4 rounded-2xl bg-slate-800 border border-slate-700 text-left my-2 space-y-2">
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-400">Liquidación Base:</span>
                            <span class="font-bold text-white">#${liqId}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-400">Tipo de Ajuste:</span>
                            <span class="font-bold ${tipoEfectivo === 'CREDITO' ? 'text-emerald-400' : 'text-rose-400'}">${tipoEfectivo === 'CREDITO' ? 'CRÉDITO (A Favor)' : 'DÉBITO (Descuento)'}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-400">Subtotal Exámenes (Bruto):</span>
                            <span class="font-mono text-slate-200">${(deltaEstudios > 0 ? '+ ' : (deltaEstudios < 0 ? '- ' : ''))}$ ${Math.abs(deltaEstudios).toLocaleString('es-CO')}</span>
                        </div>
                        ${extraHtmlNovAviso}
                        <div class="flex justify-between text-xs">
                            <span class="text-slate-400">Impacto en Deducciones:</span>
                            <span class="font-mono text-rose-400">${(calc.delta_deducciones > 0 ? '- ' : (calc.delta_deducciones < 0 ? '+ ' : ''))}$ ${Math.abs(calc.delta_deducciones || 0).toLocaleString('es-CO')}</span>
                        </div>
                        <div class="flex justify-between text-xs pt-1 border-t border-slate-700">
                            <span class="text-slate-300 font-bold">Ajuste Neto a Pagar:</span>
                            <strong class="font-mono ${tipoEfectivo === 'CREDITO' ? 'text-emerald-400' : 'text-rose-400'} font-black text-sm">${(ajusteNetoReal >= 0 ? '+ ' : '- ')}$ ${valorAjusteNeto.toLocaleString('es-CO')}</strong>
                        </div>
                    </div>
                    ${extraHtmlAviso}
                    <p class="text-xs text-slate-300 text-center">La liquidación original #${liqId} permanecerá intacta en el sistema.</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'Sí, Registrar Nota',
                cancelButtonText: 'Cancelar'
            });

            if (!confirmModal.isConfirmed) return;

            const btn = document.getElementById('btnGuardarNota');
            btn.disabled = true;
            btn.innerHTML = `<span class="material-symbols-outlined text-sm animate-spin">sync</span><span>Guardando...</span>`;

            try {
                const payload = {
                    action: 'guardar_nota',
                    liquidacion_id: liqId,
                    tipo_nota: tipoEfectivo,
                    motivo_ajuste: motivo,
                    valor_ajuste: (tipoEfectivo === 'DEBITO') ? -valorAjusteNeto : valorAjusteNeto,
                    subtotal_bruto: Math.abs(deltaBruto),
                    deducciones_ajuste: Math.abs(calc.delta_deducciones || 0),
                    novedades_json: window.novedadesNotaAplicadas && window.novedadesNotaAplicadas.length > 0 ? window.novedadesNotaAplicadas : null,
                    total_novedades: (calc.total_novedades_neto || 0),
                    modifico_retencion: cambioRete ? 1 : 0,
                    retencion_pct_original: pctOrig,
                    retencion_pct_nuevo: pctNuevo,
                    anotacion_retencion: anotacionRete,
                    detalles_ajuste_json: {
                        items: items,
                        calculo_contable: calc,
                        correccion_retencion: cambioRete ? {
                            activo: true,
                            pct_original: pctOrig,
                            pct_nuevo: pctNuevo,
                            anotacion: anotacionRete
                        } : null
                    }
                };

                const res = await fetch('notas_ajuste.php?action=guardar_nota', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await res.json();

                if (result.success) {
                    cerrarModalCrearNota();
                    SwalCustom.fire({
                        icon: 'success',
                        title: '¡Nota de Ajuste Registrada!',
                        text: `Se ha generado la nota ${result.numero_nota} vinculada a la liquidación #${liqId}.`,
                        timer: 2200,
                        showConfirmButton: false
                    });
                    cargarNotas();
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error al Guardar', text: result.error || 'Error desconocido' });
                }
            } catch(err) {
                console.error(err);
                SwalCustom.fire({ icon: 'error', title: 'Error de Conexión', text: 'Ocurrió un error al enviar la solicitud al servidor.' });
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<span class="material-symbols-outlined text-sm">check_circle</span><span>Guardar Nota de Ajuste</span>`;
            }
        }

        // 4. Ver Detalle de Nota de Ajuste
        async function verDetalleNota(id) {
            document.getElementById('modalDetalleNota').classList.remove('hidden');
            const body = document.getElementById('detalleNotaBody');
            body.innerHTML = `
                <div class="py-16 text-center text-slate-400">
                    <span class="material-symbols-outlined text-3xl animate-spin block mb-2 text-tertiary">sync</span>
                    <p class="font-bold text-xs">Cargando desglose de la nota de ajuste...</p>
                </div>
            `;
            document.getElementById('detalleNotaFooterActions').innerHTML = '';

            try {
                const res = await fetch(`notas_ajuste.php?action=ver_detalle_nota&id=${id}`);
                const result = await res.json();

                if (!result.success) {
                    body.innerHTML = `<div class="p-6 text-center text-rose-500 font-bold">${result.error || 'Error'}</div>`;
                    return;
                }

                const n = result.data;
                const valAjuste = floatval(n.valor_ajuste || 0);
                const esCredito = (n.tipo_nota === 'CREDITO' && valAjuste >= 0) || (valAjuste > 0 && n.tipo_nota !== 'DEBITO');

                document.getElementById('detalleNotaTitulo').textContent = `Nota de Ajuste ${n.numero_nota || 'NA-' + n.id}`;
                document.getElementById('detalleNotaSubtitulo').textContent = `${n.medico_nombre} (CC: ${n.medico_cedula}) • Periodo: ${n.periodo_desde} al ${n.periodo_hasta}`;

                let itemsList = [];
                let calculoContable = null;
                try {
                    const rawDet = typeof n.detalles_ajuste_json === 'string' ? JSON.parse(n.detalles_ajuste_json) : (n.detalles_ajuste_json || []);
                    if (Array.isArray(rawDet)) {
                        itemsList = rawDet;
                    } else if (rawDet && typeof rawDet === 'object') {
                        itemsList = rawDet.items || [];
                        calculoContable = rawDet.calculo_contable || null;
                    }
                } catch(e) {}

                let itemsRowsHtml = '';
                itemsList.forEach((it, idx) => {
                    const cupsTag = it.cups ? `<span class="font-mono text-tertiary font-bold text-[10px] mr-1.5 bg-teal-50 dark:bg-slate-800 px-1.5 py-0.5 rounded border border-teal-500/20">[${htmlspecialchars(it.cups)}]</span>` : '';
                    const esParticular = (it.tipo_paciente === 'P' || it.tipo_paciente === 'PARTICULAR');
                    const badgeTipoPac = esParticular 
                        ? `<span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-purple-100 text-purple-800 dark:bg-purple-950/80 dark:text-purple-300 border border-purple-200 dark:border-purple-800">Particular</span>`
                        : `<span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-blue-100 text-blue-800 dark:bg-blue-950/80 dark:text-blue-300 border border-blue-200 dark:border-blue-800">Empresa</span>`;

                    itemsRowsHtml += `
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="py-2.5 px-3 font-bold text-slate-700 dark:text-slate-300">${htmlspecialchars(it.sede || 'GENERAL')}</td>
                            <td class="py-2.5 px-3">${badgeTipoPac}</td>
                            <td class="py-2.5 px-3 text-slate-800 dark:text-slate-200 font-medium">${cupsTag}${htmlspecialchars(it.concepto || 'CONCEPTO')}</td>
                            <td class="py-2.5 px-3 text-center font-bold text-slate-700 dark:text-slate-300">${it.cantidad || 1}</td>
                            <td class="py-2.5 px-3 text-right font-mono text-slate-700 dark:text-slate-300">$ ${floatval(it.valor_unitario || 0).toLocaleString('es-CO')}</td>
                            <td class="py-2.5 px-3 text-right font-mono font-black text-slate-900 dark:text-white">$ ${floatval(it.subtotal || 0).toLocaleString('es-CO')}</td>
                        </tr>
                    `;
                });

                // Detección de Corrección de Retención para Detalle
                let correccionReteBannerHtml = '';
                try {
                    const rawDet = typeof n.detalles_ajuste_json === 'string' ? JSON.parse(n.detalles_ajuste_json) : (n.detalles_ajuste_json || {});
                    const cr = (rawDet && typeof rawDet === 'object') ? (rawDet.correccion_retencion || (rawDet.calculo_contable ? rawDet.calculo_contable.correccion_retencion : null)) : null;
                    if (cr && cr.activo) {
                        correccionReteBannerHtml = `
                            <div class="mb-4 p-3.5 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-700/80 flex items-start gap-3 shadow-xs">
                                <div class="w-8 h-8 rounded-xl bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-lg">edit_note</span>
                                </div>
                                <div class="min-w-0 flex-1 text-xs">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="font-bold text-amber-950 dark:text-amber-200 font-outfit uppercase tracking-wider text-[11px]">Corrección Manual de % Retención</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-mono font-black bg-amber-200 dark:bg-amber-900 text-amber-900 dark:text-amber-200">
                                            ${cr.pct_original}% → ${cr.pct_nuevo}%
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-amber-900/90 dark:text-amber-300 mt-1 font-medium italic">
                                        "${htmlspecialchars(cr.anotacion || 'Ajuste manual de porcentaje')}"
                                    </p>
                                </div>
                            </div>
                        `;
                    }
                } catch(e) {}

                // Logs Timeline
                let logsHtml = '';
                (n.logs || []).forEach(l => {
                    let stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950/90 dark:text-amber-300 border border-amber-200 dark:border-amber-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-clock"></i> ${l.accion}</span>`;
                    if (l.accion === 'APROBACIÓN' || l.accion === 'APROBADA') {
                        stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950/90 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> APROBACIÓN</span>`;
                    } else if (l.accion === 'ANULACIÓN' || l.accion === 'ANULADA' || l.accion === 'RECHAZO') {
                        stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-rose-100 text-rose-800 dark:bg-rose-950/90 dark:text-rose-300 border border-rose-200 dark:border-rose-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-ban"></i> ${l.accion}</span>`;
                    } else if (l.accion === 'CREACIÓN') {
                        stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-sky-100 text-sky-800 dark:bg-sky-950/90 dark:text-sky-300 border border-sky-200 dark:border-sky-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-plus-circle"></i> CREACIÓN</span>`;
                    } else if (l.accion.includes('RETENCION')) {
                        stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-amber-100 text-amber-800 dark:bg-amber-950/90 dark:text-amber-300 border border-amber-300 dark:border-amber-700 inline-flex items-center gap-1.5"><i class="fa-solid fa-pen-to-square"></i> CORRECCIÓN RETENCIÓN</span>`;
                    } else if (l.modulo === 'CORREO' || l.accion.includes('CORREO') || l.accion.includes('NOTIFICACION')) {
                        stBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-black bg-indigo-100 text-indigo-800 dark:bg-indigo-950/90 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700/80 inline-flex items-center gap-1.5"><i class="fa-solid fa-envelope"></i> NOTIFICACIÓN CORREO</span>`;
                    }
                    logsHtml += `
                        <div class="p-3.5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/50 shadow-xs flex flex-col gap-1.5 text-xs">
                            <div class="flex items-center justify-between">
                                ${stBadge}
                                <span class="text-[10px] text-slate-500 dark:text-slate-400 font-mono">${l.fecha_registro}</span>
                            </div>
                            <div class="text-slate-700 dark:text-slate-300">
                                <strong class="font-bold text-slate-900 dark:text-slate-100">Usuario / Emisor:</strong> ${htmlspecialchars(l.usuario_nombre)} (${htmlspecialchars(l.usuario_rol || 'SISTEMA')})
                            </div>
                            ${l.observaciones ? `<div class="italic text-slate-600 dark:text-slate-300 mt-1 bg-slate-50 dark:bg-slate-800/60 p-2.5 rounded-xl border border-slate-200/70 dark:border-slate-800 text-[11px]">"${htmlspecialchars(l.observaciones)}"</div>` : ''}
                        </div>
                    `;
                });

                const liqOrig = n.liquidacion_original || {};
                let sedesObj = {};
                try {
                    sedesObj = typeof liqOrig.resumen_sedes_json === 'string' ? JSON.parse(liqOrig.resumen_sedes_json) : (liqOrig.resumen_sedes_json || {});
                } catch(e) {}

                // 1. Construir tarjetas de sedes de la liquidación original
                let sedesCardsHtml = '';
                let resumenSedesRowsHtml = '';
                let totalFacturaSum = 0;

                const sedesEntries = Object.entries(sedesObj);
                if (sedesEntries.length > 0) {
                    sedesEntries.forEach(([sKey, sData]) => {
                        const conceptos = sData.conceptos || {};
                        let conceptosRowsHtml = '';
                        let totalSedeVal = parseFloat(sData.total || 0);
                        let totalSedeCant = parseInt(sData.examenes_count || (Array.isArray(sData.examenes) ? sData.examenes.length : (sData.cant || 0)));

                        const conceptosEntries = Object.entries(conceptos);
                        if (conceptosEntries.length > 0) {
                            conceptosEntries.forEach(([cNombre, cObj]) => {
                                const cCant = parseInt(cObj.cantidad || cObj.cant || 0);
                                const cVal = parseFloat(cObj.total || cObj.valor || 0);

                                const esBoni = (cObj.es_bonificacion === true || cNombre.includes('BONIFICACI') || cNombre.includes('BONI'));

                                if (esBoni) {
                                    conceptosRowsHtml += `
                                        <tr class="border-b border-amber-200 dark:border-amber-900/60 bg-amber-50/70 dark:bg-amber-950/40">
                                            <td class="py-1.5 px-2 text-[11px] font-bold text-amber-950 dark:text-amber-200">
                                                <div class="flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-xs">military_tech</span>
                                                    <span class="truncate max-w-[180px] block" title="${htmlspecialchars(cNombre)}">${htmlspecialchars(cNombre)}</span>
                                                    <span class="px-1 py-0.2 rounded text-[8px] font-black uppercase bg-amber-200 dark:bg-amber-900 text-amber-900 dark:text-amber-100">Bono</span>
                                                </div>
                                            </td>
                                            <td class="py-1.5 px-2 text-right font-mono font-black text-amber-900 dark:text-amber-200 text-[11px]">${cCant.toLocaleString('es-CO')} bono(s)</td>
                                            <td class="py-1.5 px-2 text-right font-mono font-black text-amber-600 dark:text-amber-400 text-[11px]">+$ ${cVal.toLocaleString('es-CO')}</td>
                                        </tr>
                                    `;
                                } else {
                                    conceptosRowsHtml += `
                                        <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                            <td class="py-1.5 px-2 text-[11px] font-medium text-slate-700 dark:text-slate-300">
                                                <span class="truncate max-w-[180px] block" title="${htmlspecialchars(cNombre)}">${htmlspecialchars(cNombre)}</span>
                                            </td>
                                            <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-[11px]">${cCant.toLocaleString('es-CO')}</td>
                                            <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-900 dark:text-white text-[11px]">$ ${cVal.toLocaleString('es-CO')}</td>
                                        </tr>
                                    `;
                                }
                            });
                        } else {
                            conceptosRowsHtml += `
                                <tr class="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                    <td class="py-1.5 px-2 text-[11px] font-medium text-slate-700 dark:text-slate-300">
                                        <span>RXSI</span>
                                    </td>
                                    <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-800 dark:text-slate-200 text-[11px]">${totalSedeCant.toLocaleString('es-CO')}</td>
                                    <td class="py-1.5 px-2 text-right font-mono font-bold text-slate-900 dark:text-white text-[11px]">$ ${totalSedeVal.toLocaleString('es-CO')}</td>
                                </tr>
                            `;
                        }

                        totalFacturaSum += totalSedeVal;

                        sedesCardsHtml += `
                            <div class="border border-slate-200 dark:border-slate-700/80 rounded-xl overflow-hidden flex flex-col bg-white dark:bg-slate-900 shadow-xs">
                                <div class="bg-slate-100 dark:bg-slate-800 py-1.5 px-3 border-b border-slate-200 dark:border-slate-700 font-bold text-xs text-slate-800 dark:text-slate-200 font-outfit uppercase flex items-center justify-between gap-2">
                                    <span class="truncate flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-sm text-tertiary">apartment</span>
                                        ${htmlspecialchars(sKey)}
                                    </span>
                                    <span class="text-[10px] font-mono font-bold text-slate-500">$ ${totalSedeVal.toLocaleString('es-CO')}</span>
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

                        resumenSedesRowsHtml += `
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <td class="py-1.5 px-3 text-[11px] font-medium text-slate-700 dark:text-slate-300">${htmlspecialchars(sKey)}</td>
                                <td class="py-1.5 px-3 text-right font-mono font-bold text-slate-900 dark:text-white">$ ${totalSedeVal.toLocaleString('es-CO')}</td>
                            </tr>
                        `;
                    });
                }

                if (totalFacturaSum === 0) totalFacturaSum = parseFloat(liqOrig.total_factura || n.total_original || 0);

                // Deducciones de la liquidación original
                const dedAfc         = parseFloat(liqOrig.ded_afc || 0);
                const dedSolidaridad = parseFloat(liqOrig.ded_solidaridad || 0);
                const dedIbc         = parseFloat(liqOrig.ded_ibc || 0);
                const dedSalud       = parseFloat(liqOrig.ded_salud || 0);
                const dedPension     = parseFloat(liqOrig.ded_pension || 0);
                const dedArl         = parseFloat(liqOrig.ded_arl || 0);
                const dedRete383Info = parseFloat(liqOrig.ded_rete_383_info || 0);
                const dedRete383     = parseFloat(liqOrig.ded_rete_383 || 0);
                const dedRetencionPct= parseFloat(liqOrig.ded_retencion_pct || 0);
                const dedRetencion   = parseFloat(liqOrig.ded_retencion || 0);
                const totalDeducciones = parseFloat(liqOrig.total_deducciones || 0);
                const totalAPagarOrig= parseFloat(liqOrig.total_a_pagar || n.total_original || 0);

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
                    const solPct = parseFloat(liqOrig.ded_solidaridad_pct || 0);
                    const solPctText = solPct > 0 ? ` (${solPct}%)` : '';
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2">
                            <label class="text-[11px] font-semibold text-slate-600 dark:text-slate-300">MENOS APORTES FONDO SOLIDARIDAD${solPctText}</label>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200 text-xs">$ ${dedSolidaridad.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }

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

                if (dedPension > 0) {
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400">
                            <label class="text-[11px] font-medium">MENOS APORTES PENSIÓN MES</label>
                            <span class="font-mono font-bold text-xs">- $ ${dedPension.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }

                if (dedRete383 > 0) {
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400 pt-1 border-t border-slate-100 dark:border-slate-800">
                            <label class="text-[11px] font-semibold">RETENCIÓN ART 383</label>
                            <span class="font-mono font-bold text-xs">- $ ${dedRete383.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                } else if (dedRetencion > 0) {
                    deduccionesRowsHtml += `
                        <div class="flex items-center justify-between gap-2 text-rose-600 dark:text-rose-400 pt-1 border-t border-slate-100 dark:border-slate-800">
                            <label class="text-[11px] font-semibold">RETENCIÓN (${dedRetencionPct}%)</label>
                            <span class="font-mono font-bold text-xs">- $ ${dedRetencion.toLocaleString('es-CO')}</span>
                        </div>
                    `;
                }

                // Estado Badge de la Nota
                let badgeEstadoNota = '';
                if (n.estado === 'APROBADA') {
                    badgeEstadoNota = `<span class="px-3 py-1 rounded-full text-xs font-black bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700/80 inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">check_circle</span> APROBADA</span>`;
                } else if (n.estado === 'ANULADA') {
                    badgeEstadoNota = `<span class="px-3 py-1 rounded-full text-xs font-black bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-400 border border-rose-300 dark:border-rose-700/80 inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">cancel</span> ANULADA</span>`;
                } else {
                    badgeEstadoNota = `<span class="px-3 py-1 rounded-full text-xs font-black bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-400 border border-amber-300 dark:border-amber-700/80 inline-flex items-center gap-1.5"><span class="material-symbols-outlined text-xs">schedule</span> PENDIENTE</span>`;
                }

                body.innerHTML = `
                    <!-- 1. Tarjeta Comparativa de Impacto Contable -->
                    <div class="p-5 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-slate-800 dark:text-white">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-teal-600 dark:text-teal-400 text-lg">account_balance</span>
                                <span class="font-outfit font-black text-xs uppercase tracking-wider text-slate-900 dark:text-white">IMPACTO FINANCIERO Y CONSOLIDACIÓN CONTABLE</span>
                            </div>
                            <div>
                                ${badgeEstadoNota}
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3.5 text-center">
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 flex flex-col justify-center items-center">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Total Liquidación Base (#${n.liquidacion_id})</span>
                                <span class="text-lg sm:text-xl font-black font-outfit text-slate-800 dark:text-slate-100 mt-1">$ ${floatval(n.total_original).toLocaleString('es-CO')}</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 flex flex-col justify-center items-center">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Valor Ajuste (${n.tipo_nota})</span>
                                <span class="text-lg sm:text-xl font-black font-outfit mt-1 ${esCredito ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}">
                                    ${esCredito ? '+ ' : ''}$ ${Math.abs(valAjuste).toLocaleString('es-CO')}
                                </span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-teal-50 dark:bg-teal-950/60 border border-teal-200 dark:border-emerald-500/60 flex flex-col justify-center items-center">
                                <span class="text-[10px] font-black uppercase tracking-wider text-teal-800 dark:text-emerald-400">Nuevo Saldo Consolidado</span>
                                <span class="text-xl sm:text-2xl font-black font-outfit text-teal-700 dark:text-emerald-300 mt-1">$ ${floatval(n.total_ajustado).toLocaleString('es-CO')}</span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. BLOQUE DESTACADO: NOTA DE AJUSTE (LO QUE SE AJUSTÓ) -->
                    <div class="border-2 border-teal-500/40 dark:border-teal-500/30 rounded-3xl p-5 bg-gradient-to-br from-teal-50/50 via-white to-slate-50 dark:from-slate-900 dark:via-slate-900 dark:to-teal-950/20 shadow-md space-y-4">
                        <div class="flex items-center justify-between pb-3 border-b border-teal-200/60 dark:border-slate-800">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-teal-500/10 text-teal-600 dark:text-teal-400 flex items-center justify-center font-bold">
                                    <span class="material-symbols-outlined text-lg">receipt_long</span>
                                </div>
                                <div>
                                    <h4 class="font-outfit font-black text-sm uppercase tracking-wider text-slate-900 dark:text-white">
                                        Conceptos e Ítems Agregados en este Ajuste (${itemsList.length})
                                    </h4>
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400">Desglose exacto de los ítems médicos modificados</p>
                                </div>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-teal-100 dark:bg-teal-950 text-teal-800 dark:text-teal-300 border border-teal-300 dark:border-teal-800 font-mono">
                                ${n.numero_nota || 'NA-' + n.id}
                            </span>
                        </div>

                        <!-- Motivo / Justificación -->
                        <div class="p-3.5 rounded-2xl bg-white dark:bg-slate-800/80 border border-teal-100 dark:border-slate-700/80 space-y-1">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Motivo / Justificación del Ajuste:</span>
                            <p class="text-xs font-semibold text-slate-800 dark:text-slate-200 leading-relaxed">${htmlspecialchars(n.motivo_ajuste)}</p>
                        </div>

                        <!-- Tabla de Ítems del Ajuste -->
                        <div class="border border-slate-200 dark:border-slate-700 rounded-2xl overflow-hidden shadow-xs bg-white dark:bg-slate-900">
                            <table class="w-full text-left text-xs border-collapse">
                                <thead>
                                    <tr class="bg-slate-100 dark:bg-slate-800 text-[10px] font-bold text-slate-600 dark:text-slate-300 uppercase border-b border-slate-200 dark:border-slate-700">
                                        <th class="py-2.5 px-3">Sede</th>
                                        <th class="py-2.5 px-3">Tipo Paciente</th>
                                        <th class="py-2.5 px-3">Concepto / Estudio (Tarifario)</th>
                                        <th class="py-2.5 px-3 text-center">Cant</th>
                                        <th class="py-2.5 px-3 text-right">Valor Unit.</th>
                                        <th class="py-2.5 px-3 text-right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    ${itemsRowsHtml}
                                </tbody>
                                <tfoot>
                                    <tr class="bg-slate-50 dark:bg-slate-800/80 border-t-2 border-slate-200 dark:border-slate-700 font-bold text-xs">
                                        <td colspan="5" class="py-2 px-3 text-right uppercase text-[10px] font-black text-slate-500">TOTAL AJUSTE (${n.tipo_nota})</td>
                                        <td class="py-2 px-3 text-right font-mono font-black text-sm ${esCredito ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}">
                                            ${esCredito ? '+ ' : '- '}$ ${Math.abs(valAjuste).toLocaleString('es-CO')}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        ${(() => {
                            let novsArr = [];
                            try {
                                novsArr = typeof n.novedades_json === 'string' ? JSON.parse(n.novedades_json) : (n.novedades_json || []);
                            } catch(e) {}
                            if (!Array.isArray(novsArr) || novsArr.length === 0) return '';
                            let novBadges = novsArr.map(nov => {
                                const esAd = (nov.tipo === 'ADICION');
                                return `<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold ${esAd ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300' : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border border-rose-300'}">
                                    <span>${esAd ? '+ ADICIÓN' : '- DEDUCCIÓN'}</span>
                                    <span class="font-mono text-[10px]">${nov.codigo}</span>
                                    <span>${htmlspecialchars(nov.nombre)}:</span>
                                    <strong class="font-mono">$ ${floatval(nov.valor || 0).toLocaleString('es-CO')}</strong>
                                    ${nov.observacion ? `<span class="text-[10px] opacity-75">(${htmlspecialchars(nov.observacion)})</span>` : ''}
                                </span>`;
                            }).join('');
                            return `
                                <div class="p-3.5 rounded-2xl bg-amber-50/80 dark:bg-amber-950/40 border border-amber-300 dark:border-amber-800 space-y-2">
                                    <span class="text-[10px] font-black uppercase tracking-wider text-amber-900 dark:text-amber-300 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-sm">campaign</span>
                                        Novedades de la Entidad Registradas en esta Nota
                                    </span>
                                    <div class="flex flex-wrap gap-2">${novBadges}</div>
                                </div>
                            `;
                        })()}

                        ${calculoContable ? `
                        <!-- Desglose de Deducciones del Ajuste -->
                        <div class="p-4 rounded-2xl bg-white dark:bg-slate-800/80 border border-teal-200/60 dark:border-slate-700/80 space-y-3">
                            <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-700">
                                <span class="font-outfit font-black text-xs uppercase tracking-wider text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-teal-500 text-sm">account_balance_wallet</span>
                                    Recálculo de Deducciones y Balance Contable
                                </span>
                                <span class="text-[10px] font-mono font-bold text-teal-600 dark:text-teal-400 bg-teal-50 dark:bg-teal-950 px-2 py-0.5 rounded-full border border-teal-200 dark:border-teal-800">
                                    Retención Base: ${calculoContable.retencion_pct || 0}%
                                </span>
                            </div>
                            <div class="grid grid-cols-2 ${calculoContable.total_novedades_neto ? 'sm:grid-cols-5' : 'sm:grid-cols-4'} gap-2 text-xs">
                                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700/70">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Subtotal Exámenes</span>
                                    <span class="font-mono font-black text-xs text-slate-800 dark:text-slate-100 mt-1 block">
                                        $ ${floatval(calculoContable.total_estudios_bruto !== undefined ? calculoContable.total_estudios_bruto : calculoContable.total_items_bruto || 0).toLocaleString('es-CO')}
                                    </span>
                                </div>
                                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700/70">
                                    <span class="text-[10px] font-bold text-rose-500 uppercase block">(-) Retención (${calculoContable.retencion_pct || 0}%)</span>
                                    <span class="font-mono font-black text-xs text-rose-600 dark:text-rose-400 mt-1 block">
                                        ${calculoContable.delta_retencion > 0 ? '- ' : (calculoContable.delta_retencion < 0 ? '+ ' : '')}$ ${Math.abs(calculoContable.delta_retencion || 0).toLocaleString('es-CO')}
                                    </span>
                                </div>
                                <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700/70">
                                    <span class="text-[10px] font-bold text-rose-500 uppercase block">(-) Seg. Social / Paraf.</span>
                                    <span class="font-mono font-black text-xs text-rose-600 dark:text-rose-400 mt-1 block">
                                        ${calculoContable.delta_parafiscales > 0 ? '- ' : (calculoContable.delta_parafiscales < 0 ? '+ ' : '')}$ ${Math.abs(calculoContable.delta_parafiscales || 0).toLocaleString('es-CO')}
                                    </span>
                                </div>
                                ${calculoContable.total_novedades_neto ? `
                                <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/80">
                                    <span class="text-[10px] font-bold text-amber-700 dark:text-amber-300 uppercase block">Novedades Entidad</span>
                                    <span class="font-mono font-black text-xs ${calculoContable.total_novedades_neto >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'} mt-1 block">
                                        ${(calculoContable.total_novedades_neto >= 0 ? '+ ' : '- ')}$ ${Math.abs(calculoContable.total_novedades_neto).toLocaleString('es-CO')}
                                    </span>
                                </div>
                                ` : ''}
                                <div class="p-2.5 rounded-xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800/80">
                                    <span class="text-[10px] font-black text-teal-700 dark:text-teal-300 uppercase block">(=) Ajuste Neto Real</span>
                                    <span class="font-mono font-black text-xs ${esCredito ? 'text-teal-700 dark:text-teal-300' : 'text-rose-600 dark:text-rose-400'} mt-1 block">
                                        ${esCredito ? '+ ' : '- '}$ ${Math.abs(valAjuste).toLocaleString('es-CO')}
                                    </span>
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>

                    <!-- 3. BLOQUE: LIQUIDACIÓN BASE ORIGINAL COMPLETA -->
                    <div class="border border-slate-200 dark:border-slate-800 rounded-3xl p-5 bg-white dark:bg-slate-900/60 shadow-sm space-y-4">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 pb-3 border-b border-slate-100 dark:border-slate-800">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center font-bold">
                                    <span class="material-symbols-outlined text-lg">fact_check</span>
                                </div>
                                <div>
                                    <h4 class="font-outfit font-black text-sm uppercase tracking-wider text-slate-900 dark:text-white">
                                        Liquidación Base Original (#${n.liquidacion_id})
                                    </h4>
                                    <p class="text-[10px] text-slate-400">Estructura contable inicial aprobada (preservada sin alteraciones)</p>
                                </div>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-black bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                Estado Base: APROBADA
                            </span>
                        </div>

                        <!-- 2-Column Grid Liquidación Base -->
                        <div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
                            
                            <!-- Left Column: Sedes & Centros de Costos -->
                            <div class="xl:col-span-8 space-y-3">
                                <div class="bg-primary dark:bg-slate-800 text-white font-bold text-[11px] tracking-widest text-center uppercase py-2 px-4 rounded-xl font-outfit">
                                    ESTUDIOS REALIZADOS POR SEDE (REGISTRO BASE)
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    ${sedesCardsHtml || '<div class="p-4 text-center text-slate-400 text-xs">Sin desglose de sedes</div>'}
                                </div>
                            </div>

                            <!-- Right Column: Resumen y Deducciones Originales -->
                            <div class="xl:col-span-4 space-y-4 flex flex-col">
                                <!-- Resumen Administrativo -->
                                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden">
                                    <div class="bg-primary dark:bg-slate-800 text-white font-bold text-[10px] tracking-widest text-center uppercase py-2 px-3 font-outfit">
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
                                                    <td class="py-2 px-3 text-right uppercase text-[10px] font-black">TOTAL FACTURA</td>
                                                    <td class="py-2 px-3 text-right font-black text-xs text-primary dark:text-tertiary">$ ${totalFacturaSum.toLocaleString('es-CO')}</td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>

                                <!-- Deducciones Originales -->
                                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-rose-200 dark:border-rose-900/60 shadow-xs overflow-hidden border-l-4 border-l-rose-500 flex flex-col">
                                    <div class="bg-rose-50 dark:bg-rose-950/60 text-rose-800 dark:text-rose-300 py-2 px-3 font-bold text-[10px] tracking-widest text-center uppercase border-b border-rose-200 dark:border-rose-900/40 flex items-center justify-center gap-1.5">
                                        <span class="material-symbols-outlined text-xs">calculate</span>
                                        <span>DEDUCCIONES ORIGINALES</span>
                                    </div>
                                    <div class="p-3.5 space-y-2.5">
                                        ${deduccionesRowsHtml}
                                        <div class="pt-2 border-t border-slate-200 dark:border-slate-700 flex justify-between items-center text-xs font-bold text-rose-600 dark:text-rose-400">
                                            <span class="uppercase tracking-wider text-[10px]">TOTAL DEDUCCIONES</span>
                                            <span class="font-mono text-xs font-black">- $ ${totalDeducciones.toLocaleString('es-CO')}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Total Liquidación Original -->
                                <div class="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 p-3.5 rounded-2xl text-center shadow-xs">
                                    <span class="text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400 block">TOTAL ORIGINAL BASE</span>
                                    <div class="text-xl font-black font-outfit text-primary dark:text-tertiary mt-0.5">
                                        $ ${totalAPagarOrig.toLocaleString('es-CO')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4. DOBLE HUELLA DIGITAL DE INTEGRIDAD CRIPTOGRÁFICA (SHA-256) -->
                    <div class="border border-emerald-200 dark:border-emerald-800/60 rounded-3xl p-5 bg-emerald-50/60 dark:bg-emerald-950/20 space-y-3.5 shadow-xs">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2 text-emerald-800 dark:text-emerald-300 font-black text-xs uppercase tracking-wider">
                                <span class="material-symbols-outlined text-base text-emerald-600 dark:text-emerald-400">verified_user</span>
                                <span>Seguridad e Integridad Criptográfica (SHA-256)</span>
                            </div>
                            <button type="button" onclick="verificarIntegridadNota(${n.id})" class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[11px] cursor-pointer flex items-center gap-1 shadow-sm transition-colors">
                                <span class="material-symbols-outlined text-xs">check_circle</span>
                                <span>Verificar Firma</span>
                            </button>
                        </div>

                        <!-- Huella Nota de Ajuste -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="font-bold text-emerald-950 dark:text-teal-300">1. Huella Digital de la Nota de Ajuste (${n.numero_nota || 'NA-' + n.id}):</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-800">Firma de Ajuste</span>
                            </div>
                            <div class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-emerald-200 dark:border-teal-800/50 font-mono text-[11px] text-emerald-900 dark:text-teal-300 break-all flex items-center justify-between gap-2 shadow-xs">
                                <span>${n.hash_integridad || 'Sin hash'}</span>
                                <button type="button" onclick="navigator.clipboard.writeText('${n.hash_integridad || ''}'); SwalCustom.fire({icon: 'success', title: 'Copiado', text: 'Huella de nota copiada', timer: 1200, showConfirmButton: false});" class="text-emerald-600 hover:text-emerald-800 dark:text-teal-400 dark:hover:text-white p-1" title="Copiar Hash">
                                    <span class="material-symbols-outlined text-sm">content_copy</span>
                                </button>
                            </div>
                        </div>

                        <!-- Huella Liquidación Base -->
                        <div class="space-y-1.5 pt-2 border-t border-emerald-200 dark:border-emerald-800/40">
                            <div class="flex items-center justify-between text-[11px]">
                                <span class="font-bold text-slate-800 dark:text-slate-200">2. Huella Digital Original (Liquidación Base #${n.liquidacion_id}):</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">Registro Base Inmutable</span>
                            </div>
                            <div class="p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 font-mono text-[11px] text-slate-800 dark:text-slate-300 break-all flex items-center justify-between gap-2 shadow-xs">
                                <span>${liqOrig.hash_integridad || 'Sin hash'}</span>
                                <button type="button" onclick="navigator.clipboard.writeText('${liqOrig.hash_integridad || ''}'); SwalCustom.fire({icon: 'success', title: 'Copiado', text: 'Huella base copiada', timer: 1200, showConfirmButton: false});" class="text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white p-1" title="Copiar Hash Base">
                                    <span class="material-symbols-outlined text-sm">content_copy</span>
                                </button>
                            </div>
                        </div>

                        <p class="text-[10px] text-slate-600 dark:text-slate-400 leading-relaxed font-medium">
                            Esta arquitectura garantiza que la liquidación original #${n.liquidacion_id} permanece intacta y sellada con su firma base, mientras que la nota de ajuste cuenta con su propio sello criptográfico vinculado.
                        </p>
                    </div>

                    <!-- 5. Trazabilidad de Auditoría -->
                    <div class="space-y-2">
                        <h4 class="font-outfit font-black text-xs uppercase tracking-wider text-primary dark:text-white flex items-center gap-2">
                            <span class="material-symbols-outlined text-tertiary text-base">history</span>
                            <span>Bitácora de Auditoría y Trazabilidad</span>
                        </h4>
                        <div class="space-y-2">
                            ${logsHtml}
                        </div>
                    </div>
                `;

                // Footer Actions (Aprobar, Anular, Imprimir)
                const footer = document.getElementById('detalleNotaFooterActions');
                let leftBtns = `
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs cursor-pointer flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base">print</span>
                            <span>Imprimir / PDF</span>
                        </button>
                    </div>
                `;

                let rightBtns = `<div class="flex items-center gap-2">`;
                <?php if ($canApprove): ?>
                if (n.estado === 'PENDIENTE') {
                    rightBtns += `
                        <button type="button" onclick="cambiarEstadoNota(${n.id}, 'APROBADA')" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs cursor-pointer flex items-center gap-1.5 shadow-sm">
                            <span class="material-symbols-outlined text-base">check_circle</span>
                            <span>Aprobar Nota</span>
                        </button>
                        <button type="button" onclick="cambiarEstadoNota(${n.id}, 'ANULADA')" class="px-5 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs cursor-pointer flex items-center gap-1.5 shadow-sm">
                            <span class="material-symbols-outlined text-base">cancel</span>
                            <span>Anular</span>
                        </button>
                    `;
                }
                <?php endif; ?>
                rightBtns += `
                    <button type="button" onclick="cerrarModalDetalleNota()" class="px-5 py-2 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-bold text-xs cursor-pointer hover:bg-slate-300 dark:hover:bg-slate-700 transition-colors">
                        Cerrar
                    </button>
                </div>`;

                footer.innerHTML = leftBtns + rightBtns;

            } catch(e) {
                console.error(e);
            }
        }

        function cerrarModalDetalleNota() {
            document.getElementById('modalDetalleNota').classList.add('hidden');
        }

        async function cambiarEstadoNota(id, nuevoEstado) {
            const esAprobar = (nuevoEstado === 'APROBADA');
            const titulo = esAprobar ? '¿Aprobar Nota de Ajuste?' : '¿Anular Nota de Ajuste?';

            const confirmModal = await SwalCustom.fire({
                title: titulo,
                icon: esAprobar ? 'question' : 'warning',
                text: esAprobar ? 'La nota quedará formalmente aprobada y reflejará el saldo ajustado.' : 'La nota quedará en estado anulada.',
                input: 'textarea',
                inputPlaceholder: 'Observaciones de auditoría (opcional)...',
                showCancelButton: true,
                confirmButtonText: esAprobar ? 'Sí, Aprobar' : 'Sí, Anular',
                cancelButtonText: 'Cancelar'
            });

            if (!confirmModal.isConfirmed) return;

            try {
                const res = await fetch('notas_ajuste.php?action=cambiar_estado_nota', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'cambiar_estado_nota',
                        id: id,
                        estado: nuevoEstado,
                        observaciones: confirmModal.value || ''
                    })
                });
                const result = await res.json();
                if (result.success) {
                    SwalCustom.fire({ icon: 'success', title: 'Completado', text: result.mensaje, timer: 1500, showConfirmButton: false });
                    cerrarModalDetalleNota();
                    cargarNotas();
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Error', text: result.error || 'Error' });
                }
            } catch(e) {
                console.error(e);
            }
        }

        async function verificarIntegridadNota(id) {
            try {
                const res = await fetch(`notas_ajuste.php?action=verificar_hash_nota&id=${id}`);
                const r = await res.json();
                if (r.valido) {
                    SwalCustom.fire({
                        icon: 'success',
                        title: '¡Firma Digital Válida!',
                        html: `
                            <p class="text-xs text-slate-300">La huella criptográfica SHA-256 de la nota <strong>${r.numero_nota}</strong> coincide exactamente con los registros contables.</p>
                            <div class="p-2.5 rounded-xl bg-slate-950 font-mono text-[10px] text-emerald-400 mt-3 break-all">${r.hash_calculado}</div>
                        `
                    });
                } else {
                    SwalCustom.fire({ icon: 'error', title: 'Alerta de Integridad', text: 'La firma digital no coincide con el estado actual del documento.' });
                }
            } catch(e) {
                console.error(e);
            }
        }

        function floatval(val) {
            const v = parseFloat(val);
            return isNaN(v) ? 0 : v;
        }

        function htmlspecialchars(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
    </script>

</body>
</html>

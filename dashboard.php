<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

if (isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] === true) {
    header("Location: cambiar_clave.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');

$userName = $_SESSION['user_name'] ?? 'Usuario';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = strtoupper($_SESSION['user_role'] ?? 'SIN ROL');

// Sincronizar rol y datos reales del usuario desde SQL Server en tiempo real
if (isset($con) && $con !== false && !empty($_SESSION['user_id'])) {
    $stmtUInfo = sqlsrv_query($con, "SELECT u.rol_id, u.nombre_completo, r.nombre AS rol_nombre 
                                      FROM usuarios u 
                                      LEFT JOIN roles r ON u.rol_id = r.id 
                                      WHERE u.id = ?", array($_SESSION['user_id']));
    if ($stmtUInfo !== false && $rowUInfo = sqlsrv_fetch_array($stmtUInfo, SQLSRV_FETCH_ASSOC)) {
        $rId = intval($rowUInfo['rol_id'] ?? 0);
        $rNom = strtoupper(trim((string)($rowUInfo['rol_nombre'] ?? '')));
        if ($rId === 1 || $rNom === 'ADMIN' || $rNom === 'ADMINISTRADOR') {
            $userRole = 'ADMINISTRADOR';
        } elseif ($rId === 2 || $rNom === 'FINANCIERO' || $rNom === 'AUXILIAR') {
            $userRole = 'FINANCIERO';
        } elseif ($rId === 3 || $rNom === 'MEDICO' || $rNom === 'MÉDICO') {
            $userRole = 'MÉDICO';
        } else {
            $userRole = 'SIN ROL';
        }
        $_SESSION['user_role'] = $userRole;
        $_SESSION['user_role_id'] = $rId;

        $dbNombre = trim((string)($rowUInfo['nombre_completo'] ?? $userName));
        if ($userRole !== 'MÉDICO') {
            $dbNombre = preg_replace('/^Dr\.\s*/i', '', $dbNombre);
        } else {
            $dbNombre = (stripos($dbNombre, 'dr.') === 0) ? $dbNombre : ('Dr. ' . $dbNombre);
        }
        $userName = $dbNombre;
        $_SESSION['user_name'] = $userName;
    }
}

if ($userRole !== 'MÉDICO') {
    $userName = preg_replace('/^Dr\.\s*/i', '', $userName);
}

// Consultar Métricas Reales desde SQL Server si la conexión está disponible
$totalMedicos = 0;
$medicosActivos = 0;
$totalUsuarios = 0;
$tarifasSimulador = [];

if (isset($con) && $con !== false) {
    // Total Medicos
    $stmtM = sqlsrv_query($con, "SELECT COUNT(*) AS total FROM medicos");
    if ($stmtM !== false && $rowM = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC)) {
        $totalMedicos = $rowM['total'];
    }

    // Medicos Activos
    $stmtMA = sqlsrv_query($con, "SELECT COUNT(*) AS total FROM medicos m INNER JOIN usuarios u ON m.usuario_id = u.id WHERE UPPER(LTRIM(RTRIM(u.estado))) = '1'");
    if ($stmtMA !== false && $rowMA = sqlsrv_fetch_array($stmtMA, SQLSRV_FETCH_ASSOC)) {
        $medicosActivos = $rowMA['total'];
    }

    // Total Usuarios
    $stmtU = sqlsrv_query($con, "SELECT COUNT(*) AS total FROM usuarios");
    if ($stmtU !== false && $rowU = sqlsrv_fetch_array($stmtU, SQLSRV_FETCH_ASSOC)) {
        $totalUsuarios = $rowU['total'];
    }

    // Consultar Tarifas para el Simulador Rápido
    $stmtSim = sqlsrv_query($con, "SELECT id, codigo, examen, concepto, servicio, valor_und, columna1 FROM tarifario WHERE estado = 1 ORDER BY codigo ASC");
    if ($stmtSim !== false) {
        while ($rowS = sqlsrv_fetch_array($stmtSim, SQLSRV_FETCH_ASSOC)) {
            $tarifasSimulador[] = [
                'id' => $rowS['id'],
                'codigo' => trim((string)$rowS['codigo']),
                'examen' => trim((string)$rowS['examen']),
                'concepto' => trim((string)($rowS['concepto'] ?? '')),
                'servicio' => trim((string)($rowS['servicio'] ?? '')),
                'valor_und' => (int)($rowS['columna1'] ?? ($rowS['valor_und'] ?? 0)),
                'valor_base' => (int)($rowS['columna1'] ?? 0)
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Panel Principal | LIHO</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- Google Fonts: Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet" />

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
                        sans: ["Montserrat", "sans-serif"]
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Montserrat', sans-serif; }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header / Navbar Component -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Content Area -->
    <main class="flex-grow max-w-[1380px] w-full mx-auto px-4 sm:px-6 py-8">
        
        <!-- Welcome Banner con Efectos de Brillo Ambientales y Acceso al Manual -->
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-primary via-[#1c486a] to-[#006a68] p-8 md:p-10 text-white shadow-xl mb-8 border border-white/10 transition-all duration-300 hover:shadow-2xl">
            <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-white/10 backdrop-blur-md border border-white/20 text-tertiary text-xs font-bold uppercase tracking-wider mb-4 shadow-sm">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                        <span>Sesión Autenticada - <?php echo htmlspecialchars($userRole); ?></span>
                    </div>
                    <h1 class="text-3xl md:text-5xl font-black tracking-tight mb-3">
                        Bienvenido, <?php echo htmlspecialchars($userName); ?>
                    </h1>
                    <p class="text-slate-200 text-sm md:text-base leading-relaxed font-medium">
                        Plataforma de Liquidación y Gestión de Honorarios Médicos IPS de <strong>Hernán Ocazionez y Cía S.A.S.</strong>
                    </p>
                </div>

                <!-- Botón Corporativo Destacado al Manual de Usuario -->
                <a href="manual_usuario.php" 
                    title="Consultar Manual de Usuario y Guía Operativa"
                    class="shrink-0 inline-flex items-center gap-3.5 p-4 rounded-2xl bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/25 hover:border-tertiary/70 shadow-xl transition-all duration-300 hover:scale-105 active:scale-95 group">
                    <div class="p-3 rounded-xl bg-gradient-to-tr from-tertiary to-emerald-400 text-primary shadow-md group-hover:rotate-6 transition-transform">
                        <span class="material-symbols-outlined text-2xl font-bold">menu_book</span>
                    </div>
                    <div class="text-left">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-black text-white group-hover:text-tertiary transition-colors">Manual de Usuario</span>
                            <span class="text-[9px] font-mono font-extrabold px-1.5 py-0.5 rounded bg-tertiary/20 text-tertiary border border-tertiary/40">v<?php echo defined('LIHO_VERSION') ? LIHO_VERSION : '1.1.0'; ?></span>
                        </div>
                        <p class="text-[11px] text-slate-300 font-medium">Guía operativa y reglas del sistema</p>
                    </div>
                    <span class="material-symbols-outlined text-base text-tertiary group-hover:translate-x-1 transition-transform">arrow_forward</span>
                </a>
            </div>
            <!-- Círculo decorativo de fondo -->
            <div class="absolute -right-10 -bottom-10 w-96 h-96 bg-tertiary/20 rounded-full blur-3xl pointer-events-none"></div>
        </div>

        <?php if ($userRole === 'ADMINISTRADOR'): ?>
            <!-- ================= VISTA DE ADMINISTRADOR ================= -->

            <!-- Métricas KPI Principales para Admin -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                
                <!-- Card 1: Total Médicos -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-200/80 dark:border-slate-800 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group cursor-pointer" onclick="window.location.href='medicos.php'">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Total Médicos</span>
                        <div class="p-3 rounded-2xl bg-teal-50 dark:bg-teal-950/40 text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors duration-300">
                            <span class="material-symbols-outlined text-2xl">group</span>
                        </div>
                    </div>
                    <p class="text-3xl font-black text-primary dark:text-white tracking-tight"><?php echo number_format($totalMedicos); ?></p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-2 font-medium flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-emerald-500">check_circle</span>
                        <span>Registrados en SQL Server</span>
                    </p>
                </div>

                <!-- Card 2: Médicos Activos -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-200/80 dark:border-slate-800 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group cursor-pointer" onclick="window.location.href='medicos.php'">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Activos Proteo</span>
                        <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 group-hover:bg-emerald-600 group-hover:text-white transition-colors duration-300">
                            <span class="material-symbols-outlined text-2xl">badge</span>
                        </div>
                    </div>
                    <p class="text-3xl font-black text-primary dark:text-white tracking-tight"><?php echo number_format($medicosActivos); ?></p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-2 font-medium flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <span>Habilitados para ingresar</span>
                    </p>
                </div>

                <!-- Card 3: Estado de Liquidación -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-200/80 dark:border-slate-800 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group cursor-pointer" onclick="window.location.href='estadisticas.php'">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Estado Corte</span>
                        <div class="p-3 rounded-2xl bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 group-hover:bg-amber-600 group-hover:text-white transition-colors duration-300">
                            <span class="material-symbols-outlined text-2xl">pending_actions</span>
                        </div>
                    </div>
                    <div>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-900 dark:text-amber-300 text-xs font-extrabold">
                            <span class="w-2 h-2 rounded-full bg-amber-500 animate-ping"></span>
                            <span>En Recopilación</span>
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-3 font-medium">Periodo mensual activo</p>
                </div>

                <!-- Card 4: Productividad General -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl p-6 border border-slate-200/80 dark:border-slate-800 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 group cursor-pointer" onclick="window.location.href='estadisticas.php'">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-xs font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Productividad</span>
                        <div class="p-3 rounded-2xl bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 group-hover:bg-purple-600 group-hover:text-white transition-colors duration-300">
                            <span class="material-symbols-outlined text-2xl">trending_up</span>
                        </div>
                    </div>
                    <p class="text-3xl font-black text-primary dark:text-white tracking-tight">Normal</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-2 font-medium flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-purple-500">insights</span>
                        <span>Medición automatizada</span>
                    </p>
                </div>
            </div>

            <!-- Accesos Rápidos de Administración -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 md:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-lg md:text-xl font-bold text-primary dark:text-white tracking-tight">Acciones Rápidas de Control</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5">Gestión directa de médicos, perfiles y estadísticas</p>
                    </div>
                    <span class="text-xs font-bold text-tertiary bg-tertiary/10 px-3 py-1 rounded-full border border-tertiary/20">Admin Control</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Botón 1 -->
                    <a href="medicos.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-primary dark:hover:bg-tertiary hover:text-white text-slate-800 dark:text-slate-100 border border-slate-200/80 dark:border-slate-700/80 transition-all duration-300 group shadow-2xs">
                        <div class="p-3 rounded-xl bg-primary/10 dark:bg-slate-700 text-primary dark:text-tertiary group-hover:bg-white/20 group-hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-2xl">person_add</span>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold tracking-tight">Gestionar Médicos</h3>
                            <p class="text-[11px] text-slate-400 dark:text-slate-400 group-hover:text-slate-200 transition-colors">Crear y habilitar</p>
                        </div>
                    </a>

                    <!-- Botón 2 -->
                    <a href="estadisticas.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-primary dark:hover:bg-tertiary hover:text-white text-slate-800 dark:text-slate-100 border border-slate-200/80 dark:border-slate-700/80 transition-all duration-300 group shadow-2xs">
                        <div class="p-3 rounded-xl bg-tertiary/15 dark:bg-slate-700 text-tertiary group-hover:bg-white/20 group-hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-2xl">bar_chart</span>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold tracking-tight">Ver Productividad</h3>
                            <p class="text-[11px] text-slate-400 dark:text-slate-400 group-hover:text-slate-200 transition-colors">Informes ejecutivos</p>
                        </div>
                    </a>

                    <!-- Botón 3 -->
                    <a href="historial_tarifario.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-primary dark:hover:bg-tertiary hover:text-white text-slate-800 dark:text-slate-100 border border-slate-200/80 dark:border-slate-700/80 transition-all duration-300 group shadow-2xs">
                        <div class="p-3 rounded-xl bg-blue-50 dark:bg-slate-700 text-blue-600 dark:text-blue-400 group-hover:bg-white/20 group-hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-2xl">history</span>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold tracking-tight">Auditoría Tarifas</h3>
                            <p class="text-[11px] text-slate-400 dark:text-slate-400 group-hover:text-slate-200 transition-colors">Bitácora de cambios</p>
                        </div>
                    </a>

                    <!-- Botón 4 -->
                    <a href="logs_acceso.php" class="flex items-center gap-4 p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 hover:bg-primary dark:hover:bg-tertiary hover:text-white text-slate-800 dark:text-slate-100 border border-slate-200/80 dark:border-slate-700/80 transition-all duration-300 group shadow-2xs">
                        <div class="p-3 rounded-xl bg-purple-50 dark:bg-slate-700 text-purple-600 dark:text-purple-400 group-hover:bg-white/20 group-hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-2xl">security</span>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold tracking-tight">Logs de Seguridad</h3>
                            <p class="text-[11px] text-slate-400 dark:text-slate-400 group-hover:text-slate-200 transition-colors">Accesos e IPs</p>
                        </div>
                    </a>
                </div>
            </div>

        <?php endif; ?>

        <?php if ($userRole !== 'SIN ROL'): ?>
        <!-- Simulador Rápido de Liquidación -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 md:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm mb-8">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="p-3 rounded-2xl bg-tertiary/10 text-tertiary">
                        <span class="material-symbols-outlined text-2xl">calculate</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white tracking-tight">Simulador Rápido de Liquidación</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Calcule al instante el valor estimado a pagar por exámenes realizados</p>
                    </div>
                </div>
                <span class="text-xs font-bold text-tertiary bg-tertiary/10 px-3 py-1 rounded-full border border-tertiary/20">Calculadora IPS</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                
                <!-- Formulario de Selección -->
                <div class="lg:col-span-7 space-y-4">
                    <!-- Selección de Examen / Tarifa con Buscador Inteligente -->
                    <div class="space-y-1 relative" id="simTarifaContainer">
                        <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1.5">
                            Seleccionar Examen / Tarifa
                        </label>
                        
                        <input type="hidden" id="sim_selected_id" value="" />
                        <input type="hidden" id="sim_selected_und" value="0" />
                        <input type="hidden" id="sim_selected_base" value="0" />
                        
                        <!-- Botón Trigger Selector -->
                        <button type="button" id="btnSimTarifaTrigger" 
                            class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center justify-between transition-all cursor-pointer hover:border-tertiary/50 focus:ring-2 focus:ring-tertiary/30 outline-none">
                            <span id="selectedTarifaLabel" class="truncate font-semibold text-slate-700 dark:text-slate-200">
                                -- Seleccione una tarifa de la lista (<?php echo count($tarifasSimulador); ?> activas) --
                            </span>
                            <span class="material-symbols-outlined text-base text-slate-400 shrink-0 ml-1">unfold_more</span>
                        </button>

                        <!-- Dropdown Desplegable con Buscador en Tiempo Real -->
                        <div id="dropdownSimTarifaMenu" class="hidden absolute left-0 right-0 top-full mt-1.5 z-50 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden p-2 space-y-2">
                            <!-- Campo de búsqueda -->
                            <div class="relative">
                                <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-base">search</span>
                                <input type="text" id="searchTarifaInput" placeholder="Buscar por código, nombre de examen o valor..." 
                                    autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                                    class="w-full pl-8 pr-3 py-2 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 transition-all outline-none" />
                            </div>

                            <!-- Lista Opciones Scrollable -->
                            <ul id="listaTarifasOptions" class="max-h-64 overflow-y-auto space-y-0.5 text-xs text-slate-700 dark:text-slate-200 font-medium pr-1">
                                <li>
                                    <button type="button" data-id="" data-codigo="" data-examen="" data-und="0" data-base="0" data-label="-- Seleccione una tarifa de la lista (<?php echo count($tarifasSimulador); ?> activas) --"
                                        class="tarifa-option-btn w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors font-bold text-tertiary flex items-center justify-between bg-slate-100 dark:bg-slate-800">
                                        <span>-- Limpiar selección (Ninguna) --</span>
                                        <span class="material-symbols-outlined text-sm check-icon">check</span>
                                    </button>
                                </li>
                                <?php foreach ($tarifasSimulador as $ts): ?>
                                    <li>
                                        <button type="button" 
                                            data-id="<?php echo $ts['id']; ?>" 
                                            data-codigo="<?php echo htmlspecialchars($ts['codigo']); ?>"
                                            data-examen="<?php echo htmlspecialchars($ts['examen']); ?>"
                                            data-und="<?php echo $ts['valor_und']; ?>"
                                            data-base="<?php echo $ts['valor_base']; ?>"
                                            data-label="[<?php echo htmlspecialchars($ts['codigo']); ?>] <?php echo htmlspecialchars($ts['examen']); ?> ($<?php echo number_format($ts['valor_und'], 0, ',', '.'); ?>)"
                                            class="tarifa-option-btn w-full text-left px-3 py-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors flex items-center justify-between group">
                                            <div class="flex items-center gap-2 truncate pr-2">
                                                <span class="px-1.5 py-0.5 text-[10px] font-black rounded bg-slate-200/80 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono shrink-0">
                                                    <?php echo htmlspecialchars($ts['codigo']); ?>
                                                </span>
                                                <span class="truncate text-slate-800 dark:text-slate-200 group-hover:text-primary dark:group-hover:text-tertiary">
                                                    <?php echo htmlspecialchars($ts['examen']); ?>
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-2 shrink-0">
                                                <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400 text-xs">
                                                    $<?php echo number_format($ts['valor_und'], 0, ',', '.'); ?>
                                                </span>
                                                <span class="material-symbols-outlined text-sm check-icon hidden text-tertiary">check</span>
                                            </div>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            
                            <div id="noTarifasFound" class="hidden text-center py-4 text-xs text-slate-400 font-medium">
                                No se encontraron tarifas que coincidan.
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Cantidad -->
                        <div>
                            <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1.5">
                                Cantidad Realizada
                            </label>
                            <input type="number" id="sim_cantidad" value="1" min="1" max="9999"
                                class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                        </div>

                        <!-- Botón Limpiar -->
                        <div class="flex items-end">
                            <button type="button" id="sim_reset_btn" class="w-full py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-bold transition-all cursor-pointer">
                                Restablecer Simulador
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Panel Resumen de Cálculo -->
                <div class="lg:col-span-5 bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-700/80">
                    <span class="block text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider mb-3">Resumen de Liquidación</span>

                    <div class="space-y-2.5 text-xs mb-4">
                        <div class="flex justify-between items-center text-slate-600 dark:text-slate-400">
                            <span>Valor Unitario:</span>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="sim_lbl_und">$ 0</span>
                        </div>
                        <div class="flex justify-between items-center text-slate-600 dark:text-slate-400">
                            <span>Valor Base:</span>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="sim_lbl_base">$ 0</span>
                        </div>
                        <div class="flex justify-between items-center text-slate-600 dark:text-slate-400">
                            <span>Cantidad Exámenes:</span>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-200" id="sim_lbl_cant">1</span>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-200 dark:border-slate-700">
                        <span class="block text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-400">Total Estimado a Pagar</span>
                        <p class="text-3xl font-black text-emerald-600 dark:text-emerald-400 tracking-tight mt-1" id="sim_lbl_total">$ 0</p>
                    </div>
                </div>

            </div>
        </div>
        <?php else: ?>
        <?php
            $dashUserId = intval($_SESSION['user_id'] ?? 0);
            $dashRoleId = intval($_SESSION['user_role_id'] ?? 0);
            $modulosCatalogo = obtenerModulosSistema();
            
            $urlModuloMap = [
                'dashboard'                      => 'dashboard.php',
                'medicos'                        => 'medicos.php',
                'estadisticas'                   => 'estadisticas.php',
                'liquidaciones'                  => 'aprobacion_liquidaciones.php',
                'examenes_medicos'               => 'examenes_medicos.php',
                'gestion_medicos_procedimientos' => 'gestion_medicos_procedimientos.php',
                'tarifario_especial'             => 'tarifario.php',
                'maestro_porcentajes'            => 'maestro_porcentajes.php',
                'maestro_parafiscales'           => 'maestro_parafiscales.php',
                'notas_ajuste'                   => 'notas_ajuste.php',
                'alerta_medicos'                 => 'alerta_medicos.php',
                'examenes_excluidos'             => 'examenes_excluidos.php',
                'logs_correos'                   => 'logs.php',
                'maestro_entidades'              => 'maestro_entidades.php',
                'gestion_roles'                  => 'gestion_roles.php'
            ];

            $permisosActivos = [];
            foreach ($modulosCatalogo as $mKey => $mInfo) {
                if (tienePermisoModulo($dashUserId, $dashRoleId, $mKey)) {
                    $mInfo['key'] = $mKey;
                    $mInfo['url'] = $urlModuloMap[$mKey] ?? 'dashboard.php';
                    $permisosActivos[] = $mInfo;
                }
            }
        ?>
        <!-- Sección de Permisos y Pantallas Autorizadas para el Usuario -->
        <div class="bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-3xl p-6 md:p-8 shadow-sm mb-8 transition-all">
            
            <!-- Encabezado de Perfil y Permisos -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100 dark:border-slate-800/80 mb-6">
                <div class="flex items-center gap-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-teal-50 dark:bg-teal-950/60 text-tertiary flex items-center justify-center shrink-0 shadow-xs border border-teal-500/20">
                        <span class="material-symbols-outlined text-2xl">verified_user</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base sm:text-lg font-black text-primary dark:text-white tracking-tight">Módulos y Pantallas Autorizadas</h2>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 uppercase tracking-wider">
                                <?php echo count($permisosActivos); ?> <?php echo count($permisosActivos) === 1 ? 'Módulo' : 'Módulos'; ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mt-0.5">
                            Accesos habilitados para su cuenta según su perfil institucional y permisos especiales
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 self-start sm:self-auto">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                        <span class="material-symbols-outlined text-sm text-tertiary">badge</span>
                        <span>Rol: <?php echo htmlspecialchars($userRole); ?></span>
                    </span>
                </div>
            </div>

            <!-- Grilla de Módulos Habilitados -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
                <?php foreach ($permisosActivos as $item): ?>
                    <a href="<?php echo htmlspecialchars($item['url']); ?>" class="flex items-start gap-3.5 p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/70 dark:border-slate-700/80 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group shadow-xs">
                        <div class="w-10 h-10 rounded-xl bg-white dark:bg-slate-700 text-primary dark:text-tertiary flex items-center justify-center shrink-0 shadow-xs border border-slate-200/60 dark:border-slate-600/60 group-hover:bg-tertiary group-hover:text-white transition-colors">
                            <span class="material-symbols-outlined text-xl"><?php echo htmlspecialchars($item['icono']); ?></span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-1 mb-1">
                                <h3 class="text-xs font-bold text-slate-900 dark:text-white group-hover:text-tertiary transition-colors truncate">
                                    <?php echo htmlspecialchars($item['nombre']); ?>
                                </h3>
                                <span class="material-symbols-outlined text-sm text-slate-400 dark:text-slate-500 group-hover:text-tertiary group-hover:translate-x-0.5 transition-transform shrink-0">arrow_forward</span>
                            </div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug line-clamp-2">
                                <?php echo htmlspecialchars($item['descripcion']); ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Nota Informativa Sutil -->
            <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800/80 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 text-xs text-slate-400 dark:text-slate-500">
                <div class="flex items-center gap-2 font-medium">
                    <span class="material-symbols-outlined text-base text-tertiary">info</span>
                    <span>Si requiere acceso a pantallas o procesos adicionales, solicite la habilitación al Administrador del Sistema.</span>
                </div>
                <span class="font-mono text-[10px] uppercase tracking-wider text-slate-400">LIHO SEGURIDAD</span>
            </div>

        </div>
        <?php endif; ?>

    </main>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elementos del Simulador Rápido
            const btnTrigger = document.getElementById('btnSimTarifaTrigger');
            const dropdownMenu = document.getElementById('dropdownSimTarifaMenu');
            const searchInput = document.getElementById('searchTarifaInput');
            const selectedLabel = document.getElementById('selectedTarifaLabel');
            const hiddenId = document.getElementById('sim_selected_id');
            const hiddenUnd = document.getElementById('sim_selected_und');
            const hiddenBase = document.getElementById('sim_selected_base');
            const options = document.querySelectorAll('.tarifa-option-btn');
            const noFound = document.getElementById('noTarifasFound');
            const container = document.getElementById('simTarifaContainer');

            const simCant = document.getElementById('sim_cantidad');
            const simReset = document.getElementById('sim_reset_btn');
            const simLblUnd = document.getElementById('sim_lbl_und');
            const simLblBase = document.getElementById('sim_lbl_base');
            const simLblCant = document.getElementById('sim_lbl_cant');
            const simLblTotal = document.getElementById('sim_lbl_total');

            // Abrir / Cerrar Dropdown
            if (btnTrigger && dropdownMenu) {
                btnTrigger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const isHidden = dropdownMenu.classList.contains('hidden');
                    if (isHidden) {
                        dropdownMenu.classList.remove('hidden');
                        if (searchInput) {
                            searchInput.value = '';
                            filtrarTarifas('');
                            setTimeout(() => searchInput.focus(), 50);
                        }
                    } else {
                        dropdownMenu.classList.add('hidden');
                    }
                });
            }

            // Filtrado en tiempo real
            function filtrarTarifas(text) {
                const query = text.toLowerCase().trim();
                let visibleCount = 0;

                options.forEach((opt, idx) => {
                    // La primera opción es "Limpiar selección", mantenerla siempre visible
                    if (idx === 0) {
                        opt.parentElement.style.display = '';
                        visibleCount++;
                        return;
                    }

                    const codigo = (opt.getAttribute('data-codigo') || '').toLowerCase();
                    const examen = (opt.getAttribute('data-examen') || '').toLowerCase();
                    const und = (opt.getAttribute('data-und') || '').toLowerCase();
                    const label = (opt.getAttribute('data-label') || '').toLowerCase();
                    const li = opt.parentElement;

                    if (!query || codigo.includes(query) || examen.includes(query) || und.includes(query) || label.includes(query)) {
                        if (li) li.style.display = '';
                        visibleCount++;
                    } else {
                        if (li) li.style.display = 'none';
                    }
                });

                if (noFound) {
                    if (visibleCount <= 1 && query !== '') {
                        noFound.classList.remove('hidden');
                    } else {
                        noFound.classList.add('hidden');
                    }
                }
            }

            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    filtrarTarifas(e.target.value);
                });
            }

            // Selección de Tarifa
            options.forEach(opt => {
                opt.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const id = opt.getAttribute('data-id');
                    const und = parseInt(opt.getAttribute('data-und')) || 0;
                    const base = parseInt(opt.getAttribute('data-base')) || 0;
                    const label = opt.getAttribute('data-label');

                    hiddenId.value = id;
                    hiddenUnd.value = und;
                    hiddenBase.value = base;
                    selectedLabel.textContent = label;

                    options.forEach(o => {
                        const check = o.querySelector('.check-icon');
                        if (o === opt) {
                            o.classList.add('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                            if (check) check.classList.remove('hidden');
                        } else {
                            o.classList.remove('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                            if (check) check.classList.add('hidden');
                        }
                    });

                    dropdownMenu.classList.add('hidden');
                    calcularSimulacion();
                });
            });

            // Cerrar dropdown al hacer click fuera
            document.addEventListener('click', function(e) {
                if (container && !container.contains(e.target) && dropdownMenu) {
                    dropdownMenu.classList.add('hidden');
                }
            });

            // Función de cálculo
            function calcularSimulacion() {
                const und = parseInt(hiddenUnd.value) || 0;
                const base = parseInt(hiddenBase.value) || 0;
                const cant = Math.max(1, parseInt(simCant.value) || 1);

                if (!hiddenId.value || und <= 0) {
                    if (simLblUnd) simLblUnd.textContent = '$ 0';
                    if (simLblBase) simLblBase.textContent = '$ 0';
                    if (simLblCant) simLblCant.textContent = cant.toString();
                    if (simLblTotal) simLblTotal.textContent = '$ 0';
                    return;
                }

                const total = und * cant;

                if (simLblUnd) simLblUnd.textContent = '$ ' + und.toLocaleString('es-CO');
                if (simLblBase) simLblBase.textContent = '$ ' + base.toLocaleString('es-CO');
                if (simLblCant) simLblCant.textContent = cant.toString();
                if (simLblTotal) simLblTotal.textContent = '$ ' + total.toLocaleString('es-CO');
            }

            if (simCant) simCant.addEventListener('input', calcularSimulacion);

            // Botón Reset
            if (simReset) {
                simReset.addEventListener('click', function() {
                    hiddenId.value = '';
                    hiddenUnd.value = '0';
                    hiddenBase.value = '0';
                    selectedLabel.textContent = '-- Seleccione una tarifa de la lista (<?php echo count($tarifasSimulador); ?> activas) --';
                    
                    options.forEach((o, idx) => {
                        const check = o.querySelector('.check-icon');
                        if (idx === 0) {
                            o.classList.add('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                            if (check) check.classList.remove('hidden');
                        } else {
                            o.classList.remove('font-bold', 'text-tertiary', 'bg-slate-100', 'dark:bg-slate-800');
                            if (check) check.classList.add('hidden');
                        }
                    });

                    if (simCant) simCant.value = 1;
                    calcularSimulacion();
                });
            }
        });
    </script>

</body>

</html>

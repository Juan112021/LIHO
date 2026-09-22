<?php
/**
 * Header y Barra de Navegación Institucional LIHO
 * Incluye Navegación Central con Menús Desplegables Inteligentes,
 * Drawer Offcanvas de Módulos (Inamovible), Menú de Usuario
 * y Control Global de Modo Oscuro/Claro con cambio dinámico de Logo.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userRole  = strtoupper($_SESSION['user_role'] ?? 'SIN ROL');
$userName  = $_SESSION['user_name'] ?? 'Usuario';

if (isset($con) && $con !== false && !empty($_SESSION['user_id'])) {
    $stmtNavU = sqlsrv_query($con, "SELECT u.rol_id, u.nombre_completo, r.nombre AS rol_nombre 
                                    FROM usuarios u 
                                    LEFT JOIN roles r ON u.rol_id = r.id 
                                    WHERE u.id = ?", array($_SESSION['user_id']));
    if ($stmtNavU !== false && $rowNavU = sqlsrv_fetch_array($stmtNavU, SQLSRV_FETCH_ASSOC)) {
        $nrId = intval($rowNavU['rol_id'] ?? 0);
        $nrNom = strtoupper(trim((string)($rowNavU['rol_nombre'] ?? '')));
        if ($nrId === 1 || $nrNom === 'ADMIN' || $nrNom === 'ADMINISTRADOR') {
            $userRole = 'ADMINISTRADOR';
        } elseif ($nrId === 2 || $nrNom === 'FINANCIERO' || $nrNom === 'AUXILIAR') {
            $userRole = 'FINANCIERO';
        } elseif ($nrId === 3 || $nrNom === 'MEDICO' || $nrNom === 'MÉDICO') {
            $userRole = 'MÉDICO';
        } else {
            $userRole = 'SIN ROL';
        }
        $_SESSION['user_role'] = $userRole;
        $_SESSION['user_role_id'] = $nrId;

        $dbNombre = trim((string)($rowNavU['nombre_completo'] ?? $userName));
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
$userEmail = $_SESSION['user_email'] ?? '';
$userFoto  = $_SESSION['user_foto'] ?? '';
$hasFoto   = !empty($userFoto) && file_exists(__DIR__ . '/../' . $userFoto);

// Obtener iniciales para avatar por defecto
$nameParts = explode(' ', trim($userName));
$initials = '';
if (count($nameParts) >= 2) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1));
} else {
    $initials = strtoupper(substr($userName, 0, 2));
}

// Script actual para resaltar elemento activo
$currentScript = basename($_SERVER['SCRIPT_NAME']);

// Módulos con permisos según el rol y excepciones individuales
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/../config/version.php';
$navUserId = intval($_SESSION['user_id'] ?? 0);
$userRoleIdVal = intval($_SESSION['user_role_id'] ?? ($userRole === 'ADMINISTRADOR' ? 1 : ($userRole === 'FINANCIERO' ? 2 : ($userRole === 'MÉDICO' ? 3 : 0))));

$canAccessModule = function($modKey) use ($navUserId, $userRoleIdVal, $userRole) {
    if ($userRole === 'ADMINISTRADOR' || $userRoleIdVal === 1) return true;
    if (function_exists('tienePermisoModulo')) {
        return tienePermisoModulo($navUserId, $userRoleIdVal, $modKey);
    }
    return false;
};

$canSeeGestionMedica = $canAccessModule('maestro_entidades') || 
                       $canAccessModule('liquidaciones') || 
                       $canAccessModule('gestion_medicos_procedimientos') || 
                       $canAccessModule('notas_ajuste') || 
                       $canAccessModule('medicos') || 
                       $canAccessModule('alerta_medicos') || 
                       $canAccessModule('examenes_excluidos') || 
                       $canAccessModule('examenes_medicos');

$canSeeTarifarios = $canAccessModule('tarifario_especial') || 
                    $canAccessModule('maestro_porcentajes') || 
                    $canAccessModule('maestro_parafiscales');

$canSeeEstadisticas = $canAccessModule('estadisticas');
$canSeeMedicos = $canAccessModule('medicos');
$canSeeLiquidaciones = $canAccessModule('liquidaciones');

// Detección de secciones activas para los dropdowns
$isGestionActive = in_array($currentScript, ['examenes_medicos.php', 'gestion_medicos_procedimientos.php', 'medicos.php', 'maestro_entidades.php', 'alerta_medicos.php', 'aprobacion_liquidaciones.php', 'notas_ajuste.php', 'certificados_tributarios.php', 'examenes_excluidos.php']);
$isTarifariosActive = in_array($currentScript, ['tarifario.php', 'tarifario_especial.php', 'tarifario_bloqueos.php', 'maestro_porcentajes.php', 'maestro_parafiscales.php', 'historial_tarifario.php']);
$isAdminActive = in_array($currentScript, ['usuarios.php', 'logs_acceso.php', 'logs.php', 'maestro_entidades.php']);
?>
<script>
    // Detección e inicialización inmediata del tema para evitar destellos (Anti-Flicker)
    (function() {
        const savedTheme = localStorage.getItem('liho_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        if (savedTheme === 'dark') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
        } else {
            document.documentElement.classList.remove('dark');
            document.documentElement.classList.add('light');
        }
    })();
</script>

<style>
    /* Estilos de transición y animaciones suavizadas para mega-menús */
    .nav-dropdown-menu {
        opacity: 0;
        visibility: hidden;
        transform: scale(0.98) translateY(-6px);
        transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), 
                    transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), 
                    visibility 0.2s;
        pointer-events: none;
        will-change: transform, opacity;
        background-color: #ffffff !important;
        z-index: 1000 !important;
        box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.22), 0 0 0 1px rgba(15, 23, 42, 0.08);
    }
    .dark .nav-dropdown-menu {
        background-color: #0b1329 !important;
        box-shadow: 0 30px 70px -15px rgba(0, 0, 0, 0.95), 0 0 0 1px rgba(255, 255, 255, 0.09);
    }
    
    /* Mostrar dropdown por hover en desktop */
    @media (min-width: 1024px) {
        .nav-dropdown-group:hover > .nav-dropdown-menu,
        .nav-dropdown-group:focus-within > .nav-dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: scale(1) translateY(0);
            pointer-events: auto;
        }
        .nav-dropdown-group:hover .dropdown-chevron,
        .nav-dropdown-group:focus-within .dropdown-chevron {
            transform: rotate(180deg);
        }
    }
    
    /* Activación manual vía JS (para click o touch) */
    .nav-dropdown-menu.is-open {
        opacity: 1 !important;
        visibility: visible !important;
        transform: scale(1) translateY(0) !important;
        pointer-events: auto !important;
    }
    .is-open .dropdown-chevron {
        transform: rotate(180deg) !important;
    }

    /* Puente invisible para evitar que se cierre el dropdown al mover el cursor */
    .nav-dropdown-group::after {
        content: '';
        position: absolute;
        bottom: -12px;
        left: 0;
        right: 0;
        height: 16px;
        pointer-events: auto;
    }

    /* Enlaces principales del Navbar estilo SaaS (Lightning Proxies): planos, sin pastillas gruesas */
    .nav-saas-link {
        position: relative;
        transition: color 0.18s ease;
        background: transparent !important;
    }
    .nav-saas-link::after {
        content: '';
        position: absolute;
        bottom: -4px;
        left: 10px;
        right: 10px;
        height: 2.5px;
        border-radius: 9999px;
        background: #00A896;
        opacity: 0;
        transform: scaleX(0.3);
        transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.22s ease;
    }
    .nav-saas-link:hover::after {
        opacity: 0.7;
        transform: scaleX(0.85);
    }
    .nav-saas-link.is-active::after {
        opacity: 1;
        transform: scaleX(1);
        background: #00A896;
        box-shadow: 0 0 10px rgba(0, 168, 150, 0.6);
    }

    /* Estilo de tarjeta de módulo del mega-menú estilo SaaS */
    .nav-mega-card {
        transition: background-color 0.15s ease, transform 0.18s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.15s ease;
    }
    .nav-mega-card:hover {
        transform: translateX(2px);
    }
    .nav-mega-card:hover .squircle-icon {
        transform: scale(1.06);
    }
    .squircle-icon {
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .nav-mega-card .item-hover-arrow {
        opacity: 0;
        transform: translateX(-4px);
        transition: opacity 0.18s ease, transform 0.18s ease;
    }
    .nav-mega-card:hover .item-hover-arrow {
        opacity: 1;
        transform: translateX(0);
    }

    .dropdown-chevron {
        transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .drawer-backdrop {
        transition: opacity 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .drawer-content {
        transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .user-dropdown-enter {
        opacity: 0;
        transform: scale(0.96) translateY(-8px);
        pointer-events: none;
        transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1),
                    transform 0.24s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .user-dropdown-active {
        opacity: 1;
        transform: scale(1) translateY(0);
        pointer-events: auto;
    }
</style>

<header class="bg-white/95 dark:bg-slate-900/95 backdrop-blur-md border-b border-slate-200/80 dark:border-slate-800 sticky top-0 z-50 transition-all duration-300 shadow-xs w-full">
    <div class="max-w-[1480px] mx-auto px-3 sm:px-5 lg:px-6">
        <div class="flex items-center justify-between h-16 gap-2 sm:gap-4">
            
            <!-- 1. Logo e Identidad Institucional (Cambia dinámicamente según tema) -->
            <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                <a href="dashboard.php" class="flex items-center gap-2.5 sm:gap-3 group">
                    <img id="navLogoImg" 
                        src="assets/img/Logo original.png" 
                        alt="Hernán Ocazionez Logo" 
                        class="h-8 sm:h-9 md:h-10 w-auto object-contain transition-transform duration-300 group-hover:scale-105" />
                    <div class="hidden sm:flex flex-col border-l border-slate-200 dark:border-slate-800 pl-2.5 sm:pl-3">
                        <span class="text-xs sm:text-sm font-black text-primary dark:text-tertiary tracking-wider uppercase leading-none">LIHO</span>
                        <span class="hidden xl:block text-[9px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest leading-tight mt-0.5 whitespace-nowrap">Liquidación Médica IPS</span>
                    </div>
                </a>
            </div>

            <!-- 2. Navegación Principal Estilo SaaS Moderno (Inspirado en Lightning Proxies) -->
            <nav class="hidden lg:flex items-center gap-1 xl:gap-1.5 bg-slate-100/80 dark:bg-slate-800/80 backdrop-blur-md p-1 xl:p-1.5 rounded-2xl border border-slate-200/80 dark:border-slate-700/60 shadow-inner shrink-0">
                
                <!-- 2.1 Inicio (Directo) -->
                <a href="dashboard.php" 
                    title="Panel de Control Principal"
                    class="nav-saas-link whitespace-nowrap flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 <?php echo ($currentScript === 'dashboard.php') ? 'is-active text-teal-600 dark:text-tertiary font-extrabold' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200/50 dark:hover:bg-slate-700/40'; ?>">
                    <span class="material-symbols-outlined text-base">dashboard</span>
                    <span>Inicio</span>
                </a>

                <!-- 2.2 Gestión Médica (Mega-Menú SaaS con Spotlight Card) -->
                <?php if ($canSeeGestionMedica): ?>
                <div class="relative nav-dropdown-group">
                    <button type="button" 
                        class="nav-saas-link nav-dropdown-btn whitespace-nowrap flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 cursor-pointer <?php echo $isGestionActive ? 'is-active text-teal-600 dark:text-tertiary font-extrabold' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200/50 dark:hover:bg-slate-700/40'; ?>">
                        <span class="material-symbols-outlined text-base">medical_services</span>
                        <span>Gestión Médica</span>
                        <span class="material-symbols-outlined text-base text-slate-400 dark:text-slate-400 group-hover:text-inherit dropdown-chevron">expand_more</span>
                    </button>

                    <!-- Mega-Menú Flotante: Gestión Médica & Finanzas (100% Sólido Opaco, sin transparencias) -->
                    <div class="nav-dropdown-menu absolute left-0 top-full mt-2 w-[590px] xl:w-[910px] max-w-[calc(100vw-2rem)] bg-white dark:bg-[#0b1329] rounded-3xl border border-slate-200 dark:border-slate-800 p-4 xl:p-5 z-[1000] origin-top-left shadow-2xl">
                        
                        <!-- Encabezado Temático Superior -->
                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800/80 pb-3 mb-3.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span class="text-[11px] font-black uppercase text-slate-500 dark:text-slate-400 tracking-wider font-mono">Gestión Asistencial & Honorarios IPS</span>
                            </div>
                            <?php if ($canAccessModule('medicos')): ?>
                            <a href="medicos.php" class="text-xs font-bold text-teal-600 dark:text-tertiary hover:text-emerald-500 flex items-center gap-1 group/link transition-colors">
                                <span>Ver Directorio Médico</span>
                                <span class="material-symbols-outlined text-sm transition-transform group-hover/link:translate-x-0.5">arrow_forward</span>
                            </a>
                            <?php endif; ?>
                        </div>

                        <!-- Contenedor Principal: 2 Columnas de Módulos (4x2 = 8 ordenados) + Spotlight Card -->
                        <div class="flex flex-col xl:flex-row gap-4">
                            
                            <!-- 2 Columnas de Módulos (Simétricas y ordenadas) -->
                            <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                
                                <!-- 1. Entidades / IPS (Mostrado como botón con alta visibilidad) -->
                                <?php if ($canAccessModule('maestro_entidades')): ?>
                                <a href="maestro_entidades.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'maestro_entidades.php') ? 'bg-indigo-50/80 dark:bg-slate-800/90 border border-indigo-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-md shadow-indigo-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">domain</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Entidades / IPS
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'maestro_entidades.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Empresas e IPS externas</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 2. Aprobación de Liquidaciones -->
                                <?php if ($canAccessModule('liquidaciones')): ?>
                                <a href="aprobacion_liquidaciones.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'aprobacion_liquidaciones.php') ? 'bg-emerald-50/80 dark:bg-slate-800/90 border border-emerald-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-teal-500 to-cyan-500 text-white shadow-md shadow-teal-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">payments</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Aprobación Liquidaciones
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'aprobacion_liquidaciones.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-teal-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Revisión de honorarios y cierre</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 3. Gestión Procedimientos -->
                                <?php if ($canAccessModule('gestion_medicos_procedimientos')): ?>
                                <a href="gestion_medicos_procedimientos.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'gestion_medicos_procedimientos.php') ? 'bg-cyan-50/80 dark:bg-slate-800/90 border border-cyan-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-cyan-500 to-blue-500 text-white shadow-md shadow-cyan-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">vital_signs</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Gestión Procedimientos
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'gestion_medicos_procedimientos.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-cyan-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Procedimientos y enfermería</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 4. Notas de Ajustes -->
                                <?php if ($canAccessModule('notas_ajuste')): ?>
                                <a href="notas_ajuste.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'notas_ajuste.php') ? 'bg-violet-50/80 dark:bg-slate-800/90 border border-violet-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-purple-600 to-violet-500 text-white shadow-md shadow-purple-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">note_alt</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Notas de Ajustes
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'notas_ajuste.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-purple-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Ajustes a quincenas previas</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 5. Médicos IPS -->
                                <?php if ($canAccessModule('medicos')): ?>
                                <a href="medicos.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'medicos.php') ? 'bg-blue-50/80 dark:bg-slate-800/90 border border-blue-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-500 text-white shadow-md shadow-blue-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">medical_services</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Directorio Médico
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'medicos.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-blue-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Especialistas y habilitación</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 6. Certificados Tributarios -->
                                <?php if ($canAccessModule('liquidaciones')): ?>
                                <a href="certificados_tributarios.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'certificados_tributarios.php') ? 'bg-amber-50/80 dark:bg-slate-800/90 border border-amber-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-amber-500 to-orange-400 text-white shadow-md shadow-amber-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">workspace_premium</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Certificados Tributarios
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'certificados_tributarios.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Retención Art. 381 / 383</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 7. Alerta Médicos -->
                                <?php if ($canAccessModule('alerta_medicos')): ?>
                                <a href="alerta_medicos.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'alerta_medicos.php') ? 'bg-amber-50/80 dark:bg-slate-800/90 border border-amber-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-amber-600 to-yellow-500 text-white shadow-md shadow-amber-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">notifications_active</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Alerta Médicos
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'alerta_medicos.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Pacientes en corte y avisos</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 8. Exámenes Excluidos -->
                                <?php if ($canAccessModule('examenes_excluidos')): ?>
                                <a href="examenes_excluidos.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'examenes_excluidos.php') ? 'bg-rose-50/80 dark:bg-slate-800/90 border border-rose-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-rose-500 to-pink-500 text-white shadow-md shadow-rose-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">do_not_disturb_on</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Exámenes Excluidos
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'examenes_excluidos.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Auditoría y reglas de no cruce</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                            </div>

                            <!-- Columna Lateral Derecha: Spotlight / Showcase Card - CONSULTA PRINCIPAL: CRUCE DE EXÁMENES -->
                            <?php if ($canAccessModule('examenes_medicos')): ?>
                            <div class="w-[295px] shrink-0 rounded-2xl p-4 xl:p-5 bg-gradient-to-b from-[#07152b] to-[#040a14] text-white flex flex-col justify-between border <?php echo ($currentScript === 'examenes_medicos.php') ? 'border-emerald-400 ring-2 ring-emerald-500/30' : 'border-emerald-500/30'; ?> shadow-2xl relative overflow-hidden hidden xl:flex">
                                <div class="absolute -top-10 -right-10 w-36 h-36 bg-emerald-500/20 rounded-full blur-2xl pointer-events-none"></div>
                                
                                <div class="relative z-10 space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 uppercase tracking-wider font-mono">
                                            <span class="material-symbols-outlined text-xs">deployed_code</span>
                                            CONSULTA PRINCIPAL
                                        </span>
                                        <span class="flex items-center gap-1.5 text-[10px] font-bold text-emerald-400">
                                            <span class="flex h-2 w-2 relative">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                            </span>
                                            <span>En vivo</span>
                                        </span>
                                    </div>
                                    
                                    <div>
                                        <div class="flex items-center gap-2.5 mb-1.5">
                                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-400 text-white flex items-center justify-center shadow-md shadow-emerald-500/30 shrink-0">
                                                <span class="material-symbols-outlined text-xl">description</span>
                                            </div>
                                            <div>
                                                <h4 class="text-sm font-black text-white leading-tight">Cruce de Exámenes</h4>
                                                <span class="text-[10px] font-bold text-emerald-400 uppercase tracking-wider">PROTEO vs SERVINTE</span>
                                            </div>
                                        </div>
                                        <p class="text-[11px] text-slate-300 leading-relaxed mt-2">Conciliación asistencial y contable en tiempo real entre la base de datos de producción y facturación.</p>
                                    </div>

                                    <!-- Lista de capacidades en vivo (Iconos profesionales, ZERO emojis) -->
                                    <div class="space-y-2 py-3 border-y border-white/10 text-[11px] text-slate-200">
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-emerald-400 shrink-0">check</span>
                                            <span>PROTEO SQL Server (En vivo)</span>
                                        </div>
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-emerald-400 shrink-0">check</span>
                                            <span>SERVINTE Oracle (Sincronizado)</span>
                                        </div>
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-emerald-400 shrink-0">check</span>
                                            <span>Auditoría de Honorarios Médicos</span>
                                        </div>
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-emerald-400 shrink-0">check</span>
                                            <span>Detección de diferencias y no cruces</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="relative z-10 pt-4">
                                    <a href="examenes_medicos.php" 
                                        class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white text-xs font-black text-center flex items-center justify-center gap-2 transition-all shadow-lg shadow-emerald-950/50 hover:shadow-emerald-900/60 hover:scale-[1.02] active:scale-[0.98] group/cta">
                                        <span>Ir a Cruce de Exámenes</span>
                                        <span class="material-symbols-outlined text-base transition-transform group-hover/cta:translate-x-1">arrow_forward</span>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>

                        <!-- Sub-barra inferior informativa -->
                        <div class="mt-3.5 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between px-2 text-xs">
                            <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400 font-medium text-[11px]">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>Módulos asistenciales, conciliación de consultas y liquidaciones integradas</span>
                            </div>
                            <span class="font-mono text-[10px] uppercase tracking-wider text-slate-400 dark:text-slate-500 font-bold">LIHO IPS CORE</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 2.3 Tarifarios (Mega-Menú SaaS con Spotlight Card) -->
                <?php if ($canSeeTarifarios): ?>
                <div class="relative nav-dropdown-group">
                    <button type="button" 
                        class="nav-saas-link nav-dropdown-btn whitespace-nowrap flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 cursor-pointer <?php echo $isTarifariosActive ? 'is-active text-teal-600 dark:text-tertiary font-extrabold' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200/50 dark:hover:bg-slate-700/40'; ?>">
                        <span class="material-symbols-outlined text-base">request_quote</span>
                        <span>Tarifarios</span>
                        <span class="material-symbols-outlined text-base text-slate-400 dark:text-slate-400 group-hover:text-inherit dropdown-chevron">expand_more</span>
                    </button>

                    <!-- Mega-Menú Flotante: Tarifarios & Parámetros (100% Sólido Opaco) -->
                    <div class="nav-dropdown-menu absolute left-0 xl:-left-20 top-full mt-2 w-[560px] xl:w-[860px] max-w-[calc(100vw-2rem)] bg-white dark:bg-[#0b1329] rounded-3xl border border-slate-200 dark:border-slate-800 p-4 xl:p-5 z-[1000] origin-top-left shadow-2xl">
                        
                        <!-- Encabezado Temático Superior -->
                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800/80 pb-3 mb-3.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-cyan-500 animate-pulse"></span>
                                <span class="text-[11px] font-black uppercase text-slate-500 dark:text-slate-400 tracking-wider font-mono">Catálogo Tarifario & Escalas HO</span>
                            </div>
                            <?php if ($canAccessModule('tarifario_especial')): ?>
                            <a href="tarifario.php" class="text-xs font-bold text-teal-600 dark:text-tertiary hover:text-cyan-500 flex items-center gap-1 group/link transition-colors">
                                <span>Ver Catálogo Base</span>
                                <span class="material-symbols-outlined text-sm transition-transform group-hover/link:translate-x-0.5">arrow_forward</span>
                            </a>
                            <?php endif; ?>
                        </div>

                        <!-- Contenedor Principal: 2 Columnas de Módulos (3x2 = 6 ordenados) + Spotlight Card -->
                        <div class="flex flex-col xl:flex-row gap-4">
                            
                            <!-- 2 Columnas de Tarifarios -->
                            <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                
                                <!-- 1. Catálogo Tarifario Base -->
                                <?php if ($canAccessModule('tarifario_especial')): ?>
                                <a href="tarifario.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'tarifario.php') ? 'bg-teal-50/80 dark:bg-slate-800/90 border border-teal-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-teal-500 to-emerald-500 text-white shadow-md shadow-teal-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">request_quote</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Catálogo Tarifario
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'tarifario.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-teal-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Valores base y códigos CUPS</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 2. Modalidades & Porcentajes -->
                                <?php if ($canAccessModule('maestro_porcentajes')): ?>
                                <a href="maestro_porcentajes.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'maestro_porcentajes.php') ? 'bg-emerald-50/80 dark:bg-slate-800/90 border border-emerald-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-emerald-600 to-teal-500 text-white shadow-md shadow-emerald-600/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">percent</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Modalidades & %
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'maestro_porcentajes.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Gestión dinámica % de pago</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 3. Tarifas Especiales -->
                                <?php if ($canAccessModule('tarifario_especial')): ?>
                                <a href="tarifario_especial.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'tarifario_especial.php') ? 'bg-purple-50/80 dark:bg-slate-800/90 border border-purple-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-violet-600 to-purple-500 text-white shadow-md shadow-purple-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">star</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Tarifas Especiales
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'tarifario_especial.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-purple-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Honorarios diferenciados</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 4. Maestro Parafiscales -->
                                <?php if ($canAccessModule('maestro_parafiscales')): ?>
                                <a href="maestro_parafiscales.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'maestro_parafiscales.php') ? 'bg-cyan-50/80 dark:bg-slate-800/90 border border-cyan-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-cyan-500 to-sky-600 text-white shadow-md shadow-cyan-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">account_balance</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Maestro Parafiscales
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'maestro_parafiscales.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-cyan-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Tasas IBC, Salud y ARL</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 5. Tarifario Bloqueos HO -->
                                <?php if ($canAccessModule('tarifario_especial')): ?>
                                <a href="tarifario_bloqueos.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'tarifario_bloqueos.php') ? 'bg-amber-50/80 dark:bg-slate-800/90 border border-amber-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-amber-500 to-orange-500 text-white shadow-md shadow-amber-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">syringe</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Tarifario Bloqueos HO
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'tarifario_bloqueos.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Procedimientos y escalas HO</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                                <!-- 6. Historial Tarifario -->
                                <?php if ($canAccessModule('tarifario_especial')): ?>
                                <a href="historial_tarifario.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'historial_tarifario.php') ? 'bg-indigo-50/80 dark:bg-slate-800/90 border border-indigo-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-indigo-500 to-blue-600 text-white shadow-md shadow-indigo-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">history</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Historial Tarifario
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'historial_tarifario.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Auditoría de precios y fechas</p>
                                    </div>
                                </a>
                                <?php endif; ?>

                            </div>

                            <!-- Columna Lateral Derecha: Spotlight / Showcase Card (Visible en >= 1280px) -->
                            <?php if ($canAccessModule('maestro_porcentajes')): ?>
                            <div class="w-[280px] shrink-0 rounded-2xl p-4 xl:p-5 bg-[#060D1F] text-white flex flex-col justify-between border border-slate-700/80 shadow-2xl relative overflow-hidden hidden xl:flex">
                                <div class="absolute -top-10 -right-10 w-32 h-32 bg-cyan-500/15 rounded-full blur-2xl pointer-events-none"></div>
                                
                                <div class="relative z-10 space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-cyan-400/15 text-cyan-300 border border-cyan-400/30 uppercase tracking-wider font-mono">
                                            <span class="material-symbols-outlined text-xs">calculate</span>
                                            MOTOR DE TARIFAS
                                        </span>
                                        <span class="flex h-2 w-2 relative">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-cyan-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-cyan-500"></span>
                                        </span>
                                    </div>
                                    
                                    <div>
                                        <h4 class="text-sm font-black text-white leading-snug">Matriz Tarifaria Automatizada</h4>
                                        <p class="text-[11px] text-slate-300 mt-1 leading-relaxed">Cálculo dinámico de honorarios, retenciones e IBC según normatividad vigente.</p>
                                    </div>

                                    <!-- Lista de capacidades (Iconos profesionales, ZERO emojis) -->
                                    <div class="space-y-2 py-3 border-y border-white/10 text-[11px] text-slate-200">
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-cyan-400 shrink-0">check</span>
                                            <span>Parámetros CUPS 2026</span>
                                        </div>
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-cyan-400 shrink-0">check</span>
                                            <span>Porcentajes por médico</span>
                                        </div>
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-cyan-400 shrink-0">check</span>
                                            <span>Historial inalterable</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="relative z-10 pt-4">
                                    <a href="maestro_porcentajes.php" 
                                        class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-cyan-600 to-teal-600 hover:from-cyan-500 hover:to-teal-500 text-white text-xs font-bold text-center flex items-center justify-center gap-2 transition-all shadow-lg shadow-cyan-950/40 group/cta">
                                        <span>Gestionar Porcentajes</span>
                                        <span class="material-symbols-outlined text-sm transition-transform group-hover/cta:translate-x-1">arrow_forward</span>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>

                        <!-- Sub-barra inferior informativa -->
                        <div class="mt-3.5 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between px-2 text-xs text-slate-400 dark:text-slate-500">
                            <span class="flex items-center gap-1.5 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-cyan-500"></span>
                                <span>Parámetros tributarios, tasas parafiscales y escalas actualizadas</span>
                            </span>
                            <span class="font-mono text-[10px] uppercase tracking-wider text-slate-400 dark:text-slate-500">TARIFAS HO</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($canSeeEstadisticas): ?>
                <!-- 2.4 Productividad (Directo) -->
                <a href="estadisticas.php" 
                    title="Informes de Productividad y Estadísticas"
                    class="nav-saas-link whitespace-nowrap flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 <?php echo ($currentScript === 'estadisticas.php') ? 'is-active text-teal-600 dark:text-tertiary font-extrabold' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200/50 dark:hover:bg-slate-700/40'; ?>">
                    <span class="material-symbols-outlined text-base">analytics</span>
                    <span>Productividad</span>
                </a>
                <?php endif; ?>

                <?php if ($userRole === 'ADMINISTRADOR'): ?>
                <!-- 2.5 Administración (Mega-Menú SaaS con Spotlight Card) -->
                <div class="relative nav-dropdown-group">
                    <button type="button" 
                        class="nav-saas-link nav-dropdown-btn whitespace-nowrap flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 cursor-pointer <?php echo $isAdminActive ? 'is-active text-teal-600 dark:text-tertiary font-extrabold' : 'text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white hover:bg-slate-200/50 dark:hover:bg-slate-700/40'; ?>">
                        <span class="material-symbols-outlined text-base">admin_panel_settings</span>
                        <span>Administración</span>
                        <span class="material-symbols-outlined text-base text-slate-400 dark:text-slate-400 group-hover:text-inherit dropdown-chevron">expand_more</span>
                    </button>

                    <!-- Mega-Menú Flotante: Administración & Seguridad (100% Sólido Opaco) -->
                    <div class="nav-dropdown-menu absolute right-0 top-full mt-2 w-[540px] xl:w-[820px] max-w-[calc(100vw-2rem)] bg-white dark:bg-[#0b1329] rounded-3xl border border-slate-200 dark:border-slate-800 p-4 xl:p-5 z-[1000] origin-top-right shadow-2xl">
                        
                        <!-- Encabezado Temático Superior -->
                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800/80 pb-3 mb-3.5">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                <span class="text-[11px] font-black uppercase text-slate-500 dark:text-slate-400 tracking-wider font-mono">Configuración, Seguridad & Auditoría</span>
                            </div>
                            <a href="logs.php" class="text-xs font-bold text-teal-600 dark:text-tertiary hover:text-amber-500 flex items-center gap-1 group/link transition-colors">
                                <span>Centro de Auditoría</span>
                                <span class="material-symbols-outlined text-sm transition-transform group-hover/link:translate-x-0.5">arrow_forward</span>
                            </a>
                        </div>

                        <!-- Contenedor Principal: 2 Columnas de Módulos (2x2 = 4 ordenados) + Spotlight Card -->
                        <div class="flex flex-col xl:flex-row gap-4">
                            
                            <!-- 2 Columnas de Administración -->
                            <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                
                                <?php 
                                require_once __DIR__ . '/config_helper.php';
                                $sysCfgNav = obtenerConfiguracionSistema();
                                $sysBloqNav = estanCorreosMedicosBloqueados();
                                ?>
                                <!-- 1. Seguridad & Correos -->
                                <button type="button" onclick="abrirModalSeguridadCorreosGlobal()"
                                    class="nav-mega-card w-full text-left flex items-center gap-3 p-2.5 rounded-2xl group/item hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent cursor-pointer">
                                    <div class="squircle-icon w-10 h-10 <?php echo $sysBloqNav ? 'bg-gradient-to-br from-amber-500 to-orange-500 text-white shadow-md shadow-amber-500/20' : 'bg-gradient-to-br from-emerald-500 to-teal-400 text-white shadow-md shadow-emerald-500/20'; ?> shrink-0">
                                        <span class="material-symbols-outlined text-xl"><?php echo $sysBloqNav ? 'shield' : 'mark_email_read'; ?></span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Seguridad Correos
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <span class="text-[9px] font-black uppercase px-1.5 py-0.5 rounded shrink-0 <?php echo $sysBloqNav ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'; ?>">
                                                <?php echo $sysBloqNav ? 'DEV' : 'PROD'; ?>
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5"><?php echo $sysBloqNav ? 'Envíos a médicos bloqueados' : 'Envíos a médicos activos'; ?></p>
                                    </div>
                                </button>

                                <!-- 2. Maestro de Entidades -->
                                <a href="maestro_entidades.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'maestro_entidades.php') ? 'bg-indigo-50/80 dark:bg-slate-800/90 border border-indigo-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-md shadow-indigo-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">domain</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Maestro Entidades
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'maestro_entidades.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Empresas e IPS externas</p>
                                    </div>
                                </a>

                                <!-- 3. Gestión de Roles y Permisos -->
                                <a href="gestion_roles.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'gestion_roles.php') ? 'bg-teal-50/80 dark:bg-slate-800/90 border border-teal-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-tertiary to-teal-700 text-white shadow-md shadow-tertiary/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">admin_panel_settings</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Gestión de Roles & Vistas
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'gestion_roles.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-teal-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Catálogo de roles y matriz de pantallas</p>
                                    </div>
                                </a>

                                <!-- 4. Gestión de Usuarios -->
                                <a href="usuarios.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'usuarios.php') ? 'bg-teal-50/80 dark:bg-slate-800/90 border border-teal-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-md shadow-teal-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">manage_accounts</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Usuarios del Sistema
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'usuarios.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-teal-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Cuentas y permisos especiales</p>
                                    </div>
                                </a>

                                <!-- 4. Centro de Logs y Auditoría -->
                                <a href="logs.php" 
                                    class="nav-mega-card flex items-center gap-3 p-2.5 rounded-2xl group/item <?php echo ($currentScript === 'logs.php') ? 'bg-rose-50/80 dark:bg-slate-800/90 border border-rose-500/30' : 'hover:bg-slate-100/70 dark:hover:bg-slate-800/60 border border-transparent'; ?>">
                                    <div class="squircle-icon w-10 h-10 bg-gradient-to-br from-rose-500 to-pink-600 text-white shadow-md shadow-rose-500/20 shrink-0">
                                        <span class="material-symbols-outlined text-xl">manage_history</span>
                                    </div>
                                    <div class="overflow-hidden flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-bold text-slate-900 dark:text-slate-100 group-hover/item:text-teal-600 dark:group-hover/item:text-tertiary transition-colors flex items-center gap-1 truncate">
                                                Logs & Auditoría
                                                <span class="material-symbols-outlined text-xs text-primary dark:text-tertiary item-hover-arrow">chevron_right</span>
                                            </span>
                                            <?php if ($currentScript === 'logs.php'): ?>
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0"></span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate mt-0.5">Monitoreo inalterable en vivo</p>
                                    </div>
                                </a>

                            </div>

                            <!-- Columna Lateral Derecha: Spotlight / Showcase Card (Visible en >= 1280px) -->
                            <div class="w-[280px] shrink-0 rounded-2xl p-4 xl:p-5 bg-[#060D1F] text-white flex flex-col justify-between border border-slate-700/80 shadow-2xl relative overflow-hidden hidden xl:flex">
                                <div class="absolute -top-10 -right-10 w-32 h-32 bg-amber-500/15 rounded-full blur-2xl pointer-events-none"></div>
                                
                                <div class="relative z-10 space-y-3">
                                    <div class="flex items-center justify-between">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-amber-400/15 text-amber-300 border border-amber-400/30 uppercase tracking-wider font-mono">
                                            <span class="material-symbols-outlined text-xs">shield</span>
                                            CONTROL & SEGURIDAD
                                        </span>
                                        <span class="flex h-2 w-2 relative">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                                        </span>
                                    </div>
                                    
                                    <div>
                                        <h4 class="text-sm font-black text-white leading-snug">Supervisión Centralizada</h4>
                                        <p class="text-[11px] text-slate-300 mt-1 leading-relaxed">Trazabilidad de accesos, protección en envíos y bitácora forense.</p>
                                    </div>

                                    <!-- Lista de capacidades (Iconos profesionales, ZERO emojis) -->
                                    <div class="space-y-2 py-3 border-y border-white/10 text-[11px] text-slate-200">
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-amber-400 shrink-0">check</span>
                                            <span>Protección de envíos masivos</span>
                                        </div>
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-amber-400 shrink-0">check</span>
                                            <span>Roles y permisos estrictos</span>
                                        </div>
                                        <div class="flex items-center gap-2 font-medium">
                                            <span class="material-symbols-outlined text-sm text-amber-400 shrink-0">check</span>
                                            <span>Auditoría inalterable</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="relative z-10 pt-4">
                                    <a href="logs.php" 
                                        class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-amber-600 to-yellow-600 hover:from-amber-500 hover:to-yellow-500 text-white text-xs font-bold text-center flex items-center justify-center gap-2 transition-all shadow-lg shadow-amber-950/40 group/cta">
                                        <span>Ver Bitácora en Vivo</span>
                                        <span class="material-symbols-outlined text-sm transition-transform group-hover/cta:translate-x-1">arrow_forward</span>
                                    </a>
                                </div>
                            </div>

                        </div>

                        <!-- Sub-barra inferior informativa -->
                        <div class="mt-3.5 pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between px-2 text-xs text-slate-400 dark:text-slate-500">
                            <span class="flex items-center gap-1.5 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                <span>Control de accesos, trazabilidad y configuración del sistema</span>
                            </span>
                            <span class="font-mono text-[10px] uppercase tracking-wider text-slate-400 dark:text-slate-500">SEGURIDAD</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </nav>

            <!-- 3. Acciones Rápidas: Botón Módulos & Avatar de Usuario -->
            <div class="flex items-center gap-2 sm:gap-3 shrink-0">

                <?php if ($userRole === 'ADMINISTRADOR'): 
                    require_once __DIR__ . '/config_helper.php';
                    $sysBloqueadoBar = estanCorreosMedicosBloqueados();
                ?>
                <!-- Badge Estado Desarrollo / Correos Médicos Estilo SaaS -->
                <button type="button" onclick="abrirModalSeguridadCorreosGlobal()" 
                    title="<?php echo $sysBloqueadoBar ? 'Modo Desarrollo: Correos a médicos bloqueados preventivamente. Clic para configurar.' : 'Modo Producción: Correos a médicos habilitados. Clic para configurar.'; ?>"
                    class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold transition-all cursor-pointer border shadow-xs <?php echo $sysBloqueadoBar ? 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-200 border-amber-300 dark:border-amber-800 hover:bg-amber-100' : 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border-emerald-300 dark:border-emerald-800 hover:bg-emerald-100'; ?>">
                    <span class="w-2 h-2 rounded-full <?php echo $sysBloqueadoBar ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500 animate-pulse'; ?>"></span>
                    <span class="material-symbols-outlined text-base <?php echo $sysBloqueadoBar ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'; ?>">
                        <?php echo $sysBloqueadoBar ? 'shield' : 'mark_email_read'; ?>
                    </span>
                    <span class="text-[11px] font-extrabold hidden 2xl:inline">
                        <?php echo $sysBloqueadoBar ? 'Correos: Bloqueados' : 'Correos: Activos'; ?>
                    </span>
                    <span class="text-[11px] font-extrabold hidden md:inline 2xl:hidden">
                        <?php echo $sysBloqueadoBar ? 'Bloqueados' : 'Activos'; ?>
                    </span>
                </button>
                <?php endif; ?>

                <!-- Botón Módulos (Estilo SaaS "Get Started" con micro-interacción) -->
                <button type="button" id="openDrawerBtn" 
                    title="Abrir panel completo de todos los módulos"
                    class="whitespace-nowrap inline-flex items-center gap-1.5 px-4 py-2 rounded-full bg-gradient-to-r from-primary via-[#004e64] to-tertiary hover:opacity-95 text-white text-xs font-extrabold shadow-md shadow-primary/20 transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer border border-white/20 shrink-0 group/modbtn">
                    <span class="material-symbols-outlined text-base text-tertiary group-hover/modbtn:rotate-12 transition-transform">grid_view</span>
                    <span>Módulos</span>
                    <span class="material-symbols-outlined text-sm transition-transform group-hover/modbtn:translate-x-0.5">chevron_right</span>
                </button>

                <!-- Avatar Usuario Dropdown -->
                <div class="relative shrink-0" id="userMenuDropdownContainer">
                    <button id="userMenuBtn" type="button" 
                        class="flex items-center gap-2 p-1 pl-1.5 pr-2 rounded-full bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/80 dark:hover:bg-slate-700/80 border border-slate-200 dark:border-slate-700 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-tertiary/30 cursor-pointer">
                        
                        <?php if ($hasFoto): ?>
                            <img src="<?php echo htmlspecialchars($userFoto); ?>" alt="Foto Perfil" class="w-8 h-8 rounded-full object-cover shadow-xs border border-white shrink-0" />
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-primary to-[#006a68] text-white font-extrabold flex items-center justify-center text-xs shadow-sm shrink-0">
                                <?php echo htmlspecialchars($initials); ?>
                            </div>
                        <?php endif; ?>

                        <span class="material-symbols-outlined text-slate-500 dark:text-slate-400 text-lg transition-transform duration-200" id="userMenuChevron">expand_more</span>
                    </button>

                    <!-- Dropdown Usuario -->
                    <div id="userMenuDropdown" 
                        class="user-dropdown-enter absolute right-0 mt-2 w-60 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl rounded-2xl shadow-xl border border-slate-200/80 dark:border-slate-800 py-2 z-50 transition-all duration-200 origin-top-right">
                        
                        <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
                            <?php if ($hasFoto): ?>
                                <img src="<?php echo htmlspecialchars($userFoto); ?>" alt="Foto Perfil" class="w-10 h-10 rounded-full object-cover border border-slate-200 shrink-0" />
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-primary text-white font-extrabold flex items-center justify-center text-sm shrink-0">
                                    <?php echo htmlspecialchars($initials); ?>
                                </div>
                            <?php endif; ?>
                            <div class="overflow-hidden">
                                <p class="text-xs font-extrabold text-primary dark:text-tertiary truncate"><?php echo htmlspecialchars($userName); ?></p>
                                <p class="text-[10px] text-slate-400 dark:text-slate-500 truncate mt-0.5"><?php echo htmlspecialchars($userEmail); ?></p>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-md text-[9px] font-extrabold bg-tertiary/10 text-tertiary border border-tertiary/20 uppercase tracking-wider">
                                    <?php echo htmlspecialchars($userRole); ?>
                                </span>
                            </div>
                        </div>

                        <div class="py-1">
                            <a href="perfil.php" class="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 hover:text-primary dark:hover:text-tertiary transition-colors">
                                <span class="material-symbols-outlined text-base text-slate-400">person</span>
                                <span>Mi Perfil</span>
                            </a>
                            <a href="certificados_tributarios.php" class="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 hover:text-primary dark:hover:text-tertiary transition-colors">
                                <span class="material-symbols-outlined text-base text-slate-400">workspace_premium</span>
                                <span>Certificados Tributarios</span>
                            </a>
                            <a href="cambiar_clave.php" class="flex items-center gap-2.5 px-4 py-2 text-xs text-slate-700 dark:text-slate-300 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 hover:text-primary dark:hover:text-tertiary transition-colors">
                                <span class="material-symbols-outlined text-base text-slate-400">key</span>
                                <span>Cambiar Contraseña</span>
                            </a>
                            <a href="manual_usuario.php" class="flex items-center gap-2.5 px-4 py-2 text-xs text-teal-600 dark:text-tertiary font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors border-t border-slate-100 dark:border-slate-800/80">
                                <span class="material-symbols-outlined text-base text-teal-600 dark:text-tertiary">menu_book</span>
                                <span>Manual de Usuario</span>
                            </a>
                        </div>

                        <div class="border-t border-slate-100 dark:border-slate-800 pt-1">
                            <a href="logout.php" class="flex items-center gap-2.5 px-4 py-2 text-xs text-rose-600 font-bold hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors">
                                <span class="material-symbols-outlined text-base text-rose-500">logout</span>
                                <span>Cerrar Sesión</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Botón Menú Móvil (Abre Drawer de Módulos) -->
                <button id="mobileMenuBtn" type="button" 
                    title="Menú de Navegación"
                    class="lg:hidden p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors shrink-0">
                    <span class="material-symbols-outlined text-2xl">menu</span>
                </button>

            </div>
        </div>
    </div>
</header>

<!-- 4. Drawer Offcanvas ("Explorar Módulos de LIHO") -->
<div id="drawerBackdrop" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 opacity-0 pointer-events-none drawer-backdrop">
    <div id="drawerContent" class="fixed right-0 top-0 bottom-0 w-full max-w-md bg-white dark:bg-slate-900 shadow-2xl z-50 flex flex-col translate-x-full drawer-content border-l border-slate-200 dark:border-slate-800">
        
        <!-- Drawer Header -->
        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-slate-50/80 dark:bg-slate-900/80">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-tertiary/10 text-tertiary">
                    <span class="material-symbols-outlined text-2xl">grid_view</span>
                </div>
                <div>
                    <h3 class="text-base font-black text-primary dark:text-tertiary tracking-tight">Módulos LIHO</h3>
                    <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Ecosistema Integrado de Liquidación IPS</p>
                </div>
            </div>
            <button type="button" id="closeDrawerBtn" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors">
                <span class="material-symbols-outlined text-xl">close</span>
            </button>
        </div>

        <!-- Buscador interno en Drawer -->
        <div class="p-4 border-b border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-900">
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                <input type="text" id="drawerSearchInput" placeholder="Buscar módulo o función..." 
                    class="w-full pl-9 pr-4 py-2 bg-slate-100 dark:bg-slate-800 border-none rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
            </div>
        </div>

        <!-- Drawer Body con Categorías de Módulos -->
        <div class="flex-grow overflow-y-auto p-5 space-y-6" id="drawerModuleList">
            
            <!-- 1. Operativa Médica & Cruces -->
            <div>
                <div class="flex items-center justify-between mb-3 px-1">
                    <p class="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Operativa Médica & Cruces</p>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 border border-teal-200/50 dark:border-teal-800/50 uppercase tracking-wider">Clínica</span>
                </div>
                <div class="grid grid-cols-1 gap-2.5">
                    
                    <a href="dashboard.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">dashboard</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Panel de Inicio (Dashboard)</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Resumen general de métricas, gráficos y accesos rápidos.</p>
                        </div>
                    </a>

                    <?php if ($canAccessModule('examenes_medicos')): ?>
                    <a href="examenes_medicos.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">description</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Cruce de Exámenes</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Cruce en tiempo real entre PROTEO (SQL Server) y SERVINTE (Oracle).</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($canAccessModule('gestion_medicos_procedimientos')): ?>
                    <a href="gestion_medicos_procedimientos.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-teal-50 dark:bg-slate-800 text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">vital_signs</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Gestión Médicos Procedimientos</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Cruce de procedimientos y enfermería entre PROTEO y SERVINTE.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($canAccessModule('medicos')): ?>
                    <a href="medicos.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">medical_services</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Gestión de Médicos IPS</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Directorio médico, especialidades y administración de usuarios.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($canAccessModule('maestro_entidades')): ?>
                    <a href="maestro_entidades.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-indigo-200/70 dark:border-indigo-900/40 hover:border-indigo-500/50 hover:bg-indigo-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">domain</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-indigo-700 dark:text-indigo-300 group-hover:text-indigo-600 transition-colors">Maestro de Entidades / IPS</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Registro de empresas, IPS e instituciones para médicos externos.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($canAccessModule('alerta_medicos')): ?>
                    <a href="alerta_medicos.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-amber-200/70 dark:border-amber-900/40 hover:border-amber-500/50 hover:bg-amber-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">notifications_active</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-amber-700 dark:text-amber-300 group-hover:text-amber-600 transition-colors">Alerta Médicos</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Monitoreo de pacientes en curso y envío de recordatorios automáticos.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <!-- Manual de Usuario Corporativo -->
                    <a href="manual_usuario.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-teal-200/70 dark:border-teal-900/40 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">menu_book</span>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <p class="text-xs font-black text-teal-700 dark:text-tertiary group-hover:text-teal-600 transition-colors">Manual de Usuario</p>
                                <span class="text-[9px] font-mono px-1.5 py-0.5 rounded bg-tertiary/20 text-tertiary font-bold">v<?php echo defined('LIHO_VERSION') ? LIHO_VERSION : '1.1.0'; ?></span>
                            </div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Guía operativa interactiva, políticas de liquidación y manual oficial.</p>
                        </div>
                    </a>

                </div>
            </div>

            <!-- 2. Liquidación & Finanzas -->
            <?php if ($canSeeLiquidaciones): ?>
            <div>
                <div class="flex items-center justify-between mb-3 px-1">
                    <p class="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Liquidación & Control Financiero</p>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 border border-emerald-200/50 dark:border-emerald-800/50 uppercase tracking-wider">Finanzas</span>
                </div>
                <div class="grid grid-cols-1 gap-2.5">

                    <a href="aprobacion_liquidaciones.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-emerald-200/70 dark:border-emerald-900/40 hover:border-emerald-500/50 hover:bg-emerald-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 group-hover:bg-emerald-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">payments</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-emerald-800 dark:text-emerald-300 group-hover:text-emerald-600 transition-colors">Aprobación de Liquidaciones</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Revisión, aprobación/rechazo y bitácora de trazabilidad.</p>
                        </div>
                    </a>

                    <a href="notas_ajuste.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-indigo-200/70 dark:border-indigo-900/40 hover:border-indigo-500/50 hover:bg-indigo-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">note_alt</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-indigo-700 dark:text-indigo-300 group-hover:text-indigo-600 transition-colors">Notas de Ajustes</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Ajustes a liquidaciones previas (débitos y créditos) con trazabilidad.</p>
                        </div>
                    </a>

                    <a href="certificados_tributarios.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-amber-200/70 dark:border-amber-900/40 hover:border-amber-500/50 hover:bg-amber-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">workspace_premium</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-amber-700 dark:text-amber-300 group-hover:text-amber-600 transition-colors">Certificados Tributarios</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Certificados de ingresos y retención en la fuente (Art. 381 y 383 E.T.).</p>
                        </div>
                    </a>

                    <a href="examenes_excluidos.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-rose-200/70 dark:border-rose-900/40 hover:border-rose-500/50 hover:bg-rose-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 group-hover:bg-rose-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">do_not_disturb_on</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-rose-700 dark:text-rose-300 group-hover:text-rose-600 transition-colors">Exámenes Excluidos</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Auditoría, trazabilidad y justificaciones de exámenes excluidos.</p>
                        </div>
                    </a>

                </div>
            </div>
            <?php endif; ?>

            <!-- 3. Tarifarios & Honorarios -->
            <?php if ($canSeeTarifarios): ?>
            <div>
                <div class="flex items-center justify-between mb-3 px-1">
                    <p class="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Tarifarios & Honorarios</p>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 border border-purple-200/50 dark:border-purple-800/50 uppercase tracking-wider">Reglas HO</span>
                </div>
                <div class="grid grid-cols-1 gap-2.5">

                    <?php if ($canAccessModule('tarifario_especial')): ?>
                    <a href="tarifario.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">request_quote</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Catálogo Tarifario CUPS</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Edición de valores, estados de activación y tarifas base.</p>
                        </div>
                    </a>

                    <a href="tarifario_especial.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-purple-200/70 dark:border-purple-900/40 hover:border-purple-500/50 hover:bg-purple-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 group-hover:bg-purple-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">star</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-purple-700 dark:text-purple-300 group-hover:text-purple-600 transition-colors">Tarifas Especiales</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Catálogo y escalas de honorarios para médicos especiales.</p>
                        </div>
                    </a>

                    <a href="tarifario_bloqueos.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-amber-200/70 dark:border-amber-900/40 hover:border-amber-500/50 hover:bg-amber-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 group-hover:bg-amber-500 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">syringe</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-amber-700 dark:text-amber-300 group-hover:text-amber-600 transition-colors">Tarifario Bloqueos HO</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Procedimientos y porcentajes escalonados por cantidad.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($canAccessModule('maestro_porcentajes')): ?>
                    <a href="maestro_porcentajes.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-emerald-200/70 dark:border-emerald-900/40 hover:border-emerald-500/50 hover:bg-emerald-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 group-hover:bg-emerald-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">percent</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-emerald-700 dark:text-emerald-300 group-hover:text-emerald-600 transition-colors">Modalidades y Porcentajes</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Gestión dinámica de modalidades y % de pago.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($canAccessModule('maestro_parafiscales')): ?>
                    <a href="maestro_parafiscales.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-teal-200/70 dark:border-teal-900/40 hover:border-teal-500/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 group-hover:bg-teal-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">account_balance</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-teal-700 dark:text-teal-300 group-hover:text-teal-600 transition-colors">Maestro de Parafiscales</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Tasas y porcentajes en vivo para IBC, Salud y ARL.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($canAccessModule('tarifario_especial')): ?>
                    <a href="historial_tarifario.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-indigo-200/70 dark:border-indigo-900/40 hover:border-indigo-500/50 hover:bg-indigo-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 group-hover:bg-indigo-600 group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">history</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-indigo-700 dark:text-indigo-300 group-hover:text-indigo-600 transition-colors">Historial de Tarifario</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Auditoría en tiempo real de cambios de precios y tarifas.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>

            <!-- 4. Reportes & Administración -->
            <?php if ($canSeeEstadisticas || $userRole === 'ADMINISTRADOR'): ?>
            <div>
                <div class="flex items-center justify-between mb-3 px-1">
                    <p class="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider">Reportes & Administración</p>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 border border-blue-200/50 dark:border-blue-800/50 uppercase tracking-wider">Sistema</span>
                </div>
                <div class="grid grid-cols-1 gap-2.5">
                    
                    <?php if ($canSeeEstadisticas): ?>
                    <a href="estadisticas.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">analytics</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Productividad y Estadísticas</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Informes de avance de liquidación y rendimiento médico.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <?php if ($userRole === 'ADMINISTRADOR'): ?>
                    <a href="gestion_roles.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">admin_panel_settings</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Gestión de Roles y Permisos</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Configuración de catálogo de roles y matriz de pantallas.</p>
                        </div>
                    </a>

                    <a href="usuarios.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">manage_accounts</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Administración de Usuarios</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Gestión de cuentas y permisos especiales por usuario.</p>
                        </div>
                    </a>

                    <a href="logs.php" class="module-item flex items-start gap-3.5 p-3 rounded-2xl border border-slate-200/70 dark:border-slate-800 hover:border-tertiary/50 hover:bg-teal-50/40 dark:hover:bg-slate-800 transition-all duration-200 group">
                        <div class="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary group-hover:bg-tertiary group-hover:text-white transition-colors shrink-0">
                            <span class="material-symbols-outlined text-xl">manage_history</span>
                        </div>
                        <div>
                            <p class="text-xs font-black text-primary dark:text-slate-100 group-hover:text-tertiary transition-colors">Centro de Logs y Auditoría</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5">Monitoreo inalterable: accesos, tarifas, envíos de correo y alertas.</p>
                        </div>
                    </a>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>


        </div>

        <!-- Drawer Footer -->
        <div class="p-4 bg-slate-50 dark:bg-slate-950 border-t border-slate-200 dark:border-slate-800 text-center">
            <p class="text-[11px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-widest">LIHO v2.5 • Hernán Ocazionez IPS</p>
        </div>
    </div>
</div>

<!-- Botón Flotante Global para Alternar Tema Claro / Oscuro -->
<button id="themeToggleBtn" type="button" aria-label="Cambiar tema de color"
    class="fixed bottom-6 left-6 z-40 p-3.5 rounded-full bg-slate-900 dark:bg-white text-white dark:text-slate-900 shadow-2xl hover:scale-110 active:scale-95 transition-all duration-300 flex items-center justify-center border border-slate-700 dark:border-slate-200 group cursor-pointer">
    <span class="material-symbols-outlined text-xl transition-transform duration-500 rotate-0 dark:-rotate-180" id="themeToggleIcon">dark_mode</span>
    <span class="max-w-0 overflow-hidden whitespace-nowrap group-hover:max-w-xs group-hover:ml-2 text-xs font-bold transition-all duration-300">
        <span id="themeToggleText">Modo Oscuro</span>
    </span>
</button>

<!-- Overlay Desenfoque + Loading para Cambio de Tema -->
<div id="themeTransitionOverlay" class="fixed inset-0 z-[9999] flex flex-col items-center justify-center bg-slate-900/30 dark:bg-slate-950/50 backdrop-blur-md opacity-0 pointer-events-none hidden transition-all duration-300">
    <div class="p-6 rounded-3xl bg-white/90 dark:bg-slate-900/90 shadow-2xl border border-slate-200/80 dark:border-slate-800 flex flex-col items-center gap-3 transform scale-90 transition-all duration-300" id="themeTransitionBox">
        <div class="w-12 h-12 rounded-2xl bg-tertiary/10 text-tertiary flex items-center justify-center">
            <span class="material-symbols-outlined text-3xl animate-spin">sync</span>
        </div>
        <div class="text-center">
            <span class="block text-xs font-black tracking-wider uppercase text-primary dark:text-tertiary" id="themeTransitionLabel">Cambiando Modo...</span>
            <span class="text-[10px] text-slate-400 font-medium">Aplicando tema en la plataforma</span>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Control Global de Modo Claro / Oscuro + Cambio Dinámico de Logo
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeToggleIcon = document.getElementById('themeToggleIcon');
    const themeToggleText = document.getElementById('themeToggleText');
    const navLogoImg = document.getElementById('navLogoImg');

    function applyGlobalTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
            if (themeToggleIcon) themeToggleIcon.textContent = 'light_mode';
            if (themeToggleText) themeToggleText.textContent = 'Modo Claro';
            if (navLogoImg) navLogoImg.src = 'assets/img/Logo fondo oscuro.png';
        } else {
            document.documentElement.classList.remove('dark');
            document.documentElement.classList.add('light');
            if (themeToggleIcon) themeToggleIcon.textContent = 'dark_mode';
            if (themeToggleText) themeToggleText.textContent = 'Modo Oscuro';
            if (navLogoImg) navLogoImg.src = 'assets/img/Logo original.png';
        }
    }

    const currentTheme = localStorage.getItem('liho_theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    applyGlobalTheme(currentTheme);

    function toggleThemeWithBlurLoading() {
        const overlay = document.getElementById('themeTransitionOverlay');
        const box = document.getElementById('themeTransitionBox');
        const label = document.getElementById('themeTransitionLabel');
        const isDark = document.documentElement.classList.contains('dark');
        const newTheme = isDark ? 'light' : 'dark';

        if (!overlay || !box) {
            localStorage.setItem('liho_theme', newTheme);
            applyGlobalTheme(newTheme);
            return;
        }

        if (label) {
            label.textContent = isDark ? 'Activando Modo Claro...' : 'Activando Modo Oscuro...';
        }

        overlay.classList.remove('hidden', 'pointer-events-none', 'opacity-0');
        overlay.classList.add('opacity-100');
        box.classList.remove('scale-90');
        box.classList.add('scale-100');

        setTimeout(() => {
            localStorage.setItem('liho_theme', newTheme);
            applyGlobalTheme(newTheme);

            setTimeout(() => {
                box.classList.remove('scale-100');
                box.classList.add('scale-90');
                overlay.classList.remove('opacity-100');
                overlay.classList.add('opacity-0');
                setTimeout(() => {
                    overlay.classList.add('hidden', 'pointer-events-none');
                }, 250);
            }, 140);
        }, 160);
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', toggleThemeWithBlurLoading);
    }

    // 2. Interacción de Menús Desplegables de la Barra Central (Click / Mobile)
    const dropdownGroups = document.querySelectorAll('.nav-dropdown-group');
    
    function ajustarDropdownBoundary(menu) {
        if (!menu) return;
        menu.style.transform = '';
        const rect = menu.getBoundingClientRect();
        const margin = 20;
        if (rect.right > window.innerWidth - margin) {
            const exceso = rect.right - (window.innerWidth - margin);
            menu.style.transform = `translateX(-${exceso}px)`;
        } else if (rect.left < margin) {
            const falta = margin - rect.left;
            menu.style.transform = `translateX(${falta}px)`;
        }
    }

    dropdownGroups.forEach(group => {
        const btn = group.querySelector('.nav-dropdown-btn');
        const menu = group.querySelector('.nav-dropdown-menu');
        
        if (btn && menu) {
            group.addEventListener('mouseenter', function() {
                if (window.innerWidth >= 1024) {
                    ajustarDropdownBoundary(menu);
                }
            });

            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = menu.classList.contains('is-open');
                // Cerrar otros dropdowns primero
                document.querySelectorAll('.nav-dropdown-menu.is-open').forEach(m => {
                    if (m !== menu) {
                        m.classList.remove('is-open');
                        m.style.transform = '';
                    }
                });
                if (isOpen) {
                    menu.classList.remove('is-open');
                    menu.style.transform = '';
                } else {
                    menu.classList.add('is-open');
                    ajustarDropdownBoundary(menu);
                }
            });

            // Cerrar suavemente al sacar el cursor en desktop
            group.addEventListener('mouseleave', function() {
                if (window.innerWidth >= 1024) {
                    menu.classList.remove('is-open');
                    menu.style.transform = '';
                }
            });

            // Cerrar al hacer clic en cualquier enlace interno
            menu.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', function() {
                    menu.classList.remove('is-open');
                    menu.style.transform = '';
                });
            });
        }
    });

    // 3. Menú Desplegable del Usuario
    const userMenuBtn = document.getElementById('userMenuBtn');
    const userMenuDropdown = document.getElementById('userMenuDropdown');
    const userMenuChevron = document.getElementById('userMenuChevron');

    if (userMenuBtn && userMenuDropdown) {
        userMenuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            // Cerrar dropdowns de la nav central
            document.querySelectorAll('.nav-dropdown-menu.is-open').forEach(m => m.classList.remove('is-open'));
            
            const isOpen = userMenuDropdown.classList.contains('user-dropdown-active');
            if (isOpen) {
                userMenuDropdown.classList.remove('user-dropdown-active');
                if (userMenuChevron) userMenuChevron.style.transform = 'rotate(0deg)';
            } else {
                userMenuDropdown.classList.add('user-dropdown-active');
                if (userMenuChevron) userMenuChevron.style.transform = 'rotate(180deg)';
            }
        });
    }

    // Cerrar todos los menús al hacer click fuera
    document.addEventListener('click', function(e) {
        // Cerrar dropdowns centrales
        document.querySelectorAll('.nav-dropdown-menu.is-open').forEach(menu => {
            if (!menu.contains(e.target) && !menu.closest('.nav-dropdown-group').contains(e.target)) {
                menu.classList.remove('is-open');
            }
        });
        // Cerrar dropdown de usuario
        if (userMenuDropdown && !userMenuDropdown.contains(e.target) && !userMenuBtn.contains(e.target)) {
            userMenuDropdown.classList.remove('user-dropdown-active');
            if (userMenuChevron) userMenuChevron.style.transform = 'rotate(0deg)';
        }
    });

    // 4. Offcanvas Drawer de Módulos
    const openDrawerBtn = document.getElementById('openDrawerBtn');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const closeDrawerBtn = document.getElementById('closeDrawerBtn');
    const drawerBackdrop = document.getElementById('drawerBackdrop');
    const drawerContent = document.getElementById('drawerContent');
    const drawerSearchInput = document.getElementById('drawerSearchInput');

    function openDrawer() {
        if (!drawerBackdrop || !drawerContent) return;
        drawerBackdrop.classList.remove('pointer-events-none', 'opacity-0');
        drawerBackdrop.classList.add('opacity-100');
        drawerContent.classList.remove('translate-x-full');
        drawerContent.classList.add('translate-x-0');
        document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        if (!drawerBackdrop || !drawerContent) return;
        drawerBackdrop.classList.remove('opacity-100');
        drawerBackdrop.classList.add('opacity-0', 'pointer-events-none');
        drawerContent.classList.remove('translate-x-0');
        drawerContent.classList.add('translate-x-full');
        document.body.style.overflow = '';
    }

    if (openDrawerBtn) openDrawerBtn.addEventListener('click', openDrawer);
    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', openDrawer);
    if (closeDrawerBtn) closeDrawerBtn.addEventListener('click', closeDrawer);
    if (drawerBackdrop) {
        drawerBackdrop.addEventListener('click', function(e) {
            if (e.target === drawerBackdrop) closeDrawer();
        });
    }

    if (drawerSearchInput) {
        drawerSearchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            const items = document.querySelectorAll('.module-item');
            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(query) ? 'flex' : 'none';
            });
        });
    }
});
</script>

<!-- MODAL GLOBAL: Seguridad del Sistema y Control de Correos -->
<div id="modalSeguridadCorreosGlobal" class="fixed inset-0 bg-slate-950/70 backdrop-blur-sm z-[9999] hidden items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 dark:border-slate-800 transform scale-95 transition-transform duration-200" id="modalSeguridadCorreosBox">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3 mb-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <span class="material-symbols-outlined text-2xl">shield</span>
                </div>
                <div>
                    <h3 class="text-base font-black text-slate-900 dark:text-white font-outfit">Seguridad & Envío de Correos</h3>
                    <p class="text-xs text-slate-400">Control de Entorno (Desarrollo / Producción)</p>
                </div>
            </div>
            <button type="button" onclick="cerrarModalSeguridadCorreosGlobal()" class="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form id="formSeguridadCorreosGlobal" onsubmit="guardarSeguridadCorreosGlobal(event)" class="space-y-4 text-left">
            <div class="p-3.5 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200/80 dark:border-amber-800/60 text-xs text-amber-900 dark:text-amber-200 leading-relaxed flex items-start gap-2.5">
                <span class="material-symbols-outlined text-base text-amber-600 dark:text-amber-400 shrink-0 mt-0.5">shield</span>
                <div>
                    <strong>Protección en Desarrollo:</strong> Cuando el bloqueo está activo, <strong>ningún médico real recibirá correos</strong> (alertas, liquidaciones, certificados). Los correos dirigidos a médicos se interceptarán preventivamente.
                </div>
            </div>

            <div class="space-y-3">
                <label class="flex items-start gap-3 p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 cursor-pointer select-none">
                    <input type="checkbox" id="globBloquearCorreosMedicos" name="bloquear_correos_medicos" value="1" class="w-4 h-4 mt-0.5 rounded text-amber-600 focus:ring-amber-500 cursor-pointer">
                    <div>
                        <span class="block text-xs font-bold text-slate-900 dark:text-slate-100">Bloquear envíos a Médicos (Modo Desarrollo)</span>
                        <span class="block text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Activa la protección durante el desarrollo. Desmarca solo cuando el sistema esté listo para producción con médicos reales.</span>
                    </div>
                </label>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">Buzón de pruebas para redirección (opcional):</label>
                    <input type="email" id="globEmailTestRedireccion" name="email_test_redireccion"
                        placeholder="juane6462@gmail.com"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/40 outline-none font-mono" />
                    <span class="text-[10px] text-slate-400 mt-1 block">Los correos dirigidos a médicos llegarán a este buzón con el prefijo [TEST DESARROLLO] para que puedas revisarlos sin molestar a los médicos.</span>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
                <button type="button" onclick="cerrarModalSeguridadCorreosGlobal()" class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    Cancelar
                </button>
                <button type="submit" id="btnGuardarSeguridadCorreos"
                    class="px-5 py-2.5 rounded-xl bg-primary hover:bg-[#193e5a] text-white text-xs font-bold shadow-md shadow-primary/20 transition-all cursor-pointer flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base text-tertiary">save</span>
                    <span>Guardar Configuración</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalSeguridadCorreosGlobal() {
    fetch('ajax_config_sistema.php?action=get')
    .then(r => r.json())
    .then(data => {
        if (data.success && data.config) {
            const chk = document.getElementById('globBloquearCorreosMedicos');
            const inp = document.getElementById('globEmailTestRedireccion');
            if (chk) chk.checked = !!(data.config.bloquear_correos_medicos || data.config.modo_desarrollo);
            if (inp) inp.value = data.config.email_test_redireccion || 'juane6462@gmail.com';
        }
        const m = document.getElementById('modalSeguridadCorreosGlobal');
        if (m) {
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
    })
    .catch(() => {
        const m = document.getElementById('modalSeguridadCorreosGlobal');
        if (m) {
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
    });
}

function cerrarModalSeguridadCorreosGlobal() {
    const m = document.getElementById('modalSeguridadCorreosGlobal');
    if (m) {
        m.classList.add('hidden');
        m.classList.remove('flex');
    }
}

function guardarSeguridadCorreosGlobal(e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardarSeguridadCorreos');
    if (btn) btn.disabled = true;

    const fd = new FormData(document.getElementById('formSeguridadCorreosGlobal'));
    fd.append('bloquear_correos_medicos', document.getElementById('globBloquearCorreosMedicos')?.checked ? '1' : '0');
    fd.append('modo_desarrollo', document.getElementById('globBloquearCorreosMedicos')?.checked ? '1' : '0');

    fetch('ajax_config_sistema.php?action=save', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (btn) btn.disabled = false;
        if (res.success) {
            cerrarModalSeguridadCorreosGlobal();
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'Configuración Guardada',
                    text: res.mensaje,
                    confirmButtonText: 'Aceptar'
                }).then(() => {
                    location.reload();
                });
            } else {
                alert(res.mensaje);
                location.reload();
            }
        } else {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'No se pudo guardar la configuración.' });
            } else {
                alert(res.error || 'Error al guardar.');
            }
        }
    })
    .catch(err => {
        if (btn) btn.disabled = false;
        alert('Error de conexión al guardar la configuración.');
    });
}
</script>



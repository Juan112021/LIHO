<?php
/**
 * Manual de Usuario Corporativo Interactivo - Plataforma LIHO
 * IPS Hernán Ocazionez y Cía S.A.S. — Sistemas Diagnósticos
 */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/config/conexion.php';
require_once __DIR__ . '/config/version.php';
require_once __DIR__ . '/includes/permisos_helper.php';

$userName = $_SESSION['user_name'] ?? 'Usuario';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = strtoupper($_SESSION['user_role'] ?? 'SIN ROL');
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Manual de Usuario Corporativo | LIHO v<?php echo LIHO_VERSION; ?></title>
    
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
                        secondary: "#006a68",
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
        html { scroll-behavior: smooth; }
        .manual-nav-link.active {
            background: linear-gradient(135deg, rgba(0, 193, 190, 0.15) 0%, rgba(20, 53, 78, 0.1) 100%);
            border-left: 3px solid #00c1be;
            color: #00c1be;
            font-weight: 800;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-[#060D1F] text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Navbar Global -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Header Hero del Manual -->
    <section class="relative bg-gradient-to-r from-[#0d2334] via-primary to-[#004e64] text-white py-12 px-4 sm:px-6 lg:px-8 border-b border-white/10 shadow-lg overflow-hidden">
        <!-- Fondos y destellos decorativos -->
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-tertiary/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-80 h-80 bg-emerald-500/15 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="max-w-[1440px] mx-auto relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
            <div class="space-y-3">
                <div class="inline-flex items-center gap-2.5 px-3 py-1 rounded-full bg-white/10 backdrop-blur-md border border-white/20 text-xs font-bold uppercase tracking-wider text-tertiary">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Documentación Oficial Corporativa</span>
                    <span class="text-white/40">|</span>
                    <span class="text-white font-mono">Versión <?php echo LIHO_VERSION; ?></span>
                </div>
                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight text-white">
                    Manual de Uso y Guía Operativa
                </h1>
                <p class="text-slate-200 text-sm sm:text-base max-w-2xl font-medium leading-relaxed">
                    Instrucciones paso a paso, políticas de liquidación médica, conciliación bidireccional y administración del sistema para la IPS <strong>Hernán Ocazionez y Cía S.A.S.</strong>
                </p>
            </div>

            <!-- Acciones Rápidas del Manual -->
            <div class="flex flex-wrap items-center gap-3 no-print">
                <button onclick="window.print()" type="button"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-white/10 hover:bg-white/20 border border-white/20 text-white text-xs font-bold transition-all shadow-sm hover:scale-105 active:scale-95 cursor-pointer">
                    <span class="material-symbols-outlined text-base">print</span>
                    <span>Imprimir / Guardar PDF</span>
                </button>
                <a href="dashboard.php" 
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-gradient-to-r from-tertiary to-emerald-400 hover:opacity-95 text-primary text-xs font-black transition-all shadow-md hover:scale-105 active:scale-95">
                    <span class="material-symbols-outlined text-base">dashboard</span>
                    <span>Ir al Panel Principal</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Contenido Principal: Layout con Sidebar Navegable + Contenido -->
    <div class="max-w-[1440px] w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-grow">
        <div class="flex flex-col lg:flex-row gap-8 items-start">
            
            <!-- SIDEBAR DE NAVEGACIÓN RÁPIDA (Fijo en pantallas grandes) -->
            <aside class="w-full lg:w-72 shrink-0 lg:sticky lg:top-24 space-y-4 no-print">
                
                <!-- Buscador de Contenido del Manual -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-sm">
                    <label for="manualSearchInput" class="block text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider mb-2">Buscar en el manual</label>
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-lg">search</span>
                        <input type="text" id="manualSearchInput" placeholder="Ej. Tomografías, Tarifario, Vigencia..." 
                            class="w-full pl-9 pr-3 py-2 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-medium focus:ring-2 focus:ring-tertiary focus:outline-none transition-all" />
                    </div>
                </div>

                <!-- Filtro de Rol -->
                <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-sm">
                    <span class="block text-[11px] font-black uppercase text-slate-400 dark:text-slate-500 tracking-wider mb-2">Filtrar por Perfil</span>
                    <div class="grid grid-cols-2 gap-1.5" id="roleFilters">
                        <button type="button" data-role="all" class="role-filter-btn px-2.5 py-1.5 rounded-lg text-xs font-bold text-center bg-tertiary text-primary shadow-xs">Todos</button>
                        <button type="button" data-role="medico" class="role-filter-btn px-2.5 py-1.5 rounded-lg text-xs font-bold text-center bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200">Médico</button>
                        <button type="button" data-role="financiero" class="role-filter-btn px-2.5 py-1.5 rounded-lg text-xs font-bold text-center bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200">Financiero</button>
                        <button type="button" data-role="admin" class="role-filter-btn px-2.5 py-1.5 rounded-lg text-xs font-bold text-center bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200">Admin</button>
                    </div>
                </div>

                <!-- Menú de Secciones -->
                <nav class="bg-white dark:bg-slate-900 rounded-2xl p-3 border border-slate-200/80 dark:border-slate-800 shadow-sm space-y-1 max-h-[calc(100vh-280px)] overflow-y-auto">
                    <p class="text-[10px] font-black uppercase tracking-wider text-slate-400 dark:text-slate-500 px-3 py-1.5">Índice Temático</p>
                    
                    <a href="#sec-introduccion" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-primary dark:text-tertiary">info</span>
                        <span class="truncate">1. Introducción y Filosofía</span>
                    </a>

                    <a href="#sec-cruce-examenes" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-teal-500">compare_arrows</span>
                        <span class="truncate">2. Cruce Proteo vs Servinte</span>
                    </a>

                    <a href="#sec-tarifarios-vigencias" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-cyan-500">calendar_month</span>
                        <span class="truncate">3. Tarifarios & Vigencias</span>
                    </a>

                    <a href="#sec-tarifas-especiales" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-purple-500">star</span>
                        <span class="truncate">4. Tarifas Especiales & Bloqueos</span>
                    </a>

                    <a href="#sec-liquidaciones-ajustes" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-emerald-500">payments</span>
                        <span class="truncate">5. Liquidaciones & Ajustes</span>
                    </a>

                    <a href="#sec-directorio-medico" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-blue-500">badge</span>
                        <span class="truncate">6. Directorio & Procedimientos</span>
                    </a>

                    <a href="#sec-certificados-tributarios" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-amber-500">workspace_premium</span>
                        <span class="truncate">7. Certificados Tributarios</span>
                    </a>

                    <a href="#sec-maestros-parametros" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-indigo-500">tune</span>
                        <span class="truncate">8. Maestros & Parafiscales</span>
                    </a>

                    <a href="#sec-seguridad-auditoria" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-rose-500">security</span>
                        <span class="truncate">9. Seguridad & Auditoría</span>
                    </a>

                    <a href="#sec-historial-versiones" class="manual-nav-link flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <span class="material-symbols-outlined text-base text-emerald-400">history_toggle_off</span>
                        <span class="truncate">10. Versiones & Changelog</span>
                    </a>
                </nav>

                <!-- Tarjeta de Soporte Institucional -->
                <div class="bg-gradient-to-br from-primary to-[#004e64] rounded-2xl p-4 text-white text-xs space-y-2 border border-white/10 shadow-md">
                    <div class="flex items-center gap-2 font-black text-tertiary">
                        <span class="material-symbols-outlined text-lg">support_agent</span>
                        <span>Mesa de Ayuda HO</span>
                    </div>
                    <p class="text-slate-200 leading-relaxed text-[11px]">
                        Para soporte técnico o dudas sobre liquidación médica, contacta al área de sistemas e informática.
                    </p>
                    <div class="pt-2 border-t border-white/10 flex items-center justify-between text-[10px] text-slate-300">
                        <span>Plataforma LIHO</span>
                        <span class="font-mono font-bold text-tertiary">v<?php echo LIHO_VERSION; ?></span>
                    </div>
                </div>

            </aside>

            <!-- CUERPO PRINCIPAL DEL MANUAL -->
            <main class="flex-1 w-full space-y-10 min-w-0">
                
                <!-- SECCIÓN 1: INTRODUCCIÓN Y FILOSOFÍA -->
                <section id="sec-introduccion" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero,medico">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-teal-50 dark:bg-teal-950/50 text-tertiary">
                            <span class="material-symbols-outlined text-2xl">info</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-teal-600 dark:text-tertiary font-mono">Módulo General</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">1. Introducción y Filosofía del Sistema</h2>
                        </div>
                    </div>

                    <div class="prose dark:prose-invert max-w-none text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            La plataforma <strong>LIHO (Liquidaciones Hernán Ocazionez)</strong> es el núcleo tecnológico diseñado para garantizar la transparencia, exactitud y agilidad en la conciliación y cálculo de honorarios para los médicos especialistas de la IPS <strong>Hernán Ocazionez y Cía S.A.S.</strong>
                        </p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 my-4">
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-2">
                                <div class="flex items-center gap-2 font-bold text-primary dark:text-tertiary">
                                    <span class="material-symbols-outlined text-lg text-emerald-500">database</span>
                                    <span>PROTEO (SQL Server)</span>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Registra la <strong>producción médica asistencial</strong>: exámenes leídos, diagnósticos ejecutados, médicos informantes y fechas clínicas.
                                </p>
                            </div>

                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-2">
                                <div class="flex items-center gap-2 font-bold text-primary dark:text-tertiary">
                                    <span class="material-symbols-outlined text-lg text-cyan-500">account_balance</span>
                                    <span>SERVINTE (Oracle)</span>
                                </div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Registra la <strong>gestión hospitalaria y facturación</strong>: ingresos de pacientes, órdenes, facturas emitidas, entidades responsables y copagos.
                                </p>
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60 flex items-start gap-3 text-emerald-900 dark:text-emerald-200">
                            <span class="material-symbols-outlined text-xl text-emerald-600 shrink-0 mt-0.5">verified</span>
                            <div class="text-xs leading-relaxed">
                                <strong>Principio de Conciliación:</strong> Un examen se liquida con total certeza cuando existe concordancia entre la orden asistencial (Proteo) y el ingreso formalmente facturado (Servinte), aplicando las vigencias de tarifas correspondientes a la fecha en que se realizó el procedimiento.
                            </div>
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 2: CRUCE PROTEO VS SERVINTE -->
                <section id="sec-cruce-examenes" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-teal-50 dark:bg-teal-950/50 text-teal-600 dark:text-teal-400">
                            <span class="material-symbols-outlined text-2xl">compare_arrows</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-teal-600 dark:text-tertiary font-mono">Conciliación Asistencial</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">2. Cruce Bidireccional de Exámenes</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            Disponible en <code>examenes_medicos.php</code>. Este módulo permite conciliar la totalidad de exámenes realizados en un rango de fechas y aplicar las reglas de liquidación matemática:
                        </p>

                        <div class="space-y-3">
                            <h3 class="text-sm font-bold text-primary dark:text-white flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-teal-500"></span>
                                Estados de Conciliación:
                            </h3>
                            <ul class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <li class="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/40 text-xs">
                                    <span class="font-extrabold text-emerald-700 dark:text-emerald-300 block mb-1">✔ CRUZADOS OK</span>
                                    El par Fuente e Ingreso coincide en ambos sistemas. Está listo para liquidación.
                                </li>
                                <li class="p-3 rounded-xl bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-800/40 text-xs">
                                    <span class="font-extrabold text-sky-700 dark:text-sky-300 block mb-1">🔍 SOLO EN PROTEO</span>
                                    Existe la lectura clínica pero falta validación o facturación en Servinte.
                                </li>
                                <li class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/40 text-xs">
                                    <span class="font-extrabold text-amber-700 dark:text-amber-300 block mb-1">⚠️ SOLO EN SERVINTE</span>
                                    Está facturado en el ERP pero aún no reporta informe médico en Proteo.
                                </li>
                            </ul>
                        </div>

                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 space-y-2">
                            <h4 class="font-black text-xs uppercase tracking-wider text-primary dark:text-tertiary">Reglas Especiales de Pago:</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs text-slate-600 dark:text-slate-300">
                                <div>
                                    <strong>1. Bonificación Tomografías:</strong> Por cada 50 tomografías contrastadas ejecutadas por el médico en el periodo, se bonifica con <strong>$150.000 COP</strong> adicionales.
                                </div>
                                <div>
                                    <strong>2. Modalidad Degluciones:</strong> Aplica el 45% sobre el valor del examen cuando el especialista tiene configurada esta asignación.
                                </div>
                                <div>
                                    <strong>3. Tarifas Especiales:</strong> Liquidación diferenciada al 30% para pacientes particulares (<code>P</code>).
                                </div>
                                <div>
                                    <strong>4. Base de Cálculo:</strong> Si el examen está configurado como <code>VALOR_EXAMEN</code>, se toma el valor unitario de Servinte; si es <code>VALOR_LIQUIDACION</code>, se toma la tarifa fija de LIHO.
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 3: TARIFARIOS Y VIGENCIAS -->
                <section id="sec-tarifarios-vigencias" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-cyan-50 dark:bg-cyan-950/50 text-cyan-600 dark:text-cyan-400">
                            <span class="material-symbols-outlined text-2xl">calendar_month</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-cyan-600 dark:text-cyan-400 font-mono">Gestión Tarifaria</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">3. Tarifarios y Vigencias Temporales</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            Ubicado en <code>tarifario.php</code> e <code>historial_tarifario.php</code>. Permite administrar los precios unitarios de cada código CUPS y controlar su evolución temporal mediante vigencias.
                        </p>

                        <div class="p-4 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800/60 space-y-2">
                            <div class="flex items-center gap-2 font-bold text-indigo-900 dark:text-indigo-200">
                                <span class="material-symbols-outlined">update</span>
                                <span>¿Cómo funciona la resolución histórica de tarifas?</span>
                            </div>
                            <p class="text-xs text-indigo-950 dark:text-indigo-300">
                                Cuando se liquida un examen, LIHO examina la <strong>fecha de realización del procedimiento</strong>. Si un examen se realizó el 15 de enero de 2026, el sistema busca la tarifa cuya <code>vigencia_desde</code> sea menor o igual a esa fecha y cuya <code>vigencia_hasta</code> sea mayor o nula (abierta). Esto previene que aumentos de tarifas a mitad de año alteren liquidaciones de meses previos.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <h4 class="font-black text-xs uppercase tracking-wider text-primary dark:text-tertiary">Pasos para Actualizar Tarifas:</h4>
                            <ol class="list-decimal list-inside space-y-1.5 text-xs text-slate-600 dark:text-slate-300">
                                <li>Ingresar al módulo <strong>Tarifarios > Catálogo Tarifario</strong>.</li>
                                <li>Filtrar por código CUPS o descripción de examen.</li>
                                <li>Hacer clic en el botón de edición o usar la importación masiva por archivo Excel.</li>
                                <li>Definir la fecha <strong>Vigencia Desde</strong> del nuevo valor. La vigencia previa se cerrará automáticamente el día anterior para garantizar continuidad.</li>
                            </ol>
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 4: TARIFAS ESPECIALES Y BLOQUEOS -->
                <section id="sec-tarifas-especiales" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-purple-50 dark:bg-purple-950/50 text-purple-600 dark:text-purple-400">
                            <span class="material-symbols-outlined text-2xl">star</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-purple-600 dark:text-purple-400 font-mono">Excepciones & Control</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">4. Tarifas Especiales y Bloqueos de Cobro</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            Controla los acuerdos contractuales particulares y salvaguarda la IPS de pagos indebidos:
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-2">
                                <h4 class="font-bold text-xs text-purple-600 dark:text-purple-400 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base">star</span>
                                    <span>Tarifario Especial (<code>tarifario_especial.php</code>)</span>
                                </h4>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Permite asignar precios específicos a un examen cuando es ejecutado por un médico particular o bajo un convenio exclusivo.
                                </p>
                            </div>

                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-2">
                                <h4 class="font-bold text-xs text-rose-600 dark:text-rose-400 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base">block</span>
                                    <span>Bloqueos de Cobro (<code>tarifario_bloqueos.php</code>)</span>
                                </h4>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Inhabilita el pago de ciertos códigos CUPS que correspondan a procedimientos no reconocidos por convenio o cubiertos por otra vía.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 5: LIQUIDACIONES Y AJUSTES -->
                <section id="sec-liquidaciones-ajustes" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero,medico">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                            <span class="material-symbols-outlined text-2xl">payments</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-emerald-600 dark:text-emerald-400 font-mono">Finanzas & Pagos</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">5. Liquidaciones y Notas de Ajuste</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            Ubicado en <code>aprobacion_liquidaciones.php</code> y <code>notas_ajuste.php</code>.
                        </p>
                        <div class="space-y-3">
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700">
                                <span class="w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center font-bold text-xs shrink-0">1</span>
                                <div>
                                    <strong class="text-xs text-primary dark:text-white">Preliquidación:</strong>
                                    El sistema compila todos los exámenes del mes, deducciones tributarias (Retención en la Fuente, Retención ICA) y aportes parafiscales aplicables.
                                </div>
                            </div>
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700">
                                <span class="w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center font-bold text-xs shrink-0">2</span>
                                <div>
                                    <strong class="text-xs text-primary dark:text-white">Revisión y Aprobación:</strong>
                                    El equipo financiero valida los montos. Al marcar como <em>Aprobada</em>, el médico puede visualizar su desglose oficial.
                                </div>
                            </div>
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700">
                                <span class="w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center font-bold text-xs shrink-0">3</span>
                                <div>
                                    <strong class="text-xs text-primary dark:text-white">Notas de Ajuste:</strong>
                                    Si surge alguna discrepancia posterior, se emite una Nota Débito o Crédito en <code>notas_ajuste.php</code> especificando la justificación y monto exacto.
                                </div>
                            </div>
                            <div class="flex items-start gap-3 p-3 rounded-xl bg-amber-50/70 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60">
                                <span class="w-6 h-6 rounded-full bg-amber-500 text-white flex items-center justify-center font-bold text-xs shrink-0">4</span>
                                <div>
                                    <strong class="text-xs text-amber-900 dark:text-amber-200">Desglose de Conceptos e Incentivos (Bono Tomografías):</strong>
                                    En el modal de detalle (<em>Ver Detalle</em>) y en los informes por sede, se presenta un desglose claro e individualizado de los conceptos liquidados. Los incentivos de productividad por tomografías contrastadas (regla de 50 estudios contrastados × $150.000 COP) se muestran con insignias destacadas, banner corporativo e información detallada de la sede donde se causó.
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 6: DIRECTORIO MÉDICO -->
                <section id="sec-directorio-medico" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400">
                            <span class="material-symbols-outlined text-2xl">badge</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-blue-600 dark:text-blue-400 font-mono">Talento Médico</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">6. Directorio Médico y Asignación de Procedimientos</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            En <code>medicos.php</code> y <code>gestion_medicos_procedimientos.php</code> se gestiona el perfil asistencial de cada doctor:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-xs text-slate-600 dark:text-slate-300">
                            <li><strong>Entidad / IPS Perteneciente:</strong> Permite asignar al médico a Hernán Ocazionez o a entidades aliadas (ej. IMADINSA).</li>
                            <li><strong>Modalidades Especiales:</strong> Habilitar flags de Degluciones, Tarifas Especiales o Pago Dinámico.</li>
                            <li><strong>Catálogo de Procedimientos Autorizados:</strong> Matriz individual de códigos CUPS que el médico tiene avalados para informar.</li>
                        </ul>
                    </div>
                </section>

                <!-- SECCIÓN 7: CERTIFICADOS TRIBUTARIOS -->
                <section id="sec-certificados-tributarios" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero,medico">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                            <span class="material-symbols-outlined text-2xl">workspace_premium</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-amber-600 dark:text-amber-400 font-mono">Tributario</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">7. Certificados Tributarios (Retención en la Fuente)</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            En <code>certificados_tributarios.php</code>, tanto el área administrativa como cada médico especialista pueden consultar y descargar en formato PDF oficial su certificado de retención anual y bimestral.
                        </p>
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 text-xs">
                            <strong>Autoservicio Médico:</strong> Los médicos que inicien sesión con su usuario personal pueden ir al menú de usuario (esquina superior derecha) y hacer clic en <em>"Certificados Tributarios"</em> para descargar instantáneamente sus constancias sin necesidad de radicar solicitudes al departamento contable.
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 8: MAESTROS Y PARAFISCALES -->
                <section id="sec-maestros-parametros" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                            <span class="material-symbols-outlined text-2xl">tune</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-indigo-600 dark:text-indigo-400 font-mono">Configuración Global</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">8. Maestros Institucionales y Parafiscales</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <p>
                            Módulos accesibles desde la barra superior:
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 text-xs">
                                <span class="font-extrabold text-primary dark:text-white block mb-1">Maestro de Entidades</span>
                                Gestión multi-empresa: Hernán Ocazionez y Cía S.A.S., IMADINSA SAS y sedes asociadas.
                            </div>
                            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 text-xs">
                                <span class="font-extrabold text-primary dark:text-white block mb-1">Porcentajes de Pago</span>
                                Escalas dinámicas por modalidad (porcentual o valor fijo en pesos).
                            </div>
                            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 text-xs">
                                <span class="font-extrabold text-primary dark:text-white block mb-1">Parafiscales</span>
                                Tasas legales vigentes: IBC (40%), Salud (12.5%), Pensión (16.0%), ARL y retenciones.
                            </div>
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 9: SEGURIDAD Y AUDITORÍA -->
                <section id="sec-seguridad-auditoria" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400">
                            <span class="material-symbols-outlined text-2xl">security</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-rose-600 dark:text-rose-400 font-mono">Control Forense</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">9. Seguridad, Roles y Auditoría</h2>
                        </div>
                    </div>

                    <div class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-4 leading-relaxed font-medium">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 space-y-2">
                                <h4 class="font-bold text-xs text-primary dark:text-white flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base text-amber-500">lock</span>
                                    <span>Modo Desarrollo / Protección Correos</span>
                                </h4>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Impide que se envíen correos de prueba a los médicos reales mientras se realizan ajustes, redirigiéndolos de forma segura a una casilla técnica.
                                </p>
                            </div>

                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 space-y-2">
                                <h4 class="font-bold text-xs text-primary dark:text-white flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base text-rose-500">history</span>
                                    <span>Auditoría Inalterable (<code>logs.php</code>)</span>
                                </h4>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Cada inserción, edición de tarifa, cambio de rol o eliminación queda registrada con dirección IP, timestamp, usuario y valores previos y nuevos.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- SECCIÓN 10: HISTORIAL DE VERSIONES -->
                <section id="sec-historial-versiones" class="manual-section bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-8 border border-slate-200/80 dark:border-slate-800 shadow-sm" data-roles="all,admin,financiero,medico">
                    <div class="flex items-center gap-3 border-b border-slate-100 dark:border-slate-800 pb-4 mb-6">
                        <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-emerald-950/50 text-emerald-500">
                            <span class="material-symbols-outlined text-2xl">history_toggle_off</span>
                        </div>
                        <div>
                            <span class="text-[11px] font-black uppercase tracking-wider text-emerald-600 dark:text-tertiary font-mono">Control de Versiones LIHO</span>
                            <h2 class="text-xl sm:text-2xl font-black text-primary dark:text-white">10. Versiones del Sistema y Bitácora de Cambios</h2>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-slate-100/70 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="px-3 py-1 rounded-xl bg-tertiary/20 text-tertiary border border-tertiary/30 font-black text-xs">v<?php echo LIHO_VERSION; ?></span>
                                <div>
                                    <h4 class="text-xs font-black text-primary dark:text-white"><?php echo LIHO_VERSION_NAME; ?></h4>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400">Liberada el <?php echo LIHO_VERSION_DATE; ?></p>
                                </div>
                            </div>
                            <a href="CHANGELOG.md" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-bold text-teal-600 dark:text-tertiary hover:underline">
                                <span>Ver CHANGELOG.md completo</span>
                                <span class="material-symbols-outlined text-sm">open_in_new</span>
                            </a>
                        </div>

                        <!-- Resumen Dinámico de Cambios -->
                        <div class="space-y-3 pt-2 text-xs text-slate-600 dark:text-slate-300">
                            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700/60 space-y-2">
                                <span class="font-extrabold text-primary dark:text-white block text-xs">🚀 Novedades v1.1.0:</span>
                                <ul class="list-disc list-inside space-y-1 text-slate-500 dark:text-slate-400">
                                    <li>Lanzamiento del <strong>Manual de Uso Corporativo Interactivo</strong> con filtrado por rol y búsqueda en vivo.</li>
                                    <li>Botones de acceso destacados con badges de versión en el header principal y en el panel de bienvenida.</li>
                                    <li>Centralización del versionado institucional en <code>config/version.php</code> sincronizado con Git y GitHub.</li>
                                    <li>Corrección de advertencia de variable unitaria en conciliación de exámenes médicos.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

            </main>
        </div>
    </div>

    <!-- Footer Global -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <!-- Scripts Interactivos para el Manual -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('manualSearchInput');
            const sections = document.querySelectorAll('.manual-section');
            const roleButtons = document.querySelectorAll('.role-filter-btn');
            const navLinks = document.querySelectorAll('.manual-nav-link');

            // 1. Buscador en Vivo de Secciones
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const query = e.target.value.toLowerCase().trim();
                    sections.forEach(sec => {
                        const text = sec.innerText.toLowerCase();
                        if (!query || text.includes(query)) {
                            sec.style.display = 'block';
                        } else {
                            sec.style.display = 'none';
                        }
                    });
                });
            }

            // 2. Filtro por Rol
            roleButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    roleButtons.forEach(b => {
                        b.classList.remove('bg-tertiary', 'text-primary');
                        b.classList.add('bg-slate-100', 'dark:bg-slate-800', 'text-slate-600', 'dark:text-slate-300');
                    });
                    btn.classList.remove('bg-slate-100', 'dark:bg-slate-800', 'text-slate-600', 'dark:text-slate-300');
                    btn.classList.add('bg-tertiary', 'text-primary');

                    const selectedRole = btn.getAttribute('data-role');
                    sections.forEach(sec => {
                        const roles = (sec.getAttribute('data-roles') || '').split(',');
                        if (selectedRole === 'all' || roles.includes(selectedRole)) {
                            sec.style.display = 'block';
                        } else {
                            sec.style.display = 'none';
                        }
                    });
                });
            });

            // 3. Resaltar link activo al hacer scroll
            window.addEventListener('scroll', () => {
                let current = '';
                sections.forEach(sec => {
                    const secTop = sec.offsetTop;
                    if (window.pageYOffset >= secTop - 150) {
                        current = sec.getAttribute('id');
                    }
                });

                navLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === `#${current}`) {
                        link.classList.add('active');
                    }
                });
            });
        });
    </script>
</body>
</html>

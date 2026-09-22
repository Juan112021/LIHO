<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');
require_once(__DIR__ . '/includes/vigencias_helper.php');

$userName  = $_SESSION['user_name'] ?? 'Usuario';
$userRole  = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId    = (int)($_SESSION['user_id'] ?? 0);
$userEmail = $_SESSION['user_email'] ?? '';

$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

// Control estricto de acceso al catálogo tarifario
if ($userRole !== 'ADMINISTRADOR' && $userRoleIdVal !== 1 && !tienePermisoModulo($userId, $userRoleIdVal, 'tarifario_especial')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// Permisos para editar tarifario (ÚNICAMENTE Administradores [rol_id 1] y Financiera [rol_id 2])
$canEditTarifa = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

// --------------------------------------------------------------------------
// CARGAR ENTIDADES Y CONFIGURACIÓN DE TARIFARIO POR EMPRESA
// --------------------------------------------------------------------------
$entidadesList = [];
$hoId = 4; // ID oficial de Hernán Ocazionez en maestro_entidades

$entidadesList['PROPIO'] = [
    'id'         => 'PROPIO',
    'id_int'     => $hoId,
    'nombre'     => 'HERNÁN OCAZIONEZ Y CÍA S.A.S.',
    'subtitulo'  => 'Tarifario General Matriz - Sistemas Diagnósticos',
    'tipo_ips'   => 'IPS Matriz / Propia',
    'nit'        => '800.149.695-1',
    'color_tema' => 'teal',
    'logo'       => 'assets/img/hologo.png'
];

$entidadesExternas = [];
if (isset($con) && $con !== false) {
    $sqlEnt = "SELECT id, nombre, nit, dv, logo, color_tema, ciudad, ISNULL(is_matriz, 0) AS is_matriz 
               FROM maestro_entidades 
               WHERE ISNULL(estado, 1) = 1 
               ORDER BY CASE WHEN ISNULL(is_matriz, 0) = 1 THEN 0 ELSE 1 END, id ASC";
    $stmtEnt = sqlsrv_query($con, $sqlEnt);
    if ($stmtEnt !== false) {
        while ($rE = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
            $eId = (int)$rE['id'];
            $nitComp = trim($rE['nit'] . (!empty($rE['dv']) ? '-' . $rE['dv'] : ''));
            $esMatriz = ((int)$rE['is_matriz'] === 1 || stripos($rE['nombre'], 'HERNAN') !== false || $eId === $hoId);

            if ($esMatriz) {
                $hoId = $eId;
                $hoData = [
                    'id'         => 'PROPIO',
                    'id_int'     => $hoId,
                    'nombre'     => $rE['nombre'],
                    'subtitulo'  => 'Tarifario General Matriz - Sistemas Diagnósticos',
                    'tipo_ips'   => 'IPS Matriz / Propia',
                    'nit'        => $nitComp,
                    'color_tema' => 'teal',
                    'logo'       => !empty($rE['logo']) ? $rE['logo'] : 'assets/img/hologo.png'
                ];
                $entidadesList['PROPIO'] = $hoData;
                $entidadesList[(string)$hoId] = $hoData;
            } else {
                $itemEnt = [
                    'id'         => (string)$eId,
                    'id_int'     => $eId,
                    'nombre'     => $rE['nombre'],
                    'subtitulo'  => !empty($rE['ciudad']) ? 'Sede ' . $rE['ciudad'] : 'Entidad en Convenio IPS',
                    'tipo_ips'   => 'IPS Externa',
                    'nit'        => $nitComp,
                    'color_tema' => !empty($rE['color_tema']) ? $rE['color_tema'] : 'purple',
                    'logo'       => !empty($rE['logo']) ? $rE['logo'] : ''
                ];
                $entidadesList[(string)$eId] = $itemEnt;
                $entidadesExternas[] = $itemEnt;
            }
        }
    }
}

// Entidad activa seleccionada
$entidadSeleccionada = isset($_GET['entidad_id']) ? trim($_GET['entidad_id']) : ($_SESSION['tarifario_entidad_id'] ?? 'PROPIO');
if ($entidadSeleccionada === (string)$hoId) {
    $entidadSeleccionada = 'PROPIO';
}
if (!isset($entidadesList[$entidadSeleccionada])) {
    $entidadSeleccionada = 'PROPIO';
}
$_SESSION['tarifario_entidad_id'] = $entidadSeleccionada;
$entidadActiva = $entidadesList[$entidadSeleccionada];
$entidadActivaIdInt = (int)$entidadActiva['id_int'];

// Helper para paletas de entidades (compartido y sincronizado con maestro_porcentajes.php)
if (!function_exists('obtenerPaletaEntidad')) {
function obtenerPaletaEntidad($colorTema, $entId = '') {
    $c = strtolower(trim((string)$colorTema));
    if ($entId === 'PROPIO' || empty($entId) || $c === 'teal') {
        return [
            'key'            => 'teal',
            'nombre'         => 'Verde Esmeralda Institucional',
            'primary_hex'    => '#00b4b0',
            'secondary_hex'  => '#059669',
            'hex'            => '#00b4b0',
            'hex_dark'       => '#009693',
            'badge_border'   => 'border-teal-500/40 dark:border-teal-500/50',
            'badge_bg'       => 'bg-teal-50/70 dark:bg-teal-950/40',
            'badge_shadow'   => 'shadow-teal-500/10 dark:shadow-none',
            'logo_border'    => 'border-teal-300 dark:border-teal-700',
            'logo_ring'      => 'ring-4 ring-teal-500/20',
            'dot_pulse'      => 'bg-emerald-500',
            'pill_text'      => 'text-teal-600 dark:text-teal-400',
            'accent_text'    => 'text-teal-700 dark:text-teal-300',
            'cambiar_hover'  => 'hover:text-teal-600 dark:hover:text-teal-400 hover:bg-teal-50 dark:hover:bg-teal-950/40 hover:border-teal-300 dark:hover:border-teal-700',
            'blur_ambient'   => 'from-teal-500/25 via-emerald-500/15 to-transparent',
            'banner_border'  => 'border-teal-500/50 dark:border-teal-500/60',
            'banner_bg'      => 'bg-gradient-to-r from-teal-500/15 via-emerald-500/[0.06] to-white dark:from-teal-950/60 dark:via-emerald-950/30 dark:to-slate-900',
            'banner_shadow'  => 'shadow-xl shadow-teal-500/15 dark:shadow-teal-950/40',
            'tag_badge'      => 'bg-teal-500/15 text-teal-800 dark:bg-teal-950/70 dark:text-teal-200 border-teal-300/80 dark:border-teal-700',
            'tab_active'     => 'bg-teal-600 hover:bg-teal-700 text-white shadow-md shadow-teal-600/30',
            'btn_gradient'   => 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-md shadow-emerald-600/25 hover:shadow-lg',
            'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-teal-700 dark:text-teal-300 hover:bg-teal-50 dark:hover:bg-teal-950/50 border-2 border-teal-400/60 dark:border-teal-600/60 shadow-sm hover:shadow-md',
            'icon_color'     => 'text-teal-600 dark:text-teal-400',
            'table_col_bg'   => 'bg-emerald-50/60 dark:bg-emerald-950/30 text-emerald-900 dark:text-emerald-300',
            'card_border'    => 'border-teal-500',
            'card_bg'        => 'bg-teal-50/70 dark:bg-teal-950/20 border-teal-200 dark:border-teal-800/60',
            'card_shadow'    => 'shadow-teal-500/10',
            'card_btn'       => 'bg-teal-600 hover:bg-teal-700 text-white shadow-teal-600/20',
            'card_badge'     => 'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-200 border-teal-200 dark:border-teal-700/60',
            'kpi_accent_bg'  => 'bg-teal-100 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300 border border-teal-200 dark:border-teal-800/60',
            'icon_bg'        => 'bg-teal-100 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300 border border-teal-200 dark:border-teal-800/60',
            'badge'          => 'bg-teal-100 text-teal-800 dark:bg-teal-900/50 dark:text-teal-200 border-teal-200 dark:border-teal-700/60',
            'pill'           => 'bg-teal-50 dark:bg-teal-950/50 text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-800/60',
            'text_accent'    => 'text-teal-600 dark:text-teal-400',
            'border_accent'  => 'border-teal-500',
            'ring_focus'     => 'focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20',
            'btn'            => 'bg-teal-600 hover:bg-teal-700 text-white shadow-md shadow-teal-600/30',
            'indicator'      => 'bg-teal-500',
            'border_active'  => 'border-teal-500 ring-2 ring-teal-500/50 shadow-lg shadow-teal-500/25',
        ];
    }

    switch ($c) {
        case 'purple':
        case 'violet':
            return [
                'key'            => 'purple',
                'nombre'         => 'Púrpura Institucional',
                'primary_hex'    => '#8b5cf6',
                'secondary_hex'  => '#7c3aed',
                'hex'            => '#8b5cf6',
                'hex_dark'       => '#7c3aed',
                'badge_border'   => 'border-purple-500/40 dark:border-purple-500/50',
                'badge_bg'       => 'bg-purple-50/70 dark:bg-purple-950/40',
                'badge_shadow'   => 'shadow-purple-500/10 dark:shadow-none',
                'logo_border'    => 'border-purple-300 dark:border-purple-700',
                'logo_ring'      => 'ring-4 ring-purple-500/25',
                'dot_pulse'      => 'bg-purple-500',
                'pill_text'      => 'text-purple-600 dark:text-purple-400',
                'accent_text'    => 'text-purple-700 dark:text-purple-300',
                'cambiar_hover'  => 'hover:text-purple-600 dark:hover:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-950/40 hover:border-purple-300 dark:hover:border-purple-700',
                'blur_ambient'   => 'from-purple-500/25 via-violet-500/15 to-transparent',
                'banner_border'  => 'border-purple-500/60 dark:border-purple-500/70',
                'banner_bg'      => 'bg-gradient-to-r from-purple-500/15 via-violet-500/[0.08] to-white dark:from-purple-950/60 dark:via-violet-950/30 dark:to-slate-900',
                'banner_shadow'  => 'shadow-xl shadow-purple-500/15 dark:shadow-purple-950/40',
                'tag_badge'      => 'bg-purple-500/15 text-purple-800 dark:bg-purple-950/70 dark:text-purple-200 border-purple-300/80 dark:border-purple-700',
                'tab_active'     => 'bg-purple-600 hover:bg-purple-700 text-white shadow-md shadow-purple-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-500 hover:to-violet-500 text-white shadow-md shadow-purple-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-950/50 border-2 border-purple-400/60 dark:border-purple-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-purple-600 dark:text-purple-400',
                'table_col_bg'   => 'bg-purple-50/60 dark:bg-purple-950/30 text-purple-900 dark:text-purple-300',
                'card_border'    => 'border-purple-500',
                'card_bg'        => 'bg-purple-50/70 dark:bg-purple-950/20 border-purple-200 dark:border-purple-800/60',
                'card_shadow'    => 'shadow-purple-500/10',
                'card_btn'       => 'bg-purple-600 hover:bg-purple-700 text-white shadow-purple-600/20',
                'card_badge'     => 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-200 border-purple-200 dark:border-purple-700/60',
                'kpi_accent_bg'  => 'bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60',
                'icon_bg'        => 'bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60',
                'badge'          => 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-200 border-purple-200 dark:border-purple-700/60',
                'pill'           => 'bg-purple-50 dark:bg-purple-950/50 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/60',
                'text_accent'    => 'text-purple-600 dark:text-purple-400',
                'border_accent'  => 'border-purple-500',
                'ring_focus'     => 'focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20',
                'btn'            => 'bg-purple-600 hover:bg-purple-700 text-white shadow-md shadow-purple-600/30',
                'indicator'      => 'bg-purple-500',
                'border_active'  => 'border-purple-500 ring-2 ring-purple-500/50 shadow-lg shadow-purple-500/25',
            ];

        case 'indigo':
        case 'blue':
            return [
                'key'            => 'indigo',
                'nombre'         => 'Azul Índigo Corporativo',
                'primary_hex'    => '#4f46e5',
                'secondary_hex'  => '#3b82f6',
                'hex'            => '#6366f1',
                'hex_dark'       => '#4f46e5',
                'badge_border'   => 'border-indigo-500/40 dark:border-indigo-500/50',
                'badge_bg'       => 'bg-indigo-50/70 dark:bg-indigo-950/40',
                'badge_shadow'   => 'shadow-indigo-500/10 dark:shadow-none',
                'logo_border'    => 'border-indigo-300 dark:border-indigo-700',
                'logo_ring'      => 'ring-4 ring-indigo-500/25',
                'dot_pulse'      => 'bg-indigo-500',
                'pill_text'      => 'text-indigo-600 dark:text-indigo-400',
                'accent_text'    => 'text-indigo-700 dark:text-indigo-300',
                'cambiar_hover'  => 'hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 hover:border-indigo-300 dark:hover:border-indigo-700',
                'blur_ambient'   => 'from-indigo-500/25 via-blue-500/15 to-transparent',
                'banner_border'  => 'border-indigo-500/60 dark:border-indigo-500/70',
                'banner_bg'      => 'bg-gradient-to-r from-indigo-500/15 via-blue-500/[0.08] to-white dark:from-indigo-950/60 dark:via-blue-950/30 dark:to-slate-900',
                'banner_shadow'  => 'shadow-xl shadow-indigo-500/15 dark:shadow-indigo-950/40',
                'tag_badge'      => 'bg-indigo-500/15 text-indigo-800 dark:bg-indigo-950/70 dark:text-indigo-200 border-indigo-300/80 dark:border-indigo-700',
                'tab_active'     => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white shadow-md shadow-indigo-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 border-2 border-indigo-400/60 dark:border-indigo-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-indigo-600 dark:text-indigo-400',
                'table_col_bg'   => 'bg-indigo-50/60 dark:bg-indigo-950/30 text-indigo-900 dark:text-indigo-300',
                'card_border'    => 'border-indigo-500',
                'card_bg'        => 'bg-indigo-50/70 dark:bg-indigo-950/20 border-indigo-200 dark:border-indigo-800/60',
                'card_shadow'    => 'shadow-indigo-500/10',
                'card_btn'       => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-600/20',
                'card_badge'     => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200 border-indigo-200 dark:border-indigo-700/60',
                'kpi_accent_bg'  => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/60',
                'icon_bg'        => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/60',
                'badge'          => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-200 border-indigo-200 dark:border-indigo-700/60',
                'pill'           => 'bg-indigo-50 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800/60',
                'text_accent'    => 'text-indigo-600 dark:text-indigo-400',
                'border_accent'  => 'border-indigo-500',
                'ring_focus'     => 'focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20',
                'btn'            => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-600/30',
                'indicator'      => 'bg-indigo-500',
                'border_active'  => 'border-indigo-500 ring-2 ring-indigo-500/50 shadow-lg shadow-indigo-500/25',
            ];

        case 'sky':
            return [
                'key'            => 'sky',
                'nombre'         => 'Azul Cielo',
                'primary_hex'    => '#0284c7',
                'secondary_hex'  => '#0ea5e9',
                'hex'            => '#0284c7',
                'hex_dark'       => '#0369a1',
                'badge_border'   => 'border-sky-500/40 dark:border-sky-500/50',
                'badge_bg'       => 'bg-sky-50/70 dark:bg-sky-950/40',
                'badge_shadow'   => 'shadow-sky-500/10 dark:shadow-none',
                'logo_border'    => 'border-sky-300 dark:border-sky-700',
                'logo_ring'      => 'ring-4 ring-sky-500/25',
                'dot_pulse'      => 'bg-sky-500',
                'pill_text'      => 'text-sky-600 dark:text-sky-400',
                'accent_text'    => 'text-sky-700 dark:text-sky-300',
                'cambiar_hover'  => 'hover:text-sky-600 dark:hover:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-950/40 hover:border-sky-300 dark:hover:border-sky-700',
                'blur_ambient'   => 'from-sky-500/25 via-cyan-500/15 to-transparent',
                'banner_border'  => 'border-sky-500/60 dark:border-sky-500/70',
                'banner_bg'      => 'bg-gradient-to-r from-sky-500/15 via-cyan-500/[0.08] to-white dark:from-sky-950/60 dark:via-cyan-950/30 dark:to-slate-900',
                'banner_shadow'  => 'shadow-xl shadow-sky-500/15 dark:shadow-sky-950/40',
                'tag_badge'      => 'bg-sky-500/15 text-sky-800 dark:bg-sky-950/70 dark:text-sky-200 border-sky-300/80 dark:border-sky-700',
                'tab_active'     => 'bg-sky-600 hover:bg-sky-700 text-white shadow-md shadow-sky-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-sky-600 to-cyan-600 hover:from-sky-500 hover:to-cyan-500 text-white shadow-md shadow-sky-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-sky-700 dark:text-sky-300 hover:bg-sky-50 dark:hover:bg-sky-950/50 border-2 border-sky-400/60 dark:border-sky-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-sky-600 dark:text-sky-400',
                'table_col_bg'   => 'bg-sky-50/60 dark:bg-sky-950/30 text-sky-900 dark:text-sky-300',
                'card_border'    => 'border-sky-500',
                'card_bg'        => 'bg-sky-50/70 dark:bg-sky-950/20 border-sky-200 dark:border-sky-800/60',
                'card_shadow'    => 'shadow-sky-500/10',
                'card_btn'       => 'bg-sky-600 hover:bg-sky-700 text-white shadow-sky-600/20',
                'card_badge'     => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200 border-sky-200 dark:border-sky-700/60',
                'kpi_accent_bg'  => 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300 border border-sky-200 dark:border-sky-800/60',
                'icon_bg'        => 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300 border border-sky-200 dark:border-sky-800/60',
                'badge'          => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200 border-sky-200 dark:border-sky-700/60',
                'pill'           => 'bg-sky-50 dark:bg-sky-950/50 text-sky-700 dark:text-sky-300 border border-sky-200 dark:border-sky-800/60',
                'text_accent'    => 'text-sky-600 dark:text-sky-400',
                'border_accent'  => 'border-sky-500',
                'ring_focus'     => 'focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20',
                'btn'            => 'bg-sky-600 hover:bg-sky-700 text-white shadow-md shadow-sky-600/30',
                'indicator'      => 'bg-sky-500',
                'border_active'  => 'border-sky-500 ring-2 ring-sky-500/50 shadow-lg shadow-sky-500/25',
            ];

        case 'rose':
            return [
                'key'            => 'rose',
                'nombre'         => 'Rosa Rubí',
                'primary_hex'    => '#e11d48',
                'secondary_hex'  => '#f43f5e',
                'hex'            => '#e11d48',
                'hex_dark'       => '#be123c',
                'badge_border'   => 'border-rose-500/40 dark:border-rose-500/50',
                'badge_bg'       => 'bg-rose-50/70 dark:bg-rose-950/40',
                'badge_shadow'   => 'shadow-rose-500/10 dark:shadow-none',
                'logo_border'    => 'border-rose-300 dark:border-rose-700',
                'logo_ring'      => 'ring-4 ring-rose-500/25',
                'dot_pulse'      => 'bg-rose-500',
                'pill_text'      => 'text-rose-600 dark:text-rose-400',
                'accent_text'    => 'text-rose-700 dark:text-rose-300',
                'cambiar_hover'  => 'hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 hover:border-rose-300 dark:hover:border-rose-700',
                'blur_ambient'   => 'from-rose-500/25 via-pink-500/15 to-transparent',
                'banner_border'  => 'border-rose-500/60 dark:border-rose-500/70',
                'banner_bg'      => 'bg-gradient-to-r from-rose-500/15 via-pink-500/[0.08] to-white dark:from-rose-950/60 dark:via-pink-950/30 dark:to-slate-900',
                'banner_shadow'  => 'shadow-xl shadow-rose-500/15 dark:shadow-rose-950/40',
                'tag_badge'      => 'bg-rose-500/15 text-rose-800 dark:bg-rose-950/70 dark:text-rose-200 border-rose-300/80 dark:border-rose-700',
                'tab_active'     => 'bg-rose-600 hover:bg-rose-700 text-white shadow-md shadow-rose-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-500 hover:to-pink-500 text-white shadow-md shadow-rose-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-rose-700 dark:text-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/50 border-2 border-rose-400/60 dark:border-rose-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-rose-600 dark:text-rose-400',
                'table_col_bg'   => 'bg-rose-50/60 dark:bg-rose-950/30 text-rose-900 dark:text-rose-300',
                'card_border'    => 'border-rose-500',
                'card_bg'        => 'bg-rose-50/70 dark:bg-rose-950/20 border-rose-200 dark:border-rose-800/60',
                'card_shadow'    => 'shadow-rose-500/10',
                'card_btn'       => 'bg-rose-600 hover:bg-rose-700 text-white shadow-rose-600/20',
                'card_badge'     => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200 border-rose-200 dark:border-rose-700/60',
                'kpi_accent_bg'  => 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800/60',
                'icon_bg'        => 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200 dark:border-rose-800/60',
                'badge'          => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200 border-rose-200 dark:border-rose-700/60',
                'pill'           => 'bg-rose-50 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/60',
                'text_accent'    => 'text-rose-600 dark:text-rose-400',
                'border_accent'  => 'border-rose-500',
                'ring_focus'     => 'focus:border-rose-500 focus:ring-2 focus:ring-rose-500/20',
                'btn'            => 'bg-rose-600 hover:bg-rose-700 text-white shadow-md shadow-rose-600/30',
                'indicator'      => 'bg-rose-500',
                'border_active'  => 'border-rose-500 ring-2 ring-rose-500/50 shadow-lg shadow-rose-500/25',
            ];

        case 'amber':
        case 'orange':
            return [
                'key'            => 'amber',
                'nombre'         => 'Ámbar Cálido',
                'primary_hex'    => '#d97706',
                'secondary_hex'  => '#f59e0b',
                'hex'            => '#d97706',
                'hex_dark'       => '#b45309',
                'badge_border'   => 'border-amber-500/40 dark:border-amber-500/50',
                'badge_bg'       => 'bg-amber-50/70 dark:bg-amber-950/40',
                'badge_shadow'   => 'shadow-amber-500/10 dark:shadow-none',
                'logo_border'    => 'border-amber-300 dark:border-amber-700',
                'logo_ring'      => 'ring-4 ring-amber-500/25',
                'dot_pulse'      => 'bg-amber-500',
                'pill_text'      => 'text-amber-600 dark:text-amber-400',
                'accent_text'    => 'text-amber-700 dark:text-amber-300',
                'cambiar_hover'  => 'hover:text-amber-600 dark:hover:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/40 hover:border-amber-300 dark:hover:border-amber-700',
                'blur_ambient'   => 'from-amber-500/25 via-orange-500/15 to-transparent',
                'banner_border'  => 'border-amber-500/60 dark:border-amber-500/70',
                'banner_bg'      => 'bg-gradient-to-r from-amber-500/15 via-orange-500/[0.08] to-white dark:from-amber-950/60 dark:via-orange-950/30 dark:to-slate-900',
                'banner_shadow'  => 'shadow-xl shadow-amber-500/15 dark:shadow-amber-950/40',
                'tag_badge'      => 'bg-amber-500/15 text-amber-800 dark:bg-amber-950/70 dark:text-amber-200 border-amber-300/80 dark:border-amber-700',
                'tab_active'     => 'bg-amber-600 hover:bg-amber-700 text-white shadow-md shadow-amber-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white shadow-md shadow-amber-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/50 border-2 border-amber-400/60 dark:border-amber-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-amber-600 dark:text-amber-400',
                'table_col_bg'   => 'bg-amber-50/60 dark:bg-amber-950/30 text-amber-900 dark:text-amber-300',
                'card_border'    => 'border-amber-500',
                'card_bg'        => 'bg-amber-50/70 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800/60',
                'card_shadow'    => 'shadow-amber-500/10',
                'card_btn'       => 'bg-amber-600 hover:bg-amber-700 text-white shadow-amber-600/20',
                'card_badge'     => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200 border-amber-200 dark:border-amber-700/60',
                'kpi_accent_bg'  => 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60',
                'icon_bg'        => 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60',
                'badge'          => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200 border-amber-200 dark:border-amber-700/60',
                'pill'           => 'bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60',
                'text_accent'    => 'text-amber-600 dark:text-amber-400',
                'border_accent'  => 'border-amber-500',
                'ring_focus'     => 'focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20',
                'btn'            => 'bg-amber-600 hover:bg-amber-700 text-white shadow-md shadow-amber-600/30',
                'indicator'      => 'bg-amber-500',
                'border_active'  => 'border-amber-500 ring-2 ring-amber-500/50 shadow-lg shadow-amber-500/25',
            ];

        case 'emerald':
            return [
                'key'            => 'emerald',
                'nombre'         => 'Verde Esmeralda',
                'primary_hex'    => '#059669',
                'secondary_hex'  => '#047857',
                'hex'            => '#059669',
                'hex_dark'       => '#047857',
                'badge_border'   => 'border-emerald-500/40 dark:border-emerald-500/50',
                'badge_bg'       => 'bg-emerald-50/70 dark:bg-emerald-950/40',
                'badge_shadow'   => 'shadow-emerald-500/10 dark:shadow-none',
                'logo_border'    => 'border-emerald-300 dark:border-emerald-700',
                'logo_ring'      => 'ring-4 ring-emerald-500/25',
                'dot_pulse'      => 'bg-emerald-500',
                'pill_text'      => 'text-emerald-600 dark:text-emerald-400',
                'accent_text'    => 'text-emerald-700 dark:text-emerald-300',
                'cambiar_hover'  => 'hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 hover:border-emerald-300 dark:hover:border-emerald-700',
                'blur_ambient'   => 'from-emerald-500/25 via-teal-500/15 to-transparent',
                'banner_border'  => 'border-emerald-500/60 dark:border-emerald-500/70',
                'banner_bg'      => 'bg-gradient-to-r from-emerald-500/15 via-teal-500/[0.08] to-white dark:from-emerald-950/60 dark:via-teal-950/30 dark:to-slate-900',
                'banner_shadow'  => 'shadow-xl shadow-emerald-500/15 dark:shadow-emerald-950/40',
                'tag_badge'      => 'bg-emerald-500/15 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-200 border-emerald-300/80 dark:border-emerald-700',
                'tab_active'     => 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-md shadow-emerald-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-md shadow-emerald-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-50 dark:hover:bg-emerald-950/50 border-2 border-emerald-400/60 dark:border-emerald-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-emerald-600 dark:text-emerald-400',
                'table_col_bg'   => 'bg-emerald-50/60 dark:bg-emerald-950/30 text-emerald-900 dark:text-emerald-300',
                'card_border'    => 'border-emerald-500',
                'card_bg'        => 'bg-emerald-50/70 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-800/60',
                'card_shadow'    => 'shadow-emerald-500/10',
                'card_btn'       => 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-emerald-600/20',
                'card_badge'     => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200 border-emerald-200 dark:border-emerald-700/60',
                'kpi_accent_bg'  => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60',
                'icon_bg'        => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60',
                'badge'          => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200 border-emerald-200 dark:border-emerald-700/60',
                'pill'           => 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60',
                'text_accent'    => 'text-emerald-600 dark:text-emerald-400',
                'border_accent'  => 'border-emerald-500',
                'ring_focus'     => 'focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20',
                'btn'            => 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-md shadow-emerald-600/30',
                'indicator'      => 'bg-emerald-500',
                'border_active'  => 'border-emerald-500 ring-2 ring-emerald-500/50 shadow-lg shadow-emerald-500/25',
            ];

        case 'cyan':
            return [
                'key'            => 'cyan',
                'nombre'         => 'Cian Brillante',
                'primary_hex'    => '#06b6d4',
                'secondary_hex'  => '#0891b2',
                'hex'            => '#06b6d4',
                'hex_dark'       => '#0891b2',
                'badge_border'   => 'border-cyan-500/40 dark:border-cyan-500/50',
                'badge_bg'       => 'bg-cyan-50/70 dark:bg-cyan-950/40',
                'badge_shadow'   => 'shadow-cyan-500/10 dark:shadow-none',
                'logo_border'    => 'border-cyan-300 dark:border-cyan-700',
                'logo_ring'      => 'ring-4 ring-cyan-500/25',
                'dot_pulse'      => 'bg-cyan-500',
                'pill_text'      => 'text-cyan-600 dark:text-cyan-400',
                'accent_text'    => 'text-cyan-700 dark:text-cyan-300',
                'cambiar_hover'  => 'hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-cyan-50 dark:hover:bg-cyan-950/40 hover:border-cyan-300 dark:hover:border-cyan-700',
                'blur_ambient'   => 'from-cyan-500/25 via-sky-500/15 to-transparent',
                'banner_border'  => 'border-cyan-500/60 dark:border-cyan-500/70',
                'banner_bg'      => 'bg-gradient-to-r from-cyan-500/15 via-sky-500/[0.08] to-white dark:from-cyan-950/60 dark:via-sky-950/30 dark:to-slate-900',
                'banner_shadow'  => 'shadow-xl shadow-cyan-500/15 dark:shadow-cyan-950/40',
                'tag_badge'      => 'bg-cyan-500/15 text-cyan-800 dark:bg-cyan-950/70 dark:text-cyan-200 border-cyan-300/80 dark:border-cyan-700',
                'tab_active'     => 'bg-cyan-600 hover:bg-cyan-700 text-white shadow-md shadow-cyan-600/30',
                'btn_gradient'   => 'bg-gradient-to-r from-cyan-600 to-sky-600 hover:from-cyan-500 hover:to-sky-500 text-white shadow-md shadow-cyan-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-cyan-700 dark:text-cyan-300 hover:bg-cyan-50 dark:hover:bg-cyan-950/50 border-2 border-cyan-400/60 dark:border-cyan-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-cyan-600 dark:text-cyan-400',
                'table_col_bg'   => 'bg-cyan-50/60 dark:bg-cyan-950/30 text-cyan-900 dark:text-cyan-300',
                'card_border'    => 'border-cyan-500',
                'card_bg'        => 'bg-cyan-50/70 dark:bg-cyan-950/20 border-cyan-200 dark:border-cyan-800/60',
                'card_shadow'    => 'shadow-cyan-500/10',
                'card_btn'       => 'bg-cyan-600 hover:bg-cyan-700 text-white shadow-cyan-600/20',
                'card_badge'     => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/50 dark:text-cyan-200 border-cyan-200 dark:border-cyan-700/60',
                'kpi_accent_bg'  => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950/60 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800/60',
                'icon_bg'        => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950/60 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800/60',
                'badge'          => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/50 dark:text-cyan-200 border-cyan-200 dark:border-cyan-700/60',
                'pill'           => 'bg-cyan-50 dark:bg-cyan-950/50 text-cyan-700 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800/60',
                'text_accent'    => 'text-cyan-600 dark:text-cyan-400',
                'border_accent'  => 'border-cyan-500',
                'ring_focus'     => 'focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20',
                'btn'            => 'bg-cyan-600 hover:bg-cyan-700 text-white shadow-md shadow-cyan-600/30',
                'indicator'      => 'bg-cyan-500',
                'border_active'  => 'border-cyan-500 ring-2 ring-cyan-500/50 shadow-lg shadow-cyan-500/25',
            ];

        default:
            return obtenerPaletaEntidad('teal', 'PROPIO');
    }
}
}
if (!function_exists('obtenerPaletaTema')) {
    function obtenerPaletaTema($color, $entId = '') {
        return obtenerPaletaEntidad($color, $entId);
    }
}
$paletaModal = obtenerPaletaEntidad($entidadActiva['color_tema'] ?? 'teal', $entidadSeleccionada);

// Conteo de tarifas por entidad para las pestañas
$tarifasCountPorEntidad = [];
if (isset($con) && $con !== false) {
    $sqlCnt = "SELECT ISNULL(entidad_id, 0) AS ent_id, COUNT(*) AS total FROM tarifario GROUP BY entidad_id";
    $stmtCnt = sqlsrv_query($con, $sqlCnt);
    if ($stmtCnt !== false) {
        while ($rc = sqlsrv_fetch_array($stmtCnt, SQLSRV_FETCH_ASSOC)) {
            $eId = (int)$rc['ent_id'];
            if ($eId === 0 || $eId === $hoId) {
                $tarifasCountPorEntidad['PROPIO'] = ($tarifasCountPorEntidad['PROPIO'] ?? 0) + (int)$rc['total'];
                $tarifasCountPorEntidad[(string)$hoId] = $tarifasCountPorEntidad['PROPIO'];
            } else {
                $tarifasCountPorEntidad[(string)$eId] = (int)$rc['total'];
            }
        }
    }
}

// Petición AJAX para OBTENER exámenes del tarifario base de Hernán Ocazionez para la modal de importación
if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'obtener_examenes_base') {
    header('Content-Type: application/json; charset=utf-8');

    $targetEntidadId = (int)($_REQUEST['entidad_id'] ?? 0);
    
    // Obtener códigos ya existentes en la entidad de destino
    $existentesEnDestino = [];
    if ($targetEntidadId > 0 && isset($con) && $con !== false) {
        $sqlEx = "SELECT codigo, tipo_paciente FROM tarifario WHERE entidad_id = ?";
        $stmtEx = sqlsrv_query($con, $sqlEx, array($targetEntidadId));
        if ($stmtEx !== false) {
            while ($rx = sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC)) {
                $existentesEnDestino[trim($rx['codigo']) . '_' . trim($rx['tipo_paciente'])] = true;
            }
        }
    }

    // Consultar todos los exámenes base de Hernán Ocazionez
    $sqlBase = "SELECT id, codigo, examen, concepto, servicio, tipo_paciente, valor_und, valor_texto, cuenta_contable, nombre_cuenta, columna1
                FROM tarifario 
                WHERE (entidad_id = ? OR entidad_id IS NULL OR entidad_id = 0) AND ISNULL(estado, 1) = 1
                ORDER BY codigo ASC, examen ASC";
    $stmtBase = sqlsrv_query($con, $sqlBase, array($hoId));
    
    if ($stmtBase === false) {
        echo json_encode(['success' => false, 'message' => 'Error al consultar tarifario base: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    $examenes = [];
    while ($row = sqlsrv_fetch_array($stmtBase, SQLSRV_FETCH_ASSOC)) {
        $key = trim($row['codigo']) . '_' . trim($row['tipo_paciente']);
        $row['ya_importado'] = isset($existentesEnDestino[$key]) ? 1 : 0;
        $row['id'] = (int)$row['id'];
        $row['valor_und'] = (float)($row['valor_und'] ?? 0);
        $examenes[] = $row;
    }

    echo json_encode([
        'success'  => true,
        'total'    => count($examenes),
        'examenes' => $examenes
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Petición AJAX para IMPORTAR exámenes seleccionados hacia una empresa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'importar_examenes_seleccionados') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos de edición para importar tarifas.']);
        exit;
    }

    $targetEntidadId = (int)($_POST['entidad_id'] ?? 0);
    $selectedIdsRaw  = $_POST['ids'] ?? [];
    if (is_string($selectedIdsRaw)) {
        $selectedIdsRaw = json_decode($selectedIdsRaw, true) ?? explode(',', $selectedIdsRaw);
    }
    $selectedIds = array_values(array_filter(array_map('intval', (array)$selectedIdsRaw), function($v) { return $v > 0; }));

    if ($targetEntidadId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Entidad de destino no válida.']);
        exit;
    }

    if (empty($selectedIds)) {
        echo json_encode(['success' => false, 'message' => 'Debe seleccionar al menos un examen para importar.']);
        exit;
    }

    $targetNombre = $entidadesList[(string)$targetEntidadId]['nombre'] ?? "Entidad #{$targetEntidadId}";

    // Códigos existentes en la entidad de destino para evitar duplicar exactamente el mismo código y tipo
    $existentesEnDestino = [];
    $sqlEx = "SELECT codigo, tipo_paciente FROM tarifario WHERE entidad_id = ?";
    $stmtEx = sqlsrv_query($con, $sqlEx, array($targetEntidadId));
    if ($stmtEx !== false) {
        while ($rx = sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC)) {
            $existentesEnDestino[trim($rx['codigo']) . '_' . trim($rx['tipo_paciente'])] = true;
        }
    }

    // Consultar las tarifas seleccionadas desde Hernán Ocazionez
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $sqlSelect = "SELECT id, codigo, columna1, examen, concepto, servicio, tipo, tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, cuenta_contable, nombre_cuenta, estado
                  FROM tarifario
                  WHERE id IN ($placeholders) AND (entidad_id = ? OR entidad_id IS NULL OR entidad_id = 0)";
    $selectParams = array_merge($selectedIds, array($hoId));
    $stmtSelect = sqlsrv_query($con, $sqlSelect, $selectParams);

    if ($stmtSelect === false) {
        echo json_encode(['success' => false, 'message' => 'Error al consultar tarifas seleccionadas: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    $tarifasAInsertar = [];
    $codigosOmitidos = [];
    $codigosImportados = [];

    while ($r = sqlsrv_fetch_array($stmtSelect, SQLSRV_FETCH_ASSOC)) {
        $cKey = trim($r['codigo']) . '_' . trim($r['tipo_paciente']);
        if (isset($existentesEnDestino[$cKey])) {
            $codigosOmitidos[] = $r['codigo'];
            continue;
        }

        $existentesEnDestino[$cKey] = true;
        $codigosImportados[] = $r['codigo'];
        $tarifasAInsertar[] = [
            'codigo'            => $r['codigo'],
            'columna1'          => $r['columna1'],
            'examen'            => $r['examen'],
            'concepto'          => $r['concepto'],
            'servicio'          => $r['servicio'],
            'tipo'              => $r['tipo'],
            'tipo_paciente'     => $r['tipo_paciente'],
            'valor_texto'       => $r['valor_texto'],
            'modalidad_ubi'     => $r['modalidad_ubi'] ?? 'US',
            'cant_a_pagar'      => $r['cant_a_pagar'] ?? 1,
            'valor_und'         => $r['valor_und'],
            'cuenta_contable'   => $r['cuenta_contable'],
            'nombre_cuenta'     => $r['nombre_cuenta'],
            'estado'            => 1, // Siempre se importan como activas
            'entidad_id'        => $targetEntidadId
        ];
    }

    $insertCount = 0;
    if (!empty($tarifasAInsertar)) {
        $sqlIns = "INSERT INTO tarifario (codigo, columna1, examen, concepto, servicio, tipo, tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, cuenta_contable, nombre_cuenta, estado, fecha_creacion, fecha_ultima_modificacion, usuario_ultimo_cambio_id, entidad_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), GETDATE(), ?, ?)";
        
        foreach ($tarifasAInsertar as $tarifa) {
            $paramsIns = [
                $tarifa['codigo'],
                $tarifa['columna1'],
                $tarifa['examen'],
                $tarifa['concepto'],
                $tarifa['servicio'],
                $tarifa['tipo'],
                $tarifa['tipo_paciente'],
                $tarifa['valor_texto'],
                $tarifa['modalidad_ubi'],
                $tarifa['cant_a_pagar'],
                $tarifa['valor_und'],
                $tarifa['cuenta_contable'],
                $tarifa['nombre_cuenta'],
                $tarifa['estado'],
                $userId,
                $tarifa['entidad_id']
            ];
            $stmtIns = sqlsrv_query($con, $sqlIns, $paramsIns);
            if ($stmtIns !== false) {
                $insertCount++;
            }
        }
    }

    // Auditoría de importación
    if ($insertCount > 0 && function_exists('sqlsrv_query')) {
        @sqlsrv_query(
            $con,
            "INSERT INTO logs_tarifario (usuario_id, accion, detalles, fecha) VALUES (?, 'IMPORTAR_EXAMENES_LOTE', ?, GETDATE())",
            array($userId, "Se importaron {$insertCount} exámenes desde Hernán Ocazionez hacia entidad ID {$targetEntidadId} ({$targetNombre})")
        );
    }

    echo json_encode([
        'success'       => true,
        'message'       => "Se han importado exitosamente {$insertCount} exámenes al catálogo de {$targetNombre}." . (count($codigosOmitidos) > 0 ? " (" . count($codigosOmitidos) . " exámenes ya existían y fueron omitidos)." : ""),
        'import_count'  => $insertCount,
        'omitted_count' => count($codigosOmitidos)
    ]);
    exit;
}

// Petición AJAX para CLONAR tarifario base completo de Hernán Ocazionez (Legacy / Rápido)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clonar_tarifario_base') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para clonar tarifarios.']);
        exit;
    }

    $targetEntidadId = (int)($_POST['entidad_id'] ?? 0);
    if ($targetEntidadId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Entidad de destino no válida.']);
        exit;
    }

    $targetNombre = $entidadesList[(string)$targetEntidadId]['nombre'] ?? "Entidad #{$targetEntidadId}";

    // Copiar todas las tarifas activas de Hernán Ocazionez (entidad_id = $hoId OR NULL OR 0)
    $sqlClone = "INSERT INTO tarifario (codigo, columna1, examen, concepto, servicio, tipo, tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, cuenta_contable, nombre_cuenta, estado, fecha_creacion, fecha_ultima_modificacion, usuario_ultimo_cambio_id, entidad_id)
                 SELECT codigo, columna1, examen, concepto, servicio, tipo, tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, cuenta_contable, nombre_cuenta, estado, GETDATE(), GETDATE(), ?, ?
                 FROM tarifario
                 WHERE (entidad_id = ? OR entidad_id IS NULL OR entidad_id = 0) AND ISNULL(estado, 1) = 1";
    $stmtClone = sqlsrv_query($con, $sqlClone, array($userId, $targetEntidadId, $hoId));

    if ($stmtClone === false) {
        echo json_encode(['success' => false, 'message' => 'Error al clonar tarifario base: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    $rowsCopied = sqlsrv_rows_affected($stmtClone);

    require_once(__DIR__ . '/includes/logger_helper.php');
    require_once(__DIR__ . '/includes/audit_logger.php');

    $detallesClone = "Clonación total del tarifario base de Hernán Ocazionez hacia {$targetNombre} (ID: {$targetEntidadId}). Total tarifas clonadas: {$rowsCopied}. Usuario: {$userName} ({$userEmail}).";

    if (function_exists('registrar_log_sistema')) {
        registrar_log_sistema(
            'TARIFARIO',
            'CLONAR_TARIFARIO_TOTAL',
            'CLONACION',
            $detallesClone,
            [
                'usuario_id'       => $userId,
                'usuario_nombre'   => $userName,
                'usuario_email'    => $userEmail,
                'usuario_rol'      => $userRole,
                'entidad_afectada' => "{$targetNombre} (ID: {$targetEntidadId})",
                'valor_anterior'   => "0 tarifas",
                'valor_nuevo'      => "{$rowsCopied} tarifas clonadas",
                'nivel'            => 'INFO'
            ]
        );
    }

    if (function_exists('registrarAuditoriaTarifario')) {
        registrarAuditoriaTarifario(
            $con, 0, 'CLONACION_BASE', "Clonadas {$rowsCopied} tarifas de Hernán Ocazionez hacia {$targetNombre}",
            $userId, $userName, $userEmail, $userRole,
            'CLONAR_TARIFARIO', 'N/A', "Total {$rowsCopied}", "Clonación completa del catálogo base general", 'CREACION'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => "Se han clonado exitosamente {$rowsCopied} tarifas de Hernán Ocazionez hacia el catálogo de {$targetNombre}.",
        'cloned_count' => $rowsCopied
    ]);
    exit;
}

// Manejo de petición AJAX para CREAR nueva tarifa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_tarifa') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos de Administrador o Financiera para registrar nuevas tarifas.']);
        exit;
    }

    $codigo         = isset($_POST['codigo']) ? strtoupper(trim($_POST['codigo'])) : '';
    $examen         = isset($_POST['examen']) ? trim($_POST['examen']) : '';
    $concepto       = isset($_POST['concepto']) ? strtoupper(trim($_POST['concepto'])) : '';
    $servicio       = isset($_POST['servicio']) ? strtoupper(trim($_POST['servicio'])) : '';
    $tipoPaciente   = isset($_POST['tipo_paciente']) && strtoupper(trim($_POST['tipo_paciente'])) === 'P' ? 'P' : 'E';
    $tipo           = $tipoPaciente;
    $valorUnd       = isset($_POST['valor_und']) ? (int)str_replace(['.', '$', ' '], '', $_POST['valor_und']) : 0;
    $rawColumna1    = isset($_POST['columna1']) ? (int)str_replace(['.', '$', ' '], '', $_POST['columna1']) : 0;
    $columna1       = $rawColumna1 > 0 ? $rawColumna1 : $valorUnd;
    $cuentaContable = isset($_POST['cuenta_contable']) ? trim($_POST['cuenta_contable']) : '';
    $nombreCuenta   = isset($_POST['nombre_cuenta']) ? strtoupper(trim($_POST['nombre_cuenta'])) : '';
    $motivoCambio      = isset($_POST['motivo_cambio']) ? trim($_POST['motivo_cambio']) : '';
    $valorTexto        = isset($_POST['valor_texto']) && !empty(trim($_POST['valor_texto'])) ? trim($_POST['valor_texto']) : ('$ ' . number_format($valorUnd, 0, ',', '.'));
    $pagarPorCantidad  = isset($_POST['pagar_por_cantidad']) ? (int)$_POST['pagar_por_cantidad'] : 1;
    $baseCalculo       = isset($_POST['base_calculo']) && strtoupper(trim($_POST['base_calculo'])) === 'VALOR_EXAMEN' ? 'VALOR_EXAMEN' : 'VALOR_LIQUIDACION';
    
    $entidadIdRaw      = trim($_POST['entidad_id'] ?? '');
    $entidadIdVal      = ($entidadIdRaw !== '' && $entidadIdRaw !== 'PROPIO' && (int)$entidadIdRaw > 0) ? (int)$entidadIdRaw : $hoId;

    if (empty($codigo) || empty($examen)) {
        echo json_encode(['success' => false, 'message' => 'El código y nombre del examen son campos obligatorios.']);
        exit;
    }

    if ($valorUnd <= 0) {
        echo json_encode(['success' => false, 'message' => 'Ingrese un valor unitario válido para la tarifa.']);
        exit;
    }

    if (empty($motivoCambio)) {
        echo json_encode(['success' => false, 'message' => 'El motivo de creación es obligatorio para el registro de auditoría.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos SQL Server.']);
        exit;
    }

    // Insertar en SQL Server con entidad_id, pagar_por_cantidad y base_calculo
    $sqlIns = "INSERT INTO tarifario (codigo, columna1, examen, concepto, servicio, tipo, tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, cuenta_contable, nombre_cuenta, estado, pagar_por_cantidad, base_calculo, fecha_creacion, fecha_ultima_modificacion, usuario_ultimo_cambio_id, entidad_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'US', ?, ?, ?, ?, 1, ?, ?, GETDATE(), GETDATE(), ?, ?); SELECT SCOPE_IDENTITY() AS new_id;";
    
    $stmtIns = sqlsrv_query($con, $sqlIns, array($codigo, $columna1, $examen, $concepto, $servicio, $tipo, $tipoPaciente, $valorTexto, $pagarPorCantidad, $valorUnd, $cuentaContable, $nombreCuenta, $pagarPorCantidad, $baseCalculo, $userId, $entidadIdVal));

    if ($stmtIns === false) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar la nueva tarifa en SQL Server: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    // Obtener ID insertado
    sqlsrv_next_result($stmtIns);
    $rowId = sqlsrv_fetch_array($stmtIns, SQLSRV_FETCH_ASSOC);
    $newId = $rowId['new_id'] ?? 0;

    // Registrar en Auditoría
    require_once(__DIR__ . '/includes/audit_logger.php');
    registrarAuditoriaTarifario(
        $con, $newId, $codigo, $examen,
        $userId, $userName, $userEmail, $userRole,
        'NUEVA_TARIFA', 'N/A', '$' . number_format($valorUnd, 0, ',', '.') . " (Paga Cant: {$pagarPorCantidad}, Base: {$baseCalculo})", $motivoCambio, 'CREACION'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Nueva tarifa registrada correctamente y archivada en auditoría.',
        'data' => [
            'id' => $newId,
            'codigo' => $codigo,
            'examen' => $examen,
            'concepto' => $concepto,
            'servicio' => $servicio,
            'tipo' => $tipo,
            'tipo_paciente' => $tipoPaciente,
            'valor_und' => $valorUnd,
            'columna1' => $columna1,
            'valor_texto' => $valorTexto,
            'cuenta_contable' => $cuentaContable,
            'nombre_cuenta' => $nombreCuenta,
            'estado' => 1,
            'pagar_por_cantidad' => $pagarPorCantidad,
            'base_calculo' => $baseCalculo,
            'entidad_id' => $entidadIdVal,
            'valor_und_formatted' => '$' . number_format($valorUnd, 0, ',', '.')
        ]
    ]);
    exit;
}

// Manejo de petición AJAX para AUTORIZAR CAMBIO DE TARIFAS / NUEVA VIGENCIA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autorizar_cambio_tarifario') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos de Administrador o Financiera para autorizar cambios de tarifario.']);
        exit;
    }

    $entidadIdPost = trim($_POST['entidad_id'] ?? '');
    if (!empty($entidadIdPost)) {
        $targetEntidad = ($entidadIdPost === 'PROPIO' || (int)$entidadIdPost === 0) ? $hoId : (int)$entidadIdPost;
    } else {
        $targetEntidad = ($entidadSeleccionada === 'PROPIO' || $entidadActivaIdInt === 0) ? $hoId : $entidadActivaIdInt;
    }
    $fechaVigencia = trim($_POST['fecha_vigencia'] ?? '');
    $nombreVigencia = trim($_POST['nombre_vigencia'] ?? '');
    $pctReajuste = floatval($_POST['porcentaje_reajuste'] ?? 0);
    $motivoCambio = trim($_POST['motivo_cambio'] ?? '');

    if (empty($fechaVigencia)) {
        echo json_encode(['success' => false, 'message' => 'La fecha de vigencia a partir de la cual aplica el nuevo tarifario es obligatoria.']);
        exit;
    }

    $params = [
        'fecha_vigencia'      => $fechaVigencia,
        'nombre_vigencia'     => $nombreVigencia,
        'tipo_tarifario'      => 'CUPS_GENERAL',
        'entidad_id'          => $targetEntidad,
        'porcentaje_reajuste' => $pctReajuste,
        'usuario_id'          => $userId,
        'usuario_nombre'      => $userName,
        'observaciones'       => $motivoCambio
    ];

    $res = autorizarCambioTarifarioVigencia($params);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// Manejo de petición AJAX para AUTORIZAR NUEVO TARIFARIO DESDE ARCHIVO (EXCEL / CSV)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autorizar_tarifario_archivo') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos de Administrador o Financiera para autorizar cambios de tarifario.']);
        exit;
    }

    $entidadIdPost = trim($_POST['entidad_id'] ?? '');
    if (!empty($entidadIdPost)) {
        $targetEntidad = ($entidadIdPost === 'PROPIO' || (int)$entidadIdPost === 0) ? $hoId : (int)$entidadIdPost;
    } else {
        $targetEntidad = ($entidadSeleccionada === 'PROPIO' || $entidadActivaIdInt === 0) ? $hoId : $entidadActivaIdInt;
    }
    $fechaVigencia = trim($_POST['fecha_vigencia'] ?? '');
    $nombreVigencia = trim($_POST['nombre_vigencia'] ?? '');
    $motivoCambio = trim($_POST['motivo_cambio'] ?? '');

    $tarifasJson = $_POST['tarifas'] ?? '[]';
    $tarifasNuevas = is_string($tarifasJson) ? json_decode($tarifasJson, true) : (array)$tarifasJson;

    if (empty($fechaVigencia)) {
        echo json_encode(['success' => false, 'message' => 'La fecha de vigencia a partir de la cual entra a regir el nuevo tarifario es obligatoria.']);
        exit;
    }

    if (empty($tarifasNuevas) || !is_array($tarifasNuevas) || count($tarifasNuevas) === 0) {
        echo json_encode(['success' => false, 'message' => 'El archivo no contiene exámenes válidos o no se pudieron extraer las filas.']);
        exit;
    }

    $params = [
        'fecha_vigencia'  => $fechaVigencia,
        'nombre_vigencia' => $nombreVigencia,
        'tipo_tarifario'  => 'CUPS_GENERAL',
        'entidad_id'      => $targetEntidad,
        'tarifas'         => $tarifasNuevas,
        'usuario_id'      => $userId,
        'usuario_nombre'  => $userName,
        'observaciones'   => $motivoCambio
    ];

    $res = autorizarCambioTarifarioDesdeListado($params);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// Manejo de petición AJAX para guardar/editar tarifa (incluye cambio de estado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_tarifa') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos de Administrador o Financiera para modificar tarifas.']);
        exit;
    }

    $tarifaId            = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $newExamen           = isset($_POST['examen']) ? trim($_POST['examen']) : null;
    $newValorUnd         = isset($_POST['valor_und']) ? (int)str_replace(['.', '$', ' '], '', $_POST['valor_und']) : 0;
    $newColumna1         = isset($_POST['columna1']) ? (int)str_replace(['.', '$', ' '], '', $_POST['columna1']) : 0;
    $newValorTexto       = isset($_POST['valor_texto']) ? trim($_POST['valor_texto']) : '';
    $newTipoPaciente     = isset($_POST['tipo_paciente']) && strtoupper(trim($_POST['tipo_paciente'])) === 'P' ? 'P' : 'E';
    $newEstado           = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;
    $newPagarPorCantidad = isset($_POST['pagar_por_cantidad']) ? (int)$_POST['pagar_por_cantidad'] : 1;
    $newBaseCalculo      = isset($_POST['base_calculo']) && strtoupper(trim($_POST['base_calculo'])) === 'VALOR_EXAMEN' ? 'VALOR_EXAMEN' : 'VALOR_LIQUIDACION';
    $motivoCambio        = isset($_POST['motivo_cambio']) ? trim($_POST['motivo_cambio']) : '';

    if ($tarifaId <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID de tarifa no válido.']);
        exit;
    }

    if ($newExamen !== null && $newExamen === '') {
        echo json_encode(['success' => false, 'message' => 'El nombre del examen no puede estar vacío.']);
        exit;
    }

    if (empty($motivoCambio)) {
        echo json_encode(['success' => false, 'message' => 'El motivo de la modificación es obligatorio para el registro de auditoría.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos SQL Server.']);
        exit;
    }

    // Consultar registro previo para auditoría
    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, examen, valor_und, columna1, valor_texto, ISNULL(tipo_paciente, 'E') AS tipo_paciente, ISNULL(estado, 1) AS estado, ISNULL(pagar_por_cantidad, 1) AS pagar_por_cantidad, ISNULL(base_calculo, 'VALOR_LIQUIDACION') AS base_calculo FROM tarifario WHERE id = ?", array($tarifaId));
    if ($stmtCurr === false || !($curr = sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC))) {
        echo json_encode(['success' => false, 'message' => 'No se encontró la tarifa especificada.']);
        exit;
    }

    $oldValorUnd         = (int)$curr['valor_und'];
    $oldColumna1         = (int)$curr['columna1'];
    $oldValorTexto       = (string)$curr['valor_texto'];
    $oldTipoPaciente     = (string)($curr['tipo_paciente'] ?? 'E');
    $oldEstado           = (int)$curr['estado'];
    $oldPagarPorCantidad = (int)($curr['pagar_por_cantidad'] ?? 1);
    $oldBaseCalculo      = (string)($curr['base_calculo'] ?? 'VALOR_LIQUIDACION');
    $codigoExamen        = (string)$curr['codigo'];
    $examenNombre        = (string)$curr['examen'];

    // Si no se proporcionó nuevo examen (ej. reactivación rápida), conservar el actual
    if ($newExamen === null) {
        $newExamen = $examenNombre;
    }

    if (empty($newValorTexto)) {
        $newValorTexto = '$ ' . number_format($newValorUnd, 0, ',', '.');
    }

    // Actualizar en SQL Server con pagar_por_cantidad y base_calculo
    $sqlUpdate = "UPDATE tarifario SET examen = ?, valor_und = ?, columna1 = ?, valor_texto = ?, tipo_paciente = ?, estado = ?, pagar_por_cantidad = ?, base_calculo = ?, cant_a_pagar = ?, fecha_ultima_modificacion = GETDATE(), usuario_ultimo_cambio_id = ? WHERE id = ?";
    $stmtUp = sqlsrv_query($con, $sqlUpdate, array($newExamen, $newValorUnd, $newColumna1, $newValorTexto, $newTipoPaciente, $newEstado, $newPagarPorCantidad, $newBaseCalculo, $newPagarPorCantidad, $userId, $tarifaId));

    if ($stmtUp === false) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar tarifa en SQL Server: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    // Registrar en tabla de auditoría
    require_once(__DIR__ . '/includes/audit_logger.php');

    $auditedCount = 0;

    // Auditoría de Cambio de Nombre del Examen
    if ($examenNombre !== $newExamen) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'EXAMEN', $examenNombre, $newExamen, $motivoCambio, 'EDICION'
        );
        $auditedCount++;
    }

    // Auditoría de Cambio de Estado (Activa <-> Deshabilitada)
    if ($oldEstado !== $newEstado) {
        $tipoAccion = ($newEstado === 0) ? 'DESACTIVACION' : 'ACTIVACION';
        $oldEstadoStr = ($oldEstado === 1) ? 'Activa' : 'Deshabilitada';
        $newEstadoStr = ($newEstado === 1) ? 'Activa' : 'Deshabilitada';

        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'ESTADO', $oldEstadoStr, $newEstadoStr, $motivoCambio, $tipoAccion
        );
        $auditedCount++;
    }

    if ($oldValorUnd !== $newValorUnd) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'VALOR_UND', $oldValorUnd, $newValorUnd, $motivoCambio, 'EDICION'
        );
        $auditedCount++;
    }

    if ($oldTipoPaciente !== $newTipoPaciente) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'TIPO_PACIENTE', $oldTipoPaciente, $newTipoPaciente, $motivoCambio, 'EDICION'
        );
        $auditedCount++;
    }

    if ($oldColumna1 !== $newColumna1) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'VALOR_BASE', $oldColumna1, $newColumna1, $motivoCambio, 'EDICION'
        );
        $auditedCount++;
    }

    if ($oldValorTexto !== $newValorTexto) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'VALOR_TEXTO', $oldValorTexto, $newValorTexto, $motivoCambio, 'EDICION'
        );
        $auditedCount++;
    }

    if ($oldPagarPorCantidad !== $newPagarPorCantidad) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'PAGAR_POR_CANTIDAD', ($oldPagarPorCantidad ? 'Sí (Multiplicar por cantidad)' : 'No (Tarifa fija)'), ($newPagarPorCantidad ? 'Sí (Multiplicar por cantidad)' : 'No (Tarifa fija)'), $motivoCambio, 'EDICION'
        );
        $auditedCount++;
    }

    if ($oldBaseCalculo !== $newBaseCalculo) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'BASE_CALCULO', ($oldBaseCalculo === 'VALOR_EXAMEN' ? 'Valor de Examen (Servinte)' : 'Valor de Liquidación (Tarifario)'), ($newBaseCalculo === 'VALOR_EXAMEN' ? 'Valor de Examen (Servinte)' : 'Valor de Liquidación (Tarifario)'), $motivoCambio, 'EDICION'
        );
        $auditedCount++;
    }

    if ($auditedCount === 0) {
        registrarAuditoriaTarifario(
            $con, $tarifaId, $codigoExamen, $newExamen,
            $userId, $userName, $userEmail, $userRole,
            'REVISION', $oldValorUnd, $newValorUnd, $motivoCambio, 'EDICION'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => ($oldEstado !== $newEstado) 
            ? 'Estado de la tarifa modificado correctamente (' . ($newEstado === 1 ? 'Activa' : 'Deshabilitada') . ') y auditado.'
            : 'Tarifa actualizada correctamente y registrada en el historial de auditoría.',
        'data' => [
            'id' => $tarifaId,
            'codigo' => $codigoExamen,
            'examen' => $newExamen,
            'valor_und' => $newValorUnd,
            'columna1' => $newColumna1,
            'valor_texto' => $newValorTexto,
            'estado' => $newEstado,
            'tipo_paciente' => $newTipoPaciente,
            'pagar_por_cantidad' => $newPagarPorCantidad,
            'base_calculo' => $newBaseCalculo,
            'valor_und_formatted' => '$' . number_format($newValorUnd, 0, ',', '.')
        ]
    ]);
    exit;
}

// Descarga de Plantilla de Ejemplo para Cargar Tarifario (Excel/CSV con la estructura oficial)
if (isset($_GET['action']) && $_GET['action'] === 'descargar_plantilla_tarifario') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Plantilla_Tarifario_LIHO_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 para Excel

    // Cabeceras exactas según la estructura solicitada
    fputcsv($output, [
        'CODIGO',
        'COLUMNA1',
        'EXAMEN',
        'CONCEPTO',
        'SERVICIO',
        'TIPO',
        'VALOR',
        'MODALIDAD_UBI',
        'CANT_A_PAGAR',
        'VALOR_UND',
        'CUENTA_CONTABLE',
        'NOMBRE_CUENTA'
    ], ';');

    // Filas de ejemplo con datos reales de la institución
    fputcsv($output, [
        'A73204',
        '10194',
        '8732040 RADIOGRAFIA DE HOMBRO COMPARATIVO',
        'RXSI',
        'RX SIMPLE',
        'RX SIMPLE',
        '$ 10.194',
        'CR, DX',
        '1',
        '10194',
        '61251001',
        'HONORARIOS MED RX SIMPLES'
    ], ';');

    fputcsv($output, [
        '870602',
        '13900',
        'RADIOGRAFIA DE CAVUM FARINGEO',
        'RXES',
        'RX ESPECIALES',
        'RX ESPECIALES',
        '$ 13.900',
        'CR, DX',
        '1',
        '13900',
        '61251001',
        'HONORARIOS MED RX SIMPLES'
    ], ';');

    fclose($output);
    exit;
}

// Manejo de exportación a CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $entExport = $_GET['entidad_id'] ?? $entidadSeleccionada;
    $nomEmpresaClean = "Hernan_Ocazionez";

    if ($entExport === 'PROPIO' || (int)$entExport === $hoId || empty($entExport)) {
        $whereExport = "WHERE (entidad_id = {$hoId} OR entidad_id IS NULL OR entidad_id = 0)";
    } else {
        $whereExport = "WHERE entidad_id = " . (int)$entExport;
        $nomEmpresaClean = "Entidad_" . (int)$entExport;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=tarifario_' . $nomEmpresaClean . '_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'CODIGO',
        'VALOR BASE',
        'EXAMEN',
        'CONCEPTO',
        'SERVICIO',
        'TIPO',
        'TIPO PACIENTE',
        'VALOR FORMATO',
        'MODALIDAD UBI',
        'CANT A PAGAR',
        'VALOR UND',
        'ESTADO',
        'CUENTA CONTABLE',
        'NOMBRE CUENTA'
    ], ';');

    if (isset($con) && $con !== false) {
        $stmtEx = sqlsrv_query($con, "SELECT codigo, columna1, examen, concepto, servicio, tipo, ISNULL(tipo_paciente, 'E') AS tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, ISNULL(estado, 1) AS estado, cuenta_contable, nombre_cuenta FROM tarifario {$whereExport} ORDER BY id ASC");
        if ($stmtEx !== false) {
            while ($row = sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['codigo'],
                    $row['columna1'],
                    $row['examen'],
                    $row['concepto'],
                    $row['servicio'],
                    $row['tipo'],
                    $row['tipo_paciente'],
                    $row['valor_texto'],
                    $row['modalidad_ubi'],
                    $row['cant_a_pagar'],
                    $row['valor_und'],
                    ((int)$row['estado'] === 1) ? 'ACTIVA' : 'DESHABILITADA',
                    $row['cuenta_contable'],
                    $row['nombre_cuenta']
                ], ';');
            }
        }
    }
    fclose($output);
    exit;
}

// Consultar Vigencias y vigencia activa
$targetEntidadConsulta = ($entidadSeleccionada === 'PROPIO' || $entidadActivaIdInt === 0) ? $hoId : $entidadActivaIdInt;
$vigenciaActiva = obtenerVigenciaActivaActual('CUPS_GENERAL', $targetEntidadConsulta);
$listadoVigencias = obtenerListadoVigencias('CUPS_GENERAL', $targetEntidadConsulta);

$filtroVigencia = isset($_GET['vigencia']) ? trim($_GET['vigencia']) : 'ACTIVA'; // 'ACTIVA', 'TODAS', o ID numérico

$vigenciaWhere = "";
if ($filtroVigencia === 'ACTIVA') {
    $vigenciaWhere = " AND (vigencia_hasta IS NULL) ";
} elseif (is_numeric($filtroVigencia) && (int)$filtroVigencia > 0) {
    $stmtVigRow = sqlsrv_query($con, "SELECT fecha_inicio, fecha_fin FROM tarifarios_vigencias WHERE id = ?", [(int)$filtroVigencia]);
    if ($stmtVigRow && $rVig = sqlsrv_fetch_array($stmtVigRow, SQLSRV_FETCH_ASSOC)) {
        $fIni = $rVig['fecha_inicio'] instanceof DateTime ? $rVig['fecha_inicio']->format('Y-m-d') : (string)$rVig['fecha_inicio'];
        $fFin = !empty($rVig['fecha_fin']) ? ($rVig['fecha_fin'] instanceof DateTime ? $rVig['fecha_fin']->format('Y-m-d') : (string)$rVig['fecha_fin']) : null;
        if ($fFin) {
            $vigenciaWhere = " AND (vigencia_desde >= '{$fIni}' AND vigencia_hasta <= '{$fFin}') ";
        } else {
            $vigenciaWhere = " AND (vigencia_desde >= '{$fIni}' AND vigencia_hasta IS NULL) ";
        }
    }
}

// Consultar lista de tarifas desde SQL Server para la entidad seleccionada
$tarifas = [];
$serviciosFiltro = [];
$conceptosFiltro = [];
$tarifasActivas = 0;
$tarifasDeshabilitadas = 0;
$tarifasEmpresa = 0;
$tarifasParticular = 0;

if (isset($con) && $con !== false) {
    if ($entidadSeleccionada === 'PROPIO' || $entidadActivaIdInt === $hoId || $entidadActivaIdInt === 0) {
        $sql = "SELECT id, codigo, columna1, examen, concepto, servicio, tipo, ISNULL(tipo_paciente, 'E') AS tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, ISNULL(estado, 1) AS estado, cuenta_contable, nombre_cuenta, ISNULL(entidad_id, 0) AS entidad_id, ISNULL(pagar_por_cantidad, 1) AS pagar_por_cantidad, ISNULL(base_calculo, 'VALOR_LIQUIDACION') AS base_calculo,
                       CONVERT(VARCHAR(10), ISNULL(vigencia_desde, '2020-01-01'), 120) AS vigencia_desde, 
                       CONVERT(VARCHAR(10), vigencia_hasta, 120) AS vigencia_hasta, version_id 
                FROM tarifario 
                WHERE (entidad_id = ? OR entidad_id IS NULL OR entidad_id = 0) {$vigenciaWhere}
                ORDER BY id ASC";
        $stmt = sqlsrv_query($con, $sql, array($hoId));
    } else {
        $sql = "SELECT id, codigo, columna1, examen, concepto, servicio, tipo, ISNULL(tipo_paciente, 'E') AS tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, ISNULL(estado, 1) AS estado, cuenta_contable, nombre_cuenta, ISNULL(entidad_id, 0) AS entidad_id, ISNULL(pagar_por_cantidad, 1) AS pagar_por_cantidad, ISNULL(base_calculo, 'VALOR_LIQUIDACION') AS base_calculo,
                       CONVERT(VARCHAR(10), ISNULL(vigencia_desde, '2020-01-01'), 120) AS vigencia_desde, 
                       CONVERT(VARCHAR(10), vigencia_hasta, 120) AS vigencia_hasta, version_id 
                FROM tarifario 
                WHERE entidad_id = ? {$vigenciaWhere}
                ORDER BY id ASC";
        $stmt = sqlsrv_query($con, $sql, array($entidadActivaIdInt));
    }

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['estado'] = (int)$row['estado'];
            $row['tipo_paciente'] = strtoupper(trim($row['tipo_paciente'] ?? 'E'));
            if ($row['tipo_paciente'] !== 'P') $row['tipo_paciente'] = 'E';
            $row['pagar_por_cantidad'] = (int)($row['pagar_por_cantidad'] ?? 1);
            $row['base_calculo'] = strtoupper(trim((string)($row['base_calculo'] ?? 'VALOR_LIQUIDACION')));

            $tarifas[] = $row;

            if ($row['estado'] === 1) {
                $tarifasActivas++;
            } else {
                $tarifasDeshabilitadas++;
            }

            if ($row['tipo_paciente'] === 'P') {
                $tarifasParticular++;
            } else {
                $tarifasEmpresa++;
            }

            if (!empty($row['servicio']) && !in_array($row['servicio'], $serviciosFiltro)) {
                $serviciosFiltro[] = $row['servicio'];
            }
            if (!empty($row['concepto']) && !in_array($row['concepto'], $conceptosFiltro)) {
                $conceptosFiltro[] = $row['concepto'];
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
    <title>Catálogo Tarifario | LIHO</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- Google Fonts: Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet" />

    <link rel="shortcut icon" href="assets/img/hologo.png">

    <!-- SheetJS para lectura y procesamiento instantáneo en navegador de Excel (.xlsx, .xls) y CSV -->
    <script src="assets/js/xlsx.full.min.js"></script>

    <!-- SweetAlert2 para modales interactivos y alertas -->
    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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

        /* Estilos corporativos para SweetAlert2 adaptables a Modo Oscuro y Claro */
        .swal2-container {
            backdrop-filter: blur(8px) !important;
            -webkit-backdrop-filter: blur(8px) !important;
        }
        html.dark .swal2-container, body.dark .swal2-container, .dark .swal2-container {
            background-color: rgba(10, 15, 30, 0.75) !important;
        }
        html:not(.dark) .swal2-container, html.light .swal2-container {
            background-color: rgba(15, 23, 42, 0.45) !important;
        }

        .swal2-popup {
            border-radius: 1.5rem !important;
            padding: 1.75rem !important;
            font-family: 'Montserrat', sans-serif !important;
        }
        html.dark .swal2-popup, body.dark .swal2-popup, .dark .swal2-popup, .swal2-dark-popup {
            background-color: #0c1427 !important;
            color: #f1f5f9 !important;
            border: 1px solid #1e293b !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7) !important;
        }
        html:not(.dark) .swal2-popup, html.light .swal2-popup, :root:not(.dark) .swal2-popup, .swal2-light-popup {
            background-color: #ffffff !important;
            color: #0f172a !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
        }
        .swal2-title {
            font-size: 1.25rem !important;
            font-weight: 800 !important;
            letter-spacing: -0.02em !important;
            padding: 0.5rem 0 !important;
        }
        html.dark .swal2-title, body.dark .swal2-title, .dark .swal2-title, .swal2-dark-title {
            color: #ffffff !important;
        }
        html:not(.dark) .swal2-title, html.light .swal2-title, .swal2-light-title {
            color: #0f172a !important;
        }
        .swal2-html-container {
            font-size: 0.85rem !important;
            font-weight: 500 !important;
            margin: 0.75rem 0 !important;
            line-height: 1.6 !important;
        }
        html.dark .swal2-html-container, body.dark .swal2-html-container, .dark .swal2-html-container, .swal2-dark-html {
            color: #94a3b8 !important;
        }
        html:not(.dark) .swal2-html-container, html.light .swal2-html-container, .swal2-light-html {
            color: #475569 !important;
        }
        .swal2-actions {
            margin-top: 1.25rem !important;
            gap: 0.75rem !important;
        }
        .swal2-confirm, .swal2-corporate-confirm {
            border-radius: 0.75rem !important;
            font-size: 0.82rem !important;
            font-weight: 800 !important;
            padding: 0.7rem 1.4rem !important;
            background: linear-gradient(135deg, #d97706 0%, #ea580c 100%) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 14px 0 rgba(217, 119, 6, 0.35) !important;
            border: none !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }
        .swal2-confirm:hover, .swal2-corporate-confirm:hover {
            opacity: 0.95 !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 6px 18px 0 rgba(217, 119, 6, 0.45) !important;
        }
        .swal2-cancel, .swal2-corporate-cancel {
            border-radius: 0.75rem !important;
            font-size: 0.82rem !important;
            font-weight: 700 !important;
            padding: 0.7rem 1.3rem !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }
        html.dark .swal2-cancel, body.dark .swal2-cancel, .dark .swal2-cancel, html.dark .swal2-corporate-cancel {
            background-color: #1e293b !important;
            color: #cbd5e1 !important;
            border: 1px solid #334155 !important;
        }
        html.dark .swal2-cancel:hover, body.dark .swal2-cancel:hover, .dark .swal2-cancel:hover {
            background-color: #334155 !important;
            color: #ffffff !important;
        }
        html:not(.dark) .swal2-cancel, html.light .swal2-cancel, html:not(.dark) .swal2-corporate-cancel {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border: 1px solid #cbd5e1 !important;
        }
        html:not(.dark) .swal2-cancel:hover, html.light .swal2-cancel:hover {
            background-color: #e2e8f0 !important;
            color: #0f172a !important;
        }
        .swal2-icon {
            border-width: 3px !important;
            margin: 0.5rem auto 1rem !important;
        }
        html.dark .swal2-icon.swal2-question, .dark .swal2-icon.swal2-question {
            border-color: #f59e0b !important;
            color: #f59e0b !important;
        }
        html.dark .swal2-icon.swal2-success, .dark .swal2-icon.swal2-success {
            border-color: #10b981 !important;
            color: #10b981 !important;
        }
        html.dark .swal2-icon.swal2-error, .dark .swal2-icon.swal2-error {
            border-color: #ef4444 !important;
            color: #ef4444 !important;
        }
        html.dark .swal2-icon.swal2-warning, .dark .swal2-icon.swal2-warning {
            border-color: #f59e0b !important;
            color: #f59e0b !important;
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header / Navbar Component -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Content Area (Aprovechamiento de pantalla ancha) -->
    <main class="flex-grow max-w-[98%] 2xl:max-w-[1850px] w-full mx-auto px-3 sm:px-6 py-6 sm:py-8">

        <!-- Header de Sección -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
            <div>
                <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-slate-200/80 dark:bg-slate-800 text-xs font-bold uppercase tracking-wider mb-2 border border-slate-300/60 dark:border-slate-700/60">
                    <span class="material-symbols-outlined text-sm text-teal-600 dark:text-teal-400">request_quote</span>
                    <span class="text-slate-600 dark:text-slate-300">Catálogo y Maestro de Tarifario General</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight font-outfit">
                    Catálogo de Tarifario
                </h1>
                <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-1">
                    Gestión de tarifas y honorarios médicos configurados por sede y entidad
                </p>
            </div>

            <!-- Toolbar Superior Ordenada: Pestañas de Navegación a la derecha y Acciones abajo -->
            <div class="flex flex-col items-start lg:items-end gap-2.5 w-full lg:w-auto shrink-0">
                <!-- Pestañas de Navegación Segmentada -->
                <div class="inline-flex p-1 bg-slate-200/80 dark:bg-slate-900/90 rounded-2xl border border-slate-300/70 dark:border-slate-800 shadow-xs">
                    <a href="tarifario.php?entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-extrabold <?php echo $paletaModal['tab_active']; ?> transition-all flex items-center gap-1.5 shadow-sm">
                        <span class="material-symbols-outlined text-sm">request_quote</span>
                        <span>Tarifario General</span>
                    </a>
                    <a href="tarifario_especial.php?entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-purple-600 dark:hover:text-purple-400 hover:bg-white/80 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-purple-500">star</span>
                        <span>Tarifas Especiales</span>
                    </a>
                    <a href="maestro_porcentajes.php?entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:bg-white/80 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-emerald-500">percent</span>
                        <span>Modalidades y Porcentajes</span>
                    </a>
                </div>

                <!-- Botones de Herramientas / Auditoría y Exportación -->
                <div class="flex items-center gap-2">
                    <a href="historial_tarifario.php" 
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 text-xs font-bold border border-slate-200 dark:border-slate-800 shadow-xs transition-all hover:scale-105 active:scale-95">
                        <span class="material-symbols-outlined text-base text-slate-500 dark:text-slate-400">history</span>
                        <span>Historial / Auditoría</span>
                    </a>

                    <a href="tarifario.php?action=export_csv&entidad_id=<?php echo urlencode($entidadSeleccionada); ?>" 
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all hover:scale-105 active:scale-95">
                        <span class="material-symbols-outlined text-base">download</span>
                        <span>Exportar a Excel / CSV</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Banner Principal de la Entidad Activa Seleccionada (Ultra Visible y Ambientado idéntico a maestro_porcentajes.php) -->
        <?php 
            $tarifasCount = $tarifasCountPorEntidad[$entidadSeleccionada] ?? 0;
            $tarifasTexto = $tarifasCount . ($tarifasCount === 1 ? ' tarifa registrada' : ' tarifas registradas');
        ?>
        <div class="relative z-10 overflow-hidden rounded-3xl border-2 <?php echo $paletaModal['banner_border']; ?> <?php echo $paletaModal['banner_bg']; ?> <?php echo $paletaModal['banner_shadow']; ?> p-5 sm:p-6 mb-8 transition-all duration-300">
            <!-- Resplandor ambiental interno con el color de la entidad -->
            <div class="absolute -top-14 -right-14 w-80 h-80 bg-gradient-to-br <?php echo $paletaModal['blur_ambient']; ?> rounded-full blur-3xl pointer-events-none opacity-70"></div>
            <div class="absolute -bottom-10 -left-10 w-56 h-56 bg-gradient-to-tr <?php echo $paletaModal['blur_ambient']; ?> rounded-full blur-2xl pointer-events-none opacity-40"></div>

            <div class="relative z-10 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-5">
                <!-- Logo e Información Detallada de la Entidad -->
                <div class="flex items-start sm:items-center gap-4 sm:gap-5">
                    <!-- Caja de Logo Destacada con Borde y Anillo Temático -->
                    <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-white flex items-center justify-center p-2.5 border-2 <?php echo $paletaModal['logo_border']; ?> shadow-lg shrink-0 <?php echo $paletaModal['logo_ring']; ?> overflow-hidden transition-transform duration-300 hover:scale-105">
                        <?php if ($entidadSeleccionada === 'PROPIO'): ?>
                            <img src="assets/img/hologo.png" alt="Logo IPS Matriz" class="max-w-full max-h-full object-contain">
                        <?php elseif (!empty($entidadActiva['logo']) && file_exists(__DIR__ . '/' . $entidadActiva['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($entidadActiva['logo']); ?>" alt="Logo <?php echo htmlspecialchars($entidadActiva['nombre']); ?>" class="max-w-full max-h-full object-contain" onerror="this.onerror=null; this.parentElement.innerHTML='<span class=\'text-base font-black <?php echo $paletaModal['pill_text']; ?>\'><?php echo strtoupper(substr($entidadActiva['nombre'], 0, 3)); ?></span>';">
                        <?php else: ?>
                            <span class="text-base font-black <?php echo $paletaModal['pill_text']; ?>"><?php echo strtoupper(substr($entidadActiva['nombre'], 0, 3)); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Datos y Metadatos de la Entidad -->
                    <div>
                        <!-- Etiqueta de Contexto y Tipo -->
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full <?php echo $paletaModal['dot_pulse']; ?> opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 <?php echo $paletaModal['dot_pulse']; ?>"></span>
                            </span>
                            <span class="text-[10px] font-black uppercase tracking-wider <?php echo $paletaModal['pill_text']; ?>">
                                ENTIDAD AFECTADA EN ESTA VISTA
                            </span>
                            <span class="px-2.5 py-0.5 text-[10px] font-black uppercase rounded-full <?php echo $paletaModal['card_badge']; ?>">
                                <?php echo $entidadActiva['tipo_ips']; ?>
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                <span class="material-symbols-outlined text-[13px] <?php echo $paletaModal['pill_text']; ?>">request_quote</span>
                                <span><?php echo $tarifasTexto; ?></span>
                            </span>
                        </div>

                        <!-- Nombre de la Entidad en Grande y Destacado -->
                        <h2 class="text-xl sm:text-2xl lg:text-3xl font-black text-slate-900 dark:text-white font-outfit tracking-tight leading-tight">
                            <?php echo htmlspecialchars($entidadActiva['nombre']); ?>
                        </h2>

                        <!-- Detalle y Advertencia de Alcance -->
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400 mt-1">
                            <span class="font-bold text-slate-700 dark:text-slate-200">
                                NIT: <?php echo htmlspecialchars($entidadActiva['nit']); ?>
                            </span>
                            <span>&bull;</span>
                            <span><?php echo htmlspecialchars($entidadActiva['subtitulo']); ?></span>
                            <span>&bull;</span>
                            <span class="inline-flex items-center gap-1 <?php echo $paletaModal['accent_text']; ?> font-semibold">
                                <span class="material-symbols-outlined text-[15px]">verified_user</span>
                                <span>Las tarifas y valores configurados aplican exclusivamente a esta entidad.</span>
                            </span>
                        </div>

                        <!-- Barra de Vigencia de Tarifario Activa / Selector Histórico -->
                        <div class="mt-3 pt-3 border-t border-slate-200/80 dark:border-slate-800/80 flex flex-wrap items-center justify-between gap-3 text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30 font-bold">
                                    <span class="material-symbols-outlined text-sm">calendar_month</span>
                                    <span>Vigencia Actual:</span>
                                    <span class="font-extrabold text-slate-800 dark:text-white"><?php echo htmlspecialchars($vigenciaActiva['nombre_vigencia'] ?? 'Vigencia General Activa'); ?></span>
                                </span>
                                <span class="text-slate-500 dark:text-slate-400">
                                    (Vigente desde: <strong class="text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars($vigenciaActiva['fecha_inicio'] ?? '2020-01-01'); ?></strong>)
                                </span>
                            </div>

                            <!-- Selector de Versión / Historial de Vigencias -->
                            <div class="flex items-center gap-2">
                                <label for="filtro_vigencia" class="text-slate-500 dark:text-slate-400 font-medium">Ver tarifas de:</label>
                                <select id="filtro_vigencia" onchange="cambiarFiltroVigencia(this.value)" 
                                        class="text-xs font-semibold rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-200 py-1.5 px-3 focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                                    <option value="ACTIVA" <?php echo ($filtroVigencia === 'ACTIVA') ? 'selected' : ''; ?>>
                                        Vigencia Activa Actual (Vigente hoy)
                                    </option>
                                    <?php foreach ($listadoVigencias as $vItem): ?>
                                        <?php if ($vItem['estado'] !== 'ACTIVA'): ?>
                                            <option value="<?php echo $vItem['id']; ?>" <?php echo ((string)$filtroVigencia === (string)$vItem['id']) ? 'selected' : ''; ?>>
                                                Histórica: <?php echo htmlspecialchars($vItem['nombre_vigencia']); ?> (<?php echo $vItem['fecha_inicio']; ?> al <?php echo $vItem['fecha_fin'] ?? 'N/A'; ?>)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <option value="TODAS" <?php echo ($filtroVigencia === 'TODAS') ? 'selected' : ''; ?>>
                                        Todas las versiones históricas y actuales
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción para Cambiar Entidad y Nueva Tarifa -->
                <div class="flex flex-wrap items-center gap-3 self-stretch sm:self-auto justify-start lg:justify-end shrink-0 pt-3 lg:pt-0 border-t lg:border-t-0 border-slate-200/60 dark:border-slate-800">
                    <!-- Botón Destacado: Cambiar Entidad -->
                    <button type="button" onclick="abrirModalSeleccionarEntidad()"
                            class="px-4 sm:px-5 py-2.5 sm:py-3 rounded-2xl <?php echo $paletaModal['btn_cambiar']; ?> font-extrabold text-xs transition-all duration-200 cursor-pointer inline-flex items-center gap-2.5 group hover:scale-[1.02] active:scale-[0.98] shrink-0"
                            title="Seleccionar otra entidad para configurar su tarifario">
                        <span class="material-symbols-outlined text-xl transition-transform duration-300 group-hover:rotate-180 <?php echo $paletaModal['icon_color']; ?>">swap_horiz</span>
                        <div class="text-left">
                            <span class="block text-[9px] uppercase tracking-wider text-slate-400 dark:text-slate-400 font-bold leading-none">Configurar otra</span>
                            <span class="block text-xs font-black leading-tight mt-0.5">Cambiar Entidad</span>
                        </div>
                    </button>

                    <a href="maestro_entidades.php" 
                       class="px-3.5 py-2.5 sm:py-3 rounded-2xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/60 text-slate-700 dark:text-slate-200 text-xs font-bold transition-all shadow-xs inline-flex items-center gap-2 cursor-pointer hover:scale-[1.02] active:scale-[0.98] shrink-0"
                       title="Gestionar empresas e IPS registradas">
                        <span class="material-symbols-outlined text-base text-slate-500 dark:text-slate-400">domain</span>
                        <span>Maestro Empresas</span>
                    </a>

                    <?php if ($canEditTarifa): ?>
                        <!-- Botón Autorizar Cambio de Tarifas / Nueva Vigencia -->
                        <button type="button" onclick="abrirModalAutorizarVigencia()"
                                class="px-4 sm:px-5 py-2.5 sm:py-3 rounded-2xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white font-extrabold text-xs transition-all duration-200 inline-flex items-center gap-2 cursor-pointer hover:scale-[1.02] active:scale-[0.98] shadow-md shadow-amber-600/25 shrink-0"
                                title="Autorizar y activar un nuevo listado de tarifas con fecha de vigencia general">
                            <span class="material-symbols-outlined text-lg">published_with_changes</span>
                            <span>Autorizar Vigencia / Tarifas</span>
                        </button>

                        <!-- Botón Nueva Tarifa (Con los colores de la entidad activa) -->
                        <button type="button" onclick="openNuevaTarifaModal()"
                                class="px-4 sm:px-5 py-2.5 sm:py-3 rounded-2xl <?php echo $paletaModal['btn_gradient']; ?> font-extrabold text-xs transition-all duration-200 inline-flex items-center gap-2 cursor-pointer hover:scale-[1.02] active:scale-[0.98] shrink-0">
                            <span class="material-symbols-outlined text-lg">add_circle</span>
                            <span>+ Nueva Tarifa</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Banner de catálogo vacío ambientado con el color dinámico de la entidad -->
        <?php if ($entidadActivaIdInt > 0 && count($tarifas) === 0): ?>
        <div id="bannerCatalogoVacio" class="relative mb-6 p-6 rounded-3xl <?php echo $paletaModal['card_bg']; ?> border text-slate-800 dark:text-white shadow-sm dark:shadow-xl flex flex-col sm:flex-row items-center justify-between gap-4 animate__animated animate__fadeIn pr-14" style="border-left: 6px solid <?php echo $paletaModal['hex']; ?>;">
            <!-- Botón Cerrar (X) para cerrar aviso si el usuario no desea copiar -->
            <button type="button" onclick="cerrarBannerCatalogoVacio()" class="absolute top-4 right-4 w-8 h-8 rounded-xl <?php echo $paletaModal['pill']; ?> flex items-center justify-center transition-all cursor-pointer shadow-xs" title="Cerrar aviso">
                <span class="material-symbols-outlined text-base">close</span>
            </button>
            <div class="flex items-center gap-4">
                <div class="p-3.5 rounded-2xl <?php echo $paletaModal['btn']; ?> text-white shadow-md shrink-0">
                    <span class="material-symbols-outlined text-2xl">folder_off</span>
                </div>
                <div>
                    <h3 class="text-sm font-black font-outfit uppercase tracking-wider <?php echo $paletaModal['text_accent']; ?>">
                        Catálogo Propio Vacío para <?php echo htmlspecialchars($entidadActiva['nombre']); ?>
                    </h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 font-medium mt-0.5">
                        Esta empresa aún no tiene tarifas personalizadas registradas en su propio catálogo. Puedes crear tarifas individuales o copiar los exámenes base de <strong>Hernán Ocazionez</strong> para adaptar sus precios en 1 clic.
                    </p>
                </div>
            </div>
            <?php if ($canEditTarifa): ?>
            <button type="button" onclick="abrirModalCopiarTarifario(<?php echo $entidadActivaIdInt; ?>, '<?php echo addslashes($entidadActiva['nombre']); ?>')"
                class="px-5 py-3 rounded-2xl <?php echo $paletaModal['btn']; ?> text-white font-black text-xs shadow-md transition-all hover:scale-105 active:scale-95 cursor-pointer shrink-0 flex items-center gap-2">
                <span class="material-symbols-outlined text-base">content_copy</span>
                <span>Copiar Tarifario Base de HO</span>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tarjetas Resumen KPIs -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <!-- Total Tarifas -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5 transition-all" style="border-left: 4px solid <?php echo $paletaModal['hex']; ?>;">
                <div class="p-3 rounded-2xl <?php echo $paletaModal['icon_bg']; ?> shrink-0">
                    <span class="material-symbols-outlined text-2xl">receipt_long</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider truncate">
                        Tarifas <?php echo htmlspecialchars(explode(' ', $entidadActiva['nombre'])[0]); ?>
                    </p>
                    <p class="text-2xl font-black <?php echo $paletaModal['text_accent']; ?> tracking-tight" id="kpiTotalCount"><?php echo count($tarifas); ?></p>
                </div>
            </div>

            <!-- Tarifas Empresa -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5 cursor-pointer hover:border-blue-300 dark:hover:border-blue-800 transition-all" onclick="filtrarTipoPaciente('E')">
                <div class="p-3 rounded-2xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 shrink-0">
                    <span class="material-symbols-outlined text-2xl">domain</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Empresa (E)</p>
                    <p class="text-2xl font-black text-blue-600 dark:text-blue-400 tracking-tight" id="kpiEmpresaCount"><?php echo $tarifasEmpresa; ?></p>
                </div>
            </div>

            <!-- Tarifas Particular -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5 cursor-pointer hover:border-purple-300 dark:hover:border-purple-800 transition-all" onclick="filtrarTipoPaciente('P')">
                <div class="p-3 rounded-2xl bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 shrink-0">
                    <span class="material-symbols-outlined text-2xl">person</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Particular (P)</p>
                    <p class="text-2xl font-black text-purple-600 dark:text-purple-400 tracking-tight" id="kpiParticularCount"><?php echo $tarifasParticular; ?></p>
                </div>
            </div>

            <!-- Tarifas Activas -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-3.5">
                <div class="p-3 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 shrink-0">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Tarifas Activas</p>
                    <p class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tracking-tight" id="kpiActivasCount"><?php echo $tarifasActivas; ?></p>
                </div>
            </div>

            <!-- Tarifas Deshabilitadas -->
            <div id="cardDeshabilitadas" 
                class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between transition-all group select-none <?php echo ($tarifasDeshabilitadas > 0) ? 'cursor-pointer hover:border-rose-300 dark:hover:border-rose-800 hover:shadow-md hover:scale-[1.01]' : 'opacity-80'; ?>">
                <div class="flex items-center gap-3.5">
                    <div class="p-3 rounded-2xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 shrink-0 group-hover:bg-rose-100 dark:group-hover:bg-rose-900/60 transition-colors">
                        <span class="material-symbols-outlined text-2xl">block</span>
                    </div>
                    <div>
                        <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Deshabilitadas</p>
                        <p class="text-2xl font-black text-rose-600 dark:text-rose-400 tracking-tight" id="kpiDeshabilitadasCount"><?php echo $tarifasDeshabilitadas; ?></p>
                    </div>
                </div>

                <div id="kpiDeshabilitadasAction" class="<?php echo ($tarifasDeshabilitadas > 0) ? '' : 'hidden'; ?>">
                    <span class="inline-flex items-center gap-1 text-[10px] font-extrabold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/60 px-2 py-0.5 rounded-lg border border-rose-200 dark:border-rose-800 group-hover:bg-rose-600 group-hover:text-white transition-all">
                        <span>Ver</span>
                        <span class="material-symbols-outlined text-xs">arrow_forward</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Notification Toast -->
        <div id="toastNotification" class="hidden fixed bottom-6 right-6 z-50 p-4 rounded-2xl bg-slate-900 text-white text-xs font-bold shadow-2xl flex items-center gap-3 transition-all duration-300 transform translate-y-10 opacity-0">
            <span class="material-symbols-outlined text-emerald-400 text-xl" id="toastIcon">check_circle</span>
            <span id="toastMessage">Operación realizada con éxito</span>
        </div>

        <!-- Filtros, Pestañas y Búsqueda -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs mb-6 space-y-4">
            
            <!-- Row 1: Pestañas de Tipo de Paciente y Rango de Registros -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-slate-800 pb-3">
                
                <!-- Pestañas de Filtrado (Todas / Empresa / Particular) -->
                <div class="flex flex-wrap items-center gap-2 bg-slate-100 dark:bg-slate-800/80 p-1.5 rounded-2xl border border-slate-200 dark:border-slate-700/80">
                    <button type="button" id="tabTipoAll" onclick="filtrarTipoPaciente('')"
                        class="tab-tipo-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 <?php echo $paletaModal['tab_active']; ?> cursor-pointer">
                        <span class="material-symbols-outlined text-base">apps</span>
                        <span>Todas (<span id="cntTabAll"><?php echo count($tarifas); ?></span>)</span>
                    </button>

                    <button type="button" id="tabTipoEmpresa" onclick="filtrarTipoPaciente('E')"
                        class="tab-tipo-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800 cursor-pointer">
                        <span class="material-symbols-outlined text-base text-blue-500">domain</span>
                        <span>Empresa - E (<span id="cntTabEmpresa"><?php echo $tarifasEmpresa; ?></span>)</span>
                    </button>

                    <button type="button" id="tabTipoParticular" onclick="filtrarTipoPaciente('P')"
                        class="tab-tipo-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800 cursor-pointer">
                        <span class="material-symbols-outlined text-base text-purple-500">person</span>
                        <span>Particular - P (<span id="cntTabParticular"><?php echo $tarifasParticular; ?></span>)</span>
                    </button>
                </div>

                <!-- Selector de Registros por Página -->
                <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400 font-bold self-end sm:self-auto">
                    <span class="material-symbols-outlined text-base text-slate-400">tune</span>
                    <span>Mostrar:</span>
                    <select id="perPageSelect" onchange="cambiarRegistrosPorPagina(this.value)"
                        class="py-1.5 px-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 <?php echo $paletaModal['ring_focus']; ?> cursor-pointer">
                        <option value="10">10 por pág.</option>
                        <option value="25" selected>25 por pág.</option>
                        <option value="50">50 por pág.</option>
                        <option value="100">100 por pág.</option>
                        <option value="all">Todas las tarifas</option>
                    </select>
                </div>
            </div>

            <!-- Row 2: Buscador, Filtro Estado y Concepto -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Buscador rápido -->
                <div class="relative sm:col-span-1">
                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                    <input type="text" id="searchInput" oninput="aplicarFiltrosYPaginacion()" placeholder="Buscar código, examen o cuenta..." 
                        class="w-full pl-10 pr-4 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> transition-all outline-none" />
                </div>

                <!-- Filtro por Estado (Activas / Deshabilitadas) -->
                <div>
                    <select id="filterEstado" onchange="aplicarFiltrosYPaginacion()" class="w-full py-2.5 px-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-700 dark:text-slate-200 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> transition-all outline-none cursor-pointer">
                        <option value="">Todos los Estados</option>
                        <option value="1">Solo Tarifas Activas</option>
                        <option value="0">Solo Deshabilitadas</option>
                    </select>
                </div>

                <!-- Filtro por Concepto -->
                <div>
                    <select id="filterConcepto" onchange="aplicarFiltrosYPaginacion()" class="w-full py-2.5 px-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> transition-all outline-none cursor-pointer">
                        <option value="">Todos los Conceptos</option>
                        <?php foreach ($conceptosFiltro as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Tabla Tarifario -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs overflow-hidden" style="border-top: 3px solid <?php echo $paletaModal['hex']; ?>;">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="tarifarioTable">
                    <thead>
                        <tr class="bg-slate-100/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 uppercase text-[10px] font-extrabold tracking-wider border-b border-slate-200 dark:border-slate-800">
                            <th class="py-3.5 px-4 whitespace-nowrap">CÓDIGO</th>
                            <th class="py-3.5 px-4 min-w-[280px]">EXAMEN / DESCRIPCIÓN</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">CONCEPTO</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">SERVICIO</th>
                            <th class="py-3.5 px-4 text-center whitespace-nowrap">TIPO</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">ESTADO</th>
                            <th class="py-3.5 px-4 text-right whitespace-nowrap">VALOR UNITARIO</th>
                            <th class="py-3.5 px-4 whitespace-nowrap">CUENTA CONTABLE</th>
                            <th class="py-3.5 px-4 min-w-[160px] whitespace-nowrap">NOMBRE CUENTA</th>
                            <?php if ($canEditTarifa): ?>
                                <th class="py-3.5 px-4 text-center whitespace-nowrap">ACCIONES</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-xs font-medium" id="tarifarioTableBody">
                        <!-- Renderizado dinámico con JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Footer Tabla con Navegación de Paginación -->
            <div class="py-3.5 px-6 bg-slate-50/80 dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400 font-medium">
                <div>
                    Mostrando <strong id="lblPagFrom" class="text-slate-900 dark:text-white font-bold">1</strong> a <strong id="lblPagTo" class="text-slate-900 dark:text-white font-bold">25</strong> de <strong id="lblPagTotal" class="text-slate-900 dark:text-white font-bold"><?php echo count($tarifas); ?></strong> tarifas
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" id="btnPagPrev" onclick="cambiarPagina(-1)" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 disabled:opacity-40 disabled:pointer-events-none font-bold transition-all flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-base">chevron_left</span>
                        <span>Anterior</span>
                    </button>

                    <span class="px-3 py-1.5 text-xs font-bold text-slate-700 dark:text-slate-300">
                        Página <span id="lblPaginaActual" class="<?php echo $paletaModal['text_accent']; ?> font-black">1</span> de <span id="lblTotalPaginas" class="font-black">1</span>
                    </span>

                    <button type="button" id="btnPagNext" onclick="cambiarPagina(1)" class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 disabled:opacity-40 disabled:pointer-events-none font-bold transition-all flex items-center gap-1 cursor-pointer">
                        <span>Siguiente</span>
                        <span class="material-symbols-outlined text-base">chevron_right</span>
                    </button>
                </div>
            </div>
        </div>

    </main>

    <!-- Modal Selección de Entidad / Catálogo (Idéntico a maestro_porcentajes.php) -->
    <div id="modalSeleccionEntidad" 
         class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-950/85 backdrop-blur-md transition-all duration-300">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl max-w-4xl w-full overflow-hidden flex flex-col max-h-[92vh] animate__animated animate__fadeInDown animate__faster">
            
            <!-- Modal Header -->
            <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-gradient-to-r from-slate-50 via-white to-slate-50 dark:from-slate-800/90 dark:via-slate-900 dark:to-slate-800/90">
                <div class="flex items-center gap-3.5">
                    <div class="p-3 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-500 text-white shadow-md shadow-emerald-500/20">
                        <span class="material-symbols-outlined text-2xl">domain</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-black text-slate-900 dark:text-white font-outfit tracking-tight">
                                Selección de Entidad / Catálogo de Tarifario
                            </h3>
                            <span class="px-2.5 py-0.5 text-[10px] font-black uppercase rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800/60 tracking-wider">
                                Catálogos
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Elija la IPS o Empresa sobre la cual desea consultar, filtrar y administrar las tarifas médicas.
                        </p>
                    </div>
                </div>

                <button type="button" onclick="cerrarModalSeleccionarEntidad()" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer" title="Cerrar ventana">
                    <span class="material-symbols-outlined text-2xl">close</span>
                </button>
            </div>

            <!-- Modal Body: Cards Grid -->
            <div class="p-6 overflow-y-auto space-y-6 flex-1">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- Card 1: Hernán Ocazionez (Entidad Propia / Matriz) -->
                    <?php 
                        $cntPropio = $tarifasCountPorEntidad['PROPIO'] ?? 0;
                        $isPropioActivo = ($entidadSeleccionada === 'PROPIO');
                    ?>
                    <div onclick="confirmarSeleccionEntidad('PROPIO', 'HERNÁN OCAZIONEZ Y CÍA S.A.S.')"
                        class="group relative flex flex-col justify-between p-5 rounded-2xl border-2 transition-all duration-200 cursor-pointer text-left
                        <?php echo $isPropioActivo ? 'border-teal-500 bg-teal-50/20 dark:bg-teal-950/20 shadow-lg shadow-teal-500/10' : 'border-slate-200/80 dark:border-slate-800 hover:border-teal-400 dark:hover:border-teal-500 bg-white dark:bg-slate-800/60 hover:shadow-md'; ?>">
                        
                        <div class="space-y-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="w-16 h-16 rounded-2xl bg-slate-900 border border-slate-700/80 p-2 flex items-center justify-center shadow-md shadow-slate-900/20 group-hover:scale-105 transition-transform duration-200 shrink-0">
                                    <img src="assets/img/hologo.png" alt="Hernán Ocazionez" class="w-full h-full object-contain">
                                </div>
                                
                                <div class="flex flex-col items-end gap-1">
                                    <span class="px-2.5 py-1 text-[10px] font-black uppercase rounded-full bg-slate-900 text-white dark:bg-slate-700 tracking-wider">
                                        IPS Matriz / Propia
                                    </span>
                                    <?php if ($isPropioActivo): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold text-teal-600 dark:text-teal-400">
                                            <span class="material-symbols-outlined text-sm">check_circle</span>
                                            Activa actualmente
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-base font-bold text-slate-900 dark:text-white group-hover:text-teal-600 dark:group-hover:text-teal-400 transition-colors font-outfit">
                                    HERNÁN OCAZIONEZ Y CÍA S.A.S.
                                </h4>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Sistemas Diagnósticos e Imágenes Médicas Especializadas
                                </p>
                            </div>

                            <div class="pt-3 border-t border-slate-100 dark:border-slate-700/60 flex items-center justify-between text-xs">
                                <div class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                                    <span class="material-symbols-outlined text-sm text-teal-600">request_quote</span>
                                    <span><strong><?php echo $cntPropio; ?></strong> tarifas en catálogo</span>
                                </div>
                                <span class="text-[11px] font-medium text-slate-400">NIT: 800.149.695-1</span>
                            </div>
                        </div>

                        <div class="mt-4 pt-3">
                            <button type="button" class="w-full py-2.5 px-4 rounded-xl text-xs font-bold transition-all duration-200 flex items-center justify-center gap-2
                                <?php echo $isPropioActivo ? 'bg-teal-600 text-white shadow-md shadow-teal-600/20' : 'bg-slate-100 dark:bg-slate-700/60 text-slate-700 dark:text-slate-200 group-hover:bg-teal-600 group-hover:text-white'; ?>">
                                <span><?php echo $isPropioActivo ? 'Catálogo Activo (Continuar)' : 'Seleccionar Este Catálogo'; ?></span>
                                <span class="material-symbols-outlined text-base">arrow_forward</span>
                            </button>
                        </div>
                    </div>

                    <!-- Cards 2+: External Entities Only (Excluyendo IPS Matriz) -->
                    <?php 
                    foreach ($entidadesExternas as $ent): 
                        $eKey = (string)$ent['id'];
                        if ($eKey === 'PROPIO' || $eKey === '4' || (int)($ent['id_int'] ?? 0) === 4 || stripos($ent['nombre'] ?? '', 'HERNAN') !== false) continue;
                        $isEntActiva = ($entidadSeleccionada === $eKey);
                        $cntEnt = $tarifasCountPorEntidad[$eKey] ?? 0;
                        $logoPath = !empty($ent['logo']) ? $ent['logo'] : '';
                        $entColor = !empty($ent['color_tema']) ? strtolower(trim($ent['color_tema'])) : 'purple';
                        $paletaEnt = obtenerPaletaEntidad($entColor, $ent['id']);
                    ?>
                        <div onclick="confirmarSeleccionEntidad('<?php echo $ent['id']; ?>', '<?php echo addslashes($ent['nombre']); ?>')"
                            class="group relative flex flex-col justify-between p-5 rounded-2xl border-2 transition-all duration-200 cursor-pointer text-left
                            <?php echo $isEntActiva ? $paletaEnt['card_border'] . ' ' . $paletaEnt['card_bg'] . ' ' . $paletaEnt['card_shadow'] : 'border-slate-200/80 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 bg-white dark:bg-slate-800/60 hover:shadow-md'; ?>">
                            
                            <div class="space-y-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="w-16 h-16 rounded-2xl bg-white border border-slate-200 shadow-sm p-1.5 flex items-center justify-center group-hover:scale-105 transition-transform duration-200 shrink-0 overflow-hidden">
                                        <?php if (!empty($logoPath) && file_exists(__DIR__ . '/' . $logoPath)): ?>
                                            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="Logo <?php echo htmlspecialchars($ent['nombre']); ?>" class="w-full h-full object-contain" onerror="this.onerror=null; this.parentElement.innerHTML='<span class=\'text-xs font-black text-slate-600\'><?php echo strtoupper(substr($ent['nombre'], 0, 3)); ?></span>';">
                                        <?php else: ?>
                                            <div class="w-full h-full rounded-xl bg-slate-100 flex items-center justify-center font-black text-slate-700 text-sm">
                                                <?php echo strtoupper(substr($ent['nombre'], 0, 3)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex flex-col items-end gap-1">
                                        <span class="px-2.5 py-1 text-[10px] font-black uppercase rounded-full <?php echo $paletaEnt['card_badge']; ?> tracking-wider">
                                            IPS Externa
                                        </span>
                                        <?php if ($isEntActiva): ?>
                                            <span class="inline-flex items-center gap-1 text-[10px] font-bold <?php echo $paletaEnt['pill_text']; ?>">
                                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                                Activa actualmente
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div>
                                    <h4 class="text-base font-bold text-slate-900 dark:text-white transition-colors font-outfit">
                                        <?php echo htmlspecialchars($ent['nombre']); ?>
                                    </h4>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        <?php echo htmlspecialchars($ent['subtitulo']); ?>
                                    </p>
                                </div>

                                <div class="pt-3 border-t border-slate-100 dark:border-slate-700/60 flex items-center justify-between text-xs">
                                    <div class="flex items-center gap-1.5 text-slate-600 dark:text-slate-300">
                                        <span class="material-symbols-outlined text-sm <?php echo $paletaEnt['icon_color']; ?>">request_quote</span>
                                        <span><strong><?php echo $cntEnt; ?></strong> tarifas en catálogo</span>
                                    </div>
                                    <span class="text-[11px] font-medium text-slate-400">NIT: <?php echo htmlspecialchars($ent['nit']); ?></span>
                                </div>
                            </div>

                            <div class="mt-4 pt-3">
                                <button type="button" class="w-full py-2.5 px-4 rounded-xl text-xs font-bold transition-all duration-200 flex items-center justify-center gap-2
                                    <?php echo $isEntActiva ? $paletaEnt['card_btn'] : 'bg-slate-100 dark:bg-slate-700/60 text-slate-700 dark:text-slate-200'; ?>">
                                    <span><?php echo $isEntActiva ? 'Catálogo Activo (Continuar)' : 'Seleccionar Este Catálogo'; ?></span>
                                    <span class="material-symbols-outlined text-base">arrow_forward</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($entidadesExternas)): ?>
                        <div class="col-span-1 p-6 rounded-2xl border-2 border-dashed border-slate-200 dark:border-slate-800 text-center flex flex-col items-center justify-center gap-2 bg-slate-50/50 dark:bg-slate-800/30">
                            <span class="material-symbols-outlined text-3xl text-slate-300 dark:text-slate-600">domain_disabled</span>
                            <p class="text-xs font-bold text-slate-600 dark:text-slate-300">No hay otras entidades externas registradas</p>
                            <p class="text-[11px] text-slate-400">Puede agregar convenios e IPS en el Maestro de Entidades.</p>
                        </div>
                    <?php endif; ?>

                </div>

                <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-800 dark:text-amber-300 text-xs flex items-center gap-3">
                    <span class="material-symbols-outlined text-xl text-amber-600 shrink-0">info</span>
                    <p>
                        <strong>Nota importante:</strong> Cada entidad médica cuenta con su propio catálogo de tarifas de exámenes independiente. Puede importar códigos base desde la IPS Matriz cuando lo requiera.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Registrar NUEVA TARIFA (Ultra Amplio, Espacioso y Organizado) -->
    <?php if ($canEditTarifa): ?>
    <div id="modalNuevaTarifa" class="fixed inset-0 bg-slate-950/70 backdrop-blur-md z-50 flex items-center justify-center p-4 sm:p-6 lg:p-8 hidden opacity-0 pointer-events-none transition-all duration-300 overflow-y-auto">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-4xl lg:max-w-5xl w-full shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden transform scale-95 transition-all duration-300 my-auto" id="modalNuevaTarifaContent" style="border-top: 5px solid <?php echo $paletaModal['hex']; ?>;">
            
            <!-- Modal Header -->
            <div class="px-6 sm:px-8 py-5 bg-slate-50/90 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-11 h-11 rounded-2xl <?php echo $paletaModal['icon_bg']; ?> flex items-center justify-center shrink-0 shadow-sm">
                        <span class="material-symbols-outlined text-2xl">add_circle</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <h3 class="text-lg font-black text-slate-900 dark:text-white tracking-tight font-outfit">Registrar Nueva Tarifa</h3>
                            <span class="px-3 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider <?php echo $paletaModal['badge']; ?> border">
                                <?php echo htmlspecialchars($entidadActiva['nombre']); ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mt-0.5">Inserción directa en base de datos con firma y trazabilidad de auditoría</p>
                    </div>
                </div>
                <button type="button" id="closeModalNuevaTarifaBtn" class="w-9 h-9 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 flex items-center justify-center transition-colors cursor-pointer" title="Cerrar ventana">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Formulario Nueva Tarifa -->
            <form id="formNuevaTarifa" class="p-6 sm:p-8 space-y-6">
                <input type="hidden" name="action" value="crear_tarifa" />
                <input type="hidden" name="entidad_id" id="new_entidad_id" value="<?php echo $entidadActivaIdInt; ?>" />

                <!-- Banner Ficha de Empresa Asignada (Espacioso y con jerarquía) -->
                <div class="rounded-2xl border <?php echo $paletaModal['card_bg']; ?> p-4 sm:p-5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 shadow-xs">
                    <div class="flex items-center gap-4 min-w-0">
                        <?php if (!empty($entidadActiva['logo']) && file_exists(__DIR__ . '/' . $entidadActiva['logo'])): ?>
                            <div class="w-14 h-14 rounded-2xl bg-white p-2 shadow-xs border border-slate-200 dark:border-slate-700 flex items-center justify-center shrink-0 overflow-hidden">
                                <img src="<?php echo htmlspecialchars($entidadActiva['logo']); ?>" alt="Logo" class="max-w-full max-h-full object-contain" />
                            </div>
                        <?php else: ?>
                            <div class="w-14 h-14 rounded-2xl <?php echo $paletaModal['icon_bg']; ?> flex items-center justify-center shrink-0 shadow-xs">
                                <span class="material-symbols-outlined text-3xl">
                                    <?php echo ($entidadActivaIdInt === $hoId || $entidadActivaIdInt === 0) ? 'local_hospital' : 'apartment'; ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="min-w-0">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                <span class="text-base sm:text-lg font-black text-slate-900 dark:text-white truncate font-outfit">
                                    <?php echo htmlspecialchars($entidadActiva['nombre']); ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase tracking-wider border <?php echo $paletaModal['badge']; ?>">
                                    <?php echo ($entidadActivaIdInt === $hoId || $entidadActivaIdInt === 0) ? 'IPS Matriz' : 'IPS Externa'; ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-slate-500 dark:text-slate-400 font-medium mt-1">
                                <span>NIT: <strong class="text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars($entidadActiva['nit']); ?></strong></span>
                                <span class="text-slate-300 dark:text-slate-600">•</span>
                                <span><?php echo htmlspecialchars($entidadActiva['subtitulo']); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="flex sm:flex-col items-center sm:items-end gap-1.5 shrink-0">
                        <div class="flex items-center gap-2 px-3 py-1 rounded-xl <?php echo $paletaModal['pill']; ?> text-xs font-black uppercase tracking-wide">
                            <span class="w-2 h-2 rounded-full <?php echo $paletaModal['indicator']; ?> animate-pulse"></span>
                            <span>Catálogo Destino</span>
                        </div>
                        <span class="text-[10px] text-slate-400 dark:text-slate-500 font-semibold">Asignación automática</span>
                    </div>
                </div>

                <!-- Sección: Datos del Examen -->
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                                <span>Código Examen (CUPS)</span>
                                <span class="text-rose-500 font-black">*</span>
                            </label>
                            <input type="text" name="codigo" required placeholder="Ej. 881134"
                                class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-mono font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all shadow-xs" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                                <span>Nombre / Descripción del Examen</span>
                                <span class="text-rose-500 font-black">*</span>
                            </label>
                            <input type="text" name="examen" required placeholder="Ej. ULTRASONOGRAFIA DOPPLER OBSTETRICA CON PERFIL BIOFISICO..."
                                class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all shadow-xs" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5">Concepto</label>
                            <input type="text" name="concepto" placeholder="Ej. DOPP, ECOG"
                                class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all uppercase shadow-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5">Servicio</label>
                            <input type="text" name="servicio" placeholder="Ej. DOPPLER, ECOGRAFIA"
                                class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all uppercase shadow-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5">Tipo Tarifa</label>
                            <select name="tipo_paciente" class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all cursor-pointer shadow-xs">
                                <option value="E">E - EMPRESA</option>
                                <option value="P">P - PARTICULAR</option>
                            </select>
                        </div>
                    </div>

                    <!-- Valores y Contabilidad -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                                <span>Valor Unitario ($)</span>
                                <span class="text-rose-500 font-black">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400 dark:text-slate-500">$</span>
                                <input type="text" inputmode="numeric" id="new_valor_und" name="valor_und" required placeholder="38.100"
                                    oninput="formatearMilesInput(this)"
                                    class="w-full pl-8 pr-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-white focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all font-outfit shadow-xs" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5">Valor Base ($)</label>
                            <div class="relative">
                                <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400 dark:text-slate-500">$</span>
                                <input type="text" inputmode="numeric" id="new_columna1" name="columna1" placeholder="38.100"
                                    oninput="formatearMilesInput(this)"
                                    class="w-full pl-8 pr-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-white focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all font-outfit shadow-xs" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5">Cuenta Contable</label>
                            <input type="text" name="cuenta_contable" placeholder="Ej. 61251004"
                                class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-mono font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all shadow-xs" />
                        </div>
                        <div>
                            <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5">Nombre Cuenta</label>
                            <input type="text" name="nombre_cuenta" placeholder="Ej. HONORARIOS MED DOPPLER"
                                class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all uppercase shadow-xs" />
                        </div>
                    </div>

                    <!-- Parámetros de Liquidación -->
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 space-y-3">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined <?php echo $paletaModal['text_accent']; ?> text-lg">payments</span>
                            <h4 class="text-xs font-black uppercase tracking-wider text-slate-800 dark:text-slate-200">Parámetros de Liquidación de Honorarios</h4>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                                    <span>¿Pagar por Cantidad?</span>
                                    <span class="text-[10px] text-slate-400 font-normal">Columna liquidación</span>
                                </label>
                                <select name="pagar_por_cantidad" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all shadow-xs cursor-pointer">
                                    <option value="1">SÍ (Multiplicar por cantidad de liquidación / Servinte)</option>
                                    <option value="0">NO (Tarifa fija por examen / No multiplicar)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                                    <span>Base de Cálculo</span>
                                    <span class="text-[10px] text-slate-400 font-normal">Origen del valor</span>
                                </label>
                                <select name="base_calculo" class="w-full px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all shadow-xs cursor-pointer">
                                    <option value="VALOR_LIQUIDACION">VALOR DE LIQUIDACIÓN (Tarifario LIHO fijado)</option>
                                    <option value="VALOR_EXAMEN">VALOR DE EXAMEN (Facturado en Servinte)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Justificación / Motivo Obligatorio -->
                    <div>
                        <label class="block text-xs font-black uppercase text-slate-600 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                            <span>Justificación / Motivo de Registro</span>
                            <span class="text-[10px] text-rose-500 font-black uppercase bg-rose-500/10 px-2 py-0.5 rounded-full border border-rose-500/20">Requerido para Auditoría</span>
                        </label>
                        <textarea name="motivo_cambio" rows="2" required
                            placeholder="Describa el motivo o justificación de la nueva tarifa para el registro oficial en auditoría..."
                            class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-medium text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 <?php echo $paletaModal['ring_focus']; ?> outline-none transition-all resize-none shadow-xs"></textarea>
                    </div>
                </div>

                <!-- Footer Botones -->
                <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400 font-medium">
                        <span class="material-symbols-outlined text-base <?php echo $paletaModal['text_accent']; ?>">verified_user</span>
                        <span>Se registrará en el catálogo de <strong class="text-slate-800 dark:text-slate-200"><?php echo htmlspecialchars($entidadActiva['nombre']); ?></strong></span>
                    </div>

                    <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                        <button type="button" id="cancelNuevaTarifaBtn" class="px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-bold transition-all cursor-pointer">
                            Cancelar
                        </button>

                        <button type="submit" id="saveNuevaTarifaBtn"
                            class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl <?php echo $paletaModal['btn']; ?> text-xs font-bold transition-all hover:scale-105 active:scale-95 cursor-pointer shadow-md">
                            <span class="material-symbols-outlined text-lg" id="saveNuevaIcon">add_circle</span>
                            <span id="saveNuevaText">Crear Tarifa</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Listado Tarifas Deshabilitadas -->
    <div id="modalTarifasDeshabilitadas" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-3xl w-full shadow-2xl border border-rose-200 dark:border-rose-900/60 overflow-hidden transform scale-95 transition-all duration-300 flex flex-col max-h-[85vh]">
            
            <!-- Header Modal -->
            <div class="px-6 py-4 bg-rose-50/80 dark:bg-rose-950/40 border-b border-rose-100 dark:border-rose-900/40 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-rose-100 dark:bg-rose-900/60 text-rose-700 dark:text-rose-300">
                        <span class="material-symbols-outlined text-2xl">block</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-slate-900 dark:text-white tracking-tight">Tarifas Deshabilitadas</h3>
                        <p class="text-xs text-rose-600 dark:text-rose-400 font-semibold">Listado de exámenes inactivos actualmente</p>
                    </div>
                </div>
                <button type="button" id="closeModalDeshabilitadasBtn" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Body con Tabla de Deshabilitadas -->
            <div class="p-6 overflow-y-auto flex-grow space-y-4">
                <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
                    <table class="w-full text-left border-collapse" id="tablaDeshabilitadas">
                        <thead>
                            <tr class="bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 uppercase text-[10px] font-extrabold tracking-wider border-b border-slate-200 dark:border-slate-800">
                                <th class="py-3 px-4">CÓDIGO</th>
                                <th class="py-3 px-4">EXAMEN</th>
                                <th class="py-3 px-4 text-right">VALOR UNITARIO</th>
                                <?php if ($canEditTarifa): ?>
                                <th class="py-3 px-4 text-center">ACCIONES</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs font-medium" id="tbodyDeshabilitadas">
                            <!-- Se llena dinámicamente con JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer Modal -->
            <div class="px-6 py-3 bg-slate-50 dark:bg-slate-950 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400 shrink-0">
                <span>Total deshabilitadas: <strong id="modalDeshabilitadasCount" class="text-rose-600 dark:text-rose-400 font-bold">0</strong></span>
                <button type="button" id="closeModalDeshabilitadasFooterBtn" class="px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold transition-all">
                    Cerrar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Editar Tarifa -->
    <?php if ($canEditTarifa): ?>
    <div id="modalEditarTarifa" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 hidden opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden transform scale-95 transition-all duration-300" id="modalEditarContent">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-slate-50 dark:bg-slate-800/80 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-teal-50 dark:bg-teal-950/60 text-tertiary">
                        <span class="material-symbols-outlined text-2xl">edit_square</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-primary dark:text-white tracking-tight">Editar Tarifa de Examen</h3>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Modificación registrada en historial de auditoría</p>
                    </div>
                </div>
                <button type="button" class="closeModalBtn p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Modal Formulario -->
            <form id="formEditarTarifa" class="p-6 space-y-4">
                <input type="hidden" id="edit_tarifa_id" name="id" />
                <input type="hidden" name="action" value="guardar_tarifa" />

                <!-- Card Info Examen -->
                <div class="p-3.5 rounded-2xl bg-slate-100/80 dark:bg-slate-800/80 border border-slate-200/80 dark:border-slate-700/80 space-y-2.5">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="px-2.5 py-0.5 rounded-md bg-primary dark:bg-tertiary text-white font-mono text-[11px] font-bold" id="edit_codigo_badge">-</span>
                            <span class="text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider">Código Examen</span>
                        </div>
                        <div id="modal_estado_pill"></div>
                    </div>
                    <div>
                        <label for="edit_examen_nombre" class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1 flex items-center justify-between">
                            <span>Nombre / Descripción del Examen</span>
                            <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <textarea id="edit_examen_nombre" name="examen" rows="2" required
                            placeholder="Nombre del examen..."
                            class="w-full px-3.5 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all resize-none shadow-xs"></textarea>
                    </div>
                </div>

                <!-- Selección de Estado -->
                <div>
                    <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1.5">Estado de la Tarifa</label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2.5 p-3 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-emerald-50/50 cursor-pointer transition-all has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50/80 dark:has-[:checked]:bg-emerald-950/60">
                            <input type="radio" name="estado" value="1" id="radio_estado_1" class="text-emerald-600 focus:ring-emerald-500" />
                            <div>
                                <p class="text-xs font-bold leading-tight dark:text-slate-100">Activa</p>
                                <p class="text-[10px] text-slate-400 dark:text-slate-500">Disponible para liquidar</p>
                            </div>
                        </label>

                        <label class="flex items-center gap-2.5 p-3 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-rose-50/50 cursor-pointer transition-all has-[:checked]:border-rose-500 has-[:checked]:bg-rose-50/80 dark:has-[:checked]:bg-rose-950/60">
                            <input type="radio" name="estado" value="0" id="radio_estado_0" class="text-rose-600 focus:ring-rose-500" />
                            <div>
                                <p class="text-xs font-bold leading-tight dark:text-slate-100">Deshabilitada</p>
                                <p class="text-[10px] text-slate-400 dark:text-slate-500">No disponible</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Grid de Valores y Tipo Paciente -->
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1">Tipo Tarifa</label>
                        <select name="tipo_paciente" id="edit_tipo_paciente" class="w-full px-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all">
                            <option value="E">E - EMPRESA</option>
                            <option value="P">P - PARTICULAR</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1">Valor Unitario ($)</label>
                        <input type="text" inputmode="numeric" id="edit_valor_und" name="valor_und" required
                            oninput="formatearMilesInput(this)"
                            class="w-full px-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all font-outfit" />
                    </div>

                    <div>
                        <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1">Valor Base ($)</label>
                        <input type="text" inputmode="numeric" id="edit_columna1" name="columna1" required
                            oninput="formatearMilesInput(this)"
                            class="w-full px-3 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all font-outfit" />
                    </div>
                </div>

                <!-- Valor Formateado -->
                <div>
                    <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1">Valor Formateado (Texto)</label>
                    <input type="text" id="edit_valor_texto" name="valor_texto" required
                        placeholder="Ej. $ 38.100"
                        class="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all" />
                </div>

                <!-- Configuración de Liquidación (Pagar por Cantidad y Base de Cálculo) -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 space-y-3">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-tertiary text-lg">payments</span>
                        <h4 class="text-xs font-black uppercase tracking-wider text-slate-800 dark:text-slate-200">Parámetros de Liquidación de Honorarios</h4>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <!-- Pagar por Cantidad -->
                        <div>
                            <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1 flex items-center justify-between">
                                <span>¿Pagar por Cantidad?</span>
                                <span class="text-[10px] text-slate-400 font-normal">Columna liquidación</span>
                            </label>
                            <select name="pagar_por_cantidad" id="edit_pagar_por_cantidad" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all cursor-pointer shadow-xs">
                                <option value="1">SÍ (Multiplicar por cantidad)</option>
                                <option value="0">NO (Tarifa fija / Valor único)</option>
                            </select>
                        </div>

                        <!-- Base de Cálculo -->
                        <div>
                            <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1 flex items-center justify-between">
                                <span>Base de Cálculo</span>
                                <span class="text-[10px] text-slate-400 font-normal">Origen del valor</span>
                            </label>
                            <select name="base_calculo" id="edit_base_calculo" class="w-full px-3 py-2.5 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-800 dark:text-slate-100 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all cursor-pointer shadow-xs">
                                <option value="VALOR_LIQUIDACION">VALOR DE LIQUIDACIÓN (Tarifario LIHO)</option>
                                <option value="VALOR_EXAMEN">VALOR DE EXAMEN (Facturado Servinte)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Motivo Obligatorio -->
                <div>
                    <label class="block text-xs font-extrabold uppercase text-slate-600 dark:text-slate-400 mb-1 flex items-center justify-between">
                        <span>Motivo / Justificación de Modificación</span>
                        <span class="text-[10px] text-rose-500 font-bold uppercase">Requerido</span>
                    </label>
                    <textarea id="edit_motivo_cambio" name="motivo_cambio" rows="3" required
                        placeholder="Escriba la razón de la actualización o cambio de estado para auditoría..."
                        class="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-xs font-medium text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-tertiary focus:ring-2 focus:ring-tertiary/20 outline-none transition-all resize-none"></textarea>
                </div>

                <!-- Botones -->
                <div class="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-3">
                    <button type="button" class="closeModalBtn px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-bold transition-all">
                        Cancelar
                    </button>

                    <button type="submit" id="saveTarifaBtn"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-primary dark:bg-tertiary hover:bg-primary/90 text-white text-xs font-bold shadow-md shadow-primary/20 transition-all hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg" id="saveIcon">save</span>
                        <span id="saveText">Guardar Cambios</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal para Importar / Copiar Exámenes desde Hernán Ocazionez -->
    <div id="modalImportarTarifario" class="fixed inset-0 bg-slate-950/70 backdrop-blur-md z-50 flex items-center justify-center p-3 sm:p-6 hidden opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-5xl w-full shadow-2xl border border-purple-200 dark:border-purple-900/60 overflow-hidden transform scale-95 transition-all duration-300 flex flex-col max-h-[90vh]" id="modalImportarContent">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-purple-50/80 dark:bg-purple-950/40 border-b border-purple-100 dark:border-purple-900/40 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300 shadow-sm">
                        <span class="material-symbols-outlined text-2xl">library_add</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                            <span>Copiar Exámenes de Hernán Ocazionez</span>
                            <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-700/50">Base General</span>
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                            Seleccione las tarifas que desea importar al catálogo de <strong id="modalImportEntidadNombre" class="text-purple-600 dark:text-purple-400 font-bold"></strong>
                        </p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalImportar()" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors cursor-pointer" title="Cerrar ventana">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Toolbar de Filtros y Búsqueda -->
            <div class="p-4 bg-slate-100/90 dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 shrink-0">
                <!-- Buscador rápido en vivo -->
                <div class="relative flex-grow max-w-lg">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-lg">search</span>
                    <input type="text" id="importSearchInput" oninput="onInputBusquedaImportar(this.value)"
                        placeholder="Buscar por código CUPS, examen, concepto..."
                        class="w-full pl-9 pr-8 py-2 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-semibold text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-400 focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none transition-all shadow-xs" />
                    <button type="button" onclick="limpiarBusquedaImportar()" id="btnLimpiarBusquedaImport" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hidden">
                        <span class="material-symbols-outlined text-base">close</span>
                    </button>
                </div>

                <!-- Filtros Tipo y Acciones en Lote -->
                <div class="flex items-center gap-2 flex-wrap sm:flex-nowrap justify-between">
                    <!-- Segmented Control Tipo -->
                    <div class="inline-flex p-1 bg-slate-200/80 dark:bg-slate-800 rounded-xl text-xs font-bold border border-slate-300/50 dark:border-slate-700">
                        <button type="button" id="importTabTipoAll" onclick="filtrarTipoImportar('')" class="px-3 py-1 rounded-lg bg-white dark:bg-purple-600 text-purple-700 dark:text-white shadow-xs cursor-pointer transition-all font-black">
                            Todos (<span id="importPillAllCount">0</span>)
                        </button>
                        <button type="button" id="importTabTipoEmp" onclick="filtrarTipoImportar('E')" class="px-3 py-1 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer transition-all font-semibold">
                            Empresa E (<span id="importPillEmpCount">0</span>)
                        </button>
                        <button type="button" id="importTabTipoPar" onclick="filtrarTipoImportar('P')" class="px-3 py-1 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer transition-all font-semibold">
                            Particular P (<span id="importPillParCount">0</span>)
                        </button>
                    </div>

                    <!-- Botones de Selección Rápida -->
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="seleccionarTodoElCatalogo()" class="px-3 py-1.5 rounded-xl bg-purple-100 hover:bg-purple-200 dark:bg-purple-950/60 dark:hover:bg-purple-900/80 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-800/80 text-[11px] font-bold transition-all cursor-pointer shadow-xs" title="Seleccionar todos los exámenes disponibles">
                            Todo
                        </button>
                        <button type="button" onclick="deseleccionarTodo()" class="px-3 py-1.5 rounded-xl bg-slate-200/80 hover:bg-slate-300 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-300/60 dark:border-slate-700 text-[11px] font-bold transition-all cursor-pointer shadow-xs" title="Deseleccionar todo">
                            Ninguno
                        </button>
                    </div>
                </div>
            </div>

            <!-- Contador de Selección -->
            <div class="px-6 py-2.5 bg-purple-500/10 dark:bg-purple-950/30 border-b border-purple-500/20 flex items-center justify-between text-xs shrink-0">
                <span class="text-slate-700 dark:text-slate-300 font-bold">
                    Exámenes seleccionados para importar:
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-purple-600 text-white font-black text-xs shadow-xs">
                    <span id="lblImportSeleccionados">0</span> de <span id="lblImportTotalBase">0</span>
                </span>
            </div>

            <!-- Body: Tabla con Scroll -->
            <div class="overflow-y-auto flex-grow max-h-[50vh] p-4">
                <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs">
                    <table class="w-full text-left border-collapse" id="tablaImportarExamenes">
                        <thead class="sticky top-0 z-10 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 uppercase text-[10px] font-extrabold tracking-wider border-b border-slate-200 dark:border-slate-700 shadow-xs">
                            <tr>
                                <th class="py-3 px-3.5 text-center w-12">
                                    <input type="checkbox" id="importCheckMaster" onchange="toggleSelectAllVisible(this.checked)" class="w-4 h-4 rounded text-purple-600 focus:ring-purple-500 cursor-pointer" title="Seleccionar/Deseleccionar visibles" />
                                </th>
                                <th class="py-3 px-3">CÓDIGO CUPS</th>
                                <th class="py-3 px-4">EXAMEN / DESCRIPCIÓN</th>
                                <th class="py-3 px-3">CONCEPTO / SERVICIO</th>
                                <th class="py-3 px-3 text-center">TIPO</th>
                                <th class="py-3 px-4 text-right">VALOR BASE</th>
                                <th class="py-3 px-3 text-center">ESTADO DESTINO</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs font-medium" id="importTbodyExamenes">
                            <!-- Inyectado dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-3.5 bg-slate-50 dark:bg-slate-950 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3 shrink-0">
                <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-purple-500 text-base shrink-0">verified_user</span>
                    <span>Los exámenes seleccionados se importarán activos y se generará registro en el log de auditoría.</span>
                </div>
                <div class="flex items-center gap-2.5 w-full sm:w-auto justify-end">
                    <button type="button" onclick="cerrarModalImportar()" class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-bold transition-all cursor-pointer">
                        Cancelar
                    </button>
                    <button type="button" id="btnEjecutarImportar" onclick="ejecutarImportacion()"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-black text-xs shadow-lg shadow-purple-900/30 transition-all hover:scale-105 active:scale-95 cursor-pointer disabled:opacity-50 disabled:pointer-events-none">
                        <span class="material-symbols-outlined text-base" id="btnImportarIcon">download</span>
                        <span id="btnImportarText">Importar (<span id="btnImportarCount">0</span>) Exámenes</span>
                    </button>
            </div>
        </div>
    </div>
    </div>

    <!-- Modal Autorizar Cambio de Tarifas y Nueva Vigencia -->
    <?php if ($canEditTarifa): ?>
    <div id="modalAutorizarVigencia" onclick="if(event.target === this) cerrarModalAutorizarVigencia()" class="fixed inset-0 bg-slate-950/70 backdrop-blur-md z-50 flex items-center justify-center p-3 sm:p-6 hidden opacity-0 pointer-events-none transition-all duration-300">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-3xl lg:max-w-4xl w-full shadow-2xl border border-amber-300 dark:border-amber-700/60 overflow-hidden transform scale-95 transition-all duration-300 flex flex-col" id="modalAutorizarContent">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-gradient-to-r from-amber-500/15 via-orange-500/10 to-transparent dark:from-amber-950/40 dark:via-orange-950/20 border-b border-amber-200 dark:border-amber-800/50 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                    <div class="p-2.5 rounded-2xl bg-gradient-to-tr from-amber-600 to-orange-500 text-white shadow-md shadow-amber-500/30">
                        <span class="material-symbols-outlined text-2xl">published_with_changes</span>
                    </div>
                    <div>
                        <h3 class="text-base sm:text-lg font-black text-slate-900 dark:text-white tracking-tight">Actualizar y Autorizar Tarifario</h3>
                        <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold">Cargar nuevo listado de exámenes y valores con vigencia temporal</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalAutorizarVigencia()" class="p-2 rounded-xl text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200/60 dark:hover:bg-slate-800 transition-colors cursor-pointer" title="Cerrar ventana">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <!-- Modal Body / Form -->
            <form id="formAutorizarVigencia" onsubmit="enviarAutorizarVigencia(event); return false;" class="p-6 space-y-4 overflow-y-auto max-h-[80vh]">
                
                <!-- Info Explicativa Clara y Simple -->
                <div class="p-4 rounded-2xl bg-amber-50/90 dark:bg-amber-950/30 border border-amber-200/80 dark:border-amber-800/60 text-xs text-slate-700 dark:text-slate-300">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-amber-600 dark:text-amber-400 text-xl shrink-0 mt-0.5">verified</span>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white">¿Cómo se aplicará la nueva vigencia?</p>
                            <p class="text-slate-600 dark:text-slate-400 mt-1 leading-relaxed">
                                Los nuevos valores de los exámenes comenzarán a regir a partir de la <strong>Fecha de Vigencia</strong> (por defecto, la fecha de hoy en que realizas la actualización). Los valores anteriores quedarán guardados en el historial para liquidar atenciones pasadas.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Fila de Campos: Fecha de Vigencia y Nombre del Tarifario -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Campo: Fecha de Vigencia -->
                    <div>
                        <label for="aut_fecha_vigencia" class="block text-xs font-extrabold uppercase text-slate-700 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                            <span>Fecha de Vigencia (A partir de)</span>
                            <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <input type="date" id="aut_fecha_vigencia" name="fecha_vigencia" required
                               value="<?php echo date('Y-m-d'); ?>"
                               onchange="actualizarNombreVigenciaAutomatico(this.value)"
                               class="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-white focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-all shadow-xs" />
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">
                            El tarifario anterior terminará el día anterior a esta fecha.
                        </p>
                    </div>

                    <!-- Campo: Nombre de la Vigencia -->
                    <div>
                        <label for="aut_nombre_vigencia" class="block text-xs font-extrabold uppercase text-slate-700 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                            <span>Nombre / Etiqueta de la Vigencia</span>
                            <span class="text-rose-500 font-bold">*</span>
                        </label>
                        <input type="text" id="aut_nombre_vigencia" name="nombre_vigencia" required
                               placeholder="Ej: Tarifario 2026 - Actualización Especial"
                               value="Tarifario Autorizado - <?php echo date('Y-m-d'); ?>"
                               class="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-white focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-all shadow-xs" />
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">
                            Identificador con el que se reconocerá este tarifario.
                        </p>
                    </div>
                </div>

                <!-- Pestañas / Selector de Modo -->
                <div class="pt-2">
                    <label class="block text-xs font-extrabold uppercase text-slate-700 dark:text-slate-300 mb-2">
                        Origen de los Nuevos Valores
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-2xl border border-slate-200 dark:border-slate-700/60">
                        <button type="button" id="tabModoArchivo" onclick="seleccionarModoVigencia('archivo')" 
                                class="py-2.5 px-3 rounded-xl text-xs font-black transition-all flex items-center justify-center gap-2 bg-white dark:bg-slate-900 text-amber-700 dark:text-amber-400 shadow-sm border border-amber-300/60 dark:border-amber-700/60 cursor-pointer">
                            <span class="material-symbols-outlined text-base">upload_file</span>
                            <span>Subir Archivo Excel / CSV (Recomendado)</span>
                        </button>
                        <button type="button" id="tabModoManual" onclick="seleccionarModoVigencia('manual')" 
                                class="py-2.5 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer">
                            <span class="material-symbols-outlined text-base">tune</span>
                            <span>Reajuste % o Clonar Actuales</span>
                        </button>
                    </div>
                </div>

                <!-- CONTENEDOR 1: MODO SUBIR ARCHIVO (DEFAULT) -->
                <div id="seccionModoArchivo" class="space-y-3">
                    
                    <!-- Enlace para descargar plantilla de ejemplo -->
                    <div class="flex items-center justify-between text-xs px-1">
                        <span class="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-amber-500 text-base">description</span>
                            Estructura estándar de tarifario
                        </span>
                        <a href="tarifario.php?action=descargar_plantilla_tarifario" 
                           class="inline-flex items-center gap-1 text-xs font-bold text-amber-600 dark:text-amber-400 hover:text-amber-700 dark:hover:text-amber-300 underline underline-offset-2 transition-colors">
                            <span class="material-symbols-outlined text-sm">download</span>
                            Descargar plantilla (.CSV)
                        </a>
                    </div>

                    <!-- Zona Drag & Drop -->
                    <div id="dropZoneTarifario" 
                         ondragover="manejarDragOver(event)" 
                         ondragleave="manejarDragLeave(event)" 
                         ondrop="manejarDropArchivo(event)"
                         onclick="document.getElementById('inputArchivoTarifario').click()"
                         class="border-2 border-dashed border-amber-300 dark:border-amber-700/80 hover:border-amber-500 dark:hover:border-amber-500 bg-amber-50/40 dark:bg-amber-950/20 hover:bg-amber-50/80 dark:hover:bg-amber-950/40 rounded-2xl p-6 text-center cursor-pointer transition-all duration-200 group">
                        
                        <input type="file" id="inputArchivoTarifario" accept=".xlsx,.xls,.csv" class="hidden" onchange="manejarSeleccionArchivo(this.files)" />
                        
                        <div class="flex flex-col items-center justify-center gap-2">
                            <div class="w-12 h-12 rounded-2xl bg-amber-100 dark:bg-amber-900/50 text-amber-600 dark:text-amber-400 flex items-center justify-center group-hover:scale-110 transition-transform">
                                <span class="material-symbols-outlined text-2xl">cloud_upload</span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-800 dark:text-slate-200">
                                    <span class="text-amber-600 dark:text-amber-400 underline">Haz clic para seleccionar</span> o arrastra tu archivo Excel aquí
                                </p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    Formatos compatibles: <strong>Excel (.xlsx, .xls)</strong> o <strong>CSV</strong>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center justify-center gap-1.5 text-[10px] text-slate-400 font-semibold mt-1">
                                <span class="px-2 py-0.5 rounded bg-slate-200/70 dark:bg-slate-800">CODIGO</span>
                                <span class="px-2 py-0.5 rounded bg-slate-200/70 dark:bg-slate-800">EXAMEN</span>
                                <span class="px-2 py-0.5 rounded bg-slate-200/70 dark:bg-slate-800">VALOR</span>
                                <span class="px-2 py-0.5 rounded bg-slate-200/70 dark:bg-slate-800">CONCEPTO</span>
                                <span class="px-2 py-0.5 rounded bg-slate-200/70 dark:bg-slate-800">SERVICIO</span>
                                <span class="px-2 py-0.5 rounded bg-slate-200/70 dark:bg-slate-800">TIPO</span>
                                <span class="px-2 py-0.5 rounded bg-slate-200/70 dark:bg-slate-800">CUENTA_CONTABLE</span>
                            </div>
                        </div>
                    </div>

                    <!-- Estado del Archivo Procesado y Explorador de Datos -->
                    <div id="infoArchivoProcesado" class="hidden p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 shadow-sm space-y-3">
                        
                        <!-- Barra de Resumen de Archivo -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-200 dark:border-slate-700/70">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-amber-500/15 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-500/20">
                                    <span class="material-symbols-outlined text-2xl">table_chart</span>
                                </div>
                                <div>
                                    <p class="text-xs font-black text-slate-900 dark:text-white flex items-center gap-2">
                                        <span id="nombreArchivoTxt">archivo.xlsx</span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300 border border-emerald-300/60 dark:border-emerald-700/60">
                                            Estructura Válida
                                        </span>
                                    </p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 font-medium" id="conteoFilasTxt">
                                        0 exámenes identificados y listos para activar
                                    </p>
                                </div>
                            </div>
                            <button type="button" onclick="limpiarArchivoTarifario()" 
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold text-rose-600 hover:text-white bg-rose-50 hover:bg-rose-600 dark:bg-rose-950/40 dark:hover:bg-rose-600 border border-rose-200 dark:border-rose-900/60 transition-all cursor-pointer shrink-0">
                                <span class="material-symbols-outlined text-sm">swap_horiz</span>
                                <span>Cambiar archivo</span>
                            </button>
                        </div>

                        <!-- Barra de Búsqueda Dinámica por CÓDIGO o Nombre -->
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5">
                            <div class="relative flex-grow">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-base pointer-events-none">search</span>
                                <input type="text" id="inputFiltroPreviewTarifario" 
                                       oninput="filtrarPreviewTarifario(this.value)" 
                                       placeholder="Filtrar dinámicamente por CÓDIGO o nombre del examen..." 
                                       class="w-full pl-9 pr-9 py-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-bold text-slate-900 dark:text-white placeholder:text-slate-400 placeholder:font-normal focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-all shadow-xs" />
                                <button type="button" onclick="limpiarFiltroPreview()" id="btnLimpiarFiltroPreview" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 cursor-pointer" title="Limpiar filtro">
                                    <span class="material-symbols-outlined text-base">close</span>
                                </button>
                            </div>
                            <div class="shrink-0 flex items-center justify-between sm:justify-end gap-2 px-1">
                                <span id="lblConteoFiltradas" class="text-[11px] font-black text-slate-700 dark:text-slate-300 bg-slate-200/80 dark:bg-slate-900 border border-slate-300/60 dark:border-slate-700/60 px-2.5 py-1 rounded-lg">
                                    Mostrando 0 de 0
                                </span>
                            </div>
                        </div>

                        <!-- Tabla Completa con Todos los Datos Cargados (Scrollable con Cabecera Fija) -->
                        <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700 max-h-72 sm:max-h-80 overflow-y-auto shadow-inner bg-white dark:bg-slate-900">
                            <table class="w-full text-[11px] text-left border-collapse">
                                <thead class="bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 font-extrabold text-[10px] uppercase tracking-wider sticky top-0 z-10 border-b border-slate-200 dark:border-slate-700">
                                    <tr>
                                        <th class="p-2.5 text-center w-10 text-slate-400">#</th>
                                        <th class="p-2.5 w-24">CÓDIGO</th>
                                        <th class="p-2.5">EXAMEN</th>
                                        <th class="p-2.5 w-32">SERVICIO / TIPO</th>
                                        <th class="p-2.5 text-center w-14">CANT</th>
                                        <th class="p-2.5 text-right w-28">NUEVO VALOR</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyPreviewTarifario" class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                                    <!-- Se inyectan TODOS los exámenes dinámicamente vía JS -->
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-between text-[11px] text-slate-400 px-1 pt-0.5">
                            <span class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-xs text-amber-500">info</span>
                                Puedes usar el buscador de arriba para comprobar cualquier código antes de autorizar.
                            </span>
                        </div>
                    </div>

                </div>

                <!-- CONTENEDOR 2: MODO MANUAL / REAJUSTE (ALTERNATIVO) -->
                <div id="seccionModoManual" class="hidden space-y-3">
                    <div class="space-y-2">
                        <label class="flex items-start gap-2.5 p-3 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-750 cursor-pointer transition-all has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/50 dark:has-[:checked]:bg-amber-950/40">
                            <input type="radio" name="modo_valores_manual" value="mantener" checked onchange="toggleReajusteInputManual()" class="mt-0.5 text-amber-600 focus:ring-amber-500" />
                            <div>
                                <p class="text-xs font-bold leading-tight text-slate-900 dark:text-white">Clonar tarifario actual para editarlo en pantalla</p>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">Duplica el catálogo actual bajo la nueva fecha de vigencia para que puedas modificar precios manualmente en la tabla.</p>
                            </div>
                        </label>

                        <label class="flex items-start gap-2.5 p-3 rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-750 cursor-pointer transition-all has-[:checked]:border-amber-500 has-[:checked]:bg-amber-50/50 dark:has-[:checked]:bg-amber-950/40">
                            <input type="radio" name="modo_valores_manual" value="porcentaje" onchange="toggleReajusteInputManual()" class="mt-0.5 text-amber-600 focus:ring-amber-500" />
                            <div>
                                <p class="text-xs font-bold leading-tight text-slate-900 dark:text-white">Aplicar Reajuste Porcentual General (%)</p>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">Calcula automáticamente el nuevo valor sumando o restando un porcentaje a todas las tarifas.</p>
                            </div>
                        </label>
                    </div>

                    <!-- Input Porcentaje de Reajuste (Oculto si modo = mantener) -->
                    <div id="boxPorcentajeReajusteManual" class="hidden">
                        <label for="aut_porcentaje_manual" class="block text-xs font-extrabold uppercase text-slate-700 dark:text-slate-300 mb-1 flex items-center justify-between">
                            <span>Porcentaje de Ajuste General (%)</span>
                            <span class="text-amber-600 font-bold">Ej: 5.5 o 10.0</span>
                        </label>
                        <div class="relative">
                            <input type="number" step="0.01" id="aut_porcentaje_manual" name="porcentaje_reajuste" value="0.00"
                                   placeholder="Ej: 5.0"
                                   class="w-full pl-3.5 pr-8 py-2.5 bg-white dark:bg-slate-900 border border-amber-300 dark:border-amber-700 rounded-xl text-xs font-black text-slate-900 dark:text-white focus:ring-2 focus:ring-amber-500 outline-none" />
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-xs">%</span>
                        </div>
                    </div>
                </div>

                <!-- Campo: Motivo / Justificación -->
                <div>
                    <label for="aut_motivo" class="block text-xs font-extrabold uppercase text-slate-700 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                        <span>Motivo o Justificación del Cambio</span>
                        <span class="text-rose-500 font-bold">*</span>
                    </label>
                    <textarea id="aut_motivo" name="motivo_cambio" required rows="2"
                              placeholder="Ej: Aprobación de nuevo listado de tarifas y valores por gerencia médica y financiera..."
                              class="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-xl text-xs font-medium text-slate-900 dark:text-white focus:ring-2 focus:ring-amber-500 focus:border-amber-500 outline-none transition-all resize-none shadow-xs"></textarea>
                </div>

                <!-- Modal Footer -->
                <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-end gap-3 shrink-0">
                    <button type="button" onclick="cerrarModalAutorizarVigencia()" class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 text-xs font-bold transition-all cursor-pointer">
                        Cancelar
                    </button>
                    <button type="button" onclick="enviarAutorizarVigencia(event)" id="btnSubmitAutorizarVigencia"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white font-extrabold text-xs shadow-md shadow-amber-600/30 transition-all hover:scale-105 active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-lg">check_circle</span>
                        <span id="btnSubmitAutorizarText">Autorizar y Guardar Tarifario</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <script>
        const ALL_TARIFAS = <?php echo json_encode($tarifas, JSON_UNESCAPED_UNICODE); ?>;
        const CAN_EDIT = <?php echo $canEditTarifa ? 'true' : 'false'; ?>;

        let allData = ALL_TARIFAS;
        let filteredData = [...allData];
        let filtroTipoActual = '';
        let filtroEstadoActual = '';
        let filtroConceptoActual = '';
        let searchKeyword = '';
        let paginaActual = 1;
        let registrosPorPagina = 25;
        let currentOriginalEstado = 1;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function esTextoComparativo(texto) {
            if (!texto) return false;
            if (/\bcomparativ[a-záéíóúñ\/]*/i.test(texto)) return true;
            const tieneProyeccion = /\b(a\s*\.?\s*p\.?|p\s*\.?\s*a\.?)\b/i.test(texto) || /a\.p/i.test(texto) || /p\.a/i.test(texto);
            const tieneLateral    = /\blateral(?:es)?\b/i.test(texto) || /\blat\b/i.test(texto);
            if (tieneProyeccion && tieneLateral) return true;
            return false;
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toastNotification');
            const toastMsg = document.getElementById('toastMessage');
            const toastIcon = document.getElementById('toastIcon');
            if (!toast || !toastMsg) return;

            toastMsg.textContent = message;
            if (type === 'error') {
                if (toastIcon) {
                    toastIcon.textContent = 'error';
                    toastIcon.className = 'material-symbols-outlined text-rose-400 text-xl';
                }
            } else {
                if (toastIcon) {
                    toastIcon.textContent = 'check_circle';
                    toastIcon.className = 'material-symbols-outlined text-emerald-400 text-xl';
                }
            }

            toast.classList.remove('hidden');
            setTimeout(() => {
                toast.classList.remove('translate-y-10', 'opacity-0');
                toast.classList.add('translate-y-0', 'opacity-100');
            }, 10);

            setTimeout(() => {
                toast.classList.remove('translate-y-0', 'opacity-100');
                toast.classList.add('translate-y-10', 'opacity-0');
                setTimeout(() => {
                    toast.classList.add('hidden');
                }, 300);
            }, 3500);
        }

        // Funciones de formato de moneda COP con puntuación de miles
        function formatearNumeroCOP(val) {
            if (val === null || val === undefined || val === '') return '0';
            const num = Math.round(Number(String(val).replace(/\./g, '').replace(/,/g, '.').replace(/[^\d.-]/g, '')) || 0);
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

        function limpiarNumeroCOP(val) {
            if (val === null || val === undefined || val === '') return 0;
            const num = parseFloat(String(val).replace(/\./g, '').replace(/,/g, '.').replace(/[^\d.-]/g, ''));
            return isNaN(num) ? 0 : num;
        }

        function formatearMilesInput(input) {
            if (!input) return;
            const cursorPos = input.selectionStart;
            const prevLength = input.value.length;
            
            const raw = input.value.replace(/\D/g, '');
            if (!raw) {
                input.value = '';
                return;
            }
            const formatted = parseInt(raw, 10).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            input.value = formatted;
            
            const diff = formatted.length - prevLength;
            const newPos = Math.max(0, cursorPos + diff);
            input.setSelectionRange(newPos, newPos);
        }

        function updateKpiCounters() {
            let total = allData.length;
            let activas = 0;
            let deshabilitadas = 0;
            let empresa = 0;
            let particular = 0;

            allData.forEach(t => {
                if (parseInt(t.estado) === 1) activas++;
                else deshabilitadas++;

                if (t.tipo_paciente === 'P') particular++;
                else empresa++;
            });

            const kpiTot = document.getElementById('kpiTotalCount');
            const kpiAct = document.getElementById('kpiActivasCount');
            const kpiDes = document.getElementById('kpiDeshabilitadasCount');
            const kpiEmp = document.getElementById('kpiEmpresaCount');
            const kpiPar = document.getElementById('kpiParticularCount');

            if (kpiTot) kpiTot.textContent = total.toLocaleString();
            if (kpiAct) kpiAct.textContent = activas.toLocaleString();
            if (kpiDes) kpiDes.textContent = deshabilitadas.toLocaleString();
            if (kpiEmp) kpiEmp.textContent = empresa.toLocaleString();
            if (kpiPar) kpiPar.textContent = particular.toLocaleString();

            const cTabAll = document.getElementById('cntTabAll');
            const cTabEmp = document.getElementById('cntTabEmpresa');
            const cTabPar = document.getElementById('cntTabParticular');

            if (cTabAll) cTabAll.textContent = total.toLocaleString();
            if (cTabEmp) cTabEmp.textContent = empresa.toLocaleString();
            if (cTabPar) cTabPar.textContent = particular.toLocaleString();

            const cardDes = document.getElementById('cardDeshabilitadas');
            const actionDes = document.getElementById('kpiDeshabilitadasAction');
            if (cardDes) {
                if (deshabilitadas > 0) {
                    cardDes.classList.remove('opacity-80');
                    cardDes.classList.add('cursor-pointer', 'hover:border-rose-300', 'dark:hover:border-rose-800', 'hover:shadow-md', 'hover:scale-[1.01]');
                    if (actionDes) actionDes.classList.remove('hidden');
                } else {
                    cardDes.classList.add('opacity-80');
                    cardDes.classList.remove('cursor-pointer', 'hover:border-rose-300', 'dark:hover:border-rose-800', 'hover:shadow-md', 'hover:scale-[1.01]');
                    if (actionDes) actionDes.classList.add('hidden');
                }
            }
        }

        // Control del Modal de Selección de Entidad (Idéntico a maestro_porcentajes.php)
        function abrirModalSeleccionarEntidad() {
            const modal = document.getElementById('modalSeleccionEntidad');
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }

        function cerrarModalSeleccionarEntidad() {
            const modal = document.getElementById('modalSeleccionEntidad');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }

        function confirmarSeleccionEntidad(entId, entNombre) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Cargando Catálogo...',
                    html: `<div class="text-xs text-slate-400">Abriendo tarifario de: <strong class="text-slate-800 dark:text-white block mt-1">${entNombre}</strong></div>`,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            }
            window.location.href = 'tarifario.php?entidad_id=' + encodeURIComponent(entId);
        }

        function filtrarTipoPaciente(tipo) {
            filtroTipoActual = tipo;

            const btnAll = document.getElementById('tabTipoAll');
            const btnEmp = document.getElementById('tabTipoEmpresa');
            const btnPar = document.getElementById('tabTipoParticular');

            const activeClass = "tab-tipo-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 <?php echo $paletaModal['tab_active']; ?> cursor-pointer";
            const inactiveClass = "tab-tipo-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all duration-200 flex items-center gap-1.5 text-slate-600 dark:text-slate-400 hover:bg-white dark:hover:bg-slate-800 cursor-pointer";

            if (btnAll) btnAll.className = (tipo === '') ? activeClass : inactiveClass;
            if (btnEmp) btnEmp.className = (tipo === 'E') ? activeClass : inactiveClass;
            if (btnPar) btnPar.className = (tipo === 'P') ? activeClass : inactiveClass;

            paginaActual = 1;
            aplicarFiltrosYPaginacion();
        }

        function cambiarRegistrosPorPagina(val) {
            if (val === 'all') {
                registrosPorPagina = 999999;
            } else {
                registrosPorPagina = parseInt(val) || 25;
            }
            paginaActual = 1;
            renderTablaTarifario();
        }

        function cambiarPagina(delta) {
            paginaActual += delta;
            renderTablaTarifario();
        }

        function aplicarFiltrosYPaginacion() {
            searchKeyword = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
            filtroEstadoActual = document.getElementById('filterEstado')?.value || '';
            filtroConceptoActual = document.getElementById('filterConcepto')?.value || '';

            filteredData = allData.filter(item => {
                if (filtroTipoActual !== '' && item.tipo_paciente !== filtroTipoActual) {
                    return false;
                }
                if (filtroEstadoActual !== '' && String(item.estado) !== String(filtroEstadoActual)) {
                    return false;
                }
                if (filtroConceptoActual !== '' && item.concepto !== filtroConceptoActual) {
                    return false;
                }
                if (searchKeyword !== '') {
                    const haystack = (
                        (item.codigo || '') + ' ' +
                        (item.examen || '') + ' ' +
                        (item.concepto || '') + ' ' +
                        (item.servicio || '') + ' ' +
                        (item.cuenta_contable || '') + ' ' +
                        (item.nombre_cuenta || '')
                    ).toLowerCase();
                    if (!haystack.includes(searchKeyword)) return false;
                }
                return true;
            });

            paginaActual = 1;
            renderTablaTarifario();
        }

        // Funciones de Modal Edición
        const modalEditar = document.getElementById('modalEditarTarifa');
        const modalEditarContent = document.getElementById('modalEditarContent');

        function openModal() {
            if (!modalEditar || !modalEditarContent) return;
            modalEditar.classList.remove('hidden', 'pointer-events-none');
            setTimeout(() => {
                modalEditar.classList.remove('opacity-0');
                modalEditar.classList.add('opacity-100');
                modalEditarContent.classList.remove('scale-95');
                modalEditarContent.classList.add('scale-100');
            }, 10);
        }

        function closeModal() {
            if (!modalEditar || !modalEditarContent) return;
            modalEditarContent.classList.remove('scale-100');
            modalEditarContent.classList.add('scale-95');
            modalEditar.classList.remove('opacity-100');
            modalEditar.classList.add('opacity-0');
            setTimeout(() => {
                modalEditar.classList.add('hidden', 'pointer-events-none');
            }, 200);
        }

        // Funciones de Modal Nueva Tarifa
        const modalNueva = document.getElementById('modalNuevaTarifa');
        const modalNuevaContent = document.getElementById('modalNuevaTarifaContent');
        const formNuevaTarifa = document.getElementById('formNuevaTarifa');

        function openNuevaTarifaModal() {
            if (!modalNueva || !modalNuevaContent) return;
            if (formNuevaTarifa) {
                formNuevaTarifa.reset();
                const entInput = document.getElementById('new_entidad_id');
                if (entInput) entInput.value = '<?php echo $entidadActivaIdInt; ?>';
            }
            modalNueva.classList.remove('hidden', 'pointer-events-none');
            setTimeout(() => {
                modalNueva.classList.remove('opacity-0');
                modalNueva.classList.add('opacity-100');
                modalNuevaContent.classList.remove('scale-95');
                modalNuevaContent.classList.add('scale-100');
                const firstInput = formNuevaTarifa ? formNuevaTarifa.querySelector('input[name="codigo"]') : null;
                if (firstInput) firstInput.focus();
            }, 50);
        }

        function closeNuevaTarifaModal() {
            if (!modalNueva || !modalNuevaContent) return;
            modalNuevaContent.classList.remove('scale-100');
            modalNuevaContent.classList.add('scale-95');
            modalNueva.classList.remove('opacity-100');
            modalNueva.classList.add('opacity-0');
            setTimeout(() => {
                modalNueva.classList.add('hidden', 'pointer-events-none');
            }, 200);
        }

        // Funciones de Modal Tarifas Deshabilitadas
        const modalDeshabilitadas = document.getElementById('modalTarifasDeshabilitadas');
        const modalDeshabilitadasContent = modalDeshabilitadas ? modalDeshabilitadas.querySelector('div') : null;
        const tbodyDeshabilitadas = document.getElementById('tbodyDeshabilitadas');

        function openModalDeshabilitadas() {
            if (!modalDeshabilitadas || !modalDeshabilitadasContent) return;
            renderDeshabilitadasTable();
            modalDeshabilitadas.classList.remove('hidden', 'pointer-events-none');
            setTimeout(() => {
                modalDeshabilitadas.classList.remove('opacity-0');
                modalDeshabilitadas.classList.add('opacity-100');
                modalDeshabilitadasContent.classList.remove('scale-95');
                modalDeshabilitadasContent.classList.add('scale-100');
            }, 10);
        }

        function closeModalDeshabilitadas() {
            if (!modalDeshabilitadas || !modalDeshabilitadasContent) return;
            modalDeshabilitadasContent.classList.remove('scale-100');
            modalDeshabilitadasContent.classList.add('scale-95');
            modalDeshabilitadas.classList.remove('opacity-100');
            modalDeshabilitadas.classList.add('opacity-0');
            setTimeout(() => {
                modalDeshabilitadas.classList.add('hidden', 'pointer-events-none');
            }, 200);
        }

        function abrirModalEdicionTarget(btn) {
            const id = btn.getAttribute('data-id');
            const codigo = btn.getAttribute('data-codigo');
            const examen = btn.getAttribute('data-examen');
            const valorund = btn.getAttribute('data-valorund');
            const columna1 = btn.getAttribute('data-columna1');
            const valortexto = btn.getAttribute('data-valortexto');
            const tipopaciente = btn.getAttribute('data-tipopaciente') || 'E';
            const estado = parseInt(btn.getAttribute('data-estado')) || 1;
            const pagarcantidad = btn.getAttribute('data-pagarcantidad') !== null ? btn.getAttribute('data-pagarcantidad') : '1';
            const basecalculo   = btn.getAttribute('data-basecalculo') || 'VALOR_LIQUIDACION';

            currentOriginalEstado = estado;

            document.getElementById('edit_tarifa_id').value = id;
            document.getElementById('edit_codigo_badge').textContent = codigo;
            const editExamenInput = document.getElementById('edit_examen_nombre');
            if (editExamenInput) {
                editExamenInput.value = examen || '';
            }
            document.getElementById('edit_valor_und').value = formatearNumeroCOP(valorund);
            document.getElementById('edit_columna1').value = formatearNumeroCOP(columna1);
            document.getElementById('edit_valor_texto').value = valortexto || ('$ ' + formatearNumeroCOP(valorund));
            document.getElementById('edit_motivo_cambio').value = '';

            const editTipoPac = document.getElementById('edit_tipo_paciente');
            if (editTipoPac) editTipoPac.value = tipopaciente;

            const editPagarCant = document.getElementById('edit_pagar_por_cantidad');
            if (editPagarCant) editPagarCant.value = pagarcantidad;

            const editBaseCalc = document.getElementById('edit_base_calculo');
            if (editBaseCalc) editBaseCalc.value = basecalculo;

            const radio1 = document.getElementById('radio_estado_1');
            const radio0 = document.getElementById('radio_estado_0');
            const modalEstadoPill = document.getElementById('modal_estado_pill');

            if (estado === 1) {
                if (radio1) radio1.checked = true;
                if (modalEstadoPill) modalEstadoPill.innerHTML = '<span class="px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 text-[10px] font-extrabold uppercase">Activa</span>';
            } else {
                if (radio0) radio0.checked = true;
                if (modalEstadoPill) modalEstadoPill.innerHTML = '<span class="px-2.5 py-0.5 rounded-full bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800 text-[10px] font-extrabold uppercase">Deshabilitada</span>';
            }

            openModal();
        }

        function renderTablaTarifario() {
            const tbody = document.getElementById('tarifarioTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            const total = filteredData.length;
            if (total === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="${CAN_EDIT ? '10' : '9'}" class="text-center py-12 text-slate-400 font-medium">
                            <span class="material-symbols-outlined text-4xl mb-2 text-slate-300 dark:text-slate-600 opacity-60">search_off</span>
                            <p class="font-bold text-sm text-slate-700 dark:text-slate-300">No se encontraron tarifas para los criterios seleccionados.</p>
                            <p class="text-xs text-slate-400">Prueba cambiando los filtros o seleccionando otra pestaña.</p>
                        </td>
                    </tr>
                `;
                document.getElementById('lblPagFrom').textContent = '0';
                document.getElementById('lblPagTo').textContent = '0';
                document.getElementById('lblPagTotal').textContent = '0';
                document.getElementById('lblPaginaActual').textContent = '1';
                document.getElementById('lblTotalPaginas').textContent = '1';
                document.getElementById('btnPagPrev').disabled = true;
                document.getElementById('btnPagNext').disabled = true;
                return;
            }

            const totalPaginas = Math.ceil(total / registrosPorPagina);
            if (paginaActual > totalPaginas) paginaActual = totalPaginas;
            if (paginaActual < 1) paginaActual = 1;

            const inicio = (paginaActual - 1) * registrosPorPagina;
            const fin = Math.min(inicio + registrosPorPagina, total);
            const paginaItems = filteredData.slice(inicio, fin);

            paginaItems.forEach(t => {
                const tr = document.createElement('tr');
                tr.className = `hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors tarifa-row ${parseInt(t.estado) === 0 ? 'bg-rose-50/30 dark:bg-rose-950/20' : ''}`;
                tr.id = `row-tarifa-${t.id}`;
                tr.setAttribute('data-servicio', t.servicio || '');
                tr.setAttribute('data-concepto', t.concepto || '');
                tr.setAttribute('data-estado', t.estado);

                const tipoBadge = (t.tipo_paciente === 'P') ?
                    `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 font-extrabold text-[10px] uppercase border border-purple-200/80 dark:border-purple-800/80 whitespace-nowrap shadow-xs">
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-500 shrink-0"></span>
                        <span>Particular (P)</span>
                    </span>` :
                    `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 border border-sky-200/80 dark:border-sky-800/80 text-[10px] font-extrabold uppercase whitespace-nowrap shadow-xs">
                        <span class="w-1.5 h-1.5 rounded-full bg-sky-500 shrink-0"></span>
                        <span>Empresa (E)</span>
                    </span>`;

                const estadoBadge = (parseInt(t.estado) === 1) ?
                    `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 text-[10px] font-extrabold uppercase whitespace-nowrap"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Activa</span>` :
                    `<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800 text-[10px] font-extrabold uppercase whitespace-nowrap"><span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Deshabilitada</span>`;

                const valFormatted = '$' + (parseInt(t.columna1 || t.valor_und) || 0).toLocaleString('es-CO');

                let accionesTd = '';
                if (CAN_EDIT) {
                    accionesTd = `
                        <td class="py-3.5 px-4 text-center whitespace-nowrap">
                            <button type="button" 
                                class="btn-editar-tarifa inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-tertiary hover:text-white dark:hover:bg-tertiary dark:hover:text-white text-slate-700 dark:text-slate-200 text-xs font-bold transition-all duration-200 shadow-xs active:scale-95 cursor-pointer"
                                data-id="${t.id}"
                                data-codigo="${escapeHtml(t.codigo)}"
                                data-examen="${escapeHtml(t.examen)}"
                                data-valorund="${t.valor_und}"
                                data-columna1="${t.columna1 || t.valor_und}"
                                data-valortexto="${escapeHtml(t.valor_texto || '')}"
                                data-tipopaciente="${t.tipo_paciente || 'E'}"
                                data-pagarcantidad="${t.pagar_por_cantidad !== undefined ? t.pagar_por_cantidad : 1}"
                                data-basecalculo="${escapeHtml(t.base_calculo || 'VALOR_LIQUIDACION')}"
                                data-estado="${t.estado}">
                                <span class="material-symbols-outlined text-base">edit</span>
                                <span>Editar</span>
                            </button>
                        </td>
                    `;
                }

                tr.innerHTML = `
                    <td class="py-3.5 px-4 font-black text-primary dark:text-tertiary whitespace-nowrap">
                        <span class="px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-primary dark:text-tertiary border border-slate-200 dark:border-slate-700 font-mono text-xs shadow-xs">
                            ${escapeHtml(t.codigo)}
                        </span>
                    </td>
                    <td class="py-3.5 px-4 text-slate-800 dark:text-slate-200 font-bold min-w-[260px]">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span>${escapeHtml(t.examen || '-')}</span>
                            ${t.examen && esTextoComparativo(t.examen) ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/80 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700 shadow-xs">
                                    <span class="material-symbols-outlined text-[10px]">compare_arrows</span> COMPARATIVO
                                </span>
                            ` : ''}
                        </div>
                    </td>
                    <td class="py-3.5 px-4 whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded-md <?php echo $paletaModal['pill']; ?> font-bold text-[10px] uppercase shadow-xs">
                            ${escapeHtml(t.concepto || '-')}
                        </span>
                    </td>
                    <td class="py-3.5 px-4 text-slate-600 dark:text-slate-300 font-semibold whitespace-nowrap">
                        ${escapeHtml(t.servicio || '-')}
                    </td>
                    <td class="py-3.5 px-4 text-center whitespace-nowrap">
                        ${tipoBadge}
                    </td>
                    <td class="py-3.5 px-4 estado-cell whitespace-nowrap">
                        ${estadoBadge}
                    </td>
                    <td class="py-3.5 px-4 text-right valor-und-cell whitespace-nowrap">
                        <div class="font-black text-emerald-700 dark:text-emerald-400 font-outfit text-sm">${valFormatted}</div>
                        <div class="flex items-center justify-end gap-1 mt-0.5 flex-wrap">
                            ${t.base_calculo === 'VALOR_EXAMEN' ? '<span class="inline-block px-1.5 py-0.2 rounded text-[9px] font-black uppercase bg-cyan-100 text-cyan-800 dark:bg-cyan-950/80 dark:text-cyan-300 border border-cyan-300 dark:border-cyan-700" title="Liquidación calculada sobre el valor facturado del examen en Servinte">Base Examen</span>' : ''}
                            ${parseInt(t.pagar_por_cantidad) === 0 ? '<span class="inline-block px-1.5 py-0.2 rounded text-[9px] font-black uppercase bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border border-amber-300 dark:border-amber-700" title="No multiplica por la cantidad de Servinte (tarifa fija por estudio)">Fijo (Sin Cant)</span>' : ''}
                        </div>
                    </td>
                    <td class="py-3.5 px-4 font-mono text-[11px] text-slate-600 dark:text-slate-400 whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-800/80 border border-slate-200/80 dark:border-slate-700/80">${escapeHtml(t.cuenta_contable || '-')}</span>
                    </td>
                    <td class="py-3.5 px-4 text-slate-600 dark:text-slate-300 text-[11px] font-medium min-w-[160px]">
                        ${escapeHtml(t.nombre_cuenta || '-')}
                    </td>
                    ${accionesTd}
                `;

                tbody.appendChild(tr);
            });

            tbody.querySelectorAll('.btn-editar-tarifa').forEach(btn => {
                btn.addEventListener('click', function() {
                    abrirModalEdicionTarget(this);
                });
            });

            document.getElementById('lblPagFrom').textContent = (inicio + 1).toLocaleString();
            document.getElementById('lblPagTo').textContent = fin.toLocaleString();
            document.getElementById('lblPagTotal').textContent = total.toLocaleString();
            document.getElementById('lblPaginaActual').textContent = paginaActual;
            document.getElementById('lblTotalPaginas').textContent = totalPaginas;
            document.getElementById('btnPagPrev').disabled = (paginaActual <= 1);
            document.getElementById('btnPagNext').disabled = (paginaActual >= totalPaginas);
        }

        function renderDeshabilitadasTable() {
            if (!tbodyDeshabilitadas) return;
            tbodyDeshabilitadas.innerHTML = '';

            const deshabilitadas = allData.filter(t => parseInt(t.estado) === 0);
            const cntLbl = document.getElementById('modalDeshabilitadasCount');
            if (cntLbl) cntLbl.textContent = deshabilitadas.length;

            if (deshabilitadas.length === 0) {
                tbodyDeshabilitadas.innerHTML = `
                    <tr>
                        <td colspan="${CAN_EDIT ? '4' : '3'}" class="py-8 text-center text-slate-400 font-medium">
                            No hay tarifas deshabilitadas registradas actualmente.
                        </td>
                    </tr>
                `;
                return;
            }

            deshabilitadas.forEach(item => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-colors';

                const valorFormatted = '$' + (parseInt(item.columna1 || item.valor_und) || 0).toLocaleString('es-CO');
                let accionesHtml = '';

                if (CAN_EDIT) {
                    accionesHtml = `
                        <td class="py-3 px-4 text-center">
                            <button type="button" 
                                class="btn-reactivar-tarifa inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-600 hover:text-white text-emerald-700 dark:text-emerald-400 text-xs font-bold transition-all border border-emerald-200 dark:border-emerald-800 cursor-pointer"
                                data-id="${item.id}"
                                data-codigo="${escapeHtml(item.codigo)}"
                                data-examen="${escapeHtml(item.examen)}"
                                data-valorund="${item.valor_und}"
                                data-columna1="${item.columna1 || item.valor_und}"
                                data-valortexto="${escapeHtml(item.valor_texto || '')}"
                                data-tipopaciente="${item.tipo_paciente || 'E'}">
                                <span class="material-symbols-outlined text-sm">check_circle</span>
                                <span>Reactivar</span>
                            </button>
                        </td>`;
                }

                tr.innerHTML = `
                    <td class="py-3 px-4 font-black text-primary dark:text-tertiary font-mono">${escapeHtml(item.codigo)}</td>
                    <td class="py-3 px-4 font-bold text-slate-800 dark:text-slate-200 max-w-xs">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span>${escapeHtml(item.examen || '-')}</span>
                            ${item.examen && esTextoComparativo(item.examen) ? `
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/80 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700 shadow-xs">
                                    <span class="material-symbols-outlined text-[10px]">compare_arrows</span> COMPARATIVO
                                </span>
                            ` : ''}
                        </div>
                    </td>
                    <td class="py-3 px-4 text-right font-black text-rose-600 dark:text-rose-400">${valorFormatted}</td>
                    ${accionesHtml}
                `;
                tbodyDeshabilitadas.appendChild(tr);
            });

            tbodyDeshabilitadas.querySelectorAll('.btn-reactivar-tarifa').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const motivo = prompt(`Ingrese motivo para reactivar tarifa:`, 'Reactivación autorizada');

                    if (motivo !== null && motivo.trim() !== '') {
                        const formData = new FormData();
                        formData.append('action', 'guardar_tarifa');
                        formData.append('id', id);
                        formData.append('examen', this.getAttribute('data-examen') || '');
                        formData.append('valor_und', this.getAttribute('data-valorund'));
                        formData.append('columna1', this.getAttribute('data-columna1'));
                        formData.append('valor_texto', this.getAttribute('data-valortexto'));
                        formData.append('tipo_paciente', this.getAttribute('data-tipopaciente'));
                        formData.append('estado', '1');
                        formData.append('motivo_cambio', motivo.trim());

                        fetch('tarifario.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                const match = allData.find(t => parseInt(t.id) === parseInt(id));
                                if (match) {
                                    match.estado = 1;
                                }
                                updateKpiCounters();
                                renderDeshabilitadasTable();
                                aplicarFiltrosYPaginacion();
                                showToast(data.message, 'success');
                            } else {
                                showToast(data.message || 'Error al reactivar tarifa', 'error');
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            showToast('Error de comunicación con el servidor', 'error');
                        });
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            renderTablaTarifario();
            updateKpiCounters();

            // Botón Abrir Modal Nueva Tarifa
            const btnAbrirNuevaTarifa = document.getElementById('btnAbrirNuevaTarifa');
            if (btnAbrirNuevaTarifa) {
                btnAbrirNuevaTarifa.addEventListener('click', openNuevaTarifaModal);
            }

            // Botones Cerrar Modal Nueva Tarifa (únicamente cierran con X o Cancelar)
            const closeModalNuevaTarifaBtn = document.getElementById('closeModalNuevaTarifaBtn');
            const cancelNuevaTarifaBtn = document.getElementById('cancelNuevaTarifaBtn');
            if (closeModalNuevaTarifaBtn) closeModalNuevaTarifaBtn.addEventListener('click', closeNuevaTarifaModal);
            if (cancelNuevaTarifaBtn) cancelNuevaTarifaBtn.addEventListener('click', closeNuevaTarifaModal);

            // Botones Cerrar Modal Editar Tarifa (únicamente cierran con X o Cancelar)
            document.querySelectorAll('.closeModalBtn').forEach(btn => {
                btn.addEventListener('click', closeModal);
            });

            // Botón/Card Tarifas Deshabilitadas (únicamente cierran con X o botón de cerrar)
            const cardDeshabilitadas = document.getElementById('cardDeshabilitadas');
            const closeModalDeshabilitadasBtn = document.getElementById('closeModalDeshabilitadasBtn');
            const closeModalDeshabilitadasFooterBtn = document.getElementById('closeModalDeshabilitadasFooterBtn');

            if (cardDeshabilitadas) {
                cardDeshabilitadas.addEventListener('click', function() {
                    const deshabilitadas = allData.filter(t => parseInt(t.estado) === 0);
                    if (deshabilitadas.length > 0) {
                        openModalDeshabilitadas();
                    }
                });
            }
            if (closeModalDeshabilitadasBtn) closeModalDeshabilitadasBtn.addEventListener('click', closeModalDeshabilitadas);
            if (closeModalDeshabilitadasFooterBtn) closeModalDeshabilitadasFooterBtn.addEventListener('click', closeModalDeshabilitadas);

            // Sincronización automática de valores en Modal Nueva Tarifa
            const newValorUndInput = document.getElementById('new_valor_und');
            const newColumna1Input = document.getElementById('new_columna1');
            if (newValorUndInput && newColumna1Input) {
                newValorUndInput.addEventListener('input', function() {
                    if (!newColumna1Input.value || newColumna1Input.value === this.dataset.lastVal) {
                        newColumna1Input.value = this.value;
                    }
                    this.dataset.lastVal = this.value;
                });
            }

            // Sincronización automática de valores en Modal Editar Tarifa
            const editValorUndInput = document.getElementById('edit_valor_und');
            const editColumna1Input = document.getElementById('edit_columna1');
            const editValorTextoInput = document.getElementById('edit_valor_texto');
            if (editValorUndInput) {
                editValorUndInput.addEventListener('input', function() {
                    const val = limpiarNumeroCOP(this.value);
                    if (editColumna1Input) editColumna1Input.value = formatearNumeroCOP(val);
                    if (editValorTextoInput) editValorTextoInput.value = '$ ' + formatearNumeroCOP(val);
                });
            }

            // Radios de Estado en Modal Edición
            const radioEstado1 = document.getElementById('radio_estado_1');
            const radioEstado0 = document.getElementById('radio_estado_0');
            const modalEstadoPill = document.getElementById('modal_estado_pill');

            if (radioEstado1) {
                radioEstado1.addEventListener('change', function() {
                    if (this.checked && modalEstadoPill) {
                        modalEstadoPill.innerHTML = '<span class="px-2.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 text-[10px] font-extrabold uppercase">Activa</span>';
                    }
                });
            }
            if (radioEstado0) {
                radioEstado0.addEventListener('change', function() {
                    if (this.checked && modalEstadoPill) {
                        modalEstadoPill.innerHTML = '<span class="px-2.5 py-0.5 rounded-full bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400 border border-rose-200 dark:border-rose-800 text-[10px] font-extrabold uppercase">Deshabilitada</span>';
                    }
                });
            }

            // Form Submit: NUEVA TARIFA
            if (formNuevaTarifa) {
                formNuevaTarifa.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const saveBtn = document.getElementById('saveNuevaTarifaBtn');
                    const saveIcon = document.getElementById('saveNuevaIcon');
                    const saveText = document.getElementById('saveNuevaText');

                    if (saveBtn) saveBtn.disabled = true;
                    if (saveIcon) saveIcon.textContent = 'hourglass_top';
                    if (saveText) saveText.textContent = 'Guardando...';

                    const formData = new FormData(formNuevaTarifa);

                    fetch('tarifario.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.data) {
                            allData.unshift(data.data);
                            updateKpiCounters();
                            aplicarFiltrosYPaginacion();
                            closeNuevaTarifaModal();
                            showToast(data.message, 'success');
                        } else {
                            showToast(data.message || 'Error al registrar la tarifa.', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        showToast('Error de conexión con el servidor al guardar la tarifa.', 'error');
                    })
                    .finally(() => {
                        if (saveBtn) saveBtn.disabled = false;
                        if (saveIcon) saveIcon.textContent = 'add_circle';
                        if (saveText) saveText.textContent = 'Crear Tarifa';
                    });
                });
            }

            // Form Submit: EDITAR TARIFA
            const formEditarTarifa = document.getElementById('formEditarTarifa');
            if (formEditarTarifa) {
                formEditarTarifa.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const saveBtn = document.getElementById('saveTarifaBtn');
                    const saveIcon = document.getElementById('saveIcon');
                    const saveText = document.getElementById('saveText');

                    if (saveBtn) saveBtn.disabled = true;
                    if (saveIcon) saveIcon.textContent = 'hourglass_top';
                    if (saveText) saveText.textContent = 'Guardando...';

                    const formData = new FormData(formEditarTarifa);

                    fetch('tarifario.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            const updated = data.data;
                            const match = allData.find(t => parseInt(t.id) === parseInt(updated.id));
                            if (match) {
                                match.examen = updated.examen;
                                match.valor_und = updated.valor_und;
                                match.columna1 = updated.columna1;
                                match.valor_texto = updated.valor_texto;
                                match.tipo_paciente = updated.tipo_paciente;
                                match.estado = updated.estado;
                                match.pagar_por_cantidad = updated.pagar_por_cantidad;
                                match.base_calculo = updated.base_calculo;
                            }
                            updateKpiCounters();
                            aplicarFiltrosYPaginacion();
                            closeModal();
                            showToast(data.message, 'success');
                        } else {
                            showToast(data.message || 'Error al actualizar la tarifa.', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        showToast('Error de conexión al actualizar la tarifa.', 'error');
                    })
                    .finally(() => {
                        if (saveBtn) saveBtn.disabled = false;
                        if (saveIcon) saveIcon.textContent = 'save';
                        if (saveText) saveText.textContent = 'Guardar Cambios';
                    });
                });
            }
        });

        // -------------------------------------------------------------
        // GESTIÓN DEL BANNER DE CATÁLOGO VACÍO
        // -------------------------------------------------------------
        function cerrarBannerCatalogoVacio() {
            const banner = document.getElementById('bannerCatalogoVacio');
            if (banner) {
                banner.style.transition = 'all 0.3s ease';
                banner.style.opacity = '0';
                banner.style.transform = 'translateY(-10px)';
                setTimeout(() => { banner.style.display = 'none'; }, 300);
            }
        }

        // -------------------------------------------------------------
        // MODAL DE IMPORTACIÓN / COPIA SELECTIVA DE EXÁMENES
        // -------------------------------------------------------------
        let importTargetEntidadId = 0;
        let importTargetNombre = '';
        let importExamenesBase = [];
        let importFilteredExamenes = [];
        let importSelectedIds = new Set();
        let importFiltroTipo = '';
        let importSearchText = '';

        async function abrirModalCopiarTarifario(entidadId, nombreEntidad) {
            importTargetEntidadId = entidadId;
            importTargetNombre = nombreEntidad;

            const lblNombre = document.getElementById('modalImportEntidadNombre');
            if (lblNombre) lblNombre.textContent = nombreEntidad;

            const modal = document.getElementById('modalImportarTarifario');
            const content = document.getElementById('modalImportarContent');
            if (!modal || !content) return;

            modal.classList.remove('hidden', 'opacity-0', 'pointer-events-none');
            modal.classList.add('opacity-100');
            content.classList.remove('scale-95');
            content.classList.add('scale-100');

            // Resetear filtros y búsqueda
            importSearchText = '';
            importFiltroTipo = '';
            const searchInput = document.getElementById('importSearchInput');
            if (searchInput) searchInput.value = '';
            const btnLimpiar = document.getElementById('btnLimpiarBusquedaImport');
            if (btnLimpiar) btnLimpiar.classList.add('hidden');

            actualizarPestañasTipoImportar();

            // Estado de carga inicial
            const tbody = document.getElementById('importTbodyExamenes');
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="py-14 text-center text-slate-400">
                            <span class="material-symbols-outlined animate-spin text-3xl text-purple-500 mb-2">sync</span>
                            <p class="text-xs font-bold text-slate-600 dark:text-slate-300">Cargando catálogo base de Hernán Ocazionez...</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Consultando tarifas vigentes e identificando registros existentes</p>
                        </td>
                    </tr>
                `;
            }

            try {
                const res = await fetch(`tarifario.php?action=obtener_examenes_base&entidad_id=${entidadId}`);
                const data = await res.json();

                if (!data.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error al cargar catálogo',
                        text: data.message || 'No se pudieron obtener las tarifas base.'
                    });
                    cerrarModalImportar();
                    return;
                }

                importExamenesBase = data.examenes || [];
                importSelectedIds.clear();

                // Por defecto, pre-seleccionar todos los que NO estén ya importados
                importExamenesBase.forEach(item => {
                    if (parseInt(item.ya_importado) === 0) {
                        importSelectedIds.add(item.id);
                    }
                });

                aplicarFiltrosImportar();

            } catch (err) {
                console.error(err);
                if (tbody) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="7" class="py-10 text-center text-rose-500">
                                <span class="material-symbols-outlined text-3xl mb-1">error</span>
                                <p class="text-xs font-bold">Error al comunicar con el servidor.</p>
                            </td>
                        </tr>
                    `;
                }
            }
        }

        function cerrarModalImportar() {
            const modal = document.getElementById('modalImportarTarifario');
            const content = document.getElementById('modalImportarContent');
            if (!modal || !content) return;

            content.classList.remove('scale-100');
            content.classList.add('scale-95');
            modal.classList.remove('opacity-100');
            modal.classList.add('opacity-0', 'pointer-events-none');
            setTimeout(() => { modal.classList.add('hidden'); }, 250);
        }

        function onInputBusquedaImportar(val) {
            importSearchText = (val || '').toLowerCase().trim();
            const btnLimpiar = document.getElementById('btnLimpiarBusquedaImport');
            if (btnLimpiar) {
                if (importSearchText.length > 0) btnLimpiar.classList.remove('hidden');
                else btnLimpiar.classList.add('hidden');
            }
            aplicarFiltrosImportar();
        }

        function limpiarBusquedaImportar() {
            const searchInput = document.getElementById('importSearchInput');
            if (searchInput) searchInput.value = '';
            onInputBusquedaImportar('');
        }

        function filtrarTipoImportar(tipo) {
            importFiltroTipo = tipo;
            actualizarPestañasTipoImportar();
            aplicarFiltrosImportar();
        }

        function actualizarPestañasTipoImportar() {
            const btnAll = document.getElementById('importTabTipoAll');
            const btnEmp = document.getElementById('importTabTipoEmp');
            const btnPar = document.getElementById('importTabTipoPar');

            const activeCls = "px-3 py-1 rounded-lg bg-white dark:bg-purple-600 text-purple-700 dark:text-white shadow-xs cursor-pointer transition-all font-black";
            const inactiveCls = "px-3 py-1 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer transition-all font-semibold";

            if (btnAll) btnAll.className = (importFiltroTipo === '') ? activeCls : inactiveCls;
            if (btnEmp) btnEmp.className = (importFiltroTipo === 'E') ? activeCls : inactiveCls;
            if (btnPar) btnPar.className = (importFiltroTipo === 'P') ? activeCls : inactiveCls;
        }

        function aplicarFiltrosImportar() {
            importFilteredExamenes = importExamenesBase.filter(item => {
                // Filtro por tipo E/P
                if (importFiltroTipo !== '' && item.tipo_paciente !== importFiltroTipo) {
                    return false;
                }
                // Filtro por buscador
                if (importSearchText !== '') {
                    const str = `${item.codigo || ''} ${item.examen || ''} ${item.concepto || ''} ${item.servicio || ''} ${item.cuenta_contable || ''}`.toLowerCase();
                    if (!str.includes(importSearchText)) return false;
                }
                return true;
            });

            // Actualizar conteos de píldoras
            let cntAll = 0, cntEmp = 0, cntPar = 0;
            importExamenesBase.forEach(it => {
                cntAll++;
                if (it.tipo_paciente === 'E') cntEmp++;
                else if (it.tipo_paciente === 'P') cntPar++;
            });

            const pillAll = document.getElementById('importPillAllCount');
            const pillEmp = document.getElementById('importPillEmpCount');
            const pillPar = document.getElementById('importPillParCount');
            if (pillAll) pillAll.textContent = cntAll;
            if (pillEmp) pillEmp.textContent = cntEmp;
            if (pillPar) pillPar.textContent = cntPar;

            renderTablaImportar();
            actualizarContadoresSeleccion();
        }

        function renderTablaImportar() {
            const tbody = document.getElementById('importTbodyExamenes');
            if (!tbody) return;

            if (importFilteredExamenes.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="py-12 text-center text-slate-400">
                            <span class="material-symbols-outlined text-3xl mb-1 text-slate-300">search_off</span>
                            <p class="text-xs font-bold text-slate-500">No se encontraron exámenes con los filtros aplicados.</p>
                        </td>
                    </tr>
                `;
                actualizarCheckMaster();
                return;
            }

            let html = '';
            importFilteredExamenes.forEach(item => {
                const isChecked = importSelectedIds.has(item.id);
                const yaImportado = parseInt(item.ya_importado) === 1;
                const isEmpresa = (item.tipo_paciente === 'E');

                const rowBg = isChecked 
                    ? 'bg-purple-50/90 dark:bg-purple-950/50 border-l-4 border-l-purple-600 dark:border-l-purple-400' 
                    : (yaImportado ? 'opacity-60 bg-slate-50/50 dark:bg-slate-900/30' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40');

                html += `
                    <tr class="transition-colors cursor-pointer border-b border-slate-100 dark:border-slate-800/80 ${rowBg}" onclick="toggleSelectImportItem(${item.id}, event)">
                        <td class="py-2.5 px-3.5 text-center w-12" onclick="event.stopPropagation()">
                            <input type="checkbox" ${isChecked ? 'checked' : ''} onchange="toggleSelectImportItem(${item.id})" class="w-4 h-4 rounded text-purple-600 focus:ring-purple-500 cursor-pointer" />
                        </td>
                        <td class="py-2.5 px-3 font-mono font-black text-slate-900 dark:text-purple-300 whitespace-nowrap">
                            ${escapeHtml(item.codigo)}
                        </td>
                        <td class="py-2.5 px-4 font-semibold text-slate-800 dark:text-slate-100">
                            ${escapeHtml(item.examen)}
                        </td>
                        <td class="py-2.5 px-3 text-slate-500 dark:text-slate-400 text-[11px]">
                            <span class="font-bold text-slate-700 dark:text-slate-300">${escapeHtml(item.concepto || '-')}</span>
                            ${item.servicio ? `<span class="block text-[10px] text-slate-400 dark:text-slate-500">${escapeHtml(item.servicio)}</span>` : ''}
                        </td>
                        <td class="py-2.5 px-3 text-center whitespace-nowrap">
                            <span class="text-[10px] font-black uppercase px-2 py-0.5 rounded-md ${isEmpresa ? 'bg-blue-100 dark:bg-blue-950/60 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-700/50' : 'bg-purple-100 dark:bg-purple-950/60 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-700/50'}">
                                ${isEmpresa ? 'Empresa (E)' : 'Particular (P)'}
                            </span>
                        </td>
                        <td class="py-2.5 px-4 text-right font-black font-outfit text-slate-900 dark:text-white whitespace-nowrap">
                            $ ${formatearNumeroCOP(item.valor_und)}
                        </td>
                        <td class="py-2.5 px-3 text-center whitespace-nowrap">
                            ${yaImportado 
                                ? '<span class="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800/50">Ya en catálogo</span>'
                                : '<span class="text-[10px] font-extrabold uppercase px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/50">Disponible</span>'
                            }
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
            actualizarCheckMaster();
        }

        function toggleSelectImportItem(id, event) {
            if (event && (event.target.tagName === 'INPUT' || event.target.tagName === 'LABEL')) {
                return;
            }
            if (importSelectedIds.has(id)) {
                importSelectedIds.delete(id);
            } else {
                importSelectedIds.add(id);
            }
            renderTablaImportar();
            actualizarContadoresSeleccion();
        }

        function toggleSelectAllVisible(checked) {
            importFilteredExamenes.forEach(item => {
                if (checked) {
                    importSelectedIds.add(item.id);
                } else {
                    importSelectedIds.delete(item.id);
                }
            });
            renderTablaImportar();
            actualizarContadoresSeleccion();
        }

        function seleccionarTodoElCatalogo() {
            // Selecciona todos los disponibles
            importExamenesBase.forEach(item => {
                importSelectedIds.add(item.id);
            });
            renderTablaImportar();
            actualizarContadoresSeleccion();
        }

        function deseleccionarTodo() {
            importSelectedIds.clear();
            renderTablaImportar();
            actualizarContadoresSeleccion();
        }

        function actualizarCheckMaster() {
            const master = document.getElementById('importCheckMaster');
            if (!master) return;
            if (importFilteredExamenes.length === 0) {
                master.checked = false;
                master.indeterminate = false;
                return;
            }
            const countVisibleChecked = importFilteredExamenes.filter(item => importSelectedIds.has(item.id)).length;
            if (countVisibleChecked === 0) {
                master.checked = false;
                master.indeterminate = false;
            } else if (countVisibleChecked === importFilteredExamenes.length) {
                master.checked = true;
                master.indeterminate = false;
            } else {
                master.checked = false;
                master.indeterminate = true;
            }
        }

        function actualizarContadoresSeleccion() {
            const selCount = importSelectedIds.size;
            const totCount = importExamenesBase.length;

            const lblSel = document.getElementById('lblImportSeleccionados');
            const lblTot = document.getElementById('lblImportTotalBase');
            const btnCount = document.getElementById('btnImportarCount');
            const btnEjecutar = document.getElementById('btnEjecutarImportar');

            if (lblSel) lblSel.textContent = selCount.toLocaleString();
            if (lblTot) lblTot.textContent = totCount.toLocaleString();
            if (btnCount) btnCount.textContent = selCount.toLocaleString();

            if (btnEjecutar) {
                btnEjecutar.disabled = (selCount === 0);
            }
        }

        // Ejecutar importación selectiva con confirmación y SweetAlert2
        async function ejecutarImportacion() {
            if (importSelectedIds.size === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Sin selección',
                    text: 'Debe seleccionar al menos un examen para importar.'
                });
                return;
            }

            const confirm = await Swal.fire({
                title: '¿Importar Exámenes Seleccionados?',
                html: `<div class="text-left text-xs space-y-2 text-slate-300">
                        <p>Se importarán <strong>${importSelectedIds.size}</strong> tarifas seleccionadas de Hernán Ocazionez hacia el catálogo de <strong>${escapeHtml(importTargetNombre)}</strong>.</p>
                        <p class="text-slate-400">Esta acción creará las tarifas activas en su catálogo y registrará los detalles en el módulo de auditoría y logs del sistema.</p>
                       </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: `Sí, importar ${importSelectedIds.size} exámenes`,
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#9333ea'
            });

            if (!confirm.isConfirmed) return;

            try {
                Swal.fire({
                    title: 'Importando exámenes...',
                    text: 'Registrando tarifas y generando bitácora de auditoría...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const formData = new FormData();
                formData.append('action', 'importar_examenes_seleccionados');
                formData.append('entidad_id', importTargetEntidadId);
                formData.append('ids', JSON.stringify(Array.from(importSelectedIds)));

                const res = await fetch('tarifario.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: '¡Importación Completada!',
                        text: data.message,
                        confirmButtonText: 'Ver Catálogo'
                    });
                    window.location.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error al importar',
                        text: data.message || 'No se pudo completar la importación.'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error de Comunicación',
                    text: 'Ocurrió un error al procesar la solicitud con el servidor.'
                });
            }
        }

        // Función legacy de clonación rápida como respaldo
        async function clonarTarifarioBase(entidadId, nombreEntidad) {
            abrirModalCopiarTarifario(entidadId, nombreEntidad);
        }

        // =========================================================================
        // CONTROL Y GESTIÓN DE VIGENCIAS DINÁMICAS Y CARGA DE TARIFARIOS
        // =========================================================================
        let tarifasParsedFromExcel = [];
        let filtroPreviewTexto = '';
        let modoVigenciaSeleccionado = 'archivo'; // 'archivo' (default) o 'manual'

        function abrirModalAutorizarVigencia() {
            const modal = document.getElementById('modalAutorizarVigencia');
            const content = document.getElementById('modalAutorizarContent');
            if (!modal) {
                console.error("Modal modalAutorizarVigencia no encontrado en el DOM");
                return;
            }

            // Inicializar fecha de vigencia con la fecha de hoy si no tiene valor
            const inputFecha = document.getElementById('aut_fecha_vigencia');
            if (inputFecha && !inputFecha.value) {
                const hoy = new Date().toISOString().split('T')[0];
                inputFecha.value = hoy;
                actualizarNombreVigenciaAutomatico(hoy);
            }

            // Seleccionar modo archivo por defecto
            seleccionarModoVigencia('archivo');

            modal.style.display = 'flex';
            modal.classList.remove('hidden', 'pointer-events-none');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modal.classList.add('opacity-100');
                if (content) {
                    content.classList.remove('scale-95');
                    content.classList.add('scale-100');
                }
            }, 20);
        }

        function cerrarModalAutorizarVigencia() {
            const modal = document.getElementById('modalAutorizarVigencia');
            const content = document.getElementById('modalAutorizarContent');
            if (!modal) return;
            if (content) {
                content.classList.remove('scale-100');
                content.classList.add('scale-95');
            }
            modal.classList.remove('opacity-100');
            modal.classList.add('opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden', 'pointer-events-none');
                modal.style.display = 'none';
            }, 200);
        }

        function seleccionarModoVigencia(modo) {
            modoVigenciaSeleccionado = modo;
            const tabArchivo = document.getElementById('tabModoArchivo');
            const tabManual = document.getElementById('tabModoManual');
            const secArchivo = document.getElementById('seccionModoArchivo');
            const secManual = document.getElementById('seccionModoManual');
            const btnText = document.getElementById('btnSubmitAutorizarText');

            if (modo === 'archivo') {
                if (tabArchivo) tabArchivo.className = "py-2.5 px-3 rounded-xl text-xs font-black transition-all flex items-center justify-center gap-2 bg-white dark:bg-slate-900 text-amber-700 dark:text-amber-400 shadow-sm border border-amber-300/60 dark:border-amber-700/60 cursor-pointer";
                if (tabManual) tabManual.className = "py-2.5 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer";
                if (secArchivo) secArchivo.classList.remove('hidden');
                if (secManual) secManual.classList.add('hidden');
                if (btnText) {
                    btnText.textContent = tarifasParsedFromExcel.length > 0 ? `Autorizar y Guardar (${tarifasParsedFromExcel.length}) Exámenes` : 'Autorizar y Guardar Tarifario';
                }
            } else {
                if (tabManual) tabManual.className = "py-2.5 px-3 rounded-xl text-xs font-black transition-all flex items-center justify-center gap-2 bg-white dark:bg-slate-900 text-amber-700 dark:text-amber-400 shadow-sm border border-amber-300/60 dark:border-amber-700/60 cursor-pointer";
                if (tabArchivo) tabArchivo.className = "py-2.5 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white cursor-pointer";
                if (secManual) secManual.classList.remove('hidden');
                if (secArchivo) secArchivo.classList.add('hidden');
                if (btnText) {
                    btnText.textContent = 'Autorizar y Activar Vigencia';
                }
            }
        }

        function actualizarNombreVigenciaAutomatico(val) {
            const inputNombre = document.getElementById('aut_nombre_vigencia');
            if (inputNombre && (!inputNombre.value || inputNombre.value.startsWith('Tarifario Autorizado'))) {
                inputNombre.value = 'Tarifario Autorizado - ' + val;
            }
        }

        function toggleReajusteInputManual() {
            const box = document.getElementById('boxPorcentajeReajusteManual');
            const radios = document.getElementsByName('modo_valores_manual');
            let esPorcentaje = false;
            for (const r of radios) {
                if (r.checked && r.value === 'porcentaje') {
                    esPorcentaje = true;
                    break;
                }
            }
            if (box) {
                if (esPorcentaje) {
                    box.classList.remove('hidden');
                } else {
                    box.classList.add('hidden');
                    const inp = document.getElementById('aut_porcentaje_manual');
                    if (inp) inp.value = '0.00';
                }
            }
        }

        function cambiarFiltroVigencia(val) {
            const currentUrl = new URL(window.location.href);
            if (val === 'ACTIVA') {
                currentUrl.searchParams.delete('vigencia');
            } else {
                currentUrl.searchParams.set('vigencia', val);
            }
            window.location.href = currentUrl.toString();
        }

        // Drag and Drop para Archivo de Tarifario
        function manejarDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
            const zone = document.getElementById('dropZoneTarifario');
            if (zone) zone.classList.add('border-amber-500', 'bg-amber-100/50', 'dark:bg-amber-950/60');
        }

        function manejarDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            const zone = document.getElementById('dropZoneTarifario');
            if (zone) zone.classList.remove('border-amber-500', 'bg-amber-100/50', 'dark:bg-amber-950/60');
        }

        function manejarDropArchivo(e) {
            e.preventDefault();
            e.stopPropagation();
            manejarDragLeave(e);
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                manejarSeleccionArchivo(e.dataTransfer.files);
            }
        }

        function limpiarArchivoTarifario() {
            tarifasParsedFromExcel = [];
            filtroPreviewTexto = '';
            const input = document.getElementById('inputArchivoTarifario');
            if (input) input.value = '';
            const inputFiltro = document.getElementById('inputFiltroPreviewTarifario');
            if (inputFiltro) inputFiltro.value = '';
            const btnLimpiar = document.getElementById('btnLimpiarFiltroPreview');
            if (btnLimpiar) btnLimpiar.classList.add('hidden');
            const dropZone = document.getElementById('dropZoneTarifario');
            const info = document.getElementById('infoArchivoProcesado');
            const tbody = document.getElementById('tbodyPreviewTarifario');
            if (dropZone) dropZone.classList.remove('hidden');
            if (info) info.classList.add('hidden');
            if (tbody) tbody.innerHTML = '';
            const btnText = document.getElementById('btnSubmitAutorizarText');
            if (btnText && modoVigenciaSeleccionado === 'archivo') {
                btnText.textContent = 'Autorizar y Guardar Tarifario';
            }
        }

        function limpiarValorMoneda(val) {
            if (val === null || val === undefined) return 0;
            if (typeof val === 'number') return Math.round(val);
            const cleaned = String(val).replace(/[^0-9]/g, '');
            return cleaned ? parseInt(cleaned, 10) : 0;
        }

        function sanitizarTexto(txt) {
            if (!txt) return '';
            let res = String(txt).trim();
            // Corrección de caracteres corruptos frecuentes en exportaciones hospitalarias (ej: RADIOGRAF¿A)
            res = res.replace(/RADIOGRAF[\uFFFD\?¿]A/gi, 'RADIOGRAFÍA')
                     .replace(/TOMOGRAF[\uFFFD\?¿]A/gi, 'TOMOGRAFÍA')
                     .replace(/ECOGRAF[\uFFFD\?¿]A/gi, 'ECOGRAFÍA')
                     .replace(/RESONANC[\uFFFD\?¿]A/gi, 'RESONANCIA')
                     .replace(/PROYECCI[\uFFFD\?¿]N/gi, 'PROYECCIÓN')
                     .replace(/VALORACI[\uFFFD\?¿]N/gi, 'VALORACIÓN')
                     .replace(/ATENCI[\uFFFD\?¿]N/gi, 'ATENCIÓN')
                     .replace(/PUNC[\uFFFD\?¿]N/gi, 'PUNCIÓN')
                     .replace(/BIOPS[\uFFFD\?¿]A/gi, 'BIOPSIA')
                     .replace(/GU[\uFFFD\?¿]A/gi, 'GUÍA')
                     .replace(/V[\uFFFD\?¿]A/gi, 'VÍA')
                     .replace(/OSTEOS[\uFFFD\?¿]NTESIS/gi, 'OSTEOSÍNTESIS')
                     .replace(/ARTICULACI[\uFFFD\?¿]N/gi, 'ARTICULACIÓN')
                     .replace(/EXTENSI[\uFFFD\?¿]N/gi, 'EXTENSIÓN')
                     .replace(/INYECCI[\uFFFD\?¿]N/gi, 'INYECCIÓN')
                     .replace(/ASPIRACI[\uFFFD\?¿]N/gi, 'ASPIRACIÓN')
                     .replace(/LOCALIZACI[\uFFFD\?¿]N/gi, 'LOCALIZACIÓN')
                     .replace(/EXTRACCI[\uFFFD\?¿]N/gi, 'EXTRACCIÓN')
                     .replace(/SECCI[\uFFFD\?¿]N/gi, 'SECCIÓN')
                     .replace(/RECONSTRUCCI[\uFFFD\?¿]N/gi, 'RECONSTRUCCIÓN')
                     .replace(/OBTENCI[\uFFFD\?¿]N/gi, 'OBTENCIÓN')
                     .replace(/EXPLORACI[\uFFFD\?¿]N/gi, 'EXPLORACIÓN')
                     .replace(/POSICI[\uFFFD\?¿]N/gi, 'POSICIÓN')
                     .replace(/FIJACI[\uFFFD\?¿]N/gi, 'FIJACIÓN')
                     .replace(/REDUCCI[\uFFFD\?¿]N/gi, 'REDUCCIÓN')
                     .replace(/MEDICI[\uFFFD\?¿]N/gi, 'MEDICIÓN')
                     .replace(/REHABILITACI[\uFFFD\?¿]N/gi, 'REHABILITACIÓN')
                     .replace(/[\uFFFD]/g, '');
            return res;
        }

        function filtrarPreviewTarifario(val) {
            filtroPreviewTexto = (val || '').trim().toLowerCase();
            const btnLimpiar = document.getElementById('btnLimpiarFiltroPreview');
            if (btnLimpiar) {
                if (filtroPreviewTexto.length > 0) {
                    btnLimpiar.classList.remove('hidden');
                } else {
                    btnLimpiar.classList.add('hidden');
                }
            }
            renderizarTablaPreview();
        }

        function limpiarFiltroPreview() {
            filtroPreviewTexto = '';
            const inputFiltro = document.getElementById('inputFiltroPreviewTarifario');
            const btnLimpiar = document.getElementById('btnLimpiarFiltroPreview');
            if (inputFiltro) {
                inputFiltro.value = '';
                inputFiltro.focus();
            }
            if (btnLimpiar) btnLimpiar.classList.add('hidden');
            renderizarTablaPreview();
        }

        function renderizarTablaPreview() {
            const tbody = document.getElementById('tbodyPreviewTarifario');
            const lblConteo = document.getElementById('lblConteoFiltradas');
            if (!tbody) return;

            if (!tarifasParsedFromExcel || tarifasParsedFromExcel.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="p-6 text-center text-xs text-slate-400 dark:text-slate-500 font-medium">
                            No hay datos cargados para previsualizar.
                        </td>
                    </tr>
                `;
                if (lblConteo) lblConteo.textContent = 'Mostrando 0 de 0';
                return;
            }

            const filtradas = tarifasParsedFromExcel.filter(item => {
                if (!filtroPreviewTexto) return true;
                const cod = String(item.codigo || '').toLowerCase();
                const exa = String(item.examen || '').toLowerCase();
                const srv = String(item.servicio || '').toLowerCase();
                const con = String(item.concepto || '').toLowerCase();
                return cod.includes(filtroPreviewTexto) || exa.includes(filtroPreviewTexto) || srv.includes(filtroPreviewTexto) || con.includes(filtroPreviewTexto);
            });

            if (lblConteo) {
                if (filtroPreviewTexto) {
                    lblConteo.innerHTML = `Mostrando <span class="text-amber-600 dark:text-amber-400 font-extrabold">${filtradas.length}</span> de ${tarifasParsedFromExcel.length} (filtrados)`;
                } else {
                    lblConteo.innerHTML = `Mostrando <span class="text-slate-900 dark:text-white font-extrabold">${filtradas.length}</span> de ${tarifasParsedFromExcel.length} exámenes`;
                }
            }

            if (filtradas.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="p-8 text-center">
                            <div class="flex flex-col items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-3xl text-amber-500">search_off</span>
                                <p class="text-xs font-bold text-slate-700 dark:text-slate-300">No se encontraron exámenes con el código o texto "${escapeHtml(filtroPreviewTexto)}"</p>
                                <p class="text-[11px] text-slate-400">Verifica el código ingresado o limpia la búsqueda para ver todos.</p>
                                <button type="button" onclick="limpiarFiltroPreview()" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 border border-amber-300/60 dark:border-amber-700/60 text-xs font-bold hover:bg-amber-200 transition-colors cursor-pointer">
                                    <span class="material-symbols-outlined text-sm">close</span>
                                    Limpiar filtro
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            filtradas.forEach((item, idx) => {
                const valFormateado = (item.valor_und || 0).toLocaleString('es-CO');
                const servBadge = item.servicio || item.tipo || item.concepto || 'N/A';
                html += `
                    <tr class="hover:bg-slate-100/70 dark:hover:bg-slate-800/60 transition-colors border-b border-slate-100 dark:border-slate-800/80">
                        <td class="p-2.5 text-center text-slate-400 font-mono text-[10px] select-none">${idx + 1}</td>
                        <td class="p-2.5">
                            <span class="inline-block px-2 py-0.5 rounded font-mono font-black text-[11px] bg-amber-100/80 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border border-amber-300/60 dark:border-amber-700/60 tracking-tight">
                                ${escapeHtml(item.codigo)}
                            </span>
                        </td>
                        <td class="p-2.5">
                            <div class="font-bold text-slate-800 dark:text-slate-100 text-[11px] leading-snug break-words">
                                ${escapeHtml(item.examen)}
                            </div>
                            ${item.concepto && item.concepto !== servBadge ? `<div class="text-[10px] text-slate-400 font-medium">${escapeHtml(item.concepto)}</div>` : ''}
                        </td>
                        <td class="p-2.5">
                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-extrabold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                ${escapeHtml(servBadge)}
                            </span>
                        </td>
                        <td class="p-2.5 text-center font-bold text-slate-600 dark:text-slate-300">
                            ${item.cant_a_pagar || 1}
                        </td>
                        <td class="p-2.5 text-right font-black text-xs text-emerald-600 dark:text-emerald-400 tracking-tight whitespace-nowrap">
                            $ ${valFormateado}
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // Lectura de Excel / CSV en tiempo real mediante SheetJS
        function manejarSeleccionArchivo(files) {
            if (!files || files.length === 0) return;
            const file = files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['xlsx', 'xls', 'csv'].includes(ext)) {
                safeSwalFire({
                    icon: 'warning',
                    title: 'Formato no soportado',
                    text: 'Por favor sube un archivo Excel (.xlsx, .xls) o archivo separado por comas (.csv).'
                });
                return;
            }

            if (typeof XLSX === 'undefined') {
                safeSwalFire({
                    icon: 'error',
                    title: 'Librería XLSX no disponible',
                    text: 'No se pudo inicializar el lector de Excel en el navegador. Recarga la página e intenta de nuevo.'
                });
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheetName = workbook.SheetNames[0];
                    const worksheet = workbook.Sheets[firstSheetName];
                    const rawRows = XLSX.utils.sheet_to_json(worksheet, { defval: '' });

                    if (!rawRows || rawRows.length === 0) {
                        safeSwalFire({
                            icon: 'warning',
                            title: 'Archivo vacío',
                            text: 'La hoja de cálculo no contiene filas con datos.'
                        });
                        return;
                    }

                    // Normalizar filas según la estructura institucional
                    const normalizadas = [];
                    for (const row of rawRows) {
                        const mapObj = {};
                        for (const k in row) {
                            const cleanK = String(k || '').trim().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, '_');
                            mapObj[cleanK] = row[k];
                        }

                        const codigo = String(mapObj['codigo'] || mapObj['cod'] || mapObj['cups'] || '').trim();
                        if (!codigo) continue;

                        const examenRaw = mapObj['examen'] || mapObj['descripcion'] || mapObj['nombre'] || mapObj['nombre_examen'] || `EXAMEN CUPS ${codigo}`;
                        const examen = sanitizarTexto(examenRaw);
                        
                        const rawVal = mapObj['columna1'] || mapObj['valor'] || mapObj['valor_und'] || mapObj['columna_1'] || 0;
                        const numVal = limpiarValorMoneda(rawVal);
                        
                        const rawValUnd = mapObj['valor_und'] || mapObj['valor'] || mapObj['columna1'] || 0;
                        const numValUnd = limpiarValorMoneda(rawValUnd) || numVal;

                        const cantAPagar = parseInt(mapObj['cant_a_pagar'] || mapObj['cantidad'] || 1, 10) || 1;
                        const concepto = sanitizarTexto(mapObj['concepto'] || '').toUpperCase();
                        const servicio = sanitizarTexto(mapObj['servicio'] || '').toUpperCase();
                        const tipo = sanitizarTexto(mapObj['tipo'] || servicio || 'RX SIMPLE').toUpperCase();
                        const modalidadUbi = String(mapObj['modalidad_ubi'] || mapObj['modalidad'] || 'CR, DX').trim();
                        const cuentaContable = String(mapObj['cuenta_contable'] || mapObj['cuenta'] || '').trim();
                        const nombreCuenta = sanitizarTexto(mapObj['nombre_cuenta'] || mapObj['nombre_de_cuenta'] || '').toUpperCase();
                        const valorTexto = String(mapObj['valor'] || `$ ${numVal.toLocaleString('es-CO')}`).trim();

                        normalizadas.push({
                            codigo: codigo,
                            examen: examen,
                            columna1: numVal,
                            valor: valorTexto,
                            valor_texto: valorTexto,
                            valor_und: numValUnd,
                            cant_a_pagar: cantAPagar,
                            concepto: concepto,
                            servicio: servicio,
                            tipo: tipo,
                            modalidad_ubi: modalidadUbi,
                            cuenta_contable: cuentaContable,
                            nombre_cuenta: nombreCuenta
                        });
                    }

                    if (normalizadas.length === 0) {
                        safeSwalFire({
                            icon: 'warning',
                            title: 'Sin datos válidos',
                            text: 'No se encontraron filas con columna CODIGO válida en el archivo. Verifica las cabeceras de la plantilla.'
                        });
                        return;
                    }

                    tarifasParsedFromExcel = normalizadas;
                    filtroPreviewTexto = '';
                    const inputFiltro = document.getElementById('inputFiltroPreviewTarifario');
                    if (inputFiltro) inputFiltro.value = '';
                    const btnLimpiar = document.getElementById('btnLimpiarFiltroPreview');
                    if (btnLimpiar) btnLimpiar.classList.add('hidden');

                    // Renderizar toda la tabla completa con soporte de búsqueda instantánea
                    renderizarTablaPreview();

                    const dropZone = document.getElementById('dropZoneTarifario');
                    const info = document.getElementById('infoArchivoProcesado');
                    const nombreTxt = document.getElementById('nombreArchivoTxt');
                    const conteoTxt = document.getElementById('conteoFilasTxt');
                    const btnText = document.getElementById('btnSubmitAutorizarText');

                    if (nombreTxt) nombreTxt.textContent = file.name;
                    if (conteoTxt) conteoTxt.textContent = `${normalizadas.length} exámenes identificados y listos para autorizar`;
                    if (btnText && modoVigenciaSeleccionado === 'archivo') {
                        btnText.textContent = `Autorizar y Guardar (${normalizadas.length}) Exámenes`;
                    }

                    if (dropZone) dropZone.classList.add('hidden');
                    if (info) info.classList.remove('hidden');

                } catch (err) {
                    console.error(err);
                    safeSwalFire({
                        icon: 'error',
                        title: 'Error al procesar archivo',
                        text: 'No se pudo interpretar el archivo: ' + (err.message || '')
                    });
                }
            };
            reader.readAsArrayBuffer(file);
        }

        // Helper seguro para SweetAlert con fallback nativo en caso de bloqueo de red
        async function safeSwalFire(options) {
            if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
                const isDark = document.documentElement.classList.contains('dark') || document.body.classList.contains('dark');
                const themedOptions = {
                    background: isDark ? '#0c1427' : '#ffffff',
                    color: isDark ? '#f8fafc' : '#0f172a',
                    customClass: {
                        popup: isDark ? 'swal2-dark-popup' : 'swal2-light-popup',
                        title: isDark ? 'swal2-dark-title' : 'swal2-light-title',
                        htmlContainer: isDark ? 'swal2-dark-html' : 'swal2-light-html',
                        confirmButton: 'swal2-corporate-confirm',
                        cancelButton: 'swal2-corporate-cancel'
                    },
                    ...options
                };
                return await Swal.fire(themedOptions);
            }
            if (options.showCancelButton) {
                const txt = (options.title ? options.title + "\n\n" : '') + (options.text || (options.html ? options.html.replace(/<[^>]*>?/gm, ' ') : '¿Desea continuar?'));
                const ok = window.confirm(txt);
                return { isConfirmed: ok };
            } else {
                const txt = (options.title ? options.title + "\n\n" : '') + (options.text || (options.html ? options.html.replace(/<[^>]*>?/gm, ' ') : ''));
                window.alert(txt);
                return { isConfirmed: true };
            }
        }

        // Envío y Confirmación de Autorización
        async function enviarAutorizarVigencia(e) {
            if (e) {
                try { e.preventDefault(); } catch(err) {}
                try { e.stopPropagation(); } catch(err) {}
            }

            const fechaVigencia = document.getElementById('aut_fecha_vigencia')?.value;
            const nombreVigencia = document.getElementById('aut_nombre_vigencia')?.value;
            const motivo = document.getElementById('aut_motivo')?.value;

            if (!fechaVigencia) {
                await safeSwalFire({
                    icon: 'warning',
                    title: 'Fecha requerida',
                    text: 'Debe ingresar la fecha a partir de la cual entra en vigencia el nuevo tarifario.'
                });
                return;
            }

            if (!motivo || !motivo.trim()) {
                await safeSwalFire({
                    icon: 'warning',
                    title: 'Motivo requerido',
                    text: 'Por favor describa el motivo o justificación del cambio de tarifario para la bitácora de auditoría.'
                });
                return;
            }

            const entidadUrlParam = new URLSearchParams(window.location.search).get('entidad_id') || '<?php echo $entidadSeleccionada; ?>';

            if (modoVigenciaSeleccionado === 'archivo') {
                if (!tarifasParsedFromExcel || tarifasParsedFromExcel.length === 0) {
                    await safeSwalFire({
                        icon: 'warning',
                        title: 'Archivo requerido',
                        text: 'Por favor seleccione o arrastre el archivo Excel (.xlsx, .xls) o CSV con el listado de exámenes y sus nuevos valores.'
                    });
                    return;
                }

                const confirmRes = await safeSwalFire({
                    title: '¿Autorizar y Guardar Nuevo Tarifario?',
                    html: `<div class="text-left text-xs space-y-2.5 text-slate-600 dark:text-slate-300">
                            <p>Se activará el nuevo tarifario con <strong class="text-emerald-600 dark:text-emerald-400 font-black">${tarifasParsedFromExcel.length} exámenes</strong> a partir del: <strong class="text-amber-600 dark:text-amber-400 font-bold">${fechaVigencia}</strong>.</p>
                            <p class="text-slate-500 dark:text-slate-400">El tarifario anterior terminará su vigencia automáticamente el día anterior y quedará sellado en el historial para liquidar atenciones pasadas con sus valores reales.</p>
                           </div>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: `Sí, Guardar ${tarifasParsedFromExcel.length} Exámenes`,
                    cancelButtonText: 'Cancelar'
                });

                if (!confirmRes.isConfirmed) return;

                try {
                    if (typeof Swal !== 'undefined' && Swal.showLoading) {
                        const isDark = document.documentElement.classList.contains('dark') || document.body.classList.contains('dark');
                        Swal.fire({
                            title: 'Guardando nuevo tarifario...',
                            text: `Cerrando tarifario previo, guardando ${tarifasParsedFromExcel.length} exámenes con sus nuevos valores y registrando bitácora...`,
                            allowOutsideClick: false,
                            background: isDark ? '#0c1427' : '#ffffff',
                            color: isDark ? '#f8fafc' : '#0f172a',
                            didOpen: () => Swal.showLoading()
                        });
                    }

                    const formData = new FormData();
                    formData.append('action', 'autorizar_tarifario_archivo');
                    formData.append('entidad_id', entidadUrlParam);
                    formData.append('fecha_vigencia', fechaVigencia);
                    formData.append('nombre_vigencia', nombreVigencia);
                    formData.append('motivo_cambio', motivo);
                    formData.append('tarifas', JSON.stringify(tarifasParsedFromExcel));

                    const res = await fetch('tarifario.php?entidad_id=' + encodeURIComponent(entidadUrlParam), {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        await safeSwalFire({
                            icon: 'success',
                            title: '¡Tarifario Autorizado con Éxito!',
                            text: data.message,
                            confirmButtonText: 'Ver Nuevo Catálogo'
                        });
                        window.location.reload();
                    } else {
                        await safeSwalFire({
                            icon: 'error',
                            title: 'Error al autorizar tarifario',
                            text: data.message || 'No se pudo procesar la nueva vigencia.'
                        });
                    }
                } catch (err) {
                    console.error(err);
                    await safeSwalFire({
                        icon: 'error',
                        title: 'Error de Comunicación',
                        text: 'Ocurrió un error al enviar el nuevo tarifario al servidor: ' + (err.message || '')
                    });
                }

            } else {
                // Modo manual / reajuste porcentual
                const pct = parseFloat(document.getElementById('aut_porcentaje_manual')?.value || 0);

                const confirmRes = await safeSwalFire({
                    title: '¿Confirmar Autorización de Vigencia?',
                    html: `<div class="text-left text-xs space-y-2 text-slate-600 dark:text-slate-300">
                            <p>Está a punto de autorizar y activar un cambio de tarifas a partir del: <strong class="text-amber-600 dark:text-amber-400 font-bold">${fechaVigencia}</strong>.</p>
                            <p class="text-slate-500 dark:text-slate-400">El tarifario viejo terminará su vigencia automáticamente el día anterior y quedará sellado en el historial para liquidar exámenes pasados.</p>
                            ${pct !== 0 ? `<p class="text-amber-600 dark:text-amber-400 font-bold">Se calculará un ajuste porcentual general del ${pct > 0 ? '+' + pct : pct} % a todas las tarifas.</p>` : '<p class="text-emerald-600 dark:text-emerald-400 font-bold">Se clonarán las tarifas actuales como nueva versión base para editarlas libremente en la tabla.</p>'}
                           </div>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, Autorizar y Activar',
                    cancelButtonText: 'Cancelar'
                });

                if (!confirmRes.isConfirmed) return;

                try {
                    if (typeof Swal !== 'undefined' && Swal.showLoading) {
                        Swal.fire({
                            title: 'Autorizando tarifario...',
                            text: 'Cerrando vigencia previa, registrando nuevo período y generando bitácora de auditoría...',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });
                    }

                    const formData = new FormData();
                    formData.append('action', 'autorizar_cambio_tarifario');
                    formData.append('entidad_id', entidadUrlParam);
                    formData.append('fecha_vigencia', fechaVigencia);
                    formData.append('nombre_vigencia', nombreVigencia);
                    formData.append('porcentaje_reajuste', pct);
                    formData.append('motivo_cambio', motivo);

                    const res = await fetch('tarifario.php?entidad_id=' + encodeURIComponent(entidadUrlParam), {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (data.success) {
                        await safeSwalFire({
                            icon: 'success',
                            title: '¡Vigencia Autorizada!',
                            text: data.message,
                            confirmButtonText: 'Ver Catálogo'
                        });
                        window.location.reload();
                    } else {
                        await safeSwalFire({
                            icon: 'error',
                            title: 'Error en la autorización',
                            text: data.message || 'No se pudo autorizar el nuevo tarifario.'
                        });
                    }
                } catch (err) {
                    console.error(err);
                    await safeSwalFire({
                        icon: 'error',
                        title: 'Error de Comunicación',
                        text: 'Ocurrió un error al comunicarse con el servidor: ' + (err.message || '')
                    });
                }
            }
        }
    </script>
</body>
</html>

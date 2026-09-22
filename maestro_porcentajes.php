<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');
if (!isset($con) || $con === false) {
    if (function_exists('obtenerConexionLIHO')) {
        $con = obtenerConexionLIHO();
    }
}

$userName  = $_SESSION['user_name'] ?? 'Usuario';
$userRole  = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId    = (int)($_SESSION['user_id'] ?? 0);
$userEmail = $_SESSION['user_email'] ?? '';

$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

// Control estricto de acceso a Maestro de Porcentajes
if ($userRole !== 'ADMINISTRADOR' && $userRoleIdVal !== 1 && !tienePermisoModulo($userId, $userRoleIdVal, 'maestro_porcentajes')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// Permisos para editar (Administradores [rol_id 1] y Financiera [rol_id 2])
$canEditTarifa = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

// Helper para paletas de entidades (compartido con examenes_medicos.php)
if (!function_exists('obtenerPaletaEntidad')) {
function obtenerPaletaEntidad($colorTema, $entId = '') {
    $c = strtolower(trim((string)$colorTema));
    if ($entId === 'PROPIO' || empty($entId) || $c === 'teal') {
        return [
            'key'            => 'teal',
            'nombre'         => 'Verde Esmeralda Institucional',
            'primary_hex'    => '#0d9488',
            'secondary_hex'  => '#059669',
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
            'tab_active'     => 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-md shadow-emerald-600/25',
            'btn_gradient'   => 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-md shadow-emerald-600/25 hover:shadow-lg',
            'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-teal-700 dark:text-teal-300 hover:bg-teal-50 dark:hover:bg-teal-950/50 border-2 border-teal-400/60 dark:border-teal-600/60 shadow-sm hover:shadow-md',
            'icon_color'     => 'text-teal-600 dark:text-teal-400',
            'table_col_bg'   => 'bg-emerald-50/60 dark:bg-emerald-950/30 text-emerald-900 dark:text-emerald-300',
            'card_border'    => 'border-teal-500',
            'card_bg'        => 'bg-teal-50/20 dark:bg-teal-950/20',
            'card_shadow'    => 'shadow-teal-500/10',
            'card_btn'       => 'bg-teal-600 hover:bg-teal-700 text-white shadow-teal-600/20',
            'card_badge'     => 'bg-teal-50 text-teal-700 dark:bg-teal-950/60 dark:text-teal-300 border border-teal-200/60 dark:border-teal-800/60',
            'kpi_accent_bg'  => 'bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400',
        ];
    }

    switch ($c) {
        case 'purple':
        case 'violet':
            return [
                'key'            => 'purple',
                'nombre'         => 'Púrpura Institucional',
                'primary_hex'    => '#7c3aed',
                'secondary_hex'  => '#6366f1',
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
                'tab_active'     => 'bg-purple-600 hover:bg-purple-700 text-white shadow-md shadow-purple-600/25',
                'btn_gradient'   => 'bg-gradient-to-r from-purple-600 to-violet-600 hover:from-purple-500 hover:to-violet-500 text-white shadow-md shadow-purple-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-purple-700 dark:text-purple-300 hover:bg-purple-50 dark:hover:bg-purple-950/50 border-2 border-purple-400/60 dark:border-purple-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-purple-600 dark:text-purple-400',
                'table_col_bg'   => 'bg-purple-50/60 dark:bg-purple-950/30 text-purple-900 dark:text-purple-300',
                'card_border'    => 'border-purple-500',
                'card_bg'        => 'bg-purple-50/20 dark:bg-purple-950/20',
                'card_shadow'    => 'shadow-purple-500/10',
                'card_btn'       => 'bg-purple-600 hover:bg-purple-700 text-white shadow-purple-600/20',
                'card_badge'     => 'bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200/60 dark:border-purple-800/60',
                'kpi_accent_bg'  => 'bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400',
            ];

        case 'indigo':
        case 'blue':
            return [
                'key'            => 'indigo',
                'nombre'         => 'Azul Índigo Corporativo',
                'primary_hex'    => '#4f46e5',
                'secondary_hex'  => '#3b82f6',
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
                'tab_active'     => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-600/25',
                'btn_gradient'   => 'bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white shadow-md shadow-indigo-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 border-2 border-indigo-400/60 dark:border-indigo-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-indigo-600 dark:text-indigo-400',
                'table_col_bg'   => 'bg-indigo-50/60 dark:bg-indigo-950/30 text-indigo-900 dark:text-indigo-300',
                'card_border'    => 'border-indigo-500',
                'card_bg'        => 'bg-indigo-50/20 dark:bg-indigo-950/20',
                'card_shadow'    => 'shadow-indigo-500/10',
                'card_btn'       => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-600/20',
                'card_badge'     => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200/60 dark:border-indigo-800/60',
                'kpi_accent_bg'  => 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400',
            ];

        case 'sky':
            return [
                'key'            => 'sky',
                'nombre'         => 'Azul Cielo',
                'primary_hex'    => '#0284c7',
                'secondary_hex'  => '#0ea5e9',
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
                'tab_active'     => 'bg-sky-600 hover:bg-sky-700 text-white shadow-md shadow-sky-600/25',
                'btn_gradient'   => 'bg-gradient-to-r from-sky-600 to-cyan-600 hover:from-sky-500 hover:to-cyan-500 text-white shadow-md shadow-sky-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-sky-700 dark:text-sky-300 hover:bg-sky-50 dark:hover:bg-sky-950/50 border-2 border-sky-400/60 dark:border-sky-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-sky-600 dark:text-sky-400',
                'table_col_bg'   => 'bg-sky-50/60 dark:bg-sky-950/30 text-sky-900 dark:text-sky-300',
                'card_border'    => 'border-sky-500',
                'card_bg'        => 'bg-sky-50/20 dark:bg-sky-950/20',
                'card_shadow'    => 'shadow-sky-500/10',
                'card_btn'       => 'bg-sky-600 hover:bg-sky-700 text-white shadow-sky-600/20',
                'card_badge'     => 'bg-sky-50 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300 border border-sky-200/60 dark:border-sky-800/60',
                'kpi_accent_bg'  => 'bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-400',
            ];

        case 'rose':
            return [
                'key'            => 'rose',
                'nombre'         => 'Rosa Rubí',
                'primary_hex'    => '#e11d48',
                'secondary_hex'  => '#f43f5e',
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
                'tab_active'     => 'bg-rose-600 hover:bg-rose-700 text-white shadow-md shadow-rose-600/25',
                'btn_gradient'   => 'bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-500 hover:to-pink-500 text-white shadow-md shadow-rose-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-rose-700 dark:text-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/50 border-2 border-rose-400/60 dark:border-rose-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-rose-600 dark:text-rose-400',
                'table_col_bg'   => 'bg-rose-50/60 dark:bg-rose-950/30 text-rose-900 dark:text-rose-300',
                'card_border'    => 'border-rose-500',
                'card_bg'        => 'bg-rose-50/20 dark:bg-rose-950/20',
                'card_shadow'    => 'shadow-rose-500/10',
                'card_btn'       => 'bg-rose-600 hover:bg-rose-700 text-white shadow-rose-600/20',
                'card_badge'     => 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300 border border-rose-200/60 dark:border-rose-800/60',
                'kpi_accent_bg'  => 'bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400',
            ];

        case 'amber':
        case 'orange':
            return [
                'key'            => 'amber',
                'nombre'         => 'Ámbar Cálido',
                'primary_hex'    => '#d97706',
                'secondary_hex'  => '#f59e0b',
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
                'tab_active'     => 'bg-amber-600 hover:bg-amber-700 text-white shadow-md shadow-amber-600/25',
                'btn_gradient'   => 'bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white shadow-md shadow-amber-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/50 border-2 border-amber-400/60 dark:border-amber-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-amber-600 dark:text-amber-400',
                'table_col_bg'   => 'bg-amber-50/60 dark:bg-amber-950/30 text-amber-900 dark:text-amber-300',
                'card_border'    => 'border-amber-500',
                'card_bg'        => 'bg-amber-50/20 dark:bg-amber-950/20',
                'card_shadow'    => 'shadow-amber-500/10',
                'card_btn'       => 'bg-amber-600 hover:bg-amber-700 text-white shadow-amber-600/20',
                'card_badge'     => 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200/60 dark:border-amber-800/60',
                'kpi_accent_bg'  => 'bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400',
            ];

        default:
            return [
                'key'            => 'indigo',
                'nombre'         => 'Azul Índigo Corporativo',
                'primary_hex'    => '#4f46e5',
                'secondary_hex'  => '#3b82f6',
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
                'tab_active'     => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-600/25',
                'btn_gradient'   => 'bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 text-white shadow-md shadow-indigo-600/25 hover:shadow-lg',
                'btn_cambiar'    => 'bg-white dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/50 border-2 border-indigo-400/60 dark:border-indigo-600/60 shadow-sm hover:shadow-md',
                'icon_color'     => 'text-indigo-600 dark:text-indigo-400',
                'table_col_bg'   => 'bg-indigo-50/60 dark:bg-indigo-950/30 text-indigo-900 dark:text-indigo-300',
                'card_border'    => 'border-indigo-500',
                'card_bg'        => 'bg-indigo-50/20 dark:bg-indigo-950/20',
                'card_shadow'    => 'shadow-indigo-500/10',
                'card_btn'       => 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-indigo-600/20',
                'card_badge'     => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200/60 dark:border-indigo-800/60',
                'kpi_accent_bg'  => 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400',
            ];
    }
}
}

// Helper para clases de colores de badges
if (!function_exists('obtenerEstiloBadgeColor')) {
function obtenerEstiloBadgeColor($color) {
    if (!empty($color) && strpos($color, '#') === 0) {
        return '';
    }
    $map = [
        'purple'  => 'bg-purple-100 text-purple-800 dark:bg-purple-950/80 dark:text-purple-300 border-purple-200 dark:border-purple-800',
        'amber'   => 'bg-amber-100 text-amber-800 dark:bg-amber-950/80 dark:text-amber-300 border-amber-200 dark:border-amber-800',
        'emerald' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
        'blue'    => 'bg-blue-100 text-blue-800 dark:bg-blue-950/80 dark:text-blue-300 border-blue-200 dark:border-blue-800',
        'indigo'  => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/80 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800',
        'rose'    => 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border-rose-200 dark:border-rose-800',
        'teal'    => 'bg-teal-100 text-teal-800 dark:bg-teal-950/80 dark:text-teal-300 border-teal-200 dark:border-teal-800',
        'cyan'    => 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950/80 dark:text-cyan-300 border-cyan-200 dark:border-cyan-800'
    ];
    return $map[$color] ?? $map['emerald'];
}
}

if (!function_exists('obtenerEstiloBadgeInline')) {
function obtenerEstiloBadgeInline($color) {
    if (!empty($color) && strpos($color, '#') === 0 && strlen($color) >= 7) {
        $hex = substr($color, 0, 7);
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        return "style=\"background-color: rgba({$r}, {$g}, {$b}, 0.16); color: {$hex}; border-color: rgba({$r}, {$g}, {$b}, 0.35);\"";
    }
    return "";
}
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// 0. AJAX: SELECCIONAR ENTIDAD ACTIVA (Idéntico a examenes_medicos.php)
if ($action === 'seleccionar_entidad') {
    header('Content-Type: application/json');
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true) ?: $_POST;

    $entId     = trim((string)($inputData['entidad_id'] ?? 'PROPIO'));
    $entNombre = trim((string)($inputData['entidad_nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'));
    $entLogo   = trim((string)($inputData['entidad_logo'] ?? 'assets/img/hologo.png'));
    $entNit    = trim((string)($inputData['entidad_nit'] ?? ''));
    $entColor  = trim((string)($inputData['entidad_color'] ?? ''));

    if (empty($entColor)) {
        if ($entId === 'PROPIO') {
            $entColor = 'teal';
        } else {
            if ($con) {
                $stC = sqlsrv_query($con, "SELECT color_tema FROM dbo.maestro_entidades WHERE id = ?", [(int)$entId]);
                if ($stC && $rC = sqlsrv_fetch_array($stC, SQLSRV_FETCH_ASSOC)) {
                    $entColor = $rC['color_tema'] ?? 'indigo';
                }
            }
        }
    }
    if (empty($entColor)) $entColor = 'indigo';

    $_SESSION['entidad_liquidacion_id']         = $entId;
    $_SESSION['entidad_liquidacion_nombre']     = $entNombre;
    $_SESSION['entidad_liquidacion_logo']       = $entLogo;
    $_SESSION['entidad_liquidacion_nit']        = $entNit;
    $_SESSION['entidad_liquidacion_color']      = $entColor;
    $_SESSION['maestro_porcentajes_entidad_id'] = $entId;

    if (function_exists('registrar_log_sistema')) {
        registrar_log_sistema(
            'TARIFARIOS',
            'SELECCION_ENTIDAD',
            'ACCESO',
            "Usuario {$userName} seleccionó la entidad [{$entNombre}] (ID: {$entId}) para configurar modalidades y porcentajes de pago.",
            ['entidad_afectada' => $entNombre, 'valor_nuevo' => $entId, 'nivel' => 'INFO']
        );
    }

    echo json_encode(['success' => true, 'entidad_id' => $entId, 'entidad_nombre' => $entNombre, 'color_tema' => $entColor]);
    exit;
}

// 1. AJAX: CREAR NUEVA MODALIDAD Y PORCENTAJE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_porcentaje') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para registrar modalidades.']);
        exit;
    }

    $nombre       = trim($_POST['nombre'] ?? '');
    $tipo         = strtoupper(trim($_POST['tipo'] ?? ''));
    $descripcion  = trim($_POST['descripcion'] ?? '');
    $porcentaje   = (float)($_POST['porcentaje'] ?? 0);
    $color        = trim($_POST['color'] ?? '#10b981');
    $entidadIdRaw = trim($_POST['entidad_id'] ?? 'PROPIO');
    $entidadIdVal = ($entidadIdRaw === 'PROPIO' || $entidadIdRaw === '0' || $entidadIdRaw === '') ? null : (int)$entidadIdRaw;

    if (empty($nombre)) {
        echo json_encode(['success' => false, 'message' => 'El nombre de la modalidad es obligatorio.']);
        exit;
    }

    // Auto-generar código si viene vacío o sanearlo
    if (empty($tipo)) {
        $tipo = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', str_replace([' ', '-'], '_', $nombre)));
    } else {
        $tipo = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', str_replace([' ', '-'], '_', $tipo)));
    }

    if (empty($tipo)) {
        echo json_encode(['success' => false, 'message' => 'El código identificador de la modalidad no es válido.']);
        exit;
    }

    $tipoCalculo  = strtoupper(trim($_POST['tipo_calculo'] ?? 'PORCENTAJE'));
    if ($tipoCalculo !== 'VALOR_FIJO') $tipoCalculo = 'PORCENTAJE';
    $rawValFijo   = trim((string)($_POST['valor_fijo'] ?? '0'));
    $valorFijo    = (float)str_replace(['.', '$', ' '], ['', '', ''], str_replace(',', '.', $rawValFijo));
    if ($valorFijo < 0) $valorFijo = 0;

    if ($tipoCalculo === 'PORCENTAJE' && ($porcentaje < 0 || $porcentaje > 100)) {
        echo json_encode(['success' => false, 'message' => 'El porcentaje debe estar entre 0% y 100%.']);
        exit;
    }

    $validColors = ['purple', 'amber', 'emerald', 'blue', 'indigo', 'rose', 'teal', 'cyan'];
    if (!in_array($color, $validColors) && !preg_match('/^#[a-f0-9]{3,8}$/i', $color)) {
        $color = '#10b981';
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos SQL Server.']);
        exit;
    }

    // Verificar si ya existe este tipo dentro del perfil de la entidad
    if ($entidadIdVal === null) {
        $stmtChk = sqlsrv_query($con, "SELECT id FROM maestro_porcentajes_pago WHERE tipo = ? AND (entidad_id IS NULL OR entidad_id = 0)", array($tipo));
    } else {
        $stmtChk = sqlsrv_query($con, "SELECT id FROM maestro_porcentajes_pago WHERE tipo = ? AND entidad_id = ?", array($tipo, $entidadIdVal));
    }

    if ($stmtChk !== false && sqlsrv_has_rows($stmtChk)) {
        echo json_encode(['success' => false, 'message' => "El código de modalidad '$tipo' ya se encuentra registrado para esta entidad."]);
        exit;
    }

    $sqlIns = "INSERT INTO maestro_porcentajes_pago 
               (tipo, nombre, descripcion, porcentaje, tipo_calculo, valor_fijo, color, estado, fecha_creacion, fecha_actualizacion, usuario_id, entidad_id) 
               VALUES (?, ?, ?, ?, ?, ?, ?, 1, GETDATE(), GETDATE(), ?, ?); 
               SELECT SCOPE_IDENTITY() AS new_id;";
    
    $params = array($tipo, $nombre, $descripcion, $porcentaje, $tipoCalculo, $valorFijo, $color, $userId, $entidadIdVal);
    $stmtIns = sqlsrv_query($con, $sqlIns, $params);

    if ($stmtIns === false) {
        echo json_encode(['success' => false, 'message' => 'Error al registrar la modalidad: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    sqlsrv_next_result($stmtIns);
    $rowId = sqlsrv_fetch_array($stmtIns, SQLSRV_FETCH_ASSOC);
    $newId = $rowId['new_id'] ?? 0;

    // Registrar en Historial de Auditoría
    require_once(__DIR__ . '/includes/audit_logger.php');
    $valAudit = ($tipoCalculo === 'VALOR_FIJO') ? "$ " . number_format($valorFijo, 0, ',', '.') : "{$porcentaje}%";
    registrarAuditoriaTarifario(
        $con,
        $newId,
        $tipo,
        $nombre,
        $userId,
        $userName,
        $userEmail,
        $userRole,
        'NUEVA_MODALIDAD',
        'N/A',
        "Modalidad: {$nombre} ({$valAudit}) [Tipo: {$tipoCalculo}] [Entidad ID: " . ($entidadIdVal ?? 'Matriz') . "]",
        'Creación de nueva modalidad de pago',
        'CREACION',
        'MAESTRO_PORCENTAJES'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Modalidad registrada exitosamente.',
        'data' => [
            'id' => $newId,
            'tipo' => $tipo,
            'nombre' => $nombre,
            'porcentaje' => $porcentaje,
            'tipo_calculo' => $tipoCalculo,
            'valor_fijo' => $valorFijo,
            'color' => $color,
            'entidad_id' => $entidadIdVal,
            'estado' => 1
        ]
    ]);
    exit;
}

// 2. AJAX: GUARDAR / EDITAR MODALIDAD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_porcentaje') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar modalidades.']);
        exit;
    }

    $id          = (int)($_POST['id'] ?? 0);
    $nombre      = trim($_POST['nombre'] ?? '');
    $tipo        = strtoupper(trim($_POST['tipo'] ?? ''));
    $descripcion = trim($_POST['descripcion'] ?? '');
    $porcentaje  = (float)($_POST['porcentaje'] ?? 0);
    $color       = trim($_POST['color'] ?? '#10b981');
    $estado      = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;

    $tipoCalculo  = strtoupper(trim($_POST['tipo_calculo'] ?? 'PORCENTAJE'));
    if ($tipoCalculo !== 'VALOR_FIJO') $tipoCalculo = 'PORCENTAJE';
    $rawValFijo   = trim((string)($_POST['valor_fijo'] ?? '0'));
    $valorFijo    = (float)str_replace(['.', '$', ' '], ['', '', ''], str_replace(',', '.', $rawValFijo));
    if ($valorFijo < 0) $valorFijo = 0;

    if ($id <= 0 || empty($nombre)) {
        echo json_encode(['success' => false, 'message' => 'Datos de modalidad inválidos.']);
        exit;
    }

    if ($tipoCalculo === 'PORCENTAJE' && ($porcentaje < 0 || $porcentaje > 100)) {
        echo json_encode(['success' => false, 'message' => 'El porcentaje debe estar entre 0% y 100%.']);
        exit;
    }

    $validColors = ['purple', 'amber', 'emerald', 'blue', 'indigo', 'rose', 'teal', 'cyan'];
    if (!in_array($color, $validColors) && !preg_match('/^#[a-f0-9]{3,8}$/i', $color)) {
        $color = '#10b981';
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
        exit;
    }

    // Consultar registro previo para auditoría
    $stmtCurr = sqlsrv_query($con, "SELECT id, tipo, nombre, descripcion, porcentaje, ISNULL(tipo_calculo, 'PORCENTAJE') AS tipo_calculo, ISNULL(valor_fijo, 0) AS valor_fijo, color, estado, ISNULL(entidad_id, 0) AS entidad_id FROM maestro_porcentajes_pago WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    if (!$curr) {
        echo json_encode(['success' => false, 'message' => 'La modalidad no existe.']);
        exit;
    }

    $entidadIdVal = ((int)$curr['entidad_id'] > 0) ? (int)$curr['entidad_id'] : null;
    $tipoActual = $curr['tipo'];
    $esBase = in_array($tipoActual, ['TARIFAS_ESPECIALES', 'DEGLUCIONES']) && ($entidadIdVal === null);
    
    // Si es modalidad base del sistema Matriz se preserva su 'tipo'
    $tipoFinal = $esBase ? $tipoActual : (!empty($tipo) ? preg_replace('/[^A-Za-z0-9_]/', '', str_replace([' ', '-'], '_', $tipo)) : $tipoActual);

    // Si cambió el tipo, validar que no esté ocupado dentro del perfil de la misma entidad
    if ($tipoFinal !== $tipoActual) {
        if ($entidadIdVal === null) {
            $stmtChk = sqlsrv_query($con, "SELECT id FROM maestro_porcentajes_pago WHERE tipo = ? AND (entidad_id IS NULL OR entidad_id = 0) AND id != ?", array($tipoFinal, $id));
        } else {
            $stmtChk = sqlsrv_query($con, "SELECT id FROM maestro_porcentajes_pago WHERE tipo = ? AND entidad_id = ? AND id != ?", array($tipoFinal, $entidadIdVal, $id));
        }
        if ($stmtChk && sqlsrv_has_rows($stmtChk)) {
            echo json_encode(['success' => false, 'message' => "El código '$tipoFinal' ya está siendo usado por otra modalidad en esta entidad."]);
            exit;
        }
    }

    $sqlUpd = "UPDATE maestro_porcentajes_pago 
               SET nombre = ?, tipo = ?, descripcion = ?, porcentaje = ?, tipo_calculo = ?, valor_fijo = ?, color = ?, estado = ?, 
                   fecha_actualizacion = GETDATE(), usuario_id = ? 
               WHERE id = ?";

    $params = array($nombre, $tipoFinal, $descripcion, $porcentaje, $tipoCalculo, $valorFijo, $color, $estado, $userId, $id);
    $stmtUpd = sqlsrv_query($con, $sqlUpd, $params);

    if ($stmtUpd === false) {
        echo json_encode(['success' => false, 'message' => 'Error actualizando los datos: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    // Registrar cambios en Auditoría
    require_once(__DIR__ . '/includes/audit_logger.php');
    $oldPct = (float)$curr['porcentaje'];
    $oldValFijo = (float)$curr['valor_fijo'];
    $oldTipoCalc = $curr['tipo_calculo'];
    $oldEst = (int)$curr['estado'];

    if ($oldTipoCalc !== $tipoCalculo || $oldValFijo != $valorFijo || $oldPct != $porcentaje) {
        $oldDesc = ($oldTipoCalc === 'VALOR_FIJO') ? "$ " . number_format($oldValFijo, 0, ',', '.') : "{$oldPct}%";
        $newDesc = ($tipoCalculo === 'VALOR_FIJO') ? "$ " . number_format($valorFijo, 0, ',', '.') : "{$porcentaje}%";
        registrarAuditoriaTarifario($con, $id, $tipoFinal, $nombre, $userId, $userName, $userEmail, $userRole, 'TIPO_VALOR_PAGO', $oldDesc, $newDesc, 'Modificación de valor / tipo de liquidación', 'EDICION', 'MAESTRO_PORCENTAJES');
    }
    if ($oldEst !== $estado) {
        $act = ($estado === 1) ? 'ACTIVACION' : 'DESACTIVACION';
        registrarAuditoriaTarifario($con, $id, $tipoFinal, $nombre, $userId, $userName, $userEmail, $userRole, 'ESTADO', ($oldEst === 1 ? 'ACTIVO' : 'INACTIVO'), ($estado === 1 ? 'ACTIVO' : 'INACTIVO'), 'Cambio de estado', $act, 'MAESTRO_PORCENTAJES');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Modalidad actualizada correctamente.',
        'data' => [
            'id' => $id,
            'nombre' => $nombre,
            'tipo' => $tipoFinal,
            'porcentaje' => $porcentaje,
            'tipo_calculo' => $tipoCalculo,
            'valor_fijo' => $valorFijo,
            'color' => $color,
            'estado' => $estado
        ]
    ]);
    exit;
}

// 2.1 AJAX: CLONAR MODALIDADES BASE DE LA IPS MATRIZ HACIA UNA ENTIDAD EXTERNA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clonar_modalidades_base') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para clonar modalidades.']);
        exit;
    }

    $entidadDestinoId = (int)($_POST['entidad_id'] ?? 0);
    if ($entidadDestinoId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Entidad destino no válida.']);
        exit;
    }

    // Consultar modalidades de la IPS Matriz
    $stmtBase = sqlsrv_query($con, "SELECT tipo, nombre, descripcion, porcentaje, ISNULL(tipo_calculo, 'PORCENTAJE') AS tipo_calculo, ISNULL(valor_fijo, 0) AS valor_fijo, color FROM maestro_porcentajes_pago WHERE (entidad_id IS NULL OR entidad_id = 0) AND ISNULL(estado, 1) = 1");
    if ($stmtBase === false) {
        echo json_encode(['success' => false, 'message' => 'Error consultando modalidades base.']);
        exit;
    }

    $clonados = 0;
    while ($rB = sqlsrv_fetch_array($stmtBase, SQLSRV_FETCH_ASSOC)) {
        // Verificar si ya existe para esta entidad
        $chk = sqlsrv_query($con, "SELECT id FROM maestro_porcentajes_pago WHERE tipo = ? AND entidad_id = ?", [$rB['tipo'], $entidadDestinoId]);
        if ($chk !== false && sqlsrv_has_rows($chk)) {
            continue;
        }

        $sqlIns = "INSERT INTO maestro_porcentajes_pago 
                   (tipo, nombre, descripcion, porcentaje, tipo_calculo, valor_fijo, color, estado, fecha_creacion, fecha_actualizacion, usuario_id, entidad_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, 1, GETDATE(), GETDATE(), ?, ?)";
        $resIns = sqlsrv_query($con, $sqlIns, [$rB['tipo'], $rB['nombre'], $rB['descripcion'], $rB['porcentaje'], $rB['tipo_calculo'], $rB['valor_fijo'], $rB['color'], $userId, $entidadDestinoId]);
        if ($resIns !== false) {
            $clonados++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Se importaron exitosamente {$clonados} modalidades base para la entidad.",
        'clonados' => $clonados
    ]);
    exit;
}

// 3. AJAX: TOGGLE ESTADO / ACTIVAR / DESACTIVAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_estado_porcentaje') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para cambiar el estado.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $nuevoEstado = (int)($_POST['nuevo_estado'] ?? 1);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        exit;
    }

    $stmtCurr = sqlsrv_query($con, "SELECT id, tipo, ISNULL(nombre, tipo) AS nombre, porcentaje, estado FROM maestro_porcentajes_pago WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    $stmt = sqlsrv_query($con, "UPDATE maestro_porcentajes_pago SET estado = ?, fecha_actualizacion = GETDATE(), usuario_id = ? WHERE id = ?", array($nuevoEstado, $userId, $id));
    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Error al cambiar estado.']);
        exit;
    }

    if ($curr) {
        require_once(__DIR__ . '/includes/audit_logger.php');
        $tipo       = $curr['tipo'];
        $nombre     = $curr['nombre'];
        $oldEstado  = (int)$curr['estado'];
        $tipoAccion = ($nuevoEstado === 1) ? 'ACTIVACION' : 'DESACTIVACION';
        $motivo     = ($nuevoEstado === 1) ? 'Habilitación de modalidad' : 'Inactivación de modalidad';

        registrarAuditoriaTarifario(
            $con,
            $id,
            $tipo,
            $nombre,
            $userId,
            $userName,
            $userEmail,
            $userRole,
            'ESTADO',
            ($oldEstado === 1 ? 'ACTIVO' : 'INACTIVO'),
            ($nuevoEstado === 1 ? 'ACTIVO' : 'INACTIVO'),
            $motivo,
            $tipoAccion,
            'MAESTRO_PORCENTAJES'
        );
    }

    echo json_encode([
        'success' => true, 
        'message' => ($nuevoEstado === 1) ? 'Modalidad activada exitosamente.' : 'Modalidad desactivada correctamente.', 
        'nuevo_estado' => $nuevoEstado
    ]);
    exit;
}

// 4. AJAX: ELIMINAR MODALIDAD (BLOQUEADO: SOLO SE PERMITE DESACTIVAR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'eliminar_porcentaje') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Por políticas de auditoría e integridad del sistema, las modalidades no pueden ser eliminadas físicamente. Puede desactivarlas para suspender su liquidación.'
    ]);
    exit;
}

// 5. EXPORTAR A CSV (Soporta exportar por entidad o general)
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=maestro_modalidades_porcentajes_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, [
        'ENTIDAD',
        'NOMBRE_MODALIDAD',
        'CODIGO_TIPO',
        'DESCRIPCION',
        'TIPO_CALCULO',
        'VALOR_APLICABLE',
        'COLOR',
        'ESTADO'
    ], ';');

    if (isset($con) && $con !== false) {
        $filterEntCsv = $_GET['entidad_id'] ?? '';
        $sqlEx = "SELECT p.tipo, ISNULL(p.nombre, p.tipo) AS nombre, p.descripcion, p.porcentaje, 
                         ISNULL(p.tipo_calculo, 'PORCENTAJE') AS tipo_calculo, ISNULL(p.valor_fijo, 0) AS valor_fijo,
                         ISNULL(p.color, 'emerald') AS color, p.estado, ISNULL(p.entidad_id, 0) AS entidad_id,
                         CASE WHEN p.entidad_id IS NULL OR p.entidad_id = 0 THEN 'HERNÁN OCAZIONEZ Y CÍA S.A.S. (IPS MATRIZ)' 
                              ELSE e.nombre END AS entidad_nombre
                  FROM maestro_porcentajes_pago p
                  LEFT JOIN maestro_entidades e ON p.entidad_id = e.id ";
        
        $paramsEx = [];
        if ($filterEntCsv === 'PROPIO') {
            $sqlEx .= " WHERE (p.entidad_id IS NULL OR p.entidad_id = 0) ";
        } elseif (!empty($filterEntCsv) && is_numeric($filterEntCsv)) {
            $sqlEx .= " WHERE p.entidad_id = ? ";
            $paramsEx[] = (int)$filterEntCsv;
        }
        $sqlEx .= " ORDER BY ISNULL(p.entidad_id, 0) ASC, p.id ASC";

        $stmtEx = sqlsrv_query($con, $sqlEx, $paramsEx);
        if ($stmtEx !== false) {
            while ($r = sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC)) {
                $esFijo = ($r['tipo_calculo'] === 'VALOR_FIJO');
                $valStr = $esFijo ? '$ ' . number_format((float)$r['valor_fijo'], 0, ',', '.') : number_format((float)$r['porcentaje'], 2) . '%';
                fputcsv($output, [
                    $r['entidad_nombre'],
                    $r['nombre'],
                    $r['tipo'],
                    $r['descripcion'],
                    $esFijo ? 'VALOR_FIJO' : 'PORCENTAJE',
                    $valStr,
                    $r['color'],
                    ((int)$r['estado'] === 1) ? 'ACTIVO' : 'INACTIVO'
                ], ';');
            }
        }
    }
    fclose($output);
    exit;
}

// 6. CARGAR ENTIDADES Y MODALIDADES POR PERFIL
$entidadesList = [];
// 1. IPS Matriz Propia (Hernán Ocazionez)
$hoId = 4;
$entidadesList['PROPIO'] = [
    'id'         => 'PROPIO',
    'id_int'     => $hoId,
    'nombre'     => 'HERNÁN OCAZIONEZ Y CÍA S.A.S.',
    'subtitulo'  => 'Sistemas Diagnósticos e Imágenes Médicas Especializadas',
    'tipo_ips'   => 'IPS Matriz / Propia',
    'nit'        => '800.149.695-1',
    'color_tema' => 'teal',
    'logo'       => 'assets/img/hologo.png'
];

// 2. Entidades Externas registradas en maestro_entidades
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
                    'subtitulo'  => 'Sistemas Diagnósticos e Imágenes Médicas Especializadas',
                    'tipo_ips'   => 'IPS Matriz / Propia',
                    'nit'        => $nitComp,
                    'color_tema' => 'teal',
                    'logo'       => !empty($rE['logo']) ? $rE['logo'] : 'assets/img/hologo.png'
                ];
                $entidadesList['PROPIO'] = $hoData;
            } else {
                $entidadesList[(string)$eId] = [
                    'id'         => (string)$eId,
                    'id_int'     => $eId,
                    'nombre'     => $rE['nombre'],
                    'subtitulo'  => !empty($rE['ciudad']) ? 'Sede ' . $rE['ciudad'] : 'Entidad en Convenio IPS',
                    'tipo_ips'   => 'IPS Externa',
                    'nit'        => $nitComp,
                    'color_tema' => !empty($rE['color_tema']) ? $rE['color_tema'] : 'purple',
                    'logo'       => !empty($rE['logo']) ? $rE['logo'] : ''
                ];
            }
        }
    }
}

// 2.1 Conteo de médicos por entidad para tarjetas visuales interactivas
$entidadesCounts = [];
$countPropios = 0;
if (isset($con) && $con !== false) {
    $sqlC = "SELECT ISNULL(entidad_id, 0) as entidad_id, COUNT(*) as total FROM dbo.medicos GROUP BY entidad_id";
    $stmtC = sqlsrv_query($con, $sqlC);
    if ($stmtC !== false) {
        while ($rc = sqlsrv_fetch_array($stmtC, SQLSRV_FETCH_ASSOC)) {
            $eId = (int)$rc['entidad_id'];
            if ($eId === 0 || $eId === $hoId) {
                $countPropios += (int)$rc['total'];
            } else {
                $entidadesCounts[strval($eId)] = (int)$rc['total'];
            }
        }
    }
}

// 3. Consultar listado completo de modalidades y agrupar por entidad
$todasModalidades = [];
$modalidadesPorEntidad = [];
$statsPorEntidad = [];

foreach ($entidadesList as $k => $e) {
    $modalidadesPorEntidad[$k] = [];
    $statsPorEntidad[$k] = [
        'total'          => 0,
        'activas'        => 0,
        'inactivas'      => 0,
        'count_pct'      => 0,
        'sum_porcentaje' => 0,
        'promedio'       => 0.00
    ];
}

if (isset($con) && $con !== false) {
    $sqlSel = "SELECT id, tipo, ISNULL(nombre, tipo) AS nombre, descripcion, porcentaje, 
                      ISNULL(tipo_calculo, 'PORCENTAJE') AS tipo_calculo, ISNULL(valor_fijo, 0) AS valor_fijo,
                      ISNULL(color, 'emerald') AS color, ISNULL(estado, 1) AS estado,
                      ISNULL(entidad_id, 0) AS entidad_id 
               FROM maestro_porcentajes_pago 
               ORDER BY id ASC";
    $stmt = sqlsrv_query($con, $sqlSel);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['id']           = (int)$row['id'];
            $row['estado']       = (int)$row['estado'];
            $row['porcentaje']   = (float)$row['porcentaje'];
            $row['tipo_calculo'] = strtoupper(trim((string)($row['tipo_calculo'] ?? 'PORCENTAJE')));
            if ($row['tipo_calculo'] !== 'VALOR_FIJO') $row['tipo_calculo'] = 'PORCENTAJE';
            $row['valor_fijo']    = (float)($row['valor_fijo'] ?? 0);
            $row['nombre']       = !empty($row['nombre']) ? $row['nombre'] : $row['tipo'];
            $row['color']        = !empty($row['color']) ? $row['color'] : 'emerald';
            $entIdInt            = (int)$row['entidad_id'];
            $entKey              = ($entIdInt === 0 || $entIdInt === $hoId) ? 'PROPIO' : (string)$entIdInt;
            $row['entidad_key']   = $entKey;

            $todasModalidades[] = $row;

            if (isset($modalidadesPorEntidad[$entKey])) {
                $modalidadesPorEntidad[$entKey][] = $row;
                $statsPorEntidad[$entKey]['total']++;
                if ($row['estado'] === 1) {
                    $statsPorEntidad[$entKey]['activas']++;
                    if ($row['tipo_calculo'] === 'PORCENTAJE') {
                        $statsPorEntidad[$entKey]['count_pct']++;
                        $statsPorEntidad[$entKey]['sum_porcentaje'] += $row['porcentaje'];
                    }
                } else {
                    $statsPorEntidad[$entKey]['inactivas']++;
                }
            }
        }
    }
}

// Calcular promedios por entidad
foreach ($statsPorEntidad as $k => &$st) {
    $st['promedio'] = (!empty($st['count_pct']) && $st['count_pct'] > 0) ? round($st['sum_porcentaje'] / $st['count_pct'], 2) : 0.00;
}
unset($st);

// 4. Determinar la Entidad Seleccionada y Modal de Entrada (Estilo examenes_medicos.php)
$mostrarModalSeleccion = false;

// Si se solicita cambiar explícitamente por URL
if (isset($_GET['cambiar_entidad']) || isset($_GET['seleccionar'])) {
    $mostrarModalSeleccion = true;
}

if (isset($_GET['entidad_id']) && !empty($_GET['entidad_id'])) {
    $entidadActivaKey = trim((string)$_GET['entidad_id']);
    $_SESSION['entidad_liquidacion_id']         = $entidadActivaKey;
    $_SESSION['maestro_porcentajes_entidad_id'] = $entidadActivaKey;
} else {
    // Si no viene en el URL, verificar si ya fue seleccionada en la sesión
    $entidadActivaKey = $_SESSION['maestro_porcentajes_entidad_id'] ?? ($_SESSION['entidad_liquidacion_id'] ?? '');
    if (empty($entidadActivaKey)) {
        $mostrarModalSeleccion = true;
        $entidadActivaKey = 'PROPIO'; // Default para preparar la vista de fondo
    }
}

if (!isset($entidadesList[$entidadActivaKey])) {
    $entidadActivaKey = 'PROPIO';
}

$entidadActiva = $entidadesList[$entidadActivaKey];
$paletaActiva  = obtenerPaletaEntidad($entidadActiva['color_tema'], $entidadActiva['id']);

// Modalidades y métricas para la entidad activa
$porcentajesList    = $modalidadesPorEntidad[$entidadActivaKey] ?? [];
$totalActivas       = $statsPorEntidad[$entidadActivaKey]['activas'] ?? 0;
$totalInactivas     = $statsPorEntidad[$entidadActivaKey]['inactivas'] ?? 0;
$promedioPorcentaje = $statsPorEntidad[$entidadActivaKey]['promedio'] ?? 0.00;
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Modalidades y Porcentajes | LIHO</title>

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

    <!-- Main Container (Aprovechamiento de pantalla ancha) -->
    <main class="flex-grow max-w-[98%] 2xl:max-w-[1850px] w-full mx-auto px-3 sm:px-6 py-6 sm:py-8 relative">

        <!-- Resplandor ambiental de color de la entidad para toda la vista -->
        <div class="absolute -top-6 left-1/2 -translate-x-1/2 w-full max-w-5xl h-72 bg-gradient-to-b <?php echo $paletaActiva['blur_ambient']; ?> rounded-full blur-3xl pointer-events-none opacity-50 transition-all duration-700"></div>

        <!-- Encabezado y Pestañas de Navegación -->
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
            <div>
                <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-slate-200/80 dark:bg-slate-800 text-xs font-bold uppercase tracking-wider mb-2 border border-slate-300/60 dark:border-slate-700/60">
                    <span class="material-symbols-outlined text-sm text-emerald-600 dark:text-emerald-400">percent</span>
                    <span class="text-slate-600 dark:text-slate-300">Maestro de Modalidades y Porcentajes de Pago</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight font-outfit">
                    Modalidades y Porcentajes de Pago
                </h1>
                <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-1">
                    Cree y administre dinámicamente las modalidades de liquidación y sus porcentajes de pago
                </p>
            </div>

            <!-- Toolbar Superior Ordenada: Pestañas de Navegación a la derecha y Acciones abajo -->
            <div class="flex flex-col items-start lg:items-end gap-2.5 w-full lg:w-auto shrink-0">
                <!-- Pestañas de Navegación Segmentada -->
                <div class="inline-flex p-1 bg-slate-200/80 dark:bg-slate-900/90 rounded-2xl border border-slate-300/70 dark:border-slate-800 shadow-xs">
                    <a href="tarifario.php?entidad_id=<?php echo urlencode($entidadActivaKey); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-teal-600 dark:hover:text-teal-400 hover:bg-white/80 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-teal-500">request_quote</span>
                        <span>Tarifario General</span>
                    </a>
                    <a href="tarifario_especial.php?entidad_id=<?php echo urlencode($entidadActivaKey); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 dark:text-slate-300 hover:text-purple-600 dark:hover:text-purple-400 hover:bg-white/80 dark:hover:bg-slate-800 transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-purple-500">star</span>
                        <span>Tarifas Especiales</span>
                    </a>
                    <a href="maestro_porcentajes.php?entidad_id=<?php echo urlencode($entidadActivaKey); ?>" 
                       class="px-4 py-2 rounded-xl text-xs font-extrabold <?php echo $paletaActiva['tab_active']; ?> transition-all flex items-center gap-1.5 shadow-sm">
                        <span class="material-symbols-outlined text-sm">percent</span>
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

                    <a href="maestro_porcentajes.php?action=export_csv&entidad_id=<?php echo urlencode($entidadActivaKey); ?>" 
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all hover:scale-105 active:scale-95">
                        <span class="material-symbols-outlined text-base">download</span>
                        <span>Exportar a Excel / CSV</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Banner Principal de la Entidad Activa Seleccionada (Ultra Visible y Ambientado) -->
        <?php 
            $medicosVinculadosCount = ($entidadActivaKey === 'PROPIO') ? $countPropios : ($entidadesCounts[$entidadActivaKey] ?? 0);
        ?>
        <div class="relative z-10 overflow-hidden rounded-3xl border-2 <?php echo $paletaActiva['banner_border']; ?> <?php echo $paletaActiva['banner_bg']; ?> <?php echo $paletaActiva['banner_shadow']; ?> p-5 sm:p-6 mb-8 transition-all duration-300">
            <!-- Resplandor ambiental interno con el color de la entidad -->
            <div class="absolute -top-14 -right-14 w-80 h-80 bg-gradient-to-br <?php echo $paletaActiva['blur_ambient']; ?> rounded-full blur-3xl pointer-events-none opacity-70"></div>
            <div class="absolute -bottom-10 -left-10 w-56 h-56 bg-gradient-to-tr <?php echo $paletaActiva['blur_ambient']; ?> rounded-full blur-2xl pointer-events-none opacity-40"></div>

            <div class="relative z-10 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-5">
                <!-- Logo e Información Detallada de la Entidad -->
                <div class="flex items-start sm:items-center gap-4 sm:gap-5">
                    <!-- Caja de Logo Destacada con Borde y Anillo Temático -->
                    <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-white flex items-center justify-center p-2.5 border-2 <?php echo $paletaActiva['logo_border']; ?> shadow-lg shrink-0 <?php echo $paletaActiva['logo_ring']; ?> overflow-hidden transition-transform duration-300 hover:scale-105">
                        <?php if ($entidadActivaKey === 'PROPIO'): ?>
                            <img src="assets/img/hologo.png" alt="Logo IPS Matriz" class="max-w-full max-h-full object-contain">
                        <?php elseif (!empty($entidadActiva['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($entidadActiva['logo']); ?>" alt="Logo <?php echo htmlspecialchars($entidadActiva['nombre']); ?>" class="max-w-full max-h-full object-contain" onerror="this.onerror=null; this.parentElement.innerHTML='<span class=\'text-base font-black <?php echo $paletaActiva['pill_text']; ?>\'><?php echo strtoupper(substr($entidadActiva['nombre'], 0, 3)); ?></span>';">
                        <?php else: ?>
                            <span class="text-base font-black <?php echo $paletaActiva['pill_text']; ?>"><?php echo strtoupper(substr($entidadActiva['nombre'], 0, 3)); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Datos y Metadatos de la Entidad -->
                    <div>
                        <!-- Etiqueta de Contexto y Tipo -->
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <span class="relative flex h-2.5 w-2.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full <?php echo $paletaActiva['dot_pulse']; ?> opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2.5 w-2.5 <?php echo $paletaActiva['dot_pulse']; ?>"></span>
                            </span>
                            <span class="text-[10px] font-black uppercase tracking-wider <?php echo $paletaActiva['pill_text']; ?>">
                                ENTIDAD AFECTADA EN ESTA VISTA
                            </span>
                            <span class="px-2.5 py-0.5 text-[10px] font-black uppercase rounded-full <?php echo $paletaActiva['card_badge']; ?>">
                                <?php echo $entidadActiva['tipo_ips']; ?>
                            </span>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 text-[10px] font-bold rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                <i class="fa-solid fa-user-doctor text-[10px] <?php echo $paletaActiva['pill_text']; ?>"></i>
                                <span><?php echo $medicosVinculadosCount; ?> médicos vinculados</span>
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
                            <span class="inline-flex items-center gap-1 <?php echo $paletaActiva['accent_text']; ?> font-semibold">
                                <span class="material-symbols-outlined text-[15px]">verified_user</span>
                                <span>Las modalidades y valores configurados aplican exclusivamente a esta entidad.</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción para Cambiar Entidad y Crear Modalidad -->
                <div class="flex flex-wrap items-center gap-3 self-stretch sm:self-auto justify-start lg:justify-end shrink-0 pt-3 lg:pt-0 border-t lg:border-t-0 border-slate-200/60 dark:border-slate-800">
                    <!-- Botón Destacado: Cambiar Entidad -->
                    <button type="button" onclick="abrirModalSeleccionarEntidad()"
                            class="px-4 sm:px-5 py-2.5 sm:py-3 rounded-2xl <?php echo $paletaActiva['btn_cambiar']; ?> font-extrabold text-xs transition-all duration-200 cursor-pointer inline-flex items-center gap-2.5 group hover:scale-[1.02] active:scale-[0.98] shrink-0"
                            title="Seleccionar otra entidad para configurar sus modalidades de pago">
                        <span class="material-symbols-outlined text-xl transition-transform duration-300 group-hover:rotate-180 <?php echo $paletaActiva['icon_color']; ?>">swap_horiz</span>
                        <div class="text-left">
                            <span class="block text-[9px] uppercase tracking-wider text-slate-400 dark:text-slate-400 font-bold leading-none">Configurar otra</span>
                            <span class="block text-xs font-black leading-tight mt-0.5">Cambiar Entidad</span>
                        </div>
                    </button>

                    <?php if ($canEditTarifa): ?>
                        <?php if ($entidadActivaKey !== 'PROPIO' && count($porcentajesList) === 0): ?>
                            <button type="button" onclick="clonarModalidadesBase('<?php echo $entidadActivaKey; ?>', '<?php echo addslashes($entidadActiva['nombre']); ?>')"
                                    class="px-4 py-2.5 sm:py-3 rounded-2xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-md shadow-purple-600/20 transition-all inline-flex items-center gap-2 cursor-pointer hover:scale-[1.02] active:scale-[0.98]">
                                <span class="material-symbols-outlined text-lg">content_copy</span>
                                <span>Importar Base Matriz</span>
                            </button>
                        <?php endif; ?>
                        
                        <!-- Botón Nueva Modalidad (Con los colores de la entidad activa) -->
                        <button type="button" onclick="abrirModalCrear()"
                                class="px-4 sm:px-5 py-2.5 sm:py-3 rounded-2xl <?php echo $paletaActiva['btn_gradient']; ?> font-extrabold text-xs transition-all duration-200 inline-flex items-center gap-2 cursor-pointer hover:scale-[1.02] active:scale-[0.98] shrink-0">
                            <span class="material-symbols-outlined text-lg">add_circle</span>
                            <span>+ Nueva Modalidad</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tarjetas de Métricas Rápidas (KPIs para la Entidad Activa con Ambientación) -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <!-- Total Modalidades -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between transition-all hover:border-slate-300 dark:hover:border-slate-700">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Modalidades Totales</p>
                    <h3 class="text-2xl font-black text-primary dark:text-white font-outfit mt-1"><?php echo count($porcentajesList); ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">En perfil <?php echo ($entidadActivaKey === 'PROPIO' ? 'Matriz' : htmlspecialchars($entidadActiva['nombre'])); ?></p>
                </div>
                <div class="w-12 h-12 rounded-2xl <?php echo $paletaActiva['kpi_accent_bg']; ?> flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">category</span>
                </div>
            </div>

            <!-- Porcentajes Activos -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between transition-all hover:border-slate-300 dark:hover:border-slate-700">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Activas en Liquidación</p>
                    <h3 class="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-outfit mt-1"><?php echo $totalActivas; ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?php echo $totalInactivas; ?> inactivas</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
            </div>

            <!-- Promedio Porcentaje -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between transition-all hover:border-slate-300 dark:hover:border-slate-700">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Promedio % de Pago</p>
                    <h3 class="text-2xl font-black <?php echo $paletaActiva['pill_text']; ?> font-outfit mt-1"><?php echo $promedioPorcentaje; ?>%</h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">Porcentaje medio de esta entidad</p>
                </div>
                <div class="w-12 h-12 rounded-2xl <?php echo $paletaActiva['kpi_accent_bg']; ?> flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">percent</span>
                </div>
            </div>
        </div>

        <!-- Tabla Principal de Maestro de Modalidades y Porcentajes de la Entidad -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="tablaPorcentajes">
                    <thead>
                        <tr class="bg-slate-50/90 dark:bg-slate-800/90 border-b border-slate-200 dark:border-slate-700 text-[11px] font-extrabold text-slate-400 dark:text-slate-300 uppercase tracking-wider">
                            <th class="py-4 px-6 whitespace-nowrap">Modalidad</th>
                            <th class="py-4 px-6 whitespace-nowrap">Código / Tipo</th>
                            <th class="py-4 px-6 min-w-[260px]">Descripción</th>
                            <th class="py-4 px-6 text-center whitespace-nowrap <?php echo $paletaActiva['table_col_bg']; ?> font-black text-xs uppercase tracking-wider border-x <?php echo $paletaActiva['badge_border']; ?>">Tipo y Valor Liquidación</th>
                            <th class="py-4 px-6 text-center whitespace-nowrap">Estado</th>
                            <?php if ($canEditTarifa): ?>
                            <th class="py-4 px-6 text-center whitespace-nowrap">Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($porcentajesList)): ?>
                            <tr>
                                <td colspan="<?php echo $canEditTarifa ? 6 : 5; ?>" class="text-center py-12 text-slate-400 font-medium">
                                    <div class="max-w-md mx-auto space-y-3">
                                        <div class="w-14 h-14 mx-auto rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-3xl">category</span>
                                        </div>
                                        <h4 class="text-base font-bold text-slate-800 dark:text-slate-200 font-outfit">
                                            No hay modalidades configuradas para <?php echo htmlspecialchars($entidadActiva['nombre']); ?>
                                        </h4>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            Esta entidad aún no tiene modalidades de liquidación asignadas. Puede crear una nueva o importar las modalidades base de la IPS Matriz con 1 clic.
                                        </p>
                                        <?php if ($canEditTarifa && $entidadActivaKey !== 'PROPIO'): ?>
                                            <div class="pt-2 flex flex-wrap items-center justify-center gap-2.5">
                                                <button type="button" onclick="clonarModalidadesBase('<?php echo $entidadActivaKey; ?>', '<?php echo addslashes($entidadActiva['nombre']); ?>')"
                                                        class="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-md transition-all flex items-center gap-1.5 cursor-pointer">
                                                    <span class="material-symbols-outlined text-base">content_copy</span>
                                                    <span>Importar Base de Matriz</span>
                                                </button>
                                                <button type="button" onclick="abrirModalCrear()"
                                                        class="px-4 py-2 rounded-xl <?php echo $paletaActiva['btn_gradient']; ?> font-bold text-xs shadow-md transition-all flex items-center gap-1.5 cursor-pointer">
                                                    <span class="material-symbols-outlined text-base">add_circle</span>
                                                    <span>+ Nueva Modalidad</span>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($porcentajesList as $p): ?>
                                <?php 
                                    $isActivo    = ($p['estado'] === 1);
                                    $badgeStyle  = obtenerEstiloBadgeColor($p['color'] ?? 'emerald');
                                    $badgeInline = obtenerEstiloBadgeInline($p['color'] ?? 'emerald');
                                    $esBase      = in_array($p['tipo'], ['TARIFAS_ESPECIALES', 'DEGLUCIONES']) && ($entidadActivaKey === 'PROPIO');
                                    $esFijo      = (($p['tipo_calculo'] ?? 'PORCENTAJE') === 'VALOR_FIJO');
                                ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors fila-porcentaje border-b border-slate-100 dark:border-slate-800/60">
                                    
                                    <!-- Modalidad Nombre -->
                                    <td class="py-4 px-6 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-extrabold border <?php echo $badgeStyle; ?>" <?php echo $badgeInline; ?>>
                                                <?php echo htmlspecialchars($p['nombre']); ?>
                                            </span>
                                            <?php if ($esBase): ?>
                                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-400" title="Modalidad Base del Sistema">BASE</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- Código Tipo -->
                                    <td class="py-4 px-6 whitespace-nowrap">
                                        <code class="px-2 py-1 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono text-[11px] font-bold">
                                            <?php echo htmlspecialchars($p['tipo']); ?>
                                        </code>
                                    </td>

                                    <!-- Descripción -->
                                    <td class="py-4 px-6">
                                        <p class="text-xs text-slate-600 dark:text-slate-300 leading-normal">
                                            <?php echo !empty($p['descripcion']) ? htmlspecialchars($p['descripcion']) : '<span class="text-slate-400 italic">Sin descripción</span>'; ?>
                                        </p>
                                    </td>

                                    <!-- Tipo y Valor Liquidación -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap bg-slate-50/50 dark:bg-slate-800/30">
                                        <?php if ($esFijo): ?>
                                            <div class="inline-flex items-center gap-2">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider bg-blue-100 text-blue-800 dark:bg-blue-950/80 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                    <span class="material-symbols-outlined text-[11px]">payments</span>
                                                    Fijo
                                                </span>
                                                <span class="font-black text-blue-600 dark:text-blue-400 text-sm font-outfit">
                                                    $ <?php echo number_format((float)($p['valor_fijo'] ?? 0), 0, ',', '.'); ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <div class="inline-flex items-center gap-2">
                                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                                    <span class="material-symbols-outlined text-[11px]">percent</span>
                                                    Porcentaje
                                                </span>
                                                <span class="font-black text-emerald-600 dark:text-emerald-400 text-sm font-outfit">
                                                    <?php echo number_format((float)$p['porcentaje'], 2); ?>%
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Estado -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <?php if ($isActivo): ?>
                                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 text-xs font-extrabold uppercase">
                                                Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 text-xs font-extrabold uppercase">
                                                Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <?php if ($canEditTarifa): ?>
                                    <!-- Acciones -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-2">
                                            <!-- Editar -->
                                            <button type="button" title="Editar modalidad" 
                                                onclick="abrirModalEditar(<?php echo htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8'); ?>)"
                                                class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900 border border-amber-200 dark:border-amber-800 transition-colors cursor-pointer">
                                                <span class="material-symbols-outlined text-base">edit</span>
                                            </button>

                                            <!-- Toggle Estado (Desactivar / Activar) -->
                                            <button type="button" title="<?php echo $isActivo ? 'Desactivar modalidad' : 'Activar modalidad'; ?>"
                                                onclick="toggleEstado(<?php echo $p['id']; ?>, <?php echo $isActivo ? 0 : 1; ?>, '<?php echo htmlspecialchars($p['nombre'], ENT_QUOTES); ?>')"
                                                class="p-2.5 rounded-xl <?php echo $isActivo ? 'bg-rose-50 text-rose-600 hover:bg-rose-100 dark:bg-rose-950/60 dark:text-rose-400 border border-rose-200 dark:border-rose-800' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100 dark:bg-emerald-950/60 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800'; ?> transition-colors cursor-pointer">
                                                <span class="material-symbols-outlined text-base"><?php echo $isActivo ? 'visibility_off' : 'visibility'; ?></span>
                                            </button>
                                        </div>
                                    </td>
                                    <?php endif; ?>

                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Registrar Nueva Modalidad -->
    <div id="modalCrear" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4 py-6 overflow-y-auto">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-4xl lg:max-w-5xl w-full p-6 sm:p-7 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300 animate-card-entry max-h-[92vh] overflow-y-auto">
            
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-50 dark:bg-emerald-950/80 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold shadow-xs">
                        <span class="material-symbols-outlined text-xl">add_box</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Nueva Modalidad de Pago</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500">Configure la modalidad, su cálculo (porcentaje o valor fijo) y asignación</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalCrear()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formCrear" onsubmit="guardarNuevoPorcentaje(event)" class="space-y-4 text-xs">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    <!-- Columna Izquierda: Datos de la Modalidad y Tipo de Cálculo -->
                    <div class="lg:col-span-7 space-y-3.5">
                        
                        <!-- Entidad / Perfil IPS Destino (Tarjetas Dinámicas con Logos) -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                                    <span class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-sm text-teal-600 dark:text-teal-400">domain</span>
                                        <span>Asignar a Entidad / Perfil IPS *</span>
                                    </span>
                                </label>
                                <span class="text-[10px] text-slate-400 font-semibold">Seleccione la institución</span>
                            </div>

                            <input type="hidden" id="add_entidad_id" value="<?php echo htmlspecialchars($entidadActivaKey); ?>" />

                            <!-- Listado de Recuadros Interactivos con Logos Amplios y Legibles -->
                            <div class="space-y-2.5 max-h-[260px] overflow-y-auto pr-1">
                                <!-- Tarjeta Hernán Ocazionez (IPS Matriz) -->
                                <div onclick="seleccionarEntidadModalCrear('PROPIO')" id="card_add_crear_PROPIO"
                                    class="entidad-card-crear cursor-pointer p-3 sm:p-3.5 rounded-2xl border-2 transition-all flex items-center justify-between gap-3 relative border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800/80 hover:border-slate-300">
                                    <div class="flex items-center gap-3 min-w-0 flex-1">
                                        <div class="w-24 sm:w-28 h-16 rounded-xl bg-slate-950 p-1.5 flex items-center justify-center shrink-0 border border-slate-700/80 ring-1 ring-white/10 shadow-sm overflow-hidden">
                                            <img src="assets/img/Ho_Fondo_Osc.png" alt="Hernán Ocazionez" class="max-w-full max-h-full object-contain" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                                <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white truncate">
                                                    Hernán Ocazionez y Cía S.A.S.
                                                </span>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 shrink-0">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                    Propio / Interno
                                                </span>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 shrink-0">
                                                    <span class="material-symbols-outlined text-[10px]">groups</span>
                                                    <?php echo $countPropios; ?> <?php echo $countPropios === 1 ? 'médico' : 'médicos'; ?>
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                                                Sede Principal LIHO • Radiología e Imágenes Diagnósticas
                                            </p>
                                        </div>
                                    </div>
                                    <div class="radio-indicator-crear shrink-0">
                                        <div class="w-5 h-5 rounded-full border-2 border-slate-300 dark:border-slate-600"></div>
                                    </div>
                                </div>

                                <!-- Tarjetas Entidades Externas -->
                                <?php foreach ($entidadesList as $eKey => $ent): ?>
                                    <?php if ($eKey === 'PROPIO') continue; ?>
                                    <?php $cEnt = $entidadesCounts[strval($ent['id'])] ?? 0; ?>
                                    <div onclick="seleccionarEntidadModalCrear('<?php echo $eKey; ?>')" id="card_add_crear_<?php echo $eKey; ?>"
                                        class="entidad-card-crear cursor-pointer p-3 sm:p-3.5 rounded-2xl border-2 transition-all flex items-center justify-between gap-3 relative border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800/80 hover:border-slate-300">
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <?php if (!empty($ent['logo']) && file_exists(__DIR__ . '/' . $ent['logo'])): ?>
                                                <div class="w-24 sm:w-28 h-16 rounded-xl bg-white p-2 flex items-center justify-center shrink-0 border border-slate-200 shadow-sm overflow-hidden">
                                                    <img src="<?php echo htmlspecialchars($ent['logo']); ?>" alt="Logo" class="max-w-full max-h-full object-contain" />
                                                </div>
                                            <?php else: ?>
                                                <div class="w-24 sm:w-28 h-16 rounded-xl bg-white p-1.5 flex flex-col items-center justify-center shrink-0 border border-slate-200 shadow-sm text-indigo-600 gap-0.5">
                                                    <span class="material-symbols-outlined text-xl">domain</span>
                                                    <span class="text-[8px] font-bold text-slate-400">IPS Externa</span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                                    <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white truncate">
                                                        <?php echo htmlspecialchars($ent['nombre']); ?>
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 shrink-0">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                                        IPS Externa
                                                    </span>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 shrink-0">
                                                        <span class="material-symbols-outlined text-[10px]">groups</span>
                                                        <?php echo $cEnt; ?> <?php echo $cEnt === 1 ? 'médico' : 'médicos'; ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400 flex-wrap">
                                                    <span class="font-mono font-bold text-slate-600 dark:text-slate-300">NIT: <?php echo htmlspecialchars($ent['nit']); ?></span>
                                                    <?php if (!empty($ent['subtitulo'])): ?>
                                                        <span>• <?php echo htmlspecialchars($ent['subtitulo']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="radio-indicator-crear shrink-0">
                                            <div class="w-5 h-5 rounded-full border-2 border-slate-300 dark:border-slate-600"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-[10px] text-slate-400">La modalidad quedará asignada exclusivamente a la entidad seleccionada</p>
                        </div>

                        <!-- Nombre de la Modalidad -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Nombre de la Modalidad *</label>
                            <input type="text" id="add_nombre" required placeholder="Ej: Pago Dinámico, Biopsias Guiadas, Lectura..." 
                                   oninput="autoGenerarCodigo(this.value); actualizarPreviewInsignia('add');"
                                   class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-emerald-500/30 outline-none" />
                        </div>

                        <!-- Código Identificador -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Código / Tipo Identificador *</label>
                                <span class="text-[10px] text-slate-400">Mayúsculas sin espacios</span>
                            </div>
                            <input type="text" id="add_tipo" required placeholder="PAGO_DINAMICO" 
                                   class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-mono font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-emerald-500/30 outline-none uppercase" />
                        </div>

                        <!-- Conmutador: Porcentaje (%) vs Valor Fijo ($) -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                                    Cálculo de Liquidación *
                                </label>
                                <span class="text-[10px] text-slate-400 font-medium">Elija porcentaje o monto fijo</span>
                            </div>

                            <!-- Selector Segmentado 2 Botones -->
                            <div class="grid grid-cols-2 gap-2 p-1 rounded-xl bg-slate-200/80 dark:bg-slate-700/70">
                                <button type="button" id="add_btn_tipo_pct" onclick="cambiarTipoCalculo('add', 'PORCENTAJE')"
                                        class="py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 font-bold text-xs transition-all bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 shadow-2xs">
                                    <span class="material-symbols-outlined text-sm">percent</span>
                                    <span>Porcentaje (%)</span>
                                </button>
                                <button type="button" id="add_btn_tipo_fijo" onclick="cambiarTipoCalculo('add', 'VALOR_FIJO')"
                                        class="py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 font-bold text-xs transition-all text-slate-500 hover:text-slate-700 dark:hover:text-slate-200">
                                    <span class="material-symbols-outlined text-sm">payments</span>
                                    <span>Valor Fijo ($)</span>
                                </button>
                            </div>
                            <input type="hidden" id="add_tipo_calculo" value="PORCENTAJE" />

                            <!-- Caja Porcentaje -->
                            <div id="add_box_porcentaje" class="space-y-1">
                                <label class="block text-[10px] font-bold text-emerald-800 dark:text-emerald-300 uppercase tracking-wider">Porcentaje de Pago (%) *</label>
                                <div class="relative">
                                    <input type="number" id="add_porcentaje" placeholder="30.00" value="30.00" step="0.01" min="0" max="100" 
                                           oninput="actualizarPreviewInsignia('add')"
                                           class="w-full bg-white dark:bg-slate-900 px-3.5 py-2.5 rounded-xl border border-emerald-400 dark:border-emerald-600 font-black text-emerald-700 dark:text-emerald-300 focus:ring-2 focus:ring-emerald-500/30 outline-none text-sm" />
                                    <span class="absolute right-3.5 top-1/2 -translate-y-1/2 font-black text-emerald-600 text-sm">%</span>
                                </div>
                                <p class="text-[10px] text-slate-400">Se liquidará calculando este porcentaje sobre el valor del examen.</p>
                            </div>

                            <!-- Caja Valor Fijo -->
                            <div id="add_box_valor_fijo" class="hidden space-y-1">
                                <label class="block text-[10px] font-bold text-blue-800 dark:text-blue-300 uppercase tracking-wider">Valor Fijo a Pagar por Cada Examen ($ COP) *</label>
                                <div class="relative">
                                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-black text-blue-600 text-sm">$</span>
                                    <input type="text" inputmode="numeric" id="add_valor_fijo" placeholder="50.000" value="50.000" 
                                           oninput="formatearMilesInput(this); actualizarPreviewInsignia('add');"
                                           class="w-full bg-white dark:bg-slate-900 pl-8 pr-3.5 py-2.5 rounded-xl border border-blue-400 dark:border-blue-600 font-black text-blue-700 dark:text-blue-300 focus:ring-2 focus:ring-blue-500/30 outline-none text-sm font-outfit" />
                                </div>
                                <p class="text-[10px] text-slate-400">En liquidaciones se pagará exactamente este valor por cada examen calificado.</p>
                            </div>
                        </div>

                        <!-- Descripción -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Descripción del Alcance</label>
                            <textarea id="add_descripcion" rows="2" placeholder="Detalle las condiciones o tipos de exámenes a los que aplica..." 
                                   class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 font-medium text-primary dark:text-slate-100 focus:ring-2 focus:ring-emerald-500/30 outline-none resize-none"></textarea>
                        </div>
                    </div>

                    <!-- Columna Derecha: Selector de Color Dinámico y Previsualización -->
                    <div class="lg:col-span-5 space-y-3.5">
                        <div class="p-3.5 rounded-2xl bg-slate-50/90 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm text-emerald-500">palette</span>
                                    <span class="text-[11px] font-extrabold uppercase text-slate-700 dark:text-slate-200 tracking-wider">Color Dinámico</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span id="add_color_name_badge" class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-slate-200/80 dark:bg-slate-700/80 text-slate-700 dark:text-slate-200">
                                        Verde Esmeralda
                                    </span>
                                    <button type="button" onclick="activarGotero('add')" class="px-2 py-1 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 hover:border-emerald-500 text-slate-700 dark:text-slate-200 text-[10px] font-bold flex items-center gap-1 transition-all shadow-2xs cursor-pointer" title="Capturar color con el gotero">
                                        <span class="material-symbols-outlined text-xs text-emerald-500">colorize</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Espectro 2D + Barra Vertical -->
                            <div class="flex gap-2 items-stretch">
                                <div class="relative flex-1 h-36 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 select-none shadow-inner cursor-crosshair">
                                    <canvas id="add_canvas" class="w-full h-full block"></canvas>
                                    <div id="add_cursor" class="absolute w-4 h-4 rounded-full border-2 border-white shadow-[0_2px_8px_rgba(0,0,0,0.6)] ring-1 ring-black/40 pointer-events-none -translate-x-1/2 -translate-y-1/2 z-10"></div>
                                    <div id="add_tooltip" class="absolute pointer-events-none -translate-x-1/2 -translate-y-full mb-2 px-2 py-0.5 rounded-md bg-slate-950/90 text-white text-[10px] font-bold shadow-xl border border-white/20 whitespace-nowrap z-20">Verde</div>
                                </div>
                                <div class="w-10 rounded-xl border border-slate-200 dark:border-slate-700 relative overflow-hidden shadow-inner flex flex-col justify-end p-1 transition-colors" id="add_preview_bar" style="background-color: #10B981;">
                                    <div class="w-full h-full rounded-md bg-gradient-to-b from-white/25 to-transparent pointer-events-none"></div>
                                </div>
                            </div>

                            <!-- Luminosidad -->
                            <div class="space-y-1">
                                <div class="flex justify-between items-center text-[10px] font-bold text-slate-400">
                                    <span>Brillo</span>
                                    <span id="add_brightness_val">100%</span>
                                </div>
                                <div class="relative w-full h-4 flex items-center select-none cursor-pointer" id="add_slider_box">
                                    <div id="add_slider_track" class="w-full h-2 rounded-full border border-slate-300/80 dark:border-slate-700" style="background: linear-gradient(to right, #000000 0%, #10b981 100%);"></div>
                                    <div id="add_slider_thumb" class="absolute top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-white border-2 border-slate-800 dark:border-white shadow-md pointer-events-none -translate-x-1/2"></div>
                                </div>
                            </div>

                            <!-- Controles HEX/RGB -->
                            <div class="pt-2 border-t border-slate-200/70 dark:border-slate-700/70 space-y-2">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="inline-flex p-0.5 rounded-lg bg-slate-200/80 dark:bg-slate-700/80 text-[10px] font-extrabold text-slate-600 dark:text-slate-300">
                                        <button type="button" onclick="cambiarModoColor('add', 'RGB')" id="add_tab_rgb" class="px-2 py-0.5 rounded-md bg-white dark:bg-slate-800 shadow-2xs text-primary dark:text-white font-bold">RGB</button>
                                        <button type="button" onclick="cambiarModoColor('add', 'HEX')" id="add_tab_hex" class="px-2 py-0.5 rounded-md text-slate-500 hover:text-slate-800 dark:hover:text-white">HEX</button>
                                    </div>

                                    <div class="flex items-center gap-1 bg-white dark:bg-slate-800 px-2.5 py-1 rounded-xl border border-slate-200 dark:border-slate-700 font-mono font-bold text-xs">
                                        <span class="text-slate-400 font-bold">#</span>
                                        <input type="text" id="add_hex_input" value="10B981" maxlength="6" 
                                               oninput="onInputHexDirecto('add', this.value)"
                                               class="w-16 bg-transparent text-primary dark:text-white font-mono font-black uppercase outline-none text-xs" />
                                        <button type="button" onclick="copiarHexAlClipboard('add')" class="text-slate-400 hover:text-emerald-600 p-0.5" title="Copiar HEX">
                                            <span class="material-symbols-outlined text-xs">content_copy</span>
                                        </button>
                                    </div>
                                </div>

                                <div id="add_rgb_fields" class="grid grid-cols-3 gap-1.5">
                                    <div class="bg-white dark:bg-slate-800 px-2 py-1 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                        <input type="number" id="add_r_input" min="0" max="255" value="16" oninput="onInputRgbDirecto('add')" class="w-10 bg-transparent font-mono font-bold text-xs text-primary dark:text-white outline-none" />
                                        <span class="text-[9px] font-extrabold text-slate-400">R</span>
                                    </div>
                                    <div class="bg-white dark:bg-slate-800 px-2 py-1 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                        <input type="number" id="add_g_input" min="0" max="255" value="185" oninput="onInputRgbDirecto('add')" class="w-10 bg-transparent font-mono font-bold text-xs text-primary dark:text-white outline-none" />
                                        <span class="text-[9px] font-extrabold text-slate-400">G</span>
                                    </div>
                                    <div class="bg-white dark:bg-slate-800 px-2 py-1 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                        <input type="number" id="add_b_input" min="0" max="255" value="129" oninput="onInputRgbDirecto('add')" class="w-10 bg-transparent font-mono font-bold text-xs text-primary dark:text-white outline-none" />
                                        <span class="text-[9px] font-extrabold text-slate-400">B</span>
                                    </div>
                                </div>

                                <!-- Muestras Rápidas -->
                                <div class="flex items-center gap-1.5 flex-wrap pt-1">
                                    <button type="button" onclick="setColorDirecto('add', '#10B981')" class="w-4 h-4 rounded-full bg-[#10B981] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('add', '#3B82F6')" class="w-4 h-4 rounded-full bg-[#3B82F6] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('add', '#6366F1')" class="w-4 h-4 rounded-full bg-[#6366F1] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('add', '#A855F7')" class="w-4 h-4 rounded-full bg-[#A855F7] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('add', '#EC4899')" class="w-4 h-4 rounded-full bg-[#EC4899] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('add', '#F59E0B')" class="w-4 h-4 rounded-full bg-[#F59E0B] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                </div>
                            </div>
                            <input type="hidden" id="add_color" name="color" value="#10B981" />
                        </div>

                        <!-- Recuadro Vista Previa en Vivo -->
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-2">
                            <span class="block text-[10px] font-extrabold uppercase text-slate-400 tracking-wider">Vista Previa de la Modalidad</span>
                            <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                                <span id="add_preview_badge" class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-black border transition-all" style="background-color: rgba(16, 185, 129, 0.16); color: #10b981; border-color: rgba(16, 185, 129, 0.35);">
                                    Nueva Modalidad
                                </span>
                                <span id="add_preview_val_badge" class="font-black text-xs font-outfit px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300">
                                    30.00%
                                </span>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalCrear()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarCrear" class="px-6 py-2.5 rounded-xl text-xs font-extrabold bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-md shadow-emerald-600/20 transition-all cursor-pointer">
                        Guardar Modalidad
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- Modal Editar Modalidad -->
    <div id="modalEditar" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4 py-6 overflow-y-auto">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-4xl lg:max-w-5xl w-full p-6 sm:p-7 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300 animate-card-entry max-h-[92vh] overflow-y-auto">
            
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 dark:bg-amber-950/80 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold shadow-xs">
                        <span class="material-symbols-outlined text-xl">edit_note</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Actualizar Modalidad</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500">Modifique el nombre, tipo de cálculo (porcentaje / valor fijo) y parámetros</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalEditar()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formEditar" onsubmit="guardarEdicionRegla(event)" class="space-y-4 text-xs">
                <input type="hidden" id="edit_id" value="" />
                <input type="hidden" id="edit_entidad_id" value="" />

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    <!-- Columna Izquierda -->
                    <div class="lg:col-span-7 space-y-3.5">
                        
                        <!-- Entidad Asociada (Card Visual Elegante) -->
                        <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-1.5">
                            <div class="flex items-center justify-between">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                                    <span class="flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-sm text-amber-500">domain</span>
                                        <span>Perfil de Entidad / IPS Asociada</span>
                                    </span>
                                </label>
                                <span class="text-[10px] font-bold text-slate-400">IPS a la que pertenece</span>
                            </div>
                            <div id="edit_entidad_card_container">
                                <!-- Se renderiza dinámicamente con logo, badge y datos de la IPS -->
                            </div>
                        </div>

                        <!-- Nombre de la Modalidad -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Nombre de la Modalidad *</label>
                            <input type="text" id="edit_nombre" required 
                                   oninput="actualizarPreviewInsignia('edit')"
                                   class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-amber-500/30 outline-none" />
                        </div>

                        <!-- Código Identificador -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Código / Tipo</label>
                                <span id="edit_tipo_hint" class="text-[10px] text-slate-400 font-medium"></span>
                            </div>
                            <input type="text" id="edit_tipo" required 
                                   class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-mono font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-amber-500/30 outline-none uppercase" />
                        </div>

                        <!-- Conmutador: Porcentaje (%) vs Valor Fijo ($) -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                                    Cálculo de Liquidación *
                                </label>
                                <span class="text-[10px] text-slate-400 font-medium">Elija porcentaje o monto fijo</span>
                            </div>

                            <!-- Selector Segmentado 2 Botones -->
                            <div class="grid grid-cols-2 gap-2 p-1 rounded-xl bg-slate-200/80 dark:bg-slate-700/70">
                                <button type="button" id="edit_btn_tipo_pct" onclick="cambiarTipoCalculo('edit', 'PORCENTAJE')"
                                        class="py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 font-bold text-xs transition-all bg-white dark:bg-slate-800 text-amber-600 dark:text-amber-400 shadow-2xs">
                                    <span class="material-symbols-outlined text-sm">percent</span>
                                    <span>Porcentaje (%)</span>
                                </button>
                                <button type="button" id="edit_btn_tipo_fijo" onclick="cambiarTipoCalculo('edit', 'VALOR_FIJO')"
                                        class="py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 font-bold text-xs transition-all text-slate-500 hover:text-slate-700 dark:hover:text-slate-200">
                                    <span class="material-symbols-outlined text-sm">payments</span>
                                    <span>Valor Fijo ($)</span>
                                </button>
                            </div>
                            <input type="hidden" id="edit_tipo_calculo" value="PORCENTAJE" />

                            <!-- Caja Porcentaje -->
                            <div id="edit_box_porcentaje" class="space-y-1">
                                <label class="block text-[10px] font-bold text-amber-800 dark:text-amber-300 uppercase tracking-wider">Porcentaje de Pago (%) *</label>
                                <div class="relative">
                                    <input type="number" id="edit_porcentaje" placeholder="30.00" step="0.01" min="0" max="100" 
                                           oninput="actualizarPreviewInsignia('edit')"
                                           class="w-full bg-white dark:bg-slate-900 px-3.5 py-2.5 rounded-xl border border-amber-400 dark:border-amber-600 font-black text-amber-700 dark:text-amber-300 focus:ring-2 focus:ring-amber-500/30 outline-none text-sm" />
                                    <span class="absolute right-3.5 top-1/2 -translate-y-1/2 font-black text-amber-600 text-sm">%</span>
                                </div>
                                <p class="text-[10px] text-slate-400">Se liquidará calculando este porcentaje sobre el valor del examen.</p>
                            </div>

                            <!-- Caja Valor Fijo -->
                            <div id="edit_box_valor_fijo" class="hidden space-y-1">
                                <label class="block text-[10px] font-bold text-blue-800 dark:text-blue-300 uppercase tracking-wider">Valor Fijo a Pagar por Cada Examen ($ COP) *</label>
                                <div class="relative">
                                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-black text-blue-600 text-sm">$</span>
                                    <input type="text" inputmode="numeric" id="edit_valor_fijo" placeholder="50.000" 
                                           oninput="formatearMilesInput(this); actualizarPreviewInsignia('edit');"
                                           class="w-full bg-white dark:bg-slate-900 pl-8 pr-3.5 py-2.5 rounded-xl border border-blue-400 dark:border-blue-600 font-black text-blue-700 dark:text-blue-300 focus:ring-2 focus:ring-blue-500/30 outline-none text-sm font-outfit" />
                                </div>
                                <p class="text-[10px] text-slate-400">En liquidaciones se pagará exactamente este valor por cada examen calificado.</p>
                            </div>
                        </div>

                        <!-- Descripción -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Descripción</label>
                            <textarea id="edit_descripcion" rows="2" 
                                   class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 font-medium text-primary dark:text-slate-100 focus:ring-2 focus:ring-amber-500/30 outline-none resize-none"></textarea>
                        </div>

                        <!-- Estado -->
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Estado</label>
                            <select id="edit_estado" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-amber-500/30 outline-none">
                                <option value="1">Activo (Habilitada para liquidación)</option>
                                <option value="0">Inactivo (Deshabilitada)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Columna Derecha -->
                    <div class="lg:col-span-5 space-y-3.5">
                        <div class="p-3.5 rounded-2xl bg-slate-50/90 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm text-amber-500">palette</span>
                                    <span class="text-[11px] font-extrabold uppercase text-slate-700 dark:text-slate-200 tracking-wider">Color Dinámico</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span id="edit_color_name_badge" class="px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-slate-200/80 dark:bg-slate-700/80 text-slate-700 dark:text-slate-200">
                                        Verde Esmeralda
                                    </span>
                                    <button type="button" onclick="activarGotero('edit')" class="px-2 py-1 rounded-lg bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 hover:border-amber-500 text-slate-700 dark:text-slate-200 text-[10px] font-bold flex items-center gap-1 transition-all shadow-2xs cursor-pointer" title="Capturar color con el gotero">
                                        <span class="material-symbols-outlined text-xs text-amber-500">colorize</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Espectro 2D + Barra Vertical -->
                            <div class="flex gap-2 items-stretch">
                                <div class="relative flex-1 h-36 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 select-none shadow-inner cursor-crosshair">
                                    <canvas id="edit_canvas" class="w-full h-full block"></canvas>
                                    <div id="edit_cursor" class="absolute w-4 h-4 rounded-full border-2 border-white shadow-[0_2px_8px_rgba(0,0,0,0.6)] ring-1 ring-black/40 pointer-events-none -translate-x-1/2 -translate-y-1/2 z-10"></div>
                                    <div id="edit_tooltip" class="absolute pointer-events-none -translate-x-1/2 -translate-y-full mb-2 px-2 py-0.5 rounded-md bg-slate-950/90 text-white text-[10px] font-bold shadow-xl border border-white/20 whitespace-nowrap z-20">Verde</div>
                                </div>
                                <div class="w-10 rounded-xl border border-slate-200 dark:border-slate-700 relative overflow-hidden shadow-inner flex flex-col justify-end p-1 transition-colors" id="edit_preview_bar" style="background-color: #10B981;">
                                    <div class="w-full h-full rounded-md bg-gradient-to-b from-white/25 to-transparent pointer-events-none"></div>
                                </div>
                            </div>

                            <!-- Luminosidad -->
                            <div class="space-y-1">
                                <div class="flex justify-between items-center text-[10px] font-bold text-slate-400">
                                    <span>Brillo</span>
                                    <span id="edit_brightness_val">100%</span>
                                </div>
                                <div class="relative w-full h-4 flex items-center select-none cursor-pointer" id="edit_slider_box">
                                    <div id="edit_slider_track" class="w-full h-2 rounded-full border border-slate-300/80 dark:border-slate-700" style="background: linear-gradient(to right, #000000 0%, #10b981 100%);"></div>
                                    <div id="edit_slider_thumb" class="absolute top-1/2 -translate-y-1/2 w-4 h-4 rounded-full bg-white border-2 border-slate-800 dark:border-white shadow-md pointer-events-none -translate-x-1/2"></div>
                                </div>
                            </div>

                            <!-- Controles HEX/RGB -->
                            <div class="pt-2 border-t border-slate-200/70 dark:border-slate-700/70 space-y-2">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="inline-flex p-0.5 rounded-lg bg-slate-200/80 dark:bg-slate-700/80 text-[10px] font-extrabold text-slate-600 dark:text-slate-300">
                                        <button type="button" onclick="cambiarModoColor('edit', 'RGB')" id="edit_tab_rgb" class="px-2 py-0.5 rounded-md bg-white dark:bg-slate-800 shadow-2xs text-primary dark:text-white font-bold">RGB</button>
                                        <button type="button" onclick="cambiarModoColor('edit', 'HEX')" id="edit_tab_hex" class="px-2 py-0.5 rounded-md text-slate-500 hover:text-slate-800 dark:hover:text-white">HEX</button>
                                    </div>

                                    <div class="flex items-center gap-1 bg-white dark:bg-slate-800 px-2.5 py-1 rounded-xl border border-slate-200 dark:border-slate-700 font-mono font-bold text-xs">
                                        <span class="text-slate-400 font-bold">#</span>
                                        <input type="text" id="edit_hex_input" value="10B981" maxlength="6" 
                                               oninput="onInputHexDirecto('edit', this.value)"
                                               class="w-16 bg-transparent text-primary dark:text-white font-mono font-black uppercase outline-none text-xs" />
                                        <button type="button" onclick="copiarHexAlClipboard('edit')" class="text-slate-400 hover:text-amber-600 p-0.5" title="Copiar HEX">
                                            <span class="material-symbols-outlined text-xs">content_copy</span>
                                        </button>
                                    </div>
                                </div>

                                <div id="edit_rgb_fields" class="grid grid-cols-3 gap-1.5">
                                    <div class="bg-white dark:bg-slate-800 px-2 py-1 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                        <input type="number" id="edit_r_input" min="0" max="255" value="16" oninput="onInputRgbDirecto('edit')" class="w-10 bg-transparent font-mono font-bold text-xs text-primary dark:text-white outline-none" />
                                        <span class="text-[9px] font-extrabold text-slate-400">R</span>
                                    </div>
                                    <div class="bg-white dark:bg-slate-800 px-2 py-1 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                        <input type="number" id="edit_g_input" min="0" max="255" value="185" oninput="onInputRgbDirecto('edit')" class="w-10 bg-transparent font-mono font-bold text-xs text-primary dark:text-white outline-none" />
                                        <span class="text-[9px] font-extrabold text-slate-400">G</span>
                                    </div>
                                    <div class="bg-white dark:bg-slate-800 px-2 py-1 rounded-xl border border-slate-200 dark:border-slate-700 flex items-center justify-between">
                                        <input type="number" id="edit_b_input" min="0" max="255" value="129" oninput="onInputRgbDirecto('edit')" class="w-10 bg-transparent font-mono font-bold text-xs text-primary dark:text-white outline-none" />
                                        <span class="text-[9px] font-extrabold text-slate-400">B</span>
                                    </div>
                                </div>

                                <!-- Muestras Rápidas -->
                                <div class="flex items-center gap-1.5 flex-wrap pt-1">
                                    <button type="button" onclick="setColorDirecto('edit', '#10B981')" class="w-4 h-4 rounded-full bg-[#10B981] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('edit', '#3B82F6')" class="w-4 h-4 rounded-full bg-[#3B82F6] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('edit', '#6366F1')" class="w-4 h-4 rounded-full bg-[#6366F1] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('edit', '#A855F7')" class="w-4 h-4 rounded-full bg-[#A855F7] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('edit', '#EC4899')" class="w-4 h-4 rounded-full bg-[#EC4899] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                    <button type="button" onclick="setColorDirecto('edit', '#F59E0B')" class="w-4 h-4 rounded-full bg-[#F59E0B] hover:scale-125 transition-transform border border-white dark:border-slate-900 shadow-2xs"></button>
                                </div>
                            </div>
                            <input type="hidden" id="edit_color" name="color" value="#10B981" />
                        </div>

                        <!-- Recuadro Vista Previa en Vivo -->
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 space-y-2">
                            <span class="block text-[10px] font-extrabold uppercase text-slate-400 tracking-wider">Vista Previa de la Modalidad</span>
                            <div class="flex items-center justify-between gap-2 p-2.5 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                                <span id="edit_preview_badge" class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-black border transition-all" style="background-color: rgba(16, 185, 129, 0.16); color: #10b981; border-color: rgba(16, 185, 129, 0.35);">
                                    Modalidad
                                </span>
                                <span id="edit_preview_val_badge" class="font-black text-xs font-outfit px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-950/80 text-amber-700 dark:text-amber-300">
                                    30.00%
                                </span>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalEditar()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarEditar" class="px-6 py-2.5 rounded-xl text-xs font-extrabold bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white shadow-md shadow-amber-500/20 transition-all cursor-pointer">
                        Actualizar Modalidad
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- Modal Selección Obligatoria / Entrada de Entidad (Idéntico a examenes_medicos.php) -->
    <div id="modalSeleccionEntidad" 
         class="fixed inset-0 z-50 <?php echo $mostrarModalSeleccion ? 'flex' : 'hidden'; ?> items-center justify-center p-4 bg-slate-950/85 backdrop-blur-md transition-all duration-300"
         <?php if ($mostrarModalSeleccion): ?>data-blocking="true"<?php endif; ?>>
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
                                Selección de Entidad para Modalidades y Porcentajes
                            </h3>
                            <span class="px-2.5 py-0.5 text-[10px] font-black uppercase rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800/60 tracking-wider">
                                Paso Requerido
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Elija la IPS o Entidad médica sobre la cual desea consultar y administrar las modalidades y porcentajes de liquidación.
                        </p>
                    </div>
                </div>

                <?php if (!$mostrarModalSeleccion): ?>
                    <button type="button" onclick="cerrarModalSeleccionarEntidad()" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer" title="Cerrar ventana">
                        <span class="material-symbols-outlined text-2xl">close</span>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Modal Body: Cards Grid -->
            <div class="p-6 overflow-y-auto space-y-6 flex-1">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- Card 1: Hernán Ocazionez (Entidad Propia / Matriz) -->
                    <?php 
                        $statsPropio = $statsPorEntidad['PROPIO'] ?? ['total' => 0, 'promedio' => 0.00];
                        $isPropioActivo = ($entidadActivaKey === 'PROPIO');
                    ?>
                    <div onclick="confirmarSeleccionEntidad('PROPIO', 'HERNÁN OCAZIONEZ Y CÍA S.A.S.', 'assets/img/hologo.png', '800.149.695-1', 'teal')"
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
                                    <span class="material-symbols-outlined text-sm text-teal-600">category</span>
                                    <span><strong><?php echo $statsPropio['total']; ?></strong> modalidades</span>
                                </div>
                                <span class="text-[11px] font-medium text-slate-400">NIT: 800.149.695-1</span>
                            </div>
                        </div>

                        <div class="mt-4 pt-3">
                            <button type="button" class="w-full py-2.5 px-4 rounded-xl text-xs font-bold transition-all duration-200 flex items-center justify-center gap-2
                                <?php echo $isPropioActivo ? 'bg-teal-600 text-white shadow-md shadow-teal-600/20' : 'bg-slate-100 dark:bg-slate-700/60 text-slate-700 dark:text-slate-200 group-hover:bg-teal-600 group-hover:text-white'; ?>">
                                <span><?php echo $isPropioActivo ? 'Entidad Activa (Continuar)' : 'Seleccionar Esta Entidad'; ?></span>
                                <span class="material-symbols-outlined text-base">arrow_forward</span>
                            </button>
                        </div>
                    </div>

                    <!-- Cards 2+: External Entities -->
                    <?php 
                    foreach ($entidadesList as $eKey => $ent): 
                        if ($eKey === 'PROPIO' || (string)$eKey === '4' || (int)($ent['id'] ?? 0) === 4 || stripos($ent['nombre'] ?? '', 'HERNAN') !== false) continue;
                        $isEntActiva = ($entidadActivaKey === (string)$eKey);
                        $statsEnt = $statsPorEntidad[$eKey] ?? ['total' => 0, 'promedio' => 0.00];
                        $logoPath = !empty($ent['logo']) ? $ent['logo'] : '';
                        $entColor = !empty($ent['color_tema']) ? strtolower(trim($ent['color_tema'])) : 'indigo';
                        $paletaEnt = obtenerPaletaEntidad($entColor, $ent['id']);
                    ?>
                        <div onclick="confirmarSeleccionEntidad('<?php echo $ent['id']; ?>', '<?php echo addslashes($ent['nombre']); ?>', '<?php echo addslashes($logoPath); ?>', '<?php echo addslashes($ent['nit']); ?>', '<?php echo $entColor; ?>')"
                            class="group relative flex flex-col justify-between p-5 rounded-2xl border-2 transition-all duration-200 cursor-pointer text-left
                            <?php echo $isEntActiva ? $paletaEnt['card_border'] . ' ' . $paletaEnt['card_bg'] . ' ' . $paletaEnt['card_shadow'] : 'border-slate-200/80 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 bg-white dark:bg-slate-800/60 hover:shadow-md'; ?>">
                            
                            <div class="space-y-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="w-16 h-16 rounded-2xl bg-white border border-slate-200 shadow-sm p-1.5 flex items-center justify-center group-hover:scale-105 transition-transform duration-200 shrink-0 overflow-hidden">
                                        <?php if (!empty($logoPath)): ?>
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
                                        <span class="material-symbols-outlined text-sm <?php echo $paletaEnt['icon_color']; ?>">category</span>
                                        <span><strong><?php echo $statsEnt['total']; ?></strong> modalidades</span>
                                    </div>
                                    <span class="text-[11px] font-medium text-slate-400">NIT: <?php echo htmlspecialchars($ent['nit']); ?></span>
                                </div>
                            </div>

                            <div class="mt-4 pt-3">
                                <button type="button" class="w-full py-2.5 px-4 rounded-xl text-xs font-bold transition-all duration-200 flex items-center justify-center gap-2
                                    <?php echo $isEntActiva ? $paletaEnt['card_btn'] : 'bg-slate-100 dark:bg-slate-700/60 text-slate-700 dark:text-slate-200'; ?>">
                                    <span><?php echo $isEntActiva ? 'Entidad Activa (Continuar)' : 'Seleccionar Esta Entidad'; ?></span>
                                    <span class="material-symbols-outlined text-base">arrow_forward</span>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>

                <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-800 dark:text-amber-300 text-xs flex items-center gap-3">
                    <span class="material-symbols-outlined text-xl text-amber-600 shrink-0">info</span>
                    <p>
                        <strong>Nota importante:</strong> Cada entidad médica administra sus propias modalidades y porcentajes de pago de forma independiente y autónoma para el proceso de liquidaciones.
                    </p>
                </div>

            </div>

        </div>
    </div>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <script>
    // SweetAlert2 estilizado
    const SwalCustom = Swal.mixin({
        customClass: {
            popup: 'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl p-6 text-slate-800 dark:text-white',
            title: 'text-lg font-black font-outfit text-slate-900 dark:text-white',
            htmlContainer: 'text-xs font-medium text-slate-500 dark:text-slate-400',
            confirmButton: 'px-5 py-2.5 rounded-xl font-bold text-xs bg-emerald-600 hover:bg-emerald-500 text-white shadow-md cursor-pointer transition-all mx-1.5',
            cancelButton: 'px-5 py-2.5 rounded-xl font-bold text-xs bg-slate-200 hover:bg-slate-300 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer transition-all mx-1.5'
        },
        buttonsStyling: false
    });

    // Mapeo y Normalización de Colores (Nombres Tailwind -> Hexadecimal)
    const mapColoresNombrados = {
        'purple':  '#A855F7',
        'amber':   '#F59E0B',
        'emerald': '#10B981',
        'blue':    '#3B82F6',
        'indigo':  '#6366F1',
        'rose':    '#F43F5E',
        'teal':    '#14B8A6',
        'cyan':    '#06B6D4'
    };

    function hexToRgba(hex, alpha) {
        if (!hex) hex = '#10B981';
        hex = hex.replace('#', '');
        if (hex.length === 3) {
            hex = hex[0]+hex[0] + hex[1]+hex[1] + hex[2]+hex[2];
        }
        if (hex.length !== 6) hex = '10B981';
        const r = parseInt(hex.substring(0, 2), 16) || 16;
        const g = parseInt(hex.substring(2, 4), 16) || 185;
        const b = parseInt(hex.substring(4, 6), 16) || 129;
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function normalizarColorHex(color) {
        if (!color) return '#10B981';
        if (mapColoresNombrados[color.toLowerCase()]) {
            return mapColoresNombrados[color.toLowerCase()];
        }
        if (color.startsWith('#')) {
            return color.toUpperCase();
        }
        return '#10B981';
    }

    function actualizarPreviewInsignia(prefix) {
        const nombreInput = document.getElementById(prefix + '_nombre');
        const colorInput  = document.getElementById(prefix + '_color');
        const badge       = document.getElementById(prefix + '_preview_badge');
        const valBadge    = document.getElementById(prefix + '_preview_val_badge');
        const tipoCalc    = document.getElementById(prefix + '_tipo_calculo')?.value || 'PORCENTAJE';
        
        const nombre = (nombreInput && nombreInput.value.trim()) ? nombreInput.value.trim() : (prefix === 'add' ? 'Nueva Modalidad' : 'Modalidad');
        const hex = normalizarColorHex(colorInput ? colorInput.value : '#10B981');

        if (badge) {
            badge.textContent = nombre;
            badge.style.backgroundColor = hexToRgba(hex, 0.16);
            badge.style.color = hex;
            badge.style.borderColor = hexToRgba(hex, 0.35);
        }

        if (valBadge) {
            if (tipoCalc === 'VALOR_FIJO') {
                const rawVal = document.getElementById(prefix + '_valor_fijo')?.value;
                const valFijo = limpiarNumeroCOP(rawVal);
                valBadge.textContent = '$ ' + formatearNumeroCOP(valFijo);
                valBadge.className = 'font-black text-xs font-outfit px-2.5 py-1 rounded-lg bg-blue-100 dark:bg-blue-950/80 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800';
            } else {
                const pct = parseFloat(document.getElementById(prefix + '_porcentaje')?.value || 0);
                valBadge.textContent = pct.toFixed(2) + '%';
                valBadge.className = (prefix === 'add')
                    ? 'font-black text-xs font-outfit px-2 py-1 rounded-lg bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300'
                    : 'font-black text-xs font-outfit px-2 py-1 rounded-lg bg-amber-100 dark:bg-amber-950/80 text-amber-700 dark:text-amber-300';
            }
        }
    }

    // =========================================================================
    // MOTOR MATEMÁTICO Y DE CONTROL: SELECTOR DE COLOR INTERACTIVO MODERNO
    // =========================================================================
    const pickerStates = {
        add:  { x: 0.445, y: 0.086, b: 0.725, r: 16, g: 185, bVal: 129, hex: '#10B981', mode: 'RGB', isDraggingCanvas: false, isDraggingSlider: false, initialized: false },
        edit: { x: 0.445, y: 0.086, b: 0.725, r: 16, g: 185, bVal: 129, hex: '#10B981', mode: 'RGB', isDraggingCanvas: false, isDraggingSlider: false, initialized: false }
    };

    function hueToRgb(h) {
        h = ((h % 360) + 360) % 360;
        const c = 255;
        const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
        if (h < 60)  return [255, x, 0];
        if (h < 120) return [x, 255, 0];
        if (h < 180) return [0, 255, x];
        if (h < 240) return [0, x, 255];
        if (h < 300) return [x, 0, 255];
        return [255, 0, x];
    }

    function xybToRgb(x, y, b) {
        const hue = x * 360;
        const [rBase, gBase, bBase] = hueToRgb(hue);
        const rY = rBase + (255 - rBase) * y;
        const gY = gBase + (255 - gBase) * y;
        const bY = bBase + (255 - bBase) * y;
        const r = Math.round(rY * b);
        const g = Math.round(gY * b);
        const bl = Math.round(bY * b);
        return [Math.max(0, Math.min(255, r)), Math.max(0, Math.min(255, g)), Math.max(0, Math.min(255, bl))];
    }

    function rgbToXyb(r, g, b) {
        const M = Math.max(r, g, b);
        const m = Math.min(r, g, b);
        if (M === 0) return { x: 0.5, y: 0, b: 0 };
        const bFactor = M / 255.0;
        const rP = r / bFactor;
        const gP = g / bFactor;
        const bP = b / bFactor;
        const minP = Math.min(rP, gP, bP);
        const y = minP / 255.0;
        if (y >= 0.999) return { x: 0.5, y: 1, b: bFactor };
        const rB = (rP - 255 * y) / (1.0 - y);
        const gB = (gP - 255 * y) / (1.0 - y);
        const bB = (bP - 255 * y) / (1.0 - y);

        const mx = Math.max(rB, gB, bB);
        const mn = Math.min(rB, gB, bB);
        const d = mx - mn;
        let h = 0;
        if (d > 0.001) {
            if (Math.abs(mx - rB) < 0.01) {
                h = (gB - bB) / d;
            } else if (Math.abs(mx - gB) < 0.01) {
                h = (bB - rB) / d + 2;
            } else {
                h = (rB - gB) / d + 4;
            }
            h *= 60;
            if (h < 0) h += 360;
        }
        return { x: Math.max(0, Math.min(1, h / 360.0)), y: Math.max(0, Math.min(1, y)), b: Math.max(0, Math.min(1, bFactor)) };
    }

    function rgbToHex(r, g, b) {
        const toHex = v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0').toUpperCase();
        return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
    }

    function hexToRgb(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0] + hex[1]+hex[1] + hex[2]+hex[2];
        if (hex.length !== 6) return [16, 185, 129];
        const r = parseInt(hex.substring(0, 2), 16) || 0;
        const g = parseInt(hex.substring(2, 4), 16) || 0;
        const b = parseInt(hex.substring(4, 6), 16) || 0;
        return [r, g, b];
    }

    function rgbToHsl(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b);
        let h, s, l = (max + min) / 2;

        if (max === min) {
            h = s = 0;
        } else {
            const d = max - min;
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            switch (max) {
                case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                case g: h = (b - r) / d + 2; break;
                case b: h = (r - g) / d + 4; break;
            }
            h *= 60;
        }
        return [Math.round(h), Math.round(s * 100), Math.round(l * 100)];
    }

    // Detector inteligente del nombre del color con soporte de Sky blue
    function obtenerNombreColor(r, g, b) {
        const namedList = [
            { name: 'Sky blue', es: 'Azul Cielo', r: 89, g: 247, b: 255 },
            { name: 'Cyan', es: 'Cian Eléctrico', r: 6, g: 182, b: 212 },
            { name: 'Turquoise', es: 'Turquesa', r: 20, g: 184, b: 166 },
            { name: 'Emerald', es: 'Verde Esmeralda', r: 16, g: 185, b: 129 },
            { name: 'Lime', es: 'Verde Lima', r: 132, g: 204, b: 22 },
            { name: 'Amber', es: 'Ámbar Dorado', r: 245, g: 158, b: 11 },
            { name: 'Orange', es: 'Naranja Vivo', r: 249, g: 115, b: 22 },
            { name: 'Coral', es: 'Rojo Coral', r: 244, g: 63, b: 94 },
            { name: 'Ruby', es: 'Rojo Rubí', r: 239, g: 68, b: 68 },
            { name: 'Hot Pink', es: 'Rosa Neón', r: 236, g: 72, b: 153 },
            { name: 'Purple', es: 'Púrpura Vibrante', r: 168, g: 85, b: 247 },
            { name: 'Violet', es: 'Violeta Eléctrico', r: 139, g: 92, b: 246 },
            { name: 'Indigo', es: 'Índigo Profundo', r: 99, g: 102, b: 241 },
            { name: 'Royal Blue', es: 'Azul Real', r: 59, g: 130, b: 246 }
        ];

        let closest = null;
        let minDistance = Infinity;
        for (const item of namedList) {
            const dist = Math.sqrt((r - item.r)**2 + (g - item.g)**2 + (b - item.b)**2);
            if (dist < minDistance) {
                minDistance = dist;
                closest = item;
            }
        }

        if (minDistance < 38 && closest) {
            return `${closest.name} (${closest.es})`;
        }

        const [h, s, l] = rgbToHsl(r, g, b);
        if (l < 10) return 'Negro Puro';
        if (l > 95) return 'Blanco Puro';
        if (s < 10) return 'Gris Neutro';

        let hueName = '';
        if (h < 15 || h >= 345) hueName = 'Rojo';
        else if (h < 42) hueName = 'Naranja / Ámbar';
        else if (h < 68) hueName = 'Amarillo Dorado';
        else if (h < 150) hueName = 'Verde Esmeralda';
        else if (h < 190) hueName = 'Cian / Turquesa';
        else if (h < 225) hueName = 'Sky blue (Azul Cielo)';
        else if (h < 260) hueName = 'Azul Real';
        else if (h < 290) hueName = 'Índigo / Violeta';
        else if (h < 330) hueName = 'Púrpura';
        else hueName = 'Rosa / Magenta';

        if (l > 75) return `${hueName} Pastel`;
        if (l < 30) return `${hueName} Oscuro`;
        if (s > 80) return `${hueName} Vibrante`;
        return hueName;
    }

    function initCanvasSpectrum(prefix) {
        const canvas = document.getElementById(`${prefix}_canvas`);
        if (!canvas) return;
        const rect = canvas.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        const w = rect.width || 340;
        const h = rect.height || 176;

        canvas.width = w * dpr;
        canvas.height = h * dpr;
        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);

        // Gradiente Horizontal Arcoíris (Hue 0 a 360)
        const gradH = ctx.createLinearGradient(0, 0, w, 0);
        gradH.addColorStop(0/6, '#ff0000');
        gradH.addColorStop(1/6, '#ffff00');
        gradH.addColorStop(2/6, '#00ff00');
        gradH.addColorStop(3/6, '#00ffff');
        gradH.addColorStop(4/6, '#0000ff');
        gradH.addColorStop(5/6, '#ff00ff');
        gradH.addColorStop(6/6, '#ff0000');
        ctx.fillStyle = gradH;
        ctx.fillRect(0, 0, w, h);

        // Gradiente Vertical Atenuación a Blanco
        const gradV = ctx.createLinearGradient(0, 0, 0, h);
        gradV.addColorStop(0, 'rgba(255, 255, 255, 0)');
        gradV.addColorStop(1, 'rgba(255, 255, 255, 1)');
        ctx.fillStyle = gradV;
        ctx.fillRect(0, 0, w, h);
    }

    function attachColorEvents(prefix) {
        const state = pickerStates[prefix];
        if (state.initialized) return;
        state.initialized = true;

        const canvas = document.getElementById(`${prefix}_canvas`);
        const sliderBox = document.getElementById(`${prefix}_slider_box`);

        // Eventos del Espectro 2D
        if (canvas) {
            const handleCanvasPointer = (e) => {
                const rect = canvas.getBoundingClientRect();
                let x = (e.clientX - rect.left) / rect.width;
                let y = (e.clientY - rect.top) / rect.height;
                state.x = Math.max(0, Math.min(1, x));
                state.y = Math.max(0, Math.min(1, y));
                recalculateFromXYB(prefix);
            };

            canvas.addEventListener('pointerdown', e => {
                canvas.setPointerCapture(e.pointerId);
                state.isDraggingCanvas = true;
                document.getElementById(`${prefix}_tooltip`)?.classList.remove('hidden');
                handleCanvasPointer(e);
            });

            canvas.addEventListener('pointermove', e => {
                if (state.isDraggingCanvas) handleCanvasPointer(e);
            });

            const stopCanvasDrag = () => {
                state.isDraggingCanvas = false;
            };
            canvas.addEventListener('pointerup', stopCanvasDrag);
            canvas.addEventListener('pointercancel', stopCanvasDrag);
        }

        // Eventos del Slider de Brillo
        if (sliderBox) {
            const handleSliderPointer = (e) => {
                const rect = sliderBox.getBoundingClientRect();
                let b = (e.clientX - rect.left) / rect.width;
                state.b = Math.max(0, Math.min(1, b));
                recalculateFromXYB(prefix);
            };

            sliderBox.addEventListener('pointerdown', e => {
                sliderBox.setPointerCapture(e.pointerId);
                state.isDraggingSlider = true;
                handleSliderPointer(e);
            });

            sliderBox.addEventListener('pointermove', e => {
                if (state.isDraggingSlider) handleSliderPointer(e);
            });

            const stopSliderDrag = () => {
                state.isDraggingSlider = false;
            };
            sliderBox.addEventListener('pointerup', stopSliderDrag);
            sliderBox.addEventListener('pointercancel', stopSliderDrag);
        }
    }

    function recalculateFromXYB(prefix) {
        const state = pickerStates[prefix];
        const [r, g, bVal] = xybToRgb(state.x, state.y, state.b);
        state.r = r;
        state.g = g;
        state.bVal = bVal;
        state.hex = rgbToHex(r, g, bVal);
        updateColorUI(prefix);
    }

    function updateColorUI(prefix) {
        const state = pickerStates[prefix];
        const canvas = document.getElementById(`${prefix}_canvas`);
        const cursor = document.getElementById(`${prefix}_cursor`);
        const tooltip = document.getElementById(`${prefix}_tooltip`);

        // Posición del cursor en el canvas
        if (canvas && cursor) {
            const w = canvas.offsetWidth || 340;
            const h = canvas.offsetHeight || 176;
            const posX = state.x * w;
            const posY = state.y * h;
            cursor.style.left = `${posX}px`;
            cursor.style.top = `${posY}px`;

            if (tooltip) {
                tooltip.style.left = `${posX}px`;
                tooltip.style.top = `${posY}px`;
                const nombreColor = obtenerNombreColor(state.r, state.g, state.bVal);
                tooltip.textContent = nombreColor;
            }
        }

        // Barra de previsualización vertical
        const previewBar = document.getElementById(`${prefix}_preview_bar`);
        if (previewBar) previewBar.style.backgroundColor = state.hex;

        // Slider de brillo: Fondo y Thumb
        const [baseR, baseG, baseB] = xybToRgb(state.x, state.y, 1);
        const baseHex = rgbToHex(baseR, baseG, baseB);
        const sliderTrack = document.getElementById(`${prefix}_slider_track`);
        const sliderThumb = document.getElementById(`${prefix}_slider_thumb`);
        const bValLabel = document.getElementById(`${prefix}_brightness_val`);
        if (sliderTrack) {
            sliderTrack.style.background = `linear-gradient(to right, #000000 0%, ${baseHex} 100%)`;
        }
        if (sliderThumb) {
            sliderThumb.style.left = `${state.b * 100}%`;
        }
        if (bValLabel) {
            bValLabel.textContent = `${Math.round(state.b * 100)}%`;
        }

        // Inputs HEX y RGB
        const hexInput = document.getElementById(`${prefix}_hex_input`);
        if (hexInput && document.activeElement !== hexInput) {
            hexInput.value = state.hex.replace('#', '').toUpperCase();
        }
        const rInput = document.getElementById(`${prefix}_r_input`);
        const gInput = document.getElementById(`${prefix}_g_input`);
        const bInput = document.getElementById(`${prefix}_b_input`);
        if (rInput && document.activeElement !== rInput) rInput.value = state.r;
        if (gInput && document.activeElement !== gInput) gInput.value = state.g;
        if (bInput && document.activeElement !== bInput) bInput.value = state.bVal;

        // Badge de nombre y campo de color oculto
        const nameBadge = document.getElementById(`${prefix}_color_name_badge`);
        if (nameBadge) nameBadge.textContent = obtenerNombreColor(state.r, state.g, state.bVal);

        const hiddenColor = document.getElementById(`${prefix}_color`);
        if (hiddenColor) hiddenColor.value = state.hex;

        actualizarPreviewInsignia(prefix);
    }

    function setColorDirecto(prefix, hex) {
        hex = normalizarColorHex(hex);
        const [r, g, bVal] = hexToRgb(hex);
        const xyb = rgbToXyb(r, g, bVal);
        const state = pickerStates[prefix];
        state.x = xyb.x;
        state.y = xyb.y;
        state.b = xyb.b;
        state.r = r;
        state.g = g;
        state.bVal = bVal;
        state.hex = hex;
        updateColorUI(prefix);
    }

    function onInputHexDirecto(prefix, val) {
        val = val.replace(/[^0-9A-Fa-f]/g, '');
        if (val.length === 6) {
            setColorDirecto(prefix, '#' + val);
        }
    }

    function onInputRgbDirecto(prefix) {
        const r = parseInt(document.getElementById(`${prefix}_r_input`)?.value || 0);
        const g = parseInt(document.getElementById(`${prefix}_g_input`)?.value || 0);
        const b = parseInt(document.getElementById(`${prefix}_b_input`)?.value || 0);
        const rClamped = Math.max(0, Math.min(255, r));
        const gClamped = Math.max(0, Math.min(255, g));
        const bClamped = Math.max(0, Math.min(255, b));

        const hex = rgbToHex(rClamped, gClamped, bClamped);
        const xyb = rgbToXyb(rClamped, gClamped, bClamped);
        const state = pickerStates[prefix];
        state.x = xyb.x;
        state.y = xyb.y;
        state.b = xyb.b;
        state.r = rClamped;
        state.g = gClamped;
        state.bVal = bClamped;
        state.hex = hex;
        updateColorUI(prefix);
    }

    function cambiarModoColor(prefix, modo) {
        const tabRgb = document.getElementById(`${prefix}_tab_rgb`);
        const tabHex = document.getElementById(`${prefix}_tab_hex`);
        const rgbFields = document.getElementById(`${prefix}_rgb_fields`);

        if (modo === 'RGB') {
            tabRgb.className = 'px-2.5 py-1 rounded-md bg-white dark:bg-slate-800 shadow-2xs text-primary dark:text-white font-bold transition-all';
            tabHex.className = 'px-2.5 py-1 rounded-md text-slate-500 hover:text-slate-800 dark:hover:text-white transition-all';
            if (rgbFields) rgbFields.classList.remove('hidden');
        } else {
            tabHex.className = 'px-2.5 py-1 rounded-md bg-white dark:bg-slate-800 shadow-2xs text-primary dark:text-white font-bold transition-all';
            tabRgb.className = 'px-2.5 py-1 rounded-md text-slate-500 hover:text-slate-800 dark:hover:text-white transition-all';
            if (rgbFields) rgbFields.classList.add('hidden');
        }
    }

    async function activarGotero(prefix) {
        if (window.EyeDropper) {
            try {
                const dropper = new EyeDropper();
                const res = await dropper.open();
                if (res && res.sRGBHex) {
                    setColorDirecto(prefix, res.sRGBHex);
                }
            } catch (err) {
                // Selección cancelada por el usuario
            }
        } else {
            SwalCustom.fire({
                icon: 'info',
                title: 'Gotero Digital',
                text: 'La herramienta de gotero en pantalla está disponible en navegadores basados en Chromium (Google Chrome, Microsoft Edge, Opera).'
            });
        }
    }

    function copiarHexAlClipboard(prefix) {
        const state = pickerStates[prefix];
        if (navigator.clipboard && state.hex) {
            navigator.clipboard.writeText(state.hex).then(() => {
                Toast.fire({
                    icon: 'success',
                    title: `Color ${state.hex} copiado`
                });
            });
        }
    }

    function initColorPickerForModal(prefix, initialHex) {
        initCanvasSpectrum(prefix);
        attachColorEvents(prefix);
        setColorDirecto(prefix, initialHex || '#10B981');
    }

    // Auto-generación de código tipo a partir del nombre
    function autoGenerarCodigo(nombre) {
        if (!nombre) return;
        const slug = nombre.trim().toUpperCase()
            .normalize("NFD").replace(/[\u0300-\u036f]/g, "") // remover acentos
            .replace(/[^A-Z0-9\s]/g, "")
            .replace(/\s+/g, "_");
        document.getElementById('add_tipo').value = slug;
    }

    const entidadesInfoMap = <?php echo json_encode($entidadesList); ?>;
    const entidadesCountsMap = <?php echo json_encode($entidadesCounts); ?>;
    const countPropiosVal = <?php echo (int)$countPropios; ?>;
    const entidadActivaActual = '<?php echo $entidadActivaKey; ?>';

    // Formateo de puntuación de miles para valores COP (ej: 161250 -> 161.250)
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

    // Función para cambiar tipo de cálculo: Porcentaje vs Valor Fijo
    function cambiarTipoCalculo(prefix, tipo) {
        tipo = (tipo === 'VALOR_FIJO') ? 'VALOR_FIJO' : 'PORCENTAJE';
        const hiddenInput = document.getElementById(`${prefix}_tipo_calculo`);
        if (hiddenInput) hiddenInput.value = tipo;

        const btnPct = document.getElementById(`${prefix}_btn_tipo_pct`);
        const btnFijo = document.getElementById(`${prefix}_btn_tipo_fijo`);
        const boxPct = document.getElementById(`${prefix}_box_porcentaje`);
        const boxFijo = document.getElementById(`${prefix}_box_valor_fijo`);

        const activeColorClass = (prefix === 'add') 
            ? 'py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 font-bold text-xs transition-all bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 shadow-2xs' 
            : 'py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 font-bold text-xs transition-all bg-white dark:bg-slate-800 text-amber-600 dark:text-amber-400 shadow-2xs';

        const inactiveClass = 'py-2 px-3 rounded-lg flex items-center justify-center gap-1.5 font-bold text-xs transition-all text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 bg-transparent';

        if (tipo === 'VALOR_FIJO') {
            if (btnPct) btnPct.className = inactiveClass;
            if (btnFijo) btnFijo.className = activeColorClass;
            if (boxPct) boxPct.classList.add('hidden');
            if (boxFijo) boxFijo.classList.remove('hidden');
        } else {
            if (btnPct) btnPct.className = activeColorClass;
            if (btnFijo) btnFijo.className = inactiveClass;
            if (boxPct) boxPct.classList.remove('hidden');
            if (boxFijo) boxFijo.classList.add('hidden');
        }

        actualizarPreviewInsignia(prefix);
    }

    // Selección de tarjeta de entidad en Modal Crear
    function seleccionarEntidadModalCrear(key) {
        if (!key) key = 'PROPIO';
        const hidden = document.getElementById('add_entidad_id');
        if (hidden) hidden.value = key;

        document.querySelectorAll('.entidad-card-crear').forEach(card => {
            card.classList.remove('border-teal-500', 'bg-teal-50/70', 'dark:bg-teal-950/40', 'shadow-xs', 'ring-2', 'ring-teal-500/20');
            card.classList.add('border-slate-200', 'dark:border-slate-700/80', 'bg-white', 'dark:bg-slate-800/80');
            const radio = card.querySelector('.radio-indicator-crear');
            if (radio) {
                radio.innerHTML = '<div class="w-5 h-5 rounded-full border-2 border-slate-300 dark:border-slate-600"></div>';
            }
        });

        const activeCard = document.getElementById('card_add_crear_' + key);
        if (activeCard) {
            activeCard.classList.remove('border-slate-200', 'dark:border-slate-700/80', 'bg-white', 'dark:bg-slate-800/80');
            activeCard.classList.add('border-teal-500', 'bg-teal-50/70', 'dark:bg-teal-950/40', 'shadow-xs', 'ring-2', 'ring-teal-500/20');
            const radio = activeCard.querySelector('.radio-indicator-crear');
            if (radio) {
                radio.innerHTML = '<div class="w-5 h-5 rounded-full bg-teal-600 text-white flex items-center justify-center shadow-xs"><span class="material-symbols-outlined text-xs font-black">check</span></div>';
            }
        }
    }

    // Render de tarjeta de entidad en Modal Editar
    function renderEntityCardEdit(entVal) {
        const container = document.getElementById('edit_entidad_card_container');
        if (!container) return;

        const entObj = entidadesInfoMap[entVal] || entidadesInfoMap['PROPIO'] || {};
        const isPropio = (entVal === 'PROPIO' || !entVal || entVal === '0');
        const doctorCount = isPropio ? (countPropiosVal || 0) : ((entidadesCountsMap && entidadesCountsMap[String(entVal)]) || 0);
        const docText = `${doctorCount} ${doctorCount === 1 ? 'médico' : 'médicos'}`;

        if (isPropio) {
            container.innerHTML = `
                <div class="p-3 rounded-2xl border-2 border-teal-500/50 bg-teal-50/50 dark:bg-teal-950/30 flex items-center justify-between gap-3 shadow-2xs">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="w-24 sm:w-28 h-16 rounded-xl bg-slate-950 p-1.5 flex items-center justify-center shrink-0 border border-slate-700/80 ring-1 ring-white/10 shadow-sm overflow-hidden">
                            <img src="assets/img/Ho_Fondo_Osc.png" alt="Hernán Ocazionez" class="max-w-full max-h-full object-contain" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white truncate">
                                    ${entObj.nombre || 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'}
                                </span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 shrink-0">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    Propio / Interno
                                </span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 shrink-0">
                                    <span class="material-symbols-outlined text-[10px]">groups</span>
                                    ${docText}
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                                Sede Principal LIHO • Radiología e Imágenes Diagnósticas
                            </p>
                        </div>
                    </div>
                    <div class="shrink-0 px-2.5 py-1 rounded-xl bg-teal-100 dark:bg-teal-900/60 text-teal-800 dark:text-teal-200 text-[11px] font-black">
                        IPS Matriz
                    </div>
                </div>
            `;
        } else {
            const logoHtml = (entObj.logo) 
                ? `<img src="${entObj.logo}" alt="Logo" class="max-w-full max-h-full object-contain" />`
                : `<span class="material-symbols-outlined text-xl text-indigo-600">domain</span><span class="text-[8px] font-bold text-slate-400">IPS Externa</span>`;

            container.innerHTML = `
                <div class="p-3 rounded-2xl border-2 border-indigo-500/50 bg-indigo-50/50 dark:bg-indigo-950/30 flex items-center justify-between gap-3 shadow-2xs">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div class="w-24 sm:w-28 h-16 rounded-xl bg-white p-2 flex flex-col items-center justify-center shrink-0 border border-slate-200 shadow-sm overflow-hidden">
                            ${logoHtml}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white truncate">
                                    ${entObj.nombre || 'Entidad en Convenio'}
                                </span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 shrink-0">
                                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                    IPS Externa
                                </span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 shrink-0">
                                    <span class="material-symbols-outlined text-[10px]">groups</span>
                                    ${docText}
                                </span>
                            </div>
                            <div class="flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400 flex-wrap">
                                <span class="font-mono font-bold text-slate-600 dark:text-slate-300">NIT: ${entObj.nit || ''}</span>
                                <span>• ${entObj.subtitulo || 'Entidad en Convenio'}</span>
                            </div>
                        </div>
                    </div>
                    <div class="shrink-0 px-2.5 py-1 rounded-xl bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-200 text-[11px] font-black">
                        IPS Externa
                    </div>
                </div>
            `;
        }
    }

    // Control del Modal de Selección de Entidad (Idéntico a examenes_medicos.php)
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
            if (modal.getAttribute('data-blocking') === 'true') {
                SwalCustom.fire({
                    icon: 'warning',
                    title: 'Selección Obligatoria',
                    text: 'Debe seleccionar una entidad para poder acceder y configurar las modalidades de pago.',
                    confirmButtonText: 'Entendido'
                });
                return;
            }
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    }

    function confirmarSeleccionEntidad(entId, entNombre, entLogo, entNit, entColor) {
        SwalCustom.fire({
            title: 'Cargando Entidad...',
            html: `<div class="text-xs text-slate-400">Configurando modalidades de liquidación para: <strong class="text-slate-800 dark:text-white block mt-1">${entNombre}</strong></div>`,
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        fetch('maestro_porcentajes.php?action=seleccionar_entidad', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                entidad_id: entId,
                entidad_nombre: entNombre,
                entidad_logo: entLogo,
                entidad_nit: entNit,
                entidad_color: entColor || ''
            })
        })
        .then(res => res.json())
        .then(data => {
            window.location.href = 'maestro_porcentajes.php?entidad_id=' + encodeURIComponent(entId);
        })
        .catch(err => {
            console.error(err);
            window.location.href = 'maestro_porcentajes.php?entidad_id=' + encodeURIComponent(entId);
        });
    }

    function seleccionarPerfilEntidad(key) {
        const ent = entidadesInfoMap[key] || {};
        confirmarSeleccionEntidad(key, ent.nombre || key, ent.logo || '', ent.nit || '', ent.color_tema || '');
    }

    // Clonar modalidades base de la IPS Matriz hacia una entidad externa
    async function clonarModalidadesBase(entId, entNombre) {
        const result = await SwalCustom.fire({
            title: '¿Importar modalidades base?',
            html: `Se copiarán las modalidades base estándar de la IPS Matriz hacia <strong>${entNombre}</strong> para que pueda gestionarlas y liquidarlas de forma autónoma.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, importar modalidades',
            cancelButtonText: 'Cancelar'
        });

        if (!result.isConfirmed) return;

        const formData = new FormData();
        formData.append('action', 'clonar_modalidades_base');
        formData.append('entidad_id', entId);

        try {
            const res = await fetch('maestro_porcentajes.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                SwalCustom.fire({
                    icon: 'success',
                    title: '¡Modalidades Importadas!',
                    text: data.message,
                    timer: 1600,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                SwalCustom.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message
                });
            }
        } catch (err) {
            SwalCustom.fire({
                icon: 'error',
                title: 'Error de Red',
                text: 'No se pudo comunicar con el servidor.'
            });
        }
    }

    // Modal Crear
    function abrirModalCrear() {
        document.getElementById('formCrear').reset();
        document.getElementById('add_porcentaje').value = '30.00';
        document.getElementById('add_valor_fijo').value = formatearNumeroCOP(50000);
        
        cambiarTipoCalculo('add', 'PORCENTAJE');
        seleccionarEntidadModalCrear(entidadActivaActual);

        document.getElementById('modalCrear').classList.remove('hidden');
        setTimeout(() => {
            initColorPickerForModal('add', '#10B981');
            actualizarPreviewInsignia('add');
        }, 30);
    }
    function cerrarModalCrear() {
        document.getElementById('modalCrear').classList.add('hidden');
    }

    // Guardar Nueva Modalidad
    async function guardarNuevoPorcentaje(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardarCrear');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">sync</span> Guardando...';

        const formData = new FormData();
        formData.append('action', 'crear_porcentaje');
        formData.append('nombre', document.getElementById('add_nombre').value.trim());
        formData.append('tipo', document.getElementById('add_tipo').value.trim());
        formData.append('descripcion', document.getElementById('add_descripcion').value.trim());
        formData.append('color', document.getElementById('add_color').value.trim());
        formData.append('tipo_calculo', document.getElementById('add_tipo_calculo').value);
        formData.append('porcentaje', document.getElementById('add_porcentaje').value || 0);
        formData.append('valor_fijo', limpiarNumeroCOP(document.getElementById('add_valor_fijo').value));
        formData.append('entidad_id', document.getElementById('add_entidad_id')?.value || entidadActivaActual);

        try {
            const res = await fetch('maestro_porcentajes.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                const entDestino = document.getElementById('add_entidad_id')?.value || entidadActivaActual;
                SwalCustom.fire({
                    icon: 'success',
                    title: '¡Modalidad Creada!',
                    text: 'La nueva modalidad se ha registrado exitosamente.',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = 'maestro_porcentajes.php?entidad_id=' + encodeURIComponent(entDestino);
                });
            } else {
                SwalCustom.fire({
                    icon: 'error',
                    title: 'No se pudo guardar',
                    text: data.message
                });
                btn.disabled = false;
                btn.textContent = 'Guardar Modalidad';
            }
        } catch (err) {
            SwalCustom.fire({
                icon: 'error',
                title: 'Error de Red',
                text: 'Error de comunicación con el servidor.'
            });
            btn.disabled = false;
            btn.textContent = 'Guardar Modalidad';
        }
    }

    // Modal Editar
    function abrirModalEditar(p) {
        document.getElementById('edit_id').value = p.id;
        document.getElementById('edit_nombre').value = p.nombre || p.tipo;
        document.getElementById('edit_tipo').value = p.tipo;
        document.getElementById('edit_descripcion').value = p.descripcion || '';
        
        const hexColor = normalizarColorHex(p.color || '#10B981');
        document.getElementById('edit_color').value = hexColor;
        
        document.getElementById('edit_porcentaje').value = (p.porcentaje !== undefined && p.porcentaje !== null) ? p.porcentaje : '30.00';
        const valFijoRaw = (p.valor_fijo !== undefined && p.valor_fijo !== null) ? p.valor_fijo : 50000;
        document.getElementById('edit_valor_fijo').value = formatearNumeroCOP(valFijoRaw);
        document.getElementById('edit_estado').value = p.estado;

        const tipoCalc = (p.tipo_calculo === 'VALOR_FIJO') ? 'VALOR_FIJO' : 'PORCENTAJE';
        cambiarTipoCalculo('edit', tipoCalc);

        // Entidad info
        const entVal = (p.entidad_id && p.entidad_id > 0) ? String(p.entidad_id) : 'PROPIO';
        document.getElementById('edit_entidad_id').value = entVal;
        renderEntityCardEdit(entVal);

        const esBase = (p.tipo === 'TARIFAS_ESPECIALES' || p.tipo === 'DEGLUCIONES') && (entVal === 'PROPIO');
        const tipoInput = document.getElementById('edit_tipo');
        const hint = document.getElementById('edit_tipo_hint');

        if (esBase) {
            tipoInput.readOnly = true;
            tipoInput.classList.add('opacity-60', 'cursor-not-allowed');
            hint.textContent = 'Código base del sistema Matriz (Protegido)';
        } else {
            tipoInput.readOnly = false;
            tipoInput.classList.remove('opacity-60', 'cursor-not-allowed');
            hint.textContent = 'Mayúsculas sin espacios';
        }

        document.getElementById('modalEditar').classList.remove('hidden');
        setTimeout(() => {
            initColorPickerForModal('edit', hexColor);
            actualizarPreviewInsignia('edit');
        }, 30);
    }
    function cerrarModalEditar() {
        document.getElementById('modalEditar').classList.add('hidden');
    }

    // Guardar Edición
    async function guardarEdicionRegla(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardarEditar');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">sync</span> Actualizando...';

        const formData = new FormData();
        formData.append('action', 'guardar_porcentaje');
        formData.append('id', document.getElementById('edit_id').value);
        formData.append('nombre', document.getElementById('edit_nombre').value.trim());
        formData.append('tipo', document.getElementById('edit_tipo').value.trim());
        formData.append('descripcion', document.getElementById('edit_descripcion').value.trim());
        formData.append('color', document.getElementById('edit_color').value);
        formData.append('tipo_calculo', document.getElementById('edit_tipo_calculo').value);
        formData.append('porcentaje', document.getElementById('edit_porcentaje').value || 0);
        formData.append('valor_fijo', limpiarNumeroCOP(document.getElementById('edit_valor_fijo').value));
        formData.append('estado', document.getElementById('edit_estado').value);
        formData.append('entidad_id', document.getElementById('edit_entidad_id').value);

        try {
            const res = await fetch('maestro_porcentajes.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                SwalCustom.fire({
                    icon: 'success',
                    title: '¡Actualizado!',
                    text: 'Los cambios fueron guardados exitosamente.',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                SwalCustom.fire({
                    icon: 'error',
                    title: 'Error al actualizar',
                    text: data.message
                });
                btn.disabled = false;
                btn.textContent = 'Actualizar';
            }
        } catch (err) {
            SwalCustom.fire({
                icon: 'error',
                title: 'Error de Red',
                text: 'Error de comunicación con el servidor.'
            });
            btn.disabled = false;
            btn.textContent = 'Actualizar';
        }
    }

    // Toggle Estado
    async function toggleEstado(id, nuevoEstado, nombreModalidad) {
        const esActivar = (nuevoEstado === 1);
        const titleText = esActivar ? '¿Activar modalidad?' : '¿Desactivar modalidad?';
        const bodyText  = esActivar 
            ? `La modalidad "${nombreModalidad}" quedará habilitada para liquidaciones.` 
            : `La modalidad "${nombreModalidad}" quedará en estado inactivo.`;
        const btnText   = esActivar ? 'Sí, activar' : 'Sí, desactivar';

        const result = await SwalCustom.fire({
            title: titleText,
            html: bodyText,
            icon: esActivar ? 'question' : 'warning',
            showCancelButton: true,
            confirmButtonText: btnText,
            cancelButtonText: 'Cancelar'
        });

        if (!result.isConfirmed) return;

        const formData = new FormData();
        formData.append('action', 'toggle_estado_porcentaje');
        formData.append('id', id);
        formData.append('nuevo_estado', nuevoEstado);

        try {
            const res = await fetch('maestro_porcentajes.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                SwalCustom.fire({
                    icon: 'success',
                    title: esActivar ? '¡Activada!' : '¡Desactivada!',
                    text: data.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                SwalCustom.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message
                });
            }
        } catch (err) {
            SwalCustom.fire({
                icon: 'error',
                title: 'Error de Conexión',
                text: 'No se pudo conectar con el servidor.'
            });
        }
    }

    <?php if ($mostrarModalSeleccion): ?>
    document.addEventListener('DOMContentLoaded', () => {
        abrirModalSeleccionarEntidad();
    });
    <?php endif; ?>
    </script>

</body>

</html>

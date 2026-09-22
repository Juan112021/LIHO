<?php
/**
 * Endpoint AJAX para Configuración del Sistema (Modo Desarrollo & Seguridad de Correos) - LIHO
 * Hernán Ocazionez y Cía S.A.S.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'No autorizado. Debe iniciar sesión.']);
    exit;
}

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if ($userRole !== 'ADMINISTRADOR') {
    echo json_encode(['success' => false, 'error' => 'Permiso denegado. Se requiere rol ADMINISTRADOR para modificar esta configuración.']);
    exit;
}

require_once __DIR__ . '/includes/config_helper.php';

$action = $_REQUEST['action'] ?? 'get';

if ($action === 'get') {
    $cfg = obtenerConfiguracionSistema();
    echo json_encode(['success' => true, 'config' => $cfg]);
    exit;
}

if ($action === 'save') {
    $bloqMedicos = isset($_POST['bloquear_correos_medicos']) && ($_POST['bloquear_correos_medicos'] === '1' || $_POST['bloquear_correos_medicos'] === 'true' || $_POST['bloquear_correos_medicos'] === true);
    $modoDesarrollo = isset($_POST['modo_desarrollo']) ? ($_POST['modo_desarrollo'] === '1' || $_POST['modo_desarrollo'] === 'true' || $_POST['modo_desarrollo'] === true) : $bloqMedicos;
    $emailRedir = trim($_POST['email_test_redireccion'] ?? '');

    $ok = guardarConfiguracionSistema([
        'bloquear_correos_medicos' => $bloqMedicos,
        'modo_desarrollo'          => $modoDesarrollo,
        'email_test_redireccion'   => $emailRedir
    ], $_SESSION['user_name'] ?? 'Administrador');

    if ($ok) {
        $msg = $bloqMedicos
            ? 'Modo Desarrollo ACTIVO: Los envíos de correo a médicos están bloqueados preventivamente.'
            : 'Modo Producción ACTIVO: Los envíos de correo a médicos han sido HABILITADOS.';
        echo json_encode(['success' => true, 'mensaje' => $msg, 'config' => obtenerConfiguracionSistema()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo escribir en el archivo de configuración.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción desconocida.']);

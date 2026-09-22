<?php
session_start();

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/logger_helper.php');

$correo = $_SESSION['user_email'] ?? 'Desconocido';
$userId = $_SESSION['user_id'] ?? null;

if (isset($con) && $con !== false) {
    registrar_log_acceso($con, $correo, 'LOGOUT', $userId, 'Cierre de sesión voluntario.');
}

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
header("Location: index.php");
exit;
?>

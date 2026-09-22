<?php
ob_start();
session_start();
date_default_timezone_set('America/Bogota');
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/logger_helper.php');

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Método no permitido"]);
    exit;
}

$identificador = isset($_POST['email']) ? trim($_POST['email']) : (isset($_POST['login_input']) ? trim($_POST['login_input']) : '');

if (empty($identificador)) {
    ob_end_clean();
    echo json_encode(["success" => false, "message" => "Por favor ingrese su usuario o correo electrónico."]);
    exit;
}

// Función para generar Token Alfanumérico de 6 caracteres (sin caracteres ambiguos)
function generarTokenAlfanumerico($longitud = 6) {
    $caracteres = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $token = '';
    $max = strlen($caracteres) - 1;
    for ($i = 0; $i < $longitud; $i++) {
        $token .= $caracteres[mt_rand(0, $max)];
    }
    return $token;
}

$usuarioEncontrado = false;
$usuarioData = null;

// Consultar en la base de datos SQL Server por correo (u.email) o Usuario Proteo (m.usuario_proteo)
if (isset($con) && $con !== false) {
    $sql = "SELECT TOP 1 u.id AS usuario_id, u.email, u.rol_id, u.estado, u.nombre_completo, u.cedula AS u_cedula,
                   m.cedula AS m_cedula, m.usuario_proteo, m.pnom, m.snom, m.pape, m.sape,
                   r.nombre AS rol_nombre
            FROM usuarios u
            LEFT JOIN medicos m ON u.id = m.usuario_id
            LEFT JOIN roles r ON u.rol_id = r.id
            WHERE LOWER(RTRIM(LTRIM(u.email))) = LOWER(?)
               OR LOWER(RTRIM(LTRIM(m.usuario_proteo))) = LOWER(?)";
            
    $params = array($identificador, $identificador);
    $stmt = @sqlsrv_query($con, $sql, $params);

    if ($stmt !== false && sqlsrv_has_rows($stmt)) {
        $usuarioData = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $usuarioEncontrado = true;
        
        $estadoUser = strtoupper(trim($usuarioData['estado'] ?? '1'));
        if ($estadoUser === '0' || $estadoUser === 'INACTIVO') {
            sqlsrv_free_stmt($stmt);
            sqlsrv_close($con);
            ob_end_clean();
            echo json_encode([
                "success" => false,
                "message" => "Su cuenta de usuario se encuentra desactivada. Contacte al administrador."
            ]);
            exit;
        }
    }
    if ($stmt !== false) {
        sqlsrv_free_stmt($stmt);
    }
}

if (!$usuarioEncontrado) {
    if (isset($con) && $con !== false) {
        sqlsrv_close($con);
    }
    ob_end_clean();
    echo json_encode([
        "success" => false,
        "message" => "El usuario o correo electrónico no se encuentra registrado en el sistema."
    ]);
    exit;
}

$token = generarTokenAlfanumerico(6);

$idUser     = $usuarioData['usuario_id'] ?? 1;
$docUser    = !empty($usuarioData['m_cedula']) ? $usuarioData['m_cedula'] : (!empty($usuarioData['u_cedula']) ? $usuarioData['u_cedula'] : '123456789');
$correoUser = $usuarioData['email'] ?? (filter_var($identificador, FILTER_VALIDATE_EMAIL) ? $identificador : '');

$nombreBase = trim($usuarioData['nombre_completo'] ?? '');
if (empty($nombreBase)) {
    $nombreBase = trim(($usuarioData['pnom'] ?? '') . ' ' . ($usuarioData['snom'] ?? '') . ' ' . ($usuarioData['pape'] ?? '') . ' ' . ($usuarioData['sape'] ?? ''));
}

$rolIdRaw = intval($usuarioData['rol_id'] ?? 0);
$rolNombreDb = strtoupper(trim((string)($usuarioData['rol_nombre'] ?? '')));

if ($rolIdRaw === 1 || $rolNombreDb === 'ADMIN' || $rolNombreDb === 'ADMINISTRADOR') {
    $rolUser = 'ADMINISTRADOR';
} elseif ($rolIdRaw === 2 || $rolNombreDb === 'FINANCIERO' || $rolNombreDb === 'AUXILIAR' || $rolNombreDb === 'FINANCIERA') {
    $rolUser = 'FINANCIERO';
} elseif ($rolIdRaw === 3 || $rolNombreDb === 'MEDICO' || $rolNombreDb === 'MÉDICO' || !empty($usuarioData['usuario_proteo']) || !empty($usuarioData['m_cedula'])) {
    $rolUser = 'MÉDICO';
} else {
    $rolUser = 'SIN ROL';
}

$prefix = ($rolUser === 'MÉDICO') ? 'Dr. ' : '';

if ($rolUser !== 'MÉDICO') {
    $nombreBase = preg_replace('/^Dr\.\s*/i', '', $nombreBase);
    $nombreUser = !empty($nombreBase) ? $nombreBase : ucfirst(explode('@', $correoUser)[0] ?? $identificador);
} else {
    if (!empty($nombreBase)) {
        $nombreUser = (stripos($nombreBase, 'dr.') === 0) ? $nombreBase : ($prefix . $nombreBase);
    } else {
        $parts = explode('@', $correoUser);
        $nombreUser = $prefix . ucfirst($parts[0] ?? $identificador);
    }
}

// Almacenar token y expiración de 15 minutos en la sesión activa
$_SESSION['auth_user'] = array(
    'id' => $idUser,
    'documento' => $docUser,
    'nombre' => $nombreUser,
    'correo' => $correoUser,
    'rol' => $rolUser,
    'rol_id' => $rolIdRaw,
    'permisos' => 'LIQUIDACION'
);
$_SESSION['auth_token'] = strtoupper($token);
$_SESSION['token_expires'] = time() + (15 * 60);

// Cargar Logo optimizado para el correo electrónico
$logoPath = __DIR__ . '/assets/img/logo_email_optimized.png';
if (!file_exists($logoPath)) {
    $logoPath = __DIR__ . '/assets/img/hologo.png';
}
if (!file_exists($logoPath)) {
    $logoPath = __DIR__ . '/assets/img/Logo original.png';
}

$embeddedImages = array('logo_liho' => $logoPath);

$destino = $correoUser;
$nombreUsuarioHtml = htmlspecialchars($nombreUser);
$asunto = "Código de Acceso - LIHO | Hernán Ocazionez y Cía S.A.S.";
$horaExpiracion = date("h:i A", time() + (15 * 60));

$cuerpo = '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .card { max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
        .header { text-align: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 24px; }
        .subtitle { color: #00c1be; font-size: 13px; font-weight: 700; margin: 8px 0 0 0; letter-spacing: 1px; }
        .token-box { background: #f0fdfa; border: 2px dashed #00c1be; border-radius: 14px; padding: 22px; text-align: center; margin: 24px 0; }
        .token-code { font-size: 38px; font-weight: 800; letter-spacing: 10px; color: #14354e; font-family: "Courier New", Courier, monospace; }
        .notice { font-size: 13px; color: #64748b; line-height: 1.5; text-align: center; }
        .footer { text-align: center; margin-top: 24px; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <img src="cid:logo_liho" alt="Hernán Ocazionez Logo" style="max-height: 55px; width: auto; margin-bottom: 8px; display: inline-block;" /><br>
            <p class="subtitle">LIHO &bull; Plataforma de Liquidación Hospitalaria IPS</p>
        </div>
        <p style="color: #14354e; font-size: 16px; margin-bottom: 12px; font-weight: 600;">Estimado(a) ' . $nombreUsuarioHtml . ',</p>
        <p style="color: #475569; font-size: 14px; line-height: 1.6; margin-top: 0;">Se ha generado una solicitud de inicio de sesión para ingresar al portal de <strong>Liquidación Hospitalaria (LIHO)</strong> de la IPS Hernán Ocazionez y Cía S.A.S.</p>
        
        <p style="color: #475569; font-size: 14px;">Utilice el siguiente código de seguridad alfanumérico para confirmar su identidad:</p>

        <div class="token-box">
            <div class="token-code">' . htmlspecialchars($token) . '</div>
        </div>

        <p class="notice">Este código es estrictamente personal y expira en <strong>15 minutos</strong> (a las <strong>' . $horaExpiracion . '</strong> hora Colombia).</p>

        <div class="footer">
            © ' . date("Y") . ' Hernán Ocazionez y Cía S.A.S. | LIHO V 1.0.0
        </div>
    </div>
</body>
</html>';

require_once(__DIR__ . '/includes/email_logger.php');
$mailEnviado = enviarCorreoSMTP($destino, $asunto, $cuerpo, null, $embeddedImages);

$estadoLog = $mailEnviado ? 'EXITOSO' : 'FALLIDO';
registrarLogCorreo($idUser, $destino, $asunto, 'Código de Acceso OTP', "Envío de token de seguridad alfanumérico para inicio de sesión", $estadoLog);

if (isset($con) && $con !== false) {
    @sqlsrv_close($con);
}

ob_end_clean();

if ($mailEnviado) {
    echo json_encode([
        "success" => true,
        "message" => "Código de acceso enviado exitosamente.",
        "redirect" => "validar_token.php"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "No se pudo realizar el envío del correo electrónico. Verifique la configuración SMTP."
    ]);
}
exit;
?>

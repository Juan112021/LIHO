<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

$userRole = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');

// Restringir acceso solo a ADMINISTRADOR
if ($userRole !== 'ADMINISTRADOR') {
    header("Location: dashboard.php");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');
require_once(__DIR__ . '/includes/permisos_helper.php');
require_once(__DIR__ . '/includes/email_logger.php');
require_once(__DIR__ . '/includes/logger_helper.php');
require_once(__DIR__ . '/includes/liquidaciones_helper.php');

$msgSuccess = $_SESSION['flash_msg_success'] ?? '';
$msgError   = $_SESSION['flash_msg_error'] ?? '';
unset($_SESSION['flash_msg_success'], $_SESSION['flash_msg_error']);

// Manejo de Acciones POST (Guardar Permisos, Crear Usuario Interno, Cambiar Estado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'guardar_permisos') {
        $targetUserId = intval($_POST['target_user_id'] ?? 0);
        $modulosPermitidos = $_POST['modulos_permiso'] ?? array();

        if ($targetUserId > 0) {
            $okPerms = guardarPermisosUsuario($targetUserId, $modulosPermitidos, intval($_SESSION['user_id'] ?? 0), $_SESSION['user_name'] ?? 'Administrador');
            if ($okPerms) {
                // Notificar por correo al usuario sobre la asignación de permisos
                $stmtTarget = sqlsrv_query($con, "SELECT email, nombre_completo FROM usuarios WHERE id = ?", array($targetUserId));
                if ($stmtTarget !== false && $rowT = sqlsrv_fetch_array($stmtTarget, SQLSRV_FETCH_ASSOC)) {
                    $uEmail = $rowT['email'];
                    $uName  = $rowT['nombre_completo'] ?: 'Usuario';
                    $modulosInfo = obtenerModulosSistema();

                    $nombresModulos = array();
                    foreach ($modulosPermitidos as $mKey) {
                        if (isset($modulosInfo[$mKey])) {
                            $nombresModulos[] = $modulosInfo[$mKey]['nombre'];
                        }
                    }
                    $textoPermisos = !empty($nombresModulos) ? implode(", ", $nombresModulos) : "Ningún módulo especial habilitado";

                    notificarActualizacionUsuario($targetUserId, $uEmail, $uName, array(
                        'Actualización de Permisos' => "Se han configurado sus permisos de acceso especial a los módulos: $textoPermisos",
                        'Asignado Por' => "Administración del Sistema LIHO"
                    ));
                }

                $_SESSION['flash_msg_success'] = "Permisos especiales guardados y notificados al usuario exitosamente.";
            } else {
                $_SESSION['flash_msg_error'] = "Error al actualizar los permisos en la base de datos.";
            }
        }
        header("Location: usuarios.php");
        exit;

    } elseif ($action === 'crear_usuario_interno') {
        $rolId    = trim($_POST['rol_id'] ?? '2');
        $nombre   = trim($_POST['nombre_completo'] ?? '');
        $cedula   = trim($_POST['cedula'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $fechaNac = trim($_POST['fecha_nacimiento'] ?? '');

        if (empty($nombre) || empty($email) || empty($cedula)) {
            $_SESSION['flash_msg_error'] = "Por favor complete todos los campos obligatorios (Nombre, Correo, Cédula).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_msg_error'] = "Ingrese una dirección de correo electrónico válida.";
        } else {
            $stmtCheck = sqlsrv_query($con, "SELECT TOP 1 id FROM usuarios WHERE LOWER(email) = LOWER(?)", array($email));
            if ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck)) {
                $_SESSION['flash_msg_error'] = "Ya existe un usuario registrado con ese correo electrónico.";
            } else {
                $fechaActual = date('Y-m-d H:i:s');
                $claveDummy  = password_hash("prueba", PASSWORD_DEFAULT);

                $sqlIns = "INSERT INTO usuarios (email, clave, rol_id, estado, fecha_creacion, ultima_sesion, fecha_nacimiento, nombre_completo, cedula) 
                           VALUES (?, ?, ?, '1', ?, '', ?, ?, ?)";
                $stmtIns = sqlsrv_query($con, $sqlIns, array($email, $claveDummy, $rolId, $fechaActual, $fechaNac, $nombre, $cedula));

                if ($stmtIns !== false) {
                    $stmtMax = sqlsrv_query($con, "SELECT MAX(id) AS new_id FROM usuarios WHERE LOWER(email) = LOWER(?)", array($email));
                    $rowMax = sqlsrv_fetch_array($stmtMax, SQLSRV_FETCH_ASSOC);
                    $newUserId = $rowMax['new_id'] ?? null;

                    // Trazabilidad en Logs
                    if (function_exists('registrar_log_sistema')) {
                        registrar_log_sistema(
                            'USUARIOS',
                            'CREACION_USUARIO_ADMIN',
                            'CREACION',
                            'INFO',
                            "Usuario #{$newUserId} ({$nombre})",
                            "N/A",
                            "Email: {$email} | Rol: {$rolId}",
                            "Se creó la cuenta administrativa para '{$nombre}' ({$email}) con cédula {$cedula} y rol ID #{$rolId}."
                        );
                    }

                    if (function_exists('registrarLogAuditoriaUniversal')) {
                        registrarLogAuditoriaUniversal(
                            'USUARIOS',
                            $newUserId,
                            "USER-{$newUserId}",
                            'CREACION_USUARIO_ADMIN',
                            'Alta Usuario',
                            "Rol: {$rolId}",
                            intval($_SESSION['user_id'] ?? 0),
                            $_SESSION['user_name'] ?? 'Administrador',
                            'ADMINISTRADOR',
                            "Alta de usuario administrativo '{$nombre}' ({$email})",
                            json_encode(array('usuario_id' => $newUserId, 'email' => $email, 'rol_id' => $rolId))
                        );
                    }

                    require_once(__DIR__ . '/includes/email_logger.php');
                    notificarActualizacionUsuario($newUserId, $email, $nombre, array(
                        'Alta de Usuario Interno' => "Se ha creado su cuenta de usuario administrativo en LIHO.",
                        'Correo Electrónico' => $email,
                        'Cédula / Documento' => $cedula
                    ));

                    $_SESSION['flash_msg_success'] = "Usuario administrativo registrado exitosamente con clave de acceso 'prueba'.";
                } else {
                    $_SESSION['flash_msg_error'] = "Error al guardar el usuario en la base de datos SQL Server.";
                }
            }
        }
        header("Location: usuarios.php");
        exit;

    } elseif ($action === 'editar_usuario_interno') {
        $targetUserId = intval($_POST['user_id'] ?? 0);
        $rolId        = isset($_POST['rol_id']) ? strval(intval($_POST['rol_id'])) : '2';
        $nombre       = trim($_POST['nombre_completo'] ?? '');
        $cedula       = trim($_POST['cedula'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $fechaNac     = trim($_POST['fecha_nacimiento'] ?? '');
        $cambiarClave = isset($_POST['cambiar_clave']) && ($_POST['cambiar_clave'] === '1' || $_POST['cambiar_clave'] === 'on');
        $nuevaClave   = trim($_POST['nueva_clave'] ?? '');
        $confirmClave = trim($_POST['confirmar_clave'] ?? '');

        if ($targetUserId <= 0 || empty($nombre) || empty($email) || empty($cedula)) {
            $_SESSION['flash_msg_error'] = "Por favor complete todos los campos obligatorios (Nombre, Correo, Cédula).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_msg_error'] = "Ingrese una dirección de correo electrónico válida.";
        } elseif ($cambiarClave && strlen($nuevaClave) < 6) {
            $_SESSION['flash_msg_error'] = "La nueva contraseña debe tener al menos 6 caracteres.";
        } elseif ($cambiarClave && $nuevaClave !== $confirmClave) {
            $_SESSION['flash_msg_error'] = "Las contraseñas ingresadas no coinciden.";
        } else {
            // Verificar si el correo ya pertenece a otro usuario
            $stmtCheck = sqlsrv_query($con, "SELECT TOP 1 id FROM usuarios WHERE LOWER(email) = LOWER(?) AND id <> ?", array($email, $targetUserId));
            if ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck)) {
                $_SESSION['flash_msg_error'] = "Ya existe otro usuario registrado con ese correo electrónico.";
            } else {
                // Obtener datos previos completos para auditoría minuciosa
                $stmtPrev = sqlsrv_query($con, "
                    SELECT u.nombre_completo, u.email, u.cedula, u.rol_id, u.fecha_nacimiento,
                           r.nombre AS rol_nombre
                    FROM usuarios u
                    LEFT JOIN roles r ON u.rol_id = r.id
                    WHERE u.id = ?
                ", array($targetUserId));

                $prevData = ($stmtPrev && ($rowP = sqlsrv_fetch_array($stmtPrev, SQLSRV_FETCH_ASSOC))) ? $rowP : array();
                
                // Obtener nombre del nuevo rol
                $stmtNewRol = sqlsrv_query($con, "SELECT nombre FROM roles WHERE id = ?", array($rolId));
                $newRolNombre = ($rN = sqlsrv_fetch_array($stmtNewRol, SQLSRV_FETCH_ASSOC)) ? $rN['nombre'] : "Rol #{$rolId}";

                $prevNombre = trim((string)($prevData['nombre_completo'] ?? ''));
                $prevEmail  = trim((string)($prevData['email'] ?? ''));
                $prevCedula = trim((string)($prevData['cedula'] ?? ''));
                $prevRolId  = strval($prevData['rol_id'] ?? '');
                $prevRolNom = trim((string)($prevData['rol_nombre'] ?? "Rol #{$prevRolId}"));
                $prevFecha  = '';
                if (!empty($prevData['fecha_nacimiento'])) {
                    if (is_a($prevData['fecha_nacimiento'], 'DateTime')) {
                        $prevFecha = $prevData['fecha_nacimiento']->format('Y-m-d');
                    } else {
                        $prevFecha = substr((string)$prevData['fecha_nacimiento'], 0, 10);
                    }
                }

                // Detectar cada campo modificado
                $cambiosDetallados = array();
                $resumenTexto = array();

                if ($prevNombre !== $nombre) {
                    $cambiosDetallados['nombre_completo'] = array('antes' => $prevNombre, 'despues' => $nombre);
                    $resumenTexto[] = "Nombre: '{$prevNombre}' -> '{$nombre}'";
                }
                if ($prevEmail !== $email) {
                    $cambiosDetallados['email'] = array('antes' => $prevEmail, 'despues' => $email);
                    $resumenTexto[] = "Email: '{$prevEmail}' -> '{$email}'";
                }
                if ($prevCedula !== $cedula) {
                    $cambiosDetallados['cedula'] = array('antes' => $prevCedula, 'despues' => $cedula);
                    $resumenTexto[] = "Cédula: '{$prevCedula}' -> '{$cedula}'";
                }
                if ($prevRolId !== strval($rolId)) {
                    $cambiosDetallados['rol'] = array('antes' => "{$prevRolNom} (#{$prevRolId})", 'despues' => "{$newRolNombre} (#{$rolId})");
                    $resumenTexto[] = "Rol: {$prevRolNom} (#{$prevRolId}) -> {$newRolNombre} (#{$rolId})";
                }
                if ($prevFecha !== $fechaNac) {
                    $cambiosDetallados['fecha_nacimiento'] = array('antes' => $prevFecha ?: 'Sin fecha', 'despues' => $fechaNac ?: 'Sin fecha');
                    $resumenTexto[] = "Fecha Nacimiento: '{$prevFecha}' -> '{$fechaNac}'";
                }
                if ($cambiarClave && !empty($nuevaClave)) {
                    $cambiosDetallados['clave'] = array('antes' => '********', 'despues' => 'Actualizada con nuevo hash');
                    $resumenTexto[] = "Contraseña: Se asignó una nueva clave de acceso";
                }

                if ($cambiarClave && !empty($nuevaClave)) {
                    $claveHash = password_hash($nuevaClave, PASSWORD_BCRYPT);
                    $sqlUp = "UPDATE usuarios SET nombre_completo = ?, email = ?, cedula = ?, rol_id = ?, fecha_nacimiento = ?, clave = ? WHERE id = ?";
                    $paramsUp = array($nombre, $email, $cedula, $rolId, $fechaNac, $claveHash, $targetUserId);
                } else {
                    $sqlUp = "UPDATE usuarios SET nombre_completo = ?, email = ?, cedula = ?, rol_id = ?, fecha_nacimiento = ? WHERE id = ?";
                    $paramsUp = array($nombre, $email, $cedula, $rolId, $fechaNac, $targetUserId);
                }

                $stmtUp = sqlsrv_query($con, $sqlUp, $paramsUp);
                if ($stmtUp !== false) {
                    $detallesAudit = !empty($resumenTexto) ? implode('; ', $resumenTexto) : "Sin cambios detectados en los campos";
                    $entidadAfectada = "Usuario #{$targetUserId} ({$nombre})";

                    // 1. Registro detallado en dbo.logs_sistema
                    if (function_exists('registrar_log_sistema')) {
                        registrar_log_sistema(
                            'USUARIOS',
                            'EDICION_USUARIO_ADMIN',
                            'MODIFICACION',
                            "Edición de usuario #{$targetUserId}. Modificaciones: {$detallesAudit}",
                            array(
                                'nivel' => 'INFO',
                                'entidad_afectada' => $entidadAfectada,
                                'valor_anterior' => json_encode($prevData, JSON_UNESCAPED_UNICODE),
                                'valor_nuevo' => json_encode(array(
                                    'nombre_completo' => $nombre,
                                    'email' => $email,
                                    'cedula' => $cedula,
                                    'rol_id' => $rolId,
                                    'rol_nombre' => $newRolNombre,
                                    'fecha_nacimiento' => $fechaNac,
                                    'clave_cambiada' => ($cambiarClave && !empty($nuevaClave))
                                ), JSON_UNESCAPED_UNICODE),
                                'usuario_id' => intval($_SESSION['user_id'] ?? 0),
                                'usuario_nombre' => $_SESSION['user_name'] ?? 'Administrador',
                                'usuario_email' => $_SESSION['user_email'] ?? '',
                                'usuario_rol' => $_SESSION['user_role'] ?? 'ADMINISTRADOR'
                            )
                        );
                    }

                    // 2. Registro detallado en sistema_auditoria_logs
                    if (function_exists('registrarLogAuditoriaUniversal')) {
                        registrarLogAuditoriaUniversal(
                            'USUARIOS',
                            $targetUserId,
                            "USER-{$targetUserId}",
                            'EDICION_USUARIO_ADMIN',
                            'Modificación Usuario',
                            "Rol: {$newRolNombre}",
                            intval($_SESSION['user_id'] ?? 0),
                            $_SESSION['user_name'] ?? 'Administrador',
                            'ADMINISTRADOR',
                            "Se actualizaron los datos del usuario '{$nombre}' ({$email}): {$detallesAudit}",
                            json_encode(array(
                                'usuario_id' => $targetUserId,
                                'email' => $email,
                                'nombre' => $nombre,
                                'cambios' => $cambiosDetallados
                            ), JSON_UNESCAPED_UNICODE)
                        );
                    }

                    // Notificación por correo al usuario sobre la edición de su perfil
                    if (!empty($resumenTexto)) {
                        require_once(__DIR__ . '/includes/email_logger.php');
                        $notifData = array(
                            'Actualización de Cuenta' => "Sus datos administrativos han sido actualizados por la administración.",
                            'Detalle de Cambios' => implode('<br>', $resumenTexto)
                        );
                        if ($cambiarClave && !empty($nuevaClave)) {
                            $notifData['Seguridad'] = "Su contraseña de acceso fue restablecida exitosamente.";
                        }
                        notificarActualizacionUsuario($targetUserId, $email, $nombre, $notifData);
                    }

                    $_SESSION['flash_msg_success'] = "Los datos del usuario '{$nombre}' han sido actualizados exitosamente." . ($cambiarClave ? " Se actualizó la contraseña de acceso." : "");
                } else {
                    $_SESSION['flash_msg_error'] = "Error al actualizar los datos en SQL Server.";
                }
            }
        }
        header("Location: usuarios.php");
        exit;

    } elseif ($action === 'toggle_estado_interno') {
        $targetUserId = intval($_POST['user_id'] ?? 0);
        $nuevoEstado  = (trim($_POST['estado_actual'] ?? '1') === '1') ? '0' : '1';

        if ($targetUserId > 0 && isset($con) && $con !== false) {
            $stmtToggle = sqlsrv_query($con, "UPDATE usuarios SET estado = ? WHERE id = ?", array($nuevoEstado, $targetUserId));
            if ($stmtToggle !== false) {
                $txtEst = ($nuevoEstado === '1') ? 'ACTIVADA' : 'DESACTIVADA';

                // Trazabilidad en Logs
                if (function_exists('registrar_log_sistema')) {
                    registrar_log_sistema(
                        'USUARIOS',
                        'CAMBIO_ESTADO_USUARIO',
                        'MODIFICACION',
                        'WARNING',
                        "Usuario #{$targetUserId}",
                        "Estado previo",
                        "Estado: {$nuevoEstado}",
                        "La cuenta del usuario ID #{$targetUserId} ha sido {$txtEst} por el administrador."
                    );
                }

                if (function_exists('registrarLogAuditoriaUniversal')) {
                    registrarLogAuditoriaUniversal(
                        'USUARIOS',
                        $targetUserId,
                        "USER-{$targetUserId}",
                        'CAMBIO_ESTADO_USUARIO',
                        'Cambio Estado',
                        "Nuevo: {$nuevoEstado}",
                        intval($_SESSION['user_id'] ?? 0),
                        $_SESSION['user_name'] ?? 'Administrador',
                        'ADMINISTRADOR',
                        "La cuenta del usuario #{$targetUserId} fue {$txtEst}",
                        json_encode(array('target_user_id' => $targetUserId, 'nuevo_estado' => $nuevoEstado))
                    );
                }

                $_SESSION['flash_msg_success'] = ($nuevoEstado === '1') ? "La cuenta del usuario ha sido ACTIVADA." : "La cuenta del usuario ha sido DESACTIVADA.";
            } else {
                $_SESSION['flash_msg_error'] = "No se pudo cambiar el estado del usuario.";
            }
        }
        header("Location: usuarios.php");
        exit;
    }
}

// Consultar exclusivamente usuarios NO MÉDICOS (rol_id <> 3)
$usuariosInternos = array();
$totalAdmins = 0;
$totalFinancieros = 0;

if (isset($con) && $con !== false) {
    $sqlSelect = "SELECT u.id AS usuario_id, u.email, u.estado, u.rol_id, u.fecha_creacion, u.fecha_nacimiento, u.foto_perfil, u.nombre_completo, u.cedula,
                         r.nombre AS rol_nombre
                  FROM usuarios u
                  LEFT JOIN roles r ON u.rol_id = r.id
                  WHERE u.rol_id <> '3' AND u.rol_id <> 3
                  ORDER BY u.id DESC";
    $stmtSelect = sqlsrv_query($con, $sqlSelect);
    if ($stmtSelect !== false) {
        while ($row = sqlsrv_fetch_array($stmtSelect, SQLSRV_FETCH_ASSOC)) {
            $row['permisos_map'] = obtenerPermisosUsuario($row['usuario_id']);
            $usuariosInternos[] = $row;

            if ($row['rol_id'] == '1' || $row['rol_id'] == 1 || strtolower((string)$row['rol_nombre']) === 'admin') {
                $totalAdmins++;
            } elseif ($row['rol_id'] == '2' || $row['rol_id'] == 2 || strtolower((string)$row['rol_nombre']) === 'financiero') {
                $totalFinancieros++;
            }
        }
    }
}

// Consultar Roles para el desplegable de creación y el selector interactivo de edición
$rolesList = array();
if (isset($con) && $con !== false) {
    $stmtRoles = sqlsrv_query($con, "SELECT id, nombre, descripcion, color_tema FROM roles WHERE estado = 1 AND id <> 3 ORDER BY id ASC");
    if ($stmtRoles !== false) {
        while ($rRow = sqlsrv_fetch_array($stmtRoles, SQLSRV_FETCH_ASSOC)) {
            $rolesList[] = $rRow;
        }
    }
}
if (empty($rolesList)) {
    $rolesList = array(
        array('id' => 1, 'nombre' => 'Admin', 'descripcion' => 'Control total del sistema', 'color_tema' => 'purple'),
        array('id' => 2, 'nombre' => 'Financiero', 'descripcion' => 'Gestión de liquidaciones y facturación', 'color_tema' => 'blue'),
        array('id' => 0, 'nombre' => 'Sin rol', 'descripcion' => 'Acceso estándar al panel de inicio', 'color_tema' => 'sky')
    );
}

if (!function_exists('obtenerVisualRolUsuario')) {
    function obtenerVisualRolUsuario($rolId, $nombre, $descDb = '', $colorDb = '') {
        $id = intval($rolId);
        $nomLower = strtolower(trim((string)$nombre));
        $icon = 'badge';
        $color = !empty($colorDb) ? $colorDb : 'slate';
        $desc = trim((string)$descDb);

        if ($id === 1 || strpos($nomLower, 'admin') !== false) {
            $icon = 'shield_person';
            $color = 'purple';
            if (empty($desc)) $desc = 'Control total de configuración y usuarios';
        } elseif ($id === 2 || strpos($nomLower, 'financ') !== false) {
            $icon = 'payments';
            $color = 'blue';
            if (empty($desc)) $desc = 'Liquidaciones, facturación y reportes';
        } elseif ($id === 0 || strpos($nomLower, 'sin rol') !== false || strpos($nomLower, 'observador') !== false) {
            $icon = 'visibility';
            $color = 'sky';
            if (empty($desc)) $desc = 'Acceso estándar al panel de inicio y vistas autorizadas';
        } else {
            $icon = 'manage_accounts';
            if (empty($desc)) $desc = 'Rol administrativo personalizado';
        }

        return array('icon' => $icon, 'color' => $color, 'desc' => $desc);
    }
}

$modulosSistema = obtenerModulosSistema();
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Usuarios y Permisos Especiales | LIHO</title>
    
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet" />
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
                        sans: ["Montserrat", "sans-serif"]
                    }
                }
            }
        }
    </script>
    <style>
        * { font-family: 'Montserrat', sans-serif; }

        .animate-card-entry {
            animation: cardEntry 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardEntry {
            0% { opacity: 0; transform: translateY(20px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .switch-toggle:checked + .switch-bg {
            background-color: #00c1be;
        }
        .switch-toggle:checked + .switch-bg .switch-dot {
            transform: translateX(18px);
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-[#070e1e] text-slate-800 dark:text-slate-100 min-h-screen flex flex-col selection:bg-tertiary selection:text-white transition-colors duration-300">

    <!-- Header Navigation -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Content -->
    <main class="flex-grow max-w-[1380px] w-full mx-auto px-4 sm:px-6 py-8 animate-card-entry">
        
        <!-- Alertas de Flash (Mensajes POST) -->
        <?php if (!empty($msgSuccess)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200/80 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-xs font-bold flex items-center justify-between shadow-xs">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-xl text-emerald-600">check_circle</span>
                    <span><?php echo htmlspecialchars($msgSuccess); ?></span>
                </div>
                <button onclick="this.parentElement.remove();" class="text-emerald-500 hover:text-emerald-700 p-1">
                    <span class="material-symbols-outlined text-sm">close</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if (!empty($msgError)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/60 border border-rose-200/80 dark:border-rose-800 text-rose-800 dark:text-rose-300 text-xs font-bold flex items-center justify-between shadow-xs">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-xl text-rose-600">error</span>
                    <span><?php echo htmlspecialchars($msgError); ?></span>
                </div>
                <button onclick="this.parentElement.remove();" class="text-rose-500 hover:text-rose-700 p-1">
                    <span class="material-symbols-outlined text-sm">close</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Encabezado Principal de Módulo -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-100 dark:bg-purple-950/60 border border-purple-200/80 dark:border-purple-800/80 text-purple-800 dark:text-purple-300 text-[11px] font-extrabold uppercase tracking-wider mb-2">
                    <span class="material-symbols-outlined text-sm">admin_panel_settings</span>
                    <span>Control de Acceso Interno</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-primary dark:text-white tracking-tight">Usuarios y Permisos Especiales</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mt-1">Gestione usuarios administrativos (excluyendo médicos) y otorgue permisos especiales de visualización por módulo</p>
            </div>

            <div class="flex items-center gap-3 shrink-0 self-start md:self-auto">
                <a href="gestion_roles.php" class="inline-flex items-center gap-2 bg-tertiary hover:bg-[#00b2af] text-white font-bold text-xs px-5 py-3 rounded-2xl shadow-md transition-all hover:scale-[1.02] cursor-pointer">
                    <span class="material-symbols-outlined text-lg">admin_panel_settings</span>
                    <span>Gestión de Roles & Vistas</span>
                </a>

                <button id="openCreateUserModalBtn" class="inline-flex items-center gap-2 bg-primary dark:bg-slate-800 hover:bg-[#1b4363] dark:hover:bg-slate-700 text-white font-bold text-xs px-5 py-3 rounded-2xl shadow-md transition-all hover:scale-[1.02] cursor-pointer border border-transparent dark:border-slate-700">
                    <span class="material-symbols-outlined text-lg">person_add</span>
                    <span>Registrar Usuario Administrativo</span>
                </button>
            </div>
        </div>

        <!-- Tarjetas de Métricas KPI -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            
            <!-- Total Usuarios Internos -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-primary/10 dark:bg-slate-800 text-primary dark:text-tertiary flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">group</span>
                </div>
                <div>
                    <p class="text-2xl font-extrabold text-primary dark:text-white"><?php echo count($usuariosInternos); ?></p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">Usuarios Administrativos</p>
                </div>
            </div>

            <!-- Administradores -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-purple-100 dark:bg-purple-950/50 text-purple-700 dark:text-purple-300 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">shield_person</span>
                </div>
                <div>
                    <p class="text-2xl font-extrabold text-purple-700 dark:text-purple-300"><?php echo $totalAdmins; ?></p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">Administradores</p>
                </div>
            </div>

            <!-- Personal Financiero -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-blue-100 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300 flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">account_balance</span>
                </div>
                <div>
                    <p class="text-2xl font-extrabold text-blue-700 dark:text-blue-300"><?php echo $totalFinancieros; ?></p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">Personal Financiero</p>
                </div>
            </div>

            <!-- Permisos Especiales Activos -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-teal-100 dark:bg-teal-950/50 text-tertiary flex items-center justify-center font-bold">
                    <span class="material-symbols-outlined text-2xl">key</span>
                </div>
                <div>
                    <p class="text-2xl font-extrabold text-tertiary">Activo</p>
                    <p class="text-xs font-bold text-slate-400 dark:text-slate-500">Matriz de Permisos SQL</p>
                </div>
            </div>
        </div>

        <!-- Buscador y Filtro de Usuarios -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 border border-slate-200/80 dark:border-slate-800 shadow-sm mb-6">
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="relative w-full sm:w-80">
                    <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xl pointer-events-none">search</span>
                    <input type="text" id="searchInput" placeholder="Buscar por nombre, correo o cédula..." 
                        class="w-full bg-slate-50 dark:bg-slate-800 pl-11 pr-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                </div>
                
                <div class="text-xs font-bold text-slate-400 dark:text-slate-500 flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-base text-tertiary">tune</span>
                    <span>Lista filtrada (Médicos excluidos)</span>
                </div>
            </div>
        </div>

        <!-- Tabla de Usuarios Administrativos -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-50/80 dark:bg-slate-800/80 border-b border-slate-200/80 dark:border-slate-800 text-[11px] font-extrabold text-slate-500 dark:text-slate-300 uppercase tracking-wider">
                            <th class="py-4 px-6">Usuario Administrativo</th>
                            <th class="py-4 px-6">Cédula / Documento</th>
                            <th class="py-4 px-6">Correo Electrónico</th>
                            <th class="py-4 px-6">Rol Asignado</th>
                            <th class="py-4 px-6 text-center">Permisos Especiales</th>
                            <th class="py-4 px-6 text-center">Estado</th>
                            <th class="py-4 px-6 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($usuariosInternos)): ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-400 font-semibold">
                                    <span class="material-symbols-outlined text-4xl mb-2 text-slate-300 block">no_accounts</span>
                                    No se encontraron usuarios administrativos registrados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($usuariosInternos as $u): ?>
                                <?php 
                                    $nombreUser = !empty($u['nombre_completo']) ? $u['nombre_completo'] : 'Usuario #' . $u['usuario_id'];
                                    $rolNombre  = $u['rol_nombre'] ?? 'Sin rol';
                                    $rolId      = $u['rol_id'];
                                    $isActivo   = ($u['estado'] === '1' || $u['estado'] === 1);
                                    
                                    $isAdmin = ($rolId == '1' || $rolId == 1 || strtolower((string)$rolNombre) === 'admin');
                                    $isFinan = ($rolId == '2' || $rolId == 2 || strtolower((string)$rolNombre) === 'financiero');
                                    
                                    $permMap = $u['permisos_map'] ?? array();
                                    $countPerms = 0;
                                    foreach ($permMap as $k => $v) {
                                        if ($v) $countPerms++;
                                    }
                                ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/50 transition-colors user-row border-b border-slate-100 dark:border-slate-800">
                                    
                                    <!-- Usuario & Foto -->
                                    <td class="py-4 px-6 font-bold text-primary dark:text-slate-100">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden flex items-center justify-center shrink-0">
                                                <?php if (!empty($u['foto_perfil']) && file_exists(__DIR__ . '/' . $u['foto_perfil'])): ?>
                                                    <img src="<?php echo htmlspecialchars($u['foto_perfil']); ?>" class="w-full h-full object-cover" />
                                                <?php else: ?>
                                                    <span class="font-extrabold text-xs <?php echo $isAdmin ? 'text-purple-700 dark:text-purple-300' : ($isFinan ? 'text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-300'); ?>">
                                                        <?php echo strtoupper(mb_substr($nombreUser, 0, 2)); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="font-extrabold text-primary dark:text-white text-xs"><?php echo htmlspecialchars($nombreUser); ?></p>
                                                <p class="text-[10px] font-semibold text-slate-400 dark:text-slate-500 font-mono">ID SQL: #<?php echo $u['usuario_id']; ?></p>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Cédula -->
                                    <td class="py-4 px-6 font-semibold text-slate-600 dark:text-slate-300 font-mono">
                                        <?php echo htmlspecialchars($u['cedula'] ?: 'No registrada'); ?>
                                    </td>

                                    <!-- Correo -->
                                    <td class="py-4 px-6 font-medium text-slate-600 dark:text-slate-300">
                                        <?php echo htmlspecialchars($u['email']); ?>
                                    </td>

                                    <!-- Rol -->
                                    <td class="py-4 px-6 font-bold">
                                        <?php if ($isAdmin): ?>
                                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-purple-100 dark:bg-purple-950/70 text-purple-800 dark:text-purple-300 border border-purple-200 dark:border-purple-800 text-[10px] font-extrabold uppercase">
                                                <span class="material-symbols-outlined text-xs">shield</span> Admin
                                            </span>
                                        <?php elseif ($isFinan): ?>
                                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-blue-100 dark:bg-blue-950/70 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-800 text-[10px] font-extrabold uppercase">
                                                <span class="material-symbols-outlined text-xs">payments</span> Financiero
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 text-[10px] font-extrabold uppercase">
                                                <span class="material-symbols-outlined text-xs">person</span> <?php echo htmlspecialchars($rolNombre); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Permisos Especiales Badges -->
                                    <td class="py-4 px-6 text-center">
                                        <?php if ($isAdmin): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-extrabold">
                                                Acceso Total Ilimitado
                                            </span>
                                        <?php else: ?>
                                            <button type="button" 
                                                onclick='openPermisosModal(<?php echo $u['usuario_id']; ?>, <?php echo json_encode($nombreUser); ?>, <?php echo json_encode($rolNombre); ?>, <?php echo json_encode($permMap); ?>)'
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-tertiary/10 hover:bg-tertiary/20 text-tertiary font-extrabold text-[11px] transition-all cursor-pointer border border-tertiary/20 hover:scale-105">
                                                <span class="material-symbols-outlined text-xs">tune</span>
                                                <span><?php echo $countPerms; ?> Módulo(s) Habilitado(s)</span>
                                            </button>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Estado -->
                                    <td class="py-4 px-6 text-center">
                                        <?php if ($isActivo): ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-100/80 text-emerald-800 text-[10px] font-extrabold uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-100/80 text-rose-800 text-[10px] font-extrabold uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="py-4 px-6 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button"
                                                onclick='openEditarUsuarioModal(<?php echo json_encode($u); ?>)'
                                                title="Editar Datos del Usuario"
                                                class="p-2 rounded-xl bg-slate-100 hover:bg-tertiary hover:text-white text-slate-600 dark:bg-slate-800 dark:hover:bg-tertiary dark:text-slate-300 transition-colors cursor-pointer">
                                                <span class="material-symbols-outlined text-base">edit</span>
                                            </button>

                                            <?php if (!$isAdmin): ?>
                                                <button type="button"
                                                    onclick='openPermisosModal(<?php echo $u['usuario_id']; ?>, <?php echo json_encode($nombreUser); ?>, <?php echo json_encode($rolNombre); ?>, <?php echo json_encode($permMap); ?>)'
                                                    title="Configurar Permisos Especiales"
                                                    class="p-2 rounded-xl bg-slate-100 hover:bg-tertiary hover:text-white text-slate-600 dark:bg-slate-800 dark:hover:bg-tertiary dark:text-slate-300 transition-colors cursor-pointer">
                                                    <span class="material-symbols-outlined text-base">key</span>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($isActivo): ?>
                                                <button type="button" 
                                                    onclick="confirmarToggleEstadoUsuario(<?php echo $u['usuario_id']; ?>, '<?php echo $u['estado']; ?>', '<?php echo htmlspecialchars(addslashes($nombreUser)); ?>')" 
                                                    title="Desactivar Usuario" 
                                                    class="p-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 dark:bg-rose-950/50 dark:hover:bg-rose-900/80 dark:text-rose-300 border border-rose-200 dark:border-rose-800 font-bold transition-colors cursor-pointer">
                                                    <span class="material-symbols-outlined text-base">block</span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" 
                                                    onclick="confirmarToggleEstadoUsuario(<?php echo $u['usuario_id']; ?>, '<?php echo $u['estado']; ?>', '<?php echo htmlspecialchars(addslashes($nombreUser)); ?>')" 
                                                    title="Activar Usuario" 
                                                    class="p-2 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50 dark:hover:bg-emerald-900/80 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 font-bold transition-colors cursor-pointer">
                                                    <span class="material-symbols-outlined text-base">check_circle</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- MODAL 1: Configuración de Permisos Especiales por Módulo -->
    <div id="permisosModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 dark:bg-black/80 backdrop-blur-sm px-4 overflow-y-auto py-6">
        <div class="bg-white dark:bg-[#0c1427] rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 dark:border-slate-800 transform transition-all duration-300 animate-card-entry my-auto">
            
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-tertiary/15 text-tertiary flex items-center justify-center font-bold shrink-0">
                        <span class="material-symbols-outlined text-xl">key_visualizer</span>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-primary dark:text-white">Gestionar Permisos Especiales</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium" id="modalUserNameTarget">Usuario: -</p>
                    </div>
                </div>
                <button id="closePermisosModalBtn" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="guardar_permisos" />
                <input type="hidden" name="target_user_id" id="modalTargetUserId" value="0" />

                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-3">
                    Active los interruptores de los módulos a los que desea dar <strong>acceso especial de visualización y uso</strong> para este usuario:
                </p>

                <div class="space-y-2.5 max-h-[340px] overflow-y-auto pr-1">
                    <?php foreach ($modulosSistema as $mClave => $mInfo): ?>
                        <div class="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between gap-3 hover:border-tertiary/50 transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="p-2 rounded-xl bg-slate-200/70 dark:bg-slate-700 text-slate-700 dark:text-tertiary shrink-0">
                                    <span class="material-symbols-outlined text-lg"><?php echo htmlspecialchars($mInfo['icono']); ?></span>
                                </div>
                                <div>
                                    <h4 class="text-xs font-bold text-slate-900 dark:text-white leading-tight"><?php echo htmlspecialchars($mInfo['nombre']); ?></h4>
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400 leading-snug mt-0.5"><?php echo htmlspecialchars($mInfo['descripcion']); ?></p>
                                </div>
                            </div>
                            
                            <!-- Switch Interactivo -->
                            <label class="relative inline-flex items-center cursor-pointer shrink-0 ml-2">
                                <input type="checkbox" name="modulos_permiso[]" value="<?php echo htmlspecialchars($mClave); ?>" id="switch_<?php echo htmlspecialchars($mClave); ?>" class="sr-only switch-toggle" />
                                <div class="w-11 h-6 bg-slate-300 dark:bg-slate-700 rounded-full transition-colors switch-bg flex items-center px-0.5">
                                    <div class="w-5 h-5 bg-white rounded-full transition-transform switch-dot shadow-sm"></div>
                                </div>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800 mt-6">
                    <button type="button" id="cancelPermisosModalBtn" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-tertiary hover:bg-[#00b2af] text-white shadow-md transition-all flex items-center gap-2 cursor-pointer hover:scale-[1.02]">
                        <span class="material-symbols-outlined text-sm">save</span>
                        <span>Guardar Permisos Especiales</span>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- MODAL 2: Registrar Nuevo Usuario Administrativo -->
    <div id="createUserModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 dark:bg-black/80 backdrop-blur-sm px-4 overflow-y-auto py-6">
        <div class="bg-white dark:bg-[#0c1427] rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200 dark:border-slate-800 transform transition-all duration-300 animate-card-entry my-auto">
            
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-purple-100 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 flex items-center justify-center font-bold shrink-0">
                        <span class="material-symbols-outlined text-xl">person_add</span>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-primary dark:text-white">Registrar Usuario Administrativo</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium">Formulario exclusivo para personal interno / financiero</p>
                    </div>
                </div>
                <button id="closeCreateUserModalBtn" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="crear_usuario_interno" />

                <!-- Rol -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1" for="modal_rol_id">Rol de Usuario *</label>
                    <select name="rol_id" id="modal_rol_id" required class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-bold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none">
                        <?php foreach ($rolesList as $r): ?>
                            <option value="<?php echo htmlspecialchars($r['id']); ?>" <?php echo ($r['id'] == 2) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Nombre Completo -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Nombre Completo *</label>
                    <input type="text" name="nombre_completo" required placeholder="Ej: CARLOS ALBERTO FINANCIERO" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                </div>

                <!-- Cédula & Correo -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Cédula / Documento *</label>
                        <input type="text" name="cedula" required placeholder="Ej: 1098765432" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                        <input type="email" name="email" required placeholder="financiero@hernanocazionez.com" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                    </div>
                </div>

                <!-- Fecha de Nacimiento -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Fecha de Nacimiento</label>
                    <input type="date" name="fecha_nacimiento" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800 mt-6">
                    <button type="button" id="cancelCreateUserModalBtn" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-primary hover:bg-[#1b4363] dark:bg-tertiary dark:hover:bg-[#00b2af] text-white shadow-md transition-all flex items-center gap-2 cursor-pointer hover:scale-[1.02]">
                        <span class="material-symbols-outlined text-sm">person_add</span>
                        <span>Guardar Usuario</span>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- MODAL 3: Editar Usuario Administrativo -->
    <div id="editUserModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 dark:bg-black/80 backdrop-blur-sm px-4 overflow-y-auto py-6">
        <div class="bg-white dark:bg-[#0c1427] rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 dark:border-slate-800 transform transition-all duration-300 animate-card-entry my-auto">
            
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-tertiary/15 text-tertiary flex items-center justify-center font-bold shrink-0">
                        <span class="material-symbols-outlined text-xl">manage_accounts</span>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-primary dark:text-white">Editar Datos del Usuario</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium" id="editUserModalSubtitle">Modifique el nombre, cédula, correo o rol asignado</p>
                    </div>
                </div>
                <button id="closeEditUserModalBtn" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form method="POST" class="space-y-5" id="formEditarUsuario">
                <input type="hidden" name="action" value="editar_usuario_interno" />
                <input type="hidden" name="user_id" id="edit_user_id" value="0" />
                <input type="hidden" name="rol_id" id="edit_rol_id" value="2" />

                <!-- Selector Visual Interactivo de Roles -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider">
                            Rol Asignado *
                        </label>
                        <span class="text-[10px] text-slate-400 dark:text-slate-400 font-semibold">Haga clic sobre el rol deseado</span>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5" id="rolesSelectorGrid">
                        <?php foreach ($rolesList as $r): ?>
                            <?php 
                                $rId = $r['id'];
                                $v = obtenerVisualRolUsuario($rId, $r['nombre'], $r['descripcion'] ?? '', $r['color_tema'] ?? '');
                            ?>
                            <div class="edit-role-card cursor-pointer p-3 rounded-2xl border-2 transition-all relative flex flex-col justify-between group select-none border-slate-200 dark:border-slate-700/80 bg-slate-50 dark:bg-slate-800/80 hover:bg-slate-100 dark:hover:bg-slate-800"
                                 data-role-id="<?php echo htmlspecialchars($rId); ?>"
                                 onclick="selectEditRole(<?php echo htmlspecialchars($rId); ?>)">
                                
                                <div class="flex items-start justify-between gap-1 mb-2">
                                    <div class="w-8 h-8 rounded-xl bg-slate-200/70 dark:bg-slate-700 text-slate-700 dark:text-slate-300 flex items-center justify-center shrink-0 role-icon-box transition-colors">
                                        <span class="material-symbols-outlined text-lg"><?php echo $v['icon']; ?></span>
                                    </div>
                                    <span class="role-check-indicator material-symbols-outlined text-tertiary text-lg font-bold hidden">check_circle</span>
                                </div>

                                <div>
                                    <h4 class="text-xs font-bold text-slate-900 dark:text-white leading-tight">
                                        <?php echo htmlspecialchars($r['nombre']); ?>
                                    </h4>
                                    <p class="text-[10px] text-slate-500 dark:text-slate-400 font-medium line-clamp-2 mt-0.5 leading-snug">
                                        <?php echo htmlspecialchars($v['desc']); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Nombre Completo -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Nombre Completo *</label>
                    <input type="text" name="nombre_completo" id="edit_nombre_completo" required placeholder="Nombre completo" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                </div>

                <!-- Cédula & Correo -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Cédula / Documento *</label>
                        <input type="text" name="cedula" id="edit_cedula" required placeholder="Cédula" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                        <input type="email" name="email" id="edit_email" required placeholder="correo@ejemplo.com" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                    </div>
                </div>

                <!-- Fecha de Nacimiento -->
                <div>
                    <label class="block text-[11px] font-bold text-primary dark:text-slate-200 uppercase tracking-wider mb-1">Fecha de Nacimiento</label>
                    <input type="date" name="fecha_nacimiento" id="edit_fecha_nacimiento" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                </div>

                <!-- Sección de Contraseña con Switch Desplegable y Confirmación -->
                <div class="rounded-2xl border border-slate-200 dark:border-slate-700/80 bg-slate-100/70 dark:bg-slate-800/80 p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-lg">lock_reset</span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-900 dark:text-white">¿Desea cambiar la contraseña de acceso?</p>
                                <p class="text-[10px] text-slate-500 dark:text-slate-400 font-medium">Active esta opción solo si requiere reasignar una nueva clave</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer shrink-0 ml-3">
                            <input type="checkbox" id="toggle_cambiar_clave" name="cambiar_clave" value="1" class="sr-only switch-toggle" onchange="togglePasswordFields(this.checked)">
                            <div class="w-11 h-6 bg-slate-300 dark:bg-slate-600 rounded-full switch-bg transition-colors duration-200 flex items-center px-0.5">
                                <div class="w-5 h-5 bg-white rounded-full shadow-md switch-dot transition-transform duration-200"></div>
                            </div>
                        </label>
                    </div>

                    <!-- Campos Desplegables de Contraseña -->
                    <div id="password_fields_container" class="hidden pt-3 border-t border-slate-200 dark:border-slate-700 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <!-- Nueva Clave -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">
                                    Nueva Contraseña *
                                </label>
                                <div class="relative">
                                    <input type="password" name="nueva_clave" id="edit_nueva_clave" minlength="6" placeholder="Mínimo 6 caracteres" autocomplete="new-password" class="w-full bg-white dark:bg-slate-900 px-3.5 py-2.5 pr-10 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                                    <button type="button" onclick="toggleShowPassword('edit_nueva_clave', this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 cursor-pointer">
                                        <span class="material-symbols-outlined text-lg leading-none">visibility</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Confirmar Clave -->
                            <div>
                                <label class="block text-[10px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">
                                    Confirmar Contraseña *
                                </label>
                                <div class="relative">
                                    <input type="password" name="confirmar_clave" id="edit_confirmar_clave" minlength="6" placeholder="Repita la contraseña" autocomplete="new-password" class="w-full bg-white dark:bg-slate-900 px-3.5 py-2.5 pr-10 rounded-xl border border-slate-300 dark:border-slate-700 text-xs font-semibold text-slate-900 dark:text-slate-100 focus:ring-2 focus:ring-tertiary/30 outline-none transition-all" />
                                    <button type="button" onclick="toggleShowPassword('edit_confirmar_clave', this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 cursor-pointer">
                                        <span class="material-symbols-outlined text-lg leading-none">visibility</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Indicador de validación de contraseña en vivo -->
                        <div id="pwd_validation_status" class="text-[11px] font-bold flex items-center gap-1.5 transition-all hidden"></div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                    <button type="button" id="cancelEditUserModalBtn" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-tertiary hover:bg-[#00b2af] text-white shadow-md transition-all flex items-center gap-2 cursor-pointer hover:scale-[1.02]">
                        <span class="material-symbols-outlined text-sm">check_circle</span>
                        <span>Guardar Cambios</span>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <script>
        // Modal 1: Permisos
        const permisosModal = document.getElementById('permisosModal');
        const closePermisosModalBtn = document.getElementById('closePermisosModalBtn');
        const cancelPermisosModalBtn = document.getElementById('cancelPermisosModalBtn');
        const modalUserNameTarget = document.getElementById('modalUserNameTarget');
        const modalTargetUserId = document.getElementById('modalTargetUserId');

        function openPermisosModal(userId, userName, rolNombre, permMap) {
            modalTargetUserId.value = userId;
            modalUserNameTarget.textContent = 'Usuario: ' + userName + ' (' + rolNombre + ')';

            // Desmarcar todos los switches primero
            document.querySelectorAll('#permisosModal input[type="checkbox"]').forEach(cb => cb.checked = false);

            // Marcar switches segun permMap
            if (permMap) {
                for (const [mKey, val] of Object.entries(permMap)) {
                    const chk = document.getElementById('switch_' + mKey);
                    if (chk) {
                        chk.checked = (val === true || val === 1 || val === "1");
                    }
                }
            }

            permisosModal.classList.remove('hidden');
        }

        if (closePermisosModalBtn) closePermisosModalBtn.onclick = () => permisosModal.classList.add('hidden');
        if (cancelPermisosModalBtn) cancelPermisosModalBtn.onclick = () => permisosModal.classList.add('hidden');

        // Modal 2: Crear Usuario
        const createUserModal = document.getElementById('createUserModal');
        const openCreateUserModalBtn = document.getElementById('openCreateUserModalBtn');
        const closeCreateUserModalBtn = document.getElementById('closeCreateUserModalBtn');
        const cancelCreateUserModalBtn = document.getElementById('cancelCreateUserModalBtn');

        if (openCreateUserModalBtn) openCreateUserModalBtn.onclick = () => createUserModal.classList.remove('hidden');
        if (closeCreateUserModalBtn) closeCreateUserModalBtn.onclick = () => createUserModal.classList.add('hidden');
        if (cancelCreateUserModalBtn) cancelCreateUserModalBtn.onclick = () => createUserModal.classList.add('hidden');

        // Modal 3: Editar Usuario
        const editUserModal = document.getElementById('editUserModal');
        const closeEditUserModalBtn = document.getElementById('closeEditUserModalBtn');
        const cancelEditUserModalBtn = document.getElementById('cancelEditUserModalBtn');
        const formEditarUsuario = document.getElementById('formEditarUsuario');

        // Selección interactiva de roles en tarjetas
        function selectEditRole(roleId) {
            const inputRol = document.getElementById('edit_rol_id');
            if (inputRol) inputRol.value = roleId;

            document.querySelectorAll('.edit-role-card').forEach(card => {
                const cardId = card.getAttribute('data-role-id');
                const isMatch = String(cardId) === String(roleId);
                const checkIcon = card.querySelector('.role-check-indicator');
                const iconBox = card.querySelector('.role-icon-box');

                if (isMatch) {
                    card.classList.add('border-tertiary', 'bg-teal-50/70', 'dark:bg-teal-950/40', 'ring-2', 'ring-tertiary/20');
                    card.classList.remove('border-slate-200', 'dark:border-slate-700/80', 'bg-slate-50', 'dark:bg-slate-800/80');
                    if (checkIcon) checkIcon.classList.remove('hidden');
                    if (iconBox) {
                        iconBox.classList.add('bg-tertiary', 'text-white');
                        iconBox.classList.remove('bg-slate-200/70', 'dark:bg-slate-700', 'text-slate-700', 'dark:text-slate-300');
                    }
                } else {
                    card.classList.remove('border-tertiary', 'bg-teal-50/70', 'dark:bg-teal-950/40', 'ring-2', 'ring-tertiary/20');
                    card.classList.add('border-slate-200', 'dark:border-slate-700/80', 'bg-slate-50', 'dark:bg-slate-800/80');
                    if (checkIcon) checkIcon.classList.add('hidden');
                    if (iconBox) {
                        iconBox.classList.remove('bg-tertiary', 'text-white');
                        iconBox.classList.add('bg-slate-200/70', 'dark:bg-slate-700', 'text-slate-700', 'dark:text-slate-300');
                    }
                }
            });
        }

        // Desplegar / ocultar campos de contraseña
        function togglePasswordFields(show) {
            const container = document.getElementById('password_fields_container');
            const p1 = document.getElementById('edit_nueva_clave');
            const p2 = document.getElementById('edit_confirmar_clave');
            if (!container || !p1 || !p2) return;

            if (show) {
                container.classList.remove('hidden');
                p1.setAttribute('required', 'required');
                p2.setAttribute('required', 'required');
                p1.focus();
                validatePasswordsMatch();
            } else {
                container.classList.add('hidden');
                p1.removeAttribute('required');
                p2.removeAttribute('required');
                p1.value = '';
                p2.value = '';
                const statusDiv = document.getElementById('pwd_validation_status');
                if (statusDiv) {
                    statusDiv.classList.add('hidden');
                    statusDiv.innerHTML = '';
                }
            }
        }

        // Mostrar / ocultar texto de contraseña con icono de ojo
        function toggleShowPassword(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const icon = btn.querySelector('.material-symbols-outlined');
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) icon.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                if (icon) icon.textContent = 'visibility';
            }
        }

        // Validación en tiempo real de contraseñas
        function validatePasswordsMatch() {
            const p1 = document.getElementById('edit_nueva_clave');
            const p2 = document.getElementById('edit_confirmar_clave');
            const statusDiv = document.getElementById('pwd_validation_status');
            if (!p1 || !p2 || !statusDiv) return;

            const val1 = p1.value;
            const val2 = p2.value;

            if (!val1 && !val2) {
                statusDiv.classList.add('hidden');
                statusDiv.innerHTML = '';
                return;
            }

            statusDiv.classList.remove('hidden');
            if (val1.length < 6) {
                statusDiv.className = 'text-[11px] font-bold flex items-center gap-1.5 text-amber-600 dark:text-amber-400';
                statusDiv.innerHTML = '<span class="material-symbols-outlined text-sm">info</span> Mínimo 6 caracteres requeridos';
                return;
            }

            if (val2.length > 0) {
                if (val1 === val2) {
                    statusDiv.className = 'text-[11px] font-bold flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400';
                    statusDiv.innerHTML = '<span class="material-symbols-outlined text-sm">check_circle</span> Las contraseñas coinciden';
                } else {
                    statusDiv.className = 'text-[11px] font-bold flex items-center gap-1.5 text-rose-600 dark:text-rose-400';
                    statusDiv.innerHTML = '<span class="material-symbols-outlined text-sm">cancel</span> Las contraseñas no coinciden';
                }
            } else {
                statusDiv.className = 'text-[11px] font-bold flex items-center gap-1.5 text-blue-600 dark:text-blue-400';
                statusDiv.innerHTML = '<span class="material-symbols-outlined text-sm">lock</span> Escriba la confirmación de la contraseña';
            }
        }

        const inputP1 = document.getElementById('edit_nueva_clave');
        const inputP2 = document.getElementById('edit_confirmar_clave');
        if (inputP1) inputP1.addEventListener('input', validatePasswordsMatch);
        if (inputP2) inputP2.addEventListener('input', validatePasswordsMatch);

        function openEditarUsuarioModal(u) {
            if (!u) return;
            document.getElementById('edit_user_id').value = u.usuario_id || u.id || '0';
            document.getElementById('edit_nombre_completo').value = u.nombre_completo || '';
            document.getElementById('edit_cedula').value = u.cedula || '';
            document.getElementById('edit_email').value = u.email || '';
            document.getElementById('edit_fecha_nacimiento').value = u.fecha_nacimiento || '';
            document.getElementById('editUserModalSubtitle').textContent = 'ID SQL: #' + (u.usuario_id || u.id) + ' • ' + (u.email || '');

            // Preseleccionar rol visualmente
            const currentRolId = (u.rol_id !== undefined && u.rol_id !== null) ? u.rol_id : 2;
            selectEditRole(currentRolId);

            // Reiniciar toggle y campos de contraseña
            const toggleClave = document.getElementById('toggle_cambiar_clave');
            if (toggleClave) {
                toggleClave.checked = false;
                togglePasswordFields(false);
            }

            // Restaurar tipos de inputs a password
            const p1 = document.getElementById('edit_nueva_clave');
            const p2 = document.getElementById('edit_confirmar_clave');
            if (p1) p1.type = 'password';
            if (p2) p2.type = 'password';
            document.querySelectorAll('#password_fields_container .material-symbols-outlined').forEach(icon => {
                if (icon.textContent === 'visibility_off') icon.textContent = 'visibility';
            });

            editUserModal.classList.remove('hidden');
        }

        if (closeEditUserModalBtn) closeEditUserModalBtn.onclick = () => editUserModal.classList.add('hidden');
        if (cancelEditUserModalBtn) cancelEditUserModalBtn.onclick = () => editUserModal.classList.add('hidden');

        // Validación de confirmación de contraseña en el submit
        if (formEditarUsuario) {
            formEditarUsuario.addEventListener('submit', function(e) {
                const toggleClave = document.getElementById('toggle_cambiar_clave');
                if (toggleClave && toggleClave.checked) {
                    const p1 = document.getElementById('edit_nueva_clave').value.trim();
                    const p2 = document.getElementById('edit_confirmar_clave').value.trim();
                    if (p1.length < 6) {
                        e.preventDefault();
                        SwalCustom.fire({
                            icon: 'warning',
                            title: 'Contraseña muy corta',
                            text: 'La nueva contraseña debe tener al menos 6 caracteres.'
                        });
                        return false;
                    }
                    if (p1 !== p2) {
                        e.preventDefault();
                        SwalCustom.fire({
                            icon: 'error',
                            title: 'Las contraseñas no coinciden',
                            text: 'Por favor verifique que la confirmación de la contraseña sea idéntica a la nueva contraseña ingresada.'
                        });
                        return false;
                    }
                }
            });
        }

        // SweetAlert2 estilizado
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
            showClass: {
                popup: 'animate__animated animate__fadeInDown animate__faster'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOutUp animate__faster'
            }
        });

        async function confirmarToggleEstadoUsuario(userId, estadoActual, userName) {
            const esActivo = (estadoActual === 'ACTIVO' || estadoActual === '1');
            const titulo = esActivo ? '¿Desactivar Usuario Administrativo?' : '¿Activar Usuario Administrativo?';
            const accionTexto = esActivo ? 'desactivar el acceso' : 'habilitar el acceso';

            const result = await SwalCustom.fire({
                title: titulo,
                icon: esActivo ? 'warning' : 'question',
                html: `
                    <div class="p-3.5 rounded-2xl bg-slate-800/90 border border-slate-700/80 text-left my-2 space-y-2">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400 font-medium">Usuario:</span>
                            <span class="font-bold text-white">${userName}</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-400 font-medium">Estado Actual:</span>
                            <span class="font-bold font-mono ${esActivo ? 'text-emerald-400' : 'text-rose-400'}">${esActivo ? 'ACTIVO' : 'INACTIVO'}</span>
                        </div>
                    </div>
                    <p class="text-xs text-slate-300 text-center mt-2.5">
                        ¿Confirma que desea <strong>${accionTexto}</strong> de este usuario en la plataforma?
                    </p>
                `,
                showCancelButton: true,
                confirmButtonText: esActivo ? '<i class="fa-solid fa-ban mr-1.5"></i> Sí, Desactivar' : '<i class="fa-solid fa-check-circle mr-1.5"></i> Sí, Activar',
                cancelButtonText: '<i class="fa-solid fa-xmark mr-1.5"></i> Cancelar'
            });

            if (!result.isConfirmed) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actInput = document.createElement('input');
            actInput.type = 'hidden';
            actInput.name = 'action';
            actInput.value = 'toggle_estado_interno';
            form.appendChild(actInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'user_id';
            idInput.value = userId;
            form.appendChild(idInput);

            const stInput = document.createElement('input');
            stInput.type = 'hidden';
            stInput.name = 'estado_actual';
            stInput.value = estadoActual;
            form.appendChild(stInput);

            document.body.appendChild(form);
            form.submit();
        }

        // Buscador en Vivo en la Tabla
        const searchInput = document.getElementById('searchInput');
        const userRows = document.querySelectorAll('.user-row');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const term = searchInput.value.toLowerCase().trim();
                userRows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(term) ? '' : 'none';
                });
            });
        }
    </script>

</body>
</html>

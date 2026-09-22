<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

$userRole = strtoupper($_SESSION['user_role'] ?? 'MÉDICO');
$userId   = (int)($_SESSION['user_id'] ?? 0);
$userRoleIdVal = (int)($_SESSION['user_role_id'] ?? 0);

require_once(__DIR__ . '/includes/permisos_helper.php');

// Restringir acceso solo a ADMINISTRADOR o usuarios con permiso del módulo
if ($userRole !== 'ADMINISTRADOR' && $userRoleIdVal !== 1 && !tienePermisoModulo($userId, $userRoleIdVal, 'medicos')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

require_once(__DIR__ . '/config/conexion.php');

$msgSuccess = $_SESSION['flash_msg_success'] ?? '';
$msgError   = $_SESSION['flash_msg_error'] ?? '';
unset($_SESSION['flash_msg_success'], $_SESSION['flash_msg_error']);

// Manejo de Acciones POST (Crear, Editar, Cambiar Estado)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'crear_medico' || $action === 'crear_usuario') {
        $rolId    = trim($_POST['rol_id'] ?? '3');
        $cedula   = trim($_POST['cedula'] ?? '');
        $proteo   = trim($_POST['usuario_proteo'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pnom     = strtoupper(trim($_POST['pnom'] ?? ''));
        $snom     = strtoupper(trim($_POST['snom'] ?? ''));
        $pape     = strtoupper(trim($_POST['pape'] ?? ''));
        $sape     = strtoupper(trim($_POST['sape'] ?? ''));
        $fechaNac = trim($_POST['fecha_nacimiento'] ?? '');

        $nombreCompleto = trim("$pnom $snom $pape $sape");
        if (empty($nombreCompleto)) {
            $nombreCompleto = trim($_POST['nombre_completo'] ?? '');
        }

        if (empty($email) || empty($nombreCompleto) || empty($cedula)) {
            $_SESSION['flash_msg_error'] = "Por favor diligencie todos los campos obligatorios (Cédula, Correo, Nombres, Apellidos).";
            header("Location: medicos.php");
            exit;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_msg_error'] = "Por favor ingrese un correo electrónico válido.";
            header("Location: medicos.php");
            exit;
        } else {
            if (isset($con) && $con !== false) {
                // Verificar duplicado por email o proteo
                $sqlCheck = "SELECT TOP 1 u.id FROM usuarios u LEFT JOIN medicos m ON u.id = m.usuario_id 
                             WHERE LOWER(u.email) = LOWER(?)" . (!empty($proteo) ? " OR LOWER(m.usuario_proteo) = LOWER(?)" : "");
                $paramsCheck = !empty($proteo) ? array($email, $proteo) : array($email);
                $stmtCheck = sqlsrv_query($con, $sqlCheck, $paramsCheck);

                if ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck)) {
                    $_SESSION['flash_msg_error'] = "Ya existe un usuario registrado con ese correo o Usuario Proteo.";
                    header("Location: medicos.php");
                    exit;
                } else {
                    $fechaActual = date('Y-m-d H:i:s');
                    $claveDummy  = password_hash("usuario_temp*", PASSWORD_DEFAULT);

                    // Modalidades dinámicas de pago
                    $modalidadesPost = $_POST['modalidades'] ?? [];
                    $modalidadesArr = [];
                    if (is_array($modalidadesPost)) {
                        foreach ($modalidadesPost as $modKey => $val) {
                            if ($val == '1' || $val === 'on') {
                                $modalidadesArr[] = strtoupper(trim($modKey));
                            }
                        }
                    }
                    if (isset($_POST['tarifas_especiales']) && (int)$_POST['tarifas_especiales'] === 1 && !in_array('TARIFAS_ESPECIALES', $modalidadesArr)) {
                        $modalidadesArr[] = 'TARIFAS_ESPECIALES';
                    }
                    if (isset($_POST['degluciones']) && (int)$_POST['degluciones'] === 1 && !in_array('DEGLUCIONES', $modalidadesArr)) {
                        $modalidadesArr[] = 'DEGLUCIONES';
                    }
                    $tarifasEspeciales = in_array('TARIFAS_ESPECIALES', $modalidadesArr) ? 1 : 0;
                    $degluciones       = in_array('DEGLUCIONES', $modalidadesArr) ? 1 : 0;
                    $modalidadesAdicionalesStr = implode(',', $modalidadesArr);

                    $parafiscales      = isset($_POST['parafiscales']) ? 1 : 0;
                    $pensionado        = isset($_POST['pensionado']) ? 1 : 0;
                    $retenciones       = isset($_POST['retenciones']) ? 1 : 0;
                    $retencionArt383   = isset($_POST['retencion_art_383']) ? 1 : 0;
                    if ($retenciones === 1 && $retencionArt383 === 1) {
                        $retencionArt383 = 0; // Exclusividad mutua: solo una de las dos o ninguna
                    }

                    $entidadId = (!empty($_POST['entidad_id']) && intval($_POST['entidad_id']) > 0) ? intval($_POST['entidad_id']) : null;

                    // Insertar en usuarios
                    $sqlInsU = "INSERT INTO usuarios (email, clave, rol_id, estado, fecha_creacion, ultima_sesion, fecha_nacimiento, nombre_completo, cedula, tarifas_especiales, degluciones, parafiscales, pensionado, retenciones, retencion_art_383, modalidades_adicionales, entidad_id) 
                                VALUES (?, ?, ?, '1', ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmtInsU = sqlsrv_query($con, $sqlInsU, array($email, $claveDummy, $rolId, $fechaActual, $fechaNac, $nombreCompleto, $cedula, $tarifasEspeciales, $degluciones, $parafiscales, $pensionado, $retenciones, $retencionArt383, $modalidadesAdicionalesStr, $entidadId));

                    if ($stmtInsU !== false) {
                        $stmtMax = sqlsrv_query($con, "SELECT MAX(id) AS new_id FROM usuarios WHERE LOWER(email) = LOWER(?)", array($email));
                        $rowMax = sqlsrv_fetch_array($stmtMax, SQLSRV_FETCH_ASSOC);
                        $newUserId = $rowMax['new_id'] ?? null;

                        if ($newUserId) {
                            $claveDefinitiva = password_hash("medico" . $newUserId . "*", PASSWORD_DEFAULT);
                            sqlsrv_query($con, "UPDATE usuarios SET clave = ? WHERE id = ?", array($claveDefinitiva, $newUserId));

                            // Si es médico (rol_id 3) o tiene datos de proteo, guardar en tabla medicos
                            if ($rolId == '3' || !empty($proteo)) {
                                if (empty($proteo)) {
                                    $proteo = 'C' . $cedula;
                                }
                                $sqlCheckIdM = "SELECT COLUMNPROPERTY(OBJECT_ID('medicos'), 'id', 'IsIdentity') AS is_identity";
                                $stmtIdM = sqlsrv_query($con, $sqlCheckIdM);
                                $isIdentityM = 0;
                                if ($stmtIdM !== false && $rowIdM = sqlsrv_fetch_array($stmtIdM, SQLSRV_FETCH_ASSOC)) {
                                    $isIdentityM = $rowIdM['is_identity'];
                                }

                                if ($isIdentityM == 1) {
                                    $sqlInsM = "INSERT INTO medicos (usuario_id, cedula, usuario_proteo, pnom, snom, pape, sape, fecha_nacimiento, tarifas_especiales, degluciones, parafiscales, pensionado, retenciones, retencion_art_383, modalidades_adicionales, entidad_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                    @sqlsrv_query($con, $sqlInsM, array($newUserId, $cedula, $proteo, $pnom, $snom, $pape, $sape, $fechaNac, $tarifasEspeciales, $degluciones, $parafiscales, $pensionado, $retenciones, $retencionArt383, $modalidadesAdicionalesStr, $entidadId));
                                } else {
                                    $sqlMaxM = "SELECT ISNULL(MAX(id), 0) + 1 AS next_id FROM medicos";
                                    $stmtMaxM = sqlsrv_query($con, $sqlMaxM);
                                    $rowMaxM = sqlsrv_fetch_array($stmtMaxM, SQLSRV_FETCH_ASSOC);
                                    $medicoId = $rowMaxM['next_id'];

                                    $sqlInsM = "INSERT INTO medicos (id, usuario_id, cedula, usuario_proteo, pnom, snom, pape, sape, fecha_nacimiento, tarifas_especiales, degluciones, parafiscales, pensionado, retenciones, retencion_art_383, modalidades_adicionales, entidad_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                    @sqlsrv_query($con, $sqlInsM, array($medicoId, $newUserId, $cedula, $proteo, $pnom, $snom, $pape, $sape, $fechaNac, $tarifasEspeciales, $degluciones, $parafiscales, $pensionado, $retenciones, $retencionArt383, $modalidadesAdicionalesStr, $entidadId));
                                }
                            }

                            require_once(__DIR__ . '/includes/email_logger.php');
                            $rolNombreStr = ($rolId == '1') ? 'ADMINISTRADOR' : (($rolId == '2') ? 'AUXILIAR' : 'MÉDICO');
                            $nombreUserMail = ($rolId == '3') ? "Dr. $nombreCompleto" : $nombreCompleto;
                            notificarActualizacionUsuario($newUserId, $email, $nombreUserMail, array(
                                'Registro en Plataforma' => "Se ha registrado su cuenta en LIHO con rol $rolNombreStr.",
                                'Correo Electrónico' => $email,
                                'Cédula / Documento' => $cedula
                            ));

                            $_SESSION['flash_msg_success'] = "Usuario ($rolNombreStr) registrado exitosamente en el sistema.";
                            header("Location: medicos.php");
                            exit;
                        }
                    } else {
                        $_SESSION['flash_msg_error'] = "Error al registrar el usuario en la base de datos SQL Server.";
                        header("Location: medicos.php");
                        exit;
                    }
                }
            } else {
                $_SESSION['flash_msg_error'] = "Error de conexión a la base de datos.";
                header("Location: medicos.php");
                exit;
            }
        }
    } elseif ($action === 'editar_medico') {
        if ($userRole !== 'ADMINISTRADOR') {
            $_SESSION['flash_msg_error'] = "Acceso denegado. Solo los usuarios administradores pueden actualizar datos.";
            header("Location: medicos.php");
            exit;
        }

        $userIdToEdit = intval($_POST['user_id'] ?? 0);
        $rolId        = trim($_POST['rol_id'] ?? '3');
        $cedula       = trim($_POST['cedula'] ?? '');
        $proteo       = trim($_POST['usuario_proteo'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $pnom         = strtoupper(trim($_POST['pnom'] ?? ''));
        $snom         = strtoupper(trim($_POST['snom'] ?? ''));
        $pape         = strtoupper(trim($_POST['pape'] ?? ''));
        $sape         = strtoupper(trim($_POST['sape'] ?? ''));
        $fechaNac     = trim($_POST['fecha_nacimiento'] ?? '');

        $nombreCompleto = trim("$pnom $snom $pape $sape");
        if (empty($nombreCompleto)) {
            $nombreCompleto = trim($_POST['nombre_completo'] ?? '');
        }

        if ($userIdToEdit <= 0) {
            $_SESSION['flash_msg_error'] = "Identificador de usuario no válido.";
            header("Location: medicos.php");
            exit;
        }

        if (empty($email) || empty($nombreCompleto) || empty($cedula)) {
            $_SESSION['flash_msg_error'] = "Por favor diligencie todos los campos obligatorios.";
            header("Location: medicos.php");
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_msg_error'] = "Por favor ingrese un correo electrónico válido.";
            header("Location: medicos.php");
            exit;
        }

        if (isset($con) && $con !== false) {
            $sqlCheck = "SELECT TOP 1 u.id FROM usuarios u LEFT JOIN medicos m ON u.id = m.usuario_id 
                         WHERE u.id <> ? AND (LOWER(u.email) = LOWER(?)" . (!empty($proteo) ? " OR LOWER(m.usuario_proteo) = LOWER(?)" : "") . ")";
            $paramsCheck = !empty($proteo) ? array($userIdToEdit, $email, $proteo) : array($userIdToEdit, $email);
            $stmtCheck = sqlsrv_query($con, $sqlCheck, $paramsCheck);

            if ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck)) {
                $_SESSION['flash_msg_error'] = "El correo electrónico o Usuario Proteo ya está asignado a otro usuario.";
                header("Location: medicos.php");
                exit;
            }

            // Modalidades dinámicas de pago
            $modalidadesPost = $_POST['modalidades'] ?? [];
            $modalidadesArr = [];
            if (is_array($modalidadesPost)) {
                foreach ($modalidadesPost as $modKey => $val) {
                    if ($val == '1' || $val === 'on') {
                        $modalidadesArr[] = strtoupper(trim($modKey));
                    }
                }
            }
            if (isset($_POST['tarifas_especiales']) && (int)$_POST['tarifas_especiales'] === 1 && !in_array('TARIFAS_ESPECIALES', $modalidadesArr)) {
                $modalidadesArr[] = 'TARIFAS_ESPECIALES';
            }
            if (isset($_POST['degluciones']) && (int)$_POST['degluciones'] === 1 && !in_array('DEGLUCIONES', $modalidadesArr)) {
                $modalidadesArr[] = 'DEGLUCIONES';
            }
            $tarifasEspeciales = in_array('TARIFAS_ESPECIALES', $modalidadesArr) ? 1 : 0;
            $degluciones       = in_array('DEGLUCIONES', $modalidadesArr) ? 1 : 0;
            $modalidadesAdicionalesStr = implode(',', $modalidadesArr);

            $parafiscales      = isset($_POST['parafiscales']) ? 1 : 0;
            $pensionado        = isset($_POST['pensionado']) ? 1 : 0;
            $retenciones       = isset($_POST['retenciones']) ? 1 : 0;
            $retencionArt383   = isset($_POST['retencion_art_383']) ? 1 : 0;
            if ($retenciones === 1 && $retencionArt383 === 1) {
                $retencionArt383 = 0; // Exclusividad mutua: solo una de las dos o ninguna
            }

            $entidadId = (!empty($_POST['entidad_id']) && intval($_POST['entidad_id']) > 0) ? intval($_POST['entidad_id']) : null;

            $sqlUpdU = "UPDATE usuarios SET email = ?, rol_id = ?, fecha_nacimiento = ?, nombre_completo = ?, cedula = ?, tarifas_especiales = ?, degluciones = ?, parafiscales = ?, pensionado = ?, retenciones = ?, retencion_art_383 = ?, modalidades_adicionales = ?, entidad_id = ? WHERE id = ?";
            $stmtUpdU = sqlsrv_query($con, $sqlUpdU, array($email, $rolId, $fechaNac, $nombreCompleto, $cedula, $tarifasEspeciales, $degluciones, $parafiscales, $pensionado, $retenciones, $retencionArt383, $modalidadesAdicionalesStr, $entidadId, $userIdToEdit));

            if ($stmtUpdU !== false) {
                $sqlCheckM = "SELECT id FROM medicos WHERE usuario_id = ?";
                $stmtCheckM = sqlsrv_query($con, $sqlCheckM, array($userIdToEdit));

                if ($stmtCheckM !== false && sqlsrv_has_rows($stmtCheckM)) {
                    $sqlUpdM = "UPDATE medicos SET cedula = ?, usuario_proteo = ?, pnom = ?, snom = ?, pape = ?, sape = ?, fecha_nacimiento = ?, tarifas_especiales = ?, degluciones = ?, parafiscales = ?, pensionado = ?, retenciones = ?, retencion_art_383 = ?, modalidades_adicionales = ?, entidad_id = ? WHERE usuario_id = ?";
                    sqlsrv_query($con, $sqlUpdM, array($cedula, $proteo, $pnom, $snom, $pape, $sape, $fechaNac, $tarifasEspeciales, $degluciones, $parafiscales, $pensionado, $retenciones, $retencionArt383, $modalidadesAdicionalesStr, $entidadId, $userIdToEdit));
                } else {
                    if ($rolId == '3' || !empty($proteo)) {
                        if (empty($proteo)) $proteo = 'C' . $cedula;
                        $sqlInsM = "INSERT INTO medicos (usuario_id, cedula, usuario_proteo, pnom, snom, pape, sape, fecha_nacimiento, tarifas_especiales, degluciones, parafiscales, pensionado, retenciones, retencion_art_383, modalidades_adicionales, entidad_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        @sqlsrv_query($con, $sqlInsM, array($userIdToEdit, $cedula, $proteo, $pnom, $snom, $pape, $sape, $fechaNac, $tarifasEspeciales, $degluciones, $parafiscales, $pensionado, $retenciones, $retencionArt383, $modalidadesAdicionalesStr, $entidadId));
                    }
                }

                require_once(__DIR__ . '/includes/email_logger.php');
                $rolNombreStr = ($rolId == '1') ? 'ADMINISTRADOR' : (($rolId == '2') ? 'AUXILIAR' : 'MÉDICO');
                notificarActualizacionUsuario($userIdToEdit, $email, $nombreCompleto, array(
                    'Actualización de Datos' => "Se han actualizado los datos del usuario por un Administrador.",
                    'Nombre Completo' => $nombreCompleto,
                    'Rol Registrado' => $rolNombreStr,
                    'Cédula' => $cedula,
                    'Modalidades' => (!empty($modalidadesAdicionalesStr) ? $modalidadesAdicionalesStr : 'Estándar')
                ));

                $_SESSION['flash_msg_success'] = "Los datos del médico/usuario '$nombreCompleto' han sido actualizados correctamente.";
            } else {
                $_SESSION['flash_msg_error'] = "Error al actualizar los datos en la base de datos.";
            }
        }
        header("Location: medicos.php");
        exit;
    } elseif ($action === 'toggle_pensionado') {
        header('Content-Type: application/json');
        if ($userRole !== 'ADMINISTRADOR') {
            echo json_encode(array('success' => false, 'error' => 'Acceso denegado.'));
            exit;
        }

        $targetUserId    = intval($_POST['user_id'] ?? 0);
        $nuevoPensionado = (trim($_POST['pensionado'] ?? '0') === '1' || $_POST['pensionado'] === true || $_POST['pensionado'] === 'true') ? 1 : 0;

        if ($targetUserId > 0 && isset($con) && $con !== false) {
            sqlsrv_query($con, "UPDATE usuarios SET pensionado = ? WHERE id = ?", array($nuevoPensionado, $targetUserId));
            sqlsrv_query($con, "UPDATE medicos SET pensionado = ? WHERE usuario_id = ?", array($nuevoPensionado, $targetUserId));
            echo json_encode(array('success' => true, 'pensionado' => $nuevoPensionado));
            exit;
        }

        echo json_encode(array('success' => false, 'error' => 'Error al actualizar el estado de pensionado.'));
        exit;
    } elseif ($action === 'toggle_estado') {
        $targetUserId = intval($_POST['user_id'] ?? 0);
        $nuevoEstado = (trim($_POST['estado_actual'] ?? '1') === '1') ? '0' : '1';

        if ($targetUserId > 0 && isset($con) && $con !== false) {
            $stmtToggle = sqlsrv_query($con, "UPDATE usuarios SET estado = ? WHERE id = ?", array($nuevoEstado, $targetUserId));
            if ($stmtToggle !== false) {
                // Notificar por correo
                $stmtTarget = sqlsrv_query($con, "SELECT u.email, u.nombre_completo, m.pnom, m.pape FROM usuarios u LEFT JOIN medicos m ON u.id = m.usuario_id WHERE u.id = ?", array($targetUserId));
                if ($stmtTarget !== false && $rowT = sqlsrv_fetch_array($stmtTarget, SQLSRV_FETCH_ASSOC)) {
                    require_once(__DIR__ . '/includes/email_logger.php');
                    $tEmail = $rowT['email'];
                    $tName  = $rowT['nombre_completo'] ?? trim(($rowT['pnom'] ?? '') . ' ' . ($rowT['pape'] ?? ''));
                    $tNameStr = !empty($tName) ? $tName : "Usuario";

                    $textoEstado = ($nuevoEstado === '1') ? 'ACTIVADA (Habilitada para ingresar)' : 'DESACTIVADA (Acceso suspendido temporalmente)';
                    notificarActualizacionUsuario($targetUserId, $tEmail, $tNameStr, array(
                        'Estado de Cuenta' => $textoEstado
                    ));
                }

                $_SESSION['flash_msg_success'] = ($nuevoEstado === '1') ? "La cuenta del usuario ha sido ACTIVADA." : "La cuenta del usuario ha sido DESACTIVADA.";
            } else {
                $_SESSION['flash_msg_error'] = "No se pudo actualizar el estado del usuario.";
            }
        }
        header("Location: medicos.php");
        exit;
    }
}

// Consultar Listado de Roles desde la tabla roles en SQL Server
$rolesList = array();
if (isset($con) && $con !== false) {
    $stmtRoles = sqlsrv_query($con, "SELECT id, nombre, estado FROM roles WHERE estado = 1 ORDER BY id ASC");
    if ($stmtRoles !== false) {
        while ($rRow = sqlsrv_fetch_array($stmtRoles, SQLSRV_FETCH_ASSOC)) {
            $rolesList[] = $rRow;
        }
    }
}
if (empty($rolesList)) {
    $rolesList = array(
        array('id' => 0, 'nombre' => 'Sin rol'),
        array('id' => 1, 'nombre' => 'Admin'),
        array('id' => 2, 'nombre' => 'Financiero'),
        array('id' => 3, 'nombre' => 'Medico')
    );
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

// Consultar Modalidades Activas desde el Maestro de Porcentajes de Pago
// Consultar Modalidades Activas desde el Maestro de Porcentajes de Pago
$modalidadesPagoList = [];
$modalidadesPagoMap  = [];
if (isset($con) && $con !== false) {
    $sqlMP = "SELECT id, tipo, ISNULL(nombre, tipo) AS nombre, ISNULL(color, 'emerald') AS color, porcentaje,
                     ISNULL(tipo_calculo, 'PORCENTAJE') AS tipo_calculo, ISNULL(valor_fijo, 0) AS valor_fijo,
                     ISNULL(entidad_id, 0) AS entidad_id
              FROM maestro_porcentajes_pago 
              WHERE ISNULL(estado, 1) = 1 
              ORDER BY ISNULL(entidad_id, 0) ASC, id ASC";
    $stmtMP = sqlsrv_query($con, $sqlMP);
    if ($stmtMP !== false) {
        while ($rmp = sqlsrv_fetch_array($stmtMP, SQLSRV_FETCH_ASSOC)) {
            $rmp['porcentaje']   = (float)$rmp['porcentaje'];
            $rmp['valor_fijo']   = (float)$rmp['valor_fijo'];
            $rmp['entidad_id']   = (int)$rmp['entidad_id'];
            $rmp['tipo_calculo'] = strtoupper(trim((string)($rmp['tipo_calculo'] ?? 'PORCENTAJE')));
            if ($rmp['tipo_calculo'] !== 'VALOR_FIJO') $rmp['tipo_calculo'] = 'PORCENTAJE';
            $modalidadesPagoList[] = $rmp;
            $modalidadesPagoMap[$rmp['tipo']] = $rmp;
        }
    }
}

// Consultar Entidades / IPS Activas desde Maestro de Entidades
$entidadesList = [];
$entidadesMap  = [];
if (isset($con) && $con !== false) {
    $sqlEnt = "SELECT id, nombre, nit, dv, email, telefono, ciudad, contacto_nombre, estado, logo, ISNULL(color_tema, 'teal') AS color_tema, is_matriz 
               FROM maestro_entidades 
               WHERE estado = 1 
               ORDER BY is_matriz DESC, nombre ASC";
    $stmtEnt = sqlsrv_query($con, $sqlEnt);
    if ($stmtEnt !== false) {
        while ($re = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
            $entidadesList[] = $re;
            $entidadesMap[$re['id']] = $re;
        }
    }
}

// Consultar Listado Completo de Usuarios y Médicos con Rol
$medicosList = array();
if (isset($con) && $con !== false) {
    $sqlSelect = "SELECT u.id AS usuario_id, u.email, u.estado, u.rol_id, u.fecha_creacion, u.fecha_nacimiento, u.foto_perfil, u.nombre_completo, u.cedula AS u_cedula,
                         r.nombre AS rol_nombre,
                         m.id AS medico_id, m.cedula AS m_cedula, m.usuario_proteo, m.pnom, m.snom, m.pape, m.sape,
                         ISNULL(u.entidad_id, m.entidad_id) AS entidad_id,
                         ent.nombre AS entidad_nombre, ent.nit AS entidad_nit, ent.dv AS entidad_dv, ent.email AS entidad_email, ent.logo AS entidad_logo,
                         ISNULL(m.tarifas_especiales, ISNULL(u.tarifas_especiales, 0)) AS tarifas_especiales,
                         ISNULL(m.degluciones, ISNULL(u.degluciones, 0)) AS degluciones,
                         ISNULL(m.parafiscales, ISNULL(u.parafiscales, 0)) AS parafiscales,
                         ISNULL(m.pensionado, ISNULL(u.pensionado, 0)) AS pensionado,
                         ISNULL(m.retenciones, ISNULL(u.retenciones, 0)) AS retenciones,
                         ISNULL(m.retencion_art_383, ISNULL(u.retencion_art_383, 0)) AS retencion_art_383,
                         ISNULL(m.modalidades_adicionales, ISNULL(u.modalidades_adicionales, '')) AS modalidades_adicionales
                  FROM usuarios u
                  LEFT JOIN roles r ON u.rol_id = r.id
                  LEFT JOIN medicos m ON u.id = m.usuario_id
                  LEFT JOIN maestro_entidades ent ON ISNULL(u.entidad_id, m.entidad_id) = ent.id
                  WHERE u.rol_id = '3' OR u.rol_id = 3
                  ORDER BY u.id DESC";
    $stmtSelect = sqlsrv_query($con, $sqlSelect);
    if ($stmtSelect !== false) {
        while ($row = sqlsrv_fetch_array($stmtSelect, SQLSRV_FETCH_ASSOC)) {
            $medicosList[] = $row;
        }
    }
}

// Conteo exacto de métricas y médicos por entidad
$countTotal = count($medicosList);
$countActivos = 0;
$countInactivos = 0;
$countPropios = 0;
$entidadesCounts = array();

foreach ($medicosList as $m) {
    $st = strtoupper(trim($m['estado'] ?? '1'));
    if ($st === '1') {
        $countActivos++;
    } else {
        $countInactivos++;
    }

    $eid = !empty($m['entidad_id']) ? strval($m['entidad_id']) : '';
    if ($eid === '') {
        $countPropios++;
    } else {
        if (!isset($entidadesCounts[$eid])) {
            $entidadesCounts[$eid] = 0;
        }
        $entidadesCounts[$eid]++;
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Gestión de Médicos | LIHO</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <!-- Google Fonts: Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet" />

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

    <!-- Header Navigation -->
    <?php include(__DIR__ . '/includes/navbar.php'); ?>

    <!-- Main Container -->
    <main class="flex-grow max-w-[1380px] w-full mx-auto px-4 sm:px-6 py-8">
        
        <!-- Alertas de Éxito o Error -->
        <?php if (!empty($msgSuccess)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 flex items-center justify-between shadow-sm animate-fade-in">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-emerald-600 dark:text-emerald-400 text-2xl">check_circle</span>
                    <span class="text-xs font-bold"><?php echo htmlspecialchars($msgSuccess); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if (!empty($msgError)): ?>
            <div class="mb-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/60 border border-rose-200 dark:border-rose-800 text-rose-800 dark:text-rose-300 flex items-center justify-between shadow-sm animate-fade-in">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-rose-600 dark:text-rose-400 text-2xl">error</span>
                    <span class="text-xs font-bold"><?php echo htmlspecialchars($msgError); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-500 hover:text-rose-700">
                    <span class="material-symbols-outlined text-lg">close</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Encabezado del Módulo -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary/10 dark:bg-slate-800 text-primary dark:text-tertiary text-[11px] font-extrabold uppercase tracking-wider mb-2">
                    <span class="material-symbols-outlined text-sm">medical_services</span>
                    <span>Directorio Institucional</span>
                </div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-primary dark:text-white tracking-tight">Gestión de Médicos</h1>
                <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-1">Consulte, registre y controle los accesos de los médicos en LIHO</p>
            </div>

            <!-- Acciones Principales -->
            <button id="openModalBtn" type="button"
                class="inline-flex items-center gap-2 bg-gradient-to-r from-tertiary to-[#009b98] hover:from-[#00b2af] hover:to-[#008986] text-white font-bold text-xs px-5 py-3 rounded-2xl shadow-lg shadow-tertiary/20 hover:shadow-xl hover:shadow-tertiary/30 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 cursor-pointer">
                <span class="material-symbols-outlined text-lg">person_add</span>
                <span>Registrar Nuevo Médico</span>
            </button>
        </div>

        <!-- Métricas Resumen: Cantidad de Médicos por Entidad (Interactivas) -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-<?php echo min(4, 1 + count($entidadesList)); ?> gap-3.5 mb-6">
            <!-- Todas las Entidades -->
            <div onclick="filtrarEntidadRapido('ALL')" id="cardStatEntidad_ALL" data-entidad-stat="ALL"
                class="card-stat-entidad cursor-pointer p-3.5 rounded-2xl border-2 transition-all bg-teal-50/70 dark:bg-teal-950/40 border-tertiary shadow-sm ring-2 ring-tertiary/20 flex items-center gap-3.5">
                <div class="w-11 h-11 rounded-xl bg-primary/10 dark:bg-slate-800 text-primary dark:text-tertiary flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-2xl">groups</span>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-extrabold text-slate-400 dark:text-slate-500 uppercase tracking-wider">Total Directorio</p>
                    <p class="text-base sm:text-lg font-black text-slate-900 dark:text-white leading-tight">
                        <?php echo $countTotal; ?> <span class="text-xs font-bold text-slate-400">médicos</span>
                    </p>
                </div>
            </div>

            <!-- Cada Entidad Activa desde BD (Sin duplicados) -->
            <?php foreach ($entidadesList as $ent): ?>
                <?php 
                    $cEnt = $entidadesCounts[strval($ent['id'])] ?? 0; 
                    $isMatriz = (!empty($ent['is_matriz']) || $ent['id'] == 4);
                ?>
                <div onclick="filtrarEntidadRapido('<?php echo $ent['id']; ?>')" id="cardStatEntidad_<?php echo $ent['id']; ?>" data-entidad-stat="<?php echo $ent['id']; ?>"
                    class="card-stat-entidad cursor-pointer p-3.5 rounded-2xl border-2 transition-all bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 shadow-xs flex items-center gap-3.5">
                    <div class="w-11 h-11 rounded-xl bg-white p-1 flex items-center justify-center shrink-0 border border-slate-200 dark:border-slate-700 shadow-xs overflow-hidden">
                        <?php if (!empty($ent['logo']) && file_exists(__DIR__ . '/' . $ent['logo'])): ?>
                            <img src="<?php echo htmlspecialchars($ent['logo']); ?>" alt="Logo" class="max-w-full max-h-full object-contain" />
                        <?php elseif ($isMatriz): ?>
                            <img src="assets/img/hologo.png" alt="HO" class="max-w-full max-h-full object-contain" />
                        <?php else: ?>
                            <span class="material-symbols-outlined text-xl text-indigo-600">domain</span>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[10px] font-extrabold <?php echo $isMatriz ? 'text-emerald-600 dark:text-emerald-400' : 'text-indigo-600 dark:text-indigo-400'; ?> uppercase tracking-wider truncate" title="<?php echo htmlspecialchars($ent['nombre']); ?>">
                            <?php echo $isMatriz ? 'H. Ocazionez (Matriz)' : htmlspecialchars($ent['nombre']); ?>
                        </p>
                        <p class="text-base sm:text-lg font-black text-slate-900 dark:text-white leading-tight">
                            <?php echo $cEnt; ?> <span class="text-xs font-bold text-slate-400"><?php echo $cEnt === 1 ? 'médico' : 'médicos'; ?></span>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Barra de Búsqueda y Filtros de Estado -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs mb-6 flex flex-col md:flex-row gap-4 justify-between items-center">
            
            <!-- Campo de Búsqueda en Vivo -->
            <div class="relative w-full md:w-96">
                <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xl pointer-events-none">
                    search
                </span>
                <input type="text" id="searchInput" placeholder="Buscar por Nombre, Cédula, Proteo o Correo..."
                    class="w-full bg-slate-50 dark:bg-slate-800 pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-medium text-primary dark:text-slate-100 placeholder:text-slate-400 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 focus:border-tertiary outline-none transition-all" />
            </div>

            <!-- Filtros: Estado y Entidad -->
            <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                <!-- Filtro por Entidad / IPS -->
                <div class="relative min-w-[240px]">
                    <span class="material-symbols-outlined text-base absolute left-3 top-2.5 text-slate-400 pointer-events-none">domain</span>
                    <select id="filterEntidad" onchange="filterEntidad(this.value)" 
                        class="w-full bg-slate-50 dark:bg-slate-800 pl-9 pr-8 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-slate-700 dark:text-slate-200 outline-none focus:ring-2 focus:ring-tertiary/30 cursor-pointer">
                        <option value="ALL">Todas las Entidades (<?php echo $countTotal; ?>)</option>
                        <?php foreach ($entidadesList as $ent): ?>
                            <?php 
                                $cEnt = $entidadesCounts[strval($ent['id'])] ?? 0; 
                                $isMatriz = (!empty($ent['is_matriz']) || $ent['id'] == 4);
                            ?>
                            <option value="<?php echo $ent['id']; ?>">
                                <?php echo $isMatriz ? 'Hernán Ocazionez (Matriz)' : htmlspecialchars($ent['nombre']); ?> (<?php echo $cEnt; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Botones Filtro Estado -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0">
                    <button type="button" onclick="filterStatus('ALL')" id="btnFilterALL"
                        class="px-3.5 py-1.5 rounded-xl text-xs font-extrabold bg-primary text-white shadow-xs transition-all cursor-pointer">
                        Todos (<?php echo $countTotal; ?>)
                    </button>
                    <button type="button" onclick="filterStatus('1')" id="btnFilter1"
                        class="px-3.5 py-1.5 rounded-xl text-xs font-extrabold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-emerald-50 hover:text-emerald-700 transition-all cursor-pointer">
                        Activos (<?php echo $countActivos; ?>)
                    </button>
                    <button type="button" onclick="filterStatus('0')" id="btnFilter0"
                        class="px-3.5 py-1.5 rounded-xl text-xs font-extrabold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-rose-50 hover:text-rose-700 transition-all cursor-pointer">
                        Inactivos (<?php echo $countInactivos; ?>)
                    </button>
                </div>

                <!-- Selector de Vista: Tarjetas vs Tabla -->
                <div class="flex items-center p-1 bg-slate-100 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shrink-0">
                    <button type="button" onclick="cambiarVistaMedicos('cards')" id="btnViewCards" title="Vista en Tarjetas de Usuario"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-white dark:bg-slate-900 text-tertiary shadow-xs cursor-pointer">
                        <span class="material-symbols-outlined text-base">grid_view</span>
                        <span class="hidden sm:inline">Tarjetas</span>
                    </button>
                    <button type="button" onclick="cambiarVistaMedicos('table')" id="btnViewTable" title="Vista en Tabla"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer">
                        <span class="material-symbols-outlined text-base">table_rows</span>
                        <span class="hidden sm:inline">Tabla</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ==========================================
             VISTA 1: TARJETAS DE USUARIO (GRID VIEW) - DEFAULT
             ========================================== -->
        <div id="medicosCardsView" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 mb-8">
            <?php if (empty($medicosList)): ?>
                <div class="col-span-full py-16 text-center bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm">
                    <span class="material-symbols-outlined text-4xl mb-2 text-slate-300">person_off</span>
                    <p class="text-slate-400 font-medium">No hay médicos registrados en el sistema actualmente.</p>
                </div>
            <?php else: ?>
                <?php foreach ($medicosList as $m): ?>
                    <?php 
                        $nombreComp = trim($m['nombre_completo'] ?? '');
                        if (empty($nombreComp)) {
                            $nombreComp = trim(($m['pnom'] ?? '') . ' ' . ($m['snom'] ?? '') . ' ' . ($m['pape'] ?? '') . ' ' . ($m['sape'] ?? ''));
                        }
                        if (empty($nombreComp)) {
                            $nombreComp = 'Usuario #' . $m['usuario_id'];
                        }
                        
                        $rolCode = $m['rol_id'] ?? '3';
                        $isMedico = ($rolCode == '3' || $rolCode == 'Medico');
                        $isAdmin  = ($rolCode == '1' || $rolCode == 'Admin');
                        $isAux    = ($rolCode == '2' || $rolCode == 'Auxiliar');
                        
                        $rolLabel = $isAdmin ? 'ADMINISTRADOR' : ($isAux ? 'AUXILIAR' : 'RADIÓLOGO');
                        $prefixStr = $isMedico ? (strpos(strtolower($nombreComp), 'dr.') === 0 ? '' : 'Dr. ') : '';
                        
                        $estadoCode = strtoupper(trim($m['estado'] ?? '1'));
                        $isActivo = ($estadoCode === '1');
                        $cedulaVal = !empty($m['m_cedula']) ? $m['m_cedula'] : (!empty($m['u_cedula']) ? $m['u_cedula'] : 'N/A');
                        $hasTarifaEsp = (intval($m['tarifas_especiales'] ?? 0) === 1);
                        $hasDeglucion = (intval($m['degluciones'] ?? 0) === 1);
                        $hasParafiscales = (intval($m['parafiscales'] ?? 0) === 1);
                        $hasPensionado = (intval($m['pensionado'] ?? 0) === 1);
                        $hasRetenciones = (intval($m['retenciones'] ?? 0) === 1);
                        $hasRetencion383 = (intval($m['retencion_art_383'] ?? 0) === 1);

                        $docModsStr = trim($m['modalidades_adicionales'] ?? '');
                        $docMods = !empty($docModsStr) ? array_filter(array_map('trim', explode(',', $docModsStr))) : [];
                        if ($hasTarifaEsp && !in_array('TARIFAS_ESPECIALES', $docMods)) $docMods[] = 'TARIFAS_ESPECIALES';
                        if ($hasDeglucion && !in_array('DEGLUCIONES', $docMods)) $docMods[] = 'DEGLUCIONES';

                        // Iniciales del Avatar
                        $limpioNom = trim(preg_replace('/^(dr\.|dra\.|dr|dra)\s+/i', '', $nombreComp));
                        $partesNom = preg_split('/\s+/', $limpioNom);
                        if (count($partesNom) >= 2) {
                            $iniciales = strtoupper(mb_substr($partesNom[0], 0, 1) . mb_substr($partesNom[1], 0, 1));
                        } else {
                            $iniciales = strtoupper(mb_substr($limpioNom, 0, 2));
                        }
                        if (empty($iniciales)) $iniciales = 'DR';

                        $avatarBg = $isAdmin 
                            ? 'bg-gradient-to-br from-purple-500 via-purple-600 to-indigo-700 text-white shadow-md shadow-purple-500/20' 
                            : ($isAux 
                                ? 'bg-gradient-to-br from-blue-500 via-blue-600 to-cyan-700 text-white shadow-md shadow-blue-500/20' 
                                : 'bg-gradient-to-br from-teal-500 via-teal-600 to-emerald-700 text-white shadow-md shadow-teal-500/20');

                        $editDataJson = htmlspecialchars(json_encode([
                            'id' => $m['usuario_id'],
                            'rol_id' => $m['rol_id'] ?? '3',
                            'pnom' => $m['pnom'] ?? '',
                            'snom' => $m['snom'] ?? '',
                            'pape' => $m['pape'] ?? '',
                            'sape' => $m['sape'] ?? '',
                            'nombre_completo' => $nombreComp,
                            'cedula' => $cedulaVal !== 'N/A' ? $cedulaVal : '',
                            'usuario_proteo' => $m['usuario_proteo'] ?? '',
                            'email' => $m['email'] ?? '',
                            'tarifas_especiales' => intval($m['tarifas_especiales'] ?? 0),
                            'degluciones' => intval($m['degluciones'] ?? 0),
                            'modalidades' => $docMods,
                            'modalidades_str' => implode(',', $docMods),
                            'parafiscales' => intval($m['parafiscales'] ?? 0),
                            'pensionado' => intval($m['pensionado'] ?? 0),
                            'retenciones' => intval($m['retenciones'] ?? 0),
                            'retencion_art_383' => intval($m['retencion_art_383'] ?? 0),
                            'entidad_id' => $m['entidad_id'] ?? null
                        ]), ENT_QUOTES, 'UTF-8');

                        $entidadNombreFiltro = !empty($m['entidad_nombre']) ? $m['entidad_nombre'] : 'Hernán Ocazionez Propio';
                        $searchDataAttr = strtolower($nombreComp . ' ' . $cedulaVal . ' ' . ($m['usuario_proteo'] ?? '') . ' ' . ($m['email'] ?? '') . ' ' . $entidadNombreFiltro);
                    ?>

                    <!-- Tarjeta de Médico Individual -->
                    <div class="medico-card bg-white dark:bg-slate-900 rounded-3xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs hover:shadow-xl hover:border-tertiary/50 dark:hover:border-tertiary/50 transition-all duration-300 flex flex-col justify-between relative group overflow-hidden" 
                        data-estado="<?php echo $estadoCode; ?>" 
                        data-entidad="<?php echo !empty($m['entidad_id']) ? $m['entidad_id'] : '4'; ?>"
                        data-search="<?php echo htmlspecialchars($searchDataAttr); ?>">
                        
                        <div>
                            <!-- Encabezado de Tarjeta: Avatar, Nombre y Estado -->
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3.5 min-w-0 flex-1">
                                    <!-- Avatar con iniciales o foto -->
                                    <div class="relative shrink-0 w-12 h-12" style="width: 48px; height: 48px;">
                                        <div class="w-12 h-12 rounded-2xl <?php echo $avatarBg; ?> flex items-center justify-center font-black text-sm tracking-wider ring-2 ring-white/10 overflow-hidden shrink-0" style="width: 48px; height: 48px; min-width: 48px; min-height: 48px; max-width: 48px; max-height: 48px;">
                                            <?php if (!empty($m['foto_perfil']) && file_exists(__DIR__ . '/' . $m['foto_perfil'])): ?>
                                                <img src="<?php echo htmlspecialchars($m['foto_perfil']); ?>" alt="Foto" class="w-full h-full object-cover object-center block shrink-0" style="width: 48px; height: 48px; min-width: 48px; min-height: 48px; max-width: 48px; max-height: 48px; border-radius: 0.875rem;" />
                                            <?php else: ?>
                                                <span><?php echo $iniciales; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($isActivo): ?>
                                            <span class="absolute -bottom-1 -right-1 w-3.5 h-3.5 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900 ring-2 ring-emerald-500/20 flex items-center justify-center" title="Médico Activo">
                                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="absolute -bottom-1 -right-1 w-3.5 h-3.5 rounded-full bg-rose-500 border-2 border-white dark:border-slate-900 ring-2 ring-rose-500/20" title="Médico Inactivo"></span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Nombre y Rol -->
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-extrabold text-sm text-slate-900 dark:text-white group-hover:text-tertiary transition-colors leading-snug break-words" title="<?php echo htmlspecialchars($prefixStr . $nombreComp); ?>">
                                            <?php echo htmlspecialchars($prefixStr . $nombreComp); ?>
                                        </h3>
                                        <div class="flex items-center gap-1.5 mt-1">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-wider <?php echo $isAdmin ? 'bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200 dark:border-purple-800' : ($isAux ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300 border border-blue-200 dark:border-blue-800' : 'bg-teal-100 text-teal-800 dark:bg-teal-950/60 dark:text-teal-300 border border-teal-200 dark:border-teal-800'); ?>">
                                                <span class="material-symbols-outlined text-[11px]"><?php echo $isAdmin ? 'admin_panel_settings' : ($isAux ? 'support_agent' : 'radiology'); ?></span>
                                                <?php echo $rolLabel; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Insignia de Estado -->
                                <div class="shrink-0 pt-0.5">
                                    <?php if ($isActivo): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-emerald-50 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200/80 dark:border-emerald-800 shadow-2xs">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                            Activo
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-rose-50 dark:bg-rose-950/70 text-rose-700 dark:text-rose-300 border border-rose-200/80 dark:border-rose-800 shadow-2xs">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                            Inactivo
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Entidad / Institución Perteneciente -->
                            <?php if (!empty($m['entidad_id']) && !empty($m['entidad_nombre'])): ?>
                                <div class="mt-3.5 p-2.5 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/30 border border-indigo-100/80 dark:border-indigo-900/40 flex items-center gap-2.5">
                                    <div class="w-9 h-9 rounded-xl bg-white p-1 flex items-center justify-center shrink-0 border border-slate-200/80 shadow-2xs overflow-hidden">
                                        <?php if (!empty($m['entidad_logo']) && file_exists(__DIR__ . '/' . $m['entidad_logo'])): ?>
                                            <img src="<?php echo htmlspecialchars($m['entidad_logo']); ?>" alt="Logo" class="max-w-full max-h-full object-contain" />
                                        <?php else: ?>
                                            <span class="material-symbols-outlined text-base text-indigo-600">domain</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200 truncate" title="<?php echo htmlspecialchars($m['entidad_nombre']); ?>">
                                            <?php echo htmlspecialchars($m['entidad_nombre']); ?>
                                        </p>
                                        <p class="text-[10px] font-mono text-slate-400 truncate">
                                            NIT: <?php echo htmlspecialchars($m['entidad_nit'] . ($m['entidad_dv'] !== null && $m['entidad_dv'] !== '' ? '-' . $m['entidad_dv'] : '')); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mt-3.5 p-2.5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-800 flex items-center gap-2.5">
                                    <div class="w-9 h-9 rounded-xl bg-white p-1 flex items-center justify-center shrink-0 border border-slate-200/80 dark:border-slate-700 shadow-2xs overflow-hidden">
                                        <img src="assets/img/hologo.png" alt="HO" class="max-w-full max-h-full object-contain" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[11px] font-extrabold text-slate-800 dark:text-slate-200 truncate">
                                            Hernán Ocazionez (Propio)
                                        </p>
                                        <p class="text-[10px] text-slate-400 font-medium truncate">
                                            Sede Principal • LIHO
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Identificadores Clave: Proteo & Cédula -->
                            <div class="grid grid-cols-2 gap-2 mt-3">
                                <div class="bg-teal-50/70 dark:bg-teal-950/40 rounded-xl px-2.5 py-1.5 border border-teal-200/60 dark:border-teal-800/60 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm text-tertiary">badge</span>
                                    <div class="min-w-0 flex-1">
                                        <span class="block text-[8px] font-extrabold uppercase tracking-wider text-slate-400">Proteo</span>
                                        <span class="block text-xs font-black font-mono text-tertiary truncate" title="<?php echo htmlspecialchars($m['usuario_proteo'] ?? 'N/A'); ?>">
                                            <?php echo htmlspecialchars($m['usuario_proteo'] ?? 'N/A'); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="bg-slate-50 dark:bg-slate-800/70 rounded-xl px-2.5 py-1.5 border border-slate-200 dark:border-slate-700/80 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm text-slate-400">fingerprint</span>
                                    <div class="min-w-0 flex-1">
                                        <span class="block text-[8px] font-extrabold uppercase tracking-wider text-slate-400">Cédula</span>
                                        <span class="block text-xs font-bold font-mono text-slate-700 dark:text-slate-200 truncate" title="<?php echo htmlspecialchars($cedulaVal); ?>">
                                            <?php echo htmlspecialchars($cedulaVal); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Correo Electrónico -->
                            <div class="mt-2.5 flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400 font-medium px-1">
                                <span class="material-symbols-outlined text-sm text-slate-400 shrink-0">mail</span>
                                <span class="truncate" title="<?php echo htmlspecialchars($m['email'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($m['email'] ?? 'Sin correo registrado'); ?>
                                </span>
                            </div>

                            <!-- Modalidades y Tarifas -->
                            <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800/80">
                                <div class="flex items-center justify-between gap-1 mb-1.5">
                                    <span class="text-[9px] font-extrabold uppercase tracking-wider text-slate-400">Modalidades y Tarifas</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <?php if (!empty($docMods)): ?>
                                        <?php foreach ($docMods as $dMod): ?>
                                            <?php 
                                                $modInfo = $modalidadesPagoMap[$dMod] ?? null;
                                                $bColor = $modInfo ? obtenerEstiloBadgeColor($modInfo['color']) : 'bg-purple-100 text-purple-800 dark:bg-purple-950/80 dark:text-purple-300 border-purple-200 dark:border-purple-800';
                                                $bNombre = $modInfo ? $modInfo['nombre'] : $dMod;
                                            ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[9px] font-black uppercase border <?php echo $bColor; ?>" <?php echo $modInfo ? obtenerEstiloBadgeInline($modInfo['color']) : ''; ?>>
                                                <?php echo htmlspecialchars($bNombre); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700 text-[9px] font-bold">
                                            Tarifa Estándar
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($hasParafiscales): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-[9px] font-bold">
                                            <span class="w-1 h-1 rounded-full bg-emerald-500"></span> Parafiscales
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($hasPensionado): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-[9px] font-bold">
                                            <span class="w-1 h-1 rounded-full bg-indigo-500"></span> Pensionado
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($hasRetenciones): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-rose-50 dark:bg-rose-950/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 text-[9px] font-bold">
                                            <span class="w-1 h-1 rounded-full bg-rose-500"></span> Retenciones
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($hasRetencion383): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-cyan-50 dark:bg-cyan-950/60 text-cyan-700 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800 text-[9px] font-bold">
                                            <span class="w-1 h-1 rounded-full bg-cyan-500"></span> Rete Art 383
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Pie de Tarjeta: ID y Botones de Acción -->
                        <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                            <span class="text-[10px] font-mono text-slate-400 font-bold">
                                ID: #<?php echo $m['usuario_id']; ?>
                            </span>
                            <div class="flex items-center gap-1.5">
                                <?php if ($userRole === 'ADMINISTRADOR'): ?>
                                    <button type="button" 
                                        title="Editar información del médico" 
                                        onclick="abrirModalEditar(<?php echo $editDataJson; ?>)"
                                        class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/80 border border-amber-200 dark:border-amber-800 font-bold text-xs transition-colors inline-flex items-center gap-1 cursor-pointer">
                                        <span class="material-symbols-outlined text-sm">edit</span>
                                        <span>Editar</span>
                                    </button>
                                <?php endif; ?>

                                <?php if ($isActivo): ?>
                                    <button type="button" 
                                        onclick="confirmarToggleEstadoMedico(<?php echo $m['usuario_id']; ?>, '<?php echo $estadoCode; ?>', '<?php echo htmlspecialchars(addslashes($nombreComp)); ?>')" 
                                        title="Desactivar cuenta" 
                                        class="px-2.5 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/80 border border-rose-200 dark:border-rose-800 font-bold text-xs transition-colors inline-flex items-center gap-1 cursor-pointer">
                                        <span class="material-symbols-outlined text-sm">block</span>
                                        <span class="hidden sm:inline">Desactivar</span>
                                    </button>
                                <?php else: ?>
                                    <button type="button" 
                                        onclick="confirmarToggleEstadoMedico(<?php echo $m['usuario_id']; ?>, '<?php echo $estadoCode; ?>', '<?php echo htmlspecialchars(addslashes($nombreComp)); ?>')" 
                                        title="Activar cuenta" 
                                        class="px-2.5 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/80 border border-emerald-200 dark:border-emerald-800 font-bold text-xs transition-colors inline-flex items-center gap-1 cursor-pointer">
                                        <span class="material-symbols-outlined text-sm">check_circle</span>
                                        <span class="hidden sm:inline">Activar</span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Estado Vacío en Búsqueda / Filtros (Tarjetas) -->
            <div id="emptyCardsState" class="hidden col-span-full py-16 text-center bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm">
                <div class="w-16 h-16 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mx-auto mb-3">
                    <span class="material-symbols-outlined text-3xl">search_off</span>
                </div>
                <h4 class="text-sm font-bold text-slate-800 dark:text-slate-200 mb-1">No se encontraron médicos</h4>
                <p class="text-xs text-slate-400 max-w-sm mx-auto">No hay médicos registrados que coincidan con los criterios de búsqueda o filtros seleccionados.</p>
            </div>
        </div>

        <!-- ==========================================
             VISTA 2: TABLA DE MÉDICOS (TABLE VIEW)
             ========================================== -->
        <div id="medicosTableView" class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden mb-8 hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="medicosTable">
                    <thead>
                        <tr class="bg-slate-50/80 dark:bg-slate-800/80 border-b border-slate-200/80 dark:border-slate-800 text-[11px] font-extrabold text-slate-400 dark:text-slate-300 uppercase tracking-wider">
                            <th class="py-4 px-6">Médico / Especialidad</th>
                            <th class="py-4 px-6">Entidad / IPS</th>
                            <th class="py-4 px-6">Usuario Proteo</th>
                            <th class="py-4 px-6">Cédula</th>
                            <th class="py-4 px-6">Correo Electrónico</th>
                            <th class="py-4 px-6 text-center">Tarifas</th>
                            <th class="py-4 px-6 text-center">Estado</th>
                            <th class="py-4 px-6 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($medicosList)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-12 text-slate-400 font-medium">
                                    <span class="material-symbols-outlined text-4xl mb-2 text-slate-300">person_off</span>
                                    <p>No hay médicos registrados en el sistema actualmente.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medicosList as $m): ?>
                                <?php 
                                    $nombreComp = trim($m['nombre_completo'] ?? '');
                                    if (empty($nombreComp)) {
                                        $nombreComp = trim(($m['pnom'] ?? '') . ' ' . ($m['snom'] ?? '') . ' ' . ($m['pape'] ?? '') . ' ' . ($m['sape'] ?? ''));
                                    }
                                    if (empty($nombreComp)) {
                                        $nombreComp = 'Usuario #' . $m['usuario_id'];
                                    }
                                    
                                    $rolCode = $m['rol_id'] ?? '3';
                                    $isMedico = ($rolCode == '3' || $rolCode == 'Medico');
                                    $isAdmin  = ($rolCode == '1' || $rolCode == 'Admin');
                                    $isAux    = ($rolCode == '2' || $rolCode == 'Auxiliar');
                                    
                                    $rolLabel = $isAdmin ? 'ADMINISTRADOR' : ($isAux ? 'AUXILIAR' : 'RADIÓLOGO');
                                    $prefixStr = $isMedico ? (strpos(strtolower($nombreComp), 'dr.') === 0 ? '' : 'Dr. ') : '';
                                    
                                    $estadoCode = strtoupper(trim($m['estado'] ?? '1'));
                                    $isActivo = ($estadoCode === '1');
                                    $cedulaVal = !empty($m['m_cedula']) ? $m['m_cedula'] : (!empty($m['u_cedula']) ? $m['u_cedula'] : 'N/A');
                                    $hasTarifaEsp = (intval($m['tarifas_especiales'] ?? 0) === 1);
                                    $hasDeglucion = (intval($m['degluciones'] ?? 0) === 1);
                                    $hasParafiscales = (intval($m['parafiscales'] ?? 0) === 1);
                                    $hasPensionado = (intval($m['pensionado'] ?? 0) === 1);
                                    $hasRetenciones = (intval($m['retenciones'] ?? 0) === 1);
                                    $hasRetencion383 = (intval($m['retencion_art_383'] ?? 0) === 1);

                                    $docModsStr = trim($m['modalidades_adicionales'] ?? '');
                                    $docMods = !empty($docModsStr) ? array_filter(array_map('trim', explode(',', $docModsStr))) : [];
                                    if ($hasTarifaEsp && !in_array('TARIFAS_ESPECIALES', $docMods)) $docMods[] = 'TARIFAS_ESPECIALES';
                                    if ($hasDeglucion && !in_array('DEGLUCIONES', $docMods)) $docMods[] = 'DEGLUCIONES';

                                    $editDataJson = htmlspecialchars(json_encode([
                                        'id' => $m['usuario_id'],
                                        'rol_id' => $m['rol_id'] ?? '3',
                                        'pnom' => $m['pnom'] ?? '',
                                        'snom' => $m['snom'] ?? '',
                                        'pape' => $m['pape'] ?? '',
                                        'sape' => $m['sape'] ?? '',
                                        'nombre_completo' => $nombreComp,
                                        'cedula' => $cedulaVal !== 'N/A' ? $cedulaVal : '',
                                        'usuario_proteo' => $m['usuario_proteo'] ?? '',
                                        'email' => $m['email'] ?? '',
                                        'tarifas_especiales' => intval($m['tarifas_especiales'] ?? 0),
                                        'degluciones' => intval($m['degluciones'] ?? 0),
                                        'modalidades' => $docMods,
                                        'modalidades_str' => implode(',', $docMods),
                                        'parafiscales' => intval($m['parafiscales'] ?? 0),
                                        'pensionado' => intval($m['pensionado'] ?? 0),
                                        'retenciones' => intval($m['retenciones'] ?? 0),
                                        'retencion_art_383' => intval($m['retencion_art_383'] ?? 0),
                                        'entidad_id' => $m['entidad_id'] ?? null
                                    ]), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/50 transition-colors medico-row border-b border-slate-100 dark:border-slate-800" data-estado="<?php echo $estadoCode; ?>" data-entidad="<?php echo !empty($m['entidad_id']) ? $m['entidad_id'] : '4'; ?>">
                                    
                                    <!-- Nombre -->
                                    <td class="py-4 px-6 font-bold text-primary dark:text-slate-100">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full <?php echo $isAdmin ? 'bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800' : ($isAux ? 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800' : 'bg-primary/10 dark:bg-slate-800 text-primary dark:text-tertiary border-primary/20 dark:border-slate-700'); ?> border font-black flex items-center justify-center text-xs shrink-0">
                                                <?php echo $isAdmin ? 'ADM' : ($isAux ? 'AUX' : 'DR'); ?>
                                            </div>
                                            <div>
                                                <p class="font-extrabold text-primary dark:text-white text-xs"><?php echo htmlspecialchars($prefixStr . $nombreComp); ?></p>
                                                <p class="text-[10px] font-bold <?php echo $isAdmin ? 'text-purple-600 dark:text-purple-400' : ($isAux ? 'text-blue-600 dark:text-blue-400' : 'text-slate-400 dark:text-slate-400'); ?> uppercase tracking-wider"><?php echo $rolLabel; ?></p>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Entidad / IPS -->
                                    <td class="py-4 px-6 whitespace-nowrap">
                                        <?php if (!empty($m['entidad_id']) && !empty($m['entidad_nombre'])): ?>
                                            <div class="flex flex-col">
                                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-200/60 dark:border-indigo-800 text-indigo-700 dark:text-indigo-300 text-xs font-bold">
                                                    <?php if (!empty($m['entidad_logo']) && file_exists(__DIR__ . '/' . $m['entidad_logo'])): ?>
                                                        <img src="<?php echo htmlspecialchars($m['entidad_logo']); ?>" alt="Logo" class="w-5 h-5 rounded-md object-contain bg-white p-0.5 shrink-0 border border-slate-200/80 shadow-2xs" />
                                                    <?php else: ?>
                                                        <span class="material-symbols-outlined text-xs">domain</span>
                                                    <?php endif; ?>
                                                    <span><?php echo htmlspecialchars($m['entidad_nombre']); ?></span>
                                                </span>
                                                <span class="text-[10px] text-slate-400 font-mono pl-1">NIT: <?php echo htmlspecialchars($m['entidad_nit'] . ($m['entidad_dv'] !== null && $m['entidad_dv'] !== '' ? '-' . $m['entidad_dv'] : '')); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 text-xs font-bold shadow-2xs">
                                                <img src="assets/img/hologo.png" alt="HO" class="w-5 h-5 rounded-md object-contain bg-white p-0.5 shrink-0 border border-slate-200 dark:border-slate-700 shadow-2xs" />
                                                <span>H. Ocazionez (Propio)</span>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Proteo -->
                                    <td class="py-4 px-6 font-bold text-slate-700 dark:text-slate-200">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-teal-50 dark:bg-teal-950/60 border border-teal-200/60 dark:border-teal-800 text-tertiary font-mono text-[11px] font-extrabold">
                                            <span class="material-symbols-outlined text-xs">badge</span>
                                            <?php echo htmlspecialchars($m['usuario_proteo'] ?? 'N/A'); ?>
                                        </span>
                                    </td>

                                    <!-- Cedula -->
                                    <td class="py-4 px-6 font-semibold text-slate-600 dark:text-slate-300 font-mono">
                                        <?php echo htmlspecialchars($cedulaVal); ?>
                                    </td>

                                    <!-- Correo -->
                                    <td class="py-4 px-6 font-medium text-slate-600 dark:text-slate-300">
                                        <?php echo htmlspecialchars($m['email'] ?? 'N/A'); ?>
                                    </td>

                                    <!-- Tarifas / Modalidad de Pago -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <div class="flex flex-col items-center gap-1.5">
                                            <div class="flex flex-wrap items-center justify-center gap-1 max-w-[220px]">
                                                <?php if (!empty($docMods)): ?>
                                                    <?php foreach ($docMods as $dMod): ?>
                                                        <?php 
                                                            $modInfo = $modalidadesPagoMap[$dMod] ?? null;
                                                            $bColor = $modInfo ? obtenerEstiloBadgeColor($modInfo['color']) : 'bg-purple-100 text-purple-800 dark:bg-purple-950/80 dark:text-purple-300 border-purple-200 dark:border-purple-800';
                                                            $bNombre = $modInfo ? $modInfo['nombre'] : $dMod;
                                                        ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase border <?php echo $bColor; ?>" <?php echo $modInfo ? obtenerEstiloBadgeInline($modInfo['color']) : ''; ?>>
                                                            <?php echo htmlspecialchars($bNombre); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700 text-[10px] font-bold">
                                                        Estándar
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="flex items-center gap-1 flex-wrap justify-center">
                                                <?php if ($hasParafiscales): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-100/90 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-[9px] font-extrabold uppercase tracking-wider">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Parafiscales
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($hasPensionado): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-indigo-100/90 dark:bg-indigo-950/80 text-indigo-800 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-[9px] font-extrabold uppercase tracking-wider">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span> Pensionado
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($hasRetenciones): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-rose-100/90 dark:bg-rose-950/80 text-rose-800 dark:text-rose-300 border border-rose-200 dark:border-rose-800 text-[9px] font-extrabold uppercase tracking-wider">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Retenciones
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($hasRetencion383): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-cyan-100/90 dark:bg-cyan-950/80 text-cyan-800 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800 text-[9px] font-extrabold uppercase tracking-wider">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-cyan-500"></span> Rete Art 383
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Estado -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <?php if ($isActivo): ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-100/80 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-200/60 dark:border-emerald-800 text-[10px] font-extrabold uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-100/80 dark:bg-rose-950/70 text-rose-800 dark:text-rose-300 border border-rose-200/60 dark:border-rose-800 text-[10px] font-extrabold uppercase tracking-wider">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Acciones -->
                                    <td class="py-4 px-6 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <?php if ($userRole === 'ADMINISTRADOR'): ?>
                                                <button type="button" 
                                                    title="Editar datos del médico" 
                                                    onclick="abrirModalEditar(<?php echo $editDataJson; ?>)"
                                                    class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/80 border border-amber-200 dark:border-amber-800 font-bold text-[11px] transition-colors inline-flex items-center gap-1 cursor-pointer">
                                                    <span class="material-symbols-outlined text-sm">edit</span>
                                                    <span>Editar</span>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($isActivo): ?>
                                                <button type="button" 
                                                    onclick="confirmarToggleEstadoMedico(<?php echo $m['usuario_id']; ?>, '<?php echo $estadoCode; ?>', '<?php echo htmlspecialchars(addslashes($nombreComp)); ?>')" 
                                                    title="Desactivar cuenta" 
                                                    class="px-3 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/80 border border-rose-200 dark:border-rose-800 font-bold text-[11px] transition-colors inline-flex items-center gap-1 cursor-pointer">
                                                    <span class="material-symbols-outlined text-sm">block</span>
                                                    <span>Desactivar</span>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" 
                                                    onclick="confirmarToggleEstadoMedico(<?php echo $m['usuario_id']; ?>, '<?php echo $estadoCode; ?>', '<?php echo htmlspecialchars(addslashes($nombreComp)); ?>')" 
                                                    title="Activar cuenta" 
                                                    class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/80 border border-emerald-200 dark:border-emerald-800 font-bold text-[11px] transition-colors inline-flex items-center gap-1 cursor-pointer">
                                                    <span class="material-symbols-outlined text-sm">check_circle</span>
                                                    <span>Activar</span>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr id="emptyRowsState" class="hidden">
                            <td colspan="8" class="text-center py-12 text-slate-400 font-medium">
                                <span class="material-symbols-outlined text-4xl mb-2 text-slate-300">search_off</span>
                                <p>No se encontraron médicos con los filtros aplicados.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Registrar Nuevo Médico -->
    <div id="addMedicoModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-4xl w-full p-6 sm:p-8 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300 animate-card-entry max-h-[92vh] overflow-y-auto">
            
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-tertiary/10 text-tertiary flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">person_add</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Registrar Nuevo Médico</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Complete los datos de la cuenta de médico y configure sus modalidades</p>
                    </div>
                </div>
                <button id="closeModalBtn" type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="crear_usuario" />

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    <!-- Columna Izquierda: Datos Personales & Cuenta -->
                    <div class="lg:col-span-7 space-y-4">
                        
                        <!-- Selección de Rol -->
                        <div>
                            <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1" for="rol_id_select">
                                Rol del Usuario *
                            </label>
                            <select name="rol_id" id="rol_id_select" required class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none">
                                <?php foreach ($rolesList as $r): ?>
                                    <option value="<?php echo htmlspecialchars($r['id']); ?>" <?php echo ($r['id'] == 3 || $r['id'] === '3') ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($r['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Nombres -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Primer Nombre *</label>
                                <input type="text" name="pnom" required placeholder="Ej: JUAN" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Segundo Nombre</label>
                                <input type="text" name="snom" placeholder="Ej: ESTEBAN" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Apellidos -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Primer Apellido *</label>
                                <input type="text" name="pape" required placeholder="Ej: PEREZ" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Segundo Apellido</label>
                                <input type="text" name="sape" placeholder="Ej: GARCIA" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Cédula & Usuario Proteo -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Cédula / Documento *</label>
                                <input type="text" name="cedula" required placeholder="Ej: 123456789" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Usuario Proteo *</label>
                                <input type="text" name="usuario_proteo" required placeholder="Ej: C123456789" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Correo Electrónico & Fecha de Nacimiento -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                                <input type="email" name="email" required placeholder="medico@hernanocazionez.com" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Fecha Nacimiento</label>
                                <input type="date" name="fecha_nacimiento" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Entidad / IPS Perteneciente (Selector en Recuadros con Logos) -->
                        <div class="pt-1">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider">
                                    Entidad / IPS Perteneciente <span class="text-rose-500">*</span>
                                </label>
                                <span class="text-[10px] text-slate-400 font-semibold">Seleccione la institución</span>
                            </div>

                            <input type="hidden" name="entidad_id" id="add_entidad_id" value="" />

                            <!-- Listado de Recuadros Interactivos con Logos Amplios y Legibles -->
                            <div class="space-y-3 max-h-[320px] overflow-y-auto pr-1.5">
                                <?php foreach ($entidadesList as $ent): ?>
                                    <?php 
                                        $cEnt = $entidadesCounts[strval($ent['id'])] ?? 0; 
                                        $isMatriz = (!empty($ent['is_matriz']) || $ent['id'] == 4);
                                    ?>
                                    <div onclick="seleccionarEntidadCard('add', '<?php echo $ent['id']; ?>')" id="card_add_entidad_<?php echo $ent['id']; ?>" data-entidad-id="<?php echo $ent['id']; ?>" data-color-tema="<?php echo htmlspecialchars($ent['color_tema'] ?? 'teal'); ?>" data-entidad-nombre="<?php echo htmlspecialchars($ent['nombre']); ?>"
                                        class="entidad-card-add cursor-pointer p-3.5 sm:p-4 rounded-2xl border-2 transition-all flex items-center justify-between gap-3.5 sm:gap-4 relative border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800/80 hover:border-slate-300 dark:hover:border-slate-600 hover:bg-slate-50/80">
                                        <div class="flex items-center gap-3.5 sm:gap-4 min-w-0 flex-1">
                                            <div class="w-32 sm:w-36 h-20 rounded-2xl bg-white p-2.5 flex items-center justify-center shrink-0 border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                                                <?php if (!empty($ent['logo']) && file_exists(__DIR__ . '/' . $ent['logo'])): ?>
                                                    <img src="<?php echo htmlspecialchars($ent['logo']); ?>" alt="Logo" class="max-w-full max-h-full object-contain" />
                                                <?php elseif ($isMatriz): ?>
                                                    <img src="assets/img/hologo.png" alt="HO" class="max-w-full max-h-full object-contain" />
                                                <?php else: ?>
                                                    <span class="material-symbols-outlined text-4xl text-indigo-400">domain</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                                    <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white truncate">
                                                        <?php echo htmlspecialchars($ent['nombre']); ?>
                                                    </span>
                                                    <?php if ($isMatriz): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 shrink-0">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                            Propio / Matriz
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 shrink-0">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                                            IPS Externa
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 shrink-0">
                                                        <span class="material-symbols-outlined text-[10px]">groups</span>
                                                        <?php echo $cEnt; ?> <?php echo $cEnt === 1 ? 'médico' : 'médicos'; ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-2 text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 flex-wrap">
                                                    <span class="font-mono font-bold text-slate-600 dark:text-slate-300">NIT: <?php echo htmlspecialchars($ent['nit']); ?></span>
                                                    <?php if (!empty($ent['ciudad'])): ?>
                                                        <span>• <?php echo htmlspecialchars($ent['ciudad']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($ent['email'])): ?>
                                                        <span class="truncate max-w-[170px]">• <?php echo htmlspecialchars($ent['email']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="radio-indicator shrink-0">
                                            <div class="w-5 h-5 rounded-full border-2 border-slate-300 dark:border-slate-600"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                            </div>
                        </div>
                    </div>

                    <!-- Columna Derecha: Modalidad de Pago & Parafiscales Separados -->
                    <div class="lg:col-span-5 space-y-4">
                        
                        <!-- Bloque 1: Modalidades de Pago Habilitadas (Dinámicas) -->
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 space-y-3">
                            <div class="flex items-center justify-between pb-1.5 border-b border-slate-200/70 dark:border-slate-700/70">
                                <p class="text-[11px] font-extrabold uppercase text-slate-600 dark:text-slate-300 tracking-wider">
                                    Modalidades de Pago
                                </p>
                                <span class="material-symbols-outlined text-base text-slate-400">percent</span>
                            </div>
                            
                            <?php foreach ($modalidadesPagoList as $mp): ?>
                                <?php 
                                    $swColor = 'peer-checked:bg-purple-600';
                                    $swHex = (strpos($mp['color'], '#') === 0 && strlen($mp['color']) >= 7) ? substr($mp['color'], 0, 7) : null;
                                    if ($swHex) $swColor = "peer-checked:bg-[{$swHex}]";
                                    elseif ($mp['color'] === 'amber') $swColor = 'peer-checked:bg-amber-600';
                                    elseif ($mp['color'] === 'emerald') $swColor = 'peer-checked:bg-emerald-600';
                                    elseif ($mp['color'] === 'blue') $swColor = 'peer-checked:bg-blue-600';
                                    elseif ($mp['color'] === 'indigo') $swColor = 'peer-checked:bg-indigo-600';
                                    elseif ($mp['color'] === 'rose') $swColor = 'peer-checked:bg-rose-600';
                                    elseif ($mp['color'] === 'teal') $swColor = 'peer-checked:bg-teal-600';
                                    elseif ($mp['color'] === 'cyan') $swColor = 'peer-checked:bg-cyan-600';

                                    $mInlineStyle = obtenerEstiloBadgeInline($mp['color']);
                                    $mBadgeCls = empty($mInlineStyle) ? obtenerEstiloBadgeColor($mp['color']) : '';
                                    $esFijo = (($mp['tipo_calculo'] ?? 'PORCENTAJE') === 'VALOR_FIJO');
                                ?>
                                <div class="mod-item-add flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-700 transition-all"
                                     data-entidad-id="<?php echo (int)($mp['entidad_id'] ?? 0); ?>"
                                     data-tipo="<?php echo htmlspecialchars($mp['tipo']); ?>">
                                    <div class="pr-2">
                                        <label for="add_mod_<?php echo htmlspecialchars($mp['tipo']); ?>" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer flex items-center gap-1.5 flex-wrap">
                                            <span><?php echo htmlspecialchars($mp['nombre']); ?></span>
                                            <?php if ($esFijo): ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-black border shadow-2xs bg-blue-100 dark:bg-blue-950/80 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800">
                                                    $ <?php echo number_format((float)($mp['valor_fijo'] ?? 0), 0, ',', '.'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-black border shadow-2xs <?php echo $mBadgeCls; ?>" <?php echo $mInlineStyle; ?>>
                                                    <?php echo (float)$mp['porcentaje']; ?>%
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5"><?php echo htmlspecialchars($mp['tipo']); ?> &bull; <?php echo $esFijo ? 'Valor fijo por examen' : 'Porcentaje de pago'; ?></p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                        <input type="checkbox" id="add_mod_<?php echo htmlspecialchars($mp['tipo']); ?>" 
                                               name="modalidades[<?php echo htmlspecialchars($mp['tipo']); ?>]" value="1" 
                                               data-tipo="<?php echo htmlspecialchars($mp['tipo']); ?>"
                                               class="sr-only peer mod-switch-add">
                                        <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 <?php echo $swColor; ?>"></div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div id="add_mod_empty_state" class="hidden p-4 rounded-xl bg-slate-100 dark:bg-slate-800/60 text-center text-slate-400 text-xs font-medium">
                                No hay modalidades configuradas para esta entidad.
                            </div>
                        </div>

                        <!-- Bloque 2: Deducciones y Parafiscales (Separado e Independiente) -->
                        <div class="p-4 rounded-2xl bg-teal-50/60 dark:bg-teal-950/20 border border-teal-200/80 dark:border-teal-900/60 space-y-3">
                            <div class="flex items-center justify-between pb-1.5 border-b border-teal-200/70 dark:border-teal-800/70">
                                <p class="text-[11px] font-extrabold uppercase text-teal-800 dark:text-teal-300 tracking-wider">
                                    Aportes y Parafiscales
                                </p>
                                <span class="material-symbols-outlined text-base text-teal-600 dark:text-teal-400">account_balance</span>
                            </div>

                            <!-- Parafiscales Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="add_parafiscales" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Parafiscales
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Habilitar cálculo / deducción de parafiscales</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="add_parafiscales" name="parafiscales" value="1" 
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-emerald-600"></div>
                                </label>
                            </div>

                            <!-- Pensionados Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="add_pensionado" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Pensionados
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Exento de cotización a pensión (solo liquida Salud y ARL)</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="add_pensionado" name="pensionado" value="1" 
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <!-- Retenciones Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="add_retenciones" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Retenciones
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Habilitar cálculo / deducción de retención en la fuente</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="add_retenciones" name="retenciones" value="1" 
                                           onchange="handleRetencionesExclusivity('add', 'retenciones')"
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-rose-600"></div>
                                </label>
                            </div>

                            <!-- Retención Art 383 Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="add_retencion_art_383" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Retención Art 383
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Habilitar cálculo / deducción de Retención Art. 383</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="add_retencion_art_383" name="retencion_art_383" value="1" 
                                           onchange="handleRetencionesExclusivity('add', 'retencion_art_383')"
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-cyan-600"></div>
                                </label>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" id="cancelModalBtn" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-primary dark:bg-tertiary hover:bg-[#1c486a] text-white shadow-md transition-all cursor-pointer">
                        Guardar Médico
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- Modal Editar Médico / Usuario -->
    <div id="editMedicoModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-4xl w-full p-6 sm:p-8 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300 animate-card-entry max-h-[92vh] overflow-y-auto">
            
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100 dark:border-slate-800" id="edit_modal_header">
                <div class="flex items-center gap-3">
                    <div id="edit_modal_icon_box" class="w-10 h-10 rounded-2xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold transition-all">
                        <span class="material-symbols-outlined text-xl">edit_note</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Actualizar Datos del Médico</h2>
                            <span id="edit_modal_entity_badge" class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider border hidden transition-all"></span>
                        </div>
                        <p class="text-xs text-slate-400 dark:text-slate-500 font-medium">Modifique la información registrada en el sistema y configure sus modalidades</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalEditar()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="editar_medico" />
                <input type="hidden" id="edit_user_id" name="user_id" value="" />

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    <!-- Columna Izquierda: Datos Personales & Cuenta -->
                    <div class="lg:col-span-7 space-y-4">
                        
                        <!-- Selección de Rol -->
                        <div>
                            <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1" for="edit_rol_id">
                                Rol del Usuario *
                            </label>
                            <select name="rol_id" id="edit_rol_id" required class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none">
                                <?php foreach ($rolesList as $r): ?>
                                    <option value="<?php echo htmlspecialchars($r['id']); ?>">
                                        <?php echo htmlspecialchars($r['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Nombres -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Primer Nombre *</label>
                                <input type="text" id="edit_pnom" name="pnom" required placeholder="Ej: JUAN" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Segundo Nombre</label>
                                <input type="text" id="edit_snom" name="snom" placeholder="Ej: ESTEBAN" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Apellidos -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Primer Apellido *</label>
                                <input type="text" id="edit_pape" name="pape" required placeholder="Ej: PEREZ" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Segundo Apellido</label>
                                <input type="text" id="edit_sape" name="sape" placeholder="Ej: GARCIA" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Cédula & Usuario Proteo -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Cédula / Documento *</label>
                                <input type="text" id="edit_cedula" name="cedula" required placeholder="Ej: 123456789" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Usuario Proteo *</label>
                                <input type="text" id="edit_usuario_proteo" name="usuario_proteo" required placeholder="Ej: C123456789" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Correo Electrónico & Fecha de Nacimiento -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                                <input type="email" id="edit_email" name="email" required placeholder="medico@hernanocazionez.com" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider mb-1">Fecha Nacimiento</label>
                                <input type="date" id="edit_fecha_nacimiento" name="fecha_nacimiento" class="w-full bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-semibold text-primary dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-tertiary/30 outline-none" />
                            </div>
                        </div>

                        <!-- Entidad / IPS Perteneciente (Selector en Recuadros con Logos) -->
                        <div class="pt-1">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-[11px] font-bold text-primary dark:text-slate-300 uppercase tracking-wider">
                                    Entidad / IPS Perteneciente <span class="text-rose-500">*</span>
                                </label>
                                <span class="text-[10px] text-slate-400 font-semibold">Seleccione la institución</span>
                            </div>

                            <input type="hidden" name="entidad_id" id="edit_entidad_id" value="" />

                            <!-- Listado de Recuadros Interactivos con Logos Amplios y Legibles -->
                            <div class="space-y-3 max-h-[320px] overflow-y-auto pr-1.5">
                                <?php foreach ($entidadesList as $ent): ?>
                                    <?php 
                                        $cEnt = $entidadesCounts[strval($ent['id'])] ?? 0; 
                                        $isMatriz = (!empty($ent['is_matriz']) || $ent['id'] == 4);
                                    ?>
                                    <div onclick="seleccionarEntidadCard('edit', '<?php echo $ent['id']; ?>')" id="card_edit_entidad_<?php echo $ent['id']; ?>" data-entidad-id="<?php echo $ent['id']; ?>" data-color-tema="<?php echo htmlspecialchars($ent['color_tema'] ?? 'teal'); ?>" data-entidad-nombre="<?php echo htmlspecialchars($ent['nombre']); ?>"
                                        class="entidad-card-edit cursor-pointer p-3.5 sm:p-4 rounded-2xl border-2 transition-all flex items-center justify-between gap-3.5 sm:gap-4 relative border-slate-200 dark:border-slate-700/80 bg-white dark:bg-slate-800/80 hover:border-slate-300 dark:hover:border-slate-600 hover:bg-slate-50/80">
                                        <div class="flex items-center gap-3.5 sm:gap-4 min-w-0 flex-1">
                                            <div class="w-32 sm:w-36 h-20 rounded-2xl bg-white p-2.5 flex items-center justify-center shrink-0 border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">
                                                <?php if (!empty($ent['logo']) && file_exists(__DIR__ . '/' . $ent['logo'])): ?>
                                                    <img src="<?php echo htmlspecialchars($ent['logo']); ?>" alt="Logo" class="max-w-full max-h-full object-contain" />
                                                <?php elseif ($isMatriz): ?>
                                                    <img src="assets/img/hologo.png" alt="HO" class="max-w-full max-h-full object-contain" />
                                                <?php else: ?>
                                                    <span class="material-symbols-outlined text-4xl text-indigo-400">domain</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                                    <span class="font-extrabold text-xs sm:text-sm text-slate-900 dark:text-white truncate">
                                                        <?php echo htmlspecialchars($ent['nombre']); ?>
                                                    </span>
                                                    <?php if ($isMatriz): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 shrink-0">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                                            Propio / Matriz
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 shrink-0">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                                            IPS Externa
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[9px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 shrink-0">
                                                        <span class="material-symbols-outlined text-[10px]">groups</span>
                                                        <?php echo $cEnt; ?> <?php echo $cEnt === 1 ? 'médico' : 'médicos'; ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center gap-2 text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 flex-wrap">
                                                    <span class="font-mono font-bold text-slate-600 dark:text-slate-300">NIT: <?php echo htmlspecialchars($ent['nit']); ?></span>
                                                    <?php if (!empty($ent['ciudad'])): ?>
                                                        <span>• <?php echo htmlspecialchars($ent['ciudad']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($ent['email'])): ?>
                                                        <span class="truncate max-w-[170px]">• <?php echo htmlspecialchars($ent['email']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="radio-indicator shrink-0">
                                            <div class="w-5 h-5 rounded-full border-2 border-slate-300 dark:border-slate-600"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Columna Derecha: Modalidad de Pago & Parafiscales Separados -->
                    <div class="lg:col-span-5 space-y-4">
                        
                        <!-- Bloque 1: Modalidades de Pago Habilitadas (Dinámicas) -->
                        <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 space-y-3">
                            <div class="flex items-center justify-between pb-1.5 border-b border-slate-200/70 dark:border-slate-700/70">
                                <p class="text-[11px] font-extrabold uppercase text-slate-600 dark:text-slate-300 tracking-wider">
                                    Modalidades de Pago
                                </p>
                                <span class="material-symbols-outlined text-base text-slate-400">percent</span>
                            </div>
                            
                            <?php foreach ($modalidadesPagoList as $mp): ?>
                                <?php 
                                    $swColor = 'peer-checked:bg-purple-600';
                                    $swHex = (strpos($mp['color'], '#') === 0 && strlen($mp['color']) >= 7) ? substr($mp['color'], 0, 7) : null;
                                    if ($swHex) $swColor = "peer-checked:bg-[{$swHex}]";
                                    elseif ($mp['color'] === 'amber') $swColor = 'peer-checked:bg-amber-600';
                                    elseif ($mp['color'] === 'emerald') $swColor = 'peer-checked:bg-emerald-600';
                                    elseif ($mp['color'] === 'blue') $swColor = 'peer-checked:bg-blue-600';
                                    elseif ($mp['color'] === 'indigo') $swColor = 'peer-checked:bg-indigo-600';
                                    elseif ($mp['color'] === 'rose') $swColor = 'peer-checked:bg-rose-600';
                                    elseif ($mp['color'] === 'teal') $swColor = 'peer-checked:bg-teal-600';
                                    elseif ($mp['color'] === 'cyan') $swColor = 'peer-checked:bg-cyan-600';

                                    $mInlineStyle = obtenerEstiloBadgeInline($mp['color']);
                                    $mBadgeCls = empty($mInlineStyle) ? obtenerEstiloBadgeColor($mp['color']) : '';
                                    $esFijo = (($mp['tipo_calculo'] ?? 'PORCENTAJE') === 'VALOR_FIJO');
                                ?>
                                <div class="mod-item-edit flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-700 transition-all"
                                     data-entidad-id="<?php echo (int)($mp['entidad_id'] ?? 0); ?>"
                                     data-tipo="<?php echo htmlspecialchars($mp['tipo']); ?>">
                                    <div class="pr-2">
                                        <label for="edit_mod_<?php echo htmlspecialchars($mp['tipo']); ?>" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer flex items-center gap-1.5 flex-wrap">
                                            <span><?php echo htmlspecialchars($mp['nombre']); ?></span>
                                            <?php if ($esFijo): ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-black border shadow-2xs bg-blue-100 dark:bg-blue-950/80 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800">
                                                    $ <?php echo number_format((float)($mp['valor_fijo'] ?? 0), 0, ',', '.'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-black border shadow-2xs <?php echo $mBadgeCls; ?>" <?php echo $mInlineStyle; ?>>
                                                    <?php echo (float)$mp['porcentaje']; ?>%
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                        <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5"><?php echo htmlspecialchars($mp['tipo']); ?> &bull; <?php echo $esFijo ? 'Valor fijo por examen' : 'Porcentaje de pago'; ?></p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                        <input type="checkbox" id="edit_mod_<?php echo htmlspecialchars($mp['tipo']); ?>" 
                                               name="modalidades[<?php echo htmlspecialchars($mp['tipo']); ?>]" value="1" 
                                               data-tipo="<?php echo htmlspecialchars($mp['tipo']); ?>"
                                               class="sr-only peer mod-switch-edit">
                                        <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 <?php echo $swColor; ?>"></div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div id="edit_mod_empty_state" class="hidden p-4 rounded-xl bg-slate-100 dark:bg-slate-800/60 text-center text-slate-400 text-xs font-medium">
                                No hay modalidades configuradas para esta entidad.
                            </div>
                        </div>

                        <!-- Bloque 2: Deducciones y Parafiscales (Separado e Independiente) -->
                        <div class="p-4 rounded-2xl bg-teal-50/60 dark:bg-teal-950/20 border border-teal-200/80 dark:border-teal-900/60 space-y-3">
                            <div class="flex items-center justify-between pb-1.5 border-b border-teal-200/70 dark:border-teal-800/70">
                                <p class="text-[11px] font-extrabold uppercase text-teal-800 dark:text-teal-300 tracking-wider">
                                    Aportes y Parafiscales
                                </p>
                                <span class="material-symbols-outlined text-base text-teal-600 dark:text-teal-400">account_balance</span>
                            </div>

                            <!-- Parafiscales Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="edit_parafiscales" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Parafiscales
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Habilitar cálculo / deducción de parafiscales</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="edit_parafiscales" name="parafiscales" value="1" 
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-emerald-600"></div>
                                </label>
                            </div>

                            <!-- Pensionados Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="edit_pensionado" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Pensionados
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Exento de cotización a pensión (solo liquida Salud y ARL)</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="edit_pensionado" name="pensionado" value="1" 
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-indigo-600"></div>
                                </label>
                            </div>

                            <!-- Retenciones Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="edit_retenciones" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Retenciones
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Habilitar cálculo / deducción de retención en la fuente</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="edit_retenciones" name="retenciones" value="1" 
                                           onchange="handleRetencionesExclusivity('edit', 'retenciones')"
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-rose-600"></div>
                                </label>
                            </div>

                            <!-- Retención Art 383 Switch -->
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white dark:bg-slate-900 border border-teal-200/70 dark:border-teal-800/70 shadow-2xs">
                                <div class="pr-2">
                                    <label for="edit_retencion_art_383" class="text-xs font-bold text-slate-800 dark:text-slate-100 cursor-pointer block">
                                        Retención Art 383
                                    </label>
                                    <p class="text-[10px] text-slate-400 font-medium leading-tight mt-0.5">Habilitar cálculo / deducción de Retención Art. 383</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer shrink-0">
                                    <input type="checkbox" id="edit_retencion_art_383" name="retencion_art_383" value="1" 
                                           onchange="handleRetencionesExclusivity('edit', 'retencion_art_383')"
                                           class="sr-only peer">
                                    <div class="w-11 h-6 bg-slate-300 peer-focus:outline-none rounded-full peer dark:bg-slate-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-cyan-600"></div>
                                </label>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalEditar()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white shadow-md transition-all cursor-pointer">
                        Actualizar Datos
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- Footer Component -->
    <?php include(__DIR__ . '/includes/footer.php'); ?>

    <script>
    function handleMedicoExclusivity(prefix, field) {
        const esp = document.getElementById(`${prefix}_tarifas_especiales`);
        const deg = document.getElementById(`${prefix}_degluciones`);
        if (field === 'tarifas_especiales' && esp && esp.checked && deg) {
            deg.checked = false;
        } else if (field === 'degluciones' && deg && deg.checked && esp) {
            esp.checked = false;
        }
    }

    function handleRetencionesExclusivity(prefix, field) {
        const ret = document.getElementById(`${prefix}_retenciones`);
        const r383 = document.getElementById(`${prefix}_retencion_art_383`);
        if (field === 'retenciones' && ret && ret.checked && r383) {
            r383.checked = false;
        } else if (field === 'retencion_art_383' && r383 && r383.checked && ret) {
            ret.checked = false;
        }
    }

    const colorThemePalette = {
        purple: { hex: '#7c3aed', lightBg: '#f5f3ff', darkBg: 'rgba(124, 58, 237, 0.15)', text: '#6d28d9', border: '#ddd6fe' },
        indigo: { hex: '#4f46e5', lightBg: '#eef2ff', darkBg: 'rgba(79, 70, 229, 0.15)', text: '#4338ca', border: '#c7d2fe' },
        blue:   { hex: '#2563eb', lightBg: '#eff6ff', darkBg: 'rgba(37, 99, 235, 0.15)', text: '#1d4ed8', border: '#bfdbfe' },
        emerald:{ hex: '#059669', lightBg: '#ecfdf5', darkBg: 'rgba(5, 150, 105, 0.15)', text: '#047857', border: '#a7f3d0' },
        teal:   { hex: '#0d9488', lightBg: '#f0fdfa', darkBg: 'rgba(13, 148, 136, 0.15)', text: '#0f766e', border: '#99f6e4' },
        cyan:   { hex: '#0891b2', lightBg: '#ecfeff', darkBg: 'rgba(8, 145, 178, 0.15)', text: '#0e7490', border: '#a5f3fc' },
        rose:   { hex: '#e11d48', lightBg: '#fff1f2', darkBg: 'rgba(225, 29, 72, 0.15)', text: '#be123c', border: '#fecdd3' },
        amber:  { hex: '#d97706', lightBg: '#fffbeb', darkBg: 'rgba(217, 119, 6, 0.15)', text: '#b45309', border: '#fde68a' },
        slate:  { hex: '#475569', lightBg: '#f8fafc', darkBg: 'rgba(71, 85, 105, 0.15)', text: '#334155', border: '#cbd5e1' }
    };

    function getThemeObj(colorNameOrHex) {
        if (!colorNameOrHex) return { hex: '#13757a', text: '#13757a', border: '#99f6e4' };
        const key = String(colorNameOrHex).trim().toLowerCase();
        if (colorThemePalette[key]) return colorThemePalette[key];
        if (key.startsWith('#')) return { hex: key, text: key, border: key + '60' };
        return { hex: '#13757a', text: '#13757a', border: '#99f6e4' };
    }

    function seleccionarEntidadCard(modalType, entidadId) {
        const hiddenInput = document.getElementById(`${modalType}_entidad_id`);
        const val = (entidadId !== undefined && entidadId !== null && String(entidadId).trim() !== '') ? String(entidadId).trim() : '';
        if (hiddenInput) {
            hiddenInput.value = val;
        }

        const targetEntId = (val !== '') ? parseInt(val, 10) : 0;
        let selectedTheme = getThemeObj('#13757a');
        let selectedName = 'Hernán Ocazionez';

        const cards = document.querySelectorAll(`.entidad-card-${modalType}`);
        cards.forEach(card => {
            const cardEntId = card.getAttribute('data-entidad-id') || '';
            const isMatch = (cardEntId === val);
            const indicator = card.querySelector('.radio-indicator');
            const cardColor = card.getAttribute('data-color-tema') || '#13757a';
            const cardName = card.getAttribute('data-entidad-nombre') || '';
            const th = getThemeObj(cardColor);
            
            if (isMatch) {
                selectedTheme = th;
                if (cardName) selectedName = cardName;
                card.style.borderColor = th.hex;
                card.style.boxShadow = `0 0 0 3px ${th.hex}30`;
                card.style.backgroundColor = `${th.hex}0d`;
                card.classList.remove('border-slate-200', 'dark:border-slate-700/80', 'bg-white', 'dark:bg-slate-800/80');
                if (indicator) {
                    indicator.innerHTML = `
                        <div class="w-5 h-5 rounded-full flex items-center justify-center shadow-xs text-white" style="background-color: ${th.hex};">
                            <span class="material-symbols-outlined text-xs font-black">check</span>
                        </div>
                    `;
                }
            } else {
                card.style.borderColor = '';
                card.style.boxShadow = '';
                card.style.backgroundColor = '';
                card.classList.add('border-slate-200', 'dark:border-slate-700/80');
                if (indicator) {
                    indicator.innerHTML = `
                        <div class="w-5 h-5 rounded-full border-2 border-slate-300 dark:border-slate-600"></div>
                    `;
                }
            }
        });

        // 1. Filtrar modalidades de pago para mostrar SÓLO las de la entidad/IPS seleccionada
        let visibleCount = 0;
        document.querySelectorAll(`.mod-item-${modalType}`).forEach(item => {
            const itemEntId = parseInt(item.getAttribute('data-entidad-id') || '0', 10);
            if (itemEntId === targetEntId) {
                item.style.display = 'flex';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
        const emptyState = document.getElementById(`${modalType}_mod_empty_state`);
        if (emptyState) {
            emptyState.style.display = (visibleCount === 0) ? 'block' : 'none';
        }

        // 2. Ambientar la vista / modal con el color de la entidad/IPS seleccionada
        const modalContainer = (modalType === 'edit') ? document.getElementById('editMedicoModal') : document.getElementById('modalRegistrar');
        if (modalContainer) {
            const cardContent = modalContainer.querySelector('.rounded-3xl');
            if (cardContent) {
                cardContent.style.borderTop = `5px solid ${selectedTheme.hex}`;
            }
        }
        if (modalType === 'edit') {
            const iconBox = document.getElementById('edit_modal_icon_box');
            if (iconBox) {
                iconBox.style.backgroundColor = `${selectedTheme.hex}18`;
                iconBox.style.color = selectedTheme.hex;
            }
            const entityBadge = document.getElementById('edit_modal_entity_badge');
            if (entityBadge) {
                entityBadge.style.display = 'inline-block';
                entityBadge.style.backgroundColor = `${selectedTheme.hex}18`;
                entityBadge.style.color = selectedTheme.hex;
                entityBadge.style.borderColor = `${selectedTheme.hex}40`;
                entityBadge.textContent = selectedName;
            }
        }
    }

    function abrirModalEditar(data) {
        document.getElementById('edit_user_id').value = data.id || '';
        document.getElementById('edit_rol_id').value = data.rol_id || '3';
        document.getElementById('edit_pnom').value = data.pnom || '';
        document.getElementById('edit_snom').value = data.snom || '';
        document.getElementById('edit_pape').value = data.pape || '';
        document.getElementById('edit_sape').value = data.sape || '';
        document.getElementById('edit_cedula').value = data.cedula || '';
        document.getElementById('edit_usuario_proteo').value = data.usuario_proteo || '';
        document.getElementById('edit_email').value = data.email || '';
        
        if (data.fecha_nacimiento && data.fecha_nacimiento.length >= 10) {
            document.getElementById('edit_fecha_nacimiento').value = data.fecha_nacimiento.substring(0, 10);
        } else {
            document.getElementById('edit_fecha_nacimiento').value = '';
        }

        // Asignar Entidad / IPS en los recuadros
        seleccionarEntidadCard('edit', data.entidad_id || '4');

        // Activar switches de modalidades dinámicas
        const modsList = Array.isArray(data.modalidades) ? [...data.modalidades] : ((data.modalidades_str || '').split(',').map(s => s.trim()).filter(Boolean));
        if ((data.tarifas_especiales == 1 || data.tarifas_especiales === '1') && !modsList.includes('TARIFAS_ESPECIALES')) modsList.push('TARIFAS_ESPECIALES');
        if ((data.degluciones == 1 || data.degluciones === '1') && !modsList.includes('DEGLUCIONES')) modsList.push('DEGLUCIONES');

        document.querySelectorAll('.mod-switch-edit').forEach(sw => {
            const t = sw.getAttribute('data-tipo');
            sw.checked = modsList.includes(t);
        });

        const checkParafiscales = document.getElementById('edit_parafiscales');
        if (checkParafiscales) {
            checkParafiscales.checked = (data.parafiscales == 1 || data.parafiscales === '1' || data.parafiscales === true);
        }

        const checkPensionado = document.getElementById('edit_pensionado');
        if (checkPensionado) {
            checkPensionado.checked = (data.pensionado == 1 || data.pensionado === '1' || data.pensionado === true);
        }

        const checkRetenciones = document.getElementById('edit_retenciones');
        if (checkRetenciones) {
            checkRetenciones.checked = (data.retenciones == 1 || data.retenciones === '1' || data.retenciones === true);
        }

        const checkRetencion383 = document.getElementById('edit_retencion_art_383');
        if (checkRetencion383) {
            checkRetencion383.checked = (data.retencion_art_383 == 1 || data.retencion_art_383 === '1' || data.retencion_art_383 === true);
        }

        const modal = document.getElementById('editMedicoModal');
        if (modal) modal.classList.remove('hidden');
    }

    function cerrarModalEditar() {
        const modal = document.getElementById('editMedicoModal');
        if (modal) modal.classList.add('hidden');
    }

    async function togglePensionado(userId, chkElement) {
        const isChecked = chkElement.checked;
        const labelSpan = chkElement.parentElement.querySelector('span');
        try {
            const formData = new FormData();
            formData.append('action', 'toggle_pensionado');
            formData.append('user_id', userId);
            formData.append('pensionado', isChecked ? '1' : '0');

            const resp = await fetch('medicos.php', {
                method: 'POST',
                body: formData
            });
            const res = await resp.json();

            if (res.success) {
                if (labelSpan) {
                    labelSpan.textContent = isChecked ? 'Sí' : 'No';
                    labelSpan.className = `ml-2 text-[11px] font-extrabold ${isChecked ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400'}`;
                }
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2200,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: isChecked ? 'Marcado como Pensionado (Exento de Pensión)' : 'Desmarcado como Pensionado'
                });
            } else {
                chkElement.checked = !isChecked;
                Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'No se pudo actualizar' });
            }
        } catch (err) {
            chkElement.checked = !isChecked;
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexión.' });
        }
    }

    // Variables de Estado de Filtro Globales
    let currentStatusFilter = 'ALL';
    let currentEntidadFilter = 'ALL';

    function cambiarVistaMedicos(modo) {
        const cardsView = document.getElementById('medicosCardsView');
        const tableView = document.getElementById('medicosTableView');
        const btnCards = document.getElementById('btnViewCards');
        const btnTable = document.getElementById('btnViewTable');

        if (modo === 'table') {
            if (cardsView) cardsView.classList.add('hidden');
            if (tableView) tableView.classList.remove('hidden');
            if (btnCards) {
                btnCards.className = "flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer";
            }
            if (btnTable) {
                btnTable.className = "flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-white dark:bg-slate-900 text-tertiary shadow-xs cursor-pointer";
            }
            localStorage.setItem('liho_medicos_view_mode', 'table');
        } else {
            if (cardsView) cardsView.classList.remove('hidden');
            if (tableView) tableView.classList.add('hidden');
            if (btnCards) {
                btnCards.className = "flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-white dark:bg-slate-900 text-tertiary shadow-xs cursor-pointer";
            }
            if (btnTable) {
                btnTable.className = "flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200 cursor-pointer";
            }
            localStorage.setItem('liho_medicos_view_mode', 'cards');
        }
    }

    function aplicarFiltros() {
        const term = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();

        // 1. Filtrar Filas de la Tabla
        const rows = document.querySelectorAll('.medico-row');
        let visibleRows = 0;
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const estado = row.getAttribute('data-estado');
            const entidad = row.getAttribute('data-entidad');

            const matchSearch = !term || text.includes(term);
            const matchStatus = (currentStatusFilter === 'ALL' || estado === currentStatusFilter);
            const matchEntidad = (currentEntidadFilter === 'ALL' || entidad === currentEntidadFilter);

            if (matchSearch && matchStatus && matchEntidad) {
                row.style.display = '';
                visibleRows++;
            } else {
                row.style.display = 'none';
            }
        });

        // 2. Filtrar Tarjetas de Usuario
        const cards = document.querySelectorAll('.medico-card');
        let visibleCards = 0;
        cards.forEach(card => {
            const searchData = (card.getAttribute('data-search') || card.textContent).toLowerCase();
            const estado = card.getAttribute('data-estado');
            const entidad = card.getAttribute('data-entidad');

            const matchSearch = !term || searchData.includes(term);
            const matchStatus = (currentStatusFilter === 'ALL' || estado === currentStatusFilter);
            const matchEntidad = (currentEntidadFilter === 'ALL' || entidad === currentEntidadFilter);

            if (matchSearch && matchStatus && matchEntidad) {
                card.style.display = '';
                visibleCards++;
            } else {
                card.style.display = 'none';
            }
        });

        // 3. Manejo de Estados Vacíos
        const emptyCards = document.getElementById('emptyCardsState');
        if (emptyCards) {
            emptyCards.classList.toggle('hidden', visibleCards > 0 || cards.length === 0);
        }
        const emptyRows = document.getElementById('emptyRowsState');
        if (emptyRows) {
            emptyRows.classList.toggle('hidden', visibleRows > 0 || rows.length === 0);
        }
    }

    function filterStatus(status) {
        currentStatusFilter = status;
        const btnAll = document.getElementById('btnFilterALL');
        const btn1 = document.getElementById('btnFilter1');
        const btn0 = document.getElementById('btnFilter0');

        [btnAll, btn1, btn0].forEach(btn => {
            if (btn) {
                btn.className = "px-3.5 py-1.5 rounded-xl text-xs font-extrabold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-all cursor-pointer";
            }
        });

        if (status === 'ALL') {
            if (btnAll) btnAll.className = "px-3.5 py-1.5 rounded-xl text-xs font-extrabold bg-primary dark:bg-tertiary text-white shadow-xs transition-all cursor-pointer";
        } else if (status === '1') {
            if (btn1) btn1.className = "px-3.5 py-1.5 rounded-xl text-xs font-extrabold bg-emerald-600 text-white shadow-xs transition-all cursor-pointer";
        } else if (status === '0') {
            if (btn0) btn0.className = "px-3.5 py-1.5 rounded-xl text-xs font-extrabold bg-rose-600 text-white shadow-xs transition-all cursor-pointer";
        }

        aplicarFiltros();
    }

    function filterEntidad(entId) {
        currentEntidadFilter = entId;
        actualizarCardsStatEntidad(entId);
        aplicarFiltros();
    }

    function filtrarEntidadRapido(entId) {
        const select = document.getElementById('filterEntidad');
        if (select) {
            select.value = entId;
        }
        filterEntidad(entId);
    }

    function actualizarCardsStatEntidad(activeEntId) {
        document.querySelectorAll('.card-stat-entidad').forEach(card => {
            const statId = card.getAttribute('data-entidad-stat');
            if (statId === activeEntId) {
                card.className = "card-stat-entidad cursor-pointer p-3.5 rounded-2xl border-2 transition-all bg-teal-50/80 dark:bg-teal-950/50 border-tertiary shadow-sm ring-2 ring-tertiary/20 flex items-center gap-3.5";
            } else {
                card.className = "card-stat-entidad cursor-pointer p-3.5 rounded-2xl border-2 transition-all bg-white dark:bg-slate-900 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 shadow-xs flex items-center gap-3.5";
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Inicializar vista guardada o tarjetas por defecto
        const savedView = localStorage.getItem('liho_medicos_view_mode') || 'cards';
        cambiarVistaMedicos(savedView);

        // Modal Handlers
        const openBtn = document.getElementById('openModalBtn');
        const closeBtn = document.getElementById('closeModalBtn');
        const cancelBtn = document.getElementById('cancelModalBtn');
        const modal = document.getElementById('addMedicoModal');

        if (openBtn && modal) {
            openBtn.addEventListener('click', () => {
                document.querySelectorAll('.mod-switch-add').forEach(sw => sw.checked = false);
                document.getElementById('add_parafiscales').checked = false;
                document.getElementById('add_pensionado').checked = false;
                document.getElementById('add_retenciones').checked = false;
                document.getElementById('add_retencion_art_383').checked = false;
                
                seleccionarEntidadCard('add', '4');

                modal.classList.remove('hidden');
            });
        }
        if (closeBtn && modal) {
            closeBtn.addEventListener('click', () => modal.classList.add('hidden'));
        }
        if (cancelBtn && modal) {
            cancelBtn.addEventListener('click', () => modal.classList.add('hidden'));
        }

        // Buscador en Vivo (aplica a tarjetas y filas de tabla)
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', aplicarFiltros);
        }
    });

    // SweetAlert2 personalizado corporativo
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

    async function confirmarToggleEstadoMedico(userId, estadoActual, nombreMedico) {
        const esActivo = (estadoActual === 'ACTIVO' || estadoActual === '1');
        const titulo = esActivo ? '¿Desactivar Médico Especialista?' : '¿Activar Médico Especialista?';
        const accionTexto = esActivo ? 'desactivar la cuenta' : 'activar la cuenta';

        const result = await SwalCustom.fire({
            title: titulo,
            icon: esActivo ? 'warning' : 'question',
            html: `
                <div class="p-3.5 rounded-2xl bg-slate-800/90 border border-slate-700/80 text-left my-2 space-y-2">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400 font-medium">Médico:</span>
                        <span class="font-bold text-white">${nombreMedico}</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400 font-medium">Estado Actual:</span>
                        <span class="font-bold font-mono ${esActivo ? 'text-emerald-400' : 'text-rose-400'}">${esActivo ? 'ACTIVO' : 'INACTIVO'}</span>
                    </div>
                </div>
                <p class="text-xs text-slate-300 text-center mt-2.5">
                    ¿Confirma que desea <strong>${accionTexto}</strong> de este profesional en el sistema?
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
        actInput.value = 'toggle_estado';
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
    </script>

</body>

</html>

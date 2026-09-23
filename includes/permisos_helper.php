<?php
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/logger_helper.php';

/**
 * Asegura la existencia de tablas y columnas necesarias para Roles y Permisos
 */
function asegurarTablasRolesYPermisos() {
    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) {
            $con = obtenerConexionLIHO();
        } elseif (function_exists('obtenerConexionLIMED')) {
            $con = obtenerConexionLIMED();
        }
    }
    if ($con === false) return;

    // 1. Tabla permisos_roles
    $sql1 = "IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'permisos_roles')
    BEGIN
        CREATE TABLE permisos_roles (
            id INT IDENTITY(1,1) PRIMARY KEY,
            rol_id INT NOT NULL,
            modulo_clave VARCHAR(100) NOT NULL,
            permitido BIT DEFAULT 1,
            fecha_asignacion DATETIME DEFAULT GETDATE()
        );
    END";
    @sqlsrv_query($con, $sql1);

    // 2. Columnas en tabla roles
    $colsCheck = array(
        'descripcion' => "ALTER TABLE roles ADD descripcion VARCHAR(255) NULL;",
        'color_tema'  => "ALTER TABLE roles ADD color_tema VARCHAR(50) DEFAULT 'sky';",
        'fecha_creacion' => "ALTER TABLE roles ADD fecha_creacion DATETIME DEFAULT GETDATE();",
        'fecha_modificacion' => "ALTER TABLE roles ADD fecha_modificacion DATETIME DEFAULT GETDATE();"
    );

    foreach ($colsCheck as $col => $sqlAlter) {
        $checkCol = "IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'roles' AND COLUMN_NAME = '$col')
        BEGIN
            $sqlAlter
        END";
        @sqlsrv_query($con, $checkCol);
    }
}

// Ejecutar aseguramiento de tablas
asegurarTablasRolesYPermisos();

/**
 * Lista oficial de módulos del sistema LIHO susceptibles de permisos por rol o especiales
 */
function obtenerModulosSistema() {
    return array(
        'dashboard' => array('nombre' => 'Panel de Inicio (Dashboard)', 'descripcion' => 'Acceso al resumen ejecutivo y métricas generales', 'icono' => 'dashboard'),
        'medicos'   => array('nombre' => 'Gestión de Médicos', 'descripcion' => 'Directorio, creación y activación de médicos IPS', 'icono' => 'medical_services'),
        'estadisticas' => array('nombre' => 'Productividad y Estadísticas', 'descripcion' => 'Informes de cobertura y avance de procesos', 'icono' => 'analytics'),
        'liquidaciones' => array('nombre' => 'Módulo de Liquidaciones', 'descripcion' => 'Carga y consulta de liquidaciones médicas u honorarios', 'icono' => 'payments'),
        'examenes_medicos' => array('nombre' => 'Exámenes de Médicos', 'descripcion' => 'Consulta y cruce de exámenes entre PROTEO (SQL Server) y SERVINTE (Oracle)', 'icono' => 'description'),
        'gestion_medicos_procedimientos' => array('nombre' => 'Gestión Médicos Procedimientos', 'descripcion' => 'Cruce de procedimientos y enfermería entre PROTEO y SERVINTE', 'icono' => 'vital_signs'),
        'tarifario_especial' => array('nombre' => 'Tarifas Especiales', 'descripcion' => 'Catálogo y gestión de honorarios especiales para médicos', 'icono' => 'stars'),
        'maestro_porcentajes' => array('nombre' => 'Maestro de Porcentajes', 'descripcion' => 'Configuración de % de pago, biopsias y descuentos', 'icono' => 'percent'),
        'maestro_parafiscales' => array('nombre' => 'Maestro de Parafiscales', 'descripcion' => 'Configuración de tasas en vivo para IBC, Salud y ARL', 'icono' => 'account_balance'),
        'notas_ajuste' => array('nombre' => 'Notas de Ajuste', 'descripcion' => 'Ajustes, créditos, débitos y correcciones a liquidaciones sin alterar el original', 'icono' => 'note_alt'),
        'alerta_medicos' => array('nombre' => 'Alerta Médicos', 'descripcion' => 'Monitoreo de pacientes en curso y alertas automáticas a médicos', 'icono' => 'notifications_active'),
        'examenes_excluidos' => array('nombre' => 'Auditoría de Exámenes Excluidos', 'descripcion' => 'Historial y justificaciones obligatorias de exámenes excluidos', 'icono' => 'do_not_disturb_on'),
        'logs_correos' => array('nombre' => 'Auditoría e Historial de Correos', 'descripcion' => 'Historial y registros de envíos de correo en SQL Server', 'icono' => 'mail_lock'),
        'maestro_entidades' => array('nombre' => 'Maestro de Entidades / IPS', 'descripcion' => 'Registro de empresas, IPS e instituciones para vinculación médica', 'icono' => 'domain'),
        'maestro_novedades' => array('nombre' => 'Maestro de Novedades', 'descripcion' => 'Catálogo institucional de conceptos y novedades de honorarios y nómina por entidad', 'icono' => 'campaign'),
        'gestion_roles' => array('nombre' => 'Gestión de Roles y Permisos', 'descripcion' => 'Administración de roles institucionales y matriz de accesos por pantalla', 'icono' => 'admin_panel_settings')
    );
}

/**
 * Consulta la lista completa de roles registrados
 */
function obtenerRolesSistema() {
    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) $con = obtenerConexionLIHO();
    }
    $rolesList = array();
    if ($con !== false) {
        $sql = "SELECT r.id, r.nombre, r.estado, r.descripcion, r.color_tema, r.fecha_creacion,
                       (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = CAST(r.id AS VARCHAR)) AS total_usuarios,
                       (SELECT COUNT(*) FROM permisos_roles pr WHERE pr.rol_id = r.id AND pr.permitido = 1) AS total_modulos
                FROM roles r
                ORDER BY r.id ASC";
        $stmt = sqlsrv_query($con, $sql);
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['fecha_creacion'] instanceof DateTime) {
                    $row['fecha_creacion'] = $row['fecha_creacion']->format('Y-m-d H:i:s');
                }
                $rolesList[] = $row;
            }
        }
    }
    return $rolesList;
}

/**
 * Obtiene la mapa de módulos autorizados para un rol específico
 */
function obtenerPermisosRol($rolId) {
    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) $con = obtenerConexionLIHO();
    }
    $permisosMap = array();
    $rolIdInt = intval($rolId);
    if ($con !== false && $rolIdInt >= 0) {
        $sql = "SELECT modulo_clave, permitido FROM permisos_roles WHERE rol_id = ?";
        $stmt = sqlsrv_query($con, $sql, array($rolIdInt));
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $permisosMap[$row['modulo_clave']] = ($row['permitido'] == 1);
            }
        }
    }
    // El dashboard (Panel de Inicio) siempre está activo para todos los roles
    $permisosMap['dashboard'] = true;
    return $permisosMap;
}

/**
 * Actualiza la matriz de permisos de un rol y lo registra en logs
 */
function guardarPermisosRol($rolId, array $modulosPermitidos, $usuarioId = 0, $usuarioNombre = '') {
    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) $con = obtenerConexionLIHO();
    }

    $rolIdInt = intval($rolId);
    if ($con !== false && $rolIdInt >= 0) {
        $modulosOriginales = obtenerModulosSistema();
        $modulosPermitidosNombres = array();

        // Asegurar que 'dashboard' siempre esté activo para todos los roles
        if (!in_array('dashboard', $modulosPermitidos)) {
            $modulosPermitidos[] = 'dashboard';
        }

        foreach ($modulosOriginales as $clave => $info) {
            $permitidoVal = ($clave === 'dashboard' || in_array($clave, $modulosPermitidos)) ? 1 : 0;
            if ($permitidoVal === 1) {
                $modulosPermitidosNombres[] = $info['nombre'];
            }

            $sqlCheck = "SELECT TOP 1 id FROM permisos_roles WHERE rol_id = ? AND modulo_clave = ?";
            $stmtCheck = sqlsrv_query($con, $sqlCheck, array($rolIdInt, $clave));

            if ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck)) {
                $sqlUpdate = "UPDATE permisos_roles SET permitido = ?, fecha_asignacion = GETDATE() WHERE rol_id = ? AND modulo_clave = ?";
                sqlsrv_query($con, $sqlUpdate, array($permitidoVal, $rolIdInt, $clave));
            } else {
                $sqlInsert = "INSERT INTO permisos_roles (rol_id, modulo_clave, permitido, fecha_asignacion) VALUES (?, ?, ?, GETDATE())";
                sqlsrv_query($con, $sqlInsert, array($rolIdInt, $clave, $permitidoVal));
            }
        }

        // Trazabilidad en Logs
        $listaTxt = !empty($modulosPermitidosNombres) ? implode(', ', $modulosPermitidosNombres) : 'Ningún módulo';
        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema(
                'ROLES_PERMISOS',
                'ACTUALIZACION_MATRIZ_ROL',
                'MODIFICACION',
                "Se actualizó la matriz de permisos por vista del rol #{$rolIdInt}. Vistas activas: {$listaTxt}",
                array(
                    'nivel' => 'INFO',
                    'entidad_afectada' => "Rol ID #{$rolIdInt}",
                    'valor_anterior' => "Matriz previa",
                    'valor_nuevo' => "Módulos habilitados: " . count($modulosPermitidos),
                    'usuario_id' => $usuarioId,
                    'usuario_nombre' => $usuarioNombre ?: 'Administrador'
                )
            );
        }

        if (function_exists('registrarLogAuditoriaUniversal')) {
            registrarLogAuditoriaUniversal(
                'ROLES_PERMISOS',
                $rolIdInt,
                "ROL-{$rolIdInt}",
                'ACTUALIZACION_MATRIZ_ROL',
                'Matriz Modificada',
                "Total Vistas: " . count($modulosPermitidos),
                $usuarioId,
                $usuarioNombre ?: 'Administrador',
                'ADMINISTRADOR',
                "Actualización de accesos por pantalla para el Rol #{$rolIdInt}",
                json_encode(array('rol_id' => $rolIdInt, 'modulos' => $modulosPermitidos))
            );
        }

        return true;
    }
    return false;
}

/**
 * Verifica si un usuario tiene permiso para acceder a un módulo específico.
 * Jerarquía:
 *  1. Panel de Inicio (dashboard) -> Siempre permitido para todos los usuarios autenticados.
 *  2. Admin (rol_id 1) -> Acceso total ilimitado.
 *  3. Permiso Especial de Usuario (permisos_usuarios) -> Prioridad máxima personalizada.
 *  4. Permiso por Rol (permisos_roles) -> Matriz asignada al rol (incluyendo Rol 0).
 *  5. Reglas por defecto según el perfil institucional.
 */
function tienePermisoModulo($usuarioId, $rolId, $moduloClave) {
    // 1. Panel de inicio (Dashboard) es la vista predeterminada para todos los usuarios
    if ($moduloClave === 'dashboard') {
        return true;
    }

    // 2. Administrador (rol_id 1 o 'Admin') tiene acceso total ilimitado
    if ($rolId == '1' || $rolId == 1 || strtolower((string)$rolId) === 'admin' || strtolower((string)$rolId) === 'administrador') {
        return true;
    }

    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) $con = obtenerConexionLIHO();
    }

    if ($con !== false) {
        // 3. Verificar Permiso Especial de Usuario (Prevalece sobre el rol)
        $sqlU = "SELECT TOP 1 permitido FROM permisos_usuarios WHERE usuario_id = ? AND modulo_clave = ?";
        $stmtU = sqlsrv_query($con, $sqlU, array(intval($usuarioId), $moduloClave));
        if ($stmtU !== false && $rowU = sqlsrv_fetch_array($stmtU, SQLSRV_FETCH_ASSOC)) {
            return ($rowU['permitido'] == 1 || $rowU['permitido'] === true);
        }

        // 4. Verificar Permiso Asignado al Rol (Incluso para Rol 0 "Sin rol")
        $rolIdInt = intval($rolId);
        if ($rolIdInt >= 0) {
            $sqlR = "SELECT TOP 1 permitido FROM permisos_roles WHERE rol_id = ? AND modulo_clave = ?";
            $stmtR = sqlsrv_query($con, $sqlR, array($rolIdInt, $moduloClave));
            if ($stmtR !== false && $rowR = sqlsrv_fetch_array($stmtR, SQLSRV_FETCH_ASSOC)) {
                return ($rowR['permitido'] == 1 || $rowR['permitido'] === true);
            }
        }
    }

    // 5. Reglas por defecto SOLO para roles conocidos si no existe registro en permisos_roles
    if ($rolId == '2' || $rolId == 2 || strtolower((string)$rolId) === 'financiero') {
        if ($moduloClave === 'liquidaciones') return true;
        if ($moduloClave === 'notas_ajuste') return true;
        if ($moduloClave === 'alerta_medicos') return true;
        if ($moduloClave === 'maestro_entidades') return true;
        if ($moduloClave === 'maestro_novedades') return true;
        if ($moduloClave === 'estadisticas') return true;
        if ($moduloClave === 'examenes_medicos') return true;
        if ($moduloClave === 'gestion_medicos_procedimientos') return true;
    }

    // Para Rol 0 ("Sin rol") o usuarios sin permisos otorgados, NO dar acceso a módulos asistenciales o financieros
    return false;
}

/**
 * Obtiene el mapa completo de permisos especiales de un usuario
 */
function obtenerPermisosUsuario($usuarioId) {
    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) $con = obtenerConexionLIHO();
    }

    $permisosMap = array();
    if ($con !== false) {
        $sql = "SELECT modulo_clave, permitido FROM permisos_usuarios WHERE usuario_id = ?";
        $stmt = sqlsrv_query($con, $sql, array(intval($usuarioId)));
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $permisosMap[$row['modulo_clave']] = ($row['permitido'] == 1);
            }
        }
    }
    return $permisosMap;
}

/**
 * Actualiza los permisos especiales de un usuario y los registra en la BD y Logs
 */
function guardarPermisosUsuario($usuarioId, array $modulosPermitidos, $operadorId = 0, $operadorNombre = '') {
    global $con;
    if (!isset($con) || $con === false) {
        if (function_exists('obtenerConexionLIHO')) $con = obtenerConexionLIHO();
    }

    $usuarioIdInt = intval($usuarioId);
    if ($con !== false && $usuarioIdInt > 0) {
        $modulosOriginales = obtenerModulosSistema();
        $nombresModulosHabilitados = array();

        foreach ($modulosOriginales as $clave => $info) {
            $permitidoVal = in_array($clave, $modulosPermitidos) ? 1 : 0;
            if ($permitidoVal === 1) {
                $nombresModulosHabilitados[] = $info['nombre'];
            }

            $sqlCheck = "SELECT TOP 1 id FROM permisos_usuarios WHERE usuario_id = ? AND modulo_clave = ?";
            $stmtCheck = sqlsrv_query($con, $sqlCheck, array($usuarioIdInt, $clave));

            if ($stmtCheck !== false && sqlsrv_has_rows($stmtCheck)) {
                $sqlUpdate = "UPDATE permisos_usuarios SET permitido = ?, fecha_asignacion = GETDATE() WHERE usuario_id = ? AND modulo_clave = ?";
                sqlsrv_query($con, $sqlUpdate, array($permitidoVal, $usuarioIdInt, $clave));
            } else {
                $sqlInsert = "INSERT INTO permisos_usuarios (usuario_id, modulo_clave, permitido, fecha_asignacion) VALUES (?, ?, ?, GETDATE())";
                sqlsrv_query($con, $sqlInsert, array($usuarioIdInt, $clave, $permitidoVal));
            }
        }

        // Trazabilidad en Logs
        $listaTxt = !empty($nombresModulosHabilitados) ? implode(', ', $nombresModulosHabilitados) : 'Ninguno';
        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema(
                'PERMISOS_USUARIOS',
                'PERMISOS_ESPECIALES_USUARIO',
                'MODIFICACION',
                'INFO',
                "Usuario ID #{$usuarioIdInt}",
                "Configuración previa",
                "Especiales: " . count($modulosPermitidos),
                "Se asignaron permisos especiales al Usuario #{$usuarioIdInt}. Módulos habilitados: {$listaTxt}"
            );
        }

        if (function_exists('registrarLogAuditoriaUniversal')) {
            registrarLogAuditoriaUniversal(
                'PERMISOS_USUARIOS',
                $usuarioIdInt,
                "USER-{$usuarioIdInt}",
                'PERMISOS_ESPECIALES_USUARIO',
                'Permisos Modificados',
                "Total Especiales: " . count($modulosPermitidos),
                $operadorId,
                $operadorNombre ?: 'Administrador',
                'ADMINISTRADOR',
                "Asignación de excepciones / permisos especiales para el Usuario #{$usuarioIdInt}",
                json_encode(array('usuario_id' => $usuarioIdInt, 'modulos' => $modulosPermitidos))
            );
        }

        return true;
    }
    return false;
}
?>

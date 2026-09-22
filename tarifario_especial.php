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

// Control estricto de acceso a Tarifas Especiales
if ($userRole !== 'ADMINISTRADOR' && $userRoleIdVal !== 1 && !tienePermisoModulo($userId, $userRoleIdVal, 'tarifario_especial')) {
    header("Location: dashboard.php?error=no_permission");
    exit;
}

// Permisos para editar (Administradores [rol_id 1] y Financiera [rol_id 2])
$canEditTarifa = ($userRoleIdVal === 1 || $userRoleIdVal === 2) || in_array($userRole, ['ADMINISTRADOR', 'ADMIN', 'FINANCIERA', 'FINANCIERO']);

// 1. AJAX: CREAR NUEVO EXAMEN ESPECIAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_tarifa_especial') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para registrar exámenes especiales.']);
        exit;
    }

    $codigo           = strtoupper(trim($_POST['codigo'] ?? ''));
    $tipo     = strtoupper(trim($_POST['tipo'] ?? 'ECOG'));
    $estudio  = strtoupper(trim($_POST['estudio'] ?? ''));
    $tipoPago = strtoupper(trim($_POST['tipo_pago'] ?? ''));

    if (empty($tipoPago) || $tipoPago === 'TARIFA_ESTANDAR') {
        $esTarifaEspecial = isset($_POST['es_tarifa_especial']) ? (int)$_POST['es_tarifa_especial'] : 0;
        $esDeglucion      = isset($_POST['es_deglucion']) ? (int)$_POST['es_deglucion'] : 0;
        if ($esTarifaEspecial === 1 && $esDeglucion === 1) $esDeglucion = 0;
        $tipoPago = 'TARIFA_ESTANDAR';
        if ($esTarifaEspecial === 1) $tipoPago = 'TARIFAS_ESPECIALES';
        elseif ($esDeglucion === 1) $tipoPago = 'DEGLUCIONES';
    } else {
        $esTarifaEspecial = ($tipoPago === 'TARIFAS_ESPECIALES') ? 1 : 0;
        $esDeglucion      = ($tipoPago === 'DEGLUCIONES') ? 1 : 0;
    }

    if (empty($codigo) || empty($estudio)) {
        echo json_encode(['success' => false, 'message' => 'El código CUPS y el nombre del estudio son obligatorios.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos SQL Server.']);
        exit;
    }

    // Verificar si el código ya existe
    $stmtChk = sqlsrv_query($con, "SELECT id FROM tarifario_especial WHERE codigo = ?", array($codigo));
    if ($stmtChk !== false && sqlsrv_has_rows($stmtChk)) {
        echo json_encode(['success' => false, 'message' => "El código CUPS '$codigo' ya se encuentra registrado en el maestro especial."]);
        exit;
    }

    $sqlIns = "INSERT INTO tarifario_especial (codigo, tipo, estudio, es_tarifa_especial, es_deglucion, tipo_pago, estado, fecha_creacion, fecha_actualizacion, usuario_id) 
               VALUES (?, ?, ?, ?, ?, ?, 1, GETDATE(), GETDATE(), ?); 
               SELECT SCOPE_IDENTITY() AS new_id;";
    
    $stmtIns = sqlsrv_query($con, $sqlIns, array($codigo, $tipo, $estudio, $esTarifaEspecial, $esDeglucion, $tipoPago, $userId));

    if ($stmtIns === false) {
        echo json_encode(['success' => false, 'message' => 'Error al registrar el examen: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    sqlsrv_next_result($stmtIns);
    $rowId = sqlsrv_fetch_array($stmtIns, SQLSRV_FETCH_ASSOC);
    $newId = $rowId['new_id'] ?? 0;

    // Registrar en Historial de Auditoría
    require_once(__DIR__ . '/includes/audit_logger.php');
    registrarAuditoriaTarifario(
        $con,
        $newId,
        $codigo,
        $estudio,
        $userId,
        $userName,
        $userEmail,
        $userRole,
        'NUEVO_EXAMEN_ESPECIAL',
        'N/A',
        "CUPS: {$codigo} | Especial: {$esTarifaEspecial} | Deglución: {$esDeglucion}",
        'Creación de examen en maestro de tarifas especiales',
        'CREACION',
        'TARIFARIO_ESPECIAL'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Examen registrado exitosamente en el maestro.',
        'data' => [
            'id' => $newId,
            'codigo' => $codigo,
            'tipo' => $tipo,
            'estudio' => $estudio,
            'es_tarifa_especial' => $esTarifaEspecial,
            'es_deglucion' => $esDeglucion,
            'estado' => 1
        ]
    ]);
    exit;
}

// 2. AJAX: GUARDAR / EDITAR EXAMEN ESPECIAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_tarifa_especial') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar el maestro.']);
        exit;
    }

    $id               = (int)($_POST['id'] ?? 0);
    $codigo           = strtoupper(trim($_POST['codigo'] ?? ''));
    $tipo             = strtoupper(trim($_POST['tipo'] ?? 'ECOG'));
    $estudio  = strtoupper(trim($_POST['estudio'] ?? ''));
    $tipoPago = strtoupper(trim($_POST['tipo_pago'] ?? ''));
    $estado   = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;

    if (empty($tipoPago) || $tipoPago === 'TARIFA_ESTANDAR') {
        $esTarifaEspecial = isset($_POST['es_tarifa_especial']) ? (int)$_POST['es_tarifa_especial'] : 0;
        $esDeglucion      = isset($_POST['es_deglucion']) ? (int)$_POST['es_deglucion'] : 0;
        if ($esTarifaEspecial === 1 && $esDeglucion === 1) $esDeglucion = 0;
        $tipoPago = 'TARIFA_ESTANDAR';
        if ($esTarifaEspecial === 1) $tipoPago = 'TARIFAS_ESPECIALES';
        elseif ($esDeglucion === 1) $tipoPago = 'DEGLUCIONES';
    } else {
        $esTarifaEspecial = ($tipoPago === 'TARIFAS_ESPECIALES') ? 1 : 0;
        $esDeglucion      = ($tipoPago === 'DEGLUCIONES') ? 1 : 0;
    }

    if ($id <= 0 || empty($codigo) || empty($estudio)) {
        echo json_encode(['success' => false, 'message' => 'Parámetros incompletos o inválidos.']);
        exit;
    }

    if (!isset($con) || $con === false) {
        echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
        exit;
    }

    // Consultar registro previo para registrar diferencias en auditoría
    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, tipo, estudio, es_tarifa_especial, es_deglucion, tipo_pago, estado FROM tarifario_especial WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    $sqlUpd = "UPDATE tarifario_especial 
               SET codigo = ?, tipo = ?, estudio = ?, es_tarifa_especial = ?, es_deglucion = ?, tipo_pago = ?, estado = ?, 
                   fecha_actualizacion = GETDATE(), usuario_id = ? 
               WHERE id = ?";

    $stmtUpd = sqlsrv_query($con, $sqlUpd, array($codigo, $tipo, $estudio, $esTarifaEspecial, $esDeglucion, $tipoPago, $estado, $userId, $id));

    if ($stmtUpd === false) {
        echo json_encode(['success' => false, 'message' => 'Error actualizando los datos del examen: ' . print_r(sqlsrv_errors(), true)]);
        exit;
    }

    // Registrar cambios en Auditoría
    if ($curr) {
        require_once(__DIR__ . '/includes/audit_logger.php');
        $oldCodigo   = (string)$curr['codigo'];
        $oldTipo     = (string)$curr['tipo'];
        $oldEstudio  = (string)$curr['estudio'];
        $oldEsp      = (int)($curr['es_tarifa_especial'] ?? 0);
        $oldDeg      = (int)($curr['es_deglucion'] ?? 0);
        $oldEstado   = (int)$curr['estado'];

        if ($oldCodigo !== $codigo) {
            registrarAuditoriaTarifario($con, $id, $codigo, $estudio, $userId, $userName, $userEmail, $userRole, 'CODIGO_CUPS', $oldCodigo, $codigo, 'Modificación de código CUPS', 'EDICION', 'TARIFARIO_ESPECIAL');
        }
        if ($oldEsp !== $esTarifaEspecial || $oldDeg !== $esDeglucion) {
            registrarAuditoriaTarifario($con, $id, $codigo, $estudio, $userId, $userName, $userEmail, $userRole, 'MODALIDADES_CHECK', "Esp: {$oldEsp} | Deg: {$oldDeg}", "Esp: {$esTarifaEspecial} | Deg: {$esDeglucion}", 'Modificación de modalidades de pago', 'EDICION', 'TARIFARIO_ESPECIAL');
        }
        if ($oldEstado !== $estado) {
            $act = ($estado === 1) ? 'ACTIVACION' : 'DESACTIVACION';
            registrarAuditoriaTarifario($con, $id, $codigo, $estudio, $userId, $userName, $userEmail, $userRole, 'ESTADO', ($oldEstado === 1 ? 'ACTIVO' : 'INACTIVO'), ($estado === 1 ? 'ACTIVO' : 'INACTIVO'), 'Cambio de estado en edición', $act, 'TARIFARIO_ESPECIAL');
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Examen actualizado correctamente.',
        'data' => [
            'id' => $id,
            'codigo' => $codigo,
            'tipo' => $tipo,
            'estudio' => $estudio,
            'es_tarifa_especial' => $esTarifaEspecial,
            'es_deglucion' => $esDeglucion,
            'estado' => $estado
        ]
    ]);
    exit;
}

// 3. AJAX: TOGGLE RÁPIDO DE CHECKBOX (EXCLUSIVIDAD MUTUA)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_checkbox_modalidad') {
    header('Content-Type: application/json');

    if (!$canEditTarifa) {
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar modalidades.']);
        exit;
    }

    $id    = (int)($_POST['id'] ?? 0);
    $campo = trim($_POST['campo'] ?? ''); // 'es_tarifa_especial' o 'es_deglucion'
    $valor = (int)($_POST['valor'] ?? 0);

    if ($id <= 0 || !in_array($campo, ['es_tarifa_especial', 'es_deglucion'])) {
        echo json_encode(['success' => false, 'message' => 'Parámetros inválidos.']);
        exit;
    }

    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, estudio, es_tarifa_especial, es_deglucion FROM tarifario_especial WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    // Aplicar exclusividad mutua: Si uno se activa (1), el otro se desactiva (0)
    $nuevoEsp = ($campo === 'es_tarifa_especial') ? $valor : ($valor === 1 ? 0 : (int)($curr['es_tarifa_especial'] ?? 0));
    $nuevoDeg = ($campo === 'es_deglucion') ? $valor : ($valor === 1 ? 0 : (int)($curr['es_deglucion'] ?? 0));

    $tipoPago = 'TARIFA_ESTANDAR';
    if ($nuevoEsp === 1) $tipoPago = 'TARIFAS_ESPECIALES';
    elseif ($nuevoDeg === 1) $tipoPago = 'DEGLUCIONES';

    $sqlToggle = "UPDATE tarifario_especial 
                  SET es_tarifa_especial = ?, es_deglucion = ?, tipo_pago = ?, fecha_actualizacion = GETDATE(), usuario_id = ? 
                  WHERE id = ?";
    $stmtToggle = sqlsrv_query($con, $sqlToggle, array($nuevoEsp, $nuevoDeg, $tipoPago, $userId, $id));

    if ($stmtToggle === false) {
        echo json_encode(['success' => false, 'message' => 'Error al actualizar modalidad.']);
        exit;
    }

    if ($curr) {
        require_once(__DIR__ . '/includes/audit_logger.php');
        $campoNombre = ($campo === 'es_tarifa_especial') ? 'Tarifas Especiales' : 'Degluciones';
        $estadoStr   = ($valor === 1) ? 'Marcado (Activo)' : 'Desmarcado (Inactivo)';
        registrarAuditoriaTarifario(
            $con,
            $id,
            $curr['codigo'],
            $curr['estudio'],
            $userId,
            $userName,
            $userEmail,
            $userRole,
            strtoupper($campo),
            ((int)($curr[$campo] ?? 0) === 1 ? 'ACTIVO' : 'INACTIVO'),
            ($valor === 1 ? 'ACTIVO' : 'INACTIVO'),
            "Actualización de checkbox {$campoNombre}: {$estadoStr}",
            'EDICION',
            'TARIFARIO_ESPECIAL'
        );
    }

    echo json_encode([
        'success'            => true,
        'message'            => 'Modalidad actualizada.',
        'es_tarifa_especial' => $nuevoEsp,
        'es_deglucion'       => $nuevoDeg
    ]);
    exit;
}

// 4. AJAX: TOGGLE ESTADO / ACTIVAR / DESACTIVAR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_estado_especial') {
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

    $stmtCurr = sqlsrv_query($con, "SELECT id, codigo, tipo, estudio, estado FROM tarifario_especial WHERE id = ?", array($id));
    $curr = ($stmtCurr !== false) ? sqlsrv_fetch_array($stmtCurr, SQLSRV_FETCH_ASSOC) : null;

    $stmt = sqlsrv_query($con, "UPDATE tarifario_especial SET estado = ?, fecha_actualizacion = GETDATE(), usuario_id = ? WHERE id = ?", array($nuevoEstado, $userId, $id));
    if ($stmt === false) {
        echo json_encode(['success' => false, 'message' => 'Error al cambiar estado.']);
        exit;
    }

    if ($curr) {
        require_once(__DIR__ . '/includes/audit_logger.php');
        $codigo     = $curr['codigo'];
        $estudio    = $curr['estudio'];
        $oldEstado  = (int)$curr['estado'];
        $tipoAccion = ($nuevoEstado === 1) ? 'ACTIVACION' : 'DESACTIVACION';
        $motivo     = ($nuevoEstado === 1) ? 'Habilitación de examen' : 'Inactivación de examen en el maestro';

        registrarAuditoriaTarifario(
            $con,
            $id,
            $codigo,
            $estudio,
            $userId,
            $userName,
            $userEmail,
            $userRole,
            'ESTADO',
            ($oldEstado === 1 ? 'ACTIVO' : 'INACTIVO'),
            ($nuevoEstado === 1 ? 'ACTIVO' : 'INACTIVO'),
            $motivo,
            $tipoAccion,
            'TARIFARIO_ESPECIAL'
        );
    }

    echo json_encode([
        'success' => true, 
        'message' => ($nuevoEstado === 1) ? 'Examen habilitado exitosamente.' : 'Examen desactivado correctamente.', 
        'nuevo_estado' => $nuevoEstado
    ]);
    exit;
}

// 5. EXPORTAR A CSV
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=maestro_tarifas_especiales_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['CUPS', 'TIPO', 'ESTUDIO', 'TARIFAS ESPECIALES', 'DEGLUCIONES', 'ESTADO'], ';');

    if (isset($con) && $con !== false) {
        $sqlEx = "SELECT codigo, tipo, estudio, 
                         ISNULL(es_tarifa_especial, 0) AS es_tarifa_especial, 
                         ISNULL(es_deglucion, 0) AS es_deglucion, 
                         estado 
                  FROM tarifario_especial 
                  ORDER BY id ASC";
        $stmtEx = sqlsrv_query($con, $sqlEx);
        if ($stmtEx !== false) {
            while ($r = sqlsrv_fetch_array($stmtEx, SQLSRV_FETCH_ASSOC)) {
                fputcsv($output, [
                    $r['codigo'],
                    $r['tipo'],
                    $r['estudio'],
                    ((int)$r['es_tarifa_especial'] === 1) ? 'SI' : 'NO',
                    ((int)$r['es_deglucion'] === 1) ? 'SI' : 'NO',
                    ((int)$r['estado'] === 1) ? 'ACTIVO' : 'INACTIVO'
                ], ';');
            }
        }
    }
    fclose($output);
    exit;
}

// Helper para clases de colores de badges
if (!function_exists('obtenerEstiloBadgeColor')) {
    function obtenerEstiloBadgeColor($color) {
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

// 6. CONSULTAR LISTADO DE MODALIDADES ACTIVAS
$modalidadesList = [];
$modalidadesMap  = [];
if (isset($con) && $con !== false) {
    $sqlM = "SELECT id, tipo, ISNULL(nombre, tipo) AS nombre, ISNULL(color, 'emerald') AS color, porcentaje, ISNULL(estado, 1) AS estado FROM maestro_porcentajes_pago WHERE ISNULL(estado, 1) = 1 ORDER BY id ASC";
    $stmtM = sqlsrv_query($con, $sqlM);
    if ($stmtM !== false) {
        while ($rM = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC)) {
            $rM['id'] = (int)$rM['id'];
            $rM['porcentaje'] = (float)$rM['porcentaje'];
            $modalidadesList[] = $rM;
            $modalidadesMap[$rM['tipo']] = $rM;
        }
    }
}

// 7. CONSULTAR LISTADO DE EXÁMENES ESPECIALES
$examenesEspeciales = [];
$tiposDisponibles   = [];
$totalActivas       = 0;
$totalInactivas     = 0;
$totalConModalidad  = 0;
$conteoPorModalidad = [];
$conteoActivosPorModalidad = [];

foreach ($modalidadesList as $m) {
    $conteoPorModalidad[$m['tipo']] = 0;
    $conteoActivosPorModalidad[$m['tipo']] = 0;
}

if (isset($con) && $con !== false) {
    $sqlSel = "SELECT id, codigo, tipo, estudio, 
                      ISNULL(es_tarifa_especial, 0) AS es_tarifa_especial, 
                      ISNULL(es_deglucion, 0) AS es_deglucion, 
                      ISNULL(tipo_pago, 'TARIFAS_ESPECIALES') AS tipo_pago,
                      ISNULL(estado, 1) AS estado 
               FROM tarifario_especial 
               WHERE (vigencia_hasta IS NULL)
               ORDER BY id ASC";
    $stmt = sqlsrv_query($con, $sqlSel);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['id']                 = (int)$row['id'];
            $row['estado']             = (int)$row['estado'];
            $row['es_tarifa_especial'] = (int)$row['es_tarifa_especial'];
            $row['es_deglucion']       = (int)$row['es_deglucion'];

            $mTipo = strtoupper(trim($row['tipo_pago'] ?? ''));
            if (empty($mTipo) || $mTipo === 'TARIFA_ESTANDAR') {
                if ($row['es_deglucion'] === 1) $mTipo = 'DEGLUCIONES';
                else $mTipo = 'TARIFAS_ESPECIALES';
            }
            $row['tipo_pago'] = $mTipo;

            $examenesEspeciales[] = $row;

            if (isset($conteoPorModalidad[$mTipo])) {
                $conteoPorModalidad[$mTipo]++;
                if ($row['estado'] === 1) {
                    $conteoActivosPorModalidad[$mTipo]++;
                }
            } else {
                $conteoPorModalidad[$mTipo] = 1;
                $conteoActivosPorModalidad[$mTipo] = ($row['estado'] === 1 ? 1 : 0);
            }

            if ($row['estado'] === 1) {
                $totalActivas++;
                $totalConModalidad++;
            } else {
                $totalInactivas++;
            }

            if (!empty($row['tipo']) && !in_array($row['tipo'], $tiposDisponibles)) {
                $tiposDisponibles[] = $row['tipo'];
            }
        }
    }
}

// 8. RESOLVER ENTIDADES Y PALETA (Sincronizado con tarifario.php y maestro_porcentajes.php)
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
            ];

        default:
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
            ];
    }
}
}

$entidadesList = [];
$entidadesExternas = [];
$hoId = 4;

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
$entidadActivaKey = isset($_GET['entidad_id']) ? trim($_GET['entidad_id']) : ($_SESSION['tarifario_entidad_id'] ?? $_SESSION['entidad_liquidacion_id'] ?? 'PROPIO');
if ($entidadActivaKey === (string)$hoId) {
    $entidadActivaKey = 'PROPIO';
}
if (!isset($entidadesList[$entidadActivaKey])) {
    $entidadActivaKey = 'PROPIO';
}
$_SESSION['tarifario_entidad_id'] = $entidadActivaKey;
$entidadActiva = $entidadesList[$entidadActivaKey];
$paletaActiva  = obtenerPaletaEntidad($entidadActiva['color_tema'], $entidadActiva['id']);
?>
<!DOCTYPE html>
<html class="light" lang="es">

<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title>Maestro de Tarifas Especiales | LIHO</title>

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

        <!-- Encabezado y Pestañas de Navegación -->
        <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
            <div>
                <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-slate-200/80 dark:bg-slate-800 text-xs font-bold uppercase tracking-wider mb-2 border border-slate-300/60 dark:border-slate-700/60">
                    <span class="material-symbols-outlined text-sm text-purple-600 dark:text-purple-400">stars</span>
                    <span class="text-slate-600 dark:text-slate-300">Maestro de Cruce para Tarifas Especiales</span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight font-outfit">
                    Estudios Especiales Autorizados
                </h1>
                <p class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-1">
                    Gestión de exámenes con modalidades de liquidación especial asignadas
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
                       class="px-4 py-2 rounded-xl text-xs font-extrabold bg-purple-600 text-white shadow-sm transition-all flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-white">star</span>
                        <span>Tarifas Especiales</span>
                    </a>
                    <a href="maestro_porcentajes.php?entidad_id=<?php echo urlencode($entidadActivaKey); ?>" 
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

                    <a href="tarifario_especial.php?action=export_csv" 
                       class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-md shadow-emerald-600/20 transition-all hover:scale-105 active:scale-95">
                        <span class="material-symbols-outlined text-base">download</span>
                        <span>Exportar a Excel / CSV</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Banner Principal de la Entidad Activa Seleccionada -->
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
                        <?php elseif (!empty($entidadActiva['logo']) && file_exists(__DIR__ . '/' . $entidadActiva['logo'])): ?>
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
                                <span class="material-symbols-outlined text-[13px] text-purple-500">stars</span>
                                <span><?php echo count($examenesEspeciales); ?> estudios en catálogo especial</span>
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
                                <span>El maestro de cruce especial regula las modalidades de liquidación para esta entidad.</span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Botones de Acción para Cambiar Entidad y Registrar Nuevo Examen -->
                <div class="flex flex-wrap items-center gap-3 self-stretch sm:self-auto justify-start lg:justify-end shrink-0 pt-3 lg:pt-0 border-t lg:border-t-0 border-slate-200/60 dark:border-slate-800">
                    <!-- Botón Destacado: Cambiar Entidad -->
                    <button type="button" onclick="abrirModalSeleccionarEntidad()"
                            class="px-4 sm:px-5 py-2.5 sm:py-3 rounded-2xl <?php echo $paletaActiva['btn_cambiar']; ?> font-extrabold text-xs transition-all duration-200 cursor-pointer inline-flex items-center gap-2.5 group hover:scale-[1.02] active:scale-[0.98] shrink-0"
                            title="Seleccionar otra entidad para configurar tarifas">
                        <span class="material-symbols-outlined text-xl transition-transform duration-300 group-hover:rotate-180 <?php echo $paletaActiva['icon_color']; ?>">swap_horiz</span>
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
                    <!-- Registrar Nuevo Examen Especial -->
                    <button type="button" onclick="abrirModalCrear()" 
                            class="px-4 sm:px-5 py-2.5 sm:py-3 rounded-2xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-extrabold text-xs shadow-md shadow-purple-600/20 hover:shadow-lg transition-all cursor-pointer inline-flex items-center gap-2 hover:scale-[1.02] active:scale-[0.98] shrink-0">
                        <span class="material-symbols-outlined text-base">add_circle</span>
                        <span>+ Nuevo Examen</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tarjetas de Métricas Rápidas (KPIs Dinámicos por Cada Modalidad) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-<?php echo min(6, count($modalidadesList) + 2); ?> gap-4 mb-8">
            <!-- Total Exámenes -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-slate-400 dark:text-slate-500 tracking-wider">Total Exámenes</p>
                    <h3 class="text-2xl font-black text-primary dark:text-white font-outfit mt-1"><?php echo count($examenesEspeciales); ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">Estudios en el maestro</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">receipt_long</span>
                </div>
            </div>

            <!-- Contadores Dinámicos por Cada Modalidad -->
            <?php foreach ($modalidadesList as $m): ?>
                <?php 
                    $mTipo = $m['tipo'];
                    $mNom  = $m['nombre'];
                    $mPct  = (float)$m['porcentaje'];
                    $mColor = $m['color'] ?? 'purple';
                    $cantTotal = $conteoPorModalidad[$mTipo] ?? 0;
                    $cantAct   = $conteoActivosPorModalidad[$mTipo] ?? 0;
                    
                    $styleInline = obtenerEstiloBadgeInline($mColor);
                    $badgeCls    = empty($styleInline) ? obtenerEstiloBadgeColor($mColor) : '';
                ?>
                <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                    <div>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <p class="text-[10px] font-extrabold uppercase text-slate-600 dark:text-slate-300 tracking-wider">
                                <?php echo htmlspecialchars($mNom); ?>
                            </p>
                            <span class="px-1.5 py-0.5 rounded text-[9px] font-black border <?php echo $badgeCls; ?>" <?php echo $styleInline; ?>>
                                <?php echo $mPct; ?>%
                            </span>
                        </div>
                        <h3 class="text-2xl font-black text-primary dark:text-white font-outfit mt-1" id="kpi_mod_<?php echo htmlspecialchars($mTipo); ?>">
                            <?php echo $cantTotal; ?>
                        </h3>
                        <p class="text-[11px] text-slate-400 mt-0.5">
                            <?php echo $cantAct; ?> activos
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center border <?php echo $badgeCls; ?>" <?php echo $styleInline; ?>>
                        <span class="material-symbols-outlined text-2xl">stars</span>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Exámenes Habilitados -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-200/80 dark:border-slate-800 shadow-xs flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-extrabold uppercase text-emerald-600 dark:text-emerald-400 tracking-wider">Habilitados</p>
                    <h3 class="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-outfit mt-1" id="kpiHabilitados"><?php echo $totalActivas; ?></h3>
                    <p class="text-[11px] text-slate-400 mt-0.5"><?php echo $totalInactivas; ?> inactivos</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                    <span class="material-symbols-outlined text-2xl">check_circle</span>
                </div>
            </div>
        </div>

        <!-- Barra de Búsqueda y Filtros -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200/80 dark:border-slate-800 shadow-xs mb-6 flex flex-col md:flex-row gap-4 justify-between items-center">
            
            <!-- Input Búsqueda en Vivo -->
            <div class="relative w-full md:w-96">
                <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg pointer-events-none">
                    search
                </span>
                <input type="text" id="searchInput" placeholder="Buscar por CUPS o Nombre del Estudio..."
                    class="w-full bg-slate-50 dark:bg-slate-800 pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-medium text-primary dark:text-slate-100 placeholder:text-slate-400 focus:bg-white dark:focus:bg-slate-800 focus:ring-2 focus:ring-purple-500/30 focus:border-purple-500 outline-none transition-all" />
            </div>

            <!-- Filtros de Modalidad y Estado -->
            <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                <!-- Filtro Modalidad -->
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] font-bold text-slate-400">Modalidad:</span>
                    <select id="filtroModalidad" onchange="aplicarFiltros()" 
                            class="bg-slate-50 dark:bg-slate-800 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-purple-500/30 outline-none">
                        <option value="TODOS">Todas las Modalidades</option>
                        <?php foreach ($modalidadesList as $m): ?>
                            <option value="<?php echo htmlspecialchars($m['tipo']); ?>"><?php echo htmlspecialchars($m['nombre']); ?> (<?php echo (float)$m['porcentaje']; ?>%)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filtro Estado -->
                <div class="flex items-center gap-1.5">
                    <span class="text-[11px] font-bold text-slate-400">Estado:</span>
                    <select id="filtroEstado" onchange="aplicarFiltros()" 
                            class="bg-slate-50 dark:bg-slate-800 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-purple-500/30 outline-none">
                        <option value="TODOS">Todos los Estados</option>
                        <option value="1">Activos</option>
                        <option value="0">Inactivos</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Tabla Principal con Modalidad Asignada -->
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200/80 dark:border-slate-800 shadow-sm overflow-hidden mb-8">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="tablaEspeciales">
                    <thead>
                        <tr class="bg-slate-50/90 dark:bg-slate-800/90 border-b border-slate-200 dark:border-slate-700 text-[11px] font-extrabold text-slate-400 dark:text-slate-300 uppercase tracking-wider">
                            <th class="py-4 px-6 w-32 whitespace-nowrap">CUPS</th>
                            <th class="py-4 px-3 w-20 text-center whitespace-nowrap">Tipo</th>
                            <th class="py-4 px-6 min-w-[280px]">Estudio / Examen</th>
                            <th class="py-4 px-6 text-center whitespace-nowrap text-primary dark:text-slate-200 font-extrabold w-48">
                                Modalidad Asignada
                            </th>
                            <th class="py-4 px-4 text-center w-28 whitespace-nowrap">Estado</th>
                            <?php if ($canEditTarifa): ?>
                            <th class="py-4 px-6 text-center w-32 whitespace-nowrap">Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                        <?php if (empty($examenesEspeciales)): ?>
                            <tr>
                                <td colspan="<?php echo $canEditTarifa ? 6 : 5; ?>" class="text-center py-12 text-slate-400 font-medium">
                                    <span class="material-symbols-outlined text-4xl mb-2 text-slate-300">receipt_long</span>
                                    <p>No hay exámenes registrados en el maestro.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($examenesEspeciales as $t): ?>
                                <?php 
                                    $isActivo  = ($t['estado'] === 1);
                                    $isEsp     = ($t['es_tarifa_especial'] === 1);
                                    $isDeg     = ($t['es_deglucion'] === 1);

                                    $tipoBadgeColor = 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
                                    if ($t['tipo'] === 'DOPP') {
                                        $tipoBadgeColor = 'bg-blue-100 text-blue-800 dark:bg-blue-950/80 dark:text-blue-300 border border-blue-200 dark:border-blue-800';
                                    } elseif ($t['tipo'] === 'ECOG') {
                                        $tipoBadgeColor = 'bg-teal-100 text-teal-800 dark:bg-teal-950/80 dark:text-teal-300 border border-teal-200 dark:border-teal-800';
                                    } elseif ($t['tipo'] === 'BIOP') {
                                        $tipoBadgeColor = 'bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 border border-rose-200 dark:border-rose-800';
                                    }
                                ?>
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors fila-especial border-b border-slate-100 dark:border-slate-800/60" 
                                    data-id="<?php echo $t['id']; ?>"
                                    data-cups="<?php echo htmlspecialchars($t['codigo']); ?>"
                                    data-tipo="<?php echo htmlspecialchars($t['tipo']); ?>"
                                    data-mod="<?php echo htmlspecialchars($t['tipo_pago']); ?>"
                                    data-estado="<?php echo $t['estado']; ?>">
                                    
                                    <!-- 1. CUPS -->
                                    <td class="py-4 px-6 font-mono font-black text-slate-900 dark:text-white whitespace-nowrap">
                                        <span class="px-3 py-1.5 rounded-xl bg-purple-50 dark:bg-purple-950/80 text-purple-700 dark:text-purple-300 border border-purple-200/80 dark:border-purple-800 font-extrabold text-xs tracking-wide shadow-2xs">
                                            <?php echo htmlspecialchars($t['codigo']); ?>
                                        </span>
                                    </td>

                                    <!-- 2. Tipo -->
                                    <td class="py-4 px-3 text-center whitespace-nowrap">
                                        <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-lg text-[10px] font-black whitespace-nowrap <?php echo $tipoBadgeColor; ?>">
                                            <?php echo htmlspecialchars($t['tipo']); ?>
                                        </span>
                                    </td>

                                    <!-- 3. Estudio -->
                                    <td class="py-4 px-6">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <p class="font-bold text-slate-800 dark:text-slate-200 text-xs md:text-sm leading-snug">
                                                <?php echo htmlspecialchars($t['estudio']); ?>
                                            </p>
                                            <?php 
                                                $tieneProyeccion = preg_match('/\b(a\s*\.?\s*p\.?|p\s*\.?\s*a\.?)\b/i', $t['estudio']) || preg_match('/a\.p/i', $t['estudio']) || preg_match('/p\.a/i', $t['estudio']);
                                                $tieneLateral    = preg_match('/\blateral(?:es)?\b/i', $t['estudio']) || preg_match('/\blat\b/i', $t['estudio']);
                                                $isCompOrAp = preg_match('/\bcomparativ[a-záéíóúñ\/]*/i', $t['estudio']) || ($tieneProyeccion && $tieneLateral);
                                                if ($isCompOrAp): 
                                            ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[9px] font-black uppercase bg-indigo-100 text-indigo-800 dark:bg-indigo-900/80 dark:text-indigo-200 border border-indigo-300 dark:border-indigo-700 shadow-xs">
                                                    <span class="material-symbols-outlined text-[10px]">compare_arrows</span> COMPARATIVO
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <!-- 4. Modalidad Asignada -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <?php 
                                            $modInfo = $modalidadesMap[$t['tipo_pago']] ?? null;
                                            $modNom  = $modInfo ? $modInfo['nombre'] : $t['tipo_pago'];
                                            $modPct  = $modInfo ? (float)$modInfo['porcentaje'] : null;
                                            $modCol  = $modInfo['color'] ?? 'purple';
                                            $styleAttr = obtenerEstiloBadgeInline($modCol);
                                            $clsBadge  = empty($styleAttr) ? obtenerEstiloBadgeColor($modCol) : '';
                                        ?>
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-black uppercase border shadow-2xs <?php echo $clsBadge; ?>" <?php echo $styleAttr; ?>>
                                            <span class="material-symbols-outlined text-sm">percent</span>
                                            <span><?php echo htmlspecialchars($modNom); ?></span>
                                            <?php if ($modPct !== null): ?>
                                                <span class="opacity-80 text-[11px] font-bold">(<?php echo $modPct; ?>%)</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>

                                    <!-- 5. Estado -->
                                    <td class="py-4 px-4 text-center whitespace-nowrap">
                                        <?php if ($isActivo): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300 text-[10px] font-extrabold uppercase">
                                                Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-rose-100 text-rose-800 dark:bg-rose-950/80 dark:text-rose-300 text-[10px] font-extrabold uppercase">
                                                Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <?php if ($canEditTarifa): ?>
                                    <!-- 6. Acciones -->
                                    <td class="py-4 px-6 text-center whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-2">
                                            <!-- Editar -->
                                            <button type="button" title="Editar examen" 
                                                onclick="abrirModalEditar(<?php echo htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8'); ?>)"
                                                class="p-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900 border border-amber-200 dark:border-amber-800 transition-colors cursor-pointer">
                                                <span class="material-symbols-outlined text-base">edit</span>
                                            </button>

                                            <!-- Toggle Estado (Desactivar / Activar) -->
                                            <button type="button" title="<?php echo $isActivo ? 'Desactivar examen' : 'Activar examen'; ?>"
                                                onclick="toggleEstado(<?php echo $t['id']; ?>, <?php echo $isActivo ? 0 : 1; ?>)"
                                                class="p-2 rounded-xl <?php echo $isActivo ? 'bg-rose-50 text-rose-600 hover:bg-rose-100 dark:bg-rose-950/60 dark:text-rose-400 border border-rose-200 dark:border-rose-800' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100 dark:bg-emerald-950/60 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800'; ?> transition-colors cursor-pointer">
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

    <!-- Modal Registrar Nuevo Examen Especial -->
    <div id="modalCrear" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full p-8 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300 animate-card-entry">
            
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-purple-50 dark:bg-purple-950/80 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">star</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Nuevo Examen</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500">Agregue un estudio y seleccione su modalidad</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalCrear()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formCrear" onsubmit="guardarNuevoExamen(event)" class="space-y-4 text-xs">
                
                <!-- CUPS & Tipo -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Código CUPS *</label>
                        <input type="text" id="add_codigo" required placeholder="Ej: B82102" 
                               class="w-full uppercase font-mono bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-purple-500/30 outline-none" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Tipo *</label>
                        <input type="text" id="add_tipo" required placeholder="Ej: DOPP, ECOG, BIOP" 
                               class="w-full uppercase bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-purple-500/30 outline-none" />
                    </div>
                </div>

                <!-- Nombre del Estudio -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Nombre del Estudio / Examen *</label>
                    <textarea id="add_estudio" required rows="3" placeholder="Ej: TRIPLEX TRANSVAGINAL DE VASOS PELVICOS A COLOR (ECOGRAFÍA DE VULVA)" 
                           class="w-full uppercase bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-purple-500/30 outline-none resize-none"></textarea>
                </div>

                <!-- Modalidad de Pago Dinámica -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-2.5">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[11px] font-extrabold uppercase text-slate-500 dark:text-slate-400 tracking-wider">Modalidad de Pago *</p>
                        <span class="text-[10px] text-slate-400">Seleccione la modalidad a aplicar</span>
                    </div>

                    <?php foreach ($modalidadesList as $idx => $m): ?>
                        <?php
                            $mTipo = htmlspecialchars($m['tipo']);
                            $mNom  = htmlspecialchars($m['nombre']);
                            $mPct  = (float)$m['porcentaje'];
                            $mColor = $m['color'] ?? 'purple';
                            $styleInline = obtenerEstiloBadgeInline($mColor);
                            $badgeCls = empty($styleInline) ? obtenerEstiloBadgeColor($mColor) : '';
                        ?>
                        <label class="flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 hover:border-purple-300 cursor-pointer transition-colors">
                            <input type="radio" name="add_tipo_pago" value="<?php echo $mTipo; ?>" <?php echo ($idx === 0) ? 'checked' : ''; ?>
                                   class="w-4 h-4 text-purple-600 focus:ring-purple-500 cursor-pointer" />
                            <div class="flex-1 flex items-center justify-between">
                                <div>
                                    <span class="font-extrabold text-slate-800 dark:text-slate-100">
                                        <?php echo $mNom; ?>
                                    </span>
                                    <p class="text-[10px] text-slate-400">Aplica liquidación para <?php echo strtolower($mNom); ?> (<?php echo $mPct; ?>%)</p>
                                </div>
                                <span class="px-2.5 py-1 rounded-lg text-[11px] font-black border shadow-2xs <?php echo $badgeCls; ?>" <?php echo $styleInline; ?>>
                                    <?php echo $mPct; ?>%
                                </span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalCrear()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarCrear" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-purple-600 hover:bg-purple-700 text-white shadow-md transition-all cursor-pointer">
                        Guardar Examen
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- Modal Editar Examen Especial -->
    <div id="modalEditar" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm px-4">
        <div class="bg-white dark:bg-slate-900 rounded-3xl max-w-lg w-full p-8 shadow-2xl border border-slate-100 dark:border-slate-800 transform transition-all duration-300 animate-card-entry">
            
            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-50 dark:bg-amber-950/80 text-amber-600 dark:text-amber-400 flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">edit_note</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-primary dark:text-white font-outfit">Actualizar Examen</h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500">Modifique los datos y la modalidad de pago</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalEditar()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>

            <form id="formEditar" onsubmit="guardarEdicionExamen(event)" class="space-y-4 text-xs">
                <input type="hidden" id="edit_id" value="" />

                <!-- CUPS & Tipo -->
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Código CUPS *</label>
                        <input type="text" id="edit_codigo" required 
                               class="w-full uppercase font-mono bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-amber-500/30 outline-none" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Tipo *</label>
                        <input type="text" id="edit_tipo" required 
                               class="w-full uppercase bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-amber-500/30 outline-none" />
                    </div>
                </div>

                <!-- Nombre del Estudio -->
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">Nombre del Estudio / Examen *</label>
                    <textarea id="edit_estudio" required rows="3" 
                           class="w-full uppercase bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 font-bold text-primary dark:text-slate-100 focus:ring-2 focus:ring-amber-500/30 outline-none resize-none"></textarea>
                </div>

                <!-- Modalidad de Pago Dinámica -->
                <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 space-y-2.5">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-[11px] font-extrabold uppercase text-slate-500 dark:text-slate-400 tracking-wider">Modalidad de Pago *</p>
                        <span class="text-[10px] text-slate-400">Seleccione la modalidad a aplicar</span>
                    </div>

                    <?php foreach ($modalidadesList as $m): ?>
                        <?php
                            $mTipo = htmlspecialchars($m['tipo']);
                            $mNom  = htmlspecialchars($m['nombre']);
                            $mPct  = (float)$m['porcentaje'];
                            $mColor = $m['color'] ?? 'purple';
                            $styleInline = obtenerEstiloBadgeInline($mColor);
                            $badgeCls = empty($styleInline) ? obtenerEstiloBadgeColor($mColor) : '';
                        ?>
                        <label class="flex items-center gap-3 p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 hover:border-amber-300 cursor-pointer transition-colors">
                            <input type="radio" name="edit_tipo_pago" value="<?php echo $mTipo; ?>"
                                   class="w-4 h-4 text-amber-600 focus:ring-amber-500 cursor-pointer" />
                            <div class="flex-1 flex items-center justify-between">
                                <div>
                                    <span class="font-extrabold text-slate-800 dark:text-slate-100">
                                        <?php echo $mNom; ?>
                                    </span>
                                    <p class="text-[10px] text-slate-400">Aplica liquidación para <?php echo strtolower($mNom); ?> (<?php echo $mPct; ?>%)</p>
                                </div>
                                <span class="px-2.5 py-1 rounded-lg text-[11px] font-black border shadow-2xs <?php echo $badgeCls; ?>" <?php echo $styleInline; ?>>
                                    <?php echo $mPct; ?>%
                                </span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <input type="hidden" id="edit_estado" value="1" />

                <!-- Botones de Acción -->
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <button type="button" onclick="cerrarModalEditar()" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                        Cancelar
                    </button>
                    <button type="submit" id="btnGuardarEditar" class="px-5 py-2.5 rounded-xl text-xs font-bold bg-amber-500 hover:bg-amber-600 text-white shadow-md transition-all cursor-pointer">
                        Actualizar Examen
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- Modal Selección de Entidad / Catálogo (Sincronizado con tarifario.php y maestro_porcentajes.php) -->
    <div id="modalSeleccionEntidad" 
         class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-950/85 backdrop-blur-md transition-all duration-300">
        <div class="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-2xl max-w-4xl w-full overflow-hidden flex flex-col max-h-[92vh] animate__animated animate__fadeInDown animate__faster">
            
            <!-- Modal Header -->
            <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between bg-gradient-to-r from-slate-50 via-white to-slate-50 dark:from-slate-800/90 dark:via-slate-900 dark:to-slate-800/90">
                <div class="flex items-center gap-3.5">
                    <div class="p-3 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-500 text-white shadow-md shadow-purple-500/20">
                        <span class="material-symbols-outlined text-2xl">domain</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-black text-slate-900 dark:text-white font-outfit tracking-tight">
                                Selección de Entidad / Sede de Tarifario
                            </h3>
                            <span class="px-2.5 py-0.5 text-[10px] font-black uppercase rounded-full bg-purple-50 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300 border border-purple-200/60 dark:border-purple-800/60 tracking-wider">
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
                        $isPropioActivo = ($entidadActivaKey === 'PROPIO');
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
                                    <span class="material-symbols-outlined text-sm text-teal-600">stars</span>
                                    <span><strong><?php echo count($examenesEspeciales); ?></strong> estudios en catálogo</span>
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

                    <!-- Cards 2+: External Entities (Excluyendo Hernán Ocazionez para evitar duplicados) -->
                    <?php 
                    foreach ($entidadesExternas as $ent): 
                        $eKey = (string)$ent['id'];
                        if ($eKey === 'PROPIO' || $eKey === '4' || (int)($ent['id_int'] ?? 0) === 4 || stripos($ent['nombre'] ?? '', 'HERNAN') !== false) continue;
                        $isEntActiva = ($entidadActivaKey === $eKey);
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
                                        <span class="material-symbols-outlined text-sm <?php echo $paletaEnt['icon_color']; ?>">stars</span>
                                        <span>Modalidades especiales</span>
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
            confirmButton: 'px-5 py-2.5 rounded-xl font-bold text-xs bg-purple-600 hover:bg-purple-500 text-white shadow-md cursor-pointer transition-all mx-1.5',
            cancelButton: 'px-5 py-2.5 rounded-xl font-bold text-xs bg-slate-200 hover:bg-slate-300 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 cursor-pointer transition-all mx-1.5'
        },
        buttonsStyling: false
    });

    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 1500,
        timerProgressBar: true
    });

    function actualizarKPIsEnVivo() {
        const rows = document.querySelectorAll('.fila-especial');
        const counts = {};
        let totalActivas = 0;
        let totalInactivas = 0;

        rows.forEach(r => {
            const mod = (r.getAttribute('data-mod') || '').toUpperCase();
            const est = r.getAttribute('data-estado');
            counts[mod] = (counts[mod] || 0) + 1;
            if (est === '1') {
                totalActivas++;
            } else {
                totalInactivas++;
            }
        });

        for (const [mod, count] of Object.entries(counts)) {
            const el = document.getElementById(`kpi_mod_${mod}`);
            if (el) el.textContent = count;
        }

        const elHabilitados = document.getElementById('kpiHabilitados');
        if (elHabilitados) elHabilitados.textContent = totalActivas;
    }

    // Filtros en vivo
    function aplicarFiltros() {
        const search = document.getElementById('searchInput').value.toLowerCase().trim();
        const mod    = document.getElementById('filtroModalidad').value;
        const estado = document.getElementById('filtroEstado').value;

        const rows = document.querySelectorAll('.fila-especial');
        rows.forEach(row => {
            const cups    = (row.getAttribute('data-cups') || '').toLowerCase();
            const text    = row.textContent.toLowerCase();
            const rowMod  = (row.getAttribute('data-mod') || '').toUpperCase();
            const rowEstado = row.getAttribute('data-estado');

            const matchSearch = (search === '' || text.includes(search) || cups.includes(search));
            
            let matchMod = true;
            if (mod === 'TODOS') {
                matchMod = true;
            } else {
                matchMod = (rowMod === mod);
            }

            const matchEstado = (estado === 'TODOS' || rowEstado === estado);

            if (matchSearch && matchMod && matchEstado) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    document.getElementById('searchInput')?.addEventListener('input', aplicarFiltros);

    // Modal Crear
    function abrirModalCrear() {
        document.getElementById('formCrear').reset();
        const firstRadio = document.querySelector('input[name="add_tipo_pago"]');
        if (firstRadio) firstRadio.checked = true;
        document.getElementById('modalCrear').classList.remove('hidden');
    }
    function cerrarModalCrear() {
        document.getElementById('modalCrear').classList.add('hidden');
    }

    // Guardar Nuevo Examen
    async function guardarNuevoExamen(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardarCrear');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">sync</span> Guardando...';

        const selectedRadio = document.querySelector('input[name="add_tipo_pago"]:checked');
        const tipoPago = selectedRadio ? selectedRadio.value : (document.querySelector('input[name="add_tipo_pago"]')?.value || 'TARIFAS_ESPECIALES');

        const formData = new FormData();
        formData.append('action', 'crear_tarifa_especial');
        formData.append('codigo', document.getElementById('add_codigo').value.trim());
        formData.append('tipo', document.getElementById('add_tipo').value.trim());
        formData.append('estudio', document.getElementById('add_estudio').value.trim());
        formData.append('tipo_pago', tipoPago);
        formData.append('es_tarifa_especial', tipoPago === 'TARIFAS_ESPECIALES' ? 1 : 0);
        formData.append('es_deglucion', tipoPago === 'DEGLUCIONES' ? 1 : 0);

        try {
            const res = await fetch('tarifario_especial.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                SwalCustom.fire({
                    icon: 'success',
                    title: '¡Registrado!',
                    text: data.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                SwalCustom.fire({
                    icon: 'error',
                    title: 'No se pudo guardar',
                    text: data.message
                });
                btn.disabled = false;
                btn.textContent = 'Guardar Examen';
            }
        } catch (err) {
            SwalCustom.fire({
                icon: 'error',
                title: 'Error de Red',
                text: 'Error de comunicación con el servidor.'
            });
            btn.disabled = false;
            btn.textContent = 'Guardar Examen';
        }
    }

    // Modal Editar
    function abrirModalEditar(t) {
        document.getElementById('edit_id').value = t.id;
        document.getElementById('edit_codigo').value = t.codigo;
        document.getElementById('edit_tipo').value = t.tipo || 'ECOG';
        document.getElementById('edit_estudio').value = t.estudio;
        document.getElementById('edit_estado').value = t.estado ?? 1;

        let modTipo = (t.tipo_pago || '').toUpperCase().trim();
        if (!modTipo || modTipo === 'TARIFA_ESTANDAR') {
            if (parseInt(t.es_tarifa_especial) === 1) modTipo = 'TARIFAS_ESPECIALES';
            else if (parseInt(t.es_deglucion) === 1) modTipo = 'DEGLUCIONES';
            else modTipo = 'TARIFAS_ESPECIALES';
        }

        const radio = document.querySelector(`input[name="edit_tipo_pago"][value="${modTipo}"]`);
        if (radio) {
            radio.checked = true;
        } else {
            const firstRadio = document.querySelector('input[name="edit_tipo_pago"]');
            if (firstRadio) firstRadio.checked = true;
        }

        document.getElementById('modalEditar').classList.remove('hidden');
    }
    function cerrarModalEditar() {
        document.getElementById('modalEditar').classList.add('hidden');
    }

    // Guardar Edición
    async function guardarEdicionExamen(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardarEditar');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">sync</span> Actualizando...';

        const selectedRadio = document.querySelector('input[name="edit_tipo_pago"]:checked');
        const tipoPago = selectedRadio ? selectedRadio.value : (document.querySelector('input[name="edit_tipo_pago"]')?.value || 'TARIFAS_ESPECIALES');

        const formData = new FormData();
        formData.append('action', 'guardar_tarifa_especial');
        formData.append('id', document.getElementById('edit_id').value);
        formData.append('codigo', document.getElementById('edit_codigo').value.trim());
        formData.append('tipo', document.getElementById('edit_tipo').value.trim());
        formData.append('estudio', document.getElementById('edit_estudio').value.trim());
        formData.append('tipo_pago', tipoPago);
        formData.append('es_tarifa_especial', tipoPago === 'TARIFAS_ESPECIALES' ? 1 : 0);
        formData.append('es_deglucion', tipoPago === 'DEGLUCIONES' ? 1 : 0);
        formData.append('estado', document.getElementById('edit_estado')?.value || 1);

        try {
            const res = await fetch('tarifario_especial.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                SwalCustom.fire({
                    icon: 'success',
                    title: '¡Actualizado!',
                    text: data.message,
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
                btn.textContent = 'Actualizar Examen';
            }
        } catch (err) {
            SwalCustom.fire({
                icon: 'error',
                title: 'Error de Red',
                text: 'Error de comunicación con el servidor.'
            });
            btn.disabled = false;
            btn.textContent = 'Actualizar Examen';
        }
    }

    // Toggle Estado con Popup SweetAlert2 Bonito
    async function toggleEstado(id, nuevoEstado) {
        const esActivar = (nuevoEstado === 1);
        const titleText = esActivar ? '¿Activar examen especial?' : '¿Desactivar examen especial?';
        const bodyText  = esActivar 
            ? 'El examen quedará habilitado para liquidaciones.' 
            : 'El examen quedará en estado inactivo.';
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
        formData.append('action', 'toggle_estado_especial');
        formData.append('id', id);
        formData.append('nuevo_estado', nuevoEstado);

        try {
            const res = await fetch('tarifario_especial.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                SwalCustom.fire({
                    icon: 'success',
                    title: esActivar ? '¡Activado!' : '¡Desactivado!',
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

    // Modal Selección de Entidad
    function abrirModalSeleccionarEntidad() {
        const modal = document.getElementById('modalSeleccionEntidad');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
    }

    function cerrarModalSeleccionarEntidad() {
        const modal = document.getElementById('modalSeleccionEntidad');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }
    }

    function confirmarSeleccionEntidad(id, nombre) {
        window.location.href = 'tarifario_especial.php?entidad_id=' + encodeURIComponent(id);
    }
    </script>

</body>

</html>


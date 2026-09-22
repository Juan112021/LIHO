<?php
/**
 * Helper de Vigencias y Versionamiento Temporal de Tarifarios LIHO
 * Gestiona la activación dinámica de tarifarios autorizados,
 * cálculo histórico de valores según la fecha del examen,
 * y trazabilidad en logs del sistema.
 */
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/logger_helper.php';

/**
 * Obtiene el listado de vigencias registradas para un tipo de tarifario y entidad
 */
function obtenerListadoVigencias($tipoTarifario = 'TODOS', $entidadId = null) {
    $con = obtenerConexionLIHO();
    if ($con === false) return [];

    $sql = "SELECT id, tipo_tarifario, entidad_id, nombre_vigencia, 
                   CONVERT(VARCHAR(10), fecha_inicio, 120) AS fecha_inicio, 
                   CONVERT(VARCHAR(10), fecha_fin, 120) AS fecha_fin, 
                   porcentaje_ajuste, estado, usuario_nombre, 
                   fecha_creacion, observaciones 
            FROM tarifarios_vigencias 
            WHERE 1=1";
    $params = [];

    if ($tipoTarifario !== 'TODOS' && !empty($tipoTarifario)) {
        $sql .= " AND (tipo_tarifario = ? OR tipo_tarifario = 'TODOS')";
        $params[] = $tipoTarifario;
    }

    if ($entidadId !== null && $entidadId !== '') {
        $sql .= " AND (entidad_id = ? OR entidad_id IS NULL OR entidad_id = 4)";
        $params[] = (int)$entidadId;
    }

    $sql .= " ORDER BY fecha_inicio DESC, id DESC";

    $stmt = sqlsrv_query($con, $sql, $params);
    $lista = [];
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $lista[] = $row;
        }
    }
    return $lista;
}

/**
 * Obtiene la vigencia actualmente activa
 */
function obtenerVigenciaActivaActual($tipoTarifario = 'TODOS', $entidadId = null) {
    $con = obtenerConexionLIHO();
    if ($con === false) return null;

    $sql = "SELECT TOP 1 id, tipo_tarifario, entidad_id, nombre_vigencia, 
                         CONVERT(VARCHAR(10), fecha_inicio, 120) AS fecha_inicio, 
                         CONVERT(VARCHAR(10), fecha_fin, 120) AS fecha_fin, 
                         estado, usuario_nombre, observaciones 
            FROM tarifarios_vigencias 
            WHERE estado = 'ACTIVA' ";
    $params = [];

    if ($tipoTarifario !== 'TODOS' && !empty($tipoTarifario)) {
        $sql .= " AND (tipo_tarifario = ? OR tipo_tarifario = 'TODOS')";
        $params[] = $tipoTarifario;
    }

    $sql .= " ORDER BY fecha_inicio DESC, id DESC";

    $stmt = sqlsrv_query($con, $sql, $params);
    if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        return $row;
    }
    return [
        'id' => 0,
        'nombre_vigencia' => 'Vigencia General Activa',
        'fecha_inicio' => '2020-01-01',
        'fecha_fin' => null,
        'estado' => 'ACTIVA'
    ];
}

/**
 * Autoriza y activa un cambio de tarifas con una nueva fecha de vigencia.
 * 
 * Reglas de negocio:
 * 1. El tarifario anterior finaliza su vigencia el día anterior a la nueva fecha ($fechaVigencia - 1 día).
 * 2. El nuevo tarifario entra en vigencia a partir de $fechaVigencia con fecha_fin = NULL.
 * 3. Si se especifica porcentaje_reajuste != 0, se duplican las tarifas activas con el nuevo valor ajustado.
 * 4. Queda registrado en la tabla tarifarios_vigencias y en los logs de auditoría.
 */
function autorizarCambioTarifarioVigencia($datos) {
    $con = obtenerConexionLIHO();
    if ($con === false) {
        return ['success' => false, 'message' => 'Error de conexión a la base de datos SQL Server.'];
    }

    $fechaVigenciaNueva = trim($datos['fecha_vigencia'] ?? '');
    $nombreVigencia     = trim($datos['nombre_vigencia'] ?? '');
    $tipoTarifario      = trim($datos['tipo_tarifario'] ?? 'TODOS'); // 'CUPS_GENERAL', 'ESPECIAL', 'BLOQUEOS', 'TODOS'
    $entidadId          = isset($datos['entidad_id']) && $datos['entidad_id'] !== '' ? (int)$datos['entidad_id'] : 4;
    $porcentajeReajuste = isset($datos['porcentaje_reajuste']) ? floatval($datos['porcentaje_reajuste']) : 0.0;
    $usuarioId          = (int)($datos['usuario_id'] ?? 0);
    $usuarioNombre      = trim($datos['usuario_nombre'] ?? 'Administrador');
    $observaciones      = trim($datos['observaciones'] ?? '');

    if (empty($fechaVigenciaNueva)) {
        return ['success' => false, 'message' => 'La fecha de inicio de vigencia es obligatoria.'];
    }

    if (empty($nombreVigencia)) {
        $nombreVigencia = 'Tarifario Autorizado - Vigencia ' . $fechaVigenciaNueva;
    }

    // Calcular fecha de corte (día anterior)
    $fechaObj = DateTime::createFromFormat('Y-m-d', $fechaVigenciaNueva);
    if (!$fechaObj) {
        return ['success' => false, 'message' => 'Formato de fecha de vigencia inválido (Debe ser YYYY-MM-DD).'];
    }
    $fechaFinAnteriorObj = clone $fechaObj;
    $fechaFinAnteriorObj->modify('-1 day');
    $fechaFinAnterior = $fechaFinAnteriorObj->format('Y-m-d');

    // Iniciar Transacción en SQL Server
    sqlsrv_begin_transaction($con);

    try {
        // 1. Cerrar vigencias activas previas en la tabla maestra de vigencias
        $sqlCloseVig = "UPDATE tarifarios_vigencias 
                        SET fecha_fin = ?, estado = 'HISTORICA' 
                        WHERE estado = 'ACTIVA' 
                          AND (tipo_tarifario = ? OR tipo_tarifario = 'TODOS' OR ? = 'TODOS')";
        $stmtCloseVig = sqlsrv_query($con, $sqlCloseVig, [$fechaFinAnterior, $tipoTarifario, $tipoTarifario]);
        if ($stmtCloseVig === false) {
            throw new Exception("Error al cerrar vigencia previa: " . print_r(sqlsrv_errors(), true));
        }

        // 2. Insertar nueva vigencia activa en tarifarios_vigencias
        $sqlNewVig = "INSERT INTO tarifarios_vigencias 
                      (tipo_tarifario, entidad_id, nombre_vigencia, fecha_inicio, fecha_fin, porcentaje_ajuste, estado, usuario_id, usuario_nombre, fecha_creacion, observaciones) 
                      VALUES (?, ?, ?, ?, NULL, ?, 'ACTIVA', ?, ?, GETDATE(), ?)";
        $stmtNewVig = sqlsrv_query($con, $sqlNewVig, [
            $tipoTarifario, $entidadId, $nombreVigencia, $fechaVigenciaNueva, 
            $porcentajeReajuste, $usuarioId, $usuarioNombre, $observaciones
        ]);
        if ($stmtNewVig === false) {
            throw new Exception("Error al registrar nueva vigencia: " . print_r(sqlsrv_errors(), true));
        }

        $totalAfectados = 0;

        // 3. Procesar tarifario CUPS General si aplica
        if ($tipoTarifario === 'TODOS' || $tipoTarifario === 'CUPS_GENERAL') {
            // A. Cerrar las tarifas activas actuales
            $sqlCloseTar = "UPDATE tarifario 
                            SET vigencia_hasta = ? 
                            WHERE (vigencia_hasta IS NULL OR vigencia_hasta >= ?) 
                              AND (entidad_id = ? OR (? = 4 AND (entidad_id IS NULL OR entidad_id = 0 OR entidad_id = 4)))";
            $stmtCloseTar = sqlsrv_query($con, $sqlCloseTar, [$fechaFinAnterior, $fechaVigenciaNueva, $entidadId, $entidadId]);
            if ($stmtCloseTar === false) {
                throw new Exception("Error al cerrar tarifas CUPS anteriores: " . print_r(sqlsrv_errors(), true));
            }

            // B. Clonar tarifas activas para la nueva vigencia (con factor de ajuste si se especificó o factor 1.0 para mantener)
            $factor = ($porcentajeReajuste != 0.0) ? (1.0 + ($porcentajeReajuste / 100.0)) : 1.0;
            $sqlClone = "INSERT INTO tarifario 
                         (codigo, columna1, examen, concepto, servicio, tipo, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, cuenta_contable, nombre_cuenta, fecha_creacion, fecha_ultima_modificacion, usuario_ultimo_cambio_id, estado, tipo_paciente, entidad_id, pagar_por_cantidad, base_calculo, vigencia_desde, vigencia_hasta, version_id)
                         SELECT codigo, 
                                ROUND(columna1 * ?, 0), 
                                examen, concepto, servicio, tipo, 
                                '$ ' + CONVERT(VARCHAR, CAST(ROUND(columna1 * ?, 0) AS MONEY), 1),
                                modalidad_ubi, cant_a_pagar, 
                                ROUND(valor_und * ?, 0), 
                                cuenta_contable, nombre_cuenta, 
                                GETDATE(), GETDATE(), ?, estado, tipo_paciente, entidad_id, pagar_por_cantidad, base_calculo, 
                                ?, NULL, version_id + 1
                         FROM tarifario 
                         WHERE vigencia_hasta = ? 
                           AND (entidad_id = ? OR (? = 4 AND (entidad_id IS NULL OR entidad_id = 0 OR entidad_id = 4)))";
            $stmtClone = sqlsrv_query($con, $sqlClone, [
                $factor, $factor, $factor, $usuarioId, 
                $fechaVigenciaNueva, $fechaFinAnterior, $entidadId, $entidadId
            ]);
            if ($stmtClone === false) {
                throw new Exception("Error al generar nuevas tarifas CUPS para la nueva vigencia: " . print_r(sqlsrv_errors(), true));
            }
            $totalAfectados += sqlsrv_rows_affected($stmtClone);
        }

        // 4. Procesar tarifario_especial si aplica
        if ($tipoTarifario === 'TODOS' || $tipoTarifario === 'ESPECIAL') {
            $sqlCloseEsp = "UPDATE tarifario_especial 
                            SET vigencia_hasta = ? 
                            WHERE (vigencia_hasta IS NULL OR vigencia_hasta >= ?)";
            $stmtCloseEsp = sqlsrv_query($con, $sqlCloseEsp, [$fechaFinAnterior, $fechaVigenciaNueva]);
            if ($stmtCloseEsp === false) {
                throw new Exception("Error al cerrar tarifas especiales anteriores: " . print_r(sqlsrv_errors(), true));
            }

            $sqlCloneEsp = "INSERT INTO tarifario_especial 
                            (codigo, tipo, estudio, estado, fecha_creacion, fecha_actualizacion, usuario_id, tipo_pago, es_tarifa_especial, es_deglucion, vigencia_desde, vigencia_hasta, version_id)
                            SELECT codigo, tipo, estudio, estado, GETDATE(), GETDATE(), ?, tipo_pago, es_tarifa_especial, es_deglucion, ?, NULL, version_id + 1
                            FROM tarifario_especial 
                            WHERE vigencia_hasta = ?";
            $stmtCloneEsp = sqlsrv_query($con, $sqlCloneEsp, [$usuarioId, $fechaVigenciaNueva, $fechaFinAnterior]);
            if ($stmtCloneEsp === false) {
                throw new Exception("Error al duplicar tarifas especiales para la nueva vigencia: " . print_r(sqlsrv_errors(), true));
            }
        }

        // 5. Procesar tarifario_bloqueos si aplica
        if ($tipoTarifario === 'TODOS' || $tipoTarifario === 'BLOQUEOS') {
            $sqlCloseBloq = "UPDATE tarifario_bloqueos 
                             SET vigencia_hasta = ? 
                             WHERE (vigencia_hasta IS NULL OR vigencia_hasta >= ?) 
                               AND (entidad_id = ? OR (? = 4 AND (entidad_id IS NULL OR entidad_id = 0 OR entidad_id = 4)))";
            $stmtCloseBloq = sqlsrv_query($con, $sqlCloseBloq, [$fechaFinAnterior, $fechaVigenciaNueva, $entidadId, $entidadId]);
            if ($stmtCloseBloq === false) {
                throw new Exception("Error al cerrar tarifario de bloqueos anterior: " . print_r(sqlsrv_errors(), true));
            }

            $factor = ($porcentajeReajuste != 0.0) ? (1.0 + ($porcentajeReajuste / 100.0)) : 1.0;
            $sqlCloneBloq = "INSERT INTO tarifario_bloqueos
                             (codigo, estudio, especialidad, valor_base, porcentaje_base, porcentaje_cant_2, porcentaje_cant_3, tipo_calculo, valor_fijo_cant_1, valor_fijo_cant_2, valor_fijo_cant_3, observaciones, entidad_id, estado, fecha_creacion, fecha_actualizacion, usuario_id, valor_cant_2, valor_cant_3, vigencia_desde, vigencia_hasta, version_id)
                             SELECT codigo, estudio, especialidad, 
                                    ROUND(valor_base * ?, 2), 
                                    porcentaje_base, porcentaje_cant_2, porcentaje_cant_3, tipo_calculo, 
                                    ROUND(ISNULL(valor_fijo_cant_1, 0) * ?, 2), 
                                    ROUND(ISNULL(valor_fijo_cant_2, 0) * ?, 2), 
                                    ROUND(ISNULL(valor_fijo_cant_3, 0) * ?, 2), 
                                    observaciones, entidad_id, estado, GETDATE(), GETDATE(), ?, 
                                    ROUND(valor_cant_2 * ?, 2), 
                                    ROUND(valor_cant_3 * ?, 2), 
                                    ?, NULL, version_id + 1
                             FROM tarifario_bloqueos 
                             WHERE vigencia_hasta = ? 
                               AND (entidad_id = ? OR (? = 4 AND (entidad_id IS NULL OR entidad_id = 0 OR entidad_id = 4)))";
            $stmtCloneBloq = sqlsrv_query($con, $sqlCloneBloq, [
                $factor, $factor, $factor, $factor, $usuarioId, 
                $factor, $factor, $fechaVigenciaNueva, $fechaFinAnterior, $entidadId, $entidadId
            ]);
            if ($stmtCloneBloq !== false) {
                $totalAfectados += sqlsrv_rows_affected($stmtCloneBloq);
            }
        }

        // Confirmar Transacción
        sqlsrv_commit($con);

        // 6. Registro de Trazabilidad en Logs de Auditoría
        $descLog = "Se autorizó y activó una nueva vigencia de tarifas: '{$nombreVigencia}'. Válida a partir del {$fechaVigenciaNueva}. Vigencia anterior cerrada al {$fechaFinAnterior}.";
        if ($porcentajeReajuste != 0.0) {
            $descLog .= " Reajuste aplicado: " . ($porcentajeReajuste > 0 ? "+{$porcentajeReajuste}%" : "{$porcentajeReajuste}%") . ". Registros generados: {$totalAfectados}.";
        }
        if (!empty($observaciones)) {
            $descLog .= " Observaciones: {$observaciones}";
        }

        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema(
                'TARIFARIOS',
                'AUTORIZACION_NUEVA_VIGENCIA',
                'CREACION',
                $descLog,
                [
                    'nivel' => 'INFO',
                    'entidad_afectada' => "Vigencia {$fechaVigenciaNueva}",
                    'valor_anterior' => "Vigencia cerrada: {$fechaFinAnterior}",
                    'valor_nuevo' => "Vigente desde: {$fechaVigenciaNueva}",
                    'usuario_id' => $usuarioId,
                    'usuario_nombre' => $usuarioNombre
                ]
            );
        }

        return [
            'success' => true, 
            'message' => "Vigencia autorizada y activada con éxito a partir del {$fechaVigenciaNueva}. El tarifario anterior finalizó su vigencia el {$fechaFinAnterior}.",
            'fecha_vigencia' => $fechaVigenciaNueva,
            'fecha_fin_anterior' => $fechaFinAnterior,
            'total_afectados' => $totalAfectados
        ];

    } catch (Exception $e) {
        sqlsrv_rollback($con);
        return ['success' => false, 'message' => 'Error procesando vigencia: ' . $e->getMessage()];
    }
}

/**
 * Autoriza y activa un cambio de tarifas cargando un listado nuevo completo
 * (por ejemplo, importado desde Excel/CSV con la estructura oficial).
 * 
 * Reglas de negocio:
 * 1. Cierra la vigencia previa al día anterior ($fechaVigencia - 1 día).
 * 2. Registra la nueva vigencia activa en tarifarios_vigencias.
 * 3. Cierra las tarifas activas previas en la tabla tarifario.
 * 4. Inserta el nuevo conjunto de tarifas proporcionado en $datos['tarifas'] con vigencia_desde = $fechaVigencia y vigencia_hasta = NULL.
 * 5. Registra la auditoría y trazabilidad en logs_sistema.
 */
function autorizarCambioTarifarioDesdeListado($datos) {
    $con = obtenerConexionLIHO();
    if ($con === false) {
        return ['success' => false, 'message' => 'Error de conexión a la base de datos SQL Server.'];
    }

    $fechaVigenciaNueva = trim($datos['fecha_vigencia'] ?? '');
    $nombreVigencia     = trim($datos['nombre_vigencia'] ?? '');
    $tipoTarifario      = trim($datos['tipo_tarifario'] ?? 'CUPS_GENERAL');
    $entidadId          = isset($datos['entidad_id']) && $datos['entidad_id'] !== '' ? (int)$datos['entidad_id'] : 4;
    $usuarioId          = (int)($datos['usuario_id'] ?? 0);
    $usuarioNombre      = trim($datos['usuario_nombre'] ?? 'Administrador');
    $observaciones      = trim($datos['observaciones'] ?? '');
    $tarifasNuevas      = $datos['tarifas'] ?? [];

    if (empty($fechaVigenciaNueva)) {
        $fechaVigenciaNueva = date('Y-m-d');
    }

    if (empty($nombreVigencia)) {
        $nombreVigencia = 'Tarifario Actualizado - ' . $fechaVigenciaNueva;
    }

    if (empty($tarifasNuevas) || !is_array($tarifasNuevas)) {
        return ['success' => false, 'message' => 'No se proporcionaron tarifas válidas en el listado para importar.'];
    }

    // Calcular fecha de corte (día anterior)
    $fechaObj = DateTime::createFromFormat('Y-m-d', $fechaVigenciaNueva);
    if (!$fechaObj) {
        return ['success' => false, 'message' => 'Formato de fecha de vigencia inválido (Debe ser YYYY-MM-DD).'];
    }
    $fechaFinAnteriorObj = clone $fechaObj;
    $fechaFinAnteriorObj->modify('-1 day');
    $fechaFinAnterior = $fechaFinAnteriorObj->format('Y-m-d');

    // Iniciar Transacción en SQL Server
    sqlsrv_begin_transaction($con);

    try {
        // 1. Cerrar vigencias activas previas
        $sqlCloseVig = "UPDATE tarifarios_vigencias 
                        SET fecha_fin = ?, estado = 'HISTORICA' 
                        WHERE estado = 'ACTIVA' 
                          AND (tipo_tarifario = ? OR tipo_tarifario = 'TODOS' OR ? = 'TODOS')
                          AND (entidad_id = ? OR (? = 4 AND (entidad_id IS NULL OR entidad_id = 0 OR entidad_id = 4)))";
        $stmtCloseVig = sqlsrv_query($con, $sqlCloseVig, [$fechaFinAnterior, $tipoTarifario, $tipoTarifario, $entidadId, $entidadId]);
        if ($stmtCloseVig === false) {
            throw new Exception("Error al cerrar vigencia previa: " . print_r(sqlsrv_errors(), true));
        }

        // 2. Insertar nueva vigencia activa
        $sqlNewVig = "INSERT INTO tarifarios_vigencias 
                      (tipo_tarifario, entidad_id, nombre_vigencia, fecha_inicio, fecha_fin, porcentaje_ajuste, estado, usuario_id, usuario_nombre, fecha_creacion, observaciones) 
                      OUTPUT INSERTED.id
                      VALUES (?, ?, ?, ?, NULL, 0, 'ACTIVA', ?, ?, GETDATE(), ?)";
        $stmtNewVig = sqlsrv_query($con, $sqlNewVig, [
            $tipoTarifario, $entidadId, $nombreVigencia, $fechaVigenciaNueva, 
            $usuarioId, $usuarioNombre, $observaciones
        ]);
        if ($stmtNewVig === false) {
            throw new Exception("Error al registrar nueva vigencia: " . print_r(sqlsrv_errors(), true));
        }
        $rowNewVig = sqlsrv_fetch_array($stmtNewVig, SQLSRV_FETCH_ASSOC);
        $newVigenciaId = (int)($rowNewVig['id'] ?? 1);

        // 3. Cerrar las tarifas activas previas en la tabla tarifario
        $sqlCloseTar = "UPDATE tarifario 
                        SET vigencia_hasta = ? 
                        WHERE (vigencia_hasta IS NULL OR vigencia_hasta >= ?) 
                          AND (entidad_id = ? OR (? = 4 AND (entidad_id IS NULL OR entidad_id = 0 OR entidad_id = 4)))";
        $stmtCloseTar = sqlsrv_query($con, $sqlCloseTar, [$fechaFinAnterior, $fechaVigenciaNueva, $entidadId, $entidadId]);
        if ($stmtCloseTar === false) {
            throw new Exception("Error al cerrar tarifas anteriores: " . print_r(sqlsrv_errors(), true));
        }

        // 4. Preparar inserción de nuevas tarifas
        $sqlIns = "INSERT INTO tarifario 
                   (codigo, columna1, examen, concepto, servicio, tipo, tipo_paciente, valor_texto, modalidad_ubi, cant_a_pagar, valor_und, cuenta_contable, nombre_cuenta, estado, entidad_id, pagar_por_cantidad, base_calculo, fecha_creacion, fecha_ultima_modificacion, usuario_ultimo_cambio_id, vigencia_desde, vigencia_hasta, version_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 'VALOR_LIQUIDACION', GETDATE(), GETDATE(), ?, ?, NULL, ?)";

        $stmtIns = sqlsrv_prepare($con, $sqlIns, [
            &$pCodigo, &$pColumna1, &$pExamen, &$pConcepto, &$pServicio, &$pTipo, &$pTipoPaciente,
            &$pValorTexto, &$pModalidadUbi, &$pCantAPagar, &$pValorUnd, &$pCuentaContable, &$pNombreCuenta,
            &$pEntidadId, &$pPagarPorCantidad, &$pUsuarioId, &$pFechaVigencia, &$pVersionId
        ]);

        if (!$stmtIns) {
            throw new Exception("Error preparando inserción de nuevas tarifas: " . print_r(sqlsrv_errors(), true));
        }

        $totalInsertados = 0;
        foreach ($tarifasNuevas as $t) {
            $pCodigo = strtoupper(trim((string)($t['codigo'] ?? '')));
            if (empty($pCodigo)) continue;

            $pExamen = trim((string)($t['examen'] ?? ''));
            if (empty($pExamen)) $pExamen = "EXAMEN CUPS {$pCodigo}";

            // Limpieza de valores numéricos
            $rawCol1   = (string)($t['columna1'] ?? '');
            $rawValUnd = (string)($t['valor_und'] ?? '');
            $rawValor  = (string)($t['valor'] ?? '');

            $cleanNumCol1   = (int)round(floatval(preg_replace('/[^0-9]/', '', $rawCol1)));
            $cleanNumValUnd = (int)round(floatval(preg_replace('/[^0-9]/', '', $rawValUnd)));
            $cleanNumValor  = (int)round(floatval(preg_replace('/[^0-9]/', '', $rawValor)));

            if ($cleanNumCol1 <= 0 && $cleanNumValor > 0) $cleanNumCol1 = $cleanNumValor;
            if ($cleanNumCol1 <= 0 && $cleanNumValUnd > 0) $cleanNumCol1 = $cleanNumValUnd;

            if ($cleanNumValUnd <= 0 && $cleanNumCol1 > 0) $cleanNumValUnd = $cleanNumCol1;
            if ($cleanNumValUnd <= 0 && $cleanNumValor > 0) $cleanNumValUnd = $cleanNumValor;

            $pColumna1 = $cleanNumCol1;
            $pValorUnd = $cleanNumValUnd;

            $pConcepto = strtoupper(trim((string)($t['concepto'] ?? '')));
            $pServicio = strtoupper(trim((string)($t['servicio'] ?? '')));
            $pTipo     = strtoupper(trim((string)($t['tipo'] ?? 'RX SIMPLE')));

            $pTipoPaciente = strtoupper(trim((string)($t['tipo_paciente'] ?? '')));
            if (empty($pTipoPaciente)) {
                $pTipoPaciente = (stripos($pTipo, 'PART') !== false || $pTipo === 'P') ? 'P' : 'E';
            }
            if ($pTipoPaciente !== 'P') $pTipoPaciente = 'E';

            $pModalidadUbi = trim((string)($t['modalidad_ubi'] ?? 'CR, DX'));
            if (empty($pModalidadUbi)) $pModalidadUbi = 'CR, DX';

            $pCantAPagar = isset($t['cant_a_pagar']) ? (int)$t['cant_a_pagar'] : 1;
            if ($pCantAPagar <= 0) $pCantAPagar = 1;
            $pPagarPorCantidad = $pCantAPagar;

            $pValorTexto = trim((string)($t['valor_texto'] ?? ''));
            if (empty($pValorTexto)) {
                $pValorTexto = '$ ' . number_format($pColumna1, 0, ',', '.');
            }

            $pCuentaContable = trim((string)($t['cuenta_contable'] ?? ''));
            $pNombreCuenta   = strtoupper(trim((string)($t['nombre_cuenta'] ?? '')));
            $pEntidadId      = $entidadId;
            $pUsuarioId      = $usuarioId;
            $pFechaVigencia  = $fechaVigenciaNueva;
            $pVersionId      = $newVigenciaId;

            if (sqlsrv_execute($stmtIns) === false) {
                throw new Exception("Error al insertar código {$pCodigo}: " . print_r(sqlsrv_errors(), true));
            }
            $totalInsertados++;
        }

        // Confirmar Transacción
        sqlsrv_commit($con);

        // 5. Trazabilidad en Logs
        $descLog = "Se autorizó y cargó un nuevo tarifario desde archivo para la entidad ID {$entidadId}: '{$nombreVigencia}'. Válido a partir del {$fechaVigenciaNueva}. Vigencia anterior cerrada al {$fechaFinAnterior}. Total exámenes ingresados: {$totalInsertados}.";
        if (!empty($observaciones)) {
            $descLog .= " Observaciones: {$observaciones}";
        }

        if (function_exists('registrar_log_sistema')) {
            registrar_log_sistema(
                'TARIFARIOS',
                'AUTORIZAR_TARIFARIO_ARCHIVO',
                'CREACION',
                $descLog,
                [
                    'nivel' => 'INFO',
                    'entidad_afectada' => "Vigencia {$fechaVigenciaNueva}",
                    'valor_anterior' => "Vigencia cerrada: {$fechaFinAnterior}",
                    'valor_nuevo' => "{$totalInsertados} exámenes vigentes desde {$fechaVigenciaNueva}",
                    'usuario_id' => $usuarioId,
                    'usuario_nombre' => $usuarioNombre
                ]
            );
        }

        return [
            'success' => true, 
            'message' => "Nuevo tarifario autorizado y activado con éxito a partir del {$fechaVigenciaNueva}. Se actualizaron {$totalInsertados} exámenes con sus nuevos valores. El tarifario anterior finalizó su vigencia el {$fechaFinAnterior}.",
            'fecha_vigencia' => $fechaVigenciaNueva,
            'fecha_fin_anterior' => $fechaFinAnterior,
            'total_afectados' => $totalInsertados
        ];

    } catch (Exception $e) {
        sqlsrv_rollback($con);
        return ['success' => false, 'message' => 'Error al procesar el tarifario desde archivo: ' . $e->getMessage()];
    }
}


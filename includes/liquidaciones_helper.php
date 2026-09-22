<?php
date_default_timezone_set('America/Bogota');
/**
 * Helper de Base de Datos para Registro, Aprobación y Logs de Liquidaciones
 * IPS Hernán Ocazionez y Cía S.A.S. - LIHO
 */
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/smtp_mailer.php';
require_once __DIR__ . '/pdf_liquidaciones.php';

/**
 * Garantiza que existan las tablas requeridas y la tabla unificada de auditoría 'sistema_auditoria_logs'
 */
function asegurarTablasLiquidaciones() {
    static $aseguradas = false;
    if ($aseguradas) return true;

    $con = obtenerConexionLIHO();
    if ($con === false) {
        return false;
    }

    // 1. Tabla Principal de Liquidaciones
    $sqlTableTurnos = "
    IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'liquidaciones_turnos')
    BEGIN
        CREATE TABLE liquidaciones_turnos (
            id INT IDENTITY(1,1) PRIMARY KEY,
            periodo_desde VARCHAR(20) NULL,
            periodo_hasta VARCHAR(20) NULL,
            medico_cedula VARCHAR(50) NULL,
            medico_nombre VARCHAR(200) NULL,
            total_factura DECIMAL(18,2) DEFAULT 0,
            ded_afc DECIMAL(18,2) DEFAULT 0,
            ded_solidaridad DECIMAL(18,2) DEFAULT 0,
            ded_ibc DECIMAL(18,2) DEFAULT 0,
            ded_salud DECIMAL(18,2) DEFAULT 0,
            ded_pension DECIMAL(18,2) DEFAULT 0,
            ded_arl DECIMAL(18,2) DEFAULT 0,
            ded_rete_383 DECIMAL(18,2) DEFAULT 0,
            ded_retencion DECIMAL(18,2) DEFAULT 0,
            total_deducciones DECIMAL(18,2) DEFAULT 0,
            total_a_pagar DECIMAL(18,2) DEFAULT 0,
            estado VARCHAR(50) DEFAULT 'PENDIENTE',
            fecha_creacion DATETIME DEFAULT GETDATE(),
            usuario_creador_id INT NULL,
            usuario_creador_nombre VARCHAR(150) NULL,
            detalles_json NVARCHAR(MAX) NULL,
            usuario_aprobador_id INT NULL,
            usuario_aprobador_nombre VARCHAR(150) NULL,
            fecha_aprobacion DATETIME NULL
        );
    END ELSE BEGIN
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'ded_pension')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD ded_pension DECIMAL(18,2) DEFAULT 0;
        END;
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'ded_rete_383_info')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD ded_rete_383_info DECIMAL(18,2) DEFAULT 0;
        END;
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'ded_retencion_pct')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD ded_retencion_pct DECIMAL(5,2) DEFAULT 0;
        END;
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'resumen_sedes_json')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD resumen_sedes_json NVARCHAR(MAX) NULL;
        END;
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'exclusiones_json')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD exclusiones_json NVARCHAR(MAX) NULL;
        END;
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'hash_integridad')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD hash_integridad VARCHAR(64) NULL;
        END;
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'entidad_id')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD entidad_id VARCHAR(50) NULL;
        END;
        IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_turnos') AND name = 'entidad_nombre')
        BEGIN
            ALTER TABLE liquidaciones_turnos ADD entidad_nombre VARCHAR(200) NULL;
        END;
    END;";
    @sqlsrv_query($con, $sqlTableTurnos);

    // 1.1 Tabla Relacional de Exámenes Excluidos de Liquidaciones
    $sqlTableExcluidos = "
    IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'liquidaciones_examenes_excluidos')
    BEGIN
        CREATE TABLE liquidaciones_examenes_excluidos (
            id INT IDENTITY(1,1) PRIMARY KEY,
            liquidacion_id INT NULL,
            periodo_desde VARCHAR(20) NULL,
            periodo_hasta VARCHAR(20) NULL,
            origen VARCHAR(20) NULL,
            evento_id VARCHAR(50) NULL,
            fuente VARCHAR(50) NULL,
            ingreso VARCHAR(50) NULL,
            medico_cedula VARCHAR(50) NULL,
            medico_nombre VARCHAR(200) NULL,
            paciente VARCHAR(200) NULL,
            documento_paciente VARCHAR(50) NULL,
            entidad VARCHAR(200) NULL,
            cups VARCHAR(100) NULL,
            examen_nombre NVARCHAR(300) NULL,
            valor_examen DECIMAL(18,2) DEFAULT 0,
            valor_a_pagar DECIMAL(18,2) DEFAULT 0,
            estado_cruce VARCHAR(50) NULL,
            motivo_exclusion NVARCHAR(250) NOT NULL,
            detalle_exclusion NVARCHAR(MAX) NOT NULL,
            usuario_id INT NULL,
            usuario_nombre VARCHAR(150) NULL,
            usuario_rol VARCHAR(50) NULL,
            fecha_exclusion DATETIME DEFAULT GETDATE()
        );
    END;";
    @sqlsrv_query($con, $sqlTableExcluidos);

    // 2. Tabla Principal de Notas de Ajuste
    $sqlTableNotas = "
    IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'liquidaciones_notas_ajuste')
    BEGIN
        CREATE TABLE liquidaciones_notas_ajuste (
            id INT IDENTITY(1,1) PRIMARY KEY,
            numero_nota VARCHAR(50) NULL,
            liquidacion_id INT NOT NULL,
            medico_cedula VARCHAR(50) NULL,
            medico_nombre VARCHAR(200) NULL,
            periodo_desde VARCHAR(20) NULL,
            periodo_hasta VARCHAR(20) NULL,
            tipo_nota VARCHAR(50) DEFAULT 'CREDITO',
            motivo_ajuste NVARCHAR(MAX) NOT NULL,
            total_original DECIMAL(18,2) DEFAULT 0,
            valor_ajuste DECIMAL(18,2) DEFAULT 0,
            total_ajustado DECIMAL(18,2) DEFAULT 0,
            detalles_ajuste_json NVARCHAR(MAX) NULL,
            estado VARCHAR(50) DEFAULT 'PENDIENTE',
            fecha_creacion DATETIME DEFAULT GETDATE(),
            usuario_creador_id INT NULL,
            usuario_creador_nombre VARCHAR(150) NULL,
            usuario_aprobador_id INT NULL,
            usuario_aprobador_nombre VARCHAR(150) NULL,
            fecha_aprobacion DATETIME NULL,
            hash_integridad VARCHAR(64) NULL
        );
    END;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_notas_ajuste') AND name = 'subtotal_bruto')
    BEGIN
        ALTER TABLE liquidaciones_notas_ajuste ADD subtotal_bruto DECIMAL(18,2) DEFAULT 0;
    END;
    IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('liquidaciones_notas_ajuste') AND name = 'deducciones_ajuste')
    BEGIN
        ALTER TABLE liquidaciones_notas_ajuste ADD deducciones_ajuste DECIMAL(18,2) DEFAULT 0;
    END;";
    @sqlsrv_query($con, $sqlTableNotas);

    // 3. Tabla Unificada de Auditoría y Logs del Sistema
    $sqlTableUnifiedLogs = "
    IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'sistema_auditoria_logs')
    BEGIN
        CREATE TABLE sistema_auditoria_logs (
            id INT IDENTITY(1,1) PRIMARY KEY,
            modulo VARCHAR(50) NOT NULL, -- 'LIQUIDACION', 'NOTA_AJUSTE', 'CORREO', 'USUARIO', 'SISTEMA'
            registro_id INT NULL,
            referencia VARCHAR(100) NULL,
            accion VARCHAR(100) NOT NULL,
            estado_anterior VARCHAR(50) NULL,
            estado_nuevo VARCHAR(50) NULL,
            usuario_id INT NULL,
            usuario_nombre VARCHAR(150) NULL,
            usuario_rol VARCHAR(50) NULL,
            observaciones NVARCHAR(MAX) NULL,
            detalles_json NVARCHAR(MAX) NULL,
            estado_operacion VARCHAR(50) DEFAULT 'EXITOSO',
            ip_address VARCHAR(50) NULL,
            hash_integridad VARCHAR(64) NULL,
            fecha_registro DATETIME DEFAULT GETDATE()
        );
    END;";
    @sqlsrv_query($con, $sqlTableUnifiedLogs);

    // Migración automática de tablas de logs previas si existen
    $sqlMigrateLogs = "
    IF EXISTS (SELECT * FROM sys.tables WHERE name = 'liquidaciones_logs')
    BEGIN
        INSERT INTO sistema_auditoria_logs (modulo, registro_id, referencia, accion, estado_anterior, estado_nuevo, usuario_id, usuario_nombre, usuario_rol, observaciones, hash_integridad, fecha_registro)
        SELECT 'LIQUIDACION', liquidacion_id, 'LIQ-#' + CAST(liquidacion_id AS VARCHAR), accion, estado_anterior, estado_nuevo, usuario_id, usuario_nombre, usuario_rol, observaciones, hash_integridad, fecha_registro
        FROM liquidaciones_logs l
        WHERE NOT EXISTS (SELECT 1 FROM sistema_auditoria_logs s WHERE s.modulo = 'LIQUIDACION' AND s.registro_id = l.liquidacion_id AND s.fecha_registro = l.fecha_registro);
    END;

    IF EXISTS (SELECT * FROM sys.tables WHERE name = 'liquidaciones_notas_ajuste_logs')
    BEGIN
        INSERT INTO sistema_auditoria_logs (modulo, registro_id, referencia, accion, estado_anterior, estado_nuevo, usuario_id, usuario_nombre, usuario_rol, observaciones, hash_integridad, fecha_registro)
        SELECT 'NOTA_AJUSTE', nota_id, 'NA-#' + CAST(nota_id AS VARCHAR), accion, estado_anterior, estado_nuevo, usuario_id, usuario_nombre, usuario_rol, observaciones, hash_integridad, fecha_registro
        FROM liquidaciones_notas_ajuste_logs n
        WHERE NOT EXISTS (SELECT 1 FROM sistema_auditoria_logs s WHERE s.modulo = 'NOTA_AJUSTE' AND s.registro_id = n.nota_id AND s.fecha_registro = n.fecha_registro);
    END;";
    @sqlsrv_query($con, $sqlMigrateLogs);

    $aseguradas = true;
    return true;
}

/**
 * Genera un JSON ultraligero con los totales y conceptos agrupados por sede
 */
function generarResumenSedesJSON($detalles) {
    if (is_string($detalles)) {
        $detalles = json_decode($detalles, true) ?: array();
    }
    if (!is_array($detalles)) return '{}';

    $summary = array();
    foreach ($detalles as $sKey => $sData) {
        $sKeyNorm = strtoupper(trim($sKey));
        $conceptos = array();
        $noCruzadosCant = 0;
        $noCruzadosVal = 0;
        $examenesCount = 0;

        if (isset($sData['examenes']) && is_array($sData['examenes'])) {
            $examenesCount = count($sData['examenes']);
            foreach ($sData['examenes'] as $ex) {
                $cruce = $ex['cruce'] ?? 'SOLO_PROTEO';
                $v = floatval($ex['valor_a_pagar'] ?? 0);
                $cant = intval($ex['cantidad'] ?? 1);
                if ($cant <= 0) $cant = 1;

                $cups = strtoupper(trim($ex['cups'] ?? ''));
                $idRef = strtoupper(trim($ex['id_ref'] ?? ''));
                $nom = strtoupper(trim($ex['examen'] ?? ''));
                $esBoni = (!empty($ex['es_bonificacion']) || $cups === 'BONI_TOHO' || $idRef === 'BONI_TOHO' || strpos($nom, 'BONI_TOHO') !== false || strpos($nom, 'BONIFICACIÓN') !== false || strpos($nom, 'BONIFICACION') !== false);

                if ($esBoni) {
                    $grp = 'BONIFICACIÓN TOMOGRAFÍAS';
                    if (!isset($conceptos[$grp])) {
                        $conceptos[$grp] = array('cantidad' => 0, 'total' => 0, 'es_bonificacion' => true);
                    }
                    $conceptos[$grp]['cantidad'] += $cant;
                    $conceptos[$grp]['total'] += $v;
                } elseif ($cruce === 'CRUZADO') {
                    $grp = (strpos($nom, 'ECO') !== false || strpos($cups, 'ECO') !== false) ? 'ECOGRAFÍAS' : 'RXSI';
                    if (!isset($conceptos[$grp])) {
                        $conceptos[$grp] = array('cantidad' => 0, 'total' => 0);
                    }
                    $conceptos[$grp]['cantidad'] += $cant;
                    $conceptos[$grp]['total'] += $v;
                } else {
                    $noCruzadosCant += $cant;
                    $noCruzadosVal += $v;
                }
            }
        }

        $summary[$sKeyNorm] = array(
            'total'             => floatval($sData['total'] ?? 0),
            'examenes_count'    => $examenesCount,
            'conceptos'         => $conceptos,
            'no_cruzados_count' => $noCruzadosCant,
            'no_cruzados_valor' => $noCruzadosVal
        );
    }

    return json_encode($summary);
}

/**
 * Extrae y consolida los honorarios y exámenes generados por cada médico
 */
function generarDesgloseMedicosJSON($detalles) {
    if (is_string($detalles)) {
        $detalles = json_decode($detalles, true) ?: array();
    }
    if (!is_array($detalles)) return array();

    $medicos = array();
    foreach ($detalles as $sKey => $sData) {
        if (isset($sData['examenes']) && is_array($sData['examenes'])) {
            foreach ($sData['examenes'] as $ex) {
                $mNom = trim($ex['medico_nombre'] ?? '');
                if (empty($mNom) || $mNom === 'TODOS LOS MÉDICOS / GLOBAL' || $mNom === 'GLOBAL') {
                    $mNom = trim($ex['usuario'] ?? 'MÉDICO GENERAL');
                }
                $mNom = strtoupper($mNom);

                $mCed = trim($ex['medico_cedula'] ?? ($ex['usuario_medico'] ?? ''));
                if ($mCed === 'GLOBAL') {
                    $mCed = trim($ex['usuario_medico'] ?? '');
                    if ($mCed === 'GLOBAL') $mCed = '';
                }

                $val = floatval($ex['valor_a_pagar'] ?? 0);
                $cant = intval($ex['cantidad'] ?? 1);
                if ($cant <= 0) $cant = 1;

                $key = !empty($mCed) ? $mCed : $mNom;
                if (!isset($medicos[$key])) {
                    $medicos[$key] = array(
                        'nombre'   => $mNom,
                        'cedula'   => $mCed,
                        'cantidad' => 0,
                        'total'    => 0
                    );
                }
                $medicos[$key]['cantidad'] += $cant;
                $medicos[$key]['total'] += $val;
            }
        }
    }

    uasort($medicos, function($a, $b) {
        if ($b['total'] == $a['total']) {
            return strcmp($a['nombre'], $b['nombre']);
        }
        return ($b['total'] > $a['total']) ? 1 : -1;
    });

    return array_values($medicos);
}

/**
 * Registra una nueva liquidación en la base de datos con estado 'PENDIENTE'
 */
function guardarLiquidacionBD($data, $usuarioId, $usuarioNombre, $usuarioRol) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) {
        return array('success' => false, 'error' => 'No fue posible conectar a la base de datos SQL Server.');
    }

    $periodoDesde = $data['periodo_desde'] ?? '';
    $periodoHasta = $data['periodo_hasta'] ?? '';
    $medicoCedula = $data['medico_cedula'] ?? '';
    $medicoNombre = $data['medico_nombre'] ?? '';
    
    $totalFactura     = floatval($data['total_factura'] ?? 0);
    $dedAfc           = floatval($data['ded_afc'] ?? 0);
    $dedSolidaridad   = floatval($data['ded_solidaridad'] ?? 0);
    $dedIbc           = floatval($data['ded_ibc'] ?? 0);
    $dedSalud         = floatval($data['ded_salud'] ?? 0);
    $dedPension       = floatval($data['ded_pension'] ?? 0);
    $dedArl           = floatval($data['ded_arl'] ?? 0);
    $dedRete383Info   = floatval($data['ded_rete_383_info'] ?? 0);
    $dedRete383       = floatval($data['ded_rete_383'] ?? 0);
    $dedRetencionPct  = floatval($data['ded_retencion_pct'] ?? 0);
    $dedRetencion     = floatval($data['ded_retencion'] ?? 0);
    $totalDeducciones = floatval($data['total_deducciones'] ?? 0);
    $totalAPagar      = floatval($data['total_a_pagar'] ?? 0);
    $detallesJson     = is_string($data['detalles_json'] ?? '') ? $data['detalles_json'] : json_encode($data['detalles_json'] ?? array());
    $resumenSedesJson = generarResumenSedesJSON($detallesJson);
    $exclusionesJson  = is_string($data['exclusiones_json'] ?? '') ? $data['exclusiones_json'] : json_encode($data['exclusiones_json'] ?? array());
    
    $entidadId     = strval($data['entidad_id'] ?? ($_SESSION['entidad_liquidacion_id'] ?? 'PROPIO'));
    $entidadNombre = strval($data['entidad_nombre'] ?? ($_SESSION['entidad_liquidacion_nombre'] ?? 'Hernán Ocazionez y Cía S.A.S.'));

    $sqlInsert = "INSERT INTO liquidaciones_turnos (
        periodo_desde, periodo_hasta, medico_cedula, medico_nombre,
        total_factura, ded_afc, ded_solidaridad, ded_ibc, ded_salud, ded_pension, ded_arl, ded_rete_383_info, ded_rete_383, ded_retencion_pct, ded_retencion,
        total_deducciones, total_a_pagar, estado, fecha_creacion, usuario_creador_id, usuario_creador_nombre, detalles_json, resumen_sedes_json, exclusiones_json,
        entidad_id, entidad_nombre
    ) VALUES (
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, 'PENDIENTE', GETDATE(), ?, ?, ?, ?, ?,
        ?, ?
    ); SELECT SCOPE_IDENTITY() AS id;";

    $params = array(
        $periodoDesde, $periodoHasta, $medicoCedula, $medicoNombre,
        $totalFactura, $dedAfc, $dedSolidaridad, $dedIbc, $dedSalud, $dedPension, $dedArl, $dedRete383Info, $dedRete383, $dedRetencionPct, $dedRetencion,
        $totalDeducciones, $totalAPagar, $usuarioId, $usuarioNombre, $detallesJson, $resumenSedesJson, $exclusionesJson,
        $entidadId, $entidadNombre
    );

    $stmt = sqlsrv_query($con, $sqlInsert, $params);
    if ($stmt === false) {
        $errors = print_r(sqlsrv_errors(), true);
        return array('success' => false, 'error' => 'Error insertando la liquidación: ' . $errors);
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $newId = $row['id'] ?? 0;

    if ($newId > 0) {
        // Guardar cada examen excluido en la tabla relacional de auditoría
        $excluidosArr = json_decode($exclusionesJson, true) ?: array();
        $totalExcluidosVal = 0;
        $cantExcluidos = count($excluidosArr);

        if (!empty($excluidosArr) && is_array($excluidosArr)) {
            $sqlInsExcl = "INSERT INTO liquidaciones_examenes_excluidos (
                liquidacion_id, periodo_desde, periodo_hasta, origen, evento_id, fuente, ingreso,
                medico_cedula, medico_nombre, paciente, documento_paciente, entidad, cups, examen_nombre,
                valor_examen, valor_a_pagar, estado_cruce, motivo_exclusion, detalle_exclusion,
                usuario_id, usuario_nombre, usuario_rol, fecha_exclusion
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, GETDATE()
            )";

            foreach ($excluidosArr as $ex) {
                $vPag = floatval($ex['valor_a_pagar'] ?? 0);
                $totalExcluidosVal += $vPag;

                $paramsEx = array(
                    $newId,
                    $periodoDesde,
                    $periodoHasta,
                    $ex['origen'] ?? 'PROTEO',
                    strval($ex['evento_id'] ?? ($ex['id'] ?? '')),
                    strval($ex['fuente'] ?? ''),
                    strval($ex['ingreso'] ?? ''),
                    strval($ex['medico_cedula'] ?? $medicoCedula),
                    strval($ex['medico_nombre'] ?? $medicoNombre),
                    strval($ex['paciente'] ?? ''),
                    strval($ex['documento_paciente'] ?? ($ex['documento'] ?? '')),
                    strval($ex['entidad'] ?? ''),
                    strval($ex['cups'] ?? ''),
                    strval($ex['examen_nombre'] ?? ($ex['examen'] ?? ($ex['cups'] ?? 'EXAMEN'))),
                    floatval($ex['valor_examen'] ?? 0),
                    $vPag,
                    strval($ex['estado_cruce'] ?? ($ex['cruce'] ?? 'SOLO_PROTEO')),
                    strval($ex['motivo_exclusion'] ?? 'Exclusión manual'),
                    strval($ex['detalle_exclusion'] ?? ''),
                    $usuarioId,
                    $usuarioNombre,
                    $usuarioRol
                );
                @sqlsrv_query($con, $sqlInsExcl, $paramsEx);
            }
        }

        // Registrar Log de Creación en tabla unificada (Sin envío de correo; solo se notifica al ser APROBADA)
        $obsLog = 'Liquidación registrada y enviada a revisión';
        if ($cantExcluidos > 0) {
            $obsLog .= " ({$cantExcluidos} examen(es) excluido(s) con justificación por $" . number_format($totalExcluidosVal, 0, ',', '.') . ")";
        }
        registrarLogLiquidacionBD($newId, 'CREACIÓN', null, 'PENDIENTE', $usuarioId, $usuarioNombre, $usuarioRol, $obsLog);

        return array('success' => true, 'id' => $newId, 'excluidos_count' => $cantExcluidos);
    }

    return array('success' => false, 'error' => 'No se generó el identificador de liquidación.');
}

/**
 * Cambia el estado de una liquidación ('APROBADA' o 'RECHAZADA') y registra su log
 */
function cambiarEstadoLiquidacionBD($id, $nuevoEstado, $usuarioId, $usuarioNombre, $usuarioRol, $observaciones = '') {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) {
        return array('success' => false, 'error' => 'No fue posible conectar a la base de datos SQL Server.');
    }

    // Obtener estado y datos actuales para hash
    $sqlSel = "SELECT TOP 1 * FROM liquidaciones_turnos WHERE id = ?";
    $stmtSel = sqlsrv_query($con, $sqlSel, array($id));
    if ($stmtSel === false || !($row = sqlsrv_fetch_array($stmtSel, SQLSRV_FETCH_ASSOC))) {
        return array('success' => false, 'error' => 'La liquidación especificada no fue encontrada.');
    }

    $estadoAnterior = $row['estado'];
    $hashIntegridad = $row['hash_integridad'] ?? null;

    if ($nuevoEstado === 'APROBADA') {
        $payloadRaw = json_encode(array(
            'id'                       => $row['id'],
            'periodo_desde'            => $row['periodo_desde'],
            'periodo_hasta'            => $row['periodo_hasta'],
            'medico_cedula'            => $row['medico_cedula'],
            'medico_nombre'            => $row['medico_nombre'],
            'total_factura'            => floatval($row['total_factura']),
            'total_deducciones'        => floatval($row['total_deducciones']),
            'total_a_pagar'            => floatval($row['total_a_pagar']),
            'usuario_creador_nombre'   => $row['usuario_creador_nombre'],
            'usuario_aprobador_nombre' => $usuarioNombre,
            'detalles_json'            => $row['detalles_json']
        ), JSON_UNESCAPED_UNICODE);

        $hashIntegridad = hash('sha256', $payloadRaw);
    }

    // Actualizar estado, registrador y huella digital SHA-256 de integridad
    $sqlUpd = "UPDATE liquidaciones_turnos 
               SET estado = ?, usuario_aprobador_id = ?, usuario_aprobador_nombre = ?, fecha_aprobacion = GETDATE(), hash_integridad = ? 
               WHERE id = ?";
    $stmtUpd = sqlsrv_query($con, $sqlUpd, array($nuevoEstado, $usuarioId, $usuarioNombre, $hashIntegridad, $id));
    if ($stmtUpd === false) {
        return array('success' => false, 'error' => 'Error actualizando el estado de la liquidación.');
    }

    $accionStr = ($nuevoEstado === 'APROBADA') ? 'APROBACIÓN' : (($nuevoEstado === 'CANCELADA' || $nuevoEstado === 'RECHAZADA') ? 'CANCELACIÓN' : 'MODIFICACIÓN DE ESTADO');

    // Registrar log con hash en tabla unificada
    registrarLogLiquidacionBD($id, $accionStr, $estadoAnterior, $nuevoEstado, $usuarioId, $usuarioNombre, $usuarioRol, $observaciones, $hashIntegridad);

    // Enviar notificación formal por correo al médico con CC a Directora Médica si fue aprobada
    if ($nuevoEstado === 'APROBADA') {
        $row['hash_integridad'] = $hashIntegridad;
        $row['usuario_aprobador_nombre'] = $usuarioNombre;
        $row['fecha_aprobacion'] = date('Y-m-d H:i:s');
        @notificarLiquidacionPorCorreo($id, 'APROBADA', $row);
    }

    return array('success' => true, 'id' => $id, 'estado_nuevo' => $nuevoEstado);
}

/**
 * Obtiene el correo electrónico del médico a partir de su cédula
 */
function obtenerEmailMedicoPorCedula($cedula) {
    if (empty($cedula)) return null;
    $con = obtenerConexionLIHO();
    if ($con === false) return null;

    $sql = "SELECT TOP 1 email FROM usuarios WHERE cedula = ? AND email IS NOT NULL AND email <> ''";
    $stmt = sqlsrv_query($con, $sql, array($cedula));
    if ($stmt && ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        return trim($r['email']);
    }

    $sql2 = "SELECT TOP 1 u.email FROM medicos m INNER JOIN usuarios u ON m.usuario_id = u.id WHERE m.cedula = ? AND u.email IS NOT NULL AND u.email <> ''";
    $stmt2 = sqlsrv_query($con, $sql2, array($cedula));
    if ($stmt2 && ($r2 = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC))) {
        return trim($r2['email']);
    }

    return null;
}

/**
 * Registra un log en la tabla unificada universal de auditoría (sistema_auditoria_logs)
 */
function registrarLogAuditoriaUniversal($modulo, $registroId, $referencia, $accion, $estadoAnterior, $estadoNuevo, $usuarioId, $usuarioNombre, $usuarioRol, $observaciones = '', $detallesJson = null, $estadoOperacion = 'EXITOSO', $hash = null) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return false;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $jsonStr = is_array($detallesJson) ? json_encode($detallesJson, JSON_UNESCAPED_UNICODE) : (is_string($detallesJson) ? $detallesJson : null);

    $sql = "INSERT INTO sistema_auditoria_logs (
        modulo, registro_id, referencia, accion, estado_anterior, estado_nuevo,
        usuario_id, usuario_nombre, usuario_rol, observaciones, detalles_json,
        estado_operacion, ip_address, hash_integridad, fecha_registro
    ) VALUES (
        ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, GETDATE()
    )";

    $params = array(
        $modulo, $registroId, $referencia, $accion, $estadoAnterior, $estadoNuevo,
        $usuarioId, $usuarioNombre, $usuarioRol, $observaciones, $jsonStr,
        $estadoOperacion, $ip, $hash
    );

    $stmt = sqlsrv_query($con, $sql, $params);

    // Sincronizar con el Centro Integral de Logs y Auditoría (dbo.logs_sistema)
    require_once __DIR__ . '/logger_helper.php';
    if (function_exists('registrar_log_sistema')) {
        $modCentral = ($modulo === 'LIQUIDACION') ? 'LIQUIDACIONES' : 
                      (($modulo === 'NOTA_AJUSTE' || $modulo === 'NOTAS_AJUSTE') ? 'NOTAS_AJUSTE' : 
                      (($modulo === 'CORREO' || $modulo === 'CORREOS') ? 'CORREOS' : $modulo));
        
        $accionUpper = strtoupper($accion);
        $tipoAccion = 'EDICION';
        $eventoLog = ($modulo === 'LIQUIDACION') ? "LIQ_{$accionUpper}" : $accionUpper;
        $nivel = 'INFO';

        if (strpos($accionUpper, 'CREAC') !== false || strpos($accionUpper, 'GENERAC') !== false) {
            $tipoAccion = 'CREACION';
            $eventoLog = ($modulo === 'LIQUIDACION') ? 'GENERACION_LIQUIDACION' : 'CREACION_NOTA_AJUSTE';
            $nivel = 'INFO';
        } elseif (strpos($accionUpper, 'ENVIO') !== false || strpos($accionUpper, 'CORREO') !== false || $modulo === 'CORREO' || $modulo === 'CORREOS') {
            $tipoAccion = 'ENVIO_CORREO';
            $eventoLog = $accionUpper;
            $nivel = ($estadoOperacion === 'EXITOSO' || $estadoNuevo === 'EXITOSO') ? 'SUCCESS' : 'ERROR';
        } elseif (strpos($accionUpper, 'APROB') !== false) {
            $tipoAccion = 'EDICION';
            $eventoLog = ($modulo === 'LIQUIDACION') ? 'APROBACION_LIQUIDACION' : 'APROBACION_NOTA_AJUSTE';
            $nivel = 'SUCCESS';
        } elseif (strpos($accionUpper, 'CANCEL') !== false || strpos($accionUpper, 'RECHAZ') !== false || strpos($accionUpper, 'ANUL') !== false) {
            $tipoAccion = 'EDICION';
            $eventoLog = ($modulo === 'LIQUIDACION') ? 'CANCELACION_LIQUIDACION' : 'ANULACION_NOTA_AJUSTE';
            $nivel = 'WARNING';
        }

        $entidad = (!empty($referencia) ? $referencia : ($modulo . ' #' . $registroId));
        $detallesLog = (!empty($observaciones) ? $observaciones : "Operación {$accion} sobre {$entidad}");
        if ($estadoAnterior && $estadoNuevo) {
            $detallesLog .= " (Estado: {$estadoAnterior} -> {$estadoNuevo})";
        }

        registrar_log_sistema($modCentral, $eventoLog, $tipoAccion, $detallesLog, [
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuarioNombre,
            'usuario_rol' => $usuarioRol,
            'entidad_afectada' => $entidad,
            'valor_anterior' => $estadoAnterior,
            'valor_nuevo' => $estadoNuevo,
            'ip_origen' => $ip,
            'nivel' => $nivel
        ]);
    }

    return ($stmt !== false);
}

/**
 * Inserta un registro de auditoría/log de liquidación en la tabla unificada
 */
function registrarLogLiquidacionBD($liquidacionId, $accion, $estadoAnterior, $estadoNuevo, $usuarioId, $usuarioNombre, $usuarioRol, $observaciones, $hashIntegridad = null) {
    return registrarLogAuditoriaUniversal(
        'LIQUIDACION',
        $liquidacionId,
        'LIQ-#' . $liquidacionId,
        $accion,
        $estadoAnterior,
        $estadoNuevo,
        $usuarioId,
        $usuarioNombre,
        $usuarioRol,
        $observaciones,
        null,
        'EXITOSO',
        $hashIntegridad
    );
}

/**
 * Obtiene el listado de liquidaciones filtradas
 */
function obtenerLiquidacionesBD($filtros = array()) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $where = array("1=1");
    $params = array();

    if (!empty($filtros['estado'])) {
        $where[] = "estado = ?";
        $params[] = $filtros['estado'];
    }

    if (!empty($filtros['medico'])) {
        $where[] = "(medico_cedula LIKE ? OR medico_nombre LIKE ?)";
        $params[] = '%' . $filtros['medico'] . '%';
        $params[] = '%' . $filtros['medico'] . '%';
    }

    if (!empty($filtros['fecha_desde'])) {
        $where[] = "fecha_creacion >= ?";
        $params[] = $filtros['fecha_desde'] . ' 00:00:00';
    }

    if (!empty($filtros['fecha_hasta'])) {
        $where[] = "fecha_creacion <= ?";
        $params[] = $filtros['fecha_hasta'] . ' 23:59:59';
    }

    $whereStr = implode(' AND ', $where);
    $sql = "SELECT id, periodo_desde, periodo_hasta, medico_cedula, medico_nombre, total_factura,
                   total_deducciones, total_a_pagar, estado, fecha_creacion, usuario_creador_nombre,
                   usuario_aprobador_id, usuario_aprobador_nombre, fecha_aprobacion, hash_integridad
            FROM liquidaciones_turnos
            WHERE $whereStr
            ORDER BY id DESC";

    $stmt = sqlsrv_query($con, $sql, $params);
    $list = array();

    // Obtener mapa rápido de ajustes asociados
    $mapAjustes = array();
    $stmtAj = sqlsrv_query($con, "SELECT liquidacion_id, COUNT(*) as cant_notas, 
                                  SUM(CASE WHEN estado = 'APROBADA' THEN valor_ajuste ELSE 0 END) AS total_ajustes_aprobados,
                                  SUM(CASE WHEN estado = 'PENDIENTE' THEN valor_ajuste ELSE 0 END) AS total_ajustes_pendientes 
                                  FROM liquidaciones_notas_ajuste 
                                  GROUP BY liquidacion_id");
    if ($stmtAj !== false) {
        while ($rAj = sqlsrv_fetch_array($stmtAj, SQLSRV_FETCH_ASSOC)) {
            $mapAjustes[$rAj['liquidacion_id']] = $rAj;
        }
    }

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['fecha_creacion'] instanceof DateTime) {
                $row['fecha_creacion'] = $row['fecha_creacion']->format('Y-m-d H:i:s');
            }
            if ($row['fecha_aprobacion'] instanceof DateTime) {
                $row['fecha_aprobacion'] = $row['fecha_aprobacion']->format('Y-m-d H:i:s');
            }
            
            $lId = $row['id'];
            $ajusteInfo = $mapAjustes[$lId] ?? null;
            $row['tiene_ajustes'] = ($ajusteInfo && intval($ajusteInfo['cant_notas']) > 0);
            $row['cant_notas_ajuste'] = $ajusteInfo ? intval($ajusteInfo['cant_notas']) : 0;
            $row['ajustes_aprobados'] = $ajusteInfo ? floatval($ajusteInfo['total_ajustes_aprobados']) : 0;
            $row['ajustes_pendientes'] = $ajusteInfo ? floatval($ajusteInfo['total_ajustes_pendientes']) : 0;
            $row['total_ajustado_aprobado'] = floatval($row['total_a_pagar']) + $row['ajustes_aprobados'];

            $list[] = $row;
        }
    }

    return $list;
}

/**
 * Obtiene el detalle de una liquidación por su ID de manera ultrarrápida usando el resumen precalculado
 */
function obtenerLiquidacionPorIdBD($id, $incluirDetallesCompletos = false) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return null;

    if ($incluirDetallesCompletos) {
        $sql = "SELECT * FROM liquidaciones_turnos WHERE id = ?";
    } else {
        $sql = "SELECT id, periodo_desde, periodo_hasta, medico_cedula, medico_nombre, total_factura,
                       ded_afc, ded_solidaridad, ded_ibc, ded_salud, ded_pension, ded_arl, ded_rete_383_info,
                       ded_rete_383, ded_retencion_pct, ded_retencion, total_deducciones, total_a_pagar,
                       estado, fecha_creacion, usuario_creador_id, usuario_creador_nombre,
                       usuario_aprobador_id, usuario_aprobador_nombre, fecha_aprobacion, hash_integridad,
                       resumen_sedes_json, exclusiones_json, entidad_id, entidad_nombre
                FROM liquidaciones_turnos
                WHERE id = ?";
    }
    $stmt = sqlsrv_query($con, $sql, array($id));

    if ($stmt !== false && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        if ($row['fecha_creacion'] instanceof DateTime) {
            $row['fecha_creacion'] = $row['fecha_creacion']->format('Y-m-d H:i:s');
        }
        if (isset($row['fecha_aprobacion']) && $row['fecha_aprobacion'] instanceof DateTime) {
            $row['fecha_aprobacion'] = $row['fecha_aprobacion']->format('Y-m-d H:i:s');
        }

        // Si es un registro previo sin resumen_sedes_json, o que no tiene desagregado el bono de tomografía, generarlo en caliente y guardarlo
        $necesitaRegenerar = empty($row['resumen_sedes_json']);
        if (!$necesitaRegenerar && strpos($row['resumen_sedes_json'], 'BONI') === false) {
            $stmtFullCheck = sqlsrv_query($con, "SELECT detalles_json FROM liquidaciones_turnos WHERE id = ?", array($id));
            if ($stmtFullCheck && ($rowFullCheck = sqlsrv_fetch_array($stmtFullCheck, SQLSRV_FETCH_ASSOC))) {
                if (strpos($rowFullCheck['detalles_json'] ?? '', 'BONI_TOHO') !== false) {
                    $necesitaRegenerar = true;
                }
            }
        }

        if ($necesitaRegenerar) {
            $stmtFull = sqlsrv_query($con, "SELECT detalles_json FROM liquidaciones_turnos WHERE id = ?", array($id));
            if ($stmtFull && ($rowFull = sqlsrv_fetch_array($stmtFull, SQLSRV_FETCH_ASSOC))) {
                $resumen = generarResumenSedesJSON($rowFull['detalles_json']);
                @sqlsrv_query($con, "UPDATE liquidaciones_turnos SET resumen_sedes_json = ? WHERE id = ?", array($resumen, $id));
                $row['resumen_sedes_json'] = $resumen;
                if ($incluirDetallesCompletos) {
                    $row['detalles_json'] = $rowFull['detalles_json'];
                }
            }
        }

        $isGlobal = (
            strpos(strtoupper($row['medico_cedula'] ?? ''), 'GLOBAL') !== false ||
            strpos(strtoupper($row['medico_nombre'] ?? ''), 'GLOBAL') !== false ||
            strpos(strtoupper($row['medico_nombre'] ?? ''), 'TODOS') !== false
        );

        if ($isGlobal) {
            $detallesRaw = $row['detalles_json'] ?? '';
            if (empty($detallesRaw)) {
                $stmtD = sqlsrv_query($con, "SELECT detalles_json FROM liquidaciones_turnos WHERE id = ?", array($id));
                if ($stmtD && ($rowD = sqlsrv_fetch_array($stmtD, SQLSRV_FETCH_ASSOC))) {
                    $detallesRaw = $rowD['detalles_json'];
                    if ($incluirDetallesCompletos) {
                        $row['detalles_json'] = $detallesRaw;
                    }
                }
            }
            $row['desglose_medicos'] = generarDesgloseMedicosJSON($detallesRaw);
        } else {
            $row['desglose_medicos'] = array();
        }

        $row['exclusiones'] = obtenerExclusionesPorLiquidacionIdBD($id, $row['exclusiones_json'] ?? '');
        $row['logs'] = obtenerLogsLiquidacionBD($id);
        $row['consolidacion_ajustes'] = obtenerConsolidacionLiquidacionAjustesBD($id, $row);
        return $row;
    }

    return null;
}

/**
 * Obtiene la lista de exclusiones vinculadas a una liquidación específica
 */
function obtenerExclusionesPorLiquidacionIdBD($liquidacionId, $rawExclusionesJson = '') {
    $con = obtenerConexionLIHO();
    if ($con === false) {
        if (!empty($rawExclusionesJson)) {
            return json_decode($rawExclusionesJson, true) ?: array();
        }
        return array();
    }

    $sql = "SELECT id, liquidacion_id, periodo_desde, periodo_hasta, origen, evento_id, fuente, ingreso,
                   medico_cedula, medico_nombre, paciente, documento_paciente, entidad, cups, examen_nombre,
                   valor_examen, valor_a_pagar, estado_cruce, motivo_exclusion, detalle_exclusion,
                   usuario_id, usuario_nombre, usuario_rol, fecha_exclusion
            FROM liquidaciones_examenes_excluidos
            WHERE liquidacion_id = ?
            ORDER BY id ASC";
    $stmt = sqlsrv_query($con, $sql, array($liquidacionId));
    $list = array();

    if ($stmt !== false) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($r['fecha_exclusion'] instanceof DateTime) {
                $r['fecha_exclusion'] = $r['fecha_exclusion']->format('Y-m-d H:i:s');
            }
            $list[] = $r;
        }
    }

    // Fallback al JSON de la liquidación si no existían registros en la tabla relacional
    if (empty($list) && !empty($rawExclusionesJson)) {
        $decoded = json_decode($rawExclusionesJson, true);
        if (is_array($decoded)) {
            $list = $decoded;
        }
    }

    return $list;
}

/**
 * Consulta y filtra todos los exámenes excluidos registrados en la base de datos
 */
function obtenerExamenesExcluidosBD($filtros = array()) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $where = array("1=1");
    $params = array();

    if (!empty($filtros['liquidacion_id'])) {
        $where[] = "e.liquidacion_id = ?";
        $params[] = intval($filtros['liquidacion_id']);
    }

    if (!empty($filtros['medico'])) {
        $where[] = "(e.medico_cedula LIKE ? OR e.medico_nombre LIKE ?)";
        $params[] = '%' . $filtros['medico'] . '%';
        $params[] = '%' . $filtros['medico'] . '%';
    }

    if (!empty($filtros['motivo'])) {
        $where[] = "e.motivo_exclusion = ?";
        $params[] = $filtros['motivo'];
    }

    if (!empty($filtros['fecha_desde'])) {
        $where[] = "e.fecha_exclusion >= ?";
        $params[] = $filtros['fecha_desde'] . ' 00:00:00';
    }

    if (!empty($filtros['fecha_hasta'])) {
        $where[] = "e.fecha_exclusion <= ?";
        $params[] = $filtros['fecha_hasta'] . ' 23:59:59';
    }

    if (!empty($filtros['search'])) {
        $where[] = "(e.paciente LIKE ? OR e.documento_paciente LIKE ? OR e.cups LIKE ? OR e.examen_nombre LIKE ? OR e.fuente LIKE ? OR e.ingreso LIKE ? OR e.usuario_nombre LIKE ? OR e.detalle_exclusion LIKE ?)";
        $term = '%' . $filtros['search'] . '%';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $term;
        }
    }

    $whereStr = implode(' AND ', $where);
    $sql = "SELECT e.id, e.liquidacion_id, e.periodo_desde, e.periodo_hasta, e.origen, e.evento_id, e.fuente, e.ingreso,
                   e.medico_cedula, e.medico_nombre, e.paciente, e.documento_paciente, e.entidad, e.cups, e.examen_nombre,
                   e.valor_examen, e.valor_a_pagar, e.estado_cruce, e.motivo_exclusion, e.detalle_exclusion,
                   e.usuario_id, e.usuario_nombre, e.usuario_rol, e.fecha_exclusion,
                   l.estado AS liquidacion_estado
            FROM liquidaciones_examenes_excluidos e
            LEFT JOIN liquidaciones_turnos l ON e.liquidacion_id = l.id
            WHERE $whereStr
            ORDER BY e.id DESC";

    $stmt = sqlsrv_query($con, $sql, $params);
    $list = array();

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['fecha_exclusion'] instanceof DateTime) {
                $row['fecha_exclusion'] = $row['fecha_exclusion']->format('Y-m-d H:i:s');
            }
            $row['valor_examen'] = floatval($row['valor_examen'] ?? 0);
            $row['valor_a_pagar'] = floatval($row['valor_a_pagar'] ?? 0);
            $list[] = $row;
        }
    }

    return $list;
}

/**
 * Obtiene la consolidación contable y huellas digitales de una liquidación junto a sus notas de ajuste
 */
function obtenerConsolidacionLiquidacionAjustesBD($liquidacionId, $liqData = null) {
    $con = obtenerConexionLIHO();
    if ($con === false) return null;

    if (!$liqData) {
        $stmtLiq = sqlsrv_query($con, "SELECT id, total_a_pagar, hash_integridad, estado, medico_cedula, medico_nombre, periodo_desde, periodo_hasta FROM liquidaciones_turnos WHERE id = ?", array($liquidacionId));
        if ($stmtLiq && ($r = sqlsrv_fetch_array($stmtLiq, SQLSRV_FETCH_ASSOC))) {
            $liqData = $r;
        }
    }

    if (!$liqData) return null;

    $stmt = sqlsrv_query($con, "SELECT * FROM liquidaciones_notas_ajuste WHERE liquidacion_id = ? ORDER BY id ASC", array($liquidacionId));
    $notas = array();
    $deltaAprobado = 0;
    $deltaPendiente = 0;
    $cadenaHashesAprobadas = array();
    $cadenaHashesTodas = array();

    if ($stmt !== false) {
        while ($n = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($n['fecha_creacion'] instanceof DateTime) {
                $n['fecha_creacion'] = $n['fecha_creacion']->format('Y-m-d H:i:s');
            }
            if (isset($n['fecha_aprobacion']) && $n['fecha_aprobacion'] instanceof DateTime) {
                $n['fecha_aprobacion'] = $n['fecha_aprobacion']->format('Y-m-d H:i:s');
            }
            $val = floatval($n['valor_ajuste'] ?? 0);
            $st = strtoupper(trim($n['estado'] ?? 'PENDIENTE'));
            
            $cadenaHashesTodas[] = "NOTA_{$n['id']}:{$n['hash_integridad']}:{$val}:{$st}";

            if ($st === 'APROBADA') {
                $deltaAprobado += $val;
                $cadenaHashesAprobadas[] = "NOTA_APROB_{$n['id']}:{$n['hash_integridad']}:{$val}";
            } elseif ($st === 'PENDIENTE') {
                $deltaPendiente += $val;
            }
            $notas[] = $n;
        }
    }

    $totalOrig = floatval($liqData['total_a_pagar'] ?? 0);
    $totalConsolidadoAprobado = $totalOrig + $deltaAprobado;
    $totalConsolidadoConPendientes = $totalOrig + $deltaAprobado + $deltaPendiente;
    
    $baseHash = $liqData['hash_integridad'] ?? '';
    if (empty($baseHash)) {
        $baseHash = hash('sha256', "LIQ_ID:{$liquidacionId}|ORIG_TOTAL:{$totalOrig}");
    }

    $tieneAjustes = (count($notas) > 0);
    $tieneAjustesAprobados = ($deltaAprobado != 0 || count($cadenaHashesAprobadas) > 0);

    // Huella Digital Consolidada:
    // Si tiene notas de ajuste, se computa una huella digital compuesta única
    $hashConsolidado = $tieneAjustes
        ? hash('sha256', "LIQ_BASE:{$baseHash}|NOTAS:" . implode(';', $cadenaHashesTodas) . "|TOTAL_CONSOLIDADO:" . number_format($totalConsolidadoAprobado, 2, '.', ''))
        : $baseHash;

    return array(
        'tiene_ajustes'                    => $tieneAjustes,
        'tiene_ajustes_aprobados'          => $tieneAjustesAprobados,
        'total_original'                   => $totalOrig,
        'delta_aprobado'                   => $deltaAprobado,
        'delta_pendiente'                  => $deltaPendiente,
        'total_consolidado_aprobado'       => $totalConsolidadoAprobado,
        'total_consolidado_con_pendientes' => $totalConsolidadoConPendientes,
        'hash_original'                    => $baseHash,
        'hash_consolidado'                 => $hashConsolidado,
        'cantidad_notas'                   => count($notas),
        'notas'                            => $notas
    );
}

/**
 * Obtiene bajo demanda la lista de exámenes de una sede específica para una liquidación
 */
function obtenerExamenesSedeLiquidacionBD($id, $sede) {
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $stmt = sqlsrv_query($con, "SELECT detalles_json FROM liquidaciones_turnos WHERE id = ?", array($id));
    if ($stmt && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        $detalles = json_decode($row['detalles_json'], true) ?: array();
        $sedeBuscada = strtoupper(trim($sede));
        foreach ($detalles as $sKey => $sData) {
            if (strtoupper(trim($sKey)) === $sedeBuscada) {
                return $sData['examenes'] ?? array();
            }
        }
    }
    return array();
}

/**
 * Obtiene los logs de trazabilidad de una liquidación desde la tabla unificada
 */
function obtenerLogsLiquidacionBD($liquidacionId) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $sql = "SELECT id, modulo, registro_id, referencia, accion, estado_anterior, estado_nuevo,
                   usuario_id, usuario_nombre, usuario_rol, observaciones, detalles_json,
                   estado_operacion, hash_integridad, fecha_registro
            FROM sistema_auditoria_logs 
            WHERE (modulo = 'LIQUIDACION' AND registro_id = ?)
               OR (modulo = 'CORREO' AND referencia = ?)
            ORDER BY id ASC";
    $params = array($liquidacionId, 'LIQ-#' . $liquidacionId);
    $stmt = sqlsrv_query($con, $sql, $params);
    $logs = array();

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['fecha_registro'] instanceof DateTime) {
                $row['fecha_registro'] = $row['fecha_registro']->format('Y-m-d H:i:s');
            }
            $logs[] = $row;
        }
    }

    return $logs;
}

/**
 * Verifica la huella de integridad SHA-256 de una liquidación en BD
 */
function verificarIntegridadLiquidacionBD($id) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return array('success' => false, 'error' => 'No fue posible conectar a SQL Server.');

    $sql = "SELECT TOP 1 * FROM liquidaciones_turnos WHERE id = ?";
    $stmt = sqlsrv_query($con, $sql, array($id));
    if ($stmt === false || !($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        return array('success' => false, 'error' => 'Liquidación no encontrada.');
    }

    $hashGuardado = $row['hash_integridad'];
    if (empty($hashGuardado)) {
        return array('success' => false, 'error' => 'Esta liquidación aún no cuenta con huella digital SHA-256 generada (no ha sido aprobada).');
    }

    $payloadRaw = json_encode(array(
        'id'                       => $row['id'],
        'periodo_desde'            => $row['periodo_desde'],
        'periodo_hasta'            => $row['periodo_hasta'],
        'medico_cedula'            => $row['medico_cedula'],
        'medico_nombre'            => $row['medico_nombre'],
        'total_factura'            => floatval($row['total_factura']),
        'total_deducciones'        => floatval($row['total_deducciones']),
        'total_a_pagar'            => floatval($row['total_a_pagar']),
        'usuario_creador_nombre'   => $row['usuario_creador_nombre'],
        'usuario_aprobador_nombre' => $row['usuario_aprobador_nombre'],
        'detalles_json'            => $row['detalles_json']
    ), JSON_UNESCAPED_UNICODE);

    $hashCalculado = hash('sha256', $payloadRaw);
    $esIntegro = ($hashGuardado === $hashCalculado);

    return array(
        'success'        => true,
        'integridad_ok'  => $esIntegro,
        'hash_guardado'  => $hashGuardado,
        'hash_calculado' => $hashCalculado,
        'mensaje'        => $esIntegro 
            ? 'Huella digital SHA-256 verificada con éxito. El registro es 100% auténtico y libre de alteraciones.' 
            : '¡Atención! El código hash no coincide. El registro presenta discrepancias o alteraciones.'
    );
}

/**
 * Obtiene los exámenes individuales y resumen conciliado por sede para una liquidación
 */
function obtenerExamenesSedeLiquidadorBD($idLiquidador, $sedeFiltro = '') {
    if (!function_exists('obtenerConexionProteo') && file_exists(__DIR__ . '/../config/conexion_external.php')) {
        require_once __DIR__ . '/../config/conexion_external.php';
    }
    $itemLiq = obtenerLiquidacionPorIdBD($idLiquidador);
    if (!$itemLiq) return array('success' => false, 'error' => 'Liquidación no encontrada');

    $medicoCedula = trim($itemLiq['medico_cedula'] ?? '');
    $medicoNombre = trim($itemLiq['medico_nombre'] ?? '');
    $fDesde       = trim($itemLiq['periodo_desde'] ?? '') . ' 00:00:00';
    $fHasta       = trim($itemLiq['periodo_hasta'] ?? '') . ' 23:59:59';
    $sedeUpper    = strtoupper(trim($sedeFiltro));

    // Si detalles_json ya contiene examenes estructurados para esa sede, usarlos directamente
    if (!empty($itemLiq['detalles_json'])) {
        $detallesObj = json_decode($itemLiq['detalles_json'], true);
        if (is_array($detallesObj)) {
            foreach ($detallesObj as $sKey => $sVal) {
                $sKeyUpper = strtoupper(trim($sKey));
                if (empty($sedeUpper) || $sKeyUpper === $sedeUpper || strpos($sKeyUpper, $sedeUpper) !== false || strpos($sedeUpper, $sKeyUpper) !== false) {
                    if (is_array($sVal) && !empty($sVal['examenes'])) {
                        return array(
                            'success'  => true,
                            'sede'     => $sKey,
                            'total'    => floatval($sVal['total'] ?? 0),
                            'examenes' => $sVal['examenes']
                        );
                    }
                }
            }
        }
    }

    // De lo contrario, ejecutar la consulta de conciliación idéntica a examenes_medicos.php
    $connProteo   = obtenerConexionProteo();
    $connServinte = obtenerConexionServinte();
    if ($connProteo === false || $connServinte === false) {
        return array('success' => false, 'error' => 'No se pudo conectar a Proteo/Servinte');
    }

    // A. Consultar Proteo
    $paramsProteo = array($fDesde, $fHasta);
    $whereProteo  = "WHERE AE.EventStatusName IN ('Finalizado lectura WL', 'Finalizar ECO', 'Finalizado imagen rechazada')
                     AND AE.IsDeleted = 0
                     AND AE.LastModificationTime >= ?
                     AND AE.LastModificationTime <= ?";

    if (!empty($medicoCedula)) {
        $whereProteo .= " AND (U.UserName = ? OR CONCAT(U.Name, ' ', U.Surname) LIKE ?)";
        $paramsProteo[] = $medicoCedula;
        $paramsProteo[] = '%' . $medicoCedula . '%';
    } elseif (!empty($medicoNombre)) {
        $whereProteo .= " AND CONCAT(U.Name, ' ', U.Surname) LIKE ?";
        $paramsProteo[] = '%' . $medicoNombre . '%';
    }

    $sqlProteo = "
    SELECT 
        AE.Id AS Evento_Id,
        DOC.Value AS Documento_Paciente,
        ENT.Name AS Nombre_Paciente,
        AE.LastModificationTime AS Fecha_Creacion,
        FUEN.Value AS Fuente, 
        VIS.Value AS Ingreso,
        CUP.Value AS CUPS,
        SED.Value AS Sede,
        CONCAT(U.Name, ' ', U.Surname) AS Medico_Usuario,
        U.UserName AS Usuario_Medico
    FROM dbo.AppEvents AE WITH (NOLOCK)
    LEFT JOIN dbo.AppEntities ENT WITH (NOLOCK) ON AE.EntityId = ENT.Id
    INNER JOIN dbo.AbpUsers U WITH (NOLOCK) ON AE.LastModifierUserId = U.Id
    OUTER APPLY (SELECT TOP 1 Value FROM dbo.AppEntityDetails WITH (NOLOCK) WHERE EntityId = AE.EntityId AND Name = 'Número identificación') DOC
    OUTER APPLY (SELECT TOP 1 Value FROM dbo.AppEventDynamicDetails WITH (NOLOCK) WHERE EventId = AE.Id AND [Key] = 'VisitSource' ORDER BY CreationTime DESC) FUEN
    OUTER APPLY (SELECT TOP 1 Value FROM dbo.AppEventDynamicDetails WITH (NOLOCK) WHERE EventId = AE.Id AND [Key] = 'VisitId' ORDER BY LastModificationTime DESC) VIS
    OUTER APPLY (SELECT * FROM (SELECT DISTINCT Value FROM dbo.AppEventDynamicDetails WITH (NOLOCK) WHERE EventId = AE.Id AND [Key] = 'CUPS') C) CUP
    OUTER APPLY (SELECT TOP 1 Value FROM dbo.AppReports WITH (NOLOCK) WHERE EventId = AE.Id AND [Key] = 'Sede' ORDER BY LastModificationTime DESC) SED
    {$whereProteo}
    ";

    $stmtP = sqlsrv_query($connProteo, $sqlProteo, $paramsProteo);
    $proteoItems        = array();
    $pairsByFuenteProteo= array();
    $proteoByFueIng     = array();
    $seenEventCupsHelper= array();

    $extractHelperCups = function($str) {
        if (empty($str)) return '';
        $str = trim((string)$str);
        if (strpos($str, '-') !== false) {
            $parts = explode('-', $str, 2);
            return strtoupper(trim($parts[0]));
        }
        $parts = preg_split('/\s+/', $str, 2);
        return strtoupper(trim($parts[0] ?? ''));
    };

    $extractHelperCupsSecondary = function($str) {
        if (empty($str)) return '';
        $str = trim((string)$str);
        if (strpos($str, '-') !== false) {
            $parts = explode('-', $str, 2);
            $secondPart = trim($parts[1] ?? '');
            $secondTokens = preg_split('/\s+/', $secondPart, 2);
            $sec = strtoupper(trim($secondTokens[0] ?? ''));
            return preg_replace('/[^A-Z0-9]/', '', $sec);
        }
        return '';
    };

    if ($stmtP !== false) {
        while ($row = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC)) {
            $sItem = strtoupper(trim((string)($row['Sede'] ?? '')));
            if (empty($sItem)) $sItem = 'SEDE SIN ESPECIFICAR';

            $matchSede = empty($sedeUpper) || ($sItem === $sedeUpper) || (strpos($sItem, $sedeUpper) !== false) || (strpos($sedeUpper, $sItem) !== false);
            if (!$matchSede) continue;

            $eventId = trim((string)($row['Evento_Id'] ?? ''));
            $fuente  = trim((string)($row['Fuente'] ?? ''));
            $ingreso = trim((string)($row['Ingreso'] ?? ''));
            $cupsRaw = trim((string)($row['CUPS'] ?? ''));

            // Deduplicación por EventId + Código CUPS alfanumérico completo
            $pCode = preg_replace('/[^A-Z0-9]/', '', $extractHelperCups($cupsRaw));
            $pSecCode = preg_replace('/[^A-Z0-9]/', '', $extractHelperCupsSecondary($cupsRaw));

            if ($pCode === 'CPAC' || $pCode === 'CUMO' || $pCode === 'DESC') {
                continue;
            }

            $dedupKey = $eventId . '_' . $pCode;

            if (!empty($eventId) && !empty($pCode) && isset($seenEventCupsHelper[$dedupKey])) {
                continue;
            }
            if (!empty($eventId) && !empty($pCode)) {
                $seenEventCupsHelper[$dedupKey] = true;
            }

            $item = array(
                'id'        => $eventId,
                'paciente'  => trim(($row['Nombre_Paciente'] ?? 'PACIENTE UNIFICADO')),
                'documento' => trim(($row['Documento_Paciente'] ?? '')),
                'fuente'    => $fuente,
                'ingreso'   => $ingreso,
                'cups'      => $cupsRaw,
                'cups_code' => $pCode,
                'cups_sec'  => $pSecCode,
                'sede'      => $sItem,
                'cruce'     => 'SOLO_PROTEO',
                'matched'   => false
            );

            $idx = count($proteoItems);
            $proteoItems[$idx] = $item;

            if (!empty($fuente) && !empty($ingreso)) {
                $fueUpper = strtoupper($fuente);
                $ingClean = ltrim($ingreso, '0');
                if ($ingClean === '') $ingClean = '0';

                $kExact = $fueUpper . '_' . $ingreso;
                $kClean = $fueUpper . '_' . $ingClean;

                $pairsByFuenteProteo[$fuente][] = $ingreso;

                if (!isset($proteoByFueIng[$kExact])) $proteoByFueIng[$kExact] = array();
                $proteoByFueIng[$kExact][] = $idx;

                if ($kClean !== $kExact) {
                    if (!isset($proteoByFueIng[$kClean])) $proteoByFueIng[$kClean] = array();
                    $proteoByFueIng[$kClean][] = $idx;
                }
            }
        }
    }

    // B. Consultar Servinte
    $servinteItems = array();
    if (!empty($pairsByFuenteProteo)) {
        foreach ($pairsByFuenteProteo as $fue => $ingArr) {
            $uniqueIngresos = array_unique(array_filter($ingArr));
            if (empty($uniqueIngresos)) continue;

            $allIngVariations = array();
            foreach ($uniqueIngresos as $ing) {
                $raw   = trim((string)$ing);
                $clean = ltrim($raw, '0');
                if ($clean === '') $clean = '0';
                $baseArr = array($raw, $clean, '0' . $clean, '00' . $clean);
                foreach ($baseArr as $v) {
                    $allIngVariations[] = $v;
                    $allIngVariations[] = str_pad($v, 6);
                    $allIngVariations[] = str_pad($v, 8);
                    $allIngVariations[] = str_pad($v, 10);
                }
            }
            $allIngVariations = array_unique(array_filter($allIngVariations));
            $chunks  = array_chunk($allIngVariations, 300);
            $fueRaw  = trim((string)$fue);
            $fuePad  = str_pad($fueRaw, 6);
            $fuePad8 = str_pad($fueRaw, 8);

            foreach ($chunks as $chunk) {
                $inClause = "'" . implode("','", array_map('addslashes', $chunk)) . "'";
                $sqlServinte = "
                SELECT 
                    NVL(cia.cianom, 'Sin Sede') AS SEDE,
                    mov.movfue AS FUENTE,
                    mov.movdoc AS INGRESO,
                    TRIM(pac.pacnom || ' ' || pac.pacap1 || ' ' || pac.pacap2) AS PACIENTE,
                    pac.pacide AS IDENTIFICACION,
                    det.cardetcon AS CONCEPTO,
                    det.cardetcod AS CODIGO_EXAMEN,
                    NVL(exa.exanom, det.cardetcod) AS EXAMEN,
                    det.cardetcan AS CANTIDAD,
                    det.cardettot AS TOTAL
                FROM aymov mov 
                  INNER JOIN aycardet det ON mov.movfue = det.cardetfue AND mov.movdoc = det.cardetdoc 
                  LEFT JOIN inexa exa ON exa.exacod = det.cardetcod 
                  LEFT JOIN sicia cia ON cia.ciacod = mov.movead 
                  LEFT JOIN abpac pac ON pac.pachis = mov.movhis
                WHERE mov.movfue IN (:fuente, :fuentePad, :fuentePad8)
                  AND mov.movdoc IN ({$inClause})
                  AND (det.cardetcon IS NULL OR UPPER(TRIM(det.cardetcon)) NOT IN ('CPAC', 'CUMO', 'DESC'))
                ";

                $stS = oci_parse($connServinte, $sqlServinte);
                if ($stS) {
                    oci_bind_by_name($stS, ":fuente", $fueRaw);
                    oci_bind_by_name($stS, ":fuentePad", $fuePad);
                    oci_bind_by_name($stS, ":fuentePad8", $fuePad8);
                    if (@oci_execute($stS)) {
                        while ($r = oci_fetch_array($stS, OCI_ASSOC + OCI_RETURN_NULLS)) {
                            $conRaw = strtoupper(trim((string)($r['CONCEPTO'] ?? '')));
                            if ($conRaw === 'CPAC' || $conRaw === 'CUMO' || $conRaw === 'DESC') {
                                continue;
                            }
                            $servinteItems[] = $r;
                        }
                    }
                    oci_free_statement($stS);
                }
            }
        }
    }

    // C. Hacer Cruce
    $examenesFinales = array();
    $totalMonto = 0;

    // 1. Agregar ítems Servinte cruzados
    foreach ($servinteItems as $sItem) {
        $fueStr   = strtoupper(trim($sItem['FUENTE'] ?? ''));
        $ingStr   = trim($sItem['INGRESO'] ?? '');
        $ingClean = ltrim($ingStr, '0');
        if ($ingClean === '') $ingClean = '0';

        $sCodRaw = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)($sItem['CODIGO_EXAMEN'] ?? ''))));
        $nomExa = strtoupper(trim((string)($sItem['EXAMEN'] ?? '')));
        $nomTokens = preg_split('/\s+/', $nomExa, 2);
        $sExaSec = preg_replace('/[^A-Z0-9]/', '', $nomTokens[0] ?? '');

        $kExact = $fueStr . '_' . $ingStr;
        $kClean = $fueStr . '_' . $ingClean;

        $pIndices = $proteoByFueIng[$kExact] ?? ($proteoByFueIng[$kClean] ?? array());
        if (!empty($pIndices)) {
            foreach ($pIndices as $pIdx) {
                if (!$proteoItems[$pIdx]['matched']) {
                    $pItemRef = $proteoItems[$pIdx];
                    $pCode   = $pItemRef['cups_code'] ?? '';
                    $pSecCode= $pItemRef['cups_sec'] ?? '';

                    $isMatch = false;
                    if (!empty($pCode) && !empty($sCodRaw) && $pCode === $sCodRaw) {
                        $isMatch = true;
                    } elseif (!empty($pSecCode) && !empty($sCodRaw) && $pSecCode === $sCodRaw) {
                        $isMatch = true;
                    } elseif (!empty($pSecCode) && !empty($sExaSec) && $pSecCode === $sExaSec) {
                        $isMatch = true;
                    } elseif (!empty($pCode) && !empty($sExaSec) && $pCode === $sExaSec) {
                        $isMatch = true;
                    }

                    if ($isMatch) {
                        $proteoItems[$pIdx]['matched'] = true;
                        break;
                    }
                }
            }
        }

        $tot = floatval($sItem['TOTAL'] ?? 0);
        $totalMonto += $tot;

        $examenesFinales[] = array(
            'paciente'      => trim($sItem['PACIENTE'] ?? 'PACIENTE SERVINTE'),
            'documento'     => trim($sItem['IDENTIFICACION'] ?? ''),
            'examen'        => trim($sItem['CONCEPTO'] ?? ($sItem['EXAMEN'] ?? 'EXAMEN')),
            'cups'          => trim($sItem['CODIGO_EXAMEN'] ?? ''),
            'ingreso'       => $ingStr,
            'valor_a_pagar' => $tot,
            'cruce'         => 'CRUZADO'
        );
    }

    // 2. Agregar ítems Proteo no cruzados (SOLO_PROTEO)
    foreach ($proteoItems as $pItem) {
        if (!$pItem['matched']) {
            $valP = 5800;
            if (strpos(strtolower($pItem['cups']), 'a73420') !== false) $valP = 8200;

            $totalMonto += $valP;
            $examenesFinales[] = array(
                'paciente'      => $pItem['paciente'],
                'documento'     => $pItem['documento'],
                'examen'        => $pItem['cups'] ? $pItem['cups'] : 'RADIOGRAFÍA / EXAMEN MÉDICO',
                'cups'          => $pItem['cups'],
                'ingreso'       => $pItem['ingreso'],
                'valor_a_pagar' => $valP,
                'cruce'         => 'SOLO_PROTEO'
            );
        }
    }

    return array(
        'success'  => true,
        'sede'     => $sedeUpper,
        'total'    => $totalMonto,
        'examenes' => $examenesFinales
    );
}

// =========================================================================
// FUNCIONES PARA NOTAS DE AJUSTE (NOTAS DÉBITO / CRÉDITO / REAJUSTE)
// =========================================================================

/**
 * Calcula el hash SHA-256 de integridad para una nota de ajuste
 */
function calcularHashNotaAjuste($row) {
    $str = ($row['id'] ?? '') . '|' .
           ($row['numero_nota'] ?? '') . '|' .
           ($row['liquidacion_id'] ?? '') . '|' .
           ($row['medico_cedula'] ?? '') . '|' .
           floatval($row['total_original'] ?? 0) . '|' .
           floatval($row['valor_ajuste'] ?? 0) . '|' .
           floatval($row['total_ajustado'] ?? 0) . '|' .
           ($row['estado'] ?? '') . '|' .
           ($row['fecha_creacion'] ?? '');
    return hash('sha256', $str);
}

/**
 * Registra una nueva Nota de Ajuste vinculada a una liquidación previa
 */
function guardarNotaAjusteBD($data, $usuarioId, $usuarioNombre, $usuarioRol) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) {
        return array('success' => false, 'error' => 'No fue posible conectar a la base de datos SQL Server.');
    }

    $liquidacionId = intval($data['liquidacion_id'] ?? 0);
    if ($liquidacionId <= 0) {
        return array('success' => false, 'error' => 'Debe seleccionar una liquidación original válida para asociar el ajuste.');
    }

    // Obtener datos de la liquidación original
    $liqOriginal = obtenerLiquidacionPorIdBD($liquidacionId, false);
    if (!$liqOriginal) {
        return array('success' => false, 'error' => 'La liquidación original indicada (ID #' . $liquidacionId . ') no existe.');
    }

    if (strtoupper($liqOriginal['estado'] ?? '') !== 'APROBADA') {
        return array('success' => false, 'error' => 'Solo se pueden emitir notas de ajuste sobre liquidaciones en estado APROBADA.');
    }

    $medicoCedula = $liqOriginal['medico_cedula'] ?? '';
    $medicoNombre = $liqOriginal['medico_nombre'] ?? '';
    $periodoDesde = $liqOriginal['periodo_desde'] ?? '';
    $periodoHasta = $liqOriginal['periodo_hasta'] ?? '';
    $totalOriginal = floatval($liqOriginal['total_a_pagar'] ?? 0);

    $tipoNota      = strtoupper(trim($data['tipo_nota'] ?? 'CREDITO')); // CREDITO (+), DEBITO (-)
    $motivoAjuste  = trim($data['motivo_ajuste'] ?? '');
    $valorAjuste   = floatval($data['valor_ajuste'] ?? 0);
    $detallesJson  = is_string($data['detalles_ajuste_json'] ?? '') ? $data['detalles_ajuste_json'] : json_encode($data['detalles_ajuste_json'] ?? array());

    if (empty($motivoAjuste)) {
        return array('success' => false, 'error' => 'El motivo o justificación del ajuste es obligatorio para fines de auditoría.');
    }

    // Manejo de Corrección de % de Retención por equivocación
    $modificoRete  = !empty($data['modifico_retencion']);
    $pctReteOrig   = floatval($data['retencion_pct_original'] ?? 0);
    $pctReteNuevo  = floatval($data['retencion_pct_nuevo'] ?? 0);
    $anotacionRete = trim($data['anotacion_retencion'] ?? '');

    if ($modificoRete) {
        $motivoAjuste .= " | [CORRECCIÓN % RETENCIÓN: Modificado de {$pctReteOrig}% a {$pctReteNuevo}%. Justificación: {$anotacionRete}]";
    }

    // Si es tipo DEBITO y el valor viene positivo, lo convertimos a negativo para el delta contable
    if ($tipoNota === 'DEBITO' && $valorAjuste > 0) {
        $valorAjuste = -$valorAjuste;
    }

    $totalAjustado = $totalOriginal + $valorAjuste;

    $subtotalBruto = floatval($data['subtotal_bruto'] ?? abs($valorAjuste));
    $deduccionesAjuste = floatval($data['deducciones_ajuste'] ?? 0);

    // Insertar la nota
    $sqlInsert = "INSERT INTO liquidaciones_notas_ajuste (
        numero_nota, liquidacion_id, medico_cedula, medico_nombre, periodo_desde, periodo_hasta,
        tipo_nota, motivo_ajuste, total_original, valor_ajuste, total_ajustado, detalles_ajuste_json,
        subtotal_bruto, deducciones_ajuste,
        estado, fecha_creacion, usuario_creador_id, usuario_creador_nombre
    ) VALUES (
        'TEMP', ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?, ?,
        'PENDIENTE', GETDATE(), ?, ?
    ); SELECT SCOPE_IDENTITY() AS id;";

    $params = array(
        $liquidacionId, $medicoCedula, $medicoNombre, $periodoDesde, $periodoHasta,
        $tipoNota, $motivoAjuste, $totalOriginal, $valorAjuste, $totalAjustado, $detallesJson,
        $subtotalBruto, $deduccionesAjuste,
        $usuarioId, $usuarioNombre
    );

    $stmt = sqlsrv_query($con, $sqlInsert, $params);
    if ($stmt === false) {
        $errors = print_r(sqlsrv_errors(), true);
        return array('success' => false, 'error' => 'Error al registrar la nota de ajuste: ' . $errors);
    }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $newId = $row['id'] ?? 0;

    if ($newId > 0) {
        $numeroNota = 'NA-' . str_pad($newId, 4, '0', STR_PAD_LEFT);
        
        $stmtFetch = sqlsrv_query($con, "SELECT * FROM liquidaciones_notas_ajuste WHERE id = ?", array($newId));
        if ($stmtFetch && ($rowFetch = sqlsrv_fetch_array($stmtFetch, SQLSRV_FETCH_ASSOC))) {
            if ($rowFetch['fecha_creacion'] instanceof DateTime) {
                $rowFetch['fecha_creacion'] = $rowFetch['fecha_creacion']->format('Y-m-d H:i:s');
            }
            $rowFetch['numero_nota'] = $numeroNota;
            $hash = calcularHashNotaAjuste($rowFetch);
            @sqlsrv_query($con, "UPDATE liquidaciones_notas_ajuste SET numero_nota = ?, hash_integridad = ? WHERE id = ?", array($numeroNota, $hash, $newId));
        }

        // Registrar Log de Creación en la tabla unificada
        registrarLogNotaAjusteBD($newId, 'CREACIÓN', null, 'PENDIENTE', $usuarioId, $usuarioNombre, $usuarioRol, 'Nota de Ajuste registrada para la Liquidación #' . $liquidacionId . ': ' . $motivoAjuste);

        // Si se corrigió el porcentaje de retención, registrar eventos dedicados en logs de auditoría
        if ($modificoRete) {
            // 1. Log específico de la Nota de Ajuste
            registrarLogAuditoriaUniversal(
                'NOTA_AJUSTE',
                $newId,
                $numeroNota,
                'CORRECCION_PORCENTAJE_RETENCION',
                "Retención: {$pctReteOrig}%",
                "Retención: {$pctReteNuevo}%",
                $usuarioId,
                $usuarioNombre,
                $usuarioRol,
                "Corrección manual de % de retención por equivocación: {$anotacionRete}",
                json_encode(array(
                    'pct_original' => $pctReteOrig,
                    'pct_nuevo' => $pctReteNuevo,
                    'anotacion' => $anotacionRete,
                    'liquidacion_id' => $liquidacionId
                ))
            );

            // 2. Log en el módulo central de logs (dbo.logs_sistema)
            require_once __DIR__ . '/logger_helper.php';
            if (function_exists('registrar_log_sistema')) {
                registrar_log_sistema(
                    'NOTAS_AJUSTE',
                    'CORRECCION_RETENCION',
                    'MODIFICACION',
                    'WARNING',
                    "Liquidación #{$liquidacionId} / {$numeroNota}",
                    "Retención: {$pctReteOrig}%",
                    "Retención: {$pctReteNuevo}%",
                    "Se corrigió el % de retención por equivocación de {$pctReteOrig}% a {$pctReteNuevo}%. Anotación: {$anotacionRete}"
                );
            }
        }

        // Enviar notificación por correo con copia a dirección médica
        @notificarNotaAjustePorCorreo($newId, 'CREADA');

        return array('success' => true, 'id' => $newId, 'numero_nota' => $numeroNota);
    }

    return array('success' => false, 'error' => 'No se generó el identificador de la nota de ajuste.');
}

/**
 * Consulta la lista de Notas de Ajuste con filtros
 */
function obtenerNotasAjusteBD($filtroMes = '', $medicoCedula = '', $estado = '') {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $where = array();
    $params = array();

    if (!empty($filtroMes)) {
        $where[] = "(periodo_desde LIKE ? OR fecha_creacion LIKE ?)";
        $params[] = $filtroMes . '%';
        $params[] = $filtroMes . '%';
    }

    if (!empty($medicoCedula)) {
        $where[] = "(medico_cedula = ? OR medico_nombre LIKE ?)";
        $params[] = $medicoCedula;
        $params[] = '%' . $medicoCedula . '%';
    }

    if (!empty($estado) && $estado !== 'TODOS') {
        $where[] = "estado = ?";
        $params[] = $estado;
    }

    $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT id, numero_nota, liquidacion_id, medico_cedula, medico_nombre, periodo_desde, periodo_hasta,
                   tipo_nota, motivo_ajuste, total_original, valor_ajuste, total_ajustado,
                   estado, fecha_creacion, usuario_creador_id, usuario_creador_nombre,
                   usuario_aprobador_id, usuario_aprobador_nombre, fecha_aprobacion, hash_integridad
            FROM liquidaciones_notas_ajuste
            $whereSql
            ORDER BY id DESC";

    $stmt = sqlsrv_query($con, $sql, $params);
    $list = array();

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['fecha_creacion'] instanceof DateTime) {
                $row['fecha_creacion'] = $row['fecha_creacion']->format('Y-m-d H:i:s');
            }
            if (isset($row['fecha_aprobacion']) && $row['fecha_aprobacion'] instanceof DateTime) {
                $row['fecha_aprobacion'] = $row['fecha_aprobacion']->format('Y-m-d H:i:s');
            }
            $list[] = $row;
        }
    }

    return $list;
}

/**
 * Obtiene el detalle completo de una Nota de Ajuste por su ID
 */
function obtenerNotaAjustePorIdBD($id) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return null;

    $sql = "SELECT * FROM liquidaciones_notas_ajuste WHERE id = ?";
    $stmt = sqlsrv_query($con, $sql, array($id));

    if ($stmt !== false && ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
        if ($row['fecha_creacion'] instanceof DateTime) {
            $row['fecha_creacion'] = $row['fecha_creacion']->format('Y-m-d H:i:s');
        }
        if (isset($row['fecha_aprobacion']) && $row['fecha_aprobacion'] instanceof DateTime) {
            $row['fecha_aprobacion'] = $row['fecha_aprobacion']->format('Y-m-d H:i:s');
        }

        // Obtener datos resumidos de la liquidación original asociada
        $row['liquidacion_original'] = obtenerLiquidacionPorIdBD($row['liquidacion_id'], false);
        $row['logs'] = obtenerLogsNotaAjusteBD($id);
        return $row;
    }

    return null;
}

/**
 * Cambia el estado de una Nota de Ajuste ('APROBADA' o 'ANULADA')
 */
function cambiarEstadoNotaAjusteBD($id, $nuevoEstado, $usuarioId, $usuarioNombre, $usuarioRol, $observaciones = '') {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) {
        return array('success' => false, 'error' => 'No fue posible conectar a la base de datos SQL Server.');
    }

    $nota = obtenerNotaAjustePorIdBD($id);
    if (!$nota) {
        return array('success' => false, 'error' => 'La nota de ajuste no existe.');
    }

    $estadoAnterior = $nota['estado'];
    if ($estadoAnterior === $nuevoEstado) {
        return array('success' => false, 'error' => 'La nota de ajuste ya se encuentra en estado ' . $nuevoEstado . '.');
    }

    $sqlUpd = "UPDATE liquidaciones_notas_ajuste 
               SET estado = ?, usuario_aprobador_id = ?, usuario_aprobador_nombre = ?, fecha_aprobacion = GETDATE() 
               WHERE id = ?";
    $stmtUpd = sqlsrv_query($con, $sqlUpd, array($nuevoEstado, $usuarioId, $usuarioNombre, $id));

    if ($stmtUpd === false) {
        $errors = print_r(sqlsrv_errors(), true);
        return array('success' => false, 'error' => 'Error al actualizar el estado: ' . $errors);
    }

    // Actualizar hash de integridad con nuevo estado
    $stmtFetch = sqlsrv_query($con, "SELECT * FROM liquidaciones_notas_ajuste WHERE id = ?", array($id));
    if ($stmtFetch && ($rowFetch = sqlsrv_fetch_array($stmtFetch, SQLSRV_FETCH_ASSOC))) {
        if ($rowFetch['fecha_creacion'] instanceof DateTime) {
            $rowFetch['fecha_creacion'] = $rowFetch['fecha_creacion']->format('Y-m-d H:i:s');
        }
        $nuevoHash = calcularHashNotaAjuste($rowFetch);
        @sqlsrv_query($con, "UPDATE liquidaciones_notas_ajuste SET hash_integridad = ? WHERE id = ?", array($nuevoHash, $id));
    }

    // Registrar log en tabla unificada
    $accion = ($nuevoEstado === 'APROBADA') ? 'APROBACIÓN' : (($nuevoEstado === 'ANULADA') ? 'ANULACIÓN' : 'CAMBIO_ESTADO');
    registrarLogNotaAjusteBD($id, $accion, $estadoAnterior, $nuevoEstado, $usuarioId, $usuarioNombre, $usuarioRol, $observaciones);

    // Enviar notificación por correo con copia a dirección médica
    @notificarNotaAjustePorCorreo($id, $nuevoEstado);

    return array('success' => true, 'mensaje' => 'Nota de Ajuste ' . $nuevoEstado . ' exitosamente.', 'nuevo_estado' => $nuevoEstado);
}

/**
 * Registra un log de auditoría para una Nota de Ajuste en la tabla unificada
 */
function registrarLogNotaAjusteBD($notaId, $accion, $estadoAnterior, $estadoNuevo, $usuarioId, $usuarioNombre, $usuarioRol, $observaciones = '') {
    $str = $notaId . '|' . $accion . '|' . $estadoAnterior . '|' . $estadoNuevo . '|' . $usuarioId . '|' . date('Y-m-d H:i:s');
    $hash = hash('sha256', $str);

    return registrarLogAuditoriaUniversal(
        'NOTA_AJUSTE',
        $notaId,
        'NA-#' . $notaId,
        $accion,
        $estadoAnterior,
        $estadoNuevo,
        $usuarioId,
        $usuarioNombre,
        $usuarioRol,
        $observaciones,
        null,
        'EXITOSO',
        $hash
    );
}

/**
 * Obtiene los logs de trazabilidad de una Nota de Ajuste desde la tabla unificada
 */
function obtenerLogsNotaAjusteBD($notaId) {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $sql = "SELECT id, modulo, registro_id, referencia, accion, estado_anterior, estado_nuevo,
                   usuario_id, usuario_nombre, usuario_rol, observaciones, detalles_json,
                   estado_operacion, hash_integridad, fecha_registro
            FROM sistema_auditoria_logs 
            WHERE (modulo = 'NOTA_AJUSTE' AND registro_id = ?)
               OR (modulo = 'CORREO' AND referencia = ?)
            ORDER BY id ASC";
    $params = array($notaId, 'NA-#' . $notaId);
    $stmt = sqlsrv_query($con, $sql, $params);
    $logs = array();

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['fecha_registro'] instanceof DateTime) {
                $row['fecha_registro'] = $row['fecha_registro']->format('Y-m-d H:i:s');
            }
            $logs[] = $row;
        }
    }

    return $logs;
}

/**
 * Envía una notificación formal por correo electrónico al médico y copia a la dirección médica
 * Adjuntando el Reporte PDF oficial con diseño corporativo y el archivo Excel con la consulta detallada de exámenes
 */
function notificarLiquidacionPorCorreo($liquidacionId, $tipoEvento = 'APROBADA', $liqData = null) {
    $liq = $liqData ?: obtenerLiquidacionPorIdBD($liquidacionId, true);
    if (!$liq) return false;

    // Configuración estricta de correos de desarrollo y pruebas
    $correoMedicoPrueba = 'desarrollo@hernanocazionez.com';
    $correoDirMedica    = 'coordinacionsistemas@hernanocazionez.com.co';
    $correoCopiaDev     = 'juane6462@gmail.com';

    // Durante fase de desarrollo/pruebas se utiliza el médico de prueba
    $correoMedico = obtenerEmailMedicoPorCedula($liq['medico_cedula']);
    $destinatarioPrincipal = (strtolower(trim($correoMedico ?? '')) === 'desarrollo@hernanocazionez.com') ? $correoMedico : $correoMedicoPrueba;
    $copiasCC = array($correoDirMedica, $correoCopiaDev);

    $logoPath = __DIR__ . '/../assets/img/logo_fondo_osc_hd.png';
    if (!file_exists($logoPath)) $logoPath = __DIR__ . '/../assets/img/Logo fondo oscuro.png';
    if (!file_exists($logoPath)) $logoPath = __DIR__ . '/../assets/img/Ho_Fondo_Osc.png';
    $embeddedImages = file_exists($logoPath) ? array('logo_liho' => $logoPath) : array();

    $medicoNombre = htmlspecialchars($liq['medico_nombre'] ?? 'Profesional');
    $medicoCedula = htmlspecialchars($liq['medico_cedula'] ?? 'N/A');
    $periodo = htmlspecialchars(($liq['periodo_desde'] ?? '') . ' al ' . ($liq['periodo_hasta'] ?? ''));
    $totalAPagar = number_format(floatval($liq['total_a_pagar'] ?? 0), 2, ',', '.');
    $totalFactura = number_format(floatval($liq['total_factura'] ?? 0), 2, ',', '.');
    $totalDeducciones = number_format(floatval($liq['total_deducciones'] ?? 0), 2, ',', '.');
    $hashIntegridad = htmlspecialchars($liq['hash_integridad'] ?? 'PENDIENTE_GENERACION');
    $fechaEmision = date('d/m/Y h:i A');

    // Desglose de sedes para tabla HTML en correo
    $sedesObj = array();
    if (!empty($liq['resumen_sedes_json'])) {
        $sedesObj = is_array($liq['resumen_sedes_json']) ? $liq['resumen_sedes_json'] : (json_decode($liq['resumen_sedes_json'], true) ?: array());
    } elseif (!empty($liq['detalles_json'])) {
        $sedesObj = json_decode(generarResumenSedesJSON($liq['detalles_json']), true) ?: array();
    }

    $sedesHtmlRows = '';
    foreach ($sedesObj as $sedeNom => $sData) {
        $valSede = floatval($sData['total'] ?? 0);
        $sedesHtmlRows .= '
            <tr style="border-bottom: 1px solid #e2e8f0; font-size: 12px;">
                <td style="padding: 6px 10px; font-weight: bold; color: #1e293b;">' . htmlspecialchars(strtoupper($sedeNom)) . '</td>
                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 600; color: #0f172a;">$ ' . number_format($valSede, 2, ',', '.') . '</td>
            </tr>
        ';
    }

    // Deducciones para tabla HTML en correo
    $deduccionesHtmlRows = '';
    $dedList = array();

    $ibc = floatval($liq['ded_ibc'] ?? 0);
    if ($ibc > 0) {
        $dedList['IBC Mes (Estimado)'] = $ibc;
    }

    $salud = floatval($liq['ded_salud'] ?? 0);
    if ($salud > 0) {
        $dedList['Menos Aportes Salud Mes'] = -$salud;
    }

    $pension = floatval($liq['ded_pension'] ?? 0);
    if ($pension > 0) {
        $dedList['Menos Aportes Pensión Mes'] = -$pension;
    }

    $arl = floatval($liq['ded_arl'] ?? 0);
    if ($arl > 0) {
        $dedList['Menos Aportes ARL Mes'] = -$arl;
    }

    $rete383 = floatval($liq['ded_rete_383'] ?? 0);
    $retencion = floatval($liq['ded_retencion'] ?? 0);
    $retPct = floatval($liq['ded_retencion_pct'] ?? 0);

    if ($rete383 > 0) {
        $dedList['Menos Retención Art 383'] = -$rete383;
    } elseif ($retencion > 0) {
        $lblRet = ($retPct > 0) ? "Menos Retención ({$retPct}%)" : 'Menos Retención en la Fuente';
        $dedList[$lblRet] = -$retencion;
    }

    $afc = floatval($liq['ded_afc'] ?? 0);
    if ($afc > 0) {
        $dedList['Menos Aportes AFC'] = -$afc;
    }

    $solidaridad = floatval($liq['ded_solidaridad'] ?? 0);
    if ($solidaridad > 0) {
        $dedList['Menos Fondo Solidaridad'] = -$solidaridad;
    }

    foreach ($dedList as $dNom => $dVal) {
        $esNeg = ($dVal < 0);
        $deduccionesHtmlRows .= '
            <tr style="border-bottom: 1px solid #f1f5f9; font-size: 11px;">
                <td style="padding: 4px 8px; color: #475569;">' . htmlspecialchars($dNom) . '</td>
                <td style="padding: 4px 8px; text-align: right; font-family: monospace; font-weight: 600; color: ' . ($esNeg ? '#e11d48' : '#0f172a') . ';">' . ($esNeg ? '- ' : '') . '$ ' . number_format(abs($dVal), 2, ',', '.') . '</td>
            </tr>
        ';
    }

    $asunto = "[LIHO] Liquidación de Honorarios Médicos #" . $liquidacionId . " - " . strtoupper($tipoEvento);

    $safeMedico = preg_replace('/[^a-zA-Z0-9_-]/', '_', $liq['medico_nombre'] ?? 'MEDICO');
    $nombrePdf = "Reporte_Liquidacion_{$liquidacionId}_{$safeMedico}.pdf";
    $nombreExcel = "Consulta_Examenes_Liquidacion_{$liquidacionId}_{$safeMedico}.csv";

    // 1. Generar Reporte PDF Oficial (Reutiliza $liq en memoria)
    $pdfData = generarPDFLiquidacion($liq);

    // 2. Generar Archivo Excel (CSV con UTF-8 BOM, reutiliza $liq en memoria)
    $excelData = generarExcelLiquidacion($liq);

    $attachments = array();
    if (!empty($pdfData)) {
        $attachments[] = array('name' => $nombrePdf, 'data' => $pdfData, 'type' => 'application/pdf');
    }
    if (!empty($excelData)) {
        $attachments[] = array('name' => $nombreExcel, 'data' => $excelData, 'type' => 'text/csv; charset=UTF-8');
    }

    $bodyHtml = '
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>[LIHO] Liquidación de Honorarios</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f1f5f9; margin: 0; padding: 16px 12px; color: #1e293b; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
            .wrapper { width: 100%; max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.07); border: 1px solid #e2e8f0; }
            .header { background: #0f172a; padding: 28px 20px 22px 20px; text-align: center; color: #ffffff; }
            .header p { margin: 10px 0 0 0; font-size: 12px; color: #00c1be; font-weight: 800; text-transform: uppercase; letter-spacing: 0.8px; }
            .content { padding: 24px 20px; }
            .badge-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; }
            .badge-liq { font-size: 14px; font-weight: 900; color: #0f172a; }
            .badge-aprobado { display: inline-block; padding: 4px 12px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 999px; font-size: 10px; font-weight: 900; letter-spacing: 0.5px; }
            .box-attachments { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 14px; padding: 14px 16px; margin: 18px 0; }
            .att-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; flex-wrap: wrap; }
            .att-item:last-child { border-bottom: none; }
            .att-tag { font-weight: 900; font-size: 11px; padding: 2px 6px; border-radius: 6px; margin-right: 4px; }
            .att-pdf { background: #e0f2fe; color: #0369a1; }
            .att-xls { background: #dcfce7; color: #166534; }
            .att-name { font-weight: 700; color: #0f172a; word-break: break-all; font-size: 11px; }
            .hash-card { background: #0f172a; border-radius: 14px; padding: 14px; margin: 18px 0; }
            .hash-title { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 6px; display: block; }
            .hash-code { font-family: Consolas, monospace; font-size: 10px; color: #38bdf8; word-break: break-all; overflow-wrap: anywhere; line-height: 1.4; }
            .footer { text-align: center; font-size: 10px; color: #94a3b8; padding: 16px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; line-height: 1.5; }
            @media only screen and (max-width: 480px) {
                body { padding: 8px 4px; }
                .content { padding: 16px 14px; }
            }
        </style>
    </head>
    <body>
        <div class="wrapper">
            <div class="header">
                ' . (!empty($embeddedImages) ? '<img src="cid:logo_liho" alt="Hernán Ocazionez" style="max-width: 480px; width: 92%; height: auto; display: block; margin: 0 auto 10px auto;" /><br>' : '') . '
                <p>LIHO - LIQUIDACIÓN DE HONORARIOS</p>
            </div>

            <div class="content">
                <div class="badge-bar">
                    <span class="badge-liq">Liquidación</span>
                    <span class="badge-aprobado">APROBADA</span>
                </div>

                <p style="font-size: 13px; margin: 0 0 8px 0; color: #0f172a;">Apreciado(a) <strong>' . $medicoNombre . '</strong>,</p>
                <p style="font-size: 12px; color: #475569; line-height: 1.5; margin: 0 0 16px 0;">
                    Nos complace informarle que su liquidación de honorarios correspondiente al periodo <strong>' . $periodo . '</strong> ha sido formalmente <strong>APROBADA</strong> y certificada en la plataforma LIHO.
                </p>

                <!-- Documentos Adjuntos -->
                <div class="box-attachments">
                    <span style="font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase; display: block; margin-bottom: 8px;">Documentos Oficiales Adjuntos:</span>
                    
                    <div class="att-item">
                        <div>
                            <span class="att-tag att-pdf">PDF</span>
                            <span class="att-name">' . htmlspecialchars($nombrePdf) . '</span>
                        </div>
                        <span style="font-size: 10px; color: #64748b;">Reporte Oficial con Diseño</span>
                    </div>

                    <div class="att-item">
                        <div>
                            <span class="att-tag att-xls">EXCEL</span>
                            <span class="att-name">' . htmlspecialchars($nombreExcel) . '</span>
                        </div>
                        <span style="font-size: 10px; color: #64748b;">Consulta Detallada de Exámenes</span>
                    </div>
                </div>

                <!-- Firma Digital SHA-256 -->
                <div class="hash-card">
                    <span class="hash-title">FIRMA DIGITAL DE INTEGRIDAD CRIPTOGRÁFICA (SHA-256):</span>
                    <div class="hash-code">' . $hashIntegridad . '</div>
                </div>
            </div>

            <div class="footer">
                Fecha de Aprobación: ' . $fechaEmision . '<br>
                © ' . date('Y') . ' IPS Hernán Ocazionez y Cía S.A.S. - Todos los derechos reservados.
            </div>
        </div>
    </body>
    </html>
    ';

    $enviado = enviarCorreoSMTP($destinatarioPrincipal, $asunto, $bodyHtml, null, $embeddedImages, '', $copiasCC, $attachments);
    $estadoStr = $enviado ? 'EXITOSO' : 'FALLIDO';
    $ccTexto = implode(', ', $copiasCC);

    // Registrar en tabla unificada sistema_auditoria_logs
    registrarLogAuditoriaUniversal(
        'CORREO',
        $liquidacionId,
        'LIQ-#' . $liquidacionId,
        'ENVIO_NOTIFICACION_LIQUIDACION',
        null,
        $estadoStr,
        $_SESSION['usuario_id'] ?? null,
        $_SESSION['usuario_nombre'] ?? 'Sistema Notificaciones',
        $_SESSION['rol'] ?? 'SISTEMA',
        'Notificación de liquidación enviada a ' . $destinatarioPrincipal . ' con copia a ' . $ccTexto . ' y adjuntos (' . $nombrePdf . ', ' . $nombreExcel . ') [' . $estadoStr . ']',
        array('destinatario' => $destinatarioPrincipal, 'cc' => $copiasCC, 'asunto' => $asunto, 'adjuntos' => array($nombrePdf, $nombreExcel), 'estado' => $estadoStr),
        $estadoStr,
        $hashIntegridad
    );

    return $enviado;
}

/**
 * Envía una notificación formal por correo electrónico al médico y copia a la dirección médica sobre una Nota de Ajuste
 */
function notificarNotaAjustePorCorreo($notaId, $tipoEvento = 'CREADA') {
    $nota = obtenerNotaAjustePorIdBD($notaId);
    if (!$nota) return false;

    // Configuración estricta de correos de desarrollo y pruebas
    $correoMedicoPrueba = 'desarrollo@hernanocazionez.com';
    $correoDirMedica    = 'coordinacionsistemas@hernanocazionez.com.co';
    $correoCopiaDev     = 'juane6462@gmail.com';

    // Durante fase de desarrollo/pruebas se utiliza el médico de prueba
    $correoMedico = obtenerEmailMedicoPorCedula($nota['medico_cedula']);
    $destinatarioPrincipal = (strtolower(trim($correoMedico ?? '')) === 'desarrollo@hernanocazionez.com') ? $correoMedico : $correoMedicoPrueba;
    $copiasCC = array($correoDirMedica, $correoCopiaDev);

    $logoPath = __DIR__ . '/../assets/img/logo_email_optimized.png';
    if (!file_exists($logoPath)) $logoPath = __DIR__ . '/../assets/img/hologo.png';
    $embeddedImages = file_exists($logoPath) ? array('logo_liho' => $logoPath) : array();

    $numeroNota = htmlspecialchars($nota['numero_nota'] ?? 'NA-' . $notaId);
    $medicoNombre = htmlspecialchars($nota['medico_nombre'] ?? 'Profesional');
    $medicoCedula = htmlspecialchars($nota['medico_cedula'] ?? 'N/A');
    $periodo = htmlspecialchars(($nota['periodo_desde'] ?? '') . ' al ' . ($nota['periodo_hasta'] ?? ''));
    $tipoNota = strtoupper($nota['tipo_nota'] ?? 'CREDITO');
    $esCredito = ($tipoNota === 'CREDITO' || floatval($nota['valor_ajuste']) > 0);
    $valorAjuste = number_format(abs(floatval($nota['valor_ajuste'] ?? 0)), 2, ',', '.');
    $totalOriginal = number_format(floatval($nota['total_original'] ?? 0), 2, ',', '.');
    $totalAjustado = number_format(floatval($nota['total_ajustado'] ?? 0), 2, ',', '.');
    $motivoAjuste = htmlspecialchars($nota['motivo_ajuste'] ?? '');
    $hashIntegridad = htmlspecialchars($nota['hash_integridad'] ?? 'PENDIENTE');
    $fechaEmision = date('d/m/Y h:i A');

    $items = array();
    try {
        $items = is_string($nota['detalles_ajuste_json'] ?? '') ? json_decode($nota['detalles_ajuste_json'], true) : ($nota['detalles_ajuste_json'] ?? array());
    } catch(Exception $e) {}

    $itemsRowsHtml = '';
    if (is_array($items)) {
        foreach ($items as $it) {
            $tipoPac = (strtoupper($it['tipo_paciente'] ?? '') === 'P' || strtoupper($it['tipo_paciente'] ?? '') === 'PARTICULAR') ? 'Particular' : 'Empresa';
            $cups = !empty($it['cups']) ? ('[' . htmlspecialchars($it['cups']) . '] ') : '';
            $itemsRowsHtml .= '
                <tr style="border-bottom: 1px solid #e2e8f0; font-size: 12px;">
                    <td style="padding: 6px 8px; font-weight: bold;">' . htmlspecialchars($it['sede'] ?? 'GENERAL') . '</td>
                    <td style="padding: 6px 8px;">' . $tipoPac . '</td>
                    <td style="padding: 6px 8px;">' . $cups . htmlspecialchars($it['concepto'] ?? '') . '</td>
                    <td style="padding: 6px 8px; text-align: center;">' . intval($it['cantidad'] ?? 1) . '</td>
                    <td style="padding: 6px 8px; text-align: right; font-family: monospace;">$ ' . number_format(floatval($it['valor_unitario'] ?? 0), 2, ',', '.') . '</td>
                    <td style="padding: 6px 8px; text-align: right; font-weight: bold; font-family: monospace;">$ ' . number_format(floatval($it['subtotal'] ?? 0), 2, ',', '.') . '</td>
                </tr>
            ';
        }
    }

    $asunto = "[LIHO] Nota de Ajuste " . $numeroNota . " (" . $tipoNota . ") - Liquidación #" . $nota['liquidacion_id'];

    $bodyHtml = '
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>[LIHO] Nota de Ajuste ' . $numeroNota . '</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #0f172a; margin: 0; padding: 12px; color: #1e293b; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
            .wrapper { width: 100%; max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.25); border: 1px solid #e2e8f0; }
            .header { background: #0f172a; padding: 24px 20px; text-align: center; color: #ffffff; }
            .header h2 { margin: 0; font-size: 16px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; color: #ffffff; }
            .header p { margin: 4px 0 0 0; font-size: 11px; color: #00c1be; font-weight: 700; }
            .content { padding: 24px 20px; }
            .badge-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px solid #f1f5f9; padding-bottom: 12px; }
            .badge-nota { display: inline-block; padding: 4px 12px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 999px; font-size: 10px; font-weight: 900; text-transform: uppercase; }
            .box-comparativo { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 14px; padding: 16px; margin: 18px 0; }
            .hash-card { background: #0f172a; border-radius: 14px; padding: 14px; margin: 18px 0; }
            .hash-title { font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 6px; display: block; }
            .hash-code { font-family: Consolas, monospace; font-size: 10px; color: #38bdf8; word-break: break-all; overflow-wrap: anywhere; line-height: 1.4; }
            .footer { text-align: center; font-size: 10px; color: #94a3b8; padding: 16px 20px; background: #f8fafc; border-top: 1px solid #e2e8f0; line-height: 1.5; }
            @media only screen and (max-width: 480px) {
                body { padding: 6px; }
                .content { padding: 16px 14px; }
            }
        </style>
    </head>
    <body>
        <div class="wrapper">
            <div class="header">
                ' . (!empty($embeddedImages) ? '<img src="cid:logo_liho" alt="Hernán Ocazionez" style="max-height: 44px; margin-bottom: 8px;" /><br>' : '') . '
                <h2>HERNÁN OCAZIONEZ Y CÍA S.A.S.</h2>
                <p>LIHO - Sistema de Notas de Ajuste y Auditoría</p>
            </div>

            <div class="content">
                <div class="badge-bar">
                    <span style="font-size: 13px; font-weight: 900; color: #0f172a;">Nota de Ajuste ' . $numeroNota . '</span>
                    <span class="badge-nota">' . htmlspecialchars($nota['estado']) . '</span>
                </div>

                <p style="font-size: 13px; margin: 0 0 8px 0; color: #0f172a;">Apreciado(a) <strong>' . $medicoNombre . '</strong> (C.C. ' . $medicoCedula . '),</p>
                <p style="font-size: 12px; color: #475569; line-height: 1.5; margin: 0 0 16px 0;">
                    Se ha emitido una Nota de Ajuste contable asociada a su liquidación base <strong>#' . $nota['liquidacion_id'] . '</strong> correspondiente al periodo <strong>' . $periodo . '</strong>.
                </p>

                <div style="background: #fdf4ff; border-left: 4px solid #c084fc; padding: 12px 14px; border-radius: 8px; margin: 16px 0; font-size: 12px; color: #581c87;">
                    <strong>Motivo / Justificación Auditoría:</strong><br>
                    ' . $motivoAjuste . '
                </div>

                ' . (!empty($itemsRowsHtml) ? '
                <div style="margin: 18px 0; overflow-x: auto;">
                    <span style="font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase;">Detalle de Conceptos Ajustados:</span>
                    <table style="width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 11px;">
                        <thead>
                            <tr style="background: #f1f5f9; color: #475569; text-transform: uppercase;">
                                <th style="padding: 6px 8px; text-align: left;">Sede</th>
                                <th style="padding: 6px 8px; text-align: left;">Tipo</th>
                                <th style="padding: 6px 8px; text-align: left;">Concepto</th>
                                <th style="padding: 6px 8px; text-align: center;">Cant</th>
                                <th style="padding: 6px 8px; text-align: right;">Unitario</th>
                                <th style="padding: 6px 8px; text-align: right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ' . $itemsRowsHtml . '
                        </tbody>
                    </table>
                </div>
                ' : '') . '

                <div class="box-comparativo">
                    <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                        <tr>
                            <td style="padding: 4px 0; color: #64748b;">Total Liquidación Base Original (#' . $nota['liquidacion_id'] . '):</td>
                            <td style="padding: 4px 0; text-align: right; font-weight: bold; color: #0f172a;">$ ' . $totalOriginal . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 4px 0; color: #64748b;">Valor Ajuste (' . $tipoNota . '):</td>
                            <td style="padding: 4px 0; text-align: right; font-weight: bold; color: ' . ($esCredito ? '#059669' : '#e11d48') . ';">' . ($esCredito ? '+ ' : '- ') . '$ ' . $valorAjuste . '</td>
                        </tr>
                        <tr style="border-top: 2px solid #cbd5e1;">
                            <td style="padding: 8px 0 2px 0; font-weight: 800; color: #0f172a; font-size: 13px;">NUEVO SALDO CONSOLIDADO:</td>
                            <td style="padding: 8px 0 2px 0; text-align: right; font-weight: 900; color: #0d9488; font-size: 15px;">$ ' . $totalAjustado . '</td>
                        </tr>
                    </table>
                </div>

                <div class="hash-card">
                    <span class="hash-title">FIRMA DIGITAL DE LA NOTA DE AJUSTE (SHA-256):</span>
                    <div class="hash-code">' . $hashIntegridad . '</div>
                </div>

                <p style="font-size: 11px; color: #64748b; margin: 12px 0 0 0; line-height: 1.4;">
                    Copia remitida a la Dirección Médica (' . htmlspecialchars($correoDirMedica) . ') y al Desarrollador (' . htmlspecialchars($correoCopiaDev) . '). El registro base de la liquidación original permanece inalterado.
                </p>
            </div>

            <div class="footer">
                Fecha de Registro: ' . $fechaEmision . '<br>
                © ' . date('Y') . ' IPS Hernán Ocazionez y Cía S.A.S. - Todos los derechos reservados.
            </div>
        </div>
    </body>
    </html>
    ';

    $enviado = enviarCorreoSMTP($destinatarioPrincipal, $asunto, $bodyHtml, null, $embeddedImages, '', $copiasCC);
    $estadoStr = $enviado ? 'EXITOSO' : 'FALLIDO';
    $ccTexto = implode(', ', $copiasCC);

    // Registrar en tabla unificada sistema_auditoria_logs
    registrarLogAuditoriaUniversal(
        'CORREO',
        $notaId,
        'NA-#' . $notaId,
        'ENVIO_NOTIFICACION_NOTA_AJUSTE',
        null,
        $estadoStr,
        $_SESSION['usuario_id'] ?? null,
        $_SESSION['usuario_nombre'] ?? 'Sistema Notificaciones',
        $_SESSION['rol'] ?? 'SISTEMA',
        'Notificación de nota ' . $numeroNota . ' enviada a ' . $destinatarioPrincipal . ' con copia a ' . $ccTexto . ' (' . $estadoStr . ')',
        array('destinatario' => $destinatarioPrincipal, 'cc' => $copiasCC, 'asunto' => $asunto, 'estado' => $estadoStr),
        $estadoStr,
        $hashIntegridad
    );

    return $enviado;
}

/**
 * Obtiene la lista de liquidaciones disponibles para seleccionar en la creación de notas
 */
function obtenerLiquidacionesParaSelectBD($filtroMes = '') {
    asegurarTablasLiquidaciones();
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $where = array("estado = 'APROBADA'");
    $params = array();

    if (!empty($filtroMes)) {
        $where[] = "(periodo_desde LIKE ? OR fecha_creacion LIKE ?)";
        $params[] = $filtroMes . '%';
        $params[] = $filtroMes . '%';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT id, periodo_desde, periodo_hasta, medico_cedula, medico_nombre, total_a_pagar, estado, fecha_creacion, resumen_sedes_json
            FROM liquidaciones_turnos
            $whereSql
            ORDER BY id DESC";

    $stmt = sqlsrv_query($con, $sql, $params);
    $list = array();

    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['fecha_creacion'] instanceof DateTime) {
                $row['fecha_creacion'] = $row['fecha_creacion']->format('Y-m-d H:i:s');
            }
            $list[] = $row;
        }
    }

    return $list;
}

/**
 * Verifica la firma digital SHA-256 de una Nota de Ajuste
 */
function verificarIntegridadNotaAjusteBD($id) {
    $nota = obtenerNotaAjustePorIdBD($id);
    if (!$nota) {
        return array('valido' => false, 'error' => 'Nota no encontrada');
    }

    $hashAlmacenado = $nota['hash_integridad'] ?? '';
    $hashCalculado = calcularHashNotaAjuste($nota);

    $valido = (!empty($hashAlmacenado) && hash_equals($hashAlmacenado, $hashCalculado));

    return array(
        'valido'          => $valido,
        'hash_almacenado' => $hashAlmacenado,
        'hash_calculado'  => $hashCalculado,
        'nota_id'         => $id,
        'numero_nota'     => $nota['numero_nota'] ?? '',
        'estado'          => $nota['estado'] ?? '',
        'fecha_verif'     => date('Y-m-d H:i:s')
    );
}

/**
 * Obtiene el catálogo de códigos CUPS y tarifas para autocompletado en notas de ajuste
 */
function obtenerCatalogoCupsBD($entidadId = null, $fechaExamen = null) {
    $con = obtenerConexionLIHO();
    if ($con === false) return array();

    $catalogo = array();
    $fCorte = !empty($fechaExamen) ? substr(trim((string)$fechaExamen), 0, 10) : null;

    // 1. Tarifario Base General (Hernán Ocazionez y Cía S.A.S. - entidad_id 4 o sin entidad)
    $sql = "SELECT codigo, examen, valor_und, columna1, ISNULL(concepto, '') AS concepto, ISNULL(tipo_paciente, 'E') AS tipo_paciente 
            FROM tarifario 
            WHERE ISNULL(estado, 1) = 1 AND codigo IS NOT NULL AND RTRIM(LTRIM(codigo)) <> ''
              AND (entidad_id IS NULL OR entidad_id = 0 OR entidad_id = 4) ";
    $params = [];
    if (!empty($fCorte)) {
        $sql .= " AND (? >= vigencia_desde AND (vigencia_hasta IS NULL OR ? <= vigencia_hasta)) ";
        $params[] = $fCorte;
        $params[] = $fCorte;
    } else {
        $sql .= " AND (vigencia_hasta IS NULL) ";
    }
    $sql .= " ORDER BY vigencia_desde DESC, codigo ASC";

    $stmt = @sqlsrv_query($con, $sql, $params);
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $c = strtoupper(trim((string)($row['codigo'] ?? '')));
            if (!empty($c)) {
                $tp = strtoupper(trim((string)($row['tipo_paciente'] ?? 'E')));
                if ($tp !== 'P') $tp = 'E';

                if (!isset($catalogo[$c])) {
                    $catalogo[$c] = array(
                        'codigo'   => $c,
                        'examen'   => trim((string)($row['examen'] ?? '')),
                        'concepto' => trim((string)($row['concepto'] ?? '')),
                        'valor_e'  => 0,
                        'valor_p'  => 0,
                        'valor'    => 0
                    );
                }

                $col1 = floatval($row['columna1'] ?? 0);
                $und = floatval($row['valor_und'] ?? 0);
                $val = ($col1 > 0) ? $col1 : $und;

                if ($tp === 'P') {
                    $catalogo[$c]['valor_p'] = $val;
                } else {
                    $catalogo[$c]['valor_e'] = $val;
                    $catalogo[$c]['valor']   = $val;
                }
            }
        }
    }

    // 1.1 Si hay una entidad activa especificada (y no es matriz), sobreescribir con su tarifario específico
    if (!empty($entidadId) && (int)$entidadId > 0 && (int)$entidadId !== 4) {
        $sqlEnt = "SELECT codigo, examen, valor_und, columna1, ISNULL(concepto, '') AS concepto, ISNULL(tipo_paciente, 'E') AS tipo_paciente 
                   FROM tarifario 
                   WHERE ISNULL(estado, 1) = 1 AND codigo IS NOT NULL AND RTRIM(LTRIM(codigo)) <> ''
                     AND entidad_id = ? ";
        $paramsEnt = [(int)$entidadId];
        if (!empty($fCorte)) {
            $sqlEnt .= " AND (? >= vigencia_desde AND (vigencia_hasta IS NULL OR ? <= vigencia_hasta)) ";
            $paramsEnt[] = $fCorte;
            $paramsEnt[] = $fCorte;
        } else {
            $sqlEnt .= " AND (vigencia_hasta IS NULL) ";
        }
        $sqlEnt .= " ORDER BY vigencia_desde DESC, codigo ASC";

        $stmtEnt = @sqlsrv_query($con, $sqlEnt, $paramsEnt);
        if ($stmtEnt !== false) {
            while ($row = sqlsrv_fetch_array($stmtEnt, SQLSRV_FETCH_ASSOC)) {
                $c = strtoupper(trim((string)($row['codigo'] ?? '')));
                if (!empty($c)) {
                    $tp = strtoupper(trim((string)($row['tipo_paciente'] ?? 'E')));
                    if ($tp !== 'P') $tp = 'E';

                    if (!isset($catalogo[$c])) {
                        $catalogo[$c] = array(
                            'codigo'   => $c,
                            'examen'   => trim((string)($row['examen'] ?? '')),
                            'concepto' => trim((string)($row['concepto'] ?? '')),
                            'valor_e'  => 0,
                            'valor_p'  => 0,
                            'valor'    => 0
                        );
                    }

                    $col1 = floatval($row['columna1'] ?? 0);
                    $und = floatval($row['valor_und'] ?? 0);
                    $val = ($col1 > 0) ? $col1 : $und;

                    if ($tp === 'P') {
                        $catalogo[$c]['valor_p'] = $val;
                    } else {
                        $catalogo[$c]['valor_e'] = $val;
                        $catalogo[$c]['valor']   = $val;
                    }
                }
            }
        }
    }

    // 2. Tarifario Especial
    $sqlEsp = "SELECT codigo, estudio AS examen, tipo_pago, valor 
               FROM tarifario_especial 
               WHERE ISNULL(estado, 1) = 1 AND codigo IS NOT NULL AND RTRIM(LTRIM(codigo)) <> ''";
    $stmtEsp = @sqlsrv_query($con, $sqlEsp);
    if ($stmtEsp !== false) {
        while ($rEsp = sqlsrv_fetch_array($stmtEsp, SQLSRV_FETCH_ASSOC)) {
            $c = strtoupper(trim((string)($rEsp['codigo'] ?? '')));
            if (!empty($c) && !isset($catalogo[$c])) {
                $v = floatval($rEsp['valor'] ?? 0);
                $catalogo[$c] = array(
                    'codigo'   => $c,
                    'examen'   => trim((string)($rEsp['examen'] ?? '')),
                    'concepto' => 'ESPECIAL',
                    'valor_e'  => $v,
                    'valor_p'  => $v,
                    'valor'    => $v
                );
            }
        }
    }

    // Garantizar que si un valor falta, tome el del otro tipo de paciente
    foreach ($catalogo as $code => &$item) {
        if ($item['valor_e'] <= 0 && $item['valor_p'] > 0) {
            $item['valor_e'] = $item['valor_p'];
        }
        if ($item['valor_p'] <= 0 && $item['valor_e'] > 0) {
            $item['valor_p'] = $item['valor_e'];
        }
        if ($item['valor'] <= 0) {
            $item['valor'] = $item['valor_e'];
        }
    }
    unset($item);

    // Indexar también por clave alfanumérica limpia (ej: sin espacios, puntos ni guiones)
    $clavesLimpias = array();
    foreach ($catalogo as $code => $item) {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$code);
        if ($clean !== '' && $clean !== $code && !isset($catalogo[$clean])) {
            $clavesLimpias[$clean] = $item;
        }
    }
    if (!empty($clavesLimpias)) {
        foreach ($clavesLimpias as $cleanK => $cleanVal) {
            if (!isset($catalogo[$cleanK])) {
                $catalogo[$cleanK] = $cleanVal;
            }
        }
    }

    return $catalogo;
}


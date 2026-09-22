<?php
date_default_timezone_set('America/Bogota');
/**
 * Generador de Reporte PDF Oficial de Liquidación de Turnos y Honorarios Médicos
 * IPS Hernán Ocazionez y Cía S.A.S. - LIHO
 */
require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/liquidaciones_helper.php';

class LiquidacionPDF extends FPDF {
    public $numeroLiquidacion = '';
    public $medicoNombre = '';
    public $periodo = '';

    function Header() {
        // Fondo decorativo de cabecera
        $this->SetFillColor(15, 23, 42); // slate-900
        $this->Rect(0, 0, 210, 26, 'F');

        // Logo oficial solicitado para fondos oscuros
        $logoPath = __DIR__ . '/../assets/img/Ho_Fondo_Osc.png';
        if (!file_exists($logoPath)) $logoPath = __DIR__ . '/../assets/img/logo_email_optimized.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 10, 4, 22, 18);
        }

        // Título Empresa
        $this->SetXY(36, 5);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(115, 6, utf8_decode('HERNÁN OCAZIONEZ Y CÍA S.A.S.'), 0, 1, 'L');

        $this->SetX(36);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(0, 193, 190); // Teal LIHO
        $this->Cell(115, 5, utf8_decode('LIHO - LIQUIDACIÓN DE HONORARIOS MÉDICOS'), 0, 1, 'L');

        $this->SetX(36);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(148, 163, 184); // slate-400
        $this->Cell(115, 4, utf8_decode('NIT: 890.908.414-9 | CERTIFICACIÓN OFICIAL DE PAGO Y AUDITORÍA'), 0, 1, 'L');

        // Badge Liquidación N° en cabecera derecha
        $this->SetXY(155, 6);
        $this->SetFillColor(13, 148, 136); // Teal-600
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(45, 6, utf8_decode('LIQUIDACIÓN N° ' . $this->numeroLiquidacion), 0, 1, 'C', true);

        $this->SetXY(155, 13);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(203, 213, 225);
        $this->Cell(45, 4, utf8_decode('ESTADO: APROBADA'), 0, 1, 'C');

        $this->SetY(30);
    }

    function Footer() {
        $this->SetY(-16);
        $this->SetDrawColor(226, 232, 240);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetY(-13);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(100, 5, utf8_decode('IPS Hernán Ocazionez y Cía S.A.S. - Documento con Firma Digital Criptográfica SHA-256'), 0, 0, 'L');
        $this->Cell(90, 5, utf8_decode('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'R');
    }
}

/**
 * Genera el documento PDF en memoria y retorna el binario
 */
function generarPDFLiquidacion($liquidacionIdOrData) {
    if (is_array($liquidacionIdOrData)) {
        $liq = $liquidacionIdOrData;
    } else {
        $liq = obtenerLiquidacionPorIdBD($liquidacionIdOrData, false);
    }
    if (!$liq) return null;

    $pdf = new LiquidacionPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->numeroLiquidacion = $liq['id'];
    $pdf->medicoNombre = $liq['medico_nombre'];
    $pdf->periodo = $liq['periodo_desde'] . ' al ' . $liq['periodo_hasta'];

    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 18);

    // 1. Tarjeta de Datos del Médico y Periodo
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Rect(10, 28, 190, 20.5, 'DF');

    // Fila 1
    $pdf->SetXY(13, 29.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(36, 4.5, utf8_decode('PROFESIONAL / MÉDICO:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(70, 4.5, utf8_decode(strtoupper($liq['medico_nombre'])), 0, 0, 'L');

    $pdf->SetXY(122, 29.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(26, 4.5, utf8_decode('CÉDULA / ID:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(48, 4.5, utf8_decode($liq['medico_cedula']), 0, 1, 'L');

    // Fila 2
    $pdf->SetXY(13, 34.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(36, 4.5, utf8_decode('PERIODO LIQUIDADO:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(70, 4.5, utf8_decode($liq['periodo_desde'] . '  AL  ' . $liq['periodo_hasta']), 0, 0, 'L');

    $pdf->SetXY(122, 34.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(26, 4.5, utf8_decode('FECHA EMISIÓN:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(48, 4.5, date('d/m/Y h:i A'), 0, 1, 'L');

    // Fila 3
    $pdf->SetXY(13, 39.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(36, 4.5, utf8_decode('REGISTRADO POR:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(70, 4.5, utf8_decode(($liq['usuario_creador_nombre'] ?? '') ?: 'SISTEMA'), 0, 0, 'L');

    $pdf->SetXY(122, 39.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(26, 4.5, utf8_decode('APROBADO POR:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(13, 148, 136);
    $pdf->Cell(48, 4.5, utf8_decode(($liq['usuario_aprobador_nombre'] ?? '') ?: 'Dirección Médica'), 0, 1, 'L');

    // 2. Desglose de Estudios Realizados Agrupados por Concepto
    $pdf->SetY(52);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(190, 5, utf8_decode('DETALLE DE LIQUIDACIÓN: ESTUDIOS AGRUPADOS POR CONCEPTO'), 0, 1, 'L');

    $sedesObj = array();
    if (!empty($liq['resumen_sedes_json'])) {
        $sedesObj = json_decode($liq['resumen_sedes_json'], true) ?: array();
    } elseif (!empty($liq['detalles_json'])) {
        $full = json_decode($liq['detalles_json'], true) ?: array();
        $sedesObj = json_decode(generarResumenSedesJSON($full), true) ?: array();
    }

    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(65, 5.5, utf8_decode('SEDE DE ATENCIÓN'), 1, 0, 'L', true);
    $pdf->Cell(45, 5.5, utf8_decode('CONCEPTO'), 1, 0, 'L', true);
    $pdf->Cell(25, 5.5, utf8_decode('CANTIDAD'), 1, 0, 'C', true);
    $pdf->Cell(55, 5.5, utf8_decode('VALOR SUBTOTAL'), 1, 1, 'R', true);

    $fill = false;
    $totalExamenesCant = 0;
    $totalExamenesVal = 0;

    foreach ($sedesObj as $sedeNombre => $sData) {
        $conceptos = $sData['conceptos'] ?? array();
        $totalSedeVal = floatval($sData['total'] ?? 0);
        $totalSedeCant = intval($sData['examenes_count'] ?? (isset($sData['examenes']) ? count($sData['examenes']) : ($sData['cant'] ?? 0)));

        if (!empty($conceptos) && is_array($conceptos)) {
            // Si solo hay un concepto o RXSI, asignar el total y conteo completo consolidado de la sede
            if (count($conceptos) === 1) {
                $cKey = key($conceptos);
                $totalExamenesCant += $totalSedeCant;
                $totalExamenesVal += $totalSedeVal;

                $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
                $pdf->SetTextColor(30, 41, 59);
                $pdf->SetFont('Arial', 'B', 7.5);
                $pdf->Cell(65, 5, utf8_decode(strtoupper($sedeNombre)), 1, 0, 'L', true);
                $pdf->SetFont('Arial', '', 7.5);
                $pdf->Cell(45, 5, utf8_decode($cKey), 1, 0, 'L', true);
                $pdf->Cell(25, 5, number_format($totalSedeCant, 0, ',', '.'), 1, 0, 'C', true);
                $pdf->SetFont('Courier', 'B', 8);
                $pdf->Cell(55, 5, '$ ' . number_format($totalSedeVal, 2, ',', '.'), 1, 1, 'R', true);
                $fill = !$fill;
            } else {
                $noCruzCant = intval($sData['no_cruzados_count'] ?? 0);
                $noCruzVal  = floatval($sData['no_cruzados_valor'] ?? 0);

                foreach ($conceptos as $cKey => $cVal) {
                    $cant = intval($cVal['cantidad'] ?? ($cVal['cant'] ?? 0));
                    $val = floatval($cVal['total'] ?? ($cVal['valor'] ?? 0));

                    // Unir los no cruzados al concepto principal RXSI
                    if ($cKey === 'RXSI' && $noCruzCant > 0) {
                        $cant += $noCruzCant;
                        $val += $noCruzVal;
                        $noCruzCant = 0;
                    }

                    $totalExamenesCant += $cant;
                    $totalExamenesVal += $val;

                    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
                    $pdf->SetTextColor(30, 41, 59);
                    $pdf->SetFont('Arial', 'B', 7.5);
                    $pdf->Cell(65, 5, utf8_decode(strtoupper($sedeNombre)), 1, 0, 'L', true);
                    if (strlen($cKey) > 18) {
                        $pdf->SetFont('Arial', 'B', 6.5);
                    } else {
                        $pdf->SetFont('Arial', '', 7.5);
                    }
                    $pdf->Cell(45, 5, utf8_decode($cKey), 1, 0, 'L', true);
                    $pdf->Cell(25, 5, number_format($cant, 0, ',', '.'), 1, 0, 'C', true);
                    $pdf->SetFont('Courier', 'B', 8);
                    $pdf->Cell(55, 5, '$ ' . number_format($val, 2, ',', '.'), 1, 1, 'R', true);
                    $fill = !$fill;
                }
            }
        } else {
            $totalExamenesCant += $totalSedeCant;
            $totalExamenesVal += $totalSedeVal;

            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell(65, 5, utf8_decode(strtoupper($sedeNombre)), 1, 0, 'L', true);
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->Cell(45, 5, utf8_decode('RXSI'), 1, 0, 'L', true);
            $pdf->Cell(25, 5, number_format($totalSedeCant, 0, ',', '.'), 1, 0, 'C', true);
            $pdf->SetFont('Courier', 'B', 8);
            $pdf->Cell(55, 5, '$ ' . number_format($totalSedeVal, 2, ',', '.'), 1, 1, 'R', true);
            $fill = !$fill;
        }
    }

    // Fila Total Estudios
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(110, 5.5, utf8_decode('TOTAL ESTUDIOS REALIZADOS:'), 1, 0, 'R', true);
    $pdf->Cell(25, 5.5, number_format($totalExamenesCant, 0, ',', '.'), 1, 0, 'C', true);
    $pdf->SetFont('Courier', 'B', 8.5);
    $pdf->Cell(55, 5.5, '$ ' . number_format($totalExamenesVal, 2, ',', '.'), 1, 1, 'R', true);

    // 2.1 Desglose de Honorarios por Médico (Solo para Liquidaciones Globales)
    if (empty($liq['desglose_medicos'])) {
        $isGlobalLiq = (
            strpos(strtoupper($liq['medico_cedula'] ?? ''), 'GLOBAL') !== false ||
            strpos(strtoupper($liq['medico_nombre'] ?? ''), 'GLOBAL') !== false ||
            strpos(strtoupper($liq['medico_nombre'] ?? ''), 'TODOS') !== false
        );
        if ($isGlobalLiq && !empty($liq['detalles_json']) && function_exists('generarDesgloseMedicosJSON')) {
            $liq['desglose_medicos'] = generarDesgloseMedicosJSON($liq['detalles_json']);
        }
    }

    if (!empty($liq['desglose_medicos']) && is_array($liq['desglose_medicos'])) {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(190, 5, utf8_decode('DESGLOSE DE HONORARIOS GENERADOS POR MÉDICO'), 0, 1, 'L');

        $pdf->SetFillColor(15, 23, 42);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->Cell(85, 5.5, utf8_decode('MÉDICO / PROFESIONAL'), 1, 0, 'L', true);
        $pdf->Cell(35, 5.5, utf8_decode('CÉDULA / ID'), 1, 0, 'C', true);
        $pdf->Cell(25, 5.5, utf8_decode('CANTIDAD'), 1, 0, 'C', true);
        $pdf->Cell(45, 5.5, utf8_decode('VALOR GENERADO'), 1, 1, 'R', true);

        $fillDoc = false;
        $totalDocCant = 0;
        $totalDocVal = 0;

        foreach ($liq['desglose_medicos'] as $doc) {
            $cantD = intval($doc['cantidad'] ?? 0);
            $valD  = floatval($doc['total'] ?? 0);
            $totalDocCant += $cantD;
            $totalDocVal  += $valD;

            $pdf->SetFillColor($fillDoc ? 248 : 255, $fillDoc ? 250 : 255, $fillDoc ? 252 : 255);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell(85, 5, utf8_decode(strtoupper($doc['nombre'] ?? 'MÉDICO')), 1, 0, 'L', true);
            $pdf->SetFont('Courier', '', 7.5);
            $pdf->Cell(35, 5, utf8_decode($doc['cedula'] ?? '-'), 1, 0, 'C', true);
            $pdf->SetFont('Arial', '', 7.5);
            $pdf->Cell(25, 5, number_format($cantD, 0, ',', '.'), 1, 0, 'C', true);
            $pdf->SetFont('Courier', 'B', 8);
            $pdf->Cell(45, 5, '$ ' . number_format($valD, 2, ',', '.'), 1, 1, 'R', true);
            $fillDoc = !$fillDoc;
        }

        // Fila Total Médicos
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(120, 5.5, utf8_decode('TOTAL PRODUCCIÓN MÉDICOS:'), 1, 0, 'R', true);
        $pdf->Cell(25, 5.5, number_format($totalDocCant, 0, ',', '.'), 1, 0, 'C', true);
        $pdf->SetFont('Courier', 'B', 8.5);
        $pdf->Cell(45, 5.5, '$ ' . number_format($totalDocVal, 2, ',', '.'), 1, 1, 'R', true);
    }

    // 3. Dos Columnas: Resumen por Sede (Izq) y Deducciones Contables (Der)
    $pdf->Ln(4);
    $yPos = $pdf->GetY();

    // Columna Izquierda: Resumen Sedes
    $pdf->SetXY(10, $yPos);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(90, 4.5, utf8_decode('RESUMEN DE ESTUDIOS POR SEDE'), 0, 1, 'L');

    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(50, 4.5, utf8_decode('SEDE'), 1, 0, 'L', true);
    $pdf->Cell(40, 4.5, utf8_decode('VALOR TOTAL'), 1, 1, 'R', true);

    foreach ($sedesObj as $sedeNombre => $sData) {
        $valSede = floatval($sData['total'] ?? 0);
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(50, 4.5, utf8_decode(strtoupper($sedeNombre)), 1, 0, 'L');
        $pdf->SetFont('Courier', 'B', 7.5);
        $pdf->Cell(40, 4.5, '$ ' . number_format($valSede, 2, ',', '.'), 1, 1, 'R');
    }

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(50, 5, utf8_decode('TOTAL FACTURA:'), 1, 0, 'L', true);
    $pdf->SetFont('Courier', 'B', 8.5);
    $pdf->Cell(40, 5, '$ ' . number_format(floatval($liq['total_factura']), 2, ',', '.'), 1, 1, 'R', true);

    $yIzqFinal = $pdf->GetY();

    // Columna Derecha: Información Contable y Deducciones
    $pdf->SetXY(105, $yPos);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(95, 4.5, utf8_decode('INFORMACIÓN CONTABLE Y DEDUCCIONES'), 0, 1, 'L');

    $pdf->SetX(105);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(55, 4.5, utf8_decode('CONCEPTO DEDUCCIÓN'), 1, 0, 'L', true);
    $pdf->Cell(40, 4.5, utf8_decode('MONTO'), 1, 1, 'R', true);

    // Filtrar deducciones suprimiendo las que sean $0
    $deducciones = array();

    $ibc = floatval($liq['ded_ibc'] ?? 0);
    if ($ibc > 0) {
        $deducciones['IBC MES (ESTIMADO)'] = $ibc;
    }

    $salud = floatval($liq['ded_salud'] ?? 0);
    if ($salud > 0) {
        $deducciones['MENOS APORTES SALUD MES'] = -$salud;
    }

    $pension = floatval($liq['ded_pension'] ?? 0);
    if ($pension > 0) {
        $deducciones['MENOS APORTES PENSIÓN MES'] = -$pension;
    }

    $arl = floatval($liq['ded_arl'] ?? 0);
    if ($arl > 0) {
        $deducciones['MENOS APORTES ARL MES'] = -$arl;
    }

    $rete383 = floatval($liq['ded_rete_383'] ?? 0);
    $retencion = floatval($liq['ded_retencion'] ?? 0);
    $retPct = floatval($liq['ded_retencion_pct'] ?? 0);

    if ($rete383 > 0) {
        $deducciones['MENOS RETENCIÓN ART 383'] = -$rete383;
    } elseif ($retencion > 0) {
        $lblRet = ($retPct > 0) ? "MENOS RETENCIÓN ({$retPct}%)" : 'MENOS RETENCIÓN EN LA FUENTE';
        $deducciones[$lblRet] = -$retencion;
    }

    $afc = floatval($liq['ded_afc'] ?? 0);
    if ($afc > 0) {
        $deducciones['MENOS APORTES AFC'] = -$afc;
    }

    $solidaridad = floatval($liq['ded_solidaridad'] ?? 0);
    if ($solidaridad > 0) {
        $deducciones['MENOS FONDO SOLIDARIDAD'] = -$solidaridad;
    }

    if (empty($deducciones)) {
        $pdf->SetX(105);
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(95, 5, utf8_decode('Sin deducciones aplicadas'), 1, 1, 'C');
    } else {
        foreach ($deducciones as $dNom => $dVal) {
            $pdf->SetX(105);
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(55, 4.2, utf8_decode($dNom), 1, 0, 'L');
            $pdf->SetFont('Courier', 'B', 7.5);
            if ($dVal < 0) $pdf->SetTextColor(225, 29, 72); else $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(40, 4.2, ($dVal < 0 ? '- ' : '') . '$ ' . number_format(abs($dVal), 2, ',', '.'), 1, 1, 'R');
        }
    }

    $totDed = floatval($liq['total_deducciones'] ?? 0);
    if ($totDed > 0) {
        // Fila Total Deducciones
        $pdf->SetX(105);
        $pdf->SetFillColor(254, 242, 242);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetTextColor(159, 18, 57);
        $pdf->Cell(55, 4.5, utf8_decode('TOTAL DEDUCCIONES:'), 1, 0, 'L', true);
        $pdf->SetFont('Courier', 'B', 8);
        $pdf->Cell(40, 4.5, '- $ ' . number_format($totDed, 2, ',', '.'), 1, 1, 'R', true);
    }

    // Fila TOTAL NETO A PAGAR
    $pdf->SetX(105);
    $pdf->SetFillColor(13, 148, 136); // Teal
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(55, 5.5, utf8_decode('TOTAL A PAGAR:'), 1, 0, 'L', true);
    $pdf->SetFont('Courier', 'B', 9);
    $pdf->Cell(40, 5.5, '$ ' . number_format(floatval($liq['total_a_pagar']), 2, ',', '.'), 1, 1, 'R', true);

    $yDerFinal = $pdf->GetY();
    $maxY = max($yIzqFinal, $yDerFinal);

    // 4. Huella Digital SHA-256 e Integridad Criptográfica
    $pdf->SetY($maxY + 4);
    $pdf->SetFillColor(240, 253, 250);
    $pdf->SetDrawColor(13, 148, 136);
    $pdf->Rect(10, $pdf->GetY(), 190, 16, 'DF');

    $pdf->SetXY(14, $pdf->GetY() + 2);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(13, 148, 136);
    $pdf->Cell(180, 3.5, utf8_decode('HUELLA DIGITAL DE INTEGRIDAD CRIPTOGRÁFICA (SHA-256):'), 0, 1, 'L');

    $pdf->SetX(14);
    $pdf->SetFont('Courier', 'B', 7.5);
    $pdf->SetTextColor(15, 23, 42);
    $hash = $liq['hash_integridad'] ?: 'GENERADO_AL_APROBAR';
    $pdf->Cell(180, 4, utf8_decode($hash), 0, 1, 'L');

    $pdf->SetX(14);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(180, 3.5, utf8_decode('Esta firma criptográfica certifica la inmutabilidad y autenticidad del presente reporte contable en la plataforma LIHO.'), 0, 1, 'L');

    return $pdf->Output('S');
}

/**
 * Genera el archivo Excel (CSV estructurado en UTF-8 con BOM) con todos los exámenes detallados
 */
function generarExcelLiquidacion($liquidacionIdOrData) {
    if (is_array($liquidacionIdOrData)) {
        $liq = $liquidacionIdOrData;
    } else {
        $liq = obtenerLiquidacionPorIdBD($liquidacionIdOrData, true);
    }
    if (!$liq) return null;

    $sedesObj = array();
    if (!empty($liq['detalles_json'])) {
        $sedesObj = is_array($liq['detalles_json']) ? $liq['detalles_json'] : (json_decode($liq['detalles_json'], true) ?: array());
    }

    $rows = array();
    $rows[] = array(
        'ORIGEN',
        'REF / ID EVENTO',
        'FUENTE',
        'INGRESO',
        'TIPO PACIENTE',
        'FECHA',
        'SEDE',
        'MEDICO',
        'CEDULA MEDICO',
        'PACIENTE',
        'IDENTIFICACION PACIENTE',
        'ENTIDAD / CONVENIO',
        'CODIGO CUPS',
        'DESCRIPCION EXAMEN',
        'CANTIDAD',
        'VALOR A PAGAR'
    );

    foreach ($sedesObj as $sede => $sData) {
        if ($sData && is_array($sData) && isset($sData['examenes']) && is_array($sData['examenes'])) {
            foreach ($sData['examenes'] as $ex) {
                $origen    = $ex['origen'] ?? 'PROTEO';
                $idRef     = $ex['id_ref'] ?? ($ex['id'] ?? '');
                $fuente    = $ex['fuente'] ?? '';
                $ingreso   = $ex['ingreso'] ?? '';
                $tipoPac   = $ex['tipo_paciente'] ?? 'Empresa';
                $fecha     = $ex['fecha'] ?? ($liq['periodo_hasta'] ?? '');
                $sedeItem  = $ex['sede'] ?? $sede;
                $medicoNom = $ex['medico_nombre'] ?? $liq['medico_nombre'];
                $medicoCed = $ex['medico_cedula'] ?? $liq['medico_cedula'];
                $paciente  = $ex['paciente'] ?? ($ex['nombre'] ?? '');
                $docPac    = $ex['documento'] ?? '';
                $entidad   = $ex['entidad'] ?? '';
                $cups      = $ex['cups'] ?? '';
                $examen    = $ex['examen'] ?? ($ex['nombre_examen'] ?? '');
                $cant      = intval($ex['cantidad'] ?? 1);
                $valPagar  = floatval($ex['valor_a_pagar'] ?? ($ex['valor'] ?? 0));

                $rows[] = array(
                    $origen,
                    $idRef,
                    $fuente,
                    $ingreso,
                    $tipoPac,
                    $fecha,
                    $sedeItem,
                    $medicoNom,
                    $medicoCed,
                    $paciente,
                    $docPac,
                    $entidad,
                    $cups,
                    $examen,
                    $cant,
                    $valPagar
                );
            }
        } elseif ($sData && isset($sData['conceptos'])) {
            foreach ($sData['conceptos'] as $cKey => $cVal) {
                $cant = intval($cVal['cantidad'] ?? ($cVal['cant'] ?? 1));
                $valPagar = floatval($cVal['total'] ?? ($cVal['valor'] ?? 0));
                $esBoni = (!empty($cVal['es_bonificacion']) || strpos($cKey, 'BONI') !== false || strpos($cKey, 'BONIFICACIÓN') !== false || strpos($cKey, 'BONIFICACION') !== false);
                $rows[] = array(
                    $esBoni ? 'SISTEMA' : 'PROTEO',
                    $esBoni ? 'BONI_TOHO' : '',
                    $esBoni ? 'LIHO' : '',
                    $esBoni ? 'BONIFICACION' : '',
                    $esBoni ? 'Incentivo' : 'Empresa',
                    $liq['periodo_hasta'] ?? '',
                    $sede,
                    $liq['medico_nombre'],
                    $liq['medico_cedula'],
                    $esBoni ? 'INCENTIVO POR PRODUCTIVIDAD' : 'PACIENTES AGRUPADOS',
                    $esBoni ? 'N/A' : '',
                    $esBoni ? 'LIHO IPS' : '',
                    $esBoni ? 'BONI_TOHO' : $cKey,
                    $esBoni ? 'BONIFICACIÓN TOMOGRAFÍAS (REGLA 50 CT x $150.000 COP)' : 'ESTUDIOS MEDICOS / RXSI',
                    $cant,
                    $valPagar
                );
            }
        } else {
            $totalSedeCant = intval($sData['examenes_count'] ?? ($sData['cant'] ?? 1));
            $totalSedeVal  = floatval($sData['total'] ?? ($sData['valor'] ?? 0));
            $rows[] = array(
                'PROTEO',
                '',
                '',
                '',
                'Empresa',
                $liq['periodo_hasta'] ?? '',
                $sede,
                $liq['medico_nombre'],
                $liq['medico_cedula'],
                'PACIENTES SEDE',
                '',
                '',
                'RXSI',
                'ESTUDIOS MEDICOS',
                $totalSedeCant,
                $totalSedeVal
            );
        }
    }

    // Generar CSV con UTF-8 BOM
    $csvContent = "\xEF\xBB\xBF";
    foreach ($rows as $r) {
        $line = implode(';', array_map(function($v) {
            return '"' . str_replace('"', '""', (string)$v) . '"';
        }, $r));
        $csvContent .= $line . "\r\n";
    }

    return $csvContent;
}

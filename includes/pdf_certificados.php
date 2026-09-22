<?php
date_default_timezone_set('America/Bogota');
/**
 * Generador de Reporte PDF Oficial de Certificado de Ingresos y Retenciones
 * Cumplimiento Art. 381 y 383 del Estatuto Tributario Nacional de Colombia
 * Con protección por contraseña (Cédula del médico) y cifrado estándar de seguridad
 * IPS Hernán Ocazionez y Cía S.A.S. - LIHO
 */
require_once __DIR__ . '/fpdf.php';

// Motor de cifrado RC4 para protección estándar de PDF
if (!function_exists('liho_pdf_rc4')) {
    function liho_pdf_rc4($key, $data) {
        static $last_key, $last_state;
        if ($key !== $last_key) {
            $k = str_repeat($key, (int)ceil(256 / strlen($key)));
            $state = range(0, 255);
            $j = 0;
            for ($i = 0; $i < 256; $i++) {
                $t = $state[$i];
                $j = ($j + $t + ord($k[$i])) % 256;
                $state[$i] = $state[$j];
                $state[$j] = $t;
            }
            $last_key = $key;
            $last_state = $state;
        } else {
            $state = $last_state;
        }
        $len = strlen($data);
        $a = 0;
        $b = 0;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $a = ($a + 1) % 256;
            $t = $state[$a];
            $b = ($b + $t) % 256;
            $state[$a] = $state[$b];
            $state[$b] = $t;
            $k = $state[($state[$a] + $state[$b]) % 256];
            $out .= chr(ord($data[$i]) ^ $k);
        }
        return $out;
    }
}

class CertificadoTributarioPDF extends FPDF {
    public $anioGravable = '';
    public $medicoNombre = '';
    public $medicoCedula = '';
    public $entidadNombre = '';
    public $certificadoHash = '';

    // Propiedades de cifrado y protección de documento
    protected $encrypted = false;
    protected $padding = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
    protected $encryption_key;
    protected $Uvalue;
    protected $Ovalue;
    protected $Pvalue;
    protected $enc_obj_id;

    /**
     * Configura la protección con contraseña del PDF
     */
    public function SetProtection($permissions = array(), $user_pass = '', $owner_pass = null) {
        $options = array('print' => 4, 'modify' => 8, 'copy' => 16, 'annot-forms' => 32);
        $protection = 192;
        foreach ($permissions as $permission) {
            if (!isset($options[$permission])) {
                $this->Error('Permiso incorrecto: ' . $permission);
            }
            $protection += $options[$permission];
        }
        if ($owner_pass === null) {
            $owner_pass = uniqid('liho_sec_', true);
        }
        $this->encrypted = true;
        $this->_generateencryptionkey($user_pass, $owner_pass, $protection);
    }

    protected function _putstream($s) {
        if ($this->encrypted) {
            $s = liho_pdf_rc4($this->_objectkey($this->n), $s);
        }
        parent::_putstream($s);
    }

    protected function _textstring($s) {
        if (!$this->_isascii($s)) {
            $s = $this->_UTF8toUTF16($s);
        }
        if ($this->encrypted) {
            $s = liho_pdf_rc4($this->_objectkey($this->n), $s);
        }
        return '(' . $this->_escape($s) . ')';
    }

    protected function _objectkey($n) {
        return substr($this->_md5_16($this->encryption_key . pack('VXxx', $n)), 0, 10);
    }

    protected function _putresources() {
        parent::_putresources();
        if ($this->encrypted) {
            $this->_newobj();
            $this->enc_obj_id = $this->n;
            $this->_put('<<');
            $this->_putencryption();
            $this->_put('>>');
            $this->_put('endobj');
        }
    }

    protected function _putencryption() {
        $this->_put('/Filter /Standard');
        $this->_put('/V 1');
        $this->_put('/R 2');
        $this->_put('/O (' . $this->_escape($this->Ovalue) . ')');
        $this->_put('/U (' . $this->_escape($this->Uvalue) . ')');
        $this->_put('/P ' . $this->Pvalue);
    }

    protected function _puttrailer() {
        parent::_puttrailer();
        if ($this->encrypted) {
            $this->_put('/Encrypt ' . $this->enc_obj_id . ' 0 R');
            $this->_put('/ID [()()]');
        }
    }

    protected function _md5_16($string) {
        return md5($string, true);
    }

    protected function _Ovalue($user_pass, $owner_pass) {
        $tmp = $this->_md5_16($owner_pass);
        $owner_RC4_key = substr($tmp, 0, 5);
        return liho_pdf_rc4($owner_RC4_key, $user_pass);
    }

    protected function _Uvalue() {
        return liho_pdf_rc4($this->encryption_key, $this->padding);
    }

    protected function _generateencryptionkey($user_pass, $owner_pass, $protection) {
        $user_pass = substr($user_pass . $this->padding, 0, 32);
        $owner_pass = substr($owner_pass . $this->padding, 0, 32);
        $this->Ovalue = $this->_Ovalue($user_pass, $owner_pass);
        $tmp = $this->_md5_16($user_pass . $this->Ovalue . chr($protection) . "\xFF\xFF\xFF");
        $this->encryption_key = substr($tmp, 0, 5);
        $this->Uvalue = $this->_Uvalue();
        $this->Pvalue = -(($protection ^ 255) + 1);
    }

    function Header() {
        // Fondo decorativo institucional oscuro
        $this->SetFillColor(15, 23, 42); // slate-900 (#0f172a)
        $this->Rect(10, 8, 196, 22, 'F');

        // Logotipo institucional oficial
        $logoPath = __DIR__ . '/../assets/img/Ho_Fondo_Osc.png';
        if (!file_exists($logoPath)) $logoPath = __DIR__ . '/../assets/img/Logo original.png';
        if (!file_exists($logoPath)) $logoPath = __DIR__ . '/../assets/img/logo_email_optimized.png';

        if (file_exists($logoPath)) {
            $this->Image($logoPath, 13, 10, 24, 18);
        }

        if ($this->PageNo() == 1) {
            // Título Empresa y Subtítulos Hoja 1
            $this->SetXY(40, 10);
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(110, 4.5, utf8_decode(strtoupper($this->entidadNombre ?: 'HERNÁN OCAZIONEZ Y CÍA S.A.S.')), 0, 1, 'L');

            $this->SetX(40);
            $this->SetFont('Arial', 'B', 7.8);
            $this->SetTextColor(0, 193, 190); // Teal accent
            $this->Cell(110, 4, utf8_decode('LIHO - CERTIFICADO DE INGRESOS Y RETENCIONES'), 0, 1, 'L');

            $this->SetX(40);
            $this->SetFont('Arial', '', 6.6);
            $this->SetTextColor(148, 163, 184); // slate-400
            $this->Cell(110, 3.5, utf8_decode('CERTIFICACIÓN OFICIAL DIAN ART. 381 Y 383 DEL ESTATUTO TRIBUTARIO'), 0, 1, 'L');

            // Badge Año Gravable a la derecha
            $this->SetXY(150, 10);
            $this->SetFillColor(13, 148, 136); // Teal-600
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 7);
            $this->Cell(52, 4.5, utf8_decode('CERTIFICADO OFICIAL'), 0, 1, 'C', true);

            $this->SetXY(150, 14.5);
            $this->SetFillColor(15, 118, 110); // Teal-700
            $this->SetFont('Arial', 'B', 8.5);
            $this->Cell(52, 6.5, utf8_decode('AÑO GRAVABLE ' . $this->anioGravable), 0, 1, 'C', true);

            $this->SetXY(150, 22);
            $this->SetFont('Arial', 'B', 6.2);
            $this->SetTextColor(203, 213, 225);
            $this->Cell(52, 4, utf8_decode('ESTADO: CERTIFICADO / VIGENTE'), 0, 1, 'C');
        } else {
            // Cabecera Hoja 2: Anexo Detallado (Diseño ajustado sin desbordes)
            $this->SetXY(40, 10);
            $this->SetFont('Arial', 'B', 8.5);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(108, 4.5, utf8_decode('2. ANEXO: RELACIÓN DE TURNOS Y LIQUIDACIONES'), 0, 1, 'L');

            $this->SetX(40);
            $this->SetFont('Arial', '', 7.2);
            $this->SetTextColor(0, 193, 190);
            $this->Cell(108, 3.8, utf8_decode('Especialista: ' . strtoupper($this->medicoNombre) . ' | CC: ' . $this->medicoCedula . ' | Año: ' . $this->anioGravable), 0, 1, 'L');

            $this->SetX(40);
            $this->SetFont('Arial', '', 6.5);
            $this->SetTextColor(148, 163, 184);
            $this->Cell(108, 3.5, utf8_decode('Detalle consolidado de liquidaciones formalmente aprobadas'), 0, 1, 'L');

            $this->SetXY(150, 10.5);
            $this->SetFillColor(13, 148, 136);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 7.5);
            $this->Cell(52, 5.5, utf8_decode('ANEXO DETALLADO ' . $this->anioGravable), 0, 1, 'C', true);

            $this->SetXY(150, 17);
            $this->SetFont('Arial', 'B', 6.2);
            $this->SetTextColor(203, 213, 225);
            $this->Cell(52, 3.8, utf8_decode('HOJA 2 DE 2 | ESTADO: APROBADO'), 0, 1, 'C');
        }

        $this->SetY(33);
    }

    function Footer() {
        $this->SetY(-14);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(10, $this->GetY(), 206, $this->GetY());
        $this->SetY(-11);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(100, 116, 139);

        if ($this->PageNo() == 1) {
            $this->Cell(120, 4, utf8_decode('IPS Hernán Ocazionez y Cía S.A.S. - Certificado Tributario Oficial Art. 381 y 383 E.T.'), 0, 0, 'L');
            $this->SetFont('Arial', 'B', 7);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(76, 4, utf8_decode('Página 1 de 2 | Certificado Oficial'), 0, 0, 'R');
        } else {
            $shortHash = substr($this->certificadoHash, 0, 20) . '...';
            $this->Cell(120, 4, utf8_decode('Sistema LIHO | Verificación Hash: ' . $shortHash), 0, 0, 'L');
            $this->SetFont('Arial', 'B', 7);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(76, 4, utf8_decode('Página 2 de 2 | Anexo Detallado'), 0, 0, 'R');
        }
    }
}

/**
 * Genera el documento PDF en memoria del Certificado Tributario Oficial
 * con protección por contraseña basada en la cédula del médico especialista
 *
 * @param array $datos Datos con médico, entidad, año, liquidaciones, totales y hash
 * @return string Binario del documento PDF cifrado y protegido
 */
function generarPDFCertificadoTributario($datos) {
    $selectedAnio = intval($datos['selectedAnio'] ?? date('Y'));
    $textoVigencia = $datos['textoVigenciaFiscalLarga'] ?? ("01 de Enero de {$selectedAnio} al 31 de Diciembre de {$selectedAnio}");
    $entidad = $datos['entidadInfo'] ?? [];
    $medico = $datos['medicoDatos'] ?? [];
    $totales = $datos['totales'] ?? [];
    $liquidaciones = $datos['liquidaciones'] ?? [];
    $hash = $datos['certificadoHash'] ?? '';

    // Cédula limpia que actúa como contraseña de apertura del PDF
    $cleanCedulaPassword = preg_replace('/[^0-9]/', '', (string)($medico['cedula'] ?? ''));
    if (empty($cleanCedulaPassword)) {
        $cleanCedulaPassword = '12345'; // Fallback de seguridad si no hay documento
    }

    $pdf = new CertificadoTributarioPDF('P', 'mm', 'Letter');
    $pdf->AliasNbPages();
    $pdf->anioGravable = (string)$selectedAnio;
    $pdf->medicoNombre = $medico['nombre'] ?? 'MÉDICO ESPECIALISTA';
    $pdf->medicoCedula = $medico['cedula'] ?? '';
    $pdf->entidadNombre = $entidad['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.';
    $pdf->certificadoHash = $hash;

    // Configurar protección y contraseña: clave de apertura = cédula del médico
    $pdf->SetProtection(array('print', 'copy'), $cleanCedulaPassword);

    // =========================================================================
    // HOJA 1: CERTIFICADO OFICIAL
    // =========================================================================
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);

    // 1. Tarjeta de Metadatos del Médico y Periodo Fiscal
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Rect(10, 32, 196, 21, 'DF');

    // Columna 1 (Izquierda)
    $pdf->SetXY(13, 33.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(38, 4.5, utf8_decode('PROFESIONAL / MÉDICO:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(68, 4.5, utf8_decode(substr(strtoupper($medico['nombre'] ?? 'MÉDICO ESPECIALISTA'), 0, 36)), 0, 0, 'L');

    // Columna 2 (Derecha)
    $pdf->SetXY(120, 33.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(28, 4.5, utf8_decode('CÉDULA / ID:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(54, 4.5, utf8_decode($medico['cedula'] ?? ''), 0, 1, 'L');

    // Fila 2
    $pdf->SetXY(13, 38.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(38, 4.5, utf8_decode('PERIODO CERTIFICADO:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7.8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(68, 4.5, utf8_decode($textoVigencia), 0, 0, 'L');

    $pdf->SetXY(120, 38.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(28, 4.5, utf8_decode('FECHA EMISIÓN:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7.8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(54, 4.5, date('d/m/Y h:i A'), 0, 1, 'L');

    // Fila 3
    $pdf->SetXY(13, 43.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(38, 4.5, utf8_decode('ENTIDAD RETENEDORA:'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7.8);
    $pdf->SetTextColor(15, 23, 42);
    $nitEnt = ($entidad['nit'] ?? '800149695') . (!empty($entidad['dv']) ? '-' . $entidad['dv'] : '-1');
    $pdf->Cell(68, 4.5, utf8_decode(($entidad['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.') . ' (NIT: ' . $nitEnt . ')'), 0, 0, 'L');

    $pdf->SetXY(120, 43.5);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(28, 4.5, utf8_decode('CERTIFICADO POR:'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 7.8);
    $pdf->SetTextColor(13, 148, 136);
    $pdf->Cell(54, 4.5, utf8_decode('Dirección Financiera / Contabilidad'), 0, 1, 'L');

    // 2. Cláusula Legal Normativa
    $pdf->SetY(55.5);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetFont('Arial', '', 7.2);
    $pdf->SetTextColor(71, 85, 105);
    $clausula = 'Expedido en cumplimiento del Artículo 381 del Estatuto Tributario Nacional y el Artículo 383 para personas naturales que perciben rentas de trabajo u honorarios médicos independientes.';
    $pdf->Cell(196, 5.5, utf8_decode($clausula), 1, 1, 'C', true);

    // 3. Título de la Tabla Consolidada
    $pdf->SetY(63);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(130, 5, utf8_decode('1. CONSOLIDADO DE INGRESOS BRUTOS Y RETENCIONES PRACTICADAS'), 0, 0, 'L');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(66, 5, utf8_decode('Valores en Pesos Colombianos (COP)'), 0, 1, 'R');

    // Encabezado de la Tabla
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(98, 6, utf8_decode('CONCEPTO FISCAL'), 1, 0, 'L', true);
    $pdf->Cell(32, 6, utf8_decode('REFERENCIA LEGAL'), 1, 0, 'C', true);
    $pdf->Cell(22, 6, utf8_decode('% TARIFA'), 1, 0, 'C', true);
    $pdf->Cell(44, 6, utf8_decode('VALOR CONSOLIDADO'), 1, 1, 'R', true);

    // Cálculo de porcentajes para conceptos fiscales
    $brutoVal   = floatval($totales['bruto'] ?? 0);
    $ibcVal     = floatval($totales['ibc'] ?? 0);
    $saludVal   = floatval($totales['salud'] ?? 0);
    $pensionVal = floatval(($totales['pension'] ?? 0) + ($totales['solidaridad'] ?? 0));
    $arlVal     = floatval($totales['arl'] ?? 0);
    $afcVal     = floatval($totales['afc'] ?? 0);
    $rete383Val = floatval($totales['rete_383'] ?? 0);
    $reteFteVal = floatval($totales['rete_fuente'] ?? 0);
    $totReteVal = floatval($totales['total_rete'] ?? 0);
    $netoVal    = floatval($totales['neto_pagado'] ?? 0);

    $pctIbcCol        = ($ibcVal > 0) ? '40.0%' : '';
    $pctSaludCol      = ($saludVal > 0) ? '12.5%' : '';
    $pctPensionCol    = ($pensionVal > 0) ? '16.0%' : '';
    $pctArlCol        = ($arlVal > 0) ? '2.436%' : '';
    $pctAfcCol        = ($afcVal > 0 && $brutoVal > 0) ? number_format(($afcVal / $brutoVal) * 100, 2, '.', '') . '%' : '';
    $pctRete383Col    = ''; // Se retira para Art. 383 E.T. ya que aplica tabla progresiva en UVT, no una tarifa porcentual fija
    $pctReteFuenteCol = ($reteFteVal > 0 && $brutoVal > 0) ? number_format(($reteFteVal / $brutoVal) * 100, 2, '.', '') . '%' : '';
    $pctTotReteCol    = ($totReteVal > 0 && $brutoVal > 0) ? number_format(($totReteVal / $brutoVal) * 100, 2, '.', '') . '%' : '';
    $pctNetoCol       = ($netoVal > 0 && $brutoVal > 0) ? number_format(($netoVal / $brutoVal) * 100, 2, '.', '') . '%' : '';

    // Filas de Datos Dinámicas (Base de la retención y Retención aplicada)
    $itemsFiscales = [];
    $itemsFiscales[] = [
        'Base de la retención',
        'Art. 103 E.T.',
        '100%',
        $brutoVal,
        true
    ];

    if ($rete383Val > 0) {
        $itemsFiscales[] = [
            'Retención aplicada (Art. 383 E.T.)',
            'Art. 383 E.T.',
            $pctRete383Col,
            $rete383Val,
            false
        ];
    }

    if ($reteFteVal > 0) {
        $itemsFiscales[] = [
            'Retención aplicada (Art. 392 E.T.)',
            'Art. 392 E.T.',
            $pctReteFuenteCol,
            $reteFteVal,
            false
        ];
    }

    if ($rete383Val <= 0 && $reteFteVal <= 0) {
        $itemsFiscales[] = [
            'Retención aplicada (Sin retención practicada)',
            'Art. 381 E.T.',
            '',
            0,
            false
        ];
    }

    $fill = false;
    $pdf->SetDrawColor(226, 232, 240);

    foreach ($itemsFiscales as $item) {
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        $fill = !$fill;

        $isBold = $item[4];
        $pdf->SetFont('Arial', $isBold ? 'B' : '', 7.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(98, 5.2, utf8_decode(' ' . $item[0]), 1, 0, 'L', true);

        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(32, 5.2, utf8_decode($item[1]), 1, 0, 'C', true);

        $pdf->SetFont('Arial', $isBold ? 'B' : '', 7);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(22, 5.2, utf8_decode($item[2]), 1, 0, 'C', true);

        $pdf->SetFont('Arial', $isBold ? 'B' : '', 7.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(44, 5.2, '$ ' . number_format($item[3], 2, ',', '.') . ' ', 1, 1, 'R', true);
    }

    // Fila Total Retención Practicada
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(98, 6.2, utf8_decode(' TOTAL RETENCIÓN EN LA FUENTE PRACTICADA EN EL AÑO'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(32, 6.2, utf8_decode('Art. 381 E.T.'), 1, 0, 'C', true);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(253, 224, 71); // Amber-300
    $pdf->Cell(22, 6.2, utf8_decode($pctTotReteCol), 1, 0, 'C', true);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell(44, 6.2, '$ ' . number_format(floatval($totales['total_rete'] ?? 0), 2, ',', '.') . ' ', 1, 1, 'R', true);

    // Fila Total Neto Girado / Pagado
    $pdf->SetFillColor(13, 148, 136); // Teal-600
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(98, 7, utf8_decode(' VALOR NETO TOTAL GIRADO / PAGADO AL ESPECIALISTA'), 1, 0, 'L', true);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(32, 7, utf8_decode('NETO PAGADO'), 1, 0, 'C', true);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(204, 251, 241); // Teal-100
    $pdf->Cell(22, 7, utf8_decode($pctNetoCol), 1, 0, 'C', true);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->Cell(44, 7, '$ ' . number_format(floatval($totales['neto_pagado'] ?? 0), 2, ',', '.') . ' ', 1, 1, 'R', true);

    // 4. Sección de Firmas y Huella Criptográfica SHA-256
    $yFirma = max(124, $pdf->GetY() + 6);
    $pdf->SetY($yFirma);

    // Firma Digital Registrada (Izquierda)
    $pdf->SetDrawColor(15, 23, 42);
    $pdf->Line(13, $yFirma + 16, 95, $yFirma + 16);

    $pdf->SetXY(13, $yFirma + 2);
    $pdf->SetFont('Courier', 'B', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(82, 4, utf8_decode('[ FIRMA DIGITAL REGISTRADA ]'), 0, 1, 'L');

    $pdf->SetXY(13, $yFirma + 17);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(82, 4, utf8_decode('DIRECCIÓN FINANCIERA / CONTADOR PÚBLICO'), 0, 1, 'L');

    $pdf->SetX(13);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(82, 3.5, utf8_decode($entidad['nombre'] ?? 'HERNÁN OCAZIONEZ Y CÍA S.A.S.'), 0, 1, 'L');

    $pdf->SetX(13);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(82, 3.5, utf8_decode('T.P. No. 129482-T | NIT: ' . $nitEnt), 0, 1, 'L');

    // Caja Huella Criptográfica SHA-256 (Derecha)
    $pdf->SetFillColor(240, 253, 250); // Teal light (#f0fdfa)
    $pdf->SetDrawColor(13, 148, 136); // Teal border
    $pdf->Rect(102, $yFirma - 1, 104, 30, 'DF');

    $pdf->SetXY(105, $yFirma + 1);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(13, 148, 136);
    $pdf->Cell(98, 4, utf8_decode('HUELLA DIGITAL DE INTEGRIDAD CRIPTOGRÁFICA (SHA-256):'), 0, 1, 'L');

    $pdf->SetX(105);
    $pdf->SetFont('Courier', 'B', 7);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->MultiCell(98, 3.2, $hash, 0, 'L');

    $pdf->SetXY(105, $yFirma + 17);
    $pdf->SetDrawColor(204, 251, 241);
    $pdf->Line(105, $yFirma + 17, 203, $yFirma + 17);

    $pdf->SetXY(105, $yFirma + 18.5);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->MultiCell(98, 3, utf8_decode('Esta firma criptográfica certifica la inmutabilidad y autenticidad del presente certificado contable emitido en la plataforma LIHO para efectos tributarios DIAN.'), 0, 'L');

    // 5. Cláusula Legal Exoneración de Firma Autógrafa
    $pdf->SetY($yFirma + 34);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(100, 116, 139);
    $textoExon = 'De conformidad con el Artículo 10 del Decreto 836 de 1991 (compilado en el Decreto Único Reglamentario 1625 de 2016), este certificado no requiere firma autógrafa para su plena validez jurídica y probatoria ante la Dirección de Impuestos y Aduanas Nacionales (DIAN).';
    $pdf->MultiCell(196, 3.5, utf8_decode($textoExon), 0, 'C');

    // =========================================================================
    // HOJA 2: ANEXO DETALLADO CRONOLÓGICO DE LIQUIDACIONES
    // =========================================================================
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(false);

    $pdf->SetY(33);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(130, 5, utf8_decode('RELACIÓN CRONOLÓGICA DE TURNOS Y LIQUIDACIONES DEL PERIODO FISCAL'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetTextColor(13, 148, 136);
    $pdf->Cell(66, 5, utf8_decode(count($liquidaciones) . ' liquidaciones aprobadas'), 0, 1, 'R');

    // Encabezado de la Tabla del Anexo
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(46, 6, utf8_decode('ID / PERIODO'), 1, 0, 'L', true);
    $pdf->Cell(30, 6, utf8_decode('HONORARIOS BRUTOS'), 1, 0, 'R', true);
    $pdf->Cell(25, 6, utf8_decode('RETE 383'), 1, 0, 'R', true);
    $pdf->Cell(25, 6, utf8_decode('RETE TRAD.'), 1, 0, 'R', true);
    $pdf->Cell(26, 6, utf8_decode('SEG. SOCIAL'), 1, 0, 'R', true);
    $pdf->Cell(26, 6, utf8_decode('NETO PAGADO'), 1, 0, 'R', true);
    $pdf->Cell(18, 6, utf8_decode('ESTADO'), 1, 1, 'C', true);

    $fillAnexo = false;
    $maxRowsHoja2 = 28;
    $rowCount = 0;

    foreach ($liquidaciones as $l) {
        if ($rowCount >= $maxRowsHoja2) break; // Control de desborde en hoja carta
        $rowCount++;

        $segSoc = floatval($l['ded_salud'] ?? 0) + floatval($l['ded_pension'] ?? 0) + floatval($l['ded_arl'] ?? 0);
        $periodoStr = ($l['periodo_desde'] ?? '') . ' al ' . ($l['periodo_hasta'] ?? '');
        $estado = strtoupper($l['estado'] ?? 'APROBADA');

        $pdf->SetFillColor($fillAnexo ? 248 : 255, $fillAnexo ? 250 : 255, $fillAnexo ? 252 : 255);
        $fillAnexo = !$fillAnexo;

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(12, 5, '#' . $l['id'], 1, 0, 'L', true);

        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(34, 5, utf8_decode($periodoStr), 1, 0, 'L', true);

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(30, 5, '$ ' . number_format(floatval($l['total_factura'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);

        $pdf->SetFont('Arial', '', 6.8);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(25, 5, '$ ' . number_format(floatval($l['ded_rete_383'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);
        $pdf->Cell(25, 5, '$ ' . number_format(floatval($l['ded_retencion'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);
        $pdf->Cell(26, 5, '$ ' . number_format($segSoc, 0, ',', '.') . ' ', 1, 0, 'R', true);

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(13, 148, 136);
        $pdf->Cell(26, 5, '$ ' . number_format(floatval($l['total_a_pagar'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);

        $pdf->SetFont('Arial', 'B', 6);
        $pdf->SetTextColor(16, 185, 129); // emerald-500
        $pdf->Cell(18, 5, utf8_decode(substr($estado, 0, 8)), 1, 1, 'C', true);
    }

    // Fila de Totales del Anexo
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(46, 6, utf8_decode(' TOTALES DEL PERIODO FISCAL'), 1, 0, 'L', true);

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell(30, 6, '$ ' . number_format(floatval($totales['bruto'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);
    $pdf->Cell(25, 6, '$ ' . number_format(floatval($totales['rete_383'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);
    $pdf->Cell(25, 6, '$ ' . number_format(floatval($totales['rete_fuente'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);

    $totSegSoc = floatval($totales['salud'] ?? 0) + floatval($totales['pension'] ?? 0) + floatval($totales['arl'] ?? 0);
    $pdf->Cell(26, 6, '$ ' . number_format($totSegSoc, 0, ',', '.') . ' ', 1, 0, 'R', true);

    $pdf->SetFillColor(13, 148, 136);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell(26, 6, '$ ' . number_format(floatval($totales['neto_pagado'] ?? 0), 0, ',', '.') . ' ', 1, 0, 'R', true);

    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->Cell(18, 6, ($totales['cantidad_liq'] ?? 0) . ' turnos', 1, 1, 'C', true);

    return $pdf->Output('S');
}

<?php
/**
 * Helper para envío de correos vía SMTP sin librerías externas - LIHO
 * Con soporte para imágenes incrustadas CID, texto plano anti-SPAM y cabeceras de autenticidad RFC.
 */
function enviarCorreoSMTP($to, $subject, $bodyHTML, $config = null, $embeddedImages = array(), $plainTextAlt = '', $cc = array('coordinacionsistemas@hernanocazionez.com.co', 'juane6462@gmail.com', 'contabilidad2@hernanocazionez.com'), $attachments = array()) {
    if ($config === null) {
        $configFile = __DIR__ . '/../config/config_smtp.php';
        if (file_exists($configFile)) {
            $config = include($configFile);
        } else {
            $config = [];
        }
    }

    $host     = $config['host'] ?? 'smtp.gmail.com';
    $port     = $config['port'] ?? 587;
    $username = $config['username'] ?? '';
    $password = str_replace(' ', '', $config['password'] ?? '');
    $from     = $config['from'] ?? $username;
    $fromName = $config['fromName'] ?? 'LIHO | Hernán Ocazionez';

    // Normalizar y validar destinatarios To y CC
    $limpiarCorreos = function($lista) {
        $resultado = array();
        $items = is_array($lista) ? $lista : explode(',', (string)$lista);
        foreach ($items as $email) {
            $emailLimpio = strtolower(trim($email));
            if (!empty($emailLimpio) && filter_var($emailLimpio, FILTER_VALIDATE_EMAIL)) {
                $resultado[] = $emailLimpio;
            }
        }
        return array_values(array_unique($resultado));
    };

    $toAddresses = $limpiarCorreos($to);
    $ccAddresses = $limpiarCorreos($cc);

    // Destinatario principal por defecto si está vacío
    if (empty($toAddresses)) {
        $toAddresses = array('coordinacionsistemas@hernanocazionez.com');
    }

    // Interceptor de Seguridad: Bloqueo de Correos a Médicos en Modo Desarrollo
    require_once __DIR__ . '/config_helper.php';
    if (function_exists('estanCorreosMedicosBloqueados') && estanCorreosMedicosBloqueados()) {
        $docsBloqueados = [];
        $toFiltrados = [];
        foreach ($toAddresses as $email) {
            if (esCorreoDeMedico($email)) {
                $docsBloqueados[] = $email;
            } else {
                $toFiltrados[] = $email;
            }
        }

        $ccFiltrados = [];
        foreach ($ccAddresses as $email) {
            if (esCorreoDeMedico($email)) {
                $docsBloqueados[] = $email;
            } else {
                $ccFiltrados[] = $email;
            }
        }

        $docsBloqueados = array_values(array_unique($docsBloqueados));

        if (!empty($docsBloqueados)) {
            $cfgSis = obtenerConfiguracionSistema();
            $emailRedir = strtolower(trim($cfgSis['email_test_redireccion'] ?? ''));

            if (!empty($emailRedir) && filter_var($emailRedir, FILTER_VALIDATE_EMAIL)) {
                // Redirigir hacia el buzón de pruebas el componente de médicos
                // Los administradores y personal interno se conservan para que reciban su copia normalmente
                $toAddresses = array_values(array_unique(array_merge([$emailRedir], $toFiltrados)));
                $ccAddresses = array_values(array_unique($ccFiltrados));

                $subject = "[TEST DESARROLLO - MÉDICO BLOQUEADO: " . implode(', ', $docsBloqueados) . "] " . $subject;
                $avisoSeguridad = "<div style='background:#fef3c7; border:2px solid #f59e0b; padding:12px 16px; border-radius:10px; margin-bottom:18px; font-family:Arial,sans-serif; font-size:13px; color:#92400e; line-height:1.5;'>"
                    . "<strong style='color:#b45309;'>MODO DESARROLLO - SEGURIDAD DE CORREO ACTIVA:</strong><br/>"
                    . "Este correo fue generado para el/los médico(s): <code>" . htmlspecialchars(implode(', ', $docsBloqueados)) . "</code>.<br/>"
                    . "Debido a que el aplicativo está en desarrollo, el envío a médicos reales fue <strong>interceptado y bloqueado</strong>. "
                    . "El mensaje se redirigió a este buzón de pruebas (<code>" . htmlspecialchars($emailRedir) . "</code>) para revisión.</div>";
                $bodyHTML = $avisoSeguridad . $bodyHTML;
                $plainTextAlt = "[TEST DESARROLLO - MÉDICO BLOQUEADO: " . implode(', ', $docsBloqueados) . "]\n\n" . $plainTextAlt;
            } else {
                // Si no hay redirección configurada, remover a los médicos y conservar a los administradores
                $toAddresses = $toFiltrados;
                $ccAddresses = $ccFiltrados;

                // Si no queda ningún destinatario, abortar el envío
                if (empty($toAddresses)) {
                    require_once __DIR__ . '/logger_helper.php';
                    if (function_exists('registrar_log_sistema')) {
                        registrar_log_sistema('CORREOS', 'ENVIO_BLOQUEADO_DESARROLLO', 'SEGURIDAD', "Envío a médicos bloqueado: " . implode(', ', $docsBloqueados) . " | Asunto: {$subject}", [
                            'entidad_afectada' => substr($subject, 0, 240),
                            'nivel' => 'WARNING'
                        ]);
                    }
                    return false;
                }
            }
        }
    }

    // Generación de versión en texto plano (anti-SPAM)
    if (empty($plainTextAlt)) {
        $plainTextAlt = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $bodyHTML));
        $plainTextAlt = trim(preg_replace("/\n\s+/", "\n", $plainTextAlt));
    }

    // Identificadores MIME y cabecera Message-ID obligatoria para anti-SPAM
    $boundaryMixed = "----=_Part_Mixed_" . md5(uniqid(microtime(), true));
    $boundaryRel   = "----=_Part_Rel_" . md5(uniqid(microtime(), true));
    $boundaryAlt   = "----=_Part_Alt_" . md5(uniqid(microtime(), true));

    $hasEmbedded    = !empty($embeddedImages);
    $hasAttachments = !empty($attachments);
    $domain = (strpos($from, '@') !== false) ? substr(strrchr($from, "@"), 1) : 'gmail.com';
    $msgId = "<" . md5(uniqid(microtime(), true)) . "@" . $domain . ">";

    // 1. Sub-payload de contenido (Texto plano + HTML)
    $contentPayload  = "--{$boundaryAlt}\r\n";
    $contentPayload .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $contentPayload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $contentPayload .= $plainTextAlt . "\r\n\r\n";

    $contentPayload .= "--{$boundaryAlt}\r\n";
    $contentPayload .= "Content-Type: text/html; charset=UTF-8\r\n";
    $contentPayload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $contentPayload .= $bodyHTML . "\r\n\r\n";
    $contentPayload .= "--{$boundaryAlt}--\r\n";

    // 2. Si tiene imágenes incrustadas CID, envolver en multipart/related
    if ($hasEmbedded) {
        $bodyRelated  = "--{$boundaryRel}\r\n";
        $bodyRelated .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";
        $bodyRelated .= $contentPayload . "\r\n";

        foreach ($embeddedImages as $cid => $imgPath) {
            if (file_exists($imgPath)) {
                $imgData = file_get_contents($imgPath);
                $base64Img = chunk_split(base64_encode($imgData));
                $filename = basename($imgPath);
                $mimeType = preg_match('/\.(jpg|jpeg)$/i', $filename) ? 'image/jpeg' : 'image/png';

                $bodyRelated .= "--{$boundaryRel}\r\n";
                $bodyRelated .= "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n";
                $bodyRelated .= "Content-Transfer-Encoding: base64\r\n";
                $bodyRelated .= "Content-ID: <{$cid}>\r\n";
                $bodyRelated .= "Content-Disposition: inline; filename=\"{$filename}\"\r\n\r\n";
                $bodyRelated .= $base64Img . "\r\n";
            }
        }
        $bodyRelated .= "--{$boundaryRel}--\r\n";
        $bodyInner = $bodyRelated;
        $bodyInnerType = "multipart/related; boundary=\"{$boundaryRel}\"";
    } else {
        $bodyInner = $contentPayload;
        $bodyInnerType = "multipart/alternative; boundary=\"{$boundaryAlt}\"";
    }

    // 3. Si tiene adjuntos (PDF, Excel/CSV), envolver todo en multipart/mixed
    if ($hasAttachments) {
        $mimeContentType = "multipart/mixed; boundary=\"{$boundaryMixed}\"";

        $mailPayload  = "--{$boundaryMixed}\r\n";
        $mailPayload .= "Content-Type: {$bodyInnerType}\r\n\r\n";
        $mailPayload .= $bodyInner . "\r\n";

        foreach ($attachments as $att) {
            $attName = $att['name'] ?? 'adjunto.dat';
            $attType = $att['type'] ?? 'application/octet-stream';
            $attData = '';
            if (isset($att['data'])) {
                $attData = $att['data'];
            } elseif (isset($att['path']) && file_exists($att['path'])) {
                $attData = file_get_contents($att['path']);
            }

            if (!empty($attData)) {
                $base64Att = chunk_split(base64_encode($attData));
                $mailPayload .= "--{$boundaryMixed}\r\n";
                $mailPayload .= "Content-Type: {$attType}; name=\"{$attName}\"\r\n";
                $mailPayload .= "Content-Disposition: attachment; filename=\"{$attName}\"\r\n";
                $mailPayload .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $mailPayload .= $base64Att . "\r\n";
            }
        }

        $mailPayload .= "--{$boundaryMixed}--\r\n";
    } else {
        $mimeContentType = $bodyInnerType;
        $mailPayload = $bodyInner;
    }

    // Cabeceras anti-SPAM de estándar RFC
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: {$mimeContentType}\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
    $headers .= "Reply-To: <{$from}>\r\n";
    $headers .= "To: " . implode(', ', array_map(function($e){ return "<$e>"; }, $toAddresses)) . "\r\n";
    if (!empty($ccAddresses)) {
        $headers .= "Cc: " . implode(', ', array_map(function($e){ return "<$e>"; }, $ccAddresses)) . "\r\n";
    }
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "Date: " . date("r") . "\r\n";
    $headers .= "Message-ID: {$msgId}\r\n";
    $headers .= "X-Mailer: LIHO Operational Portal v1.0\r\n";
    $headers .= "X-Priority: 3 (Normal)\r\n";

    if (empty($username) || empty($password)) {
        $firstTo = $toAddresses[0] ?? '';
        return @mail($firstTo, $subject, $mailPayload, $headers);
    }

    $timeout = 15;
    $socketHost = ($port == 465) ? "ssl://" . $host : $host;
    $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);

    if (!$socket) {
        return false;
    }

    $getResponse = function($sock) {
        $res = '';
        while ($line = fgets($sock, 512)) {
            $res .= $line;
            if (substr($line, 3, 1) == " ") break;
        }
        return $res;
    };

    $getResponse($socket);

    fwrite($socket, "EHLO " . gethostname() . "\r\n");
    $getResponse($socket);

    if ($port == 587) {
        fwrite($socket, "STARTTLS\r\n");
        $res = $getResponse($socket);
        if (substr($res, 0, 3) != "220") {
            fclose($socket);
            return false;
        }

        $cryptoRes = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if (!$cryptoRes) {
            fclose($socket);
            return false;
        }

        fwrite($socket, "EHLO " . gethostname() . "\r\n");
        $getResponse($socket);
    }

    fwrite($socket, "AUTH LOGIN\r\n");
    $getResponse($socket);

    fwrite($socket, base64_encode($username) . "\r\n");
    $getResponse($socket);

    fwrite($socket, base64_encode($password) . "\r\n");
    $authRes = $getResponse($socket);

    if (substr($authRes, 0, 3) != "235") {
        fclose($socket);
        return false;
    }

    fwrite($socket, "MAIL FROM: <$from>\r\n");
    $getResponse($socket);

    // Enviar RCPT TO para cada destinatario To
    foreach ($toAddresses as $addr) {
        if (!empty($addr)) {
            fwrite($socket, "RCPT TO: <$addr>\r\n");
            $getResponse($socket);
        }
    }

    // Enviar RCPT TO para cada destinatario CC
    foreach ($ccAddresses as $ccAddr) {
        if (!empty($ccAddr)) {
            fwrite($socket, "RCPT TO: <$ccAddr>\r\n");
            $getResponse($socket);
        }
    }

    fwrite($socket, "DATA\r\n");
    $getResponse($socket);

    $fullPayload = $headers . "\r\n" . $mailPayload . "\r\n.\r\n";
    $payloadLength = strlen($fullPayload);
    $chunkSize = 65536; // 64 KB
    for ($written = 0; $written < $payloadLength; $written += $chunkSize) {
        $chunk = substr($fullPayload, $written, $chunkSize);
        fwrite($socket, $chunk);
    }
    $dataRes = $getResponse($socket);

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    $isSuccess = (substr($dataRes, 0, 3) == "250");

    // Registro automático de auditoría de envío de correo en logs_sistema
    require_once __DIR__ . '/logger_helper.php';
    if (function_exists('registrar_log_sistema')) {
        $toListStr = implode(', ', $toAddresses);
        $ccListStr = !empty($ccAddresses) ? ' | CC: ' . implode(', ', $ccAddresses) : '';
        $det = "Destinatarios: {$toListStr}{$ccListStr} | Adjuntos: " . count($attachments) . " archivo(s)";
        if (!$isSuccess) {
            $det .= " | Respuesta SMTP: " . trim($dataRes);
        }

        registrar_log_sistema('CORREOS', 'ENVIO_CORREO_SMTP', 'ENVIO_CORREO', $det, [
            'entidad_afectada' => substr($subject, 0, 240),
            'nivel' => $isSuccess ? 'SUCCESS' : 'ERROR'
        ]);
    }

    return $isSuccess;
}
?>

<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR para el evento ID 50339 con calendario superpuesto y badge optimizado.
 * Version: 3.4.2
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

function optimizar_imagen_para_pdf($imagen_url, $upload_dir){
    $tmp = null;
    $imagen_path = '';
    $attachment_id = attachment_url_to_postid($imagen_url);

    if ($attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if ($meta && isset($meta['file'])) {
            $imagen_path = $upload_dir['basedir'].'/'.$meta['file'];
        }
    }

    if (!file_exists($imagen_path) && function_exists('download_url')) {
        $tmp = download_url($imagen_url, 300);
        if (!is_wp_error($tmp)) {
            $imagen_path = $tmp;
        }
    }

    return ['path' => $imagen_path, 'tmp' => $tmp];
}

add_action(
    'jet-form-builder/custom-action/inscripciones_qr',
    'generar_qr_pdf_personalizado',
    10,
    3
);

function generar_qr_pdf_personalizado($request, $action_handler) {

    try {
        $post_id = 50339;

        // Asistente
        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos = sanitize_text_field($request['apellidos'] ?? '');
        $nombre_completo = html_entity_decode(trim("$nombre $apellidos"), ENT_QUOTES, 'UTF-8');

        // Evento
        $titulo_evento = get_the_title($post_id);

        $ubicacion_raw = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $ubicacion = html_entity_decode($ubicacion_raw, ENT_QUOTES, 'UTF-8');

        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $ts = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);

        $dia  = date('d', $ts);
        $mes  = strtoupper(date_i18n('M', $ts));
        $ano  = date('Y', $ts);
        $fecha_formateada = date('d/m/Y H:i', $ts);

        $upload_dir = wp_upload_dir();

        /**
         * QR
         */
        $qr_url = home_url('/checkin/?') . http_build_query([
            'empresa' => $nombre_empresa,
            'nombre'  => $nombre_completo,
            'evento'  => $titulo_evento,
        ]);

        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($qr_url)
            ->size(300)
            ->margin(10)
            ->build();

        $qr_path = $upload_dir['basedir'].'/qr_'.uniqid().'.png';
        $qr->saveToFile($qr_path);

        /**
         * PDF
         */
        $pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8,8,8);
        $pdf->SetAutoPageBreak(false,0);
        $pdf->AddPage();

        $pdf->SetFillColor(245,245,247);
        $pdf->RoundedRect(8,8,194,279,6,'1111','F');

        /**
         * IMAGEN SUPERIOR
         */
        $y_actual = 8;
        $img_url = get_the_post_thumbnail_url($post_id,'full');

        if ($img_url) {
            $img = optimizar_imagen_para_pdf($img_url, $upload_dir);
            if (file_exists($img['path'])) {
                list($w,$h) = getimagesize($img['path']);
                $alto = min(($h * 194) / $w, 100);

                $pdf->StartTransform();
                $pdf->RoundedRect(8,8,194,$alto,6,'1111','CNZ');
                $pdf->Image($img['path'],8,8,194,$alto);
                $pdf->StopTransform();

                $y_actual = 8 + $alto;
            }
        }

        /**
         * CALENDARIO ULTRA COMPACTO (Día + Mes)
         */
        $cal_x = 14; 
        $cal_y = 14; 
        $cal_w = 26;   // mismo ancho para día y mes (más estrecho)
        $cal_h = 22;   // altura total reducida

        // Sombra sutil
        $pdf->SetFillColor(215, 215, 215);
        $pdf->RoundedRect($cal_x + 0.4, $cal_y + 0.4, $cal_w, $cal_h, 2.5, '1111', 'F');

        // Fondo blanco
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($cal_x, $cal_y, $cal_w, $cal_h, 2.5, '1111', 'F');

        // Día (más pequeño pero dominante)
        $pdf->SetTextColor(35, 35, 35);
        $pdf->SetFont('helvetica', 'B', 26);
        $pdf->SetXY($cal_x, $cal_y + 2);
        $pdf->Cell($cal_w, 12, $dia, 0, 0, 'C');

        // Mes (mismo ancho, más discreto)
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetXY($cal_x, $cal_y + 14);
        $pdf->Cell($cal_w, 6, ucfirst(strtolower($mes)), 0, 0, 'C');

        /**
         * BADGE CONFIRMACIÓN (Centrado Dinámico Real)
         */
        $y_actual += 8;
        $badge_w = 145;
        $badge_x = (210 - $badge_w) / 2;
        $badge_h = 11;

        // Dibujar fondo del badge
        $pdf->SetFillColor(76, 175, 80);
        $pdf->RoundedRect($badge_x, $y_actual, $badge_w, $badge_h, 3, '1111', 'F');

        $pdf->SetTextColor(255, 255, 255);
        $text_y = $y_actual + 2.5;

        // --- CÁLCULO DE CENTRADO DINÁMICO ---
        $texto_confirmacion = 'ENTRADA CONFIRMADA';
        $pdf->SetFont('helvetica', 'B', 12);
        $ancho_texto_real = $pdf->GetStringWidth($texto_confirmacion);
        
        $tick_w  = 6;  // Ancho del icono
        $space   = 2;  // Espacio entre icono y texto
        
        // Sumamos todo el contenido interno para saber cuánto mide el bloque
        $total_contenido_w = $tick_w + $space + $ancho_texto_real;

        // La X inicial será la mitad del PDF (105) menos la mitad de lo que mide el bloque
        $start_x = (210 - $total_contenido_w) / 2;

        // Dibujar Tick (Icono)
        $pdf->SetFont('zapfdingbats', '', 12);
        $pdf->SetXY($start_x, $text_y);
        $pdf->Cell($tick_w, 6, '3', 0, 0, 'C');

        // Dibujar Texto
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY($start_x + $tick_w + $space, $text_y);
        $pdf->Cell($ancho_texto_real, 6, $texto_confirmacion, 0, 0, 'L');

        /**
         * ✅ FECHA Y UBICACIÓN (AHORA DEBAJO DEL BADGE)
         */
        $y_actual += 16;
        $pdf->SetFont('helvetica','',10);
        $pdf->SetTextColor(100,100,100);
        $pdf->SetXY(15, $y_actual);
        $pdf->MultiCell(180,5,'FECHA: '.$fecha_formateada.' | LUGAR: '.$ubicacion,0,'C');

        /**
         * ASISTENTE
         */
        $pdf->Ln(6);
        $pdf->SetFont('helvetica','B',24);
        $pdf->SetTextColor(60,60,65);
        $pdf->Cell(0,12,$nombre_completo,0,1,'C');

        $pdf->SetFont('helvetica','B',14);
        $pdf->SetTextColor(100,100,105);
        $pdf->Cell(0,8,mb_strtoupper($nombre_empresa,'UTF-8'),0,1,'C');

        /**
         * QR
         */
        $y_qr = $pdf->GetY() + 10;
        $qr_size = 65;
        $qr_x = (210 - $qr_size) / 2;

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($qr_x - 4,$y_qr,$qr_size + 8,$qr_size + 8,4,'1111','F');
        $pdf->Image($qr_path,$qr_x,$y_qr + 4,$qr_size,$qr_size);

        $slug = preg_replace('/[^a-z0-9]+/','-',strtolower(remove_accents($nombre_completo)));
        $pdf->Output($upload_dir['basedir'].'/entrada_'.$slug.'_'.time().'.pdf','F');

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log('❌ Error PDF: '.$e->getMessage());
    }
}

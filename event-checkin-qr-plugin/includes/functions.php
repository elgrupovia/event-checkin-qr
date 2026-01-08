<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR para el evento ID 50339. Diseño corregido y compacto.
 * Version: 2.9.3
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * ---------------------------
 * Funciones de Utilidad
 * ---------------------------
 */
function optimizar_imagen_para_pdf($imagen_url, $upload_dir){
    $tmp = null; 
    $imagen_path = '';
    $attachment_id = attachment_url_to_postid($imagen_url);

    if($attachment_id){
        $imagen_meta = wp_get_attachment_metadata($attachment_id);
        if($imagen_meta && isset($imagen_meta['file'])){
            $imagen_path = $upload_dir['basedir'].'/'.$imagen_meta['file'];
        }
    }

    if(!file_exists($imagen_path) && function_exists('download_url')){
        $tmp = download_url($imagen_url, 300);
        if(!is_wp_error($tmp)) $imagen_path = $tmp;
    }

    return ['path'=>$imagen_path,'tmp'=>$tmp];
}

/**
 * ---------------------------
 * Generar PDF con QR
 * ---------------------------
 */
add_action(
    'jet-form-builder/custom-action/inscripciones_qr',
    'generar_qr_pdf_personalizado',
    10,
    3
);

function generar_qr_pdf_personalizado($request, $action_handler) {

    try {
        $post_id = 50339;

        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre_persona = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos_persona = sanitize_text_field($request['apellidos'] ?? '');
        $nombre_completo = html_entity_decode(
            trim($nombre_persona.' '.$apellidos_persona),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $titulo_evento = get_the_title($post_id);
        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';

        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $fecha_timestamp = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);

        $dia = date('d', $fecha_timestamp);
        $mes_nombre = strtoupper(date_i18n('M', $fecha_timestamp));
        $ano = date('Y', $fecha_timestamp);

        $upload_dir = wp_upload_dir();

        /**
         * -------- QR --------
         */
        $params = [
            'empresa' => rawurlencode($nombre_empresa),
            'nombre'  => rawurlencode($nombre_completo),
            'evento'  => rawurlencode($titulo_evento),
        ];

        $qr_url = home_url('/checkin/') . '?' . http_build_query($params);

        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($qr_url)
            ->size(300)
            ->margin(10)
            ->build();

        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        /**
         * -------- PDF --------
         */
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(8, 8, 8);
        $pdf->AddPage();

        // Fondo
        $pdf->SetFillColor(245, 245, 247);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', 'F');

        $y_cursor = 8;

        /**
         * -------- FOTO --------
         */
        $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
        if ($imagen_url) {
            $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
            if (file_exists($imagen_info['path'])) {
                list($w, $h) = getimagesize($imagen_info['path']);
                $ancho_pdf = 194;
                $alto_pdf = ($h * $ancho_pdf) / $w;

                $pdf->StartTransform();
                $pdf->RoundedRect(8, 8, $ancho_pdf, $alto_pdf, 6, '1111', 'CNZ');
                $pdf->Image($imagen_info['path'], 8, 8, $ancho_pdf, $alto_pdf);
                $pdf->StopTransform();

                $y_cursor = 8 + $alto_pdf + 8;
            }
        }

        /**
         * -------- CALENDARIO + UBICACIÓN --------
         */
        $pdf->SetAbsY($y_cursor);

        $cal_x = 20;
        $cal_w = 38;
        $cal_h = 35;

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($cal_x, $y_cursor, $cal_w, $cal_h, 3, '1111', 'F');

        $pdf->SetFillColor(30,30,30);
        $pdf->RoundedRect($cal_x, $y_cursor, $cal_w, 8, 3, '1100', 'F');

        $pdf->SetTextColor(255,255,255);
        $pdf->SetFont('helvetica','B',10);
        $pdf->SetXY($cal_x, $y_cursor + 1.5);
        $pdf->Cell($cal_w,5,$mes_nombre,0,0,'C');

        $pdf->SetTextColor(30,30,30);
        $pdf->SetFont('helvetica','B',22);
        $pdf->SetXY($cal_x, $y_cursor + 10);
        $pdf->Cell($cal_w,15,$dia,0,0,'C');

        $pdf->SetFont('helvetica','',9);
        $pdf->SetXY($cal_x, $y_cursor + 26);
        $pdf->Cell($cal_w,5,$ano,0,0,'C');

        // Ubicación
        $pdf->SetFont('dejavusans','B',11);
        $pdf->SetTextColor(80,80,80);
        $pdf->SetXY($cal_x + $cal_w + 10, $y_cursor + 5);
        $pdf->Cell(0,5,'📍 UBICACIÓN',0,1);

        $pdf->SetFont('helvetica','',11);
        $pdf->SetX($cal_x + $cal_w + 10);
        $pdf->MultiCell(100,5,$ubicacion);

        // ⭐ CAMBIO CLAVE
        $y_after_location = $pdf->GetY();

        /**
         * -------- ASISTENTE --------
         */
        $y_cursor = $y_after_location + 6; // ⭐ MÁS CERCA
        $pdf->SetAbsY($y_cursor);

        $pdf->SetFont('helvetica','B',22);
        $pdf->SetTextColor(60,60,65);
        $pdf->Cell(0,12,$nombre_completo,0,1,'C');

        $pdf->SetFont('helvetica','B',13);
        $pdf->SetTextColor(100,100,105);
        $pdf->Cell(0,8,mb_strtoupper($nombre_empresa,'UTF-8'),0,1,'C');

        /**
         * -------- QR --------
         */
        $y_cursor = $pdf->GetY() + 4; // ⭐ QR MÁS ARRIBA
        $qr_size = 65;
        $qr_x = (210 - $qr_size) / 2;

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($qr_x - 4, $y_cursor, $qr_size + 8, $qr_size + 8, 4, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $y_cursor + 4, $qr_size, $qr_size);

        /**
         * -------- CONFIRMADO --------
         */
        $y_final = 265;
        $badge_w = 70;
        $badge_x = (210 - $badge_w) / 2;

        $pdf->SetAbsY($y_final);
        $pdf->SetFillColor(76,175,80);
        $pdf->RoundedRect($badge_x, $y_final, $badge_w, 9, 3, '1111', 'F');

        $pdf->SetFont('dejavusans','B',10);
        $pdf->SetTextColor(255,255,255);
        $pdf->Cell(0,9,'✓ ENTRADA CONFIRMADA',0,1,'C');

        /**
         * -------- GUARDAR --------
         */
        $pdf_filename = 'entrada_' . time() . '.pdf';
        $pdf->Output($upload_dir['basedir'].'/'.$pdf_filename, 'F');
        @unlink($qr_path);

    } catch (Exception $e) {
        error_log('❌ PDF ERROR: '.$e->getMessage());
    }
}

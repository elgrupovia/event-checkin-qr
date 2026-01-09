<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR para el evento ID 50339 con calendario superpuesto y badge optimizado.
 * Version: 3.4.1
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * Utilidad: Optimizar imagen para PDF
 */
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

/**
 * Acción principal
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

        // Datos asistente
        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos = sanitize_text_field($request['apellidos'] ?? '');
        $nombre_completo = html_entity_decode(trim("$nombre $apellidos"), ENT_QUOTES, 'UTF-8');

        // Datos evento
        $titulo_evento = get_the_title($post_id);

        $ubicacion_raw = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $ubicacion = html_entity_decode($ubicacion_raw, ENT_QUOTES, 'UTF-8');

        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $ts = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);

        $dia = date('d', $ts);
        $mes = strtoupper(date_i18n('M', $ts));
        $ano = date('Y', $ts);
        $fecha_formateada = date('d/m/Y H:i', $ts);

        $upload_dir = wp_upload_dir();

        /**
         * ---------- Generar QR ----------
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

        $qr_path = $upload_dir['basedir'] . '/qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        /**
         * ---------- Configuración PDF ----------
         */
        $pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8,8,8);
        $pdf->SetAutoPageBreak(false,0);
        $pdf->AddPage();

        // Fondo tarjeta
        $pdf->SetFillColor(245,245,247);
        $pdf->RoundedRect(8,8,194,279,6,'1111','F');

        /**
         * 1. IMAGEN SUPERIOR
         */
        $img_url = get_the_post_thumbnail_url($post_id,'full');
        $y_actual = 8;

        if ($img_url) {
            $img = optimizar_imagen_para_pdf($img_url, $upload_dir);
            if (file_exists($img['path'])) {
                list($w,$h) = getimagesize($img['path']);
                $ancho_canvas = 194;
                $alto_imagen = min(($h * $ancho_canvas) / $w, 100);

                $pdf->StartTransform();
                $pdf->RoundedRect(8, 8, $ancho_canvas, $alto_imagen, 6, '1111', 'CNZ');
                $pdf->Image($img['path'], 8, 8, $ancho_canvas, $alto_imagen);
                $pdf->StopTransform();

                $y_actual = 8 + $alto_imagen;
            }
        }

        /**
         * 2. CALENDARIO SUPERPUESTO
         */
        $cal_x = 14;
        $cal_y = 14;
        $cal_w = 32;
        $cal_h = 30;

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($cal_x, $cal_y, $cal_w, $cal_h, 3, '1111', 'F');

        $pdf->SetFillColor(30,30,30);
        $pdf->RoundedRect($cal_x, $cal_y, $cal_w, 7, 3, '1100', 'F');

        // Mes
        $pdf->SetTextColor(255,255,255);
        $pdf->SetFont('helvetica','B',10);
        $pdf->SetXY($cal_x, $cal_y + 1);
        $pdf->Cell($cal_w,5,$mes,0,0,'C');

        // 🔥 DÍA MÁS GRANDE (sin cambiar el calendario)
        $pdf->SetTextColor(30,30,30);
        $pdf->SetFont('helvetica','B',32); // antes 26
        $pdf->SetXY($cal_x, $cal_y + 7);
        $pdf->Cell($cal_w,16,$dia,0,0,'C');

        // Año
        $pdf->SetFont('helvetica','',9);
        $pdf->SetXY($cal_x, $cal_y + 23);
        $pdf->Cell($cal_w,5,$ano,0,0,'C');

        /**
         * 3. BADGE CONFIRMACIÓN (tick inline)
         */
        $y_actual += 8;
        $badge_w = 145;
        $badge_x = (210 - $badge_w) / 2;
        $badge_h = 11;

        $pdf->SetFillColor(76,175,80);
        $pdf->RoundedRect($badge_x, $y_actual, $badge_w, $badge_h, 3, '1111', 'F');

        $pdf->SetTextColor(255,255,255);
        $text_y = $y_actual + 2.5;

        // Tick
        $pdf->SetFont('zapfdingbats','',12);
        $pdf->SetXY($badge_x + 12, $text_y);
        $pdf->Cell(6,6,'3',0,0,'L');

        // Texto
        $pdf->SetFont('helvetica','B',12);
        $pdf->SetXY($badge_x + 20, $text_y);
        $pdf->Cell($badge_w - 24,6,'ENTRADA CONFIRMADA',0,0,'L');

        $y_actual += 15;

        /**
         * 4. FECHA Y UBICACIÓN
         */
        $pdf->SetFont('helvetica','',10);
        $pdf->SetTextColor(100,100,100);
        $pdf->SetX(15);
        $pdf->MultiCell(180,5,'FECHA: '.$fecha_formateada.' | LUGAR: '.$ubicacion,0,'C');

        /**
         * 5. ASISTENTE
         */
        $pdf->Ln(6);
        $pdf->SetFont('helvetica','B',24);
        $pdf->SetTextColor(60,60,65);
        $pdf->Cell(0,12,$nombre_completo,0,1,'C');

        $pdf->SetFont('helvetica','B',14);
        $pdf->SetTextColor(100,100,105);
        $pdf->Cell(0,8,mb_strtoupper($nombre_empresa,'UTF-8'),0,1,'C');

        /**
         * 6. QR
         */
        $y_qr = $pdf->GetY() + 10;
        $qr_size = 65;
        $qr_x = (210 - $qr_size) / 2;

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($qr_x - 4, $y_qr, $qr_size + 8, $qr_size + 8, 4, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $y_qr + 4, $qr_size, $qr_size);

        /**
         * GUARDAR
         */
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(remove_accents($nombre_completo)));
        $pdf->Output($upload_dir['basedir'].'/entrada_'.$slug.'_'.time().'.pdf','F');

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log('❌ Error PDF: '.$e->getMessage());
    }
}

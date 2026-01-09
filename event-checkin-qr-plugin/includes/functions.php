<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR para el evento ID 50339 con calendario superpuesto y datos bajo imagen.
 * Version: 3.2.0
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
        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';

        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $ts = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);

        $dia = date('d', $ts);
        $mes_corto = strtoupper(date_i18n('M', $ts));
        $mes_largo = date_i18n('F', $ts);
        $ano = date('Y', $ts);
        $fecha_texto = "$dia de $mes_largo de $ano";

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

        // Fondo de la tarjeta
        $pdf->SetFillColor(245,245,247);
        $pdf->RoundedRect(8,8,194,279,6,'1111','F');

        /**
         * 1. IMAGEN SUPERIOR
         */
        $img_url = get_the_post_thumbnail_url($post_id,'full');
        $alto_imagen = 100; // Valor por defecto

        if ($img_url) {
            $img = optimizar_imagen_para_pdf($img_url, $upload_dir);
            if (file_exists($img['path'])) {
                list($w,$h) = getimagesize($img['path']);
                $ancho_canvas = 194;
                $alto_imagen = ($h * $ancho_canvas) / $w;

                $pdf->StartTransform();
                $pdf->RoundedRect(8, 8, $ancho_canvas, $alto_imagen, 6, '1111', 'CNZ');
                $pdf->Image($img['path'], 8, 8, $ancho_canvas, $alto_imagen);
                $pdf->StopTransform();
            }
        }

        /**
         * 2. CALENDARIO SUPERPUESTO (Arriba Izquierda)
         */
        $cal_x = 14; 
        $cal_y = 14;
        $cal_w = 32;
        $cal_h = 30;

        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($cal_x, $cal_y, $cal_w, $cal_h, 3, '1111', 'F');
        $pdf->SetFillColor(30, 30, 30);
        $pdf->RoundedRect($cal_x, $cal_y, $cal_w, 7, 3, '1100', 'F');

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($cal_x, $cal_y + 1);
        $pdf->Cell($cal_w, 5, $mes_corto, 0, 0, 'C');

        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetXY($cal_x, $cal_y + 9);
        $pdf->Cell($cal_w, 12, $dia, 0, 0, 'C');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY($cal_x, $cal_y + 22);
        $pdf->Cell($cal_w, 5, $ano, 0, 0, 'C');

        /**
         * 3. BLOQUE DE DATOS BAJO IMAGEN (Fecha y Ubicación)
         */
        $y_datos = 8 + $alto_imagen + 8;
        $pdf->SetAbsY($y_datos);
        
        // Etiqueta Entrada Confirmada (Pequeña arriba)
        $pdf->SetFillColor(76, 175, 80);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(8);
        $pdf->Cell(45, 7, ' ✓ ENTRADA CONFIRMADA', 0, 1, 'L', true);

        $pdf->Ln(4);

        // Fecha y Ubicación
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetX(12);
        $pdf->Cell(0, 5, 'FECHA DEL EVENTO:', 0, 1);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->SetX(12);
        $pdf->Cell(0, 6, mb_strtoupper($fecha_texto, 'UTF-8'), 0, 1);

        $pdf->Ln(3);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetX(12);
        $pdf->Cell(0, 5, 'UBICACIÓN:', 0, 1);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->SetX(12);
        $pdf->MultiCell(180, 6, $ubicacion);

        /**
         * 4. ASISTENTE (Central)
         */
        $pdf->SetAbsY($pdf->GetY() + 10);
        $pdf->SetFont('helvetica', 'B', 26);
        $pdf->SetTextColor(40, 40, 45);
        $pdf->Cell(0, 12, $nombre_completo, 0, 1, 'C');

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(100, 100, 105);
        $pdf->Cell(0, 8, mb_strtoupper($nombre_empresa, 'UTF-8'), 0, 1, 'C');

        /**
         * 5. QR (Fondo inferior)
         */
        $qr_size = 60;
        $y_qr = 279 + 8 - $qr_size - 15; // Posicionado respecto al fondo
        $qr_x = (210 - $qr_size) / 2;

        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($qr_x - 4, $y_qr - 4, $qr_size + 8, $qr_size + 8, 4, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $y_qr, $qr_size, $qr_size);

        /**
         * GUARDAR Y LIMPIAR
         */
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(remove_accents($nombre_completo)));
        $pdf_filename = 'entrada_'.$slug.'_'.time().'.pdf';
        
        $pdf->Output($upload_dir['basedir'].'/'.$pdf_filename, 'F');

        @unlink($qr_path);
        if (isset($img['tmp']) && file_exists($img['tmp'])) {
            @unlink($img['tmp']);
        }

    } catch (Exception $e) {
        error_log('❌ Error PDF: '.$e->getMessage());
    }
}
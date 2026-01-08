<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR para el evento ID 50339.
 * Version: 3.0.2
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * ---------------------------
 * Utilidades
 * ---------------------------
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

        // Datos asistente
        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos = sanitize_text_field($request['apellidos'] ?? '');
        $nombre_completo = html_entity_decode(trim("$nombre $apellidos"), ENT_QUOTES, 'UTF-8');

        // Datos evento
        $titulo_evento = get_the_title($post_id);
        $ubicacion = html_entity_decode(
            get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible',
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $ts = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);

        $dia = date('d', $ts);
        $mes = strtoupper(date_i18n('M', $ts));
        $ano = date('Y', $ts);

        $upload_dir = wp_upload_dir();

        /**
         * ---------- QR ----------
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
         * ---------- PDF ----------
         */
        $pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8,8,8);
        $pdf->SetAutoPageBreak(false,0);
        $pdf->AddPage();

        /**
         * ---------- Fondo ----------
         */
        $pdf->SetFillColor(245,245,247);
        $pdf->RoundedRect(8,8,194,279,6,'1111','F');

        /**
         * ---------- Imagen ----------
         */
        $y = 8;
        $img_url = get_the_post_thumbnail_url($post_id,'full');
        if ($img_url) {
            $img = optimizar_imagen_para_pdf($img_url,$upload_dir);
            if (file_exists($img['path'])) {
                list($w,$h) = getimagesize($img['path']);
                $ancho = 194;
                $alto = ($h * $ancho) / $w;

                $pdf->StartTransform();
                $pdf->RoundedRect(8,8,$ancho,$alto,6,'1111','CNZ');
                $pdf->Image($img['path'],8,8,$ancho,$alto);
                $pdf->StopTransform();

                $y = 8 + $alto + 8;
            }
        }

        /**
         * ---------- CALENDARIO (ESTILO REFERENCIA - CARTEL) ----------
         */
        $cal_x = 12;
        $cal_y = 12;
        $cal_w = 60;
        $cal_h = 58;

        // Fondo NEGRO del calendario
        $pdf->SetFillColor(0,0,0);
        $pdf->RoundedRect($cal_x,$cal_y,$cal_w,$cal_h,3,'1111','F');

        // BORDE GRIS CLARO alrededor
        $pdf->SetDrawColor(180,180,180);
        $pdf->SetLineWidth(0.8);
        $pdf->RoundedRect($cal_x,$cal_y,$cal_w,$cal_h,3,'1111');

        // Barra superior MES (fondo gris oscuro)
        $pdf->SetFillColor(50,50,50);
        $pdf->RoundedRect($cal_x,$cal_y,$cal_w,9,3,'1100','F');

        // MES (pequeño, gris claro)
        $pdf->SetTextColor(200,200,200);
        $pdf->SetFont('helvetica','B',8);
        $pdf->SetXY($cal_x,$cal_y+2);
        $pdf->Cell($cal_w,5,$mes,0,0,'C');

        // DÍA - ENORME y BLANCO
        $pdf->SetTextColor(255,255,255);
        $pdf->SetFont('helvetica','B',56);
        $pdf->SetXY($cal_x,$cal_y+14);
        $pdf->Cell($cal_w,26,$dia,0,0,'C');

        // AÑO (gris claro)
        $pdf->SetTextColor(200,200,200);
        $pdf->SetFont('helvetica','B',9);
        $pdf->SetXY($cal_x,$cal_y+42);
        $pdf->Cell($cal_w,8,$ano,0,0,'C');

        /**
         * ---------- Ubicación ----------
         */
        $pdf->SetAbsY($y);

        $icon_x = 20;
        $pdf->SetDrawColor(80,80,80);
        $pdf->SetFillColor(80,80,80);
        $pdf->Circle($icon_x,$y+8,1.5,0,360,'F');
        $pdf->Line($icon_x,$y+9.5,$icon_x,$y+13);

        $pdf->SetFont('helvetica','B',11);
        $pdf->SetTextColor(80,80,80);
        $pdf->SetXY($icon_x+4,$y+5);
        $pdf->Cell(0,5,'UBICACIÓN',0,1);

        $pdf->SetFont('helvetica','',11);
        $pdf->SetX($icon_x+4);
        $pdf->MultiCell(160,5,$ubicacion);

        /**
         * ---------- Asistente ----------
         */
        $pdf->Ln(6);
        $pdf->SetFont('helvetica','B',22);
        $pdf->SetTextColor(60,60,65);
        $pdf->Cell(0,12,$nombre_completo,0,1,'C');

        $pdf->SetFont('helvetica','B',13);
        $pdf->SetTextColor(100,100,105);
        $pdf->Cell(0,8,mb_strtoupper($nombre_empresa,'UTF-8'),0,1,'C');

        /**
         * ---------- QR ----------
         */
        $qr_size = 65;
        $qr_x = (210 - $qr_size) / 2;
        $y_qr = $pdf->GetY() + 4;

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($qr_x-4,$y_qr,$qr_size+8,$qr_size+8,4,'1111','F');
        $pdf->Image($qr_path,$qr_x,$y_qr+4,$qr_size,$qr_size);

        /**
         * ---------- Confirmación ----------
         */
        $pdf->SetAbsY(265);
        $badge_w = 70;
        $badge_x = (210 - $badge_w) / 2;

        $pdf->SetFillColor(76,175,80);
        $pdf->RoundedRect($badge_x,265,$badge_w,9,3,'1111','F');

        $pdf->SetFont('dejavusans','B',10);
        $pdf->SetTextColor(255,255,255);
        $pdf->Cell(0,9,'✓ ENTRADA CONFIRMADA',0,1,'C');

        /**
         * ---------- Guardar ----------
         */
        $slug = preg_replace(
            '/[^a-z0-9]+/',
            '-',
            strtolower(remove_accents($nombre_completo))
        );

        $pdf_filename = 'entrada_'.$slug.'_'.time().'.pdf';
        $pdf->Output($upload_dir['basedir'].'/'.$pdf_filename,'F');

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log('❌ Error PDF: '.$e->getMessage());
    }
}
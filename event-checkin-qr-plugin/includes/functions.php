<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho - Multi-Evento)
 * Description: Genera PDF con QR detectando el ID del evento desde campo oculto o referencia.
 * Version: 3.7.0
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

// --- FUNCIÓN DE IMAGEN ---
function optimizar_imagen_para_pdf($imagen_url, $upload_dir){
    $tmp = null; $imagen_path = '';
    $attachment_id = attachment_url_to_postid($imagen_url);
    if ($attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if ($meta && isset($meta['file'])) $imagen_path = $upload_dir['basedir'].'/'.$meta['file'];
    }
    if (!file_exists($imagen_path) && function_exists('download_url')) {
        $tmp = download_url($imagen_url, 300);
        if (!is_wp_error($tmp)) $imagen_path = $tmp;
    }
    return ['path' => $imagen_path, 'tmp' => $tmp];
}

add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

function generar_qr_pdf_personalizado($request, $action_handler) {

    try {
        $eventos_permitidos = [
            50339, 50342, 50352, 50364, 50355, 50379, 50383, 52217, 51321, 50391,
            50414, 50420, 54395, 50432, 50435, 50438, 50442, 50445, 50451, 50466,
            50469, 51324, 50490, 50520, 50530, 50533, 50605, 51033, 50493, 51448,
            51037, 51237, 51269, 51272, 51282, 54163, 51301, 51285, 51291, 51294,
            51297
        ];

        // LOGICA DE DETECCIÓN MEJORADA
        $post_id = 0;

        // 1. Intentar obtenerlo del campo oculto 'post_id' que configuramos en el formulario
        if (!empty($request['post_id'])) {
            $post_id = intval($request['post_id']);
        } 
        // 2. Si no viene, intentar obtenerlo de la URL de referencia (refer_post_id)
        elseif (!empty($request['refer_post_id'])) {
            $post_id = intval($request['refer_post_id']);
        }

        // Si el ID capturado NO está en la lista o es 0, poner el default
        if ($post_id === 0 || !in_array($post_id, $eventos_permitidos)) {
            // Esto solo pasará si el formulario no está enviando el ID correctamente
            $post_id = 50339; 
        }

        // --- RESTO DEL PROCESO IGUAL ---
        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa');
        $nombre = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos = sanitize_text_field($request['apellidos'] ?? '');
        $nombre_completo = html_entity_decode(trim("$nombre $apellidos"), ENT_QUOTES, 'UTF-8');

        $titulo_evento = get_the_title($post_id);
        $ubicacion_raw = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $ubicacion = html_entity_decode($ubicacion_raw, ENT_QUOTES, 'UTF-8');

        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $ts = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);
        $dia = date('d', $ts);
        $mes = strtoupper(date_i18n('M', $ts));
        $fecha_formateada = date('d/m/Y H:i', $ts);

        $upload_dir = wp_upload_dir();

        $qr_url = home_url('/checkin/?') . http_build_query([
            'empresa' => $nombre_empresa,
            'nombre'  => $nombre_completo,
            'evento'  => $titulo_evento,
            'ev_id'   => $post_id 
        ]);

        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(300)->margin(10)->build();
        $qr_path = $upload_dir['basedir'].'/qr_'.uniqid().'.png';
        $qr->saveToFile($qr_path);

        $pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
        $pdf->SetMargins(8,8,8); $pdf->SetAutoPageBreak(false,0);
        $pdf->AddPage();

        // Estética del PDF
        $pdf->SetFillColor(245,245,247);
        $pdf->RoundedRect(8,8,194,279,6,'1111','F');

        $y_actual = 8;
        $img_url = get_the_post_thumbnail_url($post_id, 'full');
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

        // Calendario
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(14, 14, 22, 22, 2.2, '1111', 'F');
        $pdf->SetTextColor(35, 35, 35);
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->SetXY(14, 16); $pdf->Cell(22, 11, $dia, 0, 0, 'C');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetXY(14, 27); $pdf->Cell(22, 7, ucfirst(strtolower($mes)), 0, 0, 'C');

        // Badge
        $y_actual += 8;
        $pdf->SetFillColor(76, 175, 80);
        $pdf->RoundedRect(32.5, $y_actual, 145, 11, 3, '1111', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY(32.5, $y_actual + 2.5);
        $pdf->Cell(145, 6, 'ENTRADA CONFIRMADA', 0, 0, 'C');

        // Texto Info
        $y_actual += 16;
        $pdf->SetFont('helvetica','',12); $pdf->SetTextColor(50,50,50);    
        $pdf->SetXY(15, $y_actual);
        $pdf->MultiCell(180,6,'FECHA: '.$fecha_formateada.' | LUGAR: '.$ubicacion,0,'C'); 

        $pdf->Ln(6);
        $pdf->SetFont('helvetica','B',22); $pdf->SetTextColor(60,60,65);
        $pdf->Cell(0,12,$nombre_completo,0,1,'C');
        $pdf->SetFont('helvetica','B',14); $pdf->SetTextColor(100,100,105);
        $pdf->Cell(0,8,mb_strtoupper($nombre_empresa,'UTF-8'),0,1,'C');

        // QR
        $y_qr = $pdf->GetY() + 10;
        $pdf->Image($qr_path, 72.5, $y_qr + 4, 65, 65);

        $slug = preg_replace('/[^a-z0-9]+/','-',strtolower(remove_accents($nombre_completo)));
        $nombre_archivo = 'entrada_'.$post_id.'_'.$slug.'_'.time().'.pdf';
        $pdf->Output($upload_dir['basedir'].'/'.$nombre_archivo, 'F');

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log('❌ Error en Plugin QR: '.$e->getMessage());
    }
}
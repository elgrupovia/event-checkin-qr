<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho - Multi-Evento)
 * Description: Genera PDF con QR.
 * Version: 3.5.5
 */

if (!defined('ABSPATH')) exit;

// Carga de dependencias de Composer
require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Función auxiliar para limpiar nombres en el archivo
 */
function limpiar_nombre_archivo_qr($string) {
    $string = str_replace(' ', '-', $string);
    return preg_replace('/[^A-Za-z0-9\-]/', '', $string);
}

/**
 * Función para manejar imágenes locales
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

add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

function generar_qr_pdf_personalizado($request, $action_handler) {

    try {
        // --- CONFIGURACIÓN DE EVENTOS ---
        $eventos_permitidos = [50339, 50342, 50352, 50364, 50355, 50379, 50383, 52217, 51321, 50391, 50414, 50420, 54395, 50432, 50435, 50438, 50442, 50445, 50451, 50466, 50469, 51324, 50490, 50520, 50530, 50533, 50605, 51033, 50493, 51448, 51037, 51237, 51269, 51272, 51282, 54163, 51301, 51285, 51291, 51294, 51297];
        $post_id = isset($request['refer_post_id']) ? intval($request['refer_post_id']) : get_the_ID();
        if (!in_array($post_id, $eventos_permitidos)) { $post_id = 50339; }

        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos = sanitize_text_field($request['apellidos'] ?? '');
        $nombre_completo = html_entity_decode(trim("$nombre $apellidos"), ENT_QUOTES, 'UTF-8');

        $titulo_evento = get_the_title($post_id);
        $ubicacion = html_entity_decode(get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible', ENT_QUOTES, 'UTF-8');
        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $ts = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);
        $dia = date('d', $ts);
        $mes = strtoupper(date_i18n('M', $ts));
        $fecha_formateada = date('d/m/Y H:i', $ts);

        $upload_dir = wp_upload_dir();

        // --- QR GENERACIÓN ---
        $qr_url = home_url('/checkin/?') . http_build_query(['empresa' => $nombre_empresa, 'nombre' => $nombre_completo, 'evento' => $titulo_evento, 'ev_id' => $post_id]);
        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(500)->margin(0)->build();
        $qr_path = $upload_dir['basedir'].'/qr_'.uniqid().'.png';
        $qr->saveToFile($qr_path);

        // --- INICIO TCPDF ---
        $pdf = new TCPDF('P','mm','A4',true,'UTF-8',false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(8,8,8);
        $pdf->SetAutoPageBreak(false,0);
        $pdf->AddPage();

        // --- RUTA CORREGIDA PARA PRODUCCIÓN ---
        // Usamos la ruta que me has indicado donde están los archivos
        $f_path = ABSPATH . 'wp-content/plugins/event-checkin-qr-plugin/vendor/tecnickcom/tcpdf/fonts/';
        $f_bold_file = $f_path . 'gotham-Bold.ttf';
        $f_reg_file  = $f_path . 'gotham-Book.ttf';

        error_log("---------- QR PLUGIN DEBUG ----------");
        error_log("Buscando fuentes en: " . $f_path);

        if (file_exists($f_bold_file) && file_exists($f_reg_file)) {
            $gotham_b = TCPDF_FONTS::addTTFfont($f_bold_file, 'TrueTypeUnicode', '', 96);
            $gotham_r = TCPDF_FONTS::addTTFfont($f_reg_file, 'TrueTypeUnicode', '', 96);
            error_log("STATUS: Fuentes Gotham encontradas en vendor.");
        } else {
            $gotham_b = 'helveticaB';
            $gotham_r = 'helvetica';
            error_log("STATUS: ERROR - No están en vendor. Revisa mayúsculas/minúsculas.");
        }

        // Fondo
        $pdf->SetFillColor(245,245,247);
        $pdf->RoundedRect(8,8,194,279,6,'1111','F');

        // Imagen Cabecera
        $y_actual = 8;
        $img_url = get_the_post_thumbnail_url($post_id, 'full');
        if ($img_url) {
            $img_res = optimizar_imagen_para_pdf($img_url, $upload_dir);
            if (file_exists($img_res['path'])) {
                list($w,$h) = getimagesize($img_res['path']);
                $alto = min(($h * 194) / $w, 100);
                $pdf->Image($img_res['path'],8,8,194,$alto,'','','',false,300,'',false,false,0,'TL',false,false);
                $y_actual = 8 + $alto;
            }
        }

        // --- CALENDARIO (GOTHAM) ---
        $cal_x = 14; $cal_y = 14; $cal_w = 22; $cal_h = 22;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($cal_x, $cal_y, $cal_w, $cal_h, 2.2, '1111', 'F');

        $pdf->SetTextColor(35, 35, 35);
        $pdf->SetFont($gotham_b, 'B', 24); // Numero
        $pdf->SetXY($cal_x, $cal_y + 2);
        $pdf->Cell($cal_w, 11, $dia, 0, 0, 'C');

        $pdf->SetFont($gotham_r, '', 11); // Mes
        $pdf->SetTextColor(110, 110, 110);
        $pdf->SetXY($cal_x, $cal_y + 13);
        $pdf->Cell($cal_w, 7, ucfirst(strtolower($mes)), 0, 0, 'C');

        // --- BADGE CONFIRMACIÓN ---
        $y_actual += 8;
        $badge_w = 80; $badge_x = (210 - $badge_w) / 2;
        $pdf->SetFillColor(76, 175, 80);
        $pdf->RoundedRect($badge_x, $y_actual, $badge_w, 11, 3, '1111', 'F');
        $pdf->SetTextColor(255, 255, 255);
        
        $pdf->SetFont('zapfdingbats', '', 11); 
        $pdf->SetXY($badge_x + 5, $y_actual + 2.5);
        $pdf->Cell(6, 6, '3', 0, 0, 'C');
        
        $pdf->SetFont($gotham_b, 'B', 11);
        $pdf->SetXY($badge_x + 11, $y_actual + 2.5);
        $pdf->Cell($badge_w - 15, 6, 'ENTRADA CONFIRMADA', 0, 0, 'C');

        // --- DATOS DEL EVENTO ---
        $y_actual += 16;
        $pdf->SetFont($gotham_r, '', 13);
        $pdf->SetTextColor(50,50,50);
        $pdf->SetXY(15, $y_actual);
        $pdf->MultiCell(180,6,'FECHA: '.$fecha_formateada.' | LUGAR: '.$ubicacion,0,'C');

        // --- NOMBRE ASISTENTE ---
        $pdf->Ln(6);
        $pdf->SetFont($gotham_b, 'B', 26);
        $pdf->SetTextColor(60,60,65);
        $pdf->Cell(0,12, mb_strtoupper($nombre_completo, 'UTF-8'),0,1,'C');
        
        $pdf->SetFont($gotham_b, 'B', 14);
        $pdf->SetTextColor(100,100,105);
        $pdf->Cell(0,8, mb_strtoupper($nombre_empresa,'UTF-8'),0,1,'C');

        // --- QR MAXIMIZADO ---
        $pdf->Ln(2);
        $y_qr = $pdf->GetY();
        $qr_display_size = 120;
        $qr_x = (210 - $qr_display_size) / 2;
        if (($y_qr + $qr_display_size) > 282) { $y_qr = 282 - $qr_display_size; }

        $pdf->SetFillColor(255,255,255);
        $pdf->RoundedRect($qr_x - 1, $y_qr, $qr_display_size + 2, $qr_display_size + 2, 2, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $y_qr + 1, $qr_display_size, $qr_display_size);

        // --- SALIDA ---
        $safe_name = limpiar_nombre_archivo_qr($nombre_completo);
        $filename = 'entrada_'.$post_id.'_'.$safe_name.'_'.time().'.pdf';
        $full_path = $upload_dir['basedir'].'/'.$filename;
        
        $pdf->Output($full_path, 'F');
        error_log("SUCCESS: PDF Generado en $full_path");

        if (file_exists($qr_path)) { @unlink($qr_path); }

    } catch (Exception $e) {
        error_log('❌ FATAL ERROR QR PLUGIN: '.$e->getMessage());
    }
}
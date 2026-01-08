<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR para el evento ID 50339. Diseño compacto: Nombre y QR subidos cerca de la ubicación.
 * Version: 2.7.0
 * */

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
    $tmp = null; $imagen_path = '';
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
 * Generar PDF con QR (ID Evento: 50339)
 * ---------------------------
 */
add_action('jet-form-builder/custom-action/inscripciones_qr','generar_qr_pdf_personalizado',10,3);

function generar_qr_pdf_personalizado($request, $action_handler) {
    try {
        $post_id = 50339;

        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre_persona = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos_persona = sanitize_text_field($request['apellidos'] ?? $request['last_name'] ?? '');
        $nombre_completo = html_entity_decode(trim($nombre_persona . ' ' . $apellidos_persona), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $titulo_evento = get_the_title($post_id);
        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        
        $fecha_timestamp = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);
        $dia = date('d', $fecha_timestamp);
        $mes_nombre = strtoupper(date_i18n('M', $fecha_timestamp));
        $ano = date('Y', $fecha_timestamp);

        $upload_dir = wp_upload_dir();
        
        // Generar QR
        $params = [
            'empresa' => rawurlencode($nombre_empresa),
            'nombre'  => rawurlencode($nombre_completo),
            'evento'  => rawurlencode($titulo_evento),
        ];
        $qr_url = home_url('/checkin/') . '?' . http_build_query($params);
        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(300)->margin(10)->build();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0); 
        $pdf->SetMargins(8, 8, 8); 
        $pdf->AddPage();
        
        $pdf->SetFillColor(245, 245, 247);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', 'F');

        $y_cursor = 8;

        // 1. FOTO DESTACADA
        $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
        if ($imagen_url) {
            $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
            if (file_exists($imagen_info['path'])) {
                list($ancho_orig, $alto_orig) = getimagesize($imagen_info['path']);
                $ancho_pdf = 194; 
                $alto_pdf = ($alto_orig * $ancho_pdf) / $ancho_orig;
                $pdf->StartTransform();
                $pdf->RoundedRect(8, 8, $ancho_pdf, $alto_pdf, 6, '1111', 'CNZ');
                $pdf->Image($imagen_info['path'], 8, 8, $ancho_pdf, $alto_pdf, '', '', 'T', false, 300);
                $pdf->StopTransform();
                $y_cursor = 8 + $alto_pdf + 8;
            }
        }

        // 2. BLOQUE CALENDARIO Y DIRECCIÓN
        $pdf->SetAbsY($y_cursor);
        $cal_x = 20; $cal_w = 38; $cal_h = 35;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($cal_x, $y_cursor, $cal_w, $cal_h, 3, '1111', 'F');
        $pdf->SetFillColor(30, 30, 30); 
        $pdf->RoundedRect($cal_x, $y_cursor, $cal_w, 8, 3, '1100', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($cal_x, $y_cursor + 1.5);
        $pdf->Cell($cal_w, 5, $mes_nombre, 0, 0, 'C');
        $pdf->SetTextColor(30, 30, 30); 
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetXY($cal_x, $y_cursor + 10);
        $pdf->Cell($cal_w, 15, $dia, 0, 0, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY($cal_x, $y_cursor + 26);
        $pdf->Cell($cal_w, 5, $ano, 0, 0, 'C');

        $pdf->SetXY($cal_x + $cal_w + 10, $y_cursor + 5);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 5, '📍 UBICACIÓN', 0, 1, 'L');
        $pdf->SetXY($cal_x + $cal_w + 10, $pdf->GetY() + 1);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(100, 5, $ubicacion, 0, 'L');

        // --- SUBIDA DE ELEMENTOS ---
        // Reducimos el espacio después de la ubicación (antes era +45, ahora +38)
        $y_cursor += 38; 

        // 3. DATOS ASISTENTE (Pegados a la ubicación)
        $pdf->SetAbsY($y_cursor);
        $pdf->SetTextColor(60, 60, 65); 
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->Cell(0, 10, $nombre_completo, 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(100, 100, 105);
        $pdf->Cell(0, 6, mb_strtoupper($nombre_empresa, 'UTF-8'), 0, 1, 'C');

        // Espacio entre nombre y QR reducido (antes +22, ahora +10)
        $y_cursor += 16;

        // 4. QR CENTRADO (Más arriba)
        $pdf->SetAbsY($y_cursor);
        $qr_size = 65; 
        $qr_x = (210 - $qr_size) / 2;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($qr_x - 4, $y_cursor, $qr_size + 8, $qr_size + 8, 4, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $y_cursor + 4, $qr_size, $qr_size, 'PNG', '', '', true, 300);

        // Bajamos el cursor para que el mensaje de confirmación se quede donde estaba antes
        $y_cursor = 245; 

        // 5. ENTRADA CONFIRMADA (Posición original)
        $pdf->SetAbsY($y_cursor);
        $badge_w = 70;
        $badge_x = (210 - $badge_w) / 2;
        $pdf->SetFillColor(76, 175, 80);
        $pdf->RoundedRect($badge_x, $y_cursor, $badge_w, 9, 3, '1111', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 9, '✓ ENTRADA CONFIRMADA', 0, 1, 'C');

        // Finalizar
        $pdf_filename = 'entrada_' . preg_replace('/[^a-z0-9]+/', '-', strtolower($nombre_completo)) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        @unlink($qr_path);

        $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
        $asistentes[] = ['nombre' => $nombre_completo, 'empresa' => $nombre_empresa, 'fecha_hora' => current_time('mysql')];
        update_post_meta($post_id, '_asistentes', $asistentes);

    } catch (Exception $e) {
        error_log("❌ Error PDF: " . $e->getMessage());
    }
}

/**
 * ---------------------------
 * Manejador de Check-in (ID Fijo: 50339)
 * ---------------------------
 */
add_action('template_redirect', function(){
    if(strpos($_SERVER['REQUEST_URI'],'/checkin/')!==false){
        $post_id = 50339;
        $nombre = sanitize_text_field($_GET['nombre'] ?? 'Invitado');
        $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
        $asistentes[] = [
            'nombre' => $nombre,
            'empresa' => sanitize_text_field($_GET['empresa'] ?? ''),
            'fecha_hora' => current_time('mysql'),
            'tipo' => 'escaneo_qr'
        ];
        update_post_meta($post_id, '_asistentes', $asistentes);
        echo "<div style='text-align:center;font-family:sans-serif;margin-top:100px;'>";
        echo "<div style='font-size:80px;color:#4CAF50;'>✅</div>";
        echo "<h1 style='color:#333;'>Check-in confirmado</h1>";
        echo "<p style='font-size:20px;'>Bienvenido/a: <br><strong style='font-size:30px;'>" . esc_html($nombre) . "</strong></p>";
        echo "</div>";
        exit;
    }
});

/**
 * ---------------------------
 * Administración de Asistentes
 * ---------------------------
 */
add_action('admin_menu', function() {
    add_submenu_page('edit.php?post_type=eventos', 'Asistentes', 'Asistentes', 'manage_options', 'eventos-asistentes', function() {
        echo '<div class="wrap"><h1>🧾 Asistentes Registrados</h1>';
        $post_id = 50339;
        $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
        echo "<h2>" . esc_html(get_the_title($post_id)) . " (ID: 50339)</h2>";
        if (!empty($asistentes)) {
            echo '<table class="widefat"><thead><tr><th>Nombre</th><th>Empresa</th><th>Fecha/Hora</th></tr></thead><tbody>';
            foreach ($asistentes as $a) {
                echo "<tr><td>".esc_html($a['nombre'])."</td><td>".esc_html($a['empresa'])."</td><td>".esc_html($a['fecha_hora'] ?? '-')."</td></tr>";
            }
            echo '</tbody></table>';
        } else { echo "<p>No hay asistentes registrados para este evento.</p>"; }
        echo '</div>';
    });
});
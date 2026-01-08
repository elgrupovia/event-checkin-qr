<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR para el evento ID 50339. Nombre del asistente y confirmación en la parte superior.
 * Version: 2.6.0
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
 * Generar PDF con QR (ID Evento Forzado: 50339)
 * ---------------------------
 */
add_action('jet-form-builder/custom-action/inscripciones_qr','generar_qr_pdf_personalizado',10,3);
function generar_qr_pdf_personalizado($request, $action_handler) {
    try {
        // ID DEL EVENTO FIJO
        $post_id = 50339;

        // Datos del Asistente
        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre_persona = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos_persona = sanitize_text_field($request['apellidos'] ?? $request['last_name'] ?? '');
        $nombre_completo = html_entity_decode(trim($nombre_persona . ' ' . $apellidos_persona), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Datos del Evento desde el ID 50339
        $titulo_evento = get_the_title($post_id);
        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Dirección no disponible';
        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        
        $fecha_timestamp = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);
        $dia = date('d', $fecha_timestamp);
        $mes_nombre = strtoupper(date_i18n('M', $fecha_timestamp));
        $ano = date('Y', $fecha_timestamp);

        $upload_dir = wp_upload_dir();

        // Generar QR
        $base_url = home_url('/checkin/');
        $params = [
            'empresa' => rawurlencode($nombre_empresa),
            'nombre'  => rawurlencode($nombre_completo),
            'evento'  => rawurlencode($titulo_evento),
        ];
        $qr_url = $base_url . '?' . http_build_query($params);
        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(300)->margin(10)->build();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        // CONFIGURACIÓN PDF
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0); 
        $pdf->SetMargins(10, 10, 10); 
        $pdf->AddPage();
        
        // Fondo General
        $pdf->SetFillColor(245, 245, 247);
        $pdf->RoundedRect(10, 10, 190, 277, 6, '1111', 'F');

        $y_cursor = 10;

        // 1. IMAGEN CABECERA (del evento 50339)
        $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
        if ($imagen_url) {
            $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
            if (file_exists($imagen_info['path'])) {
                list($ancho_orig, $alto_orig) = getimagesize($imagen_info['path']);
                $ancho_pdf = 190; 
                $alto_pdf = ($alto_orig * $ancho_pdf) / $ancho_orig;
                $pdf->Image($imagen_info['path'], 10, 10, $ancho_pdf, $alto_pdf, '', '', 'T', false, 300);
                $y_cursor = 10 + $alto_pdf + 8;
            }
        }

        // 2. BARRA DE CONFIRMACIÓN
        $pdf->SetFillColor(76, 175, 80);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY(10, $y_cursor);
        $pdf->Cell(190, 10, '✓ ENTRADA CONFIRMADA - ACCESO VÁLIDO', 0, 1, 'C', true);
        
        $y_cursor += 15;

        // 3. DATOS DEL ASISTENTE
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->SetXY(10, $y_cursor);
        $pdf->Cell(190, 12, mb_strtoupper($nombre_completo, 'UTF-8'), 0, 1, 'C');
        
        $y_cursor += 12;
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(190, 8, $nombre_empresa, 0, 1, 'C');

        $y_cursor += 12;

        // 4. QR CENTRADO
        $qr_size = 70;
        $qr_x = (210 - $qr_size) / 2;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($qr_x - 4, $y_cursor, $qr_size + 8, $qr_size + 8, 4, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $y_cursor + 4, $qr_size, $qr_size, 'PNG');

        $y_cursor += $qr_size + 20;

        // 5. BLOQUE CALENDARIO Y DIRECCIÓN
        $cal_x = 25; $cal_w = 35; $cal_h = 35;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($cal_x, $y_cursor, $cal_w, $cal_h, 3, '1111', 'F');
        
        $pdf->SetFillColor(30, 30, 30); 
        $pdf->RoundedRect($cal_x, $y_cursor, $cal_w, 8, 3, '1100', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($cal_x, $y_cursor + 1.5);
        $pdf->Cell($cal_w, 5, $mes_nombre, 0, 0, 'C');
        
        $pdf->SetTextColor(30, 30, 30); 
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetXY($cal_x, $y_cursor + 10);
        $pdf->Cell($cal_w, 15, $dia, 0, 0, 'C');

        $pdf->SetXY($cal_x + $cal_w + 10, $y_cursor + 5);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(100, 6, "UBICACIÓN:\n" . $ubicacion, 0, 'L');

        // Guardar PDF
        $pdf_filename = 'entrada_' . preg_replace('/[^a-z0-9]+/', '-', strtolower($nombre_completo)) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        
        @unlink($qr_path);
        
        // Registro de Asistente en WP (Meta del post 50339)
        $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
        $asistentes[] = [
            'nombre' => $nombre_completo, 
            'empresa' => $nombre_empresa, 
            'fecha_hora' => current_time('mysql')
        ];
        update_post_meta($post_id, '_asistentes', $asistentes);

    } catch (Exception $e) {
        error_log("❌ Error PDF/Registro: " . $e->getMessage());
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
        
        $asistentes = get_post_meta($post_id,'_asistentes',true) ?: [];
        $asistentes[] = [
            'nombre' => $nombre,
            'empresa' => sanitize_text_field($_GET['empresa'] ?? ''),
            'fecha_hora' => current_time('mysql'),
            'tipo' => 'escaneo_qr'
        ];
        update_post_meta($post_id,'_asistentes',$asistentes);
        
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
                echo "<tr>
                        <td>".esc_html($a['nombre'])."</td>
                        <td>".esc_html($a['empresa'])."</td>
                        <td>".esc_html($a['fecha_hora'] ?? '-')."</td>
                      </tr>";
            }
            echo '</tbody></table>';
        } else {
            echo "<p>No hay asistentes registrados para este evento.</p>";
        }
        echo '</div>';
    });
});
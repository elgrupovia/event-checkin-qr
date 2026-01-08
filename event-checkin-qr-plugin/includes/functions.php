<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, calendario tipo tarjeta y dirección. Diseño compacto con asistente y QR elevados.
 * Version: 2.5.0
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
function normalizar_texto($texto) {
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
    $texto = preg_replace('/\s+/', ' ', trim($texto));
    $texto = preg_replace('/[^a-z0-9\s\-]/', '', $texto);
    return $texto;
}

function primeras_palabras($texto, $limite = 3) {
    $texto = trim(preg_replace('/\s+/', ' ', $texto));
    $palabras = explode(' ', $texto);
    return implode(' ', array_slice($palabras, 0, $limite));
}

function buscar_evento_robusto($titulo_buscado) {
    $primeras = primeras_palabras($titulo_buscado, 3);
    $ciudades = ['barcelona','valencia','madrid','bilbao'];
    $ciudad_form = null;
    $normForm = normalizar_texto($titulo_buscado);

    foreach($ciudades as $c){
        if(stripos($normForm, normalizar_texto($c)) !== false){
            $ciudad_form = $c; break;
        }
    }

    $args = [
        'post_type' => 'eventos',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'tax_query' => [
            'relation' => 'AND',
            ['taxonomy' => 'ano', 'field' => 'slug', 'terms' => ['2025']],
            ['taxonomy' => 'ciudades', 'field' => 'slug', 'terms' => $ciudades],
        ],
    ];

    $eventos = get_posts($args);
    $event_id = 0;

    if(!empty($eventos) && $ciudad_form){
        $ciudad_buscar = normalizar_texto($ciudad_form);
        foreach($eventos as $evento){
            $titulo_evento_norm = normalizar_texto(get_the_title($evento->ID));
            if(stripos($titulo_evento_norm,$ciudad_buscar)!==false){
                $event_id = $evento->ID; break;
            }
        }
    }

    if($event_id===0){
        $eventos_all = get_posts(['post_type'=>'eventos','post_status'=>'publish','posts_per_page'=>-1]);
        foreach($eventos_all as $evento){
            if(stripos(normalizar_texto(get_the_title($evento->ID)), normalizar_texto($titulo_buscado))!==false){
                $event_id = $evento->ID; break;
            }
        }
    }
    return $event_id;
}

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
 * Generar PDF con QR
 * ---------------------------
 */
add_action('jet-form-builder/custom-action/inscripciones_qr','generar_qr_pdf_personalizado',10,3);
function generar_qr_pdf_personalizado($request, $action_handler) {
    try {
        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre_persona = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos_persona = sanitize_text_field($request['apellidos'] ?? $request['last_name'] ?? '');
        $nombre_completo = html_entity_decode(trim($nombre_persona . ' ' . $apellidos_persona), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $titulo_evento_formulario = sanitize_text_field($request['eventos_2025'][0] ?? '');
        $post_id = $titulo_evento_formulario ? buscar_evento_robusto($titulo_evento_formulario) : null;
        
        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Dirección no disponible';
        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        
        $fecha_timestamp = is_numeric($fecha_raw) ? $fecha_raw : strtotime($fecha_raw);
        $dia = date('d', $fecha_timestamp);
        $mes_nombre = strtoupper(date_i18n('M', $fecha_timestamp));
        $ano = date('Y', $fecha_timestamp);

        $upload_dir = wp_upload_dir();

        // QR
        $params = ['empresa' => rawurlencode($nombre_empresa), 'nombre'  => rawurlencode($nombre_completo), 'evento'  => rawurlencode($post_id ? get_the_title($post_id) : $titulo_evento_formulario)];
        $qr_url = home_url('/checkin/') . '?' . http_build_query($params);
        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(300)->margin(10)->build();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0); 
        $pdf->SetMargins(8, 8, 8); 
        $pdf->AddPage();
        
        // FONDO GRIS CLARO
        $pdf->SetFillColor(245, 245, 247);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', 'F');

        $y_dinamica = 8;

        // 1. FOTO CABECERA
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                if (file_exists($imagen_info['path'])) {
                    list($ancho_orig, $alto_orig) = getimagesize($imagen_info['path']);
                    $ancho_pdf = 194; $alto_pdf = ($alto_orig * $ancho_pdf) / $ancho_orig;
                    $pdf->StartTransform();
                    $pdf->RoundedRect(8, 8, $ancho_pdf, $alto_pdf, 6, '1111', 'CNZ');
                    $pdf->Image($imagen_info['path'], 8, 8, $ancho_pdf, $alto_pdf, '', '', 'T', false, 300);
                    $pdf->StopTransform();
                    $y_dinamica = 8 + $alto_pdf + 8;
                }
            }
        }

        // Borde exterior
        $pdf->SetDrawColor(200, 200, 205); $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', 'D');

        // 2. CALENDARIO Y DIRECCIÓN
        $pdf->SetAbsY($y_dinamica);
        $cal_x = 20; $cal_w = 36; $cal_h = 32;
        $pdf->SetFillColor(255, 255, 255); $pdf->RoundedRect($cal_x, $y_dinamica, $cal_w, $cal_h, 3, '1111', 'F');
        $pdf->SetFillColor(30, 30, 30); $pdf->RoundedRect($cal_x, $y_dinamica, $cal_w, 7, 3, '1100', 'F');
        $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($cal_x, $y_dinamica + 1); $pdf->Cell($cal_w, 5, $mes_nombre, 0, 0, 'C');
        $pdf->SetTextColor(30, 30, 30); $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetXY($cal_x, $y_dinamica + 9); $pdf->Cell($cal_w, 12, $dia, 0, 0, 'C');
        $pdf->SetFont('helvetica', '', 8); $pdf->SetXY($cal_x, $y_dinamica + 23); $pdf->Cell($cal_w, 5, $ano, 0, 0, 'C');

        // Ubicación
        $pdf->SetXY($cal_x + $cal_w + 8, $y_dinamica + 8);
        $pdf->SetTextColor(60, 60, 60); $pdf->SetFont('helvetica', 'B', 12);
        $pdf->MultiCell(100, 6, "• " . $ubicacion, 0, 'L');

        $y_dinamica += 38; // Espacio reducido para subir el nombre

        // 3. DATOS ASISTENTE (SUBIDO)
        $pdf->SetAbsY($y_dinamica);
        $pdf->SetTextColor(40, 40, 40); $pdf->SetFont('helvetica', 'B', 22);
        $pdf->Cell(0, 10, $nombre_completo, 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 13); $pdf->SetTextColor(100, 100, 105);
        $pdf->Cell(0, 7, mb_strtoupper($nombre_empresa, 'UTF-8'), 0, 1, 'C');

        $y_dinamica += 18; // Espacio reducido para subir el QR

        // 4. QR (SUBIDO)
        $pdf->SetAbsY($y_dinamica);
        $qr_size = 65; $qr_x = (210 - $qr_size) / 2;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($qr_x - 4, $y_dinamica, $qr_size + 8, $qr_size + 8, 4, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $y_dinamica + 4, $qr_size, $qr_size, 'PNG', '', '', true, 300);

        $y_dinamica += $qr_size + 15;

        // 5. ENTRADA CONFIRMADA (ESTILO ORIGINAL: Cuadro redondeado centrado)
        $pdf->SetAbsY($y_dinamica);
        $badge_w = 80; $badge_x = (210 - $badge_w) / 2;
        $pdf->SetFillColor(76, 175, 80);
        $pdf->RoundedRect($badge_x, $y_dinamica, $badge_w, 10, 3, '1111', 'F');
        $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($badge_x, $y_dinamica + 2.5);
        $pdf->Cell($badge_w, 5, '✓ ENTRADA CONFIRMADA', 0, 1, 'C');

        // Finalizar
        $pdf_filename = 'entrada_' . preg_replace('/[^a-z0-9]+/', '-', strtolower($nombre_completo)) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        @unlink($qr_path);

        if ($post_id) {
            $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
            $asistentes[] = ['nombre' => $nombre_completo, 'empresa' => $nombre_empresa, 'fecha_hora' => current_time('mysql')];
            update_post_meta($post_id, '_asistentes', $asistentes);
        }
    } catch (Exception $e) {
        error_log("❌ Error PDF: " . $e->getMessage());
    }
}

/**
 * ---------------------------
 * Manejador de Check-in
 * ---------------------------
 */
add_action('template_redirect', function(){
    if(strpos($_SERVER['REQUEST_URI'],'/checkin/')!==false){
        $nombre = sanitize_text_field($_GET['nombre'] ?? 'Invitado');
        $evento = sanitize_text_field($_GET['evento'] ?? '');
        $post_id = buscar_evento_robusto($evento);
        if($post_id){
            $asistentes = get_post_meta($post_id,'_asistentes',true) ?: [];
            $asistentes[] = ['nombre' => $nombre, 'empresa' => sanitize_text_field($_GET['empresa'] ?? ''), 'fecha_hora' => current_time('mysql'), 'tipo' => 'escaneo_qr'];
            update_post_meta($post_id,'_asistentes',$asistentes);
        }
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
        $eventos = get_posts(['post_type' => 'eventos', 'post_status' => 'publish', 'posts_per_page' => -1]);
        foreach ($eventos as $e) {
            $asistentes = get_post_meta($e->ID, '_asistentes', true) ?: [];
            echo "<h2>" . esc_html($e->post_title) . "</h2>";
            if (!empty($asistentes)) {
                echo '<table class="widefat"><thead><tr><th>Nombre</th><th>Empresa</th><th>Fecha/Hora</th></tr></thead><tbody>';
                foreach ($asistentes as $a) {
                    echo "<tr><td>".esc_html($a['nombre'])."</td><td>".esc_html($a['empresa'])."</td><td>".esc_html($a['fecha_hora'] ?? '-')."</td></tr>";
                }
                echo '</tbody></table>';
            } else { echo "<p>No hay asistentes registrados.</p>"; }
        }
        echo '</div>';
    });
});
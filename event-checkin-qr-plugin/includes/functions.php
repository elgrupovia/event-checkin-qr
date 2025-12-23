<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, registra asistentes y sincroniza con Zoho CRM (módulo "Eventos").
 * Version: 1.6
 * */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * ---------------------------
 * Helper Functions
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
        'orderby' => 'date',
        'order' => 'DESC',
        's' => $primeras,
        'tax_query' => [
            'relation' => 'AND',
            [
                'taxonomy' => 'ano',
                'field' => 'slug',
                'terms' => ['2025'],
            ],
            [
                'taxonomy' => 'ciudades',
                'field' => 'slug',
                'terms' => $ciudades,
            ],
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
        if($imagen_meta){
            $imagen_path = $upload_dir['basedir'].'/'.$imagen_meta['file'];
        }
    }
    if(!file_exists($imagen_path) && function_exists('download_url')){
        $tmp = download_url($imagen_url,300);
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
        $cargo_persona = sanitize_text_field($request['cargo'] ?? 'Cargo no especificado');
        $nombre_completo = html_entity_decode(trim($nombre_persona . ' ' . $apellidos_persona), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $titulo_evento_formulario = sanitize_text_field($request['eventos_2025'][0] ?? '');
        $post_id = $titulo_evento_formulario ? buscar_evento_robusto($titulo_evento_formulario) : null;

        $titulo_a_mostrar = $post_id ? get_the_title($post_id) : ($titulo_evento_formulario ?: 'Evento no identificado');
        $titulo_a_mostrar = html_entity_decode($titulo_a_mostrar, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $fecha_evento = get_post_meta($post_id, 'fecha', true);
        if (is_numeric($fecha_evento)) $fecha_evento = date('d/m/Y H:i', $fecha_evento);

        // QR URL
        $base_url = home_url('/checkin/');
        $params = [
            'empresa' => rawurlencode($nombre_empresa),
            'nombre' => rawurlencode($nombre_completo),
            'cargo' => rawurlencode($cargo_persona),
            'email' => rawurlencode($request['email'] ?? ''), 
            'evento' => rawurlencode($titulo_a_mostrar),
        ];
        $qr_url = $base_url . '?' . http_build_query($params);

        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(300)->margin(10)->build();
        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        // === DISEÑO DEL PDF ===
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0); 
        $pdf->AddPage();

        $y_dinamica = 20;

        // IMAGEN DE CABECERA (si existe)
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                if (file_exists($imagen_info['path'])) {
                    list($ancho_orig, $alto_orig) = getimagesize($imagen_info['path']);
                    $ancho_pdf = 210; 
                    $alto_pdf = ($alto_orig * $ancho_pdf) / $ancho_orig;
                    $pdf->Image($imagen_info['path'], 0, 0, $ancho_pdf, $alto_pdf, '', '', 'T', false, 300);
                    $y_dinamica = $alto_pdf + 15;
                }
            }
        }

        $pdf->SetMargins(30, 0, 30);
        $pdf->SetAbsY($y_dinamica);

        // === INDICADOR "ENTRADA CONFIRMADA" ===
        $badge_w = 150;
        $badge_h = 10;
        $badge_x = (210 - $badge_w) / 2;
        $badge_y = $pdf->GetY();

        // Fondo verde (#00C853 aprox)
        $pdf->SetFillColor(0, 200, 83);
        $pdf->Rect($badge_x, $badge_y, $badge_w, $badge_h, 'F');

        // Círculo blanco con check
        $circle_x = $badge_x + 8;
        $circle_y = $badge_y + ($badge_h / 2);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Circle($circle_x, $circle_y, 3.5, 0, 360, 'F');
        
        // Check verde
        $pdf->SetTextColor(0, 200, 83);
        $pdf->SetFont('zapfdingbats', '', 12);
        $pdf->SetXY($circle_x - 2, $circle_y - 3);
        $pdf->Cell(4, 6, '4', 0, 0, 'C'); // símbolo check

        // Texto "ENTRADA CONFIRMADA"
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($badge_x + 18, $badge_y);
        $pdf->Cell($badge_w - 18, $badge_h, 'ENTRADA CONFIRMADA', 0, 0, 'L');

        $pdf->Ln(13);

        // === TÍTULO DEL EVENTO (discreto) ===
        $pdf->SetTextColor(140, 140, 140);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(0, 6, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(2);

        // === FECHA Y UBICACIÓN ===
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        
        // Icono calendario + fecha
        $pdf->SetFont('zapfdingbats', '', 10);
        $pdf->Write(4, '❑'); // icono calendario
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Write(4, '  ' . $fecha_evento);
        $pdf->Ln(4);

        // Icono ubicación + dirección
        $pdf->SetFont('zapfdingbats', '', 10);
        $pdf->Write(4, '▼'); // icono ubicación
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Write(4, '  ' . $ubicacion);
        $pdf->Ln(6);

        // === SEPARADOR ===
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(30, $pdf->GetY(), 180, $pdf->GetY());
        $pdf->Ln(6);

        // === SECCIÓN "NOMBRE" ===
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 4, 'NOMBRE', 0, 1, 'C');
        $pdf->SetTextColor(210, 180, 60); // Color dorado/mostaza
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->MultiCell(0, 8, $nombre_completo, 0, 'C');
        $pdf->Ln(3);

        // === EMPRESA Y CARGO (sin etiquetas) ===
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 4, $nombre_empresa, 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 4, $cargo_persona, 0, 1, 'C');
        $pdf->Ln(6);

        // === CÓDIGO QR ===
        $qr_size = 60;
        $qr_x = (210 - $qr_size) / 2;
        $pdf->Image($qr_path, $qr_x, $pdf->GetY(), $qr_size, $qr_size, 'PNG', '', '', true, 300);

        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        @unlink($qr_path);

        if ($post_id) {
            $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
            $asistentes[] = ['nombre' => $nombre_completo, 'empresa' => $nombre_empresa, 'cargo' => $cargo_persona, 'fecha_hora' => current_time('mysql')];
            update_post_meta($post_id, '_asistentes', $asistentes);
        }

    } catch (Exception $e) {
        error_log("❌ Error PDF/Registro: " . $e->getMessage());
    }
}

/**
 * ---------------------------
 * Registrar QR leídos (Check-in)
 * ---------------------------
 */
add_action('template_redirect', function(){
    if(strpos($_SERVER['REQUEST_URI'],'/checkin/')!==false){
        $nombre = sanitize_text_field($_GET['nombre'] ?? 'Invitado');
        $evento = sanitize_text_field($_GET['evento'] ?? '');
        $post_id = buscar_evento_robusto($evento);
        if($post_id){
            $asistentes = get_post_meta($post_id,'_asistentes',true) ?: [];
            $asistentes[] = ['nombre'=>$nombre,'empresa'=>$_GET['empresa'],'cargo'=>$_GET['cargo'],'fecha_hora'=>current_time('mysql')];
            update_post_meta($post_id,'_asistentes',$asistentes);
        }
        echo "<h2>Check-in confirmado ✅</h2><p>Bienvenido: " . esc_html($nombre) . "</p>";
        exit;
    }
});

/**
 * ---------------------------
 * Admin: Submenú Asistentes
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
                echo '<table class="widefat"><thead><tr><th>Nombre</th><th>Empresa</th></tr></thead><tbody>';
                foreach ($asistentes as $a) echo "<tr><td>".esc_html($a['nombre'])."</td><td>".esc_html($a['empresa'])."</td></tr>";
                echo '</tbody></table>';
            }
        }
        echo '</div>';
    });
});
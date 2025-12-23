<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, registra asistentes y sincroniza con Zoho CRM (módulo "Eventos").
 * Version: 1.1
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
        error_log("=== [INSCRIPCIÓN INICIADA] ===");

        $nombre_empresa = sanitize_text_field($request['nombre_de_empresa'] ?? 'Empresa Desconocida');
        $nombre_persona = sanitize_text_field($request['nombre'] ?? 'Invitado');
        $apellidos_persona = sanitize_text_field($request['apellidos'] ?? $request['last_name'] ?? '');
        if (!$apellidos_persona && is_user_logged_in()) {
            $user = wp_get_current_user();
            $apellidos_persona = $user->last_name ?? '';
        }
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
            'email' => rawurlencode($request['email']), 
            'evento' => rawurlencode($titulo_a_mostrar),
            'ubicacion' => rawurlencode($ubicacion),
            'fecha' => rawurlencode($fecha_evento),
        ];
        $qr_url = $base_url . '?' . implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));

        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(300)->margin(10)->build();
        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        // === DISEÑO DEL PDF ===
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0); 
        $pdf->SetAutoPageBreak(false); // Desactivamos momentáneamente para controlar el espacio
        $pdf->AddPage();

        $y_pos = 15; // Margen inicial si no hay imagen

        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                if (file_exists($imagen_info['path'])) {
                    list($w_orig, $h_orig) = getimagesize($imagen_info['path']);
                    $h_pdf = ($h_orig * 210) / $w_orig;
                    // Limitamos la altura de la imagen si es demasiado grande para que quepa todo en una página
                    if ($h_pdf > 100) $h_pdf = 100; 
                    
                    $pdf->Image($imagen_info['path'], 0, 0, 210, $h_pdf, '', '', 'T', false, 300);
                    $y_pos = $h_pdf + 8;
                }
            }
        }

        $pdf->SetMargins(15, 0, 15);
        $pdf->SetY($y_pos);

        // INDICADOR VERDE
        $pdf->SetFillColor(40, 167, 69);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->RoundedRect(55, $pdf->GetY(), 100, 10, 2, '1111', 'F');
        $pdf->Cell(0, 10, '✔ ENTRADA CONFIRMADA', 0, 1, 'C');
        $pdf->Ln(5);

        // TEXTOS DEL EVENTO
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->MultiCell(0, 7, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell(0, 5, $ubicacion, 0, 'C');
        $pdf->MultiCell(0, 5, $fecha_evento, 0, 'C');
        $pdf->Ln(6);

        // DATOS DEL ASISTENTE (Compactados)
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $line_h = 6;
        $label_w = 30;
        
        $pdf->SetX(40); $pdf->Cell($label_w, $line_h, 'Empresa:'); $pdf->SetFont('helvetica', 'B', 10); $pdf->Cell(0, $line_h, $nombre_empresa, 0, 1);
        $pdf->SetX(40); $pdf->SetFont('helvetica', '', 10); $pdf->Cell($label_w, $line_h, 'Nombre:'); $pdf->SetFont('helvetica', 'B', 10); $pdf->Cell(0, $line_h, $nombre_completo, 0, 1);
        $pdf->SetX(40); $pdf->SetFont('helvetica', '', 10); $pdf->Cell($label_w, $line_h, 'Cargo:'); $pdf->SetFont('helvetica', 'B', 10); $pdf->Cell(0, $line_h, $cargo_persona, 0, 1);

        $pdf->Ln(5);
        
        // CÓDIGO QR (Justo debajo del texto)
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 5, 'CÓDIGO DE ESCANEO', 0, 1, 'C');
        
        // QR un poco más pequeño (50mm) para asegurar que no salte de página
        $qr_size = 50;
        $pdf->Image($qr_path, (210 - $qr_size) / 2, $pdf->GetY(), $qr_size, $qr_size, 'PNG', '', '', true, 300);

        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        @unlink($qr_path);

        // REGISTRO DE ASISTENTE (Lógica original)
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
?>
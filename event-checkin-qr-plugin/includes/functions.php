<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, registra asistentes y sincroniza con Zoho CRM (módulo "Eventos").
 * Version: 1.8
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
 * Generar PDF con QR - DISEÑO PREMIUM
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
        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $fecha_evento = is_numeric($fecha_raw) ? date('d/m/Y H:i', $fecha_raw) : $fecha_raw;

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

        // === DISEÑO DEL PDF PREMIUM ===
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0); 
        $pdf->AddPage();
        
        // SECCIÓN SUPERIOR - IMAGEN CON ESQUINAS REDONDEADAS Y OVERLAY
        $y_pos = 0;
        $img_margin = 8;
        $img_width = 210 - (2 * $img_margin);
        $img_height = 90;
        $img_radius = 8;
        
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                if (file_exists($imagen_info['path'])) {
                    // Crear fondo redondeado
                    $pdf->SetFillColor(220, 220, 225);
                    $pdf->RoundedRect($img_margin, 8, $img_width, $img_height, $img_radius, '1111', 'F');
                    
                    // Imagen dentro del rectángulo redondeado
                    $pdf->Image($imagen_info['path'], $img_margin, 8, $img_width, $img_height, '', '', 'T', false, 300);
                    $y_pos = 8 + $img_height;
                    
                    // Overlay oscuro para texto
                    $pdf->SetDrawColor(0, 0, 0);
                    $pdf->SetFillColor(0, 0, 0);
                    $pdf->SetAlpha(0.35);
                    $pdf->RoundedRect($img_margin, 8, $img_width, $img_height, $img_radius, '1111', 'F');
                    $pdf->SetAlpha(1);
                }
            }
        }
        
        if ($y_pos === 0) {
            $y_pos = 100;
        }

        // === SECCIÓN BLANCA PRINCIPAL ===
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, $y_pos, 210, 297 - $y_pos, 'F');

        // === LÍNEA DIVISORIA PREMIUM CON GRADIENTE (simulado) ===
        $pdf->SetDrawColor(100, 180, 220);
        $pdf->SetLineWidth(3);
        $pdf->Line(0, $y_pos, 210, $y_pos);

        $y_pos += 8;

        // === BADGE "ENTRADA CONFIRMADA" FLOTANTE ===
        $badge_y = $y_pos - 12;
        $badge_x = 15;
        $badge_w = 60;
        $badge_h = 10;
        
        $pdf->SetFillColor(41, 182, 246); // Azul vibrante
        $pdf->RoundedRect($badge_x, $badge_y, $badge_w, $badge_h, 2.5, '1111', 'F');
        
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($badge_x + 2, $badge_y + 1.5);
        $pdf->Cell($badge_w - 4, $badge_h - 3, '✓ CONFIRMADA', 0, 0, 'C');

        // === CONTENIDO PRINCIPAL ===
        $pdf->SetMargins(20, 0, 20);
        $pdf->SetY($y_pos + 5);

        // Evento título - Principal
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->MultiCell(0, 7, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(3);

        // Información evento en línea
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(20, $pdf->GetY());
        $info_evento = "📅 " . $fecha_evento . "  |  📍 " . $ubicacion;
        $pdf->Cell(0, 5, $info_evento, 0, 1, 'C');
        $pdf->Ln(5);

        // === DIVISOR DECORATIVO ===
        $pdf->SetDrawColor(230, 230, 230);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(40, $pdf->GetY(), 170, $pdf->GetY());
        $pdf->Ln(5);

        // === DISEÑO EN DOS COLUMNAS (Izq: Datos, Der: QR) ===
        
        // COLUMNA IZQUIERDA - Información Personal
        $col_left = 20;
        $col_width = 90;
        
        // Título "ASISTENTE"
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetXY($col_left, $pdf->GetY());
        $pdf->Cell($col_width, 4, 'ASISTENTE', 0, 1, 'L');
        
        // Nombre prominente
        $pdf->SetFont('helvetica', 'B', 19);
        $pdf->SetTextColor(41, 182, 246); // Azul vibrante
        $pdf->SetXY($col_left, $pdf->GetY());
        $pdf->MultiCell($col_width, 8, $nombre_completo, 0, 'L');
        $pdf->Ln(2);
        
        // Empresa
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($col_left, $pdf->GetY());
        $pdf->Cell($col_width, 6, mb_strtoupper($nombre_empresa, 'UTF-8'), 0, 1, 'L');
        
        // Cargo
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->SetXY($col_left, $pdf->GetY());
        $pdf->MultiCell($col_width, 5, $cargo_persona, 0, 'L');

        // COLUMNA DERECHA - QR Grande y Prominente
        $qr_size = 75;
        $qr_x = 125;
        $col_right_y = $pdf->GetY() - 25;
        
        // Fondo con esquinas redondeadas para QR
        $pdf->SetFillColor(248, 248, 250);
        $pdf->RoundedRect($qr_x - 3, $col_right_y - 3, $qr_size + 6, $qr_size + 6, 4, '1111', 'F');
        
        // Borde elegante
        $pdf->SetDrawColor(41, 182, 246);
        $pdf->SetLineWidth(1.5);
        $pdf->RoundedRect($qr_x - 3, $col_right_y - 3, $qr_size + 6, $qr_size + 6, 4, '1111', '');
        
        // QR
        $pdf->Image($qr_path, $qr_x, $col_right_y, $qr_size, $qr_size, 'PNG', '', '', true, 300);

        // === PIE DE PÁGINA ELEGANTE ===
        $pie_y = 275;
        $pdf->SetY($pie_y);
        
        $pdf->SetDrawColor(230, 230, 230);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(0, $pie_y, 210, $pie_y);
        
        $pdf->SetTextColor(160, 160, 160);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 4, 'Escanea el código QR para registrar tu asistencia', 0, 1, 'C');
        
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(180, 180, 180);
        $pdf->Cell(0, 3, 'Documento de acceso - No transferible', 0, 1, 'C');

        // Salida del PDF
        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        
        // Limpieza y registro
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
                echo '<table class="widefat"><thead><tr><th>Nombre</th><th>Empresa</th><th>Cargo</th><th>Fecha</th></tr></thead><tbody>';
                foreach ($asistentes as $a) {
                    echo "<tr>
                            <td>".esc_html($a['nombre'])."</td>
                            <td>".esc_html($a['empresa'])."</td>
                            <td>".esc_html($a['cargo'] ?? '-')."</td>
                            <td>".esc_html($a['fecha_hora'] ?? '-')."</td>
                          </tr>";
                }
                echo '</tbody></table>';
            } else {
                echo "<p>No hay asistentes registrados para este evento.</p>";
            }
        }
        echo '</div>';
    });
});
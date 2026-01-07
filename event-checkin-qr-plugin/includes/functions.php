<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, registra asistentes y sincroniza con Zoho CRM. Incluye imagen con esquinas redondeadas.
 * Version: 1.8.1
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
            'nombre'  => rawurlencode($nombre_completo),
            'cargo'   => rawurlencode($cargo_persona),
            'email'   => rawurlencode($request['email'] ?? ''), 
            'evento'  => rawurlencode($titulo_a_mostrar),
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
        $pdf->SetAutoPageBreak(false, 0); // Evita el salto de página automático
        $pdf->SetMargins(8, 8, 8); 
        $pdf->AddPage();
        
        // Fondo principal
        $pdf->SetFillColor(245, 245, 247);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', 'F');
        
        // Borde decorativo
        $pdf->SetDrawColor(200, 200, 205);
        $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', '');

        $y_dinamica = 18;

        // === IMAGEN DE CABECERA CON ESQUINAS REDONDEADAS ===
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                if (file_exists($imagen_info['path'])) {
                    list($ancho_orig, $alto_orig) = getimagesize($imagen_info['path']);
                    $ancho_pdf = 180; 
                    $alto_pdf = ($alto_orig * $ancho_pdf) / $ancho_orig;
                    
                    $pdf->StartTransform();
                    $pdf->RoundedRect(15, $y_dinamica, $ancho_pdf, $alto_pdf, 5, '1111', 'CNZ');
                    $pdf->Image($imagen_info['path'], 15, $y_dinamica, $ancho_pdf, $alto_pdf, '', '', 'T', false, 300);
                    $pdf->StopTransform();

                    $pdf->SetDrawColor(210, 210, 215);
                    $pdf->SetLineWidth(0.2);
                    $pdf->RoundedRect(15, $y_dinamica, $ancho_pdf, $alto_pdf, 5, '1111', 'D');

                    $y_dinamica = $y_dinamica + $alto_pdf + 6; // Ajustado de 8 a 6
                }
            }
        }

        $pdf->SetMargins(25, 0, 25);
        $pdf->SetAbsY($y_dinamica);

        // === INDICADOR "ENTRADA CONFIRMADA" ===
        $badge_w = 160; $badge_h = 12;
        $badge_x = (210 - $badge_w) / 2;
        $badge_y = $pdf->GetY();
        $pdf->SetFillColor(76, 175, 80);
        $pdf->RoundedRect($badge_x, $badge_y, $badge_w, $badge_h, 3, '1111', 'F');
        
        $circle_x = $badge_x + 8; $circle_y = $badge_y + ($badge_h / 2);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Circle($circle_x, $circle_y, 4, 0, 360, 'F');
        
        $pdf->SetTextColor(76, 175, 80);
        $pdf->SetFont('zapfdingbats', '', 13);
        $pdf->SetXY($circle_x - 2.5, $circle_y - 3.5);
        $pdf->Cell(5, 7, '4', 0, 0, 'C'); 

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY($badge_x + 15, $badge_y + 1);
        $pdf->Cell($badge_w - 15, $badge_h, 'ENTRADA CONFIRMADA', 0, 0, 'L');
        $pdf->Ln(12); // Reducido de 14 a 12

        // === CUERPO DE TEXTO ===
        $pdf->SetTextColor(100, 100, 105);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(0, 5, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(2);

        $pdf->SetDrawColor(200, 200, 210);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(25, $pdf->GetY(), 185, $pdf->GetY());
        $pdf->Ln(4);

        // === FECHA Y LUGAR (LETRA AUMENTADA) ===
        $pdf->SetTextColor(80, 80, 85);
        $pdf->SetFont('helvetica', 'B', 13); // Aumentado de 10 a 13 y en negrita
        $info_evento = "FECHA: " . $fecha_evento . "   |   LUGAR: " . $ubicacion;
        $pdf->MultiCell(0, 7, $info_evento, 0, 'C');
        $pdf->Ln(4);

        $pdf->SetTextColor(150, 150, 155);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(0, 3, 'ASISTENTE', 0, 1, 'C');
        
        $pdf->SetTextColor(60, 60, 65); 
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->MultiCell(0, 8, $nombre_completo, 0, 'C');
        $pdf->Ln(1);

        $pdf->SetTextColor(70, 70, 75);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, mb_strtoupper($nombre_empresa, 'UTF-8'), 0, 1, 'C');
        
        $pdf->SetTextColor(110, 110, 115);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, $cargo_persona, 0, 1, 'C');
        $pdf->Ln(2);

        // === QR (LIGERAMENTE REDUCIDO PARA EVITAR SALTO) ===
        $qr_size = 75; // Reducido de 80 a 75 para ganar espacio vertical
        $qr_x = (210 - $qr_size) / 2;
        $qr_y = $pdf->GetY() + 2;
        
        $pdf->SetFillColor(240, 245, 250);
        $pdf->RoundedRect($qr_x - 6, $qr_y - 3, $qr_size + 12, $qr_size + 6, 5, '1111', 'F');
        
        $pdf->SetDrawColor(76, 175, 80);
        $pdf->SetLineWidth(0.8);
        $pdf->RoundedRect($qr_x - 6, $qr_y - 3, $qr_size + 12, $qr_size + 6, 5, '1111', '');
        
        $pdf->Image($qr_path, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG', '', '', true, 300);

        // Guardar PDF
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
 * Check-in Handler
 * ---------------------------
 */
add_action('template_redirect', function(){
    if(strpos($_SERVER['REQUEST_URI'],'/checkin/')!==false){
        $nombre = sanitize_text_field($_GET['nombre'] ?? 'Invitado');
        $evento = sanitize_text_field($_GET['evento'] ?? '');
        $post_id = buscar_evento_robusto($evento);
        if($post_id){
            $asistentes = get_post_meta($post_id,'_asistentes',true) ?: [];
            $asistentes[] = [
                'nombre' => $nombre,
                'empresa' => sanitize_text_field($_GET['empresa'] ?? ''),
                'cargo' => sanitize_text_field($_GET['cargo'] ?? ''),
                'fecha_hora' => current_time('mysql')
            ];
            update_post_meta($post_id,'_asistentes',$asistentes);
        }
        echo "<div style='text-align:center;font-family:sans-serif;margin-top:50px;'>";
        echo "<h2>Check-in confirmado ✅</h2><p>Bienvenido/a: <strong>" . esc_html($nombre) . "</strong></p>";
        echo "</div>";
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
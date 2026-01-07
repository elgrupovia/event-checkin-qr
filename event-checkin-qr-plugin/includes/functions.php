<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, registra asistentes y sincroniza con Zoho CRM. Diseño con cabecera redondeada, tick de confirmación y QR estilizado.
 * Version: 2.0.0
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
        $nombre_completo = html_entity_decode(trim($nombre_persona . ' ' . $apellidos_persona), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $titulo_evento_formulario = sanitize_text_field($request['eventos_2025'][0] ?? '');
        $post_id = $titulo_evento_formulario ? buscar_evento_robusto($titulo_evento_formulario) : null;

        $titulo_a_mostrar = $post_id ? get_the_title($post_id) : ($titulo_evento_formulario ?: 'Evento no identificado');
        $titulo_a_mostrar = html_entity_decode($titulo_a_mostrar, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true) ?: 'Ubicación no disponible';
        $fecha_raw = get_post_meta($post_id, 'fecha', true);
        $fecha_evento = is_numeric($fecha_raw) ? date('d/m/Y H:i', $fecha_raw) : $fecha_raw;

        $base_url = home_url('/checkin/');
        $params = [
            'empresa' => rawurlencode($nombre_empresa),
            'nombre'  => rawurlencode($nombre_completo),
            'evento'  => rawurlencode($titulo_a_mostrar),
        ];
        $qr_url = $base_url . '?' . http_build_query($params);

        $qr = Builder::create()->writer(new PngWriter())->data($qr_url)->size(300)->margin(10)->build();
        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        // === CONFIGURACIÓN PDF ===
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0); 
        $pdf->SetMargins(8, 8, 8); 
        $pdf->AddPage();
        
        // Fondo Gris Claro del ticket
        $pdf->SetFillColor(245, 245, 247);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', 'F');

        $y_dinamica = 8;

        // === IMAGEN CABECERA (ESQUINAS SIMÉTRICAS REDONDEADAS) ===
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                if (file_exists($imagen_info['path'])) {
                    list($ancho_orig, $alto_orig) = getimagesize($imagen_info['path']);
                    $ancho_pdf = 194; 
                    $alto_pdf = ($alto_orig * $ancho_pdf) / $ancho_orig;
                    $radio_esquinas = 6; // Mismo radio que el marco del ticket
                    
                    // Coordenadas de la imagen
                    $img_x = 8;
                    $img_y = 8;
                    
                    // 1. Dibujar fondo blanco con esquinas redondeadas (contenedor)
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->RoundedRect($img_x, $img_y, $ancho_pdf, $alto_pdf, $radio_esquinas, '1100', 'F');
                    
                    // 2. Crear máscara circular para las esquinas superiores
                    $pdf->StartTransform();
                    
                    // Esquina superior-izquierda
                    $pdf->SetLineWidth(0);
                    $pdf->SetDrawColor(245, 245, 247); // Color del fondo del ticket
                    $pdf->Circle($img_x + $radio_esquinas, $img_y + $radio_esquinas, $radio_esquinas, 0, 360, 'F');
                    
                    // Esquina superior-derecha
                    $pdf->Circle($img_x + $ancho_pdf - $radio_esquinas, $img_y + $radio_esquinas, $radio_esquinas, 0, 360, 'F');
                    
                    $pdf->StopTransform();
                    
                    // 3. Insertar imagen con clipping en esquinas redondeadas
                    $pdf->Image($imagen_info['path'], $img_x, $img_y, $ancho_pdf, $alto_pdf, '', '', 'T', false, 300);
                    
                    // 4. Dibujar borde de las esquinas redondeadas
                    $pdf->SetDrawColor(200, 200, 205);
                    $pdf->SetLineWidth(0.5);
                    $pdf->RoundedRect($img_x, $img_y, $ancho_pdf, $alto_pdf, $radio_esquinas, '1100', 'D');
                    
                    $y_dinamica = $img_y + $alto_pdf + 10;
                }
            }
        }

        // Borde exterior del ticket
        $pdf->SetDrawColor(200, 200, 205);
        $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect(8, 8, 194, 279, 6, '1111', 'D');

        $pdf->SetMargins(25, 0, 25);
        $pdf->SetAbsY($y_dinamica);

        // === BADGE CONFIRMACIÓN CON TICK (✓) ===
        $badge_w = 160; $badge_h = 11;
        $badge_x = (210 - $badge_w) / 2;
        $badge_y = $pdf->GetY();
        $pdf->SetFillColor(76, 175, 80);
        $pdf->RoundedRect($badge_x, $badge_y, $badge_w, $badge_h, 3, '1111', 'F');
        
        // Dibujamos el tick ✓ usando la fuente ZapfDingbats
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('zapfdingbats', '', 12);
        $pdf->SetXY($badge_x + 45, $badge_y + 1); // Posición manual para centrar con el texto
        $pdf->Cell(10, $badge_h, '4', 0, 0, 'R'); // '4' es el código del tick en ZapfDingbats

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetXY($badge_x, $badge_y);
        $pdf->Cell($badge_w, $badge_h, '    ENTRADA CONFIRMADA', 0, 0, 'C'); 
        $pdf->Ln(15);

        // === TÍTULO Y SEPARADOR ===
        $pdf->SetTextColor(100, 100, 105);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->MultiCell(0, 6, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(2);

        $pdf->SetDrawColor(200, 200, 210);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(25, $pdf->GetY(), 185, $pdf->GetY());
        $pdf->Ln(4);

        // === FECHA Y LUGAR ===
        $pdf->SetTextColor(120, 120, 125);
        $pdf->SetFont('helvetica', '', 12); 
        $info_evento = "FECHA: " . $fecha_evento . "   |   LUGAR: " . $ubicacion;
        $pdf->MultiCell(0, 6, $info_evento, 0, 'C');
        $pdf->Ln(8);

        // === DATOS ASISTENTE (CARGO ELIMINADO) ===
        $pdf->SetTextColor(60, 60, 65); 
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->MultiCell(0, 10, $nombre_completo, 0, 'C');
        $pdf->Ln(2);

        $pdf->SetTextColor(70, 70, 75);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(0, 6, mb_strtoupper($nombre_empresa, 'UTF-8'), 0, 1, 'C');
        $pdf->Ln(8);

        // === QR CON DISEÑO REDONDEADO ===
        $qr_size = 75;
        $qr_x = (210 - $qr_size) / 2;
        $qr_y = $pdf->GetY();
        
        // Recuadro del QR con esquinas redondeadas
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($qr_x - 4, $qr_y - 4, $qr_size + 8, $qr_size + 8, 5, '1111', 'F');
        $pdf->Image($qr_path, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG', '', '', true, 300);

        // Guardar PDF
        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        
        @unlink($qr_path);
        
        if ($post_id) {
            $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
            $asistentes[] = [
                'nombre' => $nombre_completo, 
                'empresa' => $nombre_empresa, 
                'fecha_hora' => current_time('mysql')
            ];
            update_post_meta($post_id, '_asistentes', $asistentes);
        }

    } catch (Exception $e) {
        error_log("❌ Error PDF/Registro: " . $e->getMessage());
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
            $asistentes[] = [
                'nombre' => $nombre,
                'empresa' => sanitize_text_field($_GET['empresa'] ?? ''),
                'fecha_hora' => current_time('mysql'),
                'tipo' => 'escaneo_qr'
            ];
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
        }
        echo '</div>';
    });
});
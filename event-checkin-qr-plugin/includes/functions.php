<?php
/**
 * Plugin: Event Check-In QR
 * Funcionalidad:
 *  - Genera PDF con QR personalizado al registrarse.
 *  - Registra asistentes al escanear el QR.
 *  - Muestra listado de asistentes en un submenú dentro de Eventos.
 */

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

        $base_url = home_url('/checkin/');
        $params = [
            'empresa' => rawurlencode($nombre_empresa),
            'nombre' => rawurlencode($nombre_completo),
            'cargo' => rawurlencode($cargo_persona),
            'evento' => rawurlencode($titulo_a_mostrar),
            'ubicacion' => rawurlencode($ubicacion),
            'fecha' => rawurlencode($fecha_evento),
        ];
        $qr_url = $base_url . '?' . implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));

        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($qr_url)
            ->size(400)
            ->margin(15)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        // === Generar PDF ===
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCompression(false);
        $pdf->SetImageScale(4);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 20, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $logo_path = plugin_dir_path(__FILE__) . '../assets/LOGO_GRUPO_VIA_RGB__NEGRO.jpg';
        if (file_exists($logo_path)) $pdf->Image($logo_path, 85, 8, 35, '', 'JPG', '', 'T', false, 300);

        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                $imagen_path = $imagen_info['path'];
                if (file_exists($imagen_path)) {
                    $pdf->Image($imagen_path, (210 - 150) / 2, 30, 150, '', '', 'T', false, 300);
                    $imagen_insertada = true;
                }
            }
        }

        $pdf->SetY($imagen_insertada ? 115 : 60);
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->Cell(0, 14, 'ENTRADA CONFIRMADA', 0, 1, 'C');
        $pdf->Ln(8);

        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->MultiCell(0, 7, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->MultiCell(0, 5, htmlspecialchars($ubicacion, ENT_QUOTES, 'UTF-8'), 0, 'C');
        $pdf->MultiCell(0, 5, htmlspecialchars($fecha_evento, ENT_QUOTES, 'UTF-8'), 0, 'C');
        $pdf->Ln(10);

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);

        $pdf->SetX(35);
        $pdf->Write(6, 'Empresa: ');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $nombre_empresa, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $pdf->SetX(35);
        $pdf->Write(6, 'Nombre: ');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $nombre_completo, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $pdf->SetX(35);
        $pdf->Write(6, 'Cargo: ');
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $cargo_persona, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $pdf->Ln(8);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 4, 'CÓDIGO DE ESCANEO', 0, 1, 'C');
        $pdf->Ln(4);

        $pdf->Image($qr_path, (210 - 65) / 2, $pdf->GetY(), 65, 65, 'PNG', '', '', true, 300);

        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        @unlink($qr_path);

        /**
         * ✅ NUEVO BLOQUE: Registrar asistente automáticamente
         */
        if ($post_id) {
            $asistentes = get_post_meta($post_id, '_asistentes', true);
            if (!is_array($asistentes)) $asistentes = [];
            $asistentes[] = [
                'nombre' => $nombre_completo,
                'empresa' => $nombre_empresa,
                'cargo' => $cargo_persona,
                'fecha_hora' => current_time('mysql')
            ];
            update_post_meta($post_id, '_asistentes', $asistentes);
        }

    } catch (Exception $e) {
        error_log("Error PDF: " . $e->getMessage());
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
        $empresa = sanitize_text_field($_GET['empresa'] ?? '');
        $cargo = sanitize_text_field($_GET['cargo'] ?? '');
        $evento = sanitize_text_field($_GET['evento'] ?? '');

        $post_id = buscar_evento_robusto($evento);
        if($post_id){
            $asistentes = get_post_meta($post_id,'_asistentes',true);
            if(!is_array($asistentes)) $asistentes = [];
            $asistentes[] = [
                'nombre'=>$nombre,
                'empresa'=>$empresa,
                'cargo'=>$cargo,
                'fecha_hora'=>current_time('mysql')
            ];
            update_post_meta($post_id,'_asistentes',$asistentes);
        }

        echo "<h2>Check-in confirmado</h2>";
        echo "<p>Bienvenido: <strong>{$nombre}</strong></p>";
        exit;
    }
});

/**
 * ---------------------------
 * Admin: Submenú Asistentes
 * ---------------------------
 */
add_action('admin_menu', function(){
    add_submenu_page(
        'edit.php?post_type=eventos',
        'Asistentes',
        'Asistentes',
        'manage_options',
        'eventos-asistentes',
        function(){
            $eventos = get_posts(['post_type'=>'eventos','numberposts'=>-1]);
            echo '<h1>Asistentes por Evento</h1>';
            foreach($eventos as $e){
                echo '<h2>'.get_the_title($e->ID).'</h2>';
                $asistentes = get_post_meta($e->ID,'_asistentes',true);
                if($asistentes){
                    echo '<ul>';
                    foreach($asistentes as $a){
                        echo '<li>'.esc_html($a['nombre']).' ('.esc_html($a['empresa']).') - '.esc_html($a['fecha_hora']).'</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p>No hay asistentes registrados.</p>';
                }
            }
        }
    );
});
?>

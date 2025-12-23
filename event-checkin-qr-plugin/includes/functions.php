<?php
/**
 * Plugin Name: Event Check-In QR (Integración Zoho)
 * Description: Genera PDF con QR, registra asistentes y sincroniza con Zoho CRM.
 * Version: 1.2
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
        error_log("=== [INSCRIPCIÓN INICIADA] ===");

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
            'nombre'  => rawurlencode($nombre_completo),
            'cargo'   => rawurlencode($cargo_persona),
            'email'   => rawurlencode($request['email'] ?? ''), 
            'evento'  => rawurlencode($titulo_a_mostrar),
            'ubicacion'=> rawurlencode($ubicacion),
            'fecha'   => rawurlencode($fecha_evento),
        ];
        $qr_url = $base_url . '?' . http_build_query($params);

        // Generar QR
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($qr_url)
            ->size(400)
            ->margin(15)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        // === CREACIÓN DEL PDF ===
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0); // Márgenes a 0 para la imagen full-width
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->AddPage();

        $current_y = 0;

        // 1. Imagen destacada (TODO EL ANCHO)
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                if (file_exists($imagen_info['path'])) {
                    $pdf->Image($imagen_info['path'], 0, 0, 210, 0, '', '', 'T', false, 300);
                    $current_y = $pdf->GetY() + 10;
                }
            }
        }

        if ($current_y == 0) $current_y = 20;
        $pdf->SetY($current_y);
        $pdf->SetMargins(15, 0, 15); // Restaurar márgenes para el contenido

        // 2. Indicador Verde "ENTRADA CONFIRMADA"
        $pdf->SetFillColor(40, 167, 69);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->RoundedRect(45, $pdf->GetY(), 120, 12, 2, '1111', 'F');
        $pdf->Cell(0, 12, '✔ ENTRADA CONFIRMADA', 0, 1, 'C');
        
        $pdf->Ln(8);
        $pdf->SetTextColor(0, 0, 0);

        // 3. Título y detalles
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->MultiCell(0, 10, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->MultiCell(0, 6, $ubicacion, 0, 'C');
        $pdf->MultiCell(0, 6, $fecha_evento, 0, 'C');
        $pdf->Ln(10);

        // 4. Datos del Asistente
        $pdf->SetTextColor(0, 0, 0);
        $datos = [
            'Empresa' => $nombre_empresa,
            'Nombre'  => $nombre_completo,
            'Cargo'   => $cargo_persona
        ];

        foreach ($datos as $label => $valor) {
            $pdf->SetX(40);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->Write(7, $label . ': ');
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->MultiCell(0, 7, $valor, 0, 'L');
        }

        $pdf->Ln(10);

        // 5. QR Code
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(0, 5, 'CÓDIGO DE ACCESO PERSONAL', 0, 1, 'C');
        $pdf->Image($qr_path, (210 - 60) / 2, $pdf->GetY() + 2, 60, 60, 'PNG', '', '', true, 300);

        // Guardar PDF
        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        @unlink($qr_path);

        // Registro Local
        if ($post_id) {
            $asistentes = get_post_meta($post_id, '_asistentes', true) ?: [];
            $asistentes[] = [
                'nombre' => $nombre_completo,
                'empresa' => $nombre_empresa,
                'cargo' => $cargo_persona,
                'fecha_hora' => current_time('mysql')
            ];
            update_post_meta($post_id, '_asistentes', $asistentes);
        }

        error_log("=== [INSCRIPCIÓN FINALIZADA] ===");

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
        $empresa = sanitize_text_field($_GET['empresa'] ?? '');
        $cargo = sanitize_text_field($_GET['cargo'] ?? '');
        $evento = sanitize_text_field($_GET['evento'] ?? '');
        $email = sanitize_email($_GET['email'] ?? '');

        $post_id = buscar_evento_robusto($evento);
        if($post_id){
            $asistentes = get_post_meta($post_id,'_asistentes',true) ?: [];
            $asistentes[] = [
                'nombre'=>$nombre,
                'empresa'=>$empresa,
                'cargo'=>$cargo,
                'fecha_hora'=>current_time('mysql'),
                'tipo' => 'QR Scan'
            ];
            update_post_meta($post_id,'_asistentes',$asistentes);

            // Sincronización Zoho
            require_once dirname(__FILE__) . '/../zoho/config.php';
            require_once dirname(__FILE__) . '/../zoho/contacts.php';
            require_once dirname(__FILE__) . '/../zoho/eventos.php';

            if ($email) {
                $busqueda = searchContactByEmail($email);
                if (isset($busqueda['data'][0]['id'])) {
                    $contactId = $busqueda['data'][0]['id'];
                    $eventZohoId = obtenerEventoZohoId($evento);
                    if ($eventZohoId) {
                        marcadoAsistenciaZoho($contactId, $eventZohoId);
                    }
                }
            }
        }

        echo "<div style='text-align:center; font-family:sans-serif; padding-top:50px;'>";
        echo "<h1 style='color:#28a745;'>Check-in confirmado ✅</h1>";
        echo "<h2>Bienvenido, " . esc_html($nombre) . "</h2>";
        echo "<p><strong>Evento:</strong> " . esc_html($evento) . "</p>";
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
    add_submenu_page(
        'edit.php?post_type=eventos',
        'Asistentes',
        'Asistentes',
        'manage_options',
        'eventos-asistentes',
        function() {
            echo '<div class="wrap"><h1>🧾 Asistentes por Evento</h1>';
            $eventos = get_posts(['post_type'=>'eventos','post_status'=>'publish','posts_per_page'=>-1]);

            foreach ($eventos as $e) {
                $asistentes = get_post_meta($e->ID, '_asistentes', true) ?: [];
                echo '<div style="background:#fff; padding:15px; margin-bottom:20px; border:1px solid #ccd0d4;">';
                echo '<h2>' . esc_html($e->post_title) . ' (' . count($asistentes) . ')</h2>';
                if (!empty($asistentes)) {
                    echo '<table class="widefat striped"><thead><tr><th>Nombre</th><th>Empresa</th><th>Cargo</th><th>Fecha</th></tr></thead><tbody>';
                    foreach ($asistentes as $a) {
                        echo "<tr><td>{$a['nombre']}</td><td>{$a['empresa']}</td><td>{$a['cargo']}</td><td>{$a['fecha_hora']}</td></tr>";
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>No hay registros.</p>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
    );
});
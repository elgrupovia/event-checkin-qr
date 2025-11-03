<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 * Incluye nombre, empresa, cargo, evento, ubicación y fecha/hora en el QR
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

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
    $primeras = array_slice($palabras, 0, $limite);
    return implode(' ', $primeras);
}

function buscar_evento_robusto($titulo_buscado) {
    error_log("🔍 INICIO BÚSQUEDA ROBUSTA: " . $titulo_buscado);

    $primeras = primeras_palabras($titulo_buscado, 3);
    $ciudades = ['barcelona', 'valencia', 'madrid', 'bilbao'];

    $ciudad_form = null;
    $normForm = normalizar_texto($titulo_buscado);

    foreach ($ciudades as $c) {
        if (stripos($normForm, normalizar_texto($c)) !== false) {
            $ciudad_form = $c;
            break;
        }
    }

    $args = [
        'post_type'      => 'eventos',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        's'              => $primeras,
        'tax_query' => [
            'relation' => 'AND',
            [
                'taxonomy' => 'ano',
                'field'    => 'slug',
                'terms'    => ['2025'],
            ],
            [
                'taxonomy' => 'ciudades',
                'field'    => 'slug',
                'terms'    => $ciudades,
            ],
        ],
    ];

    $eventos = get_posts($args);
    $event_id = 0;

    if (!empty($eventos) && !empty($ciudad_form)) {
        $ciudad_buscar = normalizar_texto($ciudad_form);
        foreach ($eventos as $evento) {
            $titulo_evento = get_the_title($evento->ID);
            $titulo_evento_norm = normalizar_texto($titulo_evento);
            if (stripos($titulo_evento_norm, $ciudad_buscar) !== false) {
                $event_id = (int)$evento->ID;
                break;
            }
        }
    }

    if ($event_id === 0) {
        $eventos_all = get_posts([
            'post_type'      => 'eventos',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ]);

        foreach ($eventos_all as $evento) {
            $titulo_evento = get_the_title($evento->ID);
            $titulo_evento_norm = normalizar_texto($titulo_evento);
            if (stripos($titulo_evento_norm, normalizar_texto($titulo_buscado)) !== false) {
                $event_id = (int)$evento->ID;
                error_log("⚡ EVENTO FORZADO ENCONTRADO: {$titulo_evento}");
                break;
            }
        }
    }

    error_log('🏙️ Ciudad detectada: ' . ($ciudad_form ?: 'ninguna'));
    error_log('✅ Evento elegido ID: ' . $event_id);
    return $event_id;
}

function optimizar_imagen_para_pdf($imagen_url, $upload_dir) {
    $tmp = null;
    $imagen_path = '';

    $attachment_id = attachment_url_to_postid($imagen_url);
    if ($attachment_id) {
        $imagen_meta = wp_get_attachment_metadata($attachment_id);
        if ($imagen_meta) {
            $imagen_path = $upload_dir['basedir'] . '/' . $imagen_meta['file'];
        }
    }

    if (!file_exists($imagen_path)) {
        if (function_exists('download_url')) {
            $tmp = download_url($imagen_url, 300);
            if (!is_wp_error($tmp)) {
                $imagen_path = $tmp;
            }
        }
    }

    return ['path' => $imagen_path, 'tmp' => $tmp];
}

function generar_qr_pdf_personalizado($request, $action_handler) {
    try {
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';

        $apellidos_persona = '';
        if (!empty($request['apellidos'])) {
            $apellidos_persona = sanitize_text_field($request['apellidos']);
        } elseif (!empty($request['last_name'])) {
            $apellidos_persona = sanitize_text_field($request['last_name']);
        } elseif (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (!empty($user->last_name)) {
                $apellidos_persona = sanitize_text_field($user->last_name);
            }
        }

        $cargo_persona = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';
        $nombre_completo = trim($nombre_persona . ' ' . $apellidos_persona);
        $nombre_completo = html_entity_decode($nombre_completo, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $titulo_evento_formulario = '';
        if (isset($request['eventos_2025']) && !empty($request['eventos_2025'][0])) {
            $titulo_evento_formulario = trim(sanitize_text_field($request['eventos_2025'][0]));
        }

        $post_id = null;
        $titulo_evento_encontrado = $titulo_evento_formulario;

        if ($titulo_evento_formulario) {
            $post_id = buscar_evento_robusto($titulo_evento_formulario);
            if ($post_id) {
                $titulo_evento_encontrado = trim(get_the_title($post_id));
                error_log("✅ EVENTO FINAL ENCONTRADO: ID={$post_id}, Título='{$titulo_evento_encontrado}'");
            } else {
                error_log("❌ No se pudo encontrar el evento. La imagen NO se insertará.");
            }
        }

        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';
        $titulo_a_mostrar = html_entity_decode($titulo_a_mostrar, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true);
        $fecha_evento = get_post_meta($post_id, 'fecha', true);
        if (is_numeric($fecha_evento)) {
            $fecha_evento = date('d/m/Y H:i', $fecha_evento);
        }

        $base_url = home_url('/checkin/');

        $empresa   = $nombre_empresa ?: 'Desconocida';
        $nombre    = $nombre_completo ?: 'Sin nombre';
        $cargo     = $cargo_persona ?: 'Sin cargo';
        $evento    = $titulo_a_mostrar ?: 'Evento no identificado';
        $ubicacion = $ubicacion ?: 'Ubicación no disponible';
        $fecha     = $fecha_evento ?: 'Fecha no especificada';

        
        $params = [
            'empresa'   => rawurlencode($empresa),
            'nombre'    => rawurlencode($nombre),
            'cargo'     => rawurlencode($cargo),
            'evento'    => rawurlencode($evento),
            'ubicacion' => rawurlencode($ubicacion),
            'fecha'     => rawurlencode($fecha),
        ];

        
        $query_string = implode('&', array_map(fn($k, $v) => "{$k}={$v}", array_keys($params), $params));
        $qr_url = $base_url . '?' . $query_string;


        error_log("🌐 URL generada para QR: " . $qr_url);

        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($qr_url)
            ->size(400)
            ->margin(15)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);
        error_log("🧾 QR generado con URL completa en: " . $qr_path);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCompression(false);
        $pdf->SetImageScale(4);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 20, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $logo_path = plugin_dir_path(__FILE__) . '../assets/LOGO_GRUPO_VIA_RGB__NEGRO.jpg';
        if (file_exists($logo_path)) {
            $pdf->Image($logo_path, 85, 8, 35, '', 'JPG', '', 'T', false, 300);
        }

        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                $imagen_path = $imagen_info['path'];
                if (file_exists($imagen_path)) {
                    $imagen_x = (210 - 150) / 2;
                    $pdf->Image($imagen_path, $imagen_x, 30, 150, '', '', '', 'T', false, 300);
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
        if (!empty($ubicacion)) $pdf->MultiCell(0, 5, htmlspecialchars($ubicacion, ENT_QUOTES, 'UTF-8'), 0, 'C');
        if (!empty($fecha_evento)) $pdf->MultiCell(0, 5, htmlspecialchars($fecha_evento, ENT_QUOTES, 'UTF-8'), 0, 'C');

        $pdf->Ln(10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 10);

        // Empresa
        $pdf->SetX(35);
        $pdf->Write(6, "Empresa: ");
        $pdf->SetFont('helvetica', 'B', 10); // Negrita solo para el valor
        $pdf->MultiCell(0, 6, $nombre_empresa, 0, 'L');
        $pdf->SetFont('helvetica', '', 10); // Vuelve a normal

        // Nombre
        $pdf->SetX(35);
        $pdf->Write(6, "Nombre: ");
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $nombre_completo, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        // Cargo
        $pdf->SetX(35);
        $pdf->Write(6, "Cargo: ");
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->MultiCell(0, 6, $cargo_persona, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $pdf->Ln(8);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 4, 'CÓDIGO DE ESCANEO', 0, 1, 'C');
        $pdf->Ln(4);

        $qr_size = 65;
        $qr_x = (210 - $qr_size) / 2;
        $pdf->Image($qr_path, $qr_x, $pdf->GetY(), $qr_size, $qr_size, 'PNG', '', '', true, 300);

        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');

        @unlink($qr_path);
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
    }
}
/**
 * ---------------------------
 * Registrar QR leídos
 * ---------------------------
 */
add_action('template_redirect', function() {
    if (strpos($_SERVER['REQUEST_URI'], '/checkin/') !== false) {
        $nombre   = sanitize_text_field($_GET['nombre'] ?? 'Invitado');
        $empresa  = sanitize_text_field($_GET['empresa'] ?? '');
        $cargo    = sanitize_text_field($_GET['cargo'] ?? '');
        $evento   = sanitize_text_field($_GET['evento'] ?? '');

        $post_id = buscar_evento_robusto($evento);
        if ($post_id) {
            $asistentes = get_post_meta($post_id, '_asistentes', true);
            if (!is_array($asistentes)) $asistentes = [];
            $asistentes[] = [
                'nombre' => $nombre,
                'empresa' => $empresa,
                'cargo' => $cargo,
                'fecha_hora' => current_time('mysql')
            ];
            update_post_meta($post_id, '_asistentes', $asistentes);
        }

        echo "<h2>Check-in confirmado</h2>";
        echo "<p>Bienvenido: <strong>{$nombre}</strong></p>";
        exit;
    }
});

/**
 * ---------------------------
 * Admin: Apartado Asistentes
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
            $eventos = get_posts(['post_type'=>'eventos','numberposts'=>-1]);
            echo '<h1>Asistentes por Evento</h1>';
            foreach($eventos as $e) {
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

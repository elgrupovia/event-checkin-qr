<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 * ✅ Búsqueda mejorada con normalización de texto y múltiples estrategias
 * ✅ Imagen de ALTA CALIDAD y TAMAÑO GRANDE
 * ✅ Logo en cabecera y lugar del evento visible
 * ✅ DISEÑO RESPONSIVE - Imagen más grande con mejor distribución
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

/**
 * Normaliza texto para comparación (quita acentos, convierte a minúsculas, normaliza espacios)
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
    $primeras = array_slice($palabras, 0, $limite);
    return implode(' ', $primeras);
}

/**
 * Busca el evento usando múltiples estrategias y fallback forzado
 */
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

    // --- Fallback: búsqueda forzada por título completo si no se encuentra ---
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

/**
 * Optimiza la imagen para mejor calidad en PDF sin compresión excesiva
 */
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

/**
 * Función principal: genera el PDF con QR + logo + imagen del evento (DISEÑO MEJORADO)
 */
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
        } else {
            error_log("⚠️ No se recibió el nombre del evento en el formulario (campo eventos_2025)");
        }

        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';
        $titulo_a_mostrar = html_entity_decode($titulo_a_mostrar, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // --- GENERACIÓN DE QR ---
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_completo}\nCargo: {$cargo_persona}";
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(400)
            ->margin(15)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);
        error_log("🧾 QR generado en: " . $qr_path);

        // --- GENERACIÓN DE PDF ---
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCompression(false);
        $pdf->SetImageScale(4);
        $pdf->AddPage();
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);

        // --- INSERTAR LOGO EN CABECERA (más pequeño) ---
        $logo_path = plugin_dir_path(__FILE__) . '../assets/LOGO_GRUPO_VIA_RGB__NEGRO.jpg';
        if (file_exists($logo_path)) {
            try {
                $pdf->Image($logo_path, 85, 8, 35, '', 'JPG', '', 'T', false, 300);
                error_log("✅ Logo insertado correctamente en cabecera: " . $logo_path);
            } catch (Exception $e) {
                error_log("❌ Error al insertar logo: " . $e->getMessage());
            }
        } else {
            error_log("⚠️ Logo no encontrado en: " . $logo_path);
        }

        $pdf->SetY(32);

        // --- INSERTAR IMAGEN DEL EVENTO (MÁS GRANDE) ---
        $imagen_insertada = false;
        $altura_imagen = 0;

        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                $imagen_path = $imagen_info['path'];
                $tmp = $imagen_info['tmp'];

                if (file_exists($imagen_path)) {
                    try {
                        // Imagen más grande: ancho 170mm (casi todo el ancho disponible)
                        $pdf->Image($imagen_path, 20, 32, 170, '', '', '', 'T', false, 300);
                        $imagen_insertada = true;
                        $altura_imagen = 110; // Aproximadamente la altura que ocupará
                        error_log("✅ Imagen destacada insertada sin compresión - Tamaño grande");
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen en PDF: " . $e->getMessage());
                    }
                }

                if ($tmp && !is_wp_error($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        // --- POSICIÓN DE CONTENIDO BASADA EN SI HAY IMAGEN ---
        $pdf->SetY($imagen_insertada ? 145 : 50);

        // --- TÍTULO: "ENTRADA CONFIRMADA" ---
        $pdf->SetFont('helvetica', 'B', 20);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 12, 'ENTRADA CONFIRMADA', 0, 1, 'C');
        $pdf->Ln(3);

        // --- NOMBRE DEL EVENTO ---
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->MultiCell(0, 7, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(2);

        // --- MOSTRAR CIUDAD / LUGAR, UBICACIÓN Y FECHA ---
        $ciudad = '';
        $ciudades = wp_get_post_terms($post_id, 'ciudades');
        if (!empty($ciudades) && !is_wp_error($ciudades)) {
            $ciudad = $ciudades[0]->name;
        }

        // Extraer ubicación con nombre de campo exacto: "ubicacion-evento"
        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true);
        
        // Extraer fecha con nombre de campo exacto: "fecha"
        $fecha_evento = get_post_meta($post_id, 'fecha', true);

        // Si la fecha tiene formato de timestamp, convertir a formato legible
        if (is_numeric($fecha_evento)) {
            $fecha_evento = date('d/m/Y H:i', $fecha_evento);
        }

        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(100, 100, 100);

        if (!empty($ciudad)) {
            $pdf->MultiCell(0, 5, '📍 ' . $ciudad, 0, 'C');
        }

        if (!empty($ubicacion)) {
            $pdf->MultiCell(0, 5, '📍 ' . htmlspecialchars($ubicacion, ENT_QUOTES, 'UTF-8'), 0, 'C');
        }

        if (!empty($fecha_evento)) {
            $pdf->MultiCell(0, 5, '📅 ' . htmlspecialchars($fecha_evento, ENT_QUOTES, 'UTF-8'), 0, 'C');
        }

        if (!empty($ciudad) || !empty($ubicacion) || !empty($fecha_evento)) {
            $pdf->Ln(2);
        }

        // --- DATOS DEL ASISTENTE ---
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(45, 6, 'EMPRESA:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $nombre_empresa, 0, 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(45, 6, 'NOMBRE:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $nombre_completo, 0, 1);

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(45, 6, 'CARGO:', 0, 0);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $cargo_persona, 0, 1);

        $pdf->Ln(8);

        // --- CÓDIGO QR ---
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 4, 'CÓDIGO DE ESCANEO', 0, 1, 'C');
        $pdf->Ln(2);

        $qr_size = 50;
        $qr_x = (210 - $qr_size) / 2;
        $pdf->Image($qr_path, $qr_x, $pdf->GetY(), $qr_size, $qr_size, 'PNG', '', '', true, 300);

        // --- GENERAR Y GUARDAR PDF ---
        $pdf_filename = 'entrada_' . preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $nombre_completo) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
    }
}
?>
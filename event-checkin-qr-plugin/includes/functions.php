<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 * ✅ Búsqueda mejorada con normalización de texto y múltiples estrategias
 * ✅ Imagen de ALTA CALIDAD y TAMAÑO GRANDE
 * ✅ Logo en cabecera y lugar del evento visible
 * ✅ DISEÑO RESPONSIVE - Imagen más grande con mejor distribución
 * ✅ QR AMPLIADO y datos del asistente a la derecha
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

        // 🧹 ELIMINAR CABECERA Y PIE DE PÁGINA
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // 🧾 Configurar márgenes más amplios
        $pdf->SetMargins(12, 20, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        // --- INSERTAR LOGO EN CABECERA ---
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

        // --- INSERTAR IMAGEN DEL EVENTO ---
        $imagen_insertada = false;

        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_info = optimizar_imagen_para_pdf($imagen_url, $upload_dir);
                $imagen_path = $imagen_info['path'];
                $tmp = $imagen_info['tmp'];

                if (file_exists($imagen_path)) {
                    try {
                        // Imagen más grande y centrada
                        $pdf->Image($imagen_path, 20, 30, 170, '', '', '', 'T', false, 300);
                        $imagen_insertada = true;
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

        // --- POSICIÓN DE CONTENIDO (más aire debajo de imagen) ---
        $pdf->SetY($imagen_insertada ? 130 : 60);

        // --- TÍTULO: "ENTRADA CONFIRMADA" ---
        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 14, 'ENTRADA CONFIRMADA', 0, 1, 'C');
        $pdf->Ln(8);

        // --- NOMBRE DEL EVENTO ---
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->MultiCell(0, 7, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(5);

        // --- UBICACIÓN Y FECHA ---
        $ubicacion = get_post_meta($post_id, 'ubicacion-evento', true);
        $fecha_evento = get_post_meta($post_id, 'fecha', true);
        if (is_numeric($fecha_evento)) {
            $fecha_evento = date('d/m/Y H:i', $fecha_evento);
        }

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(80, 80, 80);

        if (!empty($ubicacion)) {
            $pdf->MultiCell(0, 5, htmlspecialchars($ubicacion, ENT_QUOTES, 'UTF-8'), 0, 'C');
        }
        if (!empty($fecha_evento)) {
            $pdf->MultiCell(0, 5, htmlspecialchars($fecha_evento, ENT_QUOTES, 'UTF-8'), 0, 'C');
        }

        if (!empty($ubicacion) || !empty($fecha_evento)) {
            $pdf->Ln(10);
        }

        // --- DISEÑO DE DOS COLUMNAS: QR A LA IZQUIERDA, DATOS A LA DERECHA ---
        $current_y = $pdf->GetY();
        
        // COLUMNA IZQUIERDA: QR MÁS GRANDE
        $qr_size = 70; // QR más grande
        $qr_x = 15;
        $qr_y = $current_y;
        
        // COLUMNA DERECHA: DATOS DEL ASISTENTE
        $datos_x = 105; // Más a la derecha
        $datos_y = $current_y;
        
        // --- INSERTAR QR GRANDE ---
        $pdf->SetY($qr_y);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY($qr_x, $qr_y - 5);
        $pdf->Cell(70, 4, 'CÓDIGO DE ESCANEO', 0, 1, 'C');
        
        $pdf->Image($qr_path, $qr_x + 5, $qr_y, $qr_size, $qr_size, 'PNG', '', '', true, 300);
        
        // --- DATOS DEL ASISTENTE A LA DERECHA ---
        $pdf->SetXY($datos_x, $datos_y);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'Datos del asistente:', 0, 1, 'L');
        
        $pdf->SetXY($datos_x, $pdf->GetY() + 3);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, "Empresa:\n" . $nombre_empresa, 0, 'L');
        
        $pdf->SetXY($datos_x, $pdf->GetY() + 2);
        $pdf->MultiCell(0, 6, "Nombre:\n" . $nombre_completo, 0, 'L');
        
        $pdf->SetXY($datos_x, $pdf->GetY() + 2);
        $pdf->MultiCell(0, 6, "Cargo:\n" . $cargo_persona, 0, 'L');

        // --- GUARDAR PDF ---
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
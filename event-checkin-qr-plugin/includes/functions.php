<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 * ✅ Búsqueda mejorada con normalización de texto y múltiples estrategias
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
 * Busca el evento usando múltiples estrategias
 */
function buscar_evento_robusto($titulo_buscado) {
    error_log("🔍 === INICIO BÚSQUEDA ROBUSTA DE EVENTO === " . $titulo_buscado);

    $primeras = primeras_palabras($titulo_buscado, 3);
    $ciudades = ['barcelona', 'valencia', 'madrid', 'bilbao', 'san sebastián'];

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
    error_log('EVENTOS encontrados: ' . count($eventos));
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

    error_log('🏙️ Ciudad detectada: ' . ($ciudad_form ?: 'ninguna'));
    error_log('✅ Evento elegido ID: ' . $event_id);
    return $event_id;
}

/**
 * Función principal: genera el PDF con QR + imagen del evento
 */
function generar_qr_pdf_personalizado($request, $action_handler) {
    try {
        // Datos del participante
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';

        // Apellidos: varias fuentes posibles
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

        // Construimos el nombre completo y decodificamos entidades HTML
        $nombre_completo = trim($nombre_persona . ' ' . $apellidos_persona);
        $nombre_completo = html_entity_decode($nombre_completo, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Obtener nombre del evento desde el formulario
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

        // Decodificar título del evento
        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';
        $titulo_a_mostrar = html_entity_decode($titulo_a_mostrar, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // --- GENERACIÓN DE QR ---
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_completo}\nCargo: {$cargo_persona}";
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);
        error_log("🧾 QR generado en: " . $qr_path);

        // --- GENERACIÓN DE PDF ---
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Imagen del evento
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_path = '';
                $imagen_id = get_post_thumbnail_id($post_id);
                $imagen_meta = wp_get_attachment_metadata($imagen_id);
                if ($imagen_meta) {
                    $imagen_path = $upload_dir['basedir'] . '/' . $imagen_meta['file'];
                }

                $tmp = null;
                if (!file_exists($imagen_path)) {
                    if (function_exists('download_url')) {
                        $tmp = download_url($imagen_url);
                        if (!is_wp_error($tmp)) {
                            $imagen_path = $tmp;
                        }
                    }
                }

                if (file_exists($imagen_path)) {
                    try {
                        $pdf->Image($imagen_path, 15, 20, 180, 60);
                        $imagen_insertada = true;
                        error_log("✅ Imagen destacada insertada correctamente");
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen en PDF: " . $e->getMessage());
                    }
                } else {
                    error_log("⚠️ La imagen destacada no se pudo localizar físicamente");
                }

                if ($tmp && !is_wp_error($tmp) && file_exists($tmp)) {
                    @unlink($tmp);
                }
            } else {
                error_log("⚠️ El evento ID={$post_id} no tiene imagen destacada");
            }
        }

        // Contenido del PDF
        $pdf->Ln($imagen_insertada ? 70 : 20);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell(0, 10, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 8, "Nombre: {$nombre_completo}", 0, 1);
        $pdf->Cell(0, 8, "Cargo: {$cargo_persona}", 0, 1);

        $pdf->Ln(10);
        $pdf->Image($qr_path, 70, $pdf->GetY(), 70, 70, 'PNG');

        // Guardar PDF con nombre seguro
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

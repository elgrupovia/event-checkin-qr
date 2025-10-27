<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 * ✅ Búsqueda mejorada (versión dinámica): sin palabras fijas, detecta ciudad y tema automáticamente
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

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

/**
 * Busca el evento usando múltiples estrategias (versión dinámica)
 */
function buscar_evento_robusto($titulo_buscado, $ciudad_slug = null) {
    error_log("🔍 === INICIO BÚSQUEDA ROBUSTA DE EVENTO (DINÁMICA) ===");
    error_log("📝 Título recibido del formulario: '{$titulo_buscado}'");
    if ($ciudad_slug) error_log("🏙️ Ciudad recibida: '{$ciudad_slug}'");

    $titulo_normalizado = normalizar_texto($titulo_buscado);
    error_log("🔤 Título normalizado: '{$titulo_normalizado}'");

    // Filtrado por año (2025) y ciudad si está disponible
    $tax_query = [
        [
            'taxonomy' => 'ano',
            'field'    => 'slug',
            'terms'    => '2025',
        ]
    ];
    if ($ciudad_slug) {
        $tax_query[] = [
            'taxonomy' => 'ciudad',
            'field'    => 'slug',
            'terms'    => $ciudad_slug,
        ];
    }

    $args = [
        'post_type'      => 'eventos',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => $tax_query,
    ];

    $eventos = get_posts($args);
    if (empty($eventos)) {
        error_log("⚠️ No se encontraron eventos del tipo 'eventos'.");
        return null;
    }

    error_log("✅ Se encontraron " . count($eventos) . " eventos publicados");

    // --- ESTRATEGIA 1: Coincidencia exacta ---
    foreach ($eventos as $evento) {
        $titulo_evento = get_the_title($evento->ID);
        $titulo_evento_normalizado = normalizar_texto($titulo_evento);

        if ($titulo_normalizado === $titulo_evento_normalizado) {
            error_log("✅ Coincidencia exacta (normalizada) con ID {$evento->ID}");
            return $evento->ID;
        }

        if (strcasecmp(trim($titulo_buscado), trim($titulo_evento)) === 0) {
            error_log("✅ Coincidencia exacta (sin normalizar) con ID {$evento->ID}");
            return $evento->ID;
        }
    }

    // --- ESTRATEGIA 2: Coincidencia por palabras clave dinámicas ---
    $palabras_clave = array_filter(
        explode(' ', $titulo_normalizado),
        function ($palabra) {
            $stopwords = ['de', 'del', 'la', 'el', 'para', 'en', 'y', 'con', 'por'];
            return strlen($palabra) > 3 && !in_array($palabra, $stopwords);
        }
    );
    error_log("🔑 Palabras clave detectadas: " . implode(', ', $palabras_clave));

    $mejores_coincidencias = [];
    foreach ($eventos as $evento) {
        $titulo_evento_normalizado = normalizar_texto(get_the_title($evento->ID));
        $coincidencias = 0;
        foreach ($palabras_clave as $palabra) {
            if (strpos($titulo_evento_normalizado, $palabra) !== false) {
                $coincidencias++;
            }
        }
        if ($coincidencias > 0) {
            $mejores_coincidencias[$evento->ID] = $coincidencias;
        }
    }

    if (!empty($mejores_coincidencias)) {
        arsort($mejores_coincidencias);
        $mejor_id = array_key_first($mejores_coincidencias);
        $mejor_puntuacion = $mejores_coincidencias[$mejor_id];
        error_log("🎯 Mejor coincidencia por palabras clave: ID={$mejor_id}, {$mejor_puntuacion} coincidencias");
        if ($mejor_puntuacion >= (count($palabras_clave) * 0.5)) {
            error_log("✅ Coincidencia ≥50%, evento seleccionado");
            return $mejor_id;
        }
    }

    // --- ESTRATEGIA 3: Coincidencia por slug ---
    $slug_buscado = sanitize_title($titulo_buscado);
    foreach ($eventos as $evento) {
        if ($evento->post_name === $slug_buscado) {
            error_log("✅ Coincidencia por slug con ID {$evento->ID}");
            return $evento->ID;
        }
    }

    error_log("❌ No se encontró coincidencia válida");
    error_log("🔍 === FIN BÚSQUEDA ROBUSTA (DINÁMICA) ===");
    return null;
}

/**
 * Función principal: genera el PDF con QR + imagen del evento
 */
function generar_qr_pdf_personalizado($request, $action_handler) {
    error_log("🚀 [inscripciones_qr] Hook ejecutado");
    error_log("📥 Datos completos del formulario: " . print_r($request, true));

    try {
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // Obtener nombre del evento y ciudad desde el formulario
        $titulo_evento_formulario = '';
        $ciudad_formulario = isset($request['ciudad_evento']) ? sanitize_title($request['ciudad_evento']) : null;

        if (isset($request['eventos_2025']) && !empty($request['eventos_2025'][0])) {
            $titulo_evento_formulario = trim(sanitize_text_field($request['eventos_2025'][0]));
        }

        $post_id = null;
        $titulo_evento_encontrado = $titulo_evento_formulario;

        if ($titulo_evento_formulario) {
            $post_id = buscar_evento_robusto($titulo_evento_formulario, $ciudad_formulario);

            if ($post_id) {
                $titulo_evento_encontrado = trim(get_the_title($post_id));
                error_log("✅ EVENTO FINAL ENCONTRADO: ID={$post_id}, Título='{$titulo_evento_encontrado}'");
            } else {
                error_log("❌ No se pudo encontrar el evento. La imagen no se insertará.");
            }
        } else {
            error_log("⚠️ No se recibió el nombre del evento en el formulario (campo eventos_2025)");
        }

        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';

        // === GENERACIÓN DEL QR Y PDF ===
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png';
        $qr->saveToFile($qr_path);

        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Imagen del evento (si existe)
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                try {
                    $pdf->Image($imagen_url, 15, 20, 180, 60);
                    $imagen_insertada = true;
                    error_log("✅ Imagen destacada insertada correctamente");
                } catch (Exception $e) {
                    error_log("❌ Error al insertar imagen en PDF: " . $e->getMessage());
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
        $pdf->Cell(0, 8, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 8, "Cargo: {$cargo_persona}", 0, 1);

        $pdf->Ln(10);
        $pdf->Image($qr_path, 70, $pdf->GetY(), 70, 70, 'PNG');

        // Guardar PDF
        $pdf_filename = 'entrada_' . sanitize_file_name($nombre_persona) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

        @unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
    }
}

add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado con búsqueda dinámica) cargado correctamente");

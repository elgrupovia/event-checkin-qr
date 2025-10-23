<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 */

if (!defined('ABSPATH')) {
    exit; // Evita acceso directo
}

require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

// 🔍 Debug: detectar cualquier hook relacionado con inscripciones_qr
add_action('all', function($hook_name) {
    if (strpos($hook_name, 'inscripciones_qr') !== false) {
        error_log("🎯 Se ha detectado el hook: " . $hook_name);
    }
});

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

        // 📌 Título del evento desde JetFormBuilder
        $evento_nombre = isset($request['eventos_2025'][0]) ? sanitize_text_field($request['eventos_2025'][0]) : '';
        error_log("🔎 Nombre del evento recibido desde el formulario: " . $evento_nombre);

        global $wpdb;
        
        // 📋 PRIMERO: Listar TODOS los eventos disponibles para diagnóstico
        error_log("📋 === LISTANDO TODOS LOS EVENTOS DISPONIBLES ===");
        $eventos_disponibles = $wpdb->get_results(
            "SELECT ID, post_title, post_type FROM $wpdb->posts 
             WHERE post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos', 'post', 'page')
             AND post_status = 'publish' 
             ORDER BY post_date DESC
             LIMIT 30"
        );
        
        foreach ($eventos_disponibles as $evento) {
            error_log("   🎪 ID: {$evento->ID} | Tipo: {$evento->post_type} | Título: {$evento->post_title}");
        }
        error_log("📋 === FIN DEL LISTADO ===");

        // 🧹 Limpiar el nombre del evento
        $evento_nombre_limpio = trim(preg_replace('/\s+/', ' ', $evento_nombre));
        $evento_nombre_decoded = html_entity_decode($evento_nombre_limpio);
        error_log("🧹 Nombre limpio: " . $evento_nombre_limpio);
        error_log("🧹 Nombre decoded: " . $evento_nombre_decoded);

        // 🎯 ESTRATEGIA 1: Búsqueda exacta (con y sin HTML entities)
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts 
                 WHERE (TRIM(REPLACE(post_title, '  ', ' ')) = %s 
                    OR TRIM(REPLACE(post_title, '  ', ' ')) = %s)
                 AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                 AND post_status = 'publish' 
                 LIMIT 1",
                $evento_nombre_limpio,
                $evento_nombre_decoded
            )
        );

        if (!$post_id) {
            error_log("⚠️ Intento 1 fallido. Probando búsqueda con UPPER...");
            
            // 🎯 ESTRATEGIA 2: Búsqueda sin distinguir mayúsculas/minúsculas
            $post_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts 
                     WHERE UPPER(TRIM(REPLACE(post_title, '  ', ' '))) = UPPER(%s)
                     AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                     AND post_status = 'publish' 
                     LIMIT 1",
                    $evento_nombre_limpio
                )
            );
        }

        if (!$post_id) {
            error_log("⚠️ Intento 2 fallido. Probando búsqueda parcial estricta...");
            
            // 🎯 ESTRATEGIA 3: Extraer identificador principal del evento
            // Ejemplo: "ECO CONSTRUYE 2025" de "ECO CONSTRUYE 2025 EDIFICACION SOSTENIBLE MADRID..."
            preg_match('/^([A-Z\s]+\d{4})/', $evento_nombre_limpio, $matches);
            
            if (!empty($matches[1])) {
                $identificador = trim($matches[1]);
                error_log("🔑 Identificador extraído: " . $identificador);
                
                $post_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM $wpdb->posts 
                         WHERE UPPER(post_title) LIKE UPPER(%s)
                         AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                         AND post_status = 'publish' 
                         AND UPPER(post_title) LIKE UPPER(%s)
                         ORDER BY post_date DESC
                         LIMIT 1",
                        '%' . $wpdb->esc_like($identificador) . '%',
                        '%MADRID%'
                    )
                );
            }
        }

        if (!$post_id) {
            error_log("⚠️ Intento 3 fallido. Probando búsqueda por fecha en título...");
            
            // 🎯 ESTRATEGIA 4: Buscar por fecha mencionada (26 NOVIEMBRE)
            preg_match('/(\d{1,2})\s+(ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)/i', $evento_nombre_limpio, $fecha_matches);
            
            if (!empty($fecha_matches[0])) {
                $fecha_buscar = $fecha_matches[0];
                error_log("📅 Fecha extraída: " . $fecha_buscar);
                
                $post_id = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM $wpdb->posts 
                         WHERE UPPER(post_title) LIKE UPPER(%s)
                         AND UPPER(post_title) LIKE UPPER(%s)
                         AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                         AND post_status = 'publish' 
                         ORDER BY post_date DESC
                         LIMIT 1",
                        '%' . $wpdb->esc_like($fecha_buscar) . '%',
                        '%ECO%CONSTRUYE%'
                    )
                );
            }
        }

        if ($post_id) {
            $titulo_encontrado = get_the_title($post_id);
            error_log("✅ EVENTO ENCONTRADO: ID={$post_id}");
            error_log("✅ Título del post: {$titulo_encontrado}");
        } else {
            error_log("❌ NO SE ENCONTRÓ NINGÚN EVENTO. Revisa el listado anterior.");
        }

        // 🧾 Generar QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qr = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        $qr_path = $upload_dir['basedir'] . '/qr_' . time() . '.png';
        $qr->saveToFile($qr_path);
        error_log("🧾 QR generado en: " . $qr_path);

        // 📄 Crear PDF
        $pdf = new TCPDF();
        $pdf->AddPage();

        // 🖼️ Imagen del evento
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            error_log("🔗 URL de imagen destacada: " . ($imagen_url ?: 'NO TIENE'));
            
            if ($imagen_url) {
                $imagen_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_url);
                error_log("📂 Ruta física de la imagen: " . $imagen_path);
                
                if (file_exists($imagen_path)) {
                    $pdf->Image($imagen_path, 15, 20, 180, 60);
                    error_log("✅ Imagen insertada correctamente");
                } else {
                    error_log("❌ El archivo de imagen NO existe físicamente");
                }
            } else {
                error_log("⚠️ El post no tiene imagen destacada configurada");
            }
        } else {
            error_log("⚠️ No se puede insertar imagen porque no se encontró el evento");
        }

        $pdf->Ln(70);
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 10, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 10, "Cargo: {$cargo_persona}", 0, 1);
        $pdf->Image($qr_path, 70, 150, 70, 70, 'PNG');

        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

        unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
    }
}

// 🚀 Hook JetFormBuilder — acción personalizada "inscripciones_qr"
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado) cargado correctamente");
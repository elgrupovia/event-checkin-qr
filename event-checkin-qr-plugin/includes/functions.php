<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado y la imagen destacada del evento al ejecutar el hook JetFormBuilder "inscripciones_qr"
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
 * Función que genera el PDF con QR personalizado y la imagen destacada del evento
 */
function generar_qr_pdf_personalizado($request, $action_handler) {
    error_log("🚀 [inscripciones_qr] Hook ejecutado");
    error_log("📥 Datos completos del formulario: " . print_r($request, true));

    try {
        // Datos básicos
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // Buscar evento dinámicamente
        $evento_nombre = isset($request['eventos_2025'][0]) ? sanitize_text_field($request['eventos_2025'][0]) : '';
        error_log("🔎 Nombre del evento recibido desde el formulario: " . $evento_nombre);

        // Intentar buscar el post aunque no sea título exacto
        $args = array(
            'post_type'      => array('evento', 'eventos', 'post', 'page'), // prueba varios tipos
            's'              => $evento_nombre, // búsqueda flexible
            'posts_per_page' => 1,
        );

        $query = new WP_Query($args);
        $post_id = 0;
        if ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            error_log("📌 Evento encontrado: ID={$post_id}, Título=" . get_the_title($post_id));
        } else {
            error_log("⚠️ No se encontró ningún evento que contenga el texto: " . $evento_nombre);
        }
        wp_reset_postdata();

        // Generar QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qrResult = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        $qrPath = $upload_dir['basedir'] . '/qr_' . time() . '.png';
        $qrResult->saveToFile($qrPath);
        error_log("🧾 QR generado en: " . $qrPath);

        // Crear PDF
        $pdf = new TCPDF();
        $pdf->AddPage();

        // Intentar obtener imagen destacada
        if ($post_id) {
            $imagen_evento_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_evento_url) {
                $imagen_evento_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_evento_url);
                error_log("🖼️ URL imagen destacada: " . $imagen_evento_url);
                error_log("📂 Ruta en servidor: " . $imagen_evento_path);

                if (file_exists($imagen_evento_path)) {
                    $pdf->Image($imagen_evento_path, 15, 20, 180, 60);
                    error_log("✅ Imagen añadida al PDF correctamente.");
                } else {
                    error_log("⚠️ No se encontró el archivo en el servidor: " . $imagen_evento_path);
                }
            } else {
                error_log("⚠️ No hay imagen destacada para el evento con ID {$post_id}");
            }
        } else {
            error_log("⚠️ No se pudo obtener el ID del evento, no se añadirá imagen.");
        }

        // Texto del PDF
        $pdf->Ln(70);
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 10, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 10, "Cargo: {$cargo_persona}", 0, 1);
        $pdf->Image($qrPath, 70, 150, 70, 70, 'PNG');

        // Guardar PDF
        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdfPath = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdfPath, 'F');
        unlink($qrPath);

        error_log("✅ PDF generado correctamente en: " . $pdfPath);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
    }
}


// Hook JetFormBuilder — acción personalizada "inscripciones_qr"
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado) cargado correctamente");

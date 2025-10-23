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

    // Log de todos los datos del formulario
    error_log("📥 Datos completos del formulario: " . print_r($request, true));

    try {
        // 1️⃣ Obtener datos del formulario
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';
        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // 2️⃣ Buscar evento por el título recibido desde el formulario
        $evento_nombre = isset($request['eventos_2025'][0]) ? sanitize_text_field($request['eventos_2025'][0]) : '';
        error_log("🔎 Nombre del evento recibido desde el formulario: " . $evento_nombre);

        // 👇 Asegúrate de que este sea el slug correcto de tu CPT de eventos
        $args = array(
            'post_type'      => 'evento', // 🔥 cambia esto si tu CPT se llama diferente
            'posts_per_page' => 1,
            's'              => $evento_nombre, // búsqueda parcial por título
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $query = new WP_Query($args);
        $post_id = 0;

        if ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $titulo_encontrado = get_the_title($post_id);
            error_log("📌 Evento encontrado: ID={$post_id}, Título={$titulo_encontrado}");
        } else {
            error_log("⚠️ No se encontró ningún evento que contenga el texto: " . $evento_nombre);
        }
        wp_reset_postdata();

        // 3️⃣ Generar el código QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qrResult = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        $qrPath = $upload_dir['basedir'] . '/qr_' . time() . '.png';
        $qrResult->saveToFile($qrPath);
        error_log("🧾 QR generado en: " . $qrPath);

        // 4️⃣ Crear el PDF con TCPDF
        $pdf = new TCPDF();
        $pdf->AddPage();

        // 5️⃣ Insertar imagen del evento si existe
        if ($post_id) {
            $imagen_id = get_post_thumbnail_id($post_id);
            if ($imagen_id) {
                $imagen_url = wp_get_attachment_url($imagen_id);
                if ($imagen_url) {
                    $imagen_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_url);
                    if (file_exists($imagen_path)) {
                        $pdf->Image($imagen_path, 15, 20, 180, 60);
                        error_log("🖼️ Imagen del evento insertada: " . $imagen_path);
                    } else {
                        error_log("⚠️ La imagen destacada no se encuentra en: " . $imagen_path);
                    }
                } else {
                    error_log("⚠️ wp_get_attachment_url devolvió vacío para la imagen del evento.");
                }
            } else {
                error_log("⚠️ No hay imagen destacada para el evento con ID {$post_id}");
            }
        } else {
            error_log("⚠️ No se encontró evento asociado al título recibido.");
        }

        // 6️⃣ Agregar texto al PDF
        $pdf->Ln(70);
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 10, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 10, "Cargo: {$cargo_persona}", 0, 1);

        // 7️⃣ Insertar QR
        $pdf->Image($qrPath, 70, 150, 70, 70, 'PNG');

        // 8️⃣ Guardar el PDF final
        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdfPath = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdfPath, 'F');

        // 9️⃣ Eliminar el QR temporal
        if (file_exists($qrPath)) {
            unlink($qrPath);
        }

        error_log("✅ PDF generado correctamente en: " . $pdfPath);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
    }
}

// 🚀 Hook JetFormBuilder — acción personalizada "inscripciones_qr"
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado) cargado correctamente");

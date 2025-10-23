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

    // Log de todos los datos que llegan del formulario
    error_log("📥 Datos del formulario: " . print_r($request, true));

    try {
        // 1️⃣ Datos principales del formulario
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // 2️⃣ Buscar el evento dinámicamente por el título enviado en el formulario
        $evento_nombre = isset($request['eventos_2025'][0]) ? sanitize_text_field($request['eventos_2025'][0]) : '';
        error_log("🔎 Buscando evento con título: " . $evento_nombre);

        $args = array(
            'post_type'      => 'evento', // tu CPT de eventos
            's'              => $evento_nombre, // usa 's' para búsqueda por texto parcial
            'posts_per_page' => 1,
        );

        $consulta = new WP_Query($args);
        $post_id = 0;
        $imagen_evento_url = '';

        if ($consulta->have_posts()) {
            $consulta->the_post();
            $post_id = get_the_ID();
            error_log("📌 Evento encontrado con ID: " . $post_id);

            // 3️⃣ Obtener imagen destacada del evento (featured image)
            $attachment_id = get_post_thumbnail_id($post_id);

            if ($attachment_id) {
                $imagen_evento_url = wp_get_attachment_url($attachment_id);
                error_log("🖼️ Imagen destacada encontrada: " . $imagen_evento_url);
            } else {
                error_log("⚠️ El evento no tiene imagen destacada.");
            }

            wp_reset_postdata();
        } else {
            error_log("⚠️ No se encontró ningún evento que coincida con el nombre: " . $evento_nombre);
        }

        // 4️⃣ Generar el QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qrResult = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        // 5️⃣ Guardar QR temporal
        $upload_dir = wp_upload_dir();
        $qrPath = $upload_dir['basedir'] . '/qr_' . time() . '.png';
        $qrResult->saveToFile($qrPath);

        // 6️⃣ Crear PDF con TCPDF
        $pdf = new TCPDF();
        $pdf->AddPage();

        // 7️⃣ Agregar imagen del evento si existe
        if (!empty($imagen_evento_url)) {
            $imagen_evento_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_evento_url);
            if (file_exists($imagen_evento_path)) {
                $pdf->Image($imagen_evento_path, 15, 20, 180, 60);
            } else {
                error_log("⚠️ No se encontró la imagen en la ruta local: " . $imagen_evento_path);
            }
        }

        // 8️⃣ Agregar texto al PDF
        $pdf->Ln(70);
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 10, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 10, "Cargo: {$cargo_persona}", 0, 1);

        // 9️⃣ Insertar QR
        $pdf->Image($qrPath, 70, 150, 70, 70, 'PNG');

        // 🔟 Guardar PDF final
        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdfPath = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdfPath, 'F');

        // 🔁 Limpiar archivo QR temporal
        unlink($qrPath);

        error_log("✅ PDF generado correctamente en: " . $pdfPath);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
    }
}

// Hook JetFormBuilder — acción personalizada "inscripciones_qr"
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado) cargado correctamente");

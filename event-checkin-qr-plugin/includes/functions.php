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

// 🔍 Debug: detecta cualquier hook relacionado con inscripciones_qr
add_action('all', function($hook_name) {
    if (strpos($hook_name, 'inscripciones_qr') !== false) {
        error_log("🎯 Se ha detectado el hook: " . $hook_name);
    }
});

/**
 * Función que genera el PDF con QR personalizado y la imagen del evento desde custom field
 */
function generar_qr_pdf_personalizado($request, $action_handler) {
    error_log("🚀 [inscripciones_qr] Hook ejecutado");

    // 🔹 Log de todos los datos recibidos del formulario
    error_log("📌 Datos completos del formulario: " . print_r($request, true));

    try {
        // 1️⃣ Obtener datos del formulario
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

        // 🔹 Detectar post_id dinámico del evento (reemplazar 'evento_id' por el nombre correcto del campo)
        $post_id = isset($request['evento_id']) ? intval($request['evento_id']) : 0;
        error_log("📌 Post ID del evento detectado: " . $post_id);

        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // 2️⃣ Generar el QR con esos datos
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qrResult = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        // 3️⃣ Guardar QR temporal
        $upload_dir = wp_upload_dir();
        $qrPath = $upload_dir['basedir'] . '/qr_' . time() . '.png';
        $qrResult->saveToFile($qrPath);

        // 4️⃣ Crear PDF con TCPDF
        $pdf = new TCPDF();
        $pdf->AddPage();

        // 🔹 Obtener imagen del evento desde custom field
        $imagen_evento_url = $post_id ? get_post_meta($post_id, 'imagen_evento', true) : '';
        if ($imagen_evento_url) {
            // Convertir URL de uploads a ruta absoluta
            $imagen_evento_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_evento_url);
            $pdf->Image($imagen_evento_path, 15, 20, 180, 60);
        }

        // 4b️⃣ Agregar texto del PDF
        $pdf->Ln(70); // dejar espacio debajo de la imagen
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 10, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 10, "Cargo: {$cargo_persona}", 0, 1);

        // 4c️⃣ Insertar QR
        $pdf->Image($qrPath, 70, 150, 70, 70, 'PNG');

        // 5️⃣ Guardar PDF final
        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdfPath = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdfPath, 'F');

        // 6️⃣ Limpiar archivo QR temporal
        unlink($qrPath);

        error_log("✅ PDF generado correctamente en: " . $pdfPath);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
    }
}

// Hook JetFormBuilder — acción personalizada "inscripciones_qr"
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 2);

// Confirmación de carga del archivo functions.php
error_log("✅ functions.php (QR personalizado) cargado correctamente");

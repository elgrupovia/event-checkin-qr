<?php
/**
 * Plugin: Event Check-In QR
 * Integración con JetFormBuilder
 */

if (!defined('ABSPATH')) exit;

// Cargar dependencias de Composer
require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * Hook JetFormBuilder: genera QR y PDF personalizados al enviar formulario
 */
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

function generar_qr_pdf_personalizado($request, $action_handler, $action = null) {
    try {
        // 🔹 1️⃣ Extraer los campos del formulario JetFormBuilder
        // Asegúrate de usar los names reales de tus campos del form
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre_persona']) ? sanitize_text_field($request['nombre_persona']) : 'Usuario';
        $cargo          = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo';

        // 🔹 2️⃣ Crear el contenido del QR
        $qr_text = "Empresa: $nombre_empresa\nNombre: $nombre_persona\nCargo: $cargo";

        // Generar el QR
        $qrCode = QrCode::create($qr_text)->setSize(300)->setMargin(10);
        $writer = new PngWriter();
        $qrResult = $writer->write($qrCode);

        // Guardar QR temporal
        $qrPath = __DIR__ . '/../qr_temp.png';
        $qrResult->saveToFile($qrPath);

        // 🔹 3️⃣ Crear el PDF personalizado
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, '🎟️ Check-In de Evento', 0, 1, 'C');
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: $nombre_empresa", 0, 1);
        $pdf->Cell(0, 10, "Nombre: $nombre_persona", 0, 1);
        $pdf->Cell(0, 10, "Cargo: $cargo", 0, 1);
        $pdf->Ln(10);
        $pdf->Image($qrPath, 70, 100, 70, 70, 'PNG');

        // 🔹 4️⃣ Guardar PDF
        $upload_dir = wp_upload_dir();
        $pdf_filename = 'checkin_' . sanitize_title($nombre_persona) . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf_url  = $upload_dir['baseurl'] . '/' . $pdf_filename;

        $pdf->Output($pdf_path, 'F');

        // Borrar QR temporal
        unlink($qrPath);

        // 🔹 5️⃣ (Opcional) Registrar mensaje de éxito o redirigir
        error_log("✅ PDF generado: $pdf_path");

        // 🔹 6️⃣ (Opcional) Devolver resultado a JetFormBuilder
        if (method_exists($action_handler, 'add_message')) {
            $action_handler->add_message('PDF generado correctamente: ' . $pdf_url);
        }

    } catch (Exception $e) {
        error_log('❌ Error al generar QR/PDF: ' . $e->getMessage());
        if (method_exists($action_handler, 'add_error')) {
            $action_handler->add_error('Error al generar el PDF con QR: ' . $e->getMessage());
        }
    }
}

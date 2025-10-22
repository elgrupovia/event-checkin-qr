<?php
if (!defined('ABSPATH')) exit;

// 🔹 Cargar dependencias de Composer (intenta buscar el autoload real)
$autoload_path = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
} else {
    error_log("❌ No se encontró autoload en: $autoload_path");
    return;
}

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

// Hook JetFormBuilder
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

function generar_qr_pdf_personalizado($request, $action_handler, $action = null) {
    error_log("🚀 [inscripciones_qr] Hook ejecutado");

    try {
        // Datos del formulario
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa desconocida';
        $nombre_persona = isset($request['nombre_persona']) ? sanitize_text_field($request['nombre_persona']) : 'Usuario';
        $cargo          = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo';

        error_log("📦 Datos recibidos: Empresa=$nombre_empresa, Persona=$nombre_persona, Cargo=$cargo");

        // Generar QR
        $qr_text = "Empresa: $nombre_empresa\nNombre: $nombre_persona\nCargo: $cargo";
        $qrCode = QrCode::create($qr_text)->setSize(300)->setMargin(10);
        $writer = new PngWriter();
        $qrResult = $writer->write($qrCode);

        $qrPath = __DIR__ . '/../qr_temp.png';
        $qrResult->saveToFile($qrPath);
        error_log("✅ QR guardado temporalmente en: $qrPath");

        // Crear PDF
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, '🎟️ Check-In de Evento', 0, 1, 'C');
        $pdf->Ln(10);
        $pdf->Cell(0, 10, "Empresa: $nombre_empresa", 0, 1);
        $pdf->Cell(0, 10, "Nombre: $nombre_persona", 0, 1);
        $pdf->Cell(0, 10, "Cargo: $cargo", 0, 1);
        $pdf->Ln(10);
        $pdf->Image($qrPath, 70, 100, 70, 70, 'PNG');

        // Guardar PDF en uploads
        $upload_dir = wp_upload_dir();
        $pdf_filename = 'checkin_' . sanitize_title($nombre_persona) . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf_url  = $upload_dir['baseurl'] . '/' . $pdf_filename;

        error_log("📝 Intentando guardar PDF en: $pdf_path");

        $pdf->Output($pdf_path, 'F');
        unlink($qrPath);

        error_log("✅ PDF generado correctamente: $pdf_url");

        if (method_exists($action_handler, 'add_message')) {
            $action_handler->add_message('✅ PDF generado correctamente: ' . $pdf_url);
        }

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
        if (method_exists($action_handler, 'add_error')) {
            $action_handler->add_error('Error al generar PDF: ' . $e->getMessage());
        }
    }
}

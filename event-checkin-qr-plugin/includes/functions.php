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

/**
 * Función que genera el PDF con QR personalizado
 */
function generar_qr_pdf_personalizado($result, $request, $action_handler) {
    error_log("🚀 [inscripciones_qr] Hook ejecutado");

    try {
        // ✅ 1️⃣ Obtener datos del formulario
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // ✅ 2️⃣ Generar el QR con esos datos
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qrResult = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        // ✅ 3️⃣ Guardar QR temporal
        $upload_dir = wp_upload_dir();
        $qrPath = $upload_dir['basedir'] . '/qr_' . time() . '.png';
        $qrResult->saveToFile($qrPath);

        // ✅ 4️⃣ Crear PDF con TCPDF
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 10, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 10, "Cargo: {$cargo_persona}", 0, 1);

        $pdf->Ln(10);
        $pdf->Image($qrPath, 70, 90, 70, 70, 'PNG');

        // ✅ 5️⃣ Guardar el PDF en la carpeta de uploads
        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdfPath = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdfPath, 'F');

        // ✅ 6️⃣ Limpiar archivo QR temporal
        unlink($qrPath);

        // ✅ 7️⃣ Registrar en logs
        error_log("✅ PDF generado correctamente en: " . $pdfPath);

        // ✅ 8️⃣ (Opcional) Podrías guardar la URL en un campo meta o enviarla por email
        // $pdf_url = $upload_dir['baseurl'] . '/' . $pdf_filename;

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
    }
}

// ✅ Hook JetFormBuilder — acción personalizada "inscripciones_qr"
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

// ✅ Log para confirmar carga del archivo
error_log("✅ functions.php (QR personalizado) cargado correctamente");

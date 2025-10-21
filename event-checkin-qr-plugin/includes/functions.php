<?php
/**
 * functions.php del plugin Event Check-In QR
 * Código para generar QR y PDF dentro de WordPress
 */

if (!defined('ABSPATH')) {
    exit; // Evita acceso directo
}

// ✅ Incluir autoload de Composer
require_once __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

/**
 * Función principal para generar PDF con QR
 */
function generar_qr_pdf() {
    try {
        // 1️⃣ Generar el QR
        $qrResult = Builder::create()
            ->writer(new PngWriter())
            ->data('Asistencia evento - Usuario: Test')
            ->size(300)
            ->margin(10)
            ->build();

        // Guardar QR temporal
        $qrPath = __DIR__ . '/../qr_test.png';
        $qrResult->saveToFile($qrPath);

        // 2️⃣ Crear PDF con TCPDF
        $pdf = new TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 16);
        $pdf->Cell(0, 10, '¡Hola! Este es un PDF con QR para check-in.', 0, 1, 'C');

        // Insertar QR en PDF
        $pdf->Image($qrPath, 70, 40, 70, 70, 'PNG');

        // Guardar PDF final
        $pdfPath = __DIR__ . '/../pdf_test.pdf';
        $pdf->Output($pdfPath, 'F');

        // Limpiar archivo temporal
        unlink($qrPath);

        echo '<div class="notice notice-success"><p>✅ PDF generado correctamente: pdf_test.pdf</p></div>';

    } catch (Exception $e) {
        echo '<div class="notice notice-error"><p>❌ Error: ' . $e->getMessage() . '</p></div>';
    }
}

/**
 * Hook para añadir página de administración
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Generar QR/PDF',       // Título página
        'QR/PDF',               // Título menú
        'manage_options',       // Permiso
        'qr_pdf_page',          // Slug
        function() {            // Callback contenido
            echo '<div class="wrap"><h1>Generar PDF con QR</h1>';
            if (isset($_POST['generar'])) {
                generar_qr_pdf();
            }
            echo '<form method="post"><button name="generar" class="button button-primary">Generar PDF</button></form></div>';
        }
    );
});

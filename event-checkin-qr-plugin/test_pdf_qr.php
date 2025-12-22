<?php
require __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\PngWriter;
use TCPDF;

// Generar QR
$qrResult = Builder::create()
    ->writer(new PngWriter())
    ->data('Asistente de prueba')
    ->encoding(new Encoding('UTF-8'))
    ->size(300)
    ->build();

// Guardar QR como imagen
file_put_contents('qr.png', $qrResult->getString());

// Crear PDF e insertar QR
$pdf = new TCPDF();
$pdf->AddPage();
$pdf->Image('qr.png', 50, 50, 100, 100, 'PNG');
$pdf->Output('pdf_test.pdf', 'F');

echo "PDF con QR generado ✅\n";

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

        // 🔹 Obtener ID del evento directamente
        $post_id = null;
        if (isset($request['eventos_2025']) && !empty($request['eventos_2025'][0])) {
            $post_id = intval($request['eventos_2025'][0]);
        }

        // 🎯 Verificar si el post existe y está publicado
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                error_log("❌ El ID {$post_id} no corresponde a un post válido o no está publicado");
                $post_id = null;
            } else {
                error_log("✅ EVENTO ENCONTRADO:");
                error_log("   • ID: {$post_id}");
                error_log("   • Título: " . get_the_title($post_id));
                error_log("   • Tipo: " . $post->post_type);
                error_log("   • Estado: " . $post->post_status);
            }
        } else {
            error_log("❌ No se proporcionó ID de evento válido");
        }

        // 🧾 Generar QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qr = Builder::create()
            ->writer(new PngWriter())
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
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            error_log("🖼️ URL de imagen destacada: " . ($imagen_url ?: 'NO TIENE'));

            if ($imagen_url) {
                $imagen_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_url);
                error_log("📂 Ruta física de imagen: " . $imagen_path);
                error_log("📂 ¿Existe el archivo? " . (file_exists($imagen_path) ? 'SÍ' : 'NO'));

                if (file_exists($imagen_path)) {
                    try {
                        $pdf->Image($imagen_path, 15, 20, 180, 60);
                        $imagen_insertada = true;
                        error_log("✅ Imagen del evento insertada correctamente");
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen: " . $e->getMessage());
                    }
                }
            }
        }

        // 📝 Contenido del PDF
        $pdf->Ln($imagen_insertada ? 70 : 20);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->Ln(5);

        if ($post_id) {
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->MultiCell(0, 10, get_the_title($post_id), 0, 'C');
            $pdf->Ln(5);
        }

        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 8, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 8, "Cargo: {$cargo_persona}", 0, 1);

        $pdf->Ln(10);
        $pdf->Image($qr_path, 70, $pdf->GetY(), 70, 70, 'PNG');

        // 💾 Guardar PDF
        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

        // 🧹 Limpiar archivo temporal
        @unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
    }
}

// 🚀 Hook JetFormBuilder
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado) cargado correctamente");

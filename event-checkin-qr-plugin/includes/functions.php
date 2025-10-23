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
 */function generar_qr_pdf_personalizado($request, $action_handler) {
    error_log("🚀 [inscripciones_qr] Hook ejecutado");
    error_log("📥 Datos completos del formulario: " . print_r($request, true));

    try {
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';
        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // 📌 Título del evento desde JetFormBuilder
        $evento_nombre = isset($request['eventos_2025'][0]) ? sanitize_text_field($request['eventos_2025'][0]) : '';
        error_log("🔎 Nombre del evento recibido desde el formulario: " . $evento_nombre);

        // 🧠 Buscar el evento por título exacto (no solo coincidencia parcial)
        global $wpdb;
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts 
                 WHERE post_title = %s 
                 AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                 AND post_status = 'publish' 
                 LIMIT 1",
                $evento_nombre
            )
        );

        if (!$post_id) {
            error_log("⚠️ No se encontró ningún evento con título EXACTO: {$evento_nombre}");
            // Intento secundario: buscar parcialmente
            $post_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts 
                     WHERE post_title LIKE %s 
                     AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                     AND post_status = 'publish' 
                     LIMIT 1",
                    '%' . $wpdb->esc_like($evento_nombre) . '%'
                )
            );
        }

        if ($post_id) {
            error_log("📌 Evento correcto encontrado: ID={$post_id}, Título=" . get_the_title($post_id));
        } else {
            error_log("❌ No se encontró ningún evento ni por coincidencia parcial ni exacta.");
        }

        // 🧾 Generar QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qr = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
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
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_url);
                if (file_exists($imagen_path)) {
                    $pdf->Image($imagen_path, 15, 20, 180, 60);
                    error_log("🖼️ Imagen destacada insertada desde: " . $imagen_path);
                } else {
                    error_log("⚠️ No se encontró físicamente la imagen en: " . $imagen_path);
                }
            } else {
                error_log("⚠️ El evento con ID {$post_id} no tiene imagen destacada");
            }
        }

        $pdf->Ln(70);
        $pdf->SetFont('helvetica', '', 14);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 10, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 10, "Cargo: {$cargo_persona}", 0, 1);
        $pdf->Image($qr_path, 70, 150, 70, 70, 'PNG');

        $pdf_filename = 'entrada_qr_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

        unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
    }
}

// 🚀 Hook JetFormBuilder — acción personalizada "inscripciones_qr"
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado) cargado correctamente");

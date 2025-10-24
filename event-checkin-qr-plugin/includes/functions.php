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
        // ▪ Datos del participante
        $nombre_empresa = isset($request['nombre_de_empresa']) ? sanitize_text_field($request['nombre_de_empresa']) : 'Empresa Desconocida';
        $nombre_persona = isset($request['nombre']) ? sanitize_text_field($request['nombre']) : 'Invitado';
        $cargo_persona  = isset($request['cargo']) ? sanitize_text_field($request['cargo']) : 'Cargo no especificado';

        error_log("📦 Datos recibidos: Empresa={$nombre_empresa}, Nombre={$nombre_persona}, Cargo={$cargo_persona}");

        // ▪ Obtener nombre del evento desde el formulario
        $titulo_evento = '';
        if (isset($request['eventos_2025']) && !empty($request['eventos_2025'][0])) {
            $titulo_evento = sanitize_text_field($request['eventos_2025'][0]);
        }

        error_log("🔍 Buscando evento con título exacto '{$titulo_evento}' dentro del post type 'eventos_2025'");

        $post_id = null;

        if ($titulo_evento) {
            // Buscar evento por título exacto en el CPT "eventos_2025"
            $eventos = get_posts([
                'post_type'      => 'eventos_2025',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);

            foreach ($eventos as $id_evento) {
                $titulo_post = get_the_title($id_evento);
                if (strcasecmp(trim($titulo_post), trim($titulo_evento)) === 0) {
                    $post_id = $id_evento;
                    error_log("✅ Coincidencia exacta encontrada: ID={$post_id}, Título={$titulo_post}");
                    break;
                }
            }

            if (!$post_id) {
                error_log("❌ No se encontró ningún evento con el título exacto '{$titulo_evento}' en 'eventos_2025'");
            }
        } else {
            error_log("⚠️ No se recibió el nombre del evento en el formulario (campo eventos_2025)");
        }

        // ▪ Generar QR
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

        // ▪ Crear PDF
        $pdf = new TCPDF();
        $pdf->AddPage();

        // ▪ Imagen del evento (si se encontró)
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                $imagen_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $imagen_url);
                if (!file_exists($imagen_path)) {
                    // Si no existe localmente, intentar descargarla
                    $tmp = download_url($imagen_url);
                    if (!is_wp_error($tmp)) {
                        $imagen_path = $tmp;
                    }
                }

                if (file_exists($imagen_path)) {
                    try {
                        $pdf->Image($imagen_path, 15, 20, 180, 60);
                        $imagen_insertada = true;
                        error_log("✅ Imagen destacada insertada correctamente");
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen: " . $e->getMessage());
                    }
                } else {
                    error_log("⚠️ La imagen destacada no se pudo localizar físicamente");
                }
            } else {
                error_log("⚠️ El evento no tiene imagen destacada");
            }
        }

        // ▪ Contenido del PDF
        $pdf->Ln($imagen_insertada ? 70 : 20);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 14);
        $titulo_a_mostrar = $titulo_evento ?: 'Evento no identificado';
        $pdf->MultiCell(0, 10, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 8, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 8, "Cargo: {$cargo_persona}", 0, 1);

        $pdf->Ln(10);
        $pdf->Image($qr_path, 70, $pdf->GetY(), 70, 70, 'PNG');

        // ▪ Guardar PDF
        $pdf_filename = 'entrada_' . sanitize_file_name($nombre_persona) . '_' . time() . '.pdf';
        $pdf_path = $upload_dir['basedir'] . '/' . $pdf_filename;
        $pdf->Output($pdf_path, 'F');
        error_log("✅ PDF generado correctamente en: " . $pdf_path);

        // ▪ Limpiar QR temporal
        @unlink($qr_path);

    } catch (Exception $e) {
        error_log("❌ Error al generar PDF: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
    }
}

// 🚀 Hook JetFormBuilder
add_action('jet-form-builder/custom-action/inscripciones_qr', 'generar_qr_pdf_personalizado', 10, 3);

error_log("✅ functions.php (QR personalizado) cargado correctamente");

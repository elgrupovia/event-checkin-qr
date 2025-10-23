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

        // 🔍 OBTENER EL ID DEL EVENTO desde eventos_2025
        $post_id = null;

        if (isset($request['eventos_2025'])) {
            $eventos_data = $request['eventos_2025'];
            $evento_value = is_array($eventos_data) ? ($eventos_data[0] ?? null) : $eventos_data;

            error_log("🔎 Contenido de eventos_2025: " . print_r($eventos_data, true));
            error_log("🔎 Valor procesado: " . $evento_value);

            if (is_numeric($evento_value)) {
                // Si el formulario ya devuelve ID
                $post_id = intval($evento_value);
                error_log("✅ Se recibió ID numérico: {$post_id}");
            } elseif (!empty($evento_value)) {
                // Si devuelve título, buscar post
                global $wpdb;
                $evento_titulo = sanitize_text_field($evento_value);
                error_log("🔍 Buscando post por título: {$evento_titulo}");

                // 1️⃣ Coincidencia exacta (sin importar mayúsculas/minúsculas o guiones)
                $post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts 
                     WHERE REPLACE(LOWER(TRIM(post_title)), '–', '-') = REPLACE(LOWER(TRIM(%s)), '–', '-')
                     AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                     AND post_status = 'publish' 
                     LIMIT 1",
                    $evento_titulo
                ));

                // 2️⃣ Coincidencia parcial inicial (primeras 3 palabras importantes)
                if (!$post_id) {
                    $palabras = explode(' ', $evento_titulo);
                    $palabras_filtradas = array_filter($palabras, function($p) { return strlen($p) > 4 && !is_numeric($p); });

                    if (count($palabras_filtradas) >= 3) {
                        $like = '%' . $wpdb->esc_like(implode(' ', array_slice($palabras_filtradas, 0, 3))) . '%';
                        error_log("🔑 Búsqueda parcial (frase inicial): {$like}");
                        $post_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT ID FROM $wpdb->posts 
                             WHERE LOWER(post_title) LIKE LOWER(%s)
                             AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                             AND post_status = 'publish'
                             ORDER BY post_date DESC
                             LIMIT 1",
                            $like
                        ));
                    }
                }

                // 3️⃣ Último recurso: búsqueda por palabras clave
                if (!$post_id) {
                    $palabras_importantes = array_filter($palabras, function($p) { return strlen($p) > 3 && !is_numeric($p); });
                    if (count($palabras_importantes) >= 2) {
                        $primera = array_values($palabras_importantes)[0];
                        $segunda = array_values($palabras_importantes)[1];
                        error_log("🔑 Último intento (palabras clave): {$primera}, {$segunda}");
                        $post_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT ID FROM $wpdb->posts 
                             WHERE LOWER(post_title) LIKE LOWER(%s)
                             AND LOWER(post_title) LIKE LOWER(%s)
                             AND post_type IN ('evento', 'eventos', 'via_evento', 'via_eventos')
                             AND post_status = 'publish'
                             ORDER BY post_date DESC
                             LIMIT 1",
                            '%' . $wpdb->esc_like($primera) . '%',
                            '%' . $wpdb->esc_like($segunda) . '%'
                        ));
                    }
                }
            }
        }

        // 🎯 VERIFICAR SI ENCONTRAMOS EL EVENTO
        if ($post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                error_log("✅ EVENTO ENCONTRADO:");
                error_log("   • ID: {$post_id}");
                error_log("   • Título: " . get_the_title($post_id));
                error_log("   • Tipo: " . $post->post_type);
                error_log("   • Estado: " . $post->post_status);
            } else {
                error_log("❌ El ID {$post_id} no corresponde a un post válido o no está publicado");
                $post_id = null;
            }
        } else {
            error_log("❌ No se pudo determinar el ID del evento");
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

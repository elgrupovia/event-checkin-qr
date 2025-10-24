<?php
/**
 * functions.php — Plugin Event Check-In QR
 * Genera un PDF con código QR personalizado al ejecutar el hook JetFormBuilder "inscripciones_qr"
 */

if (!defined('ABSPATH')) {
    exit; // Evita acceso directo
}

// Asegúrate de que los archivos de librerías se carguen correctamente
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
        $titulo_evento_formulario = '';
        if (isset($request['eventos_2025']) && !empty($request['eventos_2025'][0])) {
            // Limpia y normaliza el título recibido
            $titulo_evento_formulario = trim(sanitize_text_field($request['eventos_2025'][0]));
        }

        error_log("🔍 Buscando evento con título exacto '{$titulo_evento_formulario}' dentro del post type 'eventos_2025'");

        $post_id = null;
        $titulo_evento_encontrado = $titulo_evento_formulario; // Usar el título del formulario por defecto

        if ($titulo_evento_formulario) {
            // Buscar evento por título exacto en el CPT "eventos_2025"
            // Se realiza una consulta a la base de datos para obtener todos los IDs del CPT
            $eventos = get_posts([
                'post_type'      => 'eventos_2025',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);

            // ▪ Búsqueda Exhaustiva y Exacta: Recorre todos los posts de 'eventos_2025'
            foreach ($eventos as $id_evento) {
                $titulo_post = trim(get_the_title($id_evento));
                
                // Comparación de cadenas sin distinción entre mayúsculas y minúsculas
                if (strcasecmp($titulo_post, $titulo_evento_formulario) === 0) {
                    $post_id = $id_evento;
                    $titulo_evento_encontrado = $titulo_post; // Usamos el título del post encontrado
                    error_log("✅ Coincidencia exacta encontrada: ID={$post_id}, Título={$titulo_post}");
                    break;
                }
            }

            if (!$post_id) {
                // Si la búsqueda exacta falla, puedes implementar aquí una lógica de "búsqueda parcial"
                // usando 's' => $titulo_evento_formulario en get_posts y luego refinando.
                // Sin embargo, para títulos de JetFormBuilder, la coincidencia exacta es la esperada.
                error_log("❌ NO se encontró NINGÚN evento con el título exacto '{$titulo_evento_formulario}' en 'eventos_2025'. Revise el título del post.");
            }
        } else {
            error_log("⚠️ No se recibió el nombre del evento en el formulario (campo eventos_2025)");
        }
        
        // El título a mostrar en el PDF será el del post si se encontró, o el del formulario/default si no.
        $titulo_a_mostrar = $titulo_evento_encontrado ?: 'Evento no identificado';


        // --- RESTO DE LA LÓGICA DE GENERACIÓN DE PDF ---

        // ▪ Generar QR
        $data = "Empresa: {$nombre_empresa}\nNombre: {$nombre_persona}\nCargo: {$cargo_persona}";
        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($data)
            ->size(300)
            ->margin(10)
            ->build();

        $upload_dir = wp_upload_dir();
        // Usamos un nombre más simple y seguro para el QR temporal
        $qr_path = $upload_dir['basedir'] . '/temp_qr_' . uniqid() . '.png'; 
        $qr->saveToFile($qr_path);
        error_log("🧾 QR generado en: " . $qr_path);

        // ▪ Crear PDF
        $pdf = new TCPDF();
        $pdf->AddPage();
        
        // Configuración básica (si quieres evitar warnings)
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // ▪ Imagen del evento (si se encontró)
        $imagen_insertada = false;
        if ($post_id) {
            $imagen_url = get_the_post_thumbnail_url($post_id, 'full');
            if ($imagen_url) {
                // Intenta obtener la ruta local de la imagen
                $imagen_id = get_post_thumbnail_id($post_id);
                $imagen_meta = wp_get_attachment_metadata($imagen_id);
                $imagen_path = $upload_dir['basedir'] . '/' . $imagen_meta['file'];
                
                if (!file_exists($imagen_path)) {
                    // Si no existe localmente o la ruta es incorrecta (p. ej. imagen externa o ruta compleja)
                    // Intenta descargar la URL como respaldo, si tienes la función download_url
                    if (function_exists('download_url')) {
                        $tmp = download_url($imagen_url);
                        if (!is_wp_error($tmp)) {
                            $imagen_path = $tmp;
                        }
                    }
                }

                if (file_exists($imagen_path)) {
                    try {
                        // Insertar imagen: x, y, ancho, alto
                        // Ajusta las dimensiones según tu diseño (180mm ancho, 60mm alto es un ejemplo)
                        $pdf->Image($imagen_path, 15, 20, 180, 60); 
                        $imagen_insertada = true;
                        error_log("✅ Imagen destacada insertada correctamente: " . $imagen_path);
                    } catch (Exception $e) {
                        error_log("❌ Error al insertar imagen en PDF (TCPDF): " . $e->getMessage());
                    }
                } else {
                    error_log("⚠️ La imagen destacada no se pudo localizar físicamente en: " . $imagen_path . " (URL: " . $imagen_url . ")");
                }
                
                // Limpiar archivo temporal si se descargó
                if (isset($tmp) && !is_wp_error($tmp) && $imagen_path === $tmp) {
                    @unlink($tmp);
                }
            } else {
                error_log("⚠️ El evento ID={$post_id} no tiene imagen destacada");
            }
        }
        
        // ▪ Contenido del PDF
        // Avanza la línea: 70mm si se insertó la imagen, 20mm si no.
        $pdf->Ln($imagen_insertada ? 70 : 20); 
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Entrada para el evento', 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->MultiCell(0, 10, $titulo_a_mostrar, 0, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, "Empresa: {$nombre_empresa}", 0, 1);
        $pdf->Cell(0, 8, "Nombre: {$nombre_persona}", 0, 1);
        $pdf->Cell(0, 8, "Cargo: {$cargo_persona}", 0, 1);

        $pdf->Ln(10);
        // Coordenadas QR: centrado (70mm) y debajo del texto
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